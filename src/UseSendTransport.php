<?php

declare(strict_types=1);

namespace MattStein\UseSend;

use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use MattStein\UseSend\Exceptions\ApiRequestFailedException;
use MattStein\UseSend\Exceptions\InvalidIdempotencyKeyException;
use MattStein\UseSend\Exceptions\UnsupportedMessageException;
use MattStein\UseSend\Support\BaseUrl;
use MattStein\UseSend\Support\EmailPayloadBuilder;
use MattStein\UseSend\Support\MessageHeaders;
use MattStein\UseSend\Support\TransportOptions;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * Sends mail through useSend's send-email endpoint.
 *
 * Options resolve per send: entries in mail.mailers.usesend win over the
 * package config, so a single mailer can be pointed at a different instance.
 */
class UseSendTransport extends AbstractTransport
{
    public const MAILER = TransportOptions::MAILER;

    /** Set on a message to send it with a useSend template. */
    public const TEMPLATE_ID_HEADER = 'X-UseSend-Template-Id';

    /** A JSON object of template variables, used with TEMPLATE_ID_HEADER. */
    public const VARIABLES_HEADER = 'X-UseSend-Variables';

    /** The useSend email this message replies to, for threading. */
    public const IN_REPLY_TO_ID_HEADER = 'X-UseSend-In-Reply-To-Id';

    /** When useSend should deliver the message, as an ISO 8601 date. */
    public const SCHEDULED_AT_HEADER = 'X-UseSend-Scheduled-At';

    /** Set on a message to choose its idempotency key. */
    public const IDEMPOTENCY_KEY_HEADER = 'X-UseSend-Idempotency-Key';

    /** Added to the message after a send, holding the useSend email ID. */
    public const EMAIL_ID_HEADER = 'X-UseSend-Email-Id';

    /** The request header useSend uses to collapse duplicate sends. */
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public const MAX_IDEMPOTENCY_KEY_LENGTH = 256;

    /** Caps a rate limit's Retry-After, so a bad value cannot stall a worker. */
    private const MAX_RETRY_AFTER_MILLISECONDS = 60_000;

    /**
     * @param  array<array-key, mixed>  $options  the mail.mailers.usesend array
     * @param  HttpFactory|null  $http  resolved from the container per send when omitted
     */
    public function __construct(private readonly array $options = [], private readonly ?HttpFactory $http = null)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return self::MAILER;
    }

    protected function doSend(SentMessage $message): void
    {
        $options = TransportOptions::resolve($this->options);
        $request = $this->request($options);
        $endpoint = BaseUrl::make($options->baseUrl)->emailsEndpoint();

        $email = $this->emailFrom($message);
        $payload = (new EmailPayloadBuilder($options->includeInlineAttachments))->build($email, $message->getEnvelope());

        if (($key = $this->idempotencyKey($email, $options)) !== null) {
            $request = $request->withHeaders([self::IDEMPOTENCY_HEADER => $key]);
        }

        $response = $this->post($request, $endpoint, $payload);

        if (! $response->successful()) {
            throw ApiRequestFailedException::fromResponse($endpoint, $response);
        }

        $this->recordEmailId($message, $this->emailId($endpoint, $response));
    }

    private function request(TransportOptions $options): PendingRequest
    {
        // Resolved per send rather than captured at boot, so Http::fake()
        // applies however early the mailer was built.
        $http = $this->http ?? Container::getInstance()->make(HttpFactory::class);

        // Following a redirect would turn the POST into a GET, which can
        // answer 200 without sending anything.
        $request = $http->asJson()
            ->acceptJson()
            ->withToken($options->requireApiKey())
            ->withUserAgent($options->userAgent)
            ->timeout($options->timeout)
            ->connectTimeout($options->connectTimeout)
            ->withoutRedirecting();

        if ($options->retries === 0) {
            return $request;
        }

        return $request->retry(
            $options->retries + 1,
            fn (int $attempt, mixed $exception): int => $this->retryDelay($exception, $options->retrySleepMilliseconds),
            fn (Throwable $exception): bool => $this->shouldRetry($exception),
        );
    }

    /**
     * Retries go to whatever may succeed a second time: connection failures,
     * server errors, rate limits, and a duplicate still being processed. A
     * rejected payload would only be rejected again.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return true;
        }

        $response = $exception->response;

        return match (true) {
            $response->serverError(), $response->status() === 429 => true,
            $response->status() === 409 => str_contains(strtolower($response->body()), 'in progress'),
            default => false,
        };
    }

    private function retryDelay(mixed $exception, int $default): int
    {
        $retryAfter = $exception instanceof RequestException && $exception->response->status() === 429
            ? $exception->response->header('Retry-After')
            : '';

        if (! is_numeric($retryAfter)) {
            return $default;
        }

        return min(max($default, (int) ceil((float) $retryAfter * 1000)), self::MAX_RETRY_AFTER_MILLISECONDS);
    }

    /**
     * A key set on the message always wins: the caller knows what makes a send
     * unique, and the same key survives a queued job being retried.
     *
     * Otherwise a key is generated whenever retries are on, so a retry after a
     * lost response is answered with the original email instead of a second
     * one. It is random rather than derived from the message, so two
     * intentional sends of the same mailable stay two sends.
     */
    private function idempotencyKey(Email $email, TransportOptions $options): ?string
    {
        $key = MessageHeaders::get($email, self::IDEMPOTENCY_KEY_HEADER);

        if ($key === null) {
            return $options->retries > 0 ? Str::uuid()->toString() : null;
        }

        if (mb_strlen($key) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw InvalidIdempotencyKeyException::tooLong(mb_strlen($key), self::MAX_IDEMPOTENCY_KEY_LENGTH);
        }

        return $key;
    }

    /**
     * Laravel's HTTP client throws on a connection error, and throws a
     * RequestException once a retry loop has run out of attempts. Both become
     * this package's exceptions.
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(PendingRequest $request, string $endpoint, array $payload): Response
    {
        try {
            return $request->post($endpoint, $payload);
        } catch (ConnectionException $exception) {
            throw ApiRequestFailedException::connectionFailed($endpoint, $exception);
        } catch (RequestException $exception) {
            throw ApiRequestFailedException::fromResponse($endpoint, $exception->response);
        }
    }

    /**
     * useSend answers a send with {"emailId": "..."}. Anything other than JSON
     * means the request reached something that is not the useSend API, such
     * as a web page served from a mistyped base URL. An empty body is allowed,
     * because that is what a bare Http::fake() returns.
     */
    private function emailId(string $endpoint, Response $response): ?string
    {
        if (trim($response->body()) === '') {
            return null;
        }

        if (! is_array($response->json())) {
            throw ApiRequestFailedException::unexpectedResponse($endpoint, $response);
        }

        $emailId = $response->json('emailId');

        return is_string($emailId) && $emailId !== '' ? $emailId : null;
    }

    private function recordEmailId(SentMessage $message, ?string $emailId): void
    {
        if ($emailId === null) {
            return;
        }

        $message->setMessageId($emailId);

        $original = $message->getOriginalMessage();

        if ($original instanceof Message) {
            MessageHeaders::set($original, self::EMAIL_ID_HEADER, $emailId);
        }
    }

    private function emailFrom(SentMessage $message): Email
    {
        $original = $message->getOriginalMessage();

        return match (true) {
            $original instanceof Email => $original,
            $original instanceof Message => MessageConverter::toEmail($original),
            default => throw UnsupportedMessageException::forClass($original::class),
        };
    }
}

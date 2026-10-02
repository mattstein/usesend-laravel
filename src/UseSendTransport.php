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
use MattStein\UseSend\Exceptions\UseSendException;
use MattStein\UseSend\Support\BaseUrl;
use MattStein\UseSend\Support\EmailPayloadBuilder;
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
 * Register the transport with MAIL_MAILER=usesend, or target it per message
 * with Mail::mailer('usesend')->send(...).
 *
 * Options resolve per send: entries in mail.mailers.usesend win over the
 * package config, so a single mailer can be pointed at a different instance.
 *
 * After a successful send, the email ID useSend returns becomes the sent
 * message's ID and is added to the message as an X-UseSend-Email-Id header.
 */
class UseSendTransport extends AbstractTransport
{
    public const MAILER = TransportOptions::MAILER;

    /** Request header useSend uses to collapse duplicate sends. */
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    /** Set this header on a message to choose its idempotency key. */
    public const IDEMPOTENCY_KEY_HEADER = EmailPayloadBuilder::HEADER_IDEMPOTENCY_KEY;

    /** Added to the message after a send, holding the useSend email ID. */
    public const EMAIL_ID_HEADER = 'X-UseSend-Email-Id';

    /** useSend rejects idempotency keys longer than this. */
    public const MAX_IDEMPOTENCY_KEY_LENGTH = 256;

    /** @var array<array-key, mixed> */
    private array $options;

    /**
     * @param  array<array-key, mixed>  $options  the mail.mailers.usesend array
     * @param  HttpFactory|null  $http  resolved from the container per send when omitted
     */
    public function __construct(array $options = [], private readonly ?HttpFactory $http = null)
    {
        $this->options = $options;

        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $options = TransportOptions::resolve($this->options);
        $apiKey = $options->requireApiKey();
        $endpoint = BaseUrl::make($options->baseUrl)->emailsEndpoint();

        $email = $this->emailFrom($message);

        $payload = (new EmailPayloadBuilder($options->includeInlineAttachments))->build(
            $email,
            $message->getEnvelope(),
        );

        $request = $this->request($options, $apiKey);

        if (($idempotencyKey = $this->idempotencyKey($email, $options->idempotency)) !== null) {
            $request = $request->withHeaders([self::IDEMPOTENCY_HEADER => $idempotencyKey]);
        }

        if ($options->retries > 0) {
            $request = $this->withRetries($request, $options);
        }

        $response = $this->post($request, $endpoint, $payload);

        if ($response->failed()) {
            throw $this->rejected($endpoint, $response);
        }

        $this->recordEmailId($message, $response);
    }

    public function __toString(): string
    {
        return self::MAILER;
    }

    /**
     * Retry only what is worth retrying: connection problems and server-side
     * failures. A rejected payload (4xx) is deterministic, so retrying it just
     * delays the error.
     */
    private function withRetries(PendingRequest $request, TransportOptions $options): PendingRequest
    {
        return $request->retry(
            $options->retries,
            $options->retrySleepMilliseconds,
            static fn (Throwable $exception): bool => ! $exception instanceof RequestException
                || $exception->response->status() >= 500,
        );
    }

    /**
     * Laravel's HTTP client throws on a connection error, and throws a
     * RequestException once a retry loop has run out of attempts. Both are
     * translated here, so the caller only ever sees a response useSend accepted
     * or an exception from this package.
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
            throw $this->rejected($endpoint, $exception->response);
        }
    }

    private function rejected(string $endpoint, Response $response): ApiRequestFailedException
    {
        return $response->status() === 409
            ? ApiRequestFailedException::idempotencyConflict($endpoint, $response)
            : ApiRequestFailedException::httpError($endpoint, $response);
    }

    /**
     * Keep useSend's ID for the email, so it can be looked up through the API
     * or matched to a webhook later.
     */
    private function recordEmailId(SentMessage $message, Response $response): void
    {
        $emailId = $response->json('emailId');

        if (! is_string($emailId) || $emailId === '') {
            return;
        }

        $message->setMessageId($emailId);

        $original = $message->getOriginalMessage();

        if ($original instanceof Message) {
            $original->getHeaders()->remove(self::EMAIL_ID_HEADER);
            $original->getHeaders()->addTextHeader(self::EMAIL_ID_HEADER, $emailId);
        }
    }

    private function request(TransportOptions $options, string $apiKey): PendingRequest
    {
        // Resolved per send rather than captured at boot, so Http::fake()
        // applies however early the mailer was built.
        $http = $this->http ?? Container::getInstance()->make(HttpFactory::class);

        return $http->asJson()
            ->withToken($apiKey)
            ->acceptJson()
            ->withUserAgent($options->userAgent)
            ->timeout($options->timeout)
            ->connectTimeout($options->connectTimeout);
    }

    private function emailFrom(SentMessage $message): Email
    {
        $original = $message->getOriginalMessage();

        if ($original instanceof Email) {
            return $original;
        }

        if (! $original instanceof Message) {
            throw UseSendException::unsupportedMessage($original::class);
        }

        return MessageConverter::toEmail($original);
    }

    /**
     * useSend remembers an idempotency key for 24 hours and answers a repeat
     * with the original email instead of sending again.
     *
     * A key set on the message wins, whatever the config says: the caller knows
     * what makes a send unique (an order or signup ID), and the same key
     * survives a queued job being retried, which is where most duplicate sends
     * come from.
     *
     * Otherwise, with idempotency enabled, a key is generated once per send and
     * reused by the HTTP retry loop. That only protects those retries. It is
     * deliberately not derived from the message: Symfony only materialises a
     * Message-ID when a message is serialised, which this transport never does,
     * and tying the key to it would quietly merge two intentional sends of the
     * same mailable.
     */
    private function idempotencyKey(Email $email, bool $enabled): ?string
    {
        $key = trim($email->getHeaders()->get(self::IDEMPOTENCY_KEY_HEADER)?->getBodyAsString() ?? '');

        if ($key !== '') {
            if (mb_strlen($key) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
                throw InvalidIdempotencyKeyException::tooLong(mb_strlen($key), self::MAX_IDEMPOTENCY_KEY_LENGTH);
            }

            return $key;
        }

        return $enabled ? Str::uuid()->toString() : null;
    }
}

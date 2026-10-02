<?php

declare(strict_types=1);

namespace MattStein\UseSend\Exceptions;

use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Thrown when useSend refuses a request, or cannot be reached at all.
 *
 * The API key is never included in the message, so these exceptions are safe
 * to log.
 */
final class ApiRequestFailedException extends UseSendException
{
    private function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function connectionFailed(string $endpoint, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Could not reach useSend at %s: %s', $endpoint, $previous?->getMessage() ?? 'connection failed'),
            null,
            null,
            $previous,
        );
    }

    public static function httpError(string $endpoint, Response $response): self
    {
        $status = $response->status();
        $body = $response->body();

        $detail = self::describe($body);
        $message = sprintf(
            'useSend rejected the email with HTTP %d (%s)%s',
            $status,
            $response->reason(),
            $detail === '' ? '' : ': '.$detail,
        );

        if ($endpoint !== '') {
            $message .= ' [POST '.$endpoint.']';
        }

        return new self($message, $status, $body);
    }

    public static function idempotencyConflict(string $endpoint, Response $response): self
    {
        $detail = self::describe($response->body());

        return new self(
            sprintf(
                'useSend returned HTTP 409 for an idempotency key: %s The key is reused for 24 hours and must match the original request body exactly.'
                .' Set USESEND_IDEMPOTENCY to false if you send the same mailable instance more than once. [POST %s]',
                $detail === '' ? 'the key is already in use or the body changed.' : $detail,
                $endpoint,
            ),
            409,
            $response->body(),
        );
    }

    /**
     * Pull a human-readable message out of a useSend error response.
     */
    private static function describe(string $body): string
    {
        if (trim($body) === '') {
            return '';
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            foreach (['message', 'error', 'reason'] as $key) {
                $value = $decoded[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            // Zod-style validation errors: flatten field + message pairs.
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                $parts = [];

                foreach ($decoded['errors'] as $field => $messages) {
                    foreach ((array) $messages as $message) {
                        $parts[] = is_string($message) ? $field.': '.$message : $field;
                    }
                }

                if ($parts !== []) {
                    return implode('; ', $parts);
                }
            }
        }

        return mb_substr(trim($body), 0, 200);
    }
}

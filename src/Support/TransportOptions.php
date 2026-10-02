<?php

declare(strict_types=1);

namespace MattStein\UseSend\Support;

use MattStein\UseSend\Exceptions\InvalidOptionException;
use MattStein\UseSend\Exceptions\MissingApiKeyException;

/**
 * Resolved settings for one useSend transport.
 *
 * Values come from the mailer array in config/mail.php first, so a single
 * mailer can override the package defaults, then from config/usesend.php.
 * Resolution happens per send rather than at construction, which lets an
 * application swap API keys or base URLs at runtime.
 */
final class TransportOptions
{
    public const MAILER = 'usesend';

    public const INLINE_SKIP = 'skip';

    public const INLINE_ATTACH = 'attach';

    private function __construct(
        private readonly mixed $apiKey,
        public readonly string $baseUrl,
        public readonly int $timeout,
        public readonly int $connectTimeout,
        public readonly int $retries,
        public readonly int $retrySleepMilliseconds,
        public readonly bool $idempotency,
        public readonly bool $includeInlineAttachments,
        public readonly string $userAgent,
    ) {
        //
    }

    /**
     * @param  array<array-key, mixed>  $overrides  the mail.mailers.usesend array
     */
    public static function resolve(array $overrides = []): self
    {
        $read = static fn (string $key, mixed $default): mixed => $overrides[$key] ?? config('usesend.'.$key, $default);

        return new self(
            $read('api_key', null),
            self::stringOr($read('base_url', BaseUrl::DEFAULT_BASE_URL), BaseUrl::DEFAULT_BASE_URL),
            self::intOr($read('timeout', 30), 30),
            self::intOr($read('connect_timeout', 10), 10),
            max(0, self::intOr($read('retries', 0), 0)),
            max(0, self::intOr($read('retry_sleep', 200), 200)),
            self::boolOr($read('idempotency', false), false),
            self::inlineAttachments($read('inline_attachments', self::INLINE_SKIP)),
            self::stringOr($read('user_agent', 'mattstein-usesend-laravel'), 'mattstein-usesend-laravel'),
        );
    }

    public function requireApiKey(): string
    {
        $key = is_string($this->apiKey) ? trim($this->apiKey) : '';

        if ($key === '') {
            throw MissingApiKeyException::forMailer(self::MAILER);
        }

        return $key;
    }

    /**
     * "skip" or "attach", as documented. A boolean is accepted too, so a mailer
     * array can say 'inline_attachments' => true.
     */
    private static function inlineAttachments(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = is_string($value) ? strtolower(trim($value)) : $value;

        return match ($normalized) {
            null, '', self::INLINE_SKIP => false,
            self::INLINE_ATTACH => true,
            default => throw InvalidOptionException::notOneOf(
                'inline_attachments',
                $value,
                [self::INLINE_SKIP, self::INLINE_ATTACH],
            ),
        };
    }

    private static function stringOr(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }

    private static function intOr(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? (int) $value : $fallback;
    }

    private static function boolOr(mixed $value, bool $fallback): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $fallback;
        }

        return $fallback;
    }
}

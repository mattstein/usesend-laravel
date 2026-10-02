<?php

declare(strict_types=1);

namespace MattStein\UseSend\Exceptions;

/**
 * Thrown when no API key is configured for the useSend mailer.
 */
final class MissingApiKeyException extends UseSendException
{
    public static function forMailer(string $mailer = 'usesend'): self
    {
        return new self(sprintf(
            'The useSend mailer [%s] has no API key. Set USESEND_API_KEY in your .env file, or add an "api_key" entry to the mail.mailers.%s configuration.',
            $mailer,
            $mailer,
        ));
    }
}

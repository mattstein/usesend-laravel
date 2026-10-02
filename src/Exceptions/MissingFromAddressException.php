<?php

declare(strict_types=1);

namespace MattStein\UseSend\Exceptions;

/**
 * Thrown when the message has no sender, which useSend requires.
 */
final class MissingFromAddressException extends UseSendException
{
    public static function forMessage(): self
    {
        return new self(
            'The message has no "from" address. Set one on the mailable, or configure a global address with MAIL_FROM_ADDRESS.'
        );
    }
}

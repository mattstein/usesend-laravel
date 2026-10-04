<?php

declare(strict_types=1);

namespace MattStein\UseSend\Exceptions;

/**
 * Thrown when the transport is handed something other than a MIME message.
 */
final class UnsupportedMessageException extends UseSendException
{
    public static function forClass(string $messageClass): self
    {
        return new self(sprintf(
            'useSend can only send a MIME message, but the message was a %s. Build your mail with a mailable, or a Symfony Email.',
            $messageClass,
        ));
    }
}

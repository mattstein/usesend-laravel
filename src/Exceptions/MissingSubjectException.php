<?php

declare(strict_types=1);

namespace MattStein\UseSend\Exceptions;

/**
 * Thrown when a message has no subject and no useSend template.
 */
final class MissingSubjectException extends UseSendException
{
    public static function forMessage(): self
    {
        return new self(
            'The message has no subject. useSend requires one, unless the message uses a template.'
        );
    }
}

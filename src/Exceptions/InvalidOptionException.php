<?php

declare(strict_types=1);

namespace MattStein\UseSend\Exceptions;

/**
 * Thrown when a configuration option has a value the transport cannot use.
 */
final class InvalidOptionException extends UseSendException
{
    /**
     * @param  list<string>  $allowed
     */
    public static function notOneOf(string $option, mixed $value, array $allowed): self
    {
        return new self(sprintf(
            'The useSend option [%s] must be one of [%s], but it was [%s].',
            $option,
            implode(', ', $allowed),
            is_scalar($value) ? var_export($value, true) : get_debug_type($value),
        ));
    }
}

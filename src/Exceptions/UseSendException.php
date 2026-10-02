<?php

declare(strict_types=1);

namespace MattStein\UseSend\Exceptions;

use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Base class for every error thrown by this package.
 *
 * Implementing Symfony's TransportExceptionInterface tells Laravel that the
 * failure belongs to the transport, so a mailable that throws one of these is
 * reported as a failed delivery instead of surfacing as an unhandled error.
 */
class UseSendException extends RuntimeException implements TransportExceptionInterface
{
    /** @var string */
    private $debug = '';

    /**
     * Symfony's transport exception contract: an extra debugging breadcrumb.
     */
    public function getDebug(): string
    {
        return $this->debug;
    }

    public function appendDebug(string $debug): void
    {
        $this->debug .= $debug;
    }

    public static function malformedTemplateVariables(string $reason): self
    {
        return new self(sprintf(
            'The %s header could not be read as a JSON object of template variables: %s',
            'X-UseSend-Variables',
            $reason,
        ));
    }

    public static function unsupportedMessage(string $messageClass): self
    {
        return new self(sprintf(
            'useSend can only send a MIME message, but the message was a %s. Build your mail with a mailable, or a Symfony Email.',
            $messageClass,
        ));
    }
}

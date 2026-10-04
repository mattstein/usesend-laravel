<?php

declare(strict_types=1);

namespace MattStein\UseSend\Exceptions;

use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Base class for every error thrown by this package.
 *
 * As a TransportExceptionInterface, it is reported the way Laravel reports
 * any other failed delivery.
 */
abstract class UseSendException extends RuntimeException implements TransportExceptionInterface
{
    private string $debug = '';

    public function getDebug(): string
    {
        return $this->debug;
    }

    public function appendDebug(string $debug): void
    {
        $this->debug .= $debug;
    }
}

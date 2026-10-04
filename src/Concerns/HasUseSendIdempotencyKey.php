<?php

declare(strict_types=1);

namespace MattStein\UseSend\Concerns;

use MattStein\UseSend\Support\MessageHeaders;
use MattStein\UseSend\UseSendTransport;
use Symfony\Component\Mime\Email;

/**
 * Gives a mailable an idempotency key, so useSend sends it at most once.
 *
 * Derive the key from what makes the email unique, such as an order ID. The
 * same key is sent when a queued job is retried, so useSend answers the retry
 * with the original email instead of sending it again.
 */
trait HasUseSendIdempotencyKey
{
    public function useSendIdempotencyKey(string $key): static
    {
        return $this->withSymfonyMessage(function (Email $email) use ($key): void {
            MessageHeaders::set($email, UseSendTransport::IDEMPOTENCY_KEY_HEADER, $key);
        });
    }
}

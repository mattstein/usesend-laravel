<?php

declare(strict_types=1);

namespace MattStein\UseSend\Concerns;

use DateTimeInterface;
use MattStein\UseSend\Support\MessageHeaders;
use MattStein\UseSend\UseSendTransport;
use Symfony\Component\Mime\Email;

/**
 * Has useSend hold a mailable and deliver it later.
 *
 * The send itself happens straight away: useSend accepts the email and
 * returns its ID, then delivers it at the scheduled time.
 */
trait HasUseSendSchedule
{
    public function useSendScheduledAt(DateTimeInterface $at): static
    {
        $at = $at->format(DateTimeInterface::ATOM);

        return $this->withSymfonyMessage(function (Email $email) use ($at): void {
            MessageHeaders::set($email, UseSendTransport::SCHEDULED_AT_HEADER, $at);
        });
    }
}

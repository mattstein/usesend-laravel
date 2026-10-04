<?php

declare(strict_types=1);

namespace MattStein\UseSend\Concerns;

use MattStein\UseSend\Support\MessageHeaders;
use MattStein\UseSend\UseSendTransport;
use Symfony\Component\Mime\Email;

/**
 * Sends a mailable through a useSend template instead of its rendered HTML.
 */
trait HasUseSendTemplate
{
    /**
     * @param  array<string, mixed>  $variables  scalars are sent as strings, arrays as JSON
     * @param  string|null  $inReplyToId  the useSend email this one replies to
     */
    public function useSendTemplate(string $templateId, array $variables = [], ?string $inReplyToId = null): static
    {
        $variables = $variables === [] ? null : json_encode($variables, JSON_THROW_ON_ERROR);

        return $this->withSymfonyMessage(function (Email $email) use ($templateId, $variables, $inReplyToId): void {
            MessageHeaders::set($email, UseSendTransport::TEMPLATE_ID_HEADER, $templateId);
            MessageHeaders::set($email, UseSendTransport::VARIABLES_HEADER, $variables);

            if ($inReplyToId !== null) {
                MessageHeaders::set($email, UseSendTransport::IN_REPLY_TO_ID_HEADER, $inReplyToId);
            }
        });
    }
}

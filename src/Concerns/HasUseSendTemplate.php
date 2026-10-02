<?php

declare(strict_types=1);

namespace MattStein\UseSend\Concerns;

use MattStein\UseSend\Support\EmailPayloadBuilder;
use Symfony\Component\Mime\Email;

/**
 * Sends a mailable through a useSend template instead of its rendered HTML.
 *
 * When a template is set, the transport leaves the subject, text, and HTML out
 * of the request so useSend renders the template itself.
 */
trait HasUseSendTemplate
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function useSendTemplate(string $templateId, array $variables = [], ?string $inReplyToId = null): static
    {
        return $this->withSymfonyMessage(function (Email $email) use ($templateId, $variables, $inReplyToId): void {
            $headers = $email->getHeaders();

            $headers->addTextHeader(EmailPayloadBuilder::HEADER_TEMPLATE_ID, $templateId);

            if ($variables !== []) {
                $headers->addTextHeader(
                    EmailPayloadBuilder::HEADER_VARIABLES,
                    json_encode($variables, JSON_THROW_ON_ERROR),
                );
            }

            if ($inReplyToId !== null && $inReplyToId !== '') {
                $headers->addTextHeader(EmailPayloadBuilder::HEADER_IN_REPLY_TO_ID, $inReplyToId);
            }
        });
    }
}

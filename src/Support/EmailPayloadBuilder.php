<?php

declare(strict_types=1);

namespace MattStein\UseSend\Support;

use MattStein\UseSend\Exceptions\MissingBodyException;
use MattStein\UseSend\Exceptions\MissingFromAddressException;
use MattStein\UseSend\Exceptions\MissingRecipientException;
use MattStein\UseSend\Exceptions\MissingSubjectException;
use MattStein\UseSend\Exceptions\TooManyAttachmentsException;
use MattStein\UseSend\Exceptions\UseSendException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

/**
 * Turns a Symfony email into a useSend "send email" request body.
 *
 * Everything useSend accepts is filled in from the message: recipients, the
 * sender, subject, bodies, attachments, and any custom headers the mailable
 * set. Headers that describe the envelope or the MIME structure are left out,
 * because useSend owns those.
 *
 * Recipients are sent as bare addresses. useSend matches its suppression list
 * against the exact string it receives, so a display name ("Jane"
 * <jane@example.com>) would slip past a suppressed jane@example.com.
 */
final class EmailPayloadBuilder
{
    /** Set this header on a message to send it with a useSend template. */
    public const HEADER_TEMPLATE_ID = 'X-UseSend-Template-Id';

    /** JSON object of template variables, used with HEADER_TEMPLATE_ID. */
    public const HEADER_VARIABLES = 'X-UseSend-Variables';

    /** The useSend email this message replies to, for threading. */
    public const HEADER_IN_REPLY_TO_ID = 'X-UseSend-In-Reply-To-Id';

    /** Sent to useSend as the Idempotency-Key request header, not as an email header. */
    public const HEADER_IDEMPOTENCY_KEY = 'X-UseSend-Idempotency-Key';

    /** useSend rejects requests with more attachments than this. */
    public const MAX_ATTACHMENTS = 10;

    /**
     * Headers useSend or the email format already owns, or that must not be
     * forwarded as custom headers. Names are lower-cased, because that is how
     * they are compared.
     *
     * @var list<string>
     */
    private const STRIPPED_HEADERS = [
        'to',
        'from',
        'cc',
        'bcc',
        'reply-to',
        'subject',
        'date',
        'message-id',
        'in-reply-to',
        'references',
        'return-path',
        'sender',
        'mime-version',
        'content-type',
        'content-transfer-encoding',
        'content-disposition',
        'content-id',
        'resent-from',
        'resent-to',
        'resent-cc',
        'resent-bcc',
        'resent-date',
        'resent-sender',
        'resent-message-id',
        'x-usesend-template-id',
        'x-usesend-variables',
        'x-usesend-in-reply-to-id',
        'x-usesend-idempotency-key',
        'x-usesend-email-id',
    ];

    public function __construct(private readonly bool $includeInlineAttachments = false)
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Email $email, ?Envelope $envelope = null): array
    {
        $payload = [];

        $to = $this->addresses($this->toRecipients($email, $envelope));

        if ($to === []) {
            throw MissingRecipientException::forMessage();
        }

        $payload['to'] = $to;

        $from = $email->getFrom()[0] ?? null;

        if (! $from instanceof Address) {
            throw MissingFromAddressException::forMessage();
        }

        $payload['from'] = $from->toString();

        foreach (['cc' => $email->getCc(), 'bcc' => $email->getBcc()] as $field => $addresses) {
            $values = $this->addresses($addresses);

            if ($values !== []) {
                $payload[$field] = $values;
            }
        }

        $replyTo = $this->addresses($email->getReplyTo());

        if ($replyTo !== []) {
            $payload['replyTo'] = $replyTo;
        }

        $templateId = $this->controlHeader($email, self::HEADER_TEMPLATE_ID);
        $text = $this->bodyToString($email->getTextBody());
        $html = $this->bodyToString($email->getHtmlBody());

        if ($text === null && $html === null) {
            throw MissingBodyException::forMessage();
        }

        if ($templateId !== null) {
            $payload['templateId'] = $templateId;

            $variables = $this->variables($email);

            if ($variables !== []) {
                $payload['variables'] = $variables;
            }

            // useSend's API rejects a request with neither text nor HTML, even
            // with a template, then replaces the subject and HTML with the
            // template's. It does not replace text, so text is only sent when
            // there is no HTML to stand in for it.
            $payload += $html !== null ? ['html' => $html] : ['text' => $text];
        } else {
            $subject = $this->nonEmptyString($email->getSubject());

            if ($subject === null) {
                throw MissingSubjectException::forMessage();
            }

            $payload['subject'] = $subject;

            if ($text !== null) {
                $payload['text'] = $text;
            }

            if ($html !== null) {
                $payload['html'] = $html;
            }
        }

        $inReplyToId = $this->controlHeader($email, self::HEADER_IN_REPLY_TO_ID);

        if ($inReplyToId !== null) {
            $payload['inReplyToId'] = $inReplyToId;
        }

        $headers = $this->headers($email);

        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        $attachments = $this->attachments($email);

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * The "to" recipients, taken from the envelope when there is one.
     *
     * The envelope is who the mail is actually delivered to, and can differ
     * from the To header (an envelope passed to the mailer, or one changed by
     * a listener). Envelope recipients also include every cc and bcc, so those
     * are taken out again, unless the address is in the To header too.
     *
     * @return list<Address>
     */
    private function toRecipients(Email $email, ?Envelope $envelope): array
    {
        if ($envelope === null) {
            return array_values($email->getTo());
        }

        $to = $this->keyed($email->getTo());
        $copies = $this->keyed([...$email->getCc(), ...$email->getBcc()]);

        $recipients = [];

        foreach ($envelope->getRecipients() as $recipient) {
            $key = strtolower($recipient->getAddress());

            if (isset($to[$key]) || ! isset($copies[$key])) {
                $recipients[$key] ??= $to[$key] ?? $recipient;
            }
        }

        return array_values($recipients);
    }

    /**
     * @param  Address[]  $addresses
     * @return array<string, Address>
     */
    private function keyed(array $addresses): array
    {
        $keyed = [];

        foreach ($addresses as $address) {
            $keyed[strtolower($address->getAddress())] = $address;
        }

        return $keyed;
    }

    /**
     * Bare addresses, without display names: see the class docblock.
     *
     * @param  Address[]  $addresses
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        $values = [];

        foreach ($addresses as $address) {
            $values[strtolower($address->getAddress())] ??= $address->getEncodedAddress();
        }

        return array_values($values);
    }

    /**
     * @return array<string, string>
     */
    private function headers(Email $email): array
    {
        $headers = [];

        // Symfony's Headers object is not iterable; all() is the accessor.
        foreach ($email->getHeaders()->all() as $header) {
            if (! $header instanceof HeaderInterface) {
                continue;
            }

            if (in_array(strtolower($header->getName()), self::STRIPPED_HEADERS, true)) {
                continue;
            }

            try {
                $value = trim($header->getBodyAsString());
            } catch (Throwable) {
                // A header whose value cannot be represented as a string (a
                // malformed one) is dropped rather than breaking the send.
                continue;
            }

            if ($value === '') {
                continue;
            }

            $headers[$header->getName()] = $value;
        }

        return $headers;
    }

    /**
     * @return list<array{filename: string, content: string}>
     */
    private function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $part) {
            // DataPart::getContentId() generates an id on first call, so the
            // disposition is what decides whether a part is inline.
            $inline = $part->getDisposition() === 'inline';

            if ($inline && ! $this->includeInlineAttachments) {
                continue;
            }

            $attachments[] = [
                'filename' => $this->filename($part, $inline),
                'content' => base64_encode($part->getBody()),
            ];
        }

        if (count($attachments) > self::MAX_ATTACHMENTS) {
            throw TooManyAttachmentsException::forCount(count($attachments), self::MAX_ATTACHMENTS);
        }

        return $attachments;
    }

    private function filename(DataPart $part, bool $inline): string
    {
        $filename = $part->getFilename();

        if (is_string($filename) && trim($filename) !== '') {
            return mb_substr(trim($filename), 0, 255);
        }

        // useSend requires a filename, so name the part after its media type.
        $extension = $part->getMediaSubtype() ?: 'bin';

        return ($inline ? 'inline' : 'attachment').'.'.$extension;
    }

    /**
     * @return array<string, string>
     */
    private function variables(Email $email): array
    {
        $raw = $this->controlHeader($email, self::HEADER_VARIABLES);

        if ($raw === null) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw UseSendException::malformedTemplateVariables($exception->getMessage());
        }

        if (! is_array($decoded)) {
            throw UseSendException::malformedTemplateVariables('it is not a JSON object.');
        }

        $variables = [];

        foreach ($decoded as $name => $value) {
            $variables[(string) $name] = $this->stringify($value);
        }

        return $variables;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            is_scalar($value) => (string) $value,
            is_array($value) => (string) json_encode($value),
            default => throw UseSendException::malformedTemplateVariables('template variables must be scalars or arrays.'),
        };
    }

    private function controlHeader(Email $email, string $name): ?string
    {
        $header = $email->getHeaders()->get($name);

        if ($header === null) {
            return null;
        }

        $value = trim($header->getBodyAsString());

        return $value === '' ? null : $value;
    }

    /**
     * A message body may be a string, a stream, or absent. useSend takes JSON,
     * so a stream is read out and anything empty is left out of the request.
     */
    private function bodyToString(mixed $value): ?string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return ($contents === false || $contents === '') ? null : $contents;
        }

        return $this->nonEmptyString($value);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

use MattStein\UseSend\Exceptions\MissingBodyException;
use MattStein\UseSend\Exceptions\MissingFromAddressException;
use MattStein\UseSend\Exceptions\MissingRecipientException;
use MattStein\UseSend\Exceptions\MissingSubjectException;
use MattStein\UseSend\Exceptions\TooManyAttachmentsException;
use MattStein\UseSend\Exceptions\UseSendException;
use MattStein\UseSend\Support\EmailPayloadBuilder;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * @return array<string, mixed>
 */
function buildPayload(Email $email, bool $includeInlineAttachments = false, ?Envelope $envelope = null): array
{
    return (new EmailPayloadBuilder($includeInlineAttachments))->build($email, $envelope);
}

function basicEmail(): Email
{
    return (new Email)
        ->from(new Address('events@example.com', 'Events'))
        ->to(new Address('jane@example.com', 'Jane'))
        ->subject('Your meetup')
        ->text('plain body')
        ->html('<p>rich body</p>');
}

it('maps recipients, sender, and bodies', function () {
    $email = basicEmail()
        ->cc(new Address('cc@example.com', 'Copy'))
        ->bcc(new Address('bcc@example.com', 'Blind'))
        ->replyTo(new Address('replies@example.com', 'Replies'));

    $payload = buildPayload($email);

    // The sender keeps its display name; recipients are bare addresses, so
    // useSend's suppression list can match them.
    expect($payload['from'])->toBe('"Events" <events@example.com>')
        ->and($payload['to'])->toBe(['jane@example.com'])
        ->and($payload['cc'])->toBe(['cc@example.com'])
        ->and($payload['bcc'])->toBe(['bcc@example.com'])
        ->and($payload['replyTo'])->toBe(['replies@example.com'])
        ->and($payload['subject'])->toBe('Your meetup')
        ->and($payload['text'])->toBe('plain body')
        ->and($payload['html'])->toBe('<p>rich body</p>');
});

it('sends each recipient once', function () {
    $email = basicEmail()->addTo('jane@example.com', new Address('JANE@example.com', 'Jane again'));

    expect(buildPayload($email)['to'])->toBe(['jane@example.com']);
});

it('takes the to recipients from the envelope', function () {
    $email = basicEmail()->cc('cc@example.com')->bcc('bcc@example.com');

    $envelope = new Envelope(new Address('events@example.com'), [
        new Address('redirected@example.com'),
        new Address('cc@example.com'),
        new Address('bcc@example.com'),
    ]);

    $payload = buildPayload($email, envelope: $envelope);

    expect($payload['to'])->toBe(['redirected@example.com'])
        ->and($payload['cc'])->toBe(['cc@example.com'])
        ->and($payload['bcc'])->toBe(['bcc@example.com']);
});

it('keeps a to recipient that is also copied', function () {
    $email = basicEmail()->cc('Jane@Example.com');

    $envelope = new Envelope(new Address('events@example.com'), [
        new Address('jane@example.com'),
        new Address('Jane@Example.com'),
    ]);

    $payload = buildPayload($email, envelope: $envelope);

    expect($payload['to'])->toBe(['jane@example.com'])
        ->and($payload['cc'])->toBe(['Jane@Example.com']);
});

it('rejects a message with no to recipient', function () {
    buildPayload((new Email)->from('events@example.com')->bcc('bcc@example.com')->subject('Hi')->text('Body'));
})->throws(MissingRecipientException::class, 'no "to" recipient');

it('rejects a message with no body', function () {
    buildPayload((new Email)->from('events@example.com')->to('jane@example.com')->subject('Hi'));
})->throws(MissingBodyException::class, 'no text or HTML body');

it('omits recipients that were not set', function () {
    $payload = buildPayload((new Email)->from('events@example.com')->to('jane@example.com')->subject('Hi')->text('Body'));

    expect($payload)->not->toHaveKey('cc')
        ->and($payload)->not->toHaveKey('bcc')
        ->and($payload)->not->toHaveKey('replyTo')
        ->and($payload)->not->toHaveKey('html');
});

it('forwards custom headers and strips envelope and mime headers', function () {
    $email = basicEmail();
    $email->getHeaders()->addTextHeader('X-Campaign', 'welcome');
    $email->getHeaders()->addTextHeader('List-Unsubscribe', '<mailto:stop@example.com>');
    $email->getHeaders()->addTextHeader(EmailPayloadBuilder::HEADER_IDEMPOTENCY_KEY, 'order-1');
    $email->getHeaders()->addTextHeader('X-UseSend-Email-Id', 'email_1');

    $payload = buildPayload($email);

    expect($payload['headers'])->toBe([
        'X-Campaign' => 'welcome',
        'List-Unsubscribe' => '<mailto:stop@example.com>',
    ]);
});

it('encodes attachments as base64 with their filename', function () {
    $payload = buildPayload(basicEmail()->attach('the file body', 'notes.txt', 'text/plain'));

    expect($payload['attachments'])->toBe([
        ['filename' => 'notes.txt', 'content' => base64_encode('the file body')],
    ]);
});

it('names attachments that have no filename after their media type', function () {
    $email = basicEmail()->attach('plain bytes', null, 'text/csv');

    expect(buildPayload($email)['attachments'])->toBe([
        ['filename' => 'attachment.csv', 'content' => base64_encode('plain bytes')],
    ]);
});

it('skips inline attachments by default', function () {
    $email = basicEmail()->embed('PNGDATA', 'logo.png', 'image/png');

    expect(buildPayload($email))->not->toHaveKey('attachments');
});

it('sends inline attachments when configured to', function () {
    $email = basicEmail()->embed('PNGDATA', 'logo.png', 'image/png');

    $payload = buildPayload($email, includeInlineAttachments: true);

    expect($payload['attachments'])->toBe([
        ['filename' => 'logo.png', 'content' => base64_encode('PNGDATA')],
    ]);
});

it('names an inline attachment that has no filename', function () {
    $email = basicEmail()->embed('PNGDATA', null, 'image/png');

    expect(buildPayload($email, includeInlineAttachments: true)['attachments'])->toBe([
        ['filename' => 'inline.png', 'content' => base64_encode('PNGDATA')],
    ]);
});

it('rejects more attachments than useSend accepts', function () {
    $email = basicEmail();

    for ($i = 0; $i < 11; $i++) {
        $email->attach('body', "file-{$i}.txt", 'text/plain');
    }

    buildPayload($email);
})->throws(TooManyAttachmentsException::class, 'useSend accepts at most 10');

it('accepts exactly ten attachments', function () {
    $email = basicEmail();

    for ($i = 0; $i < 10; $i++) {
        $email->attach('body', "file-{$i}.txt", 'text/plain');
    }

    expect(buildPayload($email)['attachments'])->toHaveCount(10);
});

it('replaces the rendered copy with a template and coerces variables to strings', function () {
    $email = basicEmail();
    $email->getHeaders()->addTextHeader(EmailPayloadBuilder::HEADER_TEMPLATE_ID, 'welcome_template');
    $email->getHeaders()->addTextHeader(EmailPayloadBuilder::HEADER_VARIABLES, (string) json_encode([
        'name' => 'Jane',
        'attempts' => 3,
        'vip' => true,
        'archived' => false,
        'nothing' => null,
    ]));

    $payload = buildPayload($email);

    expect($payload['templateId'])->toBe('welcome_template')
        ->and($payload['variables'])->toBe([
            'name' => 'Jane',
            'attempts' => '3',
            'vip' => 'true',
            'archived' => 'false',
            'nothing' => '',
        ])
        // useSend requires a body even with a template, then swaps in the
        // template's subject and HTML. It never swaps the text, so the
        // mailable's text is left out.
        ->and($payload['html'])->toBe('<p>rich body</p>')
        ->and($payload)->not->toHaveKey('subject')
        ->and($payload)->not->toHaveKey('text')
        ->and($payload)->not->toHaveKey('headers');
});

it('sends the text body with a template when there is no html', function () {
    $email = (new Email)->from('events@example.com')->to('jane@example.com')->text('plain body');
    $email->getHeaders()->addTextHeader(EmailPayloadBuilder::HEADER_TEMPLATE_ID, 'welcome_template');

    $payload = buildPayload($email);

    expect($payload['text'])->toBe('plain body')
        ->and($payload)->not->toHaveKey('html');
});

it('rejects a template message with no body', function () {
    $email = (new Email)->from('events@example.com')->to('jane@example.com');
    $email->getHeaders()->addTextHeader(EmailPayloadBuilder::HEADER_TEMPLATE_ID, 'welcome_template');

    buildPayload($email);
})->throws(MissingBodyException::class);

it('passes a reply target for threading', function () {
    $email = basicEmail();
    $email->getHeaders()->addTextHeader(EmailPayloadBuilder::HEADER_IN_REPLY_TO_ID, 'email_42');

    expect(buildPayload($email)['inReplyToId'])->toBe('email_42');
});

it('rejects unreadable template variables', function () {
    $email = basicEmail();
    $email->getHeaders()->addTextHeader(EmailPayloadBuilder::HEADER_TEMPLATE_ID, 'welcome_template');
    $email->getHeaders()->addTextHeader(EmailPayloadBuilder::HEADER_VARIABLES, 'not json at all');

    buildPayload($email);
})->throws(UseSendException::class, 'JSON object');

it('rejects a message without a sender', function () {
    buildPayload((new Email)->to('jane@example.com')->subject('Hi')->text('Body'));
})->throws(MissingFromAddressException::class, 'no "from" address');

it('rejects a message without a subject', function () {
    buildPayload((new Email)->from('events@example.com')->to('jane@example.com')->text('Body'));
})->throws(MissingSubjectException::class, 'no subject');

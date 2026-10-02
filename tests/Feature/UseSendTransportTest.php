<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Message as IlluminateMailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MattStein\UseSend\Exceptions\ApiRequestFailedException;
use MattStein\UseSend\Exceptions\InvalidIdempotencyKeyException;
use MattStein\UseSend\Exceptions\InvalidOptionException;
use MattStein\UseSend\Exceptions\MissingApiKeyException;
use MattStein\UseSend\Tests\Fixtures\IdempotentMailable;
use MattStein\UseSend\Tests\Fixtures\PlainMailable;
use MattStein\UseSend\Tests\Fixtures\TemplateMailable;
use MattStein\UseSend\UseSendTransport;
use PHPUnit\Framework\Assert;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

const ENDPOINT = 'https://app.usesend.com/api/v1/emails';

function deliver(Mailable $mailable): void
{
    Mail::mailer('usesend')->send($mailable);
}

/**
 * The payload of the single request the fake captured.
 *
 * @return array<array-key, mixed>
 */
function sentPayload(): array
{
    $payload = [];

    Http::assertSentCount(1);

    Http::assertSent(function (Request $request) use (&$payload) {
        $payload = $request->data();

        return true;
    });

    return $payload;
}

/**
 * @param  Closure(Mailable): Mailable  $build
 */
function mailableUsing(Closure $build): Mailable
{
    return new class($build) extends Mailable
    {
        /** @param Closure(Mailable): Mailable $build */
        public function __construct(private readonly Closure $build) {}

        public function build(): Mailable
        {
            return ($this->build)($this);
        }
    };
}

/**
 * The idempotency key the request carried, or an empty string when it had none.
 */
function idempotencyKey(Request $request): string
{
    return implode('', array_filter($request->header('Idempotency-Key'), 'is_string'));
}

/**
 * Every idempotency key the fake saw, in order.
 *
 * @return list<string>
 */
function sentIdempotencyKeys(): array
{
    $keys = [];

    Http::assertSent(function (Request $request) use (&$keys) {
        $keys[] = idempotencyKey($request);

        return true;
    });

    return $keys;
}

function embeddedImageMailable(): Mailable
{
    return mailableUsing(fn (Mailable $mail) => $mail
        ->from('events@example.com')
        ->to('jane@example.com')
        ->subject('Your meetup')
        ->html('<p><img src="cid:logo.png"></p>')
        ->withSymfonyMessage(function (Email $email): void {
            $email->embed('PNGDATA', 'logo.png', 'image/png');
        }));
}

it('reports its transport name', function () {
    expect((string) new UseSendTransport)->toBe('usesend');
});

it('posts the message to the useSend send endpoint', function () {
    Http::fake();

    deliver(new PlainMailable);

    Http::assertSent(fn (Request $request) => $request->url() === ENDPOINT
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer us_test_key')
        && $request->hasHeader('Accept', 'application/json')
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->hasHeader('User-Agent', 'mattstein-usesend-laravel')
        && $request->data()['to'] === ['jane@example.com']
        && $request->data()['from'] === 'events@example.com'
        && $request->data()['subject'] === 'Your meetup'
        && $request->data()['html'] === '<p>See you there.</p>');
});

it('sends through an injected http client', function () {
    $http = (new HttpFactory)->fake(['*' => HttpFactory::response(['emailId' => 'email_injected'])]);

    $sent = (new UseSendTransport([], $http))->send(
        (new Email)->from('events@example.com')->to('jane@example.com')->subject('Hi')->text('Body'),
    );

    $http->assertSentCount(1);

    expect($sent?->getMessageId())->toBe('email_injected');
});

it('merges the package config into the application', function () {
    expect(config('usesend.timeout'))->toBe(30)
        ->and(config('usesend.retries'))->toBe(0)
        ->and(config('usesend.idempotency'))->toBeFalse();
});

it('lets the mailer array override the package config', function () {
    config()->set('mail.mailers.usesend', [
        'transport' => 'usesend',
        'api_key' => 'us_mailer_key',
        'base_url' => 'http://localhost:3000',
    ]);

    Http::fake();

    deliver(new PlainMailable);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:3000/api/v1/emails'
        && $request->hasHeader('Authorization', 'Bearer us_mailer_key'));
});

it('reads settings that change between sends', function () {
    Http::fake();

    config()->set('usesend.api_key', 'us_first');
    deliver(new PlainMailable);

    config()->set('usesend.api_key', 'us_second');
    deliver(new PlainMailable);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer us_first'));
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer us_second'));
});

it('refuses to send without an api key', function () {
    config()->set('usesend.api_key', null);
    Http::fake();

    deliver(new PlainMailable);
})->throws(MissingApiKeyException::class, 'USESEND_API_KEY');

it('turns a rejected payload into a readable error', function () {
    Http::fake(['*' => Http::response(['message' => 'Invalid "from" address'], 422)]);

    try {
        deliver(new PlainMailable);
        Assert::fail('Expected the send to fail.');
    } catch (ApiRequestFailedException $exception) {
        expect($exception->status)->toBe(422)
            ->and($exception->responseBody)->toContain('message')
            ->and($exception->getMessage())->toContain('HTTP 422')
            ->and($exception->getMessage())->toContain('Invalid "from" address')
            ->and($exception->getMessage())->not->toContain('us_test_key');
    }
});

it('flattens validation errors from the api', function () {
    Http::fake(['*' => Http::response([
        'errors' => ['subject' => ['Required', 'Too short']],
    ], 400)]);

    deliver(new PlainMailable);
})->throws(ApiRequestFailedException::class, 'subject: Required; subject: Too short');

it('explains an idempotency conflict', function () {
    config()->set('usesend.idempotency', true);
    Http::fake(['*' => Http::response(['message' => 'NOT_UNIQUE'], 409)]);

    deliver(new PlainMailable);
})->throws(ApiRequestFailedException::class, '409');

it('explains an unreachable host', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    deliver(new PlainMailable);
})->throws(ApiRequestFailedException::class, 'Could not reach useSend');

it('omits the idempotency header by default', function () {
    Http::fake();

    deliver(new PlainMailable);

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Idempotency-Key'));
});

it('sends an idempotency key when enabled', function () {
    config()->set('usesend.idempotency', true);
    Http::fake();

    deliver(new PlainMailable);
    deliver(new PlainMailable);

    $keys = sentIdempotencyKeys();

    // One key per send, and never empty: a retry of a send reuses its key,
    // but two intentional sends stay two sends.
    expect($keys)->toHaveCount(2)
        ->and($keys[0])->not->toBe('')
        ->and($keys[1])->not->toBe('')
        ->and($keys[0])->not->toBe($keys[1]);
});

it('reuses one idempotency key across the retries of a single send', function () {
    config()->set('usesend.idempotency', true);
    config()->set('usesend.retries', 2);
    config()->set('usesend.retry_sleep', 0);

    Http::fake(['*' => Http::sequence()
        ->push('Internal Server Error', 500)
        ->push(['emailId' => 'email_1'], 200),
    ]);

    deliver(new PlainMailable);

    $keys = sentIdempotencyKeys();

    expect($keys)->toHaveCount(2)
        ->and(array_unique($keys))->toHaveCount(1);
});

it('sends the idempotency key set on the message, even with idempotency off', function () {
    Http::fake();

    deliver(new IdempotentMailable('order-42'));

    $payload = sentPayload();

    expect(sentIdempotencyKeys())->toBe(['order-42'])
        ->and($payload)->not->toHaveKey('headers');
});

it('prefers the message key over a generated one', function () {
    config()->set('usesend.idempotency', true);
    Http::fake();

    deliver(new IdempotentMailable('order-42'));
    deliver(new IdempotentMailable('order-42'));

    // The same key on both sends, as when a queued job is retried.
    expect(sentIdempotencyKeys())->toBe(['order-42', 'order-42']);
});

it('reuses the message key across retries', function () {
    config()->set('usesend.retries', 2);
    config()->set('usesend.retry_sleep', 0);

    Http::fake(['*' => Http::sequence()
        ->push('Internal Server Error', 500)
        ->push(['emailId' => 'email_1'], 200),
    ]);

    deliver(new IdempotentMailable('order-42'));

    expect(sentIdempotencyKeys())->toBe(['order-42', 'order-42']);
});

it('accepts the idempotency key as a plain header', function () {
    Http::fake();

    deliver(mailableUsing(fn (Mailable $mail) => $mail
        ->from('events@example.com')
        ->to('jane@example.com')
        ->subject('Your meetup')
        ->html('<p>Hi</p>')
        ->withSymfonyMessage(function (Email $email): void {
            $email->getHeaders()->addTextHeader(UseSendTransport::IDEMPOTENCY_KEY_HEADER, 'signup-7');
        })));

    expect(sentIdempotencyKeys())->toBe(['signup-7']);
});

it('rejects an idempotency key longer than useSend accepts', function () {
    Http::fake();

    deliver(new IdempotentMailable(str_repeat('k', 257)));
})->throws(InvalidIdempotencyKeyException::class, 'at most 256');

it('records the useSend email id on the sent message', function () {
    Http::fake(['*' => Http::response(['emailId' => 'email_123'])]);

    $headerValue = null;

    Event::listen(function (MessageSent $event) use (&$headerValue) {
        $headerValue = $event->message->getHeaders()->get(UseSendTransport::EMAIL_ID_HEADER)?->getBodyAsString();
    });

    $sent = Mail::mailer('usesend')->send(new PlainMailable);

    expect($sent?->getMessageId())->toBe('email_123')
        ->and($headerValue)->toBe('email_123');
});

it('leaves the message id alone when useSend returns no email id', function () {
    Http::fake(['*' => Http::response([])]);

    $sent = Mail::mailer('usesend')->send(new PlainMailable);

    $original = $sent?->getSymfonySentMessage()->getOriginalMessage();

    if (! $original instanceof Email) {
        Assert::fail('Expected the original message to be an Email.');
    }

    // Symfony's own generated id, which looks like an address.
    expect($sent->getMessageId())->toContain('@')
        ->and($original->getHeaders()->has(UseSendTransport::EMAIL_ID_HEADER))->toBeFalse();
});

it('retries server errors when retries are enabled', function () {
    config()->set('usesend.retries', 2);
    config()->set('usesend.retry_sleep', 0);

    Http::fake(['*' => Http::sequence()
        ->push('Internal Server Error', 500)
        ->push(['emailId' => 'email_1'], 200),
    ]);

    deliver(new PlainMailable);

    Http::assertSentCount(2);
});

it('does not retry a rejected payload', function () {
    config()->set('usesend.retries', 3);
    config()->set('usesend.retry_sleep', 0);

    Http::fake(['*' => Http::sequence()
        ->push(['message' => 'Invalid "from" address'], 422)
        ->push(['emailId' => 'email_1'], 200),
    ]);

    expect(fn () => deliver(new PlainMailable))->toThrow(ApiRequestFailedException::class);

    Http::assertSentCount(1);
});

it('gives up after the configured number of retries', function () {
    config()->set('usesend.retries', 2);
    config()->set('usesend.retry_sleep', 0);

    Http::fake(['*' => Http::response('Internal Server Error', 503)]);

    deliver(new PlainMailable);
})->throws(ApiRequestFailedException::class, 'HTTP 503');

it('forwards custom headers set on the message', function () {
    Http::fake();

    deliver(mailableUsing(fn (Mailable $mail) => $mail
        ->from('events@example.com')
        ->to('jane@example.com')
        ->subject('Your meetup')
        ->html('<p>Hi</p>')
        ->withSymfonyMessage(function (Email $email): void {
            $email->getHeaders()->addTextHeader('X-Campaign', 'welcome');
        })));

    expect(sentPayload()['headers'])->toBe(['X-Campaign' => 'welcome']);
});

it('forwards attachments', function () {
    Http::fake();

    deliver(mailableUsing(fn (Mailable $mail) => $mail
        ->from('events@example.com')
        ->to('jane@example.com')
        ->subject('Your receipt')
        ->html('<p>Attached.</p>')
        ->attachData('the file body', 'notes.txt', ['mime' => 'text/plain'])));

    expect(sentPayload()['attachments'])->toBe([
        ['filename' => 'notes.txt', 'content' => base64_encode('the file body')],
    ]);
});

it('sends a plain text body', function () {
    Http::fake();

    Mail::mailer('usesend')->raw('See you there.', function (IlluminateMailMessage $message) {
        $message->to('jane@example.com')
            ->from('events@example.com')
            ->subject('Your meetup');
    });

    $payload = sentPayload();

    expect($payload['text'])->toBe('See you there.')
        ->and($payload['subject'])->toBe('Your meetup');
});

it('sends a template mailable', function () {
    Http::fake();

    deliver(new TemplateMailable);

    $payload = sentPayload();

    expect($payload['templateId'])->toBe('welcome_template')
        ->and($payload['variables'])->toBe([
            'name' => 'Jane',
            'attempts' => '3',
            'vip' => 'true',
            'nothing' => '',
        ])
        ->and($payload['html'])->toBe('<p>Body the template replaces</p>')
        ->and($payload)->not->toHaveKey('subject')
        ->and($payload)->not->toHaveKey('headers');
});

it('skips inline attachments by default', function () {
    Http::fake();

    deliver(embeddedImageMailable());

    expect(sentPayload())->not->toHaveKey('attachments');
});

it('attaches inline images when the config says attach', function (mixed $value) {
    config()->set('usesend.inline_attachments', $value);
    Http::fake();

    deliver(embeddedImageMailable());

    expect(sentPayload()['attachments'])->toBe([
        ['filename' => 'logo.png', 'content' => base64_encode('PNGDATA')],
    ]);
})->with(['attach', 'ATTACH', true]);

it('lets a mailer attach inline images', function () {
    config()->set('mail.mailers.usesend', ['transport' => 'usesend', 'inline_attachments' => 'attach']);
    Http::fake();

    deliver(embeddedImageMailable());

    expect(sentPayload()['attachments'])->toHaveCount(1);
});

it('rejects an unknown inline attachment policy', function () {
    config()->set('usesend.inline_attachments', 'embed');
    Http::fake();

    deliver(embeddedImageMailable());
})->throws(InvalidOptionException::class, 'skip, attach');

it('sends copied recipients once, and keeps a recipient who is also copied', function () {
    Http::fake();

    deliver(mailableUsing(fn (Mailable $mail) => $mail
        ->from('events@example.com')
        ->to([new Address('jane@example.com', 'Jane'), 'sam@example.com'])
        ->cc(['sam@example.com', 'cc@example.com'])
        ->bcc('bcc@example.com')
        ->subject('Your meetup')
        ->html('<p>Hi</p>')));

    $payload = sentPayload();

    expect($payload['to'])->toBe(['jane@example.com', 'sam@example.com'])
        ->and($payload['cc'])->toBe(['sam@example.com', 'cc@example.com'])
        ->and($payload['bcc'])->toBe(['bcc@example.com']);
});

it('is reachable as the default mailer', function () {
    config()->set('mail.default', 'usesend');
    Http::fake();

    Mail::to('jane@example.com')->send(new PlainMailable);

    expect(sentPayload()['to'])->toBe(['jane@example.com']);
});

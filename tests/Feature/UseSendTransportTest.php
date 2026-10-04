<?php

declare(strict_types=1);

use GuzzleHttp\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Message as IlluminateMailMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use MattStein\UseSend\Exceptions\ApiRequestFailedException;
use MattStein\UseSend\Exceptions\InvalidBaseUrlException;
use MattStein\UseSend\Exceptions\InvalidIdempotencyKeyException;
use MattStein\UseSend\Exceptions\InvalidOptionException;
use MattStein\UseSend\Exceptions\MissingApiKeyException;
use MattStein\UseSend\Exceptions\UnsupportedMessageException;
use MattStein\UseSend\Support\MessageHeaders;
use MattStein\UseSend\Tests\Fixtures\ConfigurableMailable;
use MattStein\UseSend\Tests\Fixtures\PlainMailable;
use MattStein\UseSend\Tests\Fixtures\TemplateMailable;
use MattStein\UseSend\UseSendTransport;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Component\Mime\RawMessage;

function enableRetries(int $retries = 2, int $sleep = 0): void
{
    config()->set('usesend.retries', $retries);
    config()->set('usesend.retry_sleep', $sleep);
}

/**
 * @param  array<string, string>  $headers
 */
function useSendError(int $status, string $code, string $message, array $headers = []): Closure
{
    return fn () => Http::response(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
}

/**
 * Sends the mailable and returns the exception it failed with.
 */
function failedDelivery(ConfigurableMailable|PlainMailable $mailable): ApiRequestFailedException
{
    try {
        deliver($mailable);
    } catch (ApiRequestFailedException $exception) {
        return $exception;
    }

    Assert::fail('Expected the send to fail.');
}

describe('sending', function () {
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
            && $request->hasHeader('User-Agent', 'mattstein-usesend-laravel'));

        expect(sentPayload())->toBe([
            'to' => ['jane@example.com'],
            'from' => 'events@example.com',
            'subject' => 'Your meetup',
            'html' => '<p>See you there.</p>',
        ]);
    });

    it('is reachable as the default mailer', function () {
        config()->set('mail.default', 'usesend');
        Http::fake();

        Mail::to('sam@example.com')->send(new PlainMailable);

        expect(sentPayload()['to'])->toBe(['sam@example.com', 'jane@example.com']);
    });

    it('sends a plain text message', function () {
        Http::fake();

        Mail::mailer('usesend')->raw('See you there.', function (IlluminateMailMessage $message) {
            $message->to('jane@example.com')->from('events@example.com')->subject('Your meetup');
        });

        $payload = sentPayload();

        expect($payload)->toMatchArray(['text' => 'See you there.', 'subject' => 'Your meetup']);
        expect($payload)->not->toHaveKey('html');
    });

    it('sends a notification', function () {
        config()->set('mail.from', ['address' => 'events@example.com', 'name' => 'Events']);
        Http::fake();

        Notification::route('mail', 'jane@example.com')->notify(new class extends BaseNotification
        {
            /** @return list<string> */
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)
                    ->mailer('usesend')
                    ->subject('Your meetup')
                    ->line('See you there.')
                    ->tag('reminder')
                    ->withSymfonyMessage(function (Email $email): void {
                        $email->getHeaders()->addTextHeader(UseSendTransport::IDEMPOTENCY_KEY_HEADER, 'reminder-1');
                    });
            }
        });

        $payload = sentPayload();

        expect($payload['to'])->toBe(['jane@example.com'])
            ->and($payload['from'])->toBe('"Events" <events@example.com>')
            ->and($payload['html'])->toStartWith('<!DOCTYPE html')->toContain('See you there.')
            ->and($payload['text'])->toContain('See you there.')
            ->and($payload['headers'])->toBe(['X-Tag' => 'reminder'])
            ->and(sentIdempotencyKeys())->toBe(['reminder-1']);
    });

    it('sends a queued mailable', function () {
        config()->set('queue.default', 'sync');
        Http::fake();

        Mail::mailer('usesend')->to('sam@example.com')->queue(new TemplateMailable);

        expect(sentPayload()['templateId'])->toBe('welcome_template');
    });

    it('sends through an injected http client', function () {
        $http = (new HttpFactory)->fake(['*' => HttpFactory::response(['emailId' => 'email_injected'])]);

        $sent = (new UseSendTransport([], $http))->send(
            (new Email)->from('events@example.com')->to('jane@example.com')->subject('Hi')->text('Body'),
        );

        $http->assertSentCount(1);

        expect($sent?->getMessageId())->toBe('email_injected');
    });

    it('converts a mime message that is not an email', function () {
        Http::fake();

        $headers = (new Headers)
            ->addMailboxListHeader('From', ['events@example.com'])
            ->addMailboxListHeader('To', ['jane@example.com'])
            ->addTextHeader('Subject', 'Hi');

        (new UseSendTransport)->send(new Message($headers, new TextPart('Body')));

        expect(sentPayload())->toMatchArray(['subject' => 'Hi', 'text' => 'Body']);
    });

    it('refuses a raw message', function () {
        Http::fake();

        $envelope = new Envelope(new Address('events@example.com'), [new Address('jane@example.com')]);

        expect(fn () => (new UseSendTransport)->send(new RawMessage('raw'), $envelope))
            ->toThrow(UnsupportedMessageException::class, 'but the message was a Symfony\Component\Mime\RawMessage');

        Http::assertNothingSent();
    });
});

describe('configuration', function () {
    it('lets the mailer array override the package config', function () {
        config()->set('mail.mailers.usesend', [
            'transport' => 'usesend',
            'api_key' => 'us_mailer_key',
            'base_url' => 'http://localhost:3000',
            'user_agent' => 'my-app',
        ]);
        Http::fake();

        deliver(new PlainMailable);

        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:3000/api/v1/emails'
            && $request->hasHeader('Authorization', 'Bearer us_mailer_key')
            && $request->hasHeader('User-Agent', 'my-app'));
    });

    it('supports several mailers on the transport', function () {
        config()->set('mail.mailers.usesend-staging', [
            'transport' => 'usesend',
            'api_key' => 'us_staging_key',
            'base_url' => 'https://staging.example.com/usesend',
        ]);
        Http::fake();

        Mail::mailer('usesend-staging')->send(new PlainMailable);
        deliver(new PlainMailable);

        expect(array_map(fn (Request $request) => $request->url(), sentRequests()))->toBe([
            'https://staging.example.com/usesend/api/v1/emails',
            ENDPOINT,
        ]);
    });

    it('reads settings that change between sends', function () {
        Http::fake();

        config()->set('usesend.api_key', 'us_first');
        deliver(new PlainMailable);

        config()->set('usesend.api_key', 'us_second');
        deliver(new PlainMailable);

        [$first, $second] = sentRequests();

        expect($first->header('Authorization'))->toBe(['Bearer us_first'])
            ->and($second->header('Authorization'))->toBe(['Bearer us_second']);
    });

    it('refuses to send without an api key', function () {
        config()->set('usesend.api_key', null);
        Http::fake();

        expect(fn () => deliver(new PlainMailable))->toThrow(MissingApiKeyException::class, 'USESEND_API_KEY');

        Http::assertNothingSent();
    });

    it('refuses to send to an invalid base url', function () {
        config()->set('usesend.base_url', 'ftp://example.com');
        Http::fake();

        expect(fn () => deliver(new PlainMailable))->toThrow(InvalidBaseUrlException::class);

        Http::assertNothingSent();
    });

    it('applies the configured timeouts and refuses redirects', function () {
        config()->set('usesend.timeout', 12);
        config()->set('usesend.connect_timeout', 3);

        $options = [];

        Http::globalMiddleware(Middleware::tap(function (RequestInterface $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;
        }));
        Http::fake();

        deliver(new PlainMailable);

        expect($options)->toMatchArray(['timeout' => 12, 'connect_timeout' => 3, 'allow_redirects' => false]);
    });
});

describe('the useSend email id', function () {
    it('becomes the message id and a header on the message', function () {
        Http::fake(['*' => Http::response(['emailId' => 'email_123'])]);

        $headerValue = null;

        Event::listen(function (MessageSent $event) use (&$headerValue) {
            $headerValue = $event->message->getHeaders()->get(UseSendTransport::EMAIL_ID_HEADER)?->getBodyAsString();
        });

        $sent = deliver(new PlainMailable);

        expect($sent?->getMessageId())->toBe('email_123')
            ->and($headerValue)->toBe('email_123');
    });

    it('records the id on the sent copy, not the message passed in', function () {
        Http::fake(['*' => Http::response(['emailId' => 'email_1'])]);

        $email = (new Email)->from('events@example.com')->to('jane@example.com')->subject('Hi')->text('Body');

        $original = (new UseSendTransport)->send($email)?->getOriginalMessage();

        expect($original instanceof Email ? MessageHeaders::get($original, UseSendTransport::EMAIL_ID_HEADER) : null)->toBe('email_1')
            ->and($email->getHeaders()->has(UseSendTransport::EMAIL_ID_HEADER))->toBeFalse();
    });

    it('leaves the message id alone without one', function (array|string $body) {
        Http::fake(['*' => Http::response($body)]);

        $sent = deliver(new PlainMailable);
        $original = $sent?->getSymfonySentMessage()->getOriginalMessage();

        expect($sent?->getMessageId())->toContain('@')
            ->and($original instanceof Email && $original->getHeaders()->has(UseSendTransport::EMAIL_ID_HEADER))->toBeFalse();
    })->with([
        'an empty body' => [''],
        'no email id' => [[]],
        'a blank email id' => [['emailId' => '']],
        'a non-string email id' => [['emailId' => 42]],
    ]);

    it('refuses a successful response that is not json', function () {
        Http::fake(['*' => Http::response('<!doctype html><title>Sign in</title>', 200, ['Content-Type' => 'text/html'])]);

        $exception = failedDelivery(new PlainMailable);

        expect($exception->status)->toBe(200)
            ->and($exception->getMessage())->toContain('not JSON, so the email may not have been sent');
    });
});

describe('failures', function () {
    it('turns a rejected email into a readable error', function () {
        Http::fake(['*' => useSendError(403, 'FORBIDDEN', "API key doesn't have access to this domain")]);

        $exception = failedDelivery(new PlainMailable);

        expect($exception->status)->toBe(403)
            ->and($exception->errorCode)->toBe('FORBIDDEN')
            ->and($exception->getMessage())->toBe(
                "useSend returned HTTP 403 (Forbidden): API key doesn't have access to this domain [POST ".ENDPOINT.']',
            )
            ->and($exception->getMessage())->not->toContain('us_test_key');
    });

    it('reports failed validation field by field', function () {
        Http::fake(['*' => Http::response([
            'success' => false,
            'error' => ['name' => 'ZodError', 'issues' => [['path' => ['subject'], 'message' => 'Required']]],
        ], 400)]);

        expect(failedDelivery(new PlainMailable)->getMessage())->toContain('HTTP 400 (Bad Request): subject: Required');
    });

    it('explains an unreachable host', function () {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        expect(failedDelivery(new PlainMailable)->getMessage())
            ->toBe('Could not reach useSend at '.ENDPOINT.': Connection refused');
    });

    it('does not follow a redirect', function () {
        Http::fake(['*' => Http::response('', 301, ['Location' => 'https://app.usesend.com/login'])]);

        expect(failedDelivery(new PlainMailable)->getMessage())->toContain('redirecting to https://app.usesend.com/login');

        Http::assertSentCount(1);
    });
});

describe('retries', function () {
    it('sends once and never retries by default', function () {
        Http::fake(['*' => Http::response('', 503)]);

        expect(failedDelivery(new PlainMailable)->status)->toBe(503);

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    });

    it('makes the configured number of extra attempts', function (int $retries) {
        enableRetries($retries);
        Http::fake(['*' => Http::response('', 503)]);

        expect(failedDelivery(new PlainMailable)->getMessage())->toContain('HTTP 503');

        Http::assertSentCount($retries + 1);
    })->with([1, 2, 3]);

    it('stops retrying once a send succeeds', function () {
        enableRetries(3);
        Http::fake(['*' => Http::sequence()->push('', 500)->push('', 502)->push(['emailId' => 'email_1'])]);

        expect(deliver(new PlainMailable)?->getMessageId())->toBe('email_1');

        Http::assertSentCount(3);
    });

    it('retries a connection failure', function () {
        enableRetries(1);

        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            return ++$attempts === 1
                ? throw new ConnectionException('Connection timed out')
                : Http::response(['emailId' => 'email_1']);
        });

        expect(deliver(new PlainMailable)?->getMessageId())->toBe('email_1')
            ->and($attempts)->toBe(2);
    });

    it('gives up on a host that stays unreachable', function () {
        enableRetries(2);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        expect(fn () => deliver(new PlainMailable))->toThrow(ApiRequestFailedException::class, 'Could not reach useSend');
    });

    it('never retries a rejected email', function (int $status) {
        enableRetries(3);
        Http::fake(['*' => Http::response(['error' => ['message' => 'No']], $status)]);

        expect(failedDelivery(new PlainMailable)->status)->toBe($status);

        Http::assertSentCount(1);
    })->with([400, 401, 403, 404, 422]);

    it('waits between attempts', function () {
        enableRetries(2, sleep: 250);
        Http::fake(['*' => Http::response('', 500)]);

        failedDelivery(new PlainMailable);

        Sleep::assertSequence([Sleep::for(250)->milliseconds(), Sleep::for(250)->milliseconds()]);
    });

    it('retries a rate limit after the time useSend asks for', function () {
        enableRetries(1, sleep: 100);
        Http::fake(['*' => Http::sequence()
            ->pushResponse(useSendError(429, 'RATE_LIMITED', 'Rate limit exceeded.', ['Retry-After' => '2'])())
            ->push(['emailId' => 'email_1']),
        ]);

        expect(deliver(new PlainMailable)?->getMessageId())->toBe('email_1');

        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    });

    it('waits at least the configured time for a rate limit', function (?string $retryAfter) {
        enableRetries(1, sleep: 300);
        Http::fake(['*' => Http::sequence()
            ->push('', 429, $retryAfter === null ? [] : ['Retry-After' => $retryAfter])
            ->push(['emailId' => 'email_1']),
        ]);

        deliver(new PlainMailable);

        Sleep::assertSequence([Sleep::for(300)->milliseconds()]);
    })->with([
        'no header' => [null],
        'a shorter wait' => ['0'],
        'an http date' => ['Wed, 21 Oct 2026 07:28:00 GMT'],
    ]);

    it('caps a long rate limit wait', function () {
        enableRetries(1);
        Http::fake(['*' => Http::sequence()->push('', 429, ['Retry-After' => '3600'])->push(['emailId' => 'email_1'])]);

        deliver(new PlainMailable);

        Sleep::assertSequence([Sleep::for(60)->seconds()]);
    });

    it('retries while a duplicate of the send is still in progress', function () {
        enableRetries(1);
        Http::fake(['*' => Http::sequence()
            ->pushResponse(useSendError(409, 'NOT_UNIQUE', 'Request with same Idempotency-Key is in progress. Retry later.')())
            ->push(['emailId' => 'email_original']),
        ]);

        expect(deliver(new PlainMailable)?->getMessageId())->toBe('email_original');

        Http::assertSentCount(2);
    });

    it('never retries a key reused for a different email', function () {
        enableRetries(3);
        Http::fake(['*' => useSendError(409, 'NOT_UNIQUE', 'Idempotency-Key already used with a different payload')]);

        expect(failedDelivery(mailable(fn (ConfigurableMailable $mail) => $mail->useSendIdempotencyKey('order-42')))->errorCode)
            ->toBe('NOT_UNIQUE');

        Http::assertSentCount(1);
    });
});

describe('idempotency', function () {
    it('sends no key when nothing asks for one', function () {
        Http::fake();

        deliver(new PlainMailable);

        expect(sentIdempotencyKeys())->toBe([null]);
    });

    it('generates one key per send when retries are on', function () {
        enableRetries();
        Http::fake();

        deliver(new PlainMailable);
        deliver(new PlainMailable);

        [$first, $second] = sentIdempotencyKeys();

        expect($first)->toBeString()->toHaveLength(36)
            ->and($second)->toBeString()->toHaveLength(36);
        expect($first)->not->toBe($second);
    });

    it('shares the generated key across the retries of a send', function () {
        enableRetries();
        Http::fake(['*' => Http::sequence()->push('', 500)->push('', 500)->push(['emailId' => 'email_1'])]);

        deliver(new PlainMailable);

        $keys = sentIdempotencyKeys();

        expect($keys)->toHaveCount(3)
            ->and(array_unique($keys))->toHaveCount(1)
            ->and($keys[0])->not->toBeNull();
    });

    it('sends the key set on the message, with or without retries', function (int $retries) {
        enableRetries($retries);
        Http::fake();

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail->useSendIdempotencyKey('order-42')));
        deliver(mailable(fn (ConfigurableMailable $mail) => $mail->useSendIdempotencyKey('order-42')));

        expect(sentIdempotencyKeys())->toBe(['order-42', 'order-42'])
            ->and(sentRequests()[0]->data())->not->toHaveKey('headers');
    })->with([0, 2]);

    it('shares the message key across retries', function () {
        enableRetries();
        Http::fake(['*' => Http::sequence()->push('', 500)->push(['emailId' => 'email_1'])]);

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail->useSendIdempotencyKey('order-42')));

        expect(sentIdempotencyKeys())->toBe(['order-42', 'order-42']);
    });

    it('accepts the key as a plain header', function () {
        Http::fake();

        deliver(mailableWithHeaders([UseSendTransport::IDEMPOTENCY_KEY_HEADER => '  signup-7  ']));

        expect(sentIdempotencyKeys())->toBe(['signup-7']);
    });

    it('keeps the last key when one is set twice', function () {
        Http::fake();

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail->useSendIdempotencyKey('first')->useSendIdempotencyKey('second')));

        expect(sentIdempotencyKeys())->toBe(['second']);
    });

    it('ignores a blank key', function () {
        Http::fake();

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail->useSendIdempotencyKey('   ')));

        expect(sentIdempotencyKeys())->toBe([null]);
    });

    it('accepts a key of the longest length useSend allows, counted in characters', function () {
        Http::fake();

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail->useSendIdempotencyKey(str_repeat('é', 256))));

        expect(sentIdempotencyKeys())->toBe([str_repeat('é', 256)]);
    });

    it('rejects a longer key before sending', function () {
        Http::fake();

        expect(fn () => deliver(mailable(fn (ConfigurableMailable $mail) => $mail->useSendIdempotencyKey(str_repeat('k', 257)))))
            ->toThrow(InvalidIdempotencyKeyException::class, 'is 257 characters long but useSend accepts at most 256');

        Http::assertNothingSent();
    });
});

describe('concerns', function () {
    it('sends a template mailable', function () {
        Http::fake();

        deliver(new TemplateMailable);

        expect(sentPayload())->toBe([
            'to' => ['jane@example.com'],
            'from' => 'events@example.com',
            'subject' => 'Subject the template replaces',
            'templateId' => 'welcome_template',
            'variables' => ['name' => 'Jane', 'attempts' => '3', 'vip' => 'true', 'nothing' => ''],
            'html' => '<p>Body the template replaces</p>',
        ]);
    });

    it('replies to an earlier useSend email', function () {
        Http::fake();

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail->useSendTemplate('reply_template', inReplyToId: 'email_41')));

        expect(sentPayload()['inReplyToId'])->toBe('email_41');
    });

    it('keeps the last template when one is set twice', function () {
        Http::fake();

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail
            ->useSendTemplate('first_template', ['name' => 'Jane'], 'email_1')
            ->useSendTemplate('second_template')));

        $payload = sentPayload();

        expect($payload)->toMatchArray(['templateId' => 'second_template', 'inReplyToId' => 'email_1']);
        expect($payload)->not->toHaveKey('variables');
    });

    it('schedules a mailable', function () {
        Http::fake();

        $at = Carbon::parse('2026-10-05 09:30:00', 'Europe/Berlin');

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail->useSendScheduledAt($at)));

        expect(sentPayload()['scheduledAt'])->toBe('2026-10-05T09:30:00+02:00');
    });

    it('keeps the last schedule when one is set twice', function () {
        Http::fake();

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail
            ->useSendScheduledAt(new DateTimeImmutable('2026-10-05T09:00:00Z'))
            ->useSendScheduledAt(new DateTimeImmutable('2026-10-06T09:00:00Z'))));

        expect(sentPayload()['scheduledAt'])->toBe('2026-10-06T09:00:00+00:00');
    });

    it('combines every concern on one mailable', function () {
        enableRetries();
        Http::fake();

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail
            ->useSendTemplate('welcome_template', ['name' => 'Jane'])
            ->useSendIdempotencyKey('welcome-7')
            ->useSendScheduledAt(new DateTimeImmutable('2026-10-05T09:00:00Z'))));

        $payload = sentPayload();

        expect($payload)->toMatchArray([
            'templateId' => 'welcome_template',
            'variables' => ['name' => 'Jane'],
            'scheduledAt' => '2026-10-05T09:00:00+00:00',
        ])->and(sentIdempotencyKeys())->toBe(['welcome-7']);
        expect($payload)->not->toHaveKey('headers');
    });
});

describe('headers and attachments', function () {
    it('forwards custom headers, tags, and metadata', function () {
        Http::fake();

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail
            ->tag('welcome')
            ->tag('onboarding')
            ->metadata('user_id', '7')
            ->withSymfonyMessage(function (Email $email): void {
                $email->getHeaders()->addTextHeader('X-Campaign', 'Spring “launch”');
            })));

        expect(sentPayload()['headers'])->toBe([
            'X-Tag' => 'welcome, onboarding',
            'X-Metadata-user_id' => '7',
            'X-Campaign' => 'Spring “launch”',
        ]);
    });

    it('forwards attachments', function () {
        Http::fake();

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail
            ->attachData('the file body', 'notes.txt', ['mime' => 'text/plain'])));

        expect(sentPayload()['attachments'])->toBe([
            ['filename' => 'notes.txt', 'content' => base64_encode('the file body')],
        ]);
    });

    it('skips inline images by default', function () {
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

        expect(fn () => deliver(embeddedImageMailable()))->toThrow(InvalidOptionException::class, 'skip, attach');

        Http::assertNothingSent();
    });

    it('sends copied recipients once, and keeps a recipient who is also copied', function () {
        Http::fake();

        deliver(mailable(fn (ConfigurableMailable $mail) => $mail
            ->to([new Address('jane@example.com', 'Jane'), 'sam@example.com'])
            ->cc(['sam@example.com', 'cc@example.com'])
            ->bcc('bcc@example.com')));

        expect(sentPayload())->toMatchArray([
            'to' => ['jane@example.com', 'sam@example.com'],
            'cc' => ['sam@example.com', 'cc@example.com'],
            'bcc' => ['bcc@example.com'],
        ]);
    });
});

function embeddedImageMailable(): ConfigurableMailable
{
    return mailable(fn (ConfigurableMailable $mail) => $mail
        ->html('<p><img src="cid:logo.png"></p>')
        ->withSymfonyMessage(function (Email $email): void {
            $email->embed('PNGDATA', 'logo.png', 'image/png');
        }));
}

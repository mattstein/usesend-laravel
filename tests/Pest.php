<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MattStein\UseSend\Tests\Fixtures\ConfigurableMailable;
use MattStein\UseSend\Tests\TestCase;
use Symfony\Component\Mime\Email;

uses(TestCase::class)->in('Feature');

const ENDPOINT = 'https://app.usesend.com/api/v1/emails';

function deliver(Mailable $mailable): ?SentMessage
{
    return Mail::mailer('usesend')->send($mailable);
}

/**
 * A mailable with every useSend concern, built by the given callback on top of
 * a valid sender, recipient, subject, and body.
 *
 * @param  (Closure(ConfigurableMailable): mixed)|null  $build
 */
function mailable(?Closure $build = null): ConfigurableMailable
{
    return new ConfigurableMailable($build ?? static fn () => null);
}

/**
 * A mailable that adds headers to its Symfony message.
 *
 * @param  array<string, string>  $headers
 */
function mailableWithHeaders(array $headers): ConfigurableMailable
{
    return mailable(fn (ConfigurableMailable $mail) => $mail->withSymfonyMessage(function (Email $email) use ($headers): void {
        foreach ($headers as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }
    }));
}

/**
 * Every request the fake saw, in order.
 *
 * @return list<Request>
 */
function sentRequests(): array
{
    return array_values(array_map(static fn (array $pair): Request => $pair[0], Http::recorded()->all()));
}

/**
 * The payload of the single request the fake saw.
 *
 * @return array<array-key, mixed>
 */
function sentPayload(): array
{
    Http::assertSentCount(1);

    return sentRequests()[0]->data();
}

/**
 * The Idempotency-Key of every request the fake saw, or null where none was sent.
 *
 * @return list<string|null>
 */
function sentIdempotencyKeys(): array
{
    return array_map(static function (Request $request): ?string {
        $key = $request->header('Idempotency-Key')[0] ?? null;

        return is_string($key) ? $key : null;
    }, sentRequests());
}

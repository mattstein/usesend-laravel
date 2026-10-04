# useSend mail transport for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mattstein/usesend-laravel.svg?style=flat-square)](https://packagist.org/packages/mattstein/usesend-laravel)
[![Tests](https://img.shields.io/github/actions/workflow/status/mattstein/usesend-laravel/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mattstein/usesend-laravel/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/dependency-v/mattstein/usesend-laravel/php.svg?style=flat-square)](https://packagist.org/packages/mattstein/usesend-laravel)
[![License](https://img.shields.io/packagist/l/mattstein/usesend-laravel.svg?style=flat-square)](LICENSE.md)

Send Laravel mail through [useSend](https://usesend.com), the open-source
transactional email service, with a first-party mail transport.

- Works with useSend Cloud, or a self-hosted instance behind your own domain.
- Maps recipients, attachments, custom headers, tags, and metadata onto useSend's send API.
- Sends through a useSend template, schedules delivery, and threads replies.
- Fails loudly: every rejection becomes an exception that says what useSend said.
- Retries connection failures, server errors, and rate limits without ever double-sending.
- Supports idempotency keys, so a retried queued job cannot double-send either.
- Keeps the email ID useSend returns, for API lookups and webhooks.
- Sends recipients so useSend’s suppression list can match them.

## Requirements

- PHP 8.2+
- Laravel 12 or 13

Laravel 10 and 11 are past end of life, and every release of them is affected
by an unpatched security advisory, so Composer will not install them.

## Installation

```bash
composer require mattstein/usesend-laravel
```

Publish the config and point it at your instance:

```bash
php artisan usesend:install
```

The command publishes `config/usesend.php` and lists the next steps. Pass
`--force` to overwrite a config file you already published.

```dotenv
USESEND_API_KEY=us_your_api_key
USESEND_BASE_URL=https://app.usesend.com
```

Then set the mailer:

```dotenv
MAIL_MAILER=usesend
```

## Usage

Mail now goes through useSend; nothing else changes.

```php
Mail::to($user)->send(new MeetupReminder($meetup));
```

### A named mailer alongside another provider

The `usesend` mailer is always available, so you can keep a second transport
without touching the global one:

```php
Mail::mailer('usesend')->send(new MeetupReminder($meetup));
```

```dotenv
MAIL_MAILER=ses
USESEND_API_KEY=us_your_api_key
```

### Per-mailer settings

Anything in `config/usesend.php` can be overridden for a single mailer, which
is handy for multi-tenant apps or a staging instance:

```php
// config/mail.php
'mailers' => [
    'usesend-staging' => [
        'transport' => 'usesend',
        'api_key' => env('USESEND_STAGING_API_KEY'),
        'base_url' => env('USESEND_STAGING_BASE_URL'),
    ],
],
```

```php
Mail::mailer('usesend-staging')->send(new MeetupReminder($meetup));
```

Settings are read per send, not cached at boot, so an app that resolves an API
key per tenant at runtime works too.

### Templates

Send a useSend template instead of the mailable’s rendered HTML. useSend
replaces the subject and HTML with the template’s:

```php
use Illuminate\Mail\Mailable;
use MattStein\UseSend\Concerns\HasUseSendTemplate;

class WelcomeMail extends Mailable
{
    use HasUseSendTemplate;

    public function build(): self
    {
        return $this->from('events@example.com')
            ->to($this->user)
            ->useSendTemplate('welcome_template', [
                'name' => $this->user->name,
                'attempts' => $this->user->attempts, // cast to a string for you
            ]);
    }
}
```

The mailable still needs a body. useSend rejects a request with neither text
nor HTML, even when it uses a template, and Laravel will not send a message
without one either. Its HTML is sent and replaced by the template; its text is
only sent when there is no HTML, because useSend does not replace text. Its
subject is sent too, and replaced by the template's: if the template ID does
not exist, useSend sends the mailable's own subject and HTML instead.

Variables are sent as strings, because that is all useSend accepts: numbers
are cast, booleans become `"true"` or `"false"`, `null` becomes `""`, and
arrays become JSON.

### Scheduled sends

Have useSend hold an email and deliver it later:

```php
use Illuminate\Mail\Mailable;
use MattStein\UseSend\Concerns\HasUseSendSchedule;

class MeetupReminder extends Mailable
{
    use HasUseSendSchedule;

    public function build(): self
    {
        return $this->subject('Your meetup is tomorrow')
            ->view('mail.reminder')
            ->useSendScheduledAt($this->meetup->starts_at->subDay());
    }
}
```

The send itself happens straight away: useSend accepts the email, returns its
ID, and delivers it at the scheduled time. A time in the past is delivered
immediately. Cancelling or rescheduling a scheduled email is done through
[useSend's API](https://docs.usesend.com) with that ID.

### Replies

Thread an email as a reply to one useSend sent earlier, by its email ID:

```php
$this->useSendTemplate('reply_template', inReplyToId: $ticket->usesend_email_id);
```

Or without a template, with the header:

```php
use MattStein\UseSend\UseSendTransport;

$this->withSymfonyMessage(function (Email $email) use ($ticket): void {
    $email->getHeaders()->addTextHeader(UseSendTransport::IN_REPLY_TO_ID_HEADER, $ticket->usesend_email_id);
});
```

To reply to mail useSend did not send, set the standard `In-Reply-To` and
`References` headers, which are forwarded as they are.

### Idempotent sends

Give a mailable an idempotency key and useSend sends it at most once per key,
for 24 hours. Derive the key from what makes the email unique, so a retried
queued job sends the same key:

```php
use Illuminate\Mail\Mailable;
use MattStein\UseSend\Concerns\HasUseSendIdempotencyKey;

class OrderConfirmation extends Mailable
{
    use HasUseSendIdempotencyKey;

    public function build(): self
    {
        return $this->to($this->order->customer)
            ->subject('Your order')
            ->view('mail.order')
            ->useSendIdempotencyKey('order-confirmation-'.$this->order->id);
    }
}
```

Or set the header yourself, which works from `headers()` or a notification:

```php
use Illuminate\Mail\Mailables\Headers;
use MattStein\UseSend\UseSendTransport;

public function headers(): Headers
{
    return new Headers(text: [
        UseSendTransport::IDEMPOTENCY_KEY_HEADER => 'order-confirmation-'.$this->order->id,
    ]);
}
```

The key goes to useSend as its `Idempotency-Key` request header, never as an
email header. Keys can be up to 256 characters. useSend answers a repeat of the
same key and email with the original email, and a repeat with a different email
with HTTP 409.

### The useSend email ID

After a send, the ID useSend returns becomes the message ID, and is added to
the message as an `X-UseSend-Email-Id` header:

```php
$sent = Mail::to($user)->send(new MeetupReminder($meetup));

$emailId = $sent?->getMessageId();
```

```php
use Illuminate\Mail\Events\MessageSent;
use MattStein\UseSend\UseSendTransport;

Event::listen(function (MessageSent $event) {
    $emailId = $event->message->getHeaders()
        ->get(UseSendTransport::EMAIL_ID_HEADER)?->getBodyAsString();
});
```

### Recipients

The `to` recipients come from the message envelope, which is who the mail is
actually delivered to, minus anyone who is only on cc or bcc. A message needs
at least one `to` recipient, because useSend requires one.

Recipients and reply-to addresses are sent without display names. useSend
checks its suppression list against the exact string it receives, so
`"Jane" <jane@example.com>` would get past a suppressed `jane@example.com`. The
sender keeps its display name.

### Custom headers, tags, and metadata

Any header a mailable sets is forwarded to useSend, except the ones that
describe the envelope or the MIME structure, which useSend owns:

```php
Mail::to($user)->send(new InviteMail($user)); // with this on the mailable

$this->withSymfonyMessage(function (Email $email): void {
    $email->getHeaders()->addTextHeader('X-Campaign', 'spring-invite');
    $email->getHeaders()->addTextHeader('List-Unsubscribe', '<mailto:stop@example.com>');
});
```

`List-Unsubscribe`, `X-Campaign`, and similar headers are all forwarded, which
copy-pasted transports usually drop. Values are sent as written, so non-ASCII
text arrives intact.

Laravel's `tag()` and `metadata()` become `X-Tag` and `X-Metadata-*` headers.
useSend takes one value per header, so repeated headers are joined: two tags
are sent as `X-Tag: welcome, onboarding`.

### Attachments

Attachments are sent base64-encoded, up to the ten useSend accepts. A part with
no filename is named after its media type (`attachment.csv`), because useSend
rejects an empty filename.

Inline images (`cid:` references) have no equivalent in useSend's send API, so
they are left out by default. Set `USESEND_INLINE_ATTACHMENTS=attach` to send
them as ordinary attachments instead.

## Configuration

| Key | Env | Default | Notes |
| --- | --- | --- | --- |
| `api_key` | `USESEND_API_KEY` | — | Required. Missing it throws before any request. |
| `base_url` | `USESEND_BASE_URL` | `https://app.usesend.com` | Falls back to `USESEND_DOMAIN`. |
| `timeout` | `USESEND_TIMEOUT` | `30` | Seconds for the whole request. |
| `connect_timeout` | `USESEND_CONNECT_TIMEOUT` | `10` | Seconds to establish the connection. |
| `retries` | `USESEND_RETRIES` | `0` | Extra attempts on connection errors, 5xx, and rate limits. |
| `retry_sleep` | `USESEND_RETRY_SLEEP` | `200` | Milliseconds between attempts. |
| `inline_attachments` | `USESEND_INLINE_ATTACHMENTS` | `skip` | `skip` or `attach`. Anything else throws. |
| `user_agent` | `USESEND_USER_AGENT` | `mattstein-usesend-laravel` | Sent on every request. |

### Self-hosted instances

`base_url` accepts the shapes people actually paste:

| Value | Request sent to |
| --- | --- |
| `app.usesend.com` | `https://app.usesend.com/api/v1/emails` |
| `https://app.usesend.com` | `https://app.usesend.com/api/v1/emails` |
| `https://app.usesend.com/api` | `https://app.usesend.com/api/v1/emails` |
| `http://localhost:3000` | `http://localhost:3000/api/v1/emails` |
| `https://example.com/usesend` | `https://example.com/usesend/api/v1/emails` |

Anything else, including an empty value, throws `InvalidBaseUrlException` rather
than guessing an instance.

### Retries

Retries are off by default. Turn them on to ride out a dropped connection, a
server error, or useSend's rate limit:

```dotenv
USESEND_RETRIES=2
```

That is up to three attempts in all, `USESEND_RETRY_SLEEP` milliseconds apart.
A rate limit waits as long as useSend's `Retry-After` asks, up to a minute. Any
other 4xx is never retried, because a rejected email will be rejected again.

Retrying is always safe. The attempts of one send share a generated
`Idempotency-Key`, so if a response is lost and the request is retried, useSend
answers with the original email instead of sending a second one. The key is
random, so two intentional sends of the same mailable stay two sends.

A generated key only covers these HTTP retries. When a queued job is retried,
it is a new send with a new key. To cover that too, set a key on the message,
as described in [Idempotent sends](#idempotent-sends).

## Errors

Everything thrown extends `UseSendException`, which implements Symfony's
`TransportExceptionInterface` so Laravel reports a failed delivery rather than an
unhandled error.

| Exception | Cause |
| --- | --- |
| `MissingApiKeyException` | No API key configured. |
| `InvalidBaseUrlException` | `base_url` is empty, malformed, or not http(s). |
| `InvalidOptionException` | A config value the transport cannot use, such as an unknown `inline_attachments` policy. |
| `MissingFromAddressException` | The message has no sender. |
| `MissingRecipientException` | The message has no `to` recipient. |
| `MissingSubjectException` | No subject and no template. |
| `MissingBodyException` | No text or HTML body. |
| `TooManyAttachmentsException` | More than ten attachments. |
| `InvalidIdempotencyKeyException` | An idempotency key over 256 characters. |
| `InvalidTemplateVariablesException` | Template variables that are not a JSON object. |
| `InvalidScheduledAtException` | A scheduled time that is not a date. |
| `UnsupportedMessageException` | A raw message, rather than a MIME message. |
| `ApiRequestFailedException` | useSend rejected the request, was unreachable, or answered with something other than its API. |

Everything except `ApiRequestFailedException` is thrown before any request is
made.

`ApiRequestFailedException` carries the HTTP `status`, useSend's `errorCode`
(such as `RATE_LIMITED` or `NOT_UNIQUE`), and the `responseBody`. Its message
includes whatever useSend said, field by field for a failed validation. The API
key is never part of a message, so these exceptions are safe to log:

```php
try {
    Mail::to($user)->send(new MeetupReminder($meetup));
} catch (ApiRequestFailedException $e) {
    Log::warning('useSend rejected an email', [
        'status' => $e->status,
        'code' => $e->errorCode,
        'body' => $e->responseBody,
    ]);
}
```

Redirects are never followed, because following one would turn the request
into a GET that can answer 200 without sending anything. A redirect, or a
successful response that is not JSON, means the base URL points at something
other than useSend's API, and fails with a message that says so.

## Testing

The transport uses Laravel's HTTP client, so `Http::fake()` works as usual:

```php
use Illuminate\Support\Facades\Http;

Http::fake();

Mail::to('jane@example.com')->send(new PlainMailable);

Http::assertSent(fn ($request) => $request->url() === 'https://app.usesend.com/api/v1/emails'
    && $request->hasHeader('Authorization', 'Bearer us_test_key')
    && $request['to'] === ['jane@example.com']);
```

To assert on a failure, fake the error useSend would return:

```php
Http::fake(['*' => Http::response(['message' => 'Invalid "from" address'], 422)]);
```

The HTTP client is resolved from the container for each send, so `Http::fake()`
applies however early the mailer was created. To test the transport on its own,
pass it an HTTP factory:

```php
use Illuminate\Http\Client\Factory;
use MattStein\UseSend\UseSendTransport;

$http = (new Factory)->fake();

(new UseSendTransport(['api_key' => 'us_test_key'], $http))->send($email);

$http->assertSentCount(1);
```

## Migrating from a hand-rolled transport

See [UPGRADING.md](UPGRADING.md) for the step-by-step swap, including the
`mail.mailers.unsend` → `mail.mailers.usesend` rename.

## How the message maps to the API

| Message | useSend field |
| --- | --- |
| Envelope recipients not only on Cc/Bcc | `to` (bare addresses) |
| Cc, Bcc | `cc`, `bcc` (bare addresses) |
| From (first address) | `from` (display name included) |
| Reply-To | `replyTo` (bare addresses) |
| Subject | `subject` |
| Text / HTML body | `text` / `html`, in UTF-8; with a template, HTML only when there is some |
| Attachments | `attachments`, base64 |
| Other headers, tags, metadata | `headers` |
| `useSendTemplate()` | `templateId`, `variables` |
| `useSendTemplate(..., inReplyToId:)` | `inReplyToId` |
| `useSendScheduledAt()` | `scheduledAt` |
| `useSendIdempotencyKey()` | `Idempotency-Key` request header |

Each concern sets a header, so the same options work from `withSymfonyMessage()`
in a notification or anywhere else a mailable trait cannot go:

| Constant on `UseSendTransport` | Header | Value |
| --- | --- | --- |
| `TEMPLATE_ID_HEADER` | `X-UseSend-Template-Id` | A template ID |
| `VARIABLES_HEADER` | `X-UseSend-Variables` | A JSON object |
| `IN_REPLY_TO_ID_HEADER` | `X-UseSend-In-Reply-To-Id` | A useSend email ID |
| `SCHEDULED_AT_HEADER` | `X-UseSend-Scheduled-At` | An ISO 8601 date |
| `IDEMPOTENCY_KEY_HEADER` | `X-UseSend-Idempotency-Key` | Up to 256 characters |

These headers are read by the transport and never sent as email headers.

The transport sends one email per message. useSend's other endpoints, such as
batch sends, cancelling or rescheduling, and contacts, are outside what a mail
transport does; use the email ID it records with [useSend's
API](https://docs.usesend.com) for those.

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.
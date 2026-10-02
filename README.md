# useSend mail transport for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mattstein/usesend-laravel.svg?style=flat-square)](https://packagist.org/packages/mattstein/usesend-laravel)
[![Tests](https://github.com/mattstein/usesend-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/mattstein/usesend-laravel/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/p/mattstein/usesend-laravel.svg?style=flat-square)](https://packagist.org/packages/mattstein/usesend-laravel)
[![License](https://img.shields.io/packagist/l/mattstein/usesend-laravel.svg?style=flat-square)](LICENSE.md)

Send Laravel mail through [useSend](https://usesend.com), the open-source
transactional email service, with a first-party mail transport.

- Works with useSend Cloud, or a self-hosted instance behind your own domain.
- Maps recipients, attachments, and custom headers onto useSend's send API.
- Sends through a useSend template instead of a rendered mailable body.
- Fails loudly: every rejection becomes an exception that says what useSend said.
- Supports retries and idempotency keys, so a flaky network or a retried job cannot double-send.
- Keeps the email ID useSend returns, for API lookups and webhooks.
- Sends recipients so useSend’s suppression list can match them.

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13

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
only sent when there is no HTML, because useSend does not replace text.

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
email header, and is used whether or not `USESEND_IDEMPOTENCY` is on. Keys can
be up to 256 characters. useSend answers a repeat of the same key and body with
the original email, and a repeat with a different body with HTTP 409.

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

### Custom headers

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
copy-pasted transports usually drop.

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
| `retries` | `USESEND_RETRIES` | `0` | Extra attempts on connection errors and 5xx. |
| `retry_sleep` | `USESEND_RETRY_SLEEP` | `200` | Milliseconds between attempts. |
| `idempotency` | `USESEND_IDEMPOTENCY` | `false` | Generates an `Idempotency-Key` per send. A key set on the message is always used. |
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

### Retries and idempotency

Retries are off by default because useSend does not de-duplicate on its own: a
retry after a lost response can send the same email twice. Turn both on and the
trade-off disappears:

```dotenv
USESEND_RETRIES=2
USESEND_IDEMPOTENCY=true
```

Each send gets one idempotency key, reused by its retries, so useSend answers a
repeat with the original email instead of sending again. Two intentional sends
stay two sends. A 4xx is never retried, because a rejected payload will be
rejected again.

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
| `ApiRequestFailedException` | useSend rejected the request, or was unreachable. |
| `UseSendException` | A malformed template payload, or an unsupported message type. |

`ApiRequestFailedException` carries `status` and `responseBody`, and its message
includes whatever useSend said. The API key is never part of a message, so these
exceptions are safe to log:

```php
try {
    Mail::to($user)->send(new MeetupReminder($meetup));
} catch (ApiRequestFailedException $e) {
    Log::warning('useSend rejected an email', [
        'status' => $e->status,
        'body' => $e->responseBody,
    ]);
}
```

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
| Subject | `subject`, unless a template is used |
| Text / HTML body | `text` / `html`; with a template, HTML only when there is some |
| Attachments | `attachments`, base64 |
| Other headers | `headers` |
| `useSendTemplate()` | `templateId`, `variables` |
| `useSendTemplate(..., inReplyToId:)` | `inReplyToId` |
| `useSendIdempotencyKey()` | `Idempotency-Key` request header |

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.
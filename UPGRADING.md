# Upgrading

## From 0.1

- **`USESEND_IDEMPOTENCY` is gone.** Retries now always share a generated
  `Idempotency-Key`, which is all the option ever did. Remove it from `.env`
  and from a published `config/usesend.php`; it is ignored if left behind.
- **`USESEND_RETRIES` counts extra attempts.** `USESEND_RETRIES=2` now makes up
  to three attempts, as documented. 0.1 made two.
- **`UseSendException` is abstract.** Catching it still catches everything. A
  malformed template payload now throws `InvalidTemplateVariablesException`, and
  a raw message `UnsupportedMessageException`.
- **Header constants moved to `UseSendTransport`.** Replace
  `EmailPayloadBuilder::HEADER_TEMPLATE_ID` with
  `UseSendTransport::TEMPLATE_ID_HEADER`, and likewise for the others.
- **Template sends include the subject.** useSend replaces it with the
  template's, so the email is unchanged unless the template does not exist.

## From a copy-pasted `UnsendTransport`

Applications that copied a transport class into `app/Mail/Transports` can switch
to this package without changing how their mail is written. Nothing else in the
app needs to move.

### 1. Install the package

```bash
composer require mattstein/usesend-laravel
```

### 2. Publish the config

```bash
php artisan vendor:publish --tag=usesend-config
```

### 3. Rename the environment variables

The package uses the product's current name:

```dotenv
USESEND_API_KEY=us_your_api_key
USESEND_BASE_URL=https://app.usesend.com
```

If your app already had `UNSEND_API_KEY` and `UNSEND_DOMAIN`, rename them.
`USESEND_DOMAIN` is still read as a fallback for `USESEND_BASE_URL`, but the
rename keeps things consistent.

### 4. Point the mailer at the transport

Delete the `unsend` block from `config/mail.php` and set the mailer name:

```dotenv
MAIL_MAILER=usesend
```

Or keep a named mailer:

```php
// config/mail.php
'mailers' => [
    'usesend' => ['transport' => 'usesend'],
],
```

### 5. Remove the old code

Delete `app/Mail/Transports/UnsendTransport.php` and the `Mail::extend('unsend', ...)`
call from your `AppServiceProvider`. The package registers the transport itself
through Laravel's package discovery.

### What changes

| Before | After |
| --- | --- |
| `MAIL_MAILER=unsend` | `MAIL_MAILER=usesend` |
| `UNSEND_API_KEY` | `USESEND_API_KEY` |
| `UNSEND_DOMAIN` | `USESEND_BASE_URL` |
| `Mail::mailer('unsend')` | `Mail::mailer('usesend')` |
| `config('mail.mailers.unsend.api_key')` | `config('usesend.api_key')` |

Mailables, notifications, and queued mail keep working untouched.

### What improves

- **Custom headers are forwarded.** A copy-pasted transport usually drops
  everything except the envelope headers, so `List-Unsubscribe`, `X-Campaign`,
  and similar headers now reach useSend.
- **A rejected send is loud.** A failed API call used to be swallowed; it now
  throws `ApiRequestFailedException` with useSend's own error message.
- **Attachments are validated.** More than ten attachments, or a part with no
  filename, fails with a clear message instead of a confusing API error.
- **The base URL is normalised.** A value like `https://app.usesend.com/api`
  from the docs, or a bare `app.usesend.com`, works instead of producing a
  malformed URL.
- **Retries are available**, and never double-send.
- **Idempotency survives queue retries.** Set a key with
  `useSendIdempotencyKey()`, or keep the `X-Unsend-Idempotency-Key` header your
  mailables already set by renaming it to `X-UseSend-Idempotency-Key`.
- **The useSend email ID is kept.** If you read `X-Unsend-Email-ID` in a
  `MessageSent` listener, read `UseSendTransport::EMAIL_ID_HEADER` instead.
- **Suppressed recipients stay suppressed.** Recipients are sent without
  display names, so useSend’s suppression list matches them.

## Versioning

This package follows [SemVer](https://semver.org). Breaking changes to the
configuration keys, the mailer name, or the request shape are announced here
before a major release is tagged.
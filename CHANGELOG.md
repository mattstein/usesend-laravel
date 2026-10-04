# Changelog

All notable changes to `usesend-laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `HasUseSendSchedule` concern and `X-UseSend-Scheduled-At` header, for useSend's
  `scheduledAt`.
- Retries cover rate limits (HTTP 429), waiting as long as `Retry-After` asks,
  up to a minute.
- Retries cover a 409 for a duplicate that useSend is still processing, which a
  retry after a timeout can run into.
- `ApiRequestFailedException::$errorCode`, holding useSend's error code, such as
  `RATE_LIMITED`.
- `UseSendTransport` constants for every header the package reads, so the
  options work from a notification's `withSymfonyMessage()`.
- `In-Reply-To` and `References` headers are forwarded, for replies to mail
  useSend did not send.

### Changed

- Retries always send a generated `Idempotency-Key`, so they can no longer
  double-send. The `idempotency` option and `USESEND_IDEMPOTENCY` are removed:
  a generated key only ever protected retries.
- Template sends include the mailable's subject, which useSend uses if the
  template does not exist.
- Repeated headers, such as one `X-Tag` per Laravel tag, are joined into one
  instead of keeping only the last.
- Calling a concern's method twice replaces the earlier value.
- `UseSendException` is abstract. A malformed template payload throws
  `InvalidTemplateVariablesException`, and a raw message throws
  `UnsupportedMessageException`.
- The `EmailPayloadBuilder::HEADER_*` constants are replaced by the
  `UseSendTransport` constants. Classes in `MattStein\UseSend\Support` are
  marked `@internal`.

### Fixed

- `USESEND_RETRIES` now counts extra attempts, as documented. It was passed to
  Laravel as the total, so `USESEND_RETRIES=1` never retried.
- useSend's errors are read from its `{"error": {"code", "message"}}` shape and
  its validation issues, instead of being quoted as raw JSON.
- The 409 message no longer suggests turning idempotency off.
- Redirects are not followed. Following one turned the POST into a GET that
  could answer 200 without sending anything.
- A successful response that is not JSON, such as a web page at a mistyped base
  URL, fails instead of passing as a send.
- Header values are sent as written. Non-ASCII values were MIME-encoded first.
- Nested template variables keep Unicode and slashes as they are, so `Zürich`
  no longer reaches the email as `Z\u00fcrich`.
- Bodies in charsets other than UTF-8 are converted, and stream bodies are read
  from the start.
- The package requires the `illuminate/console`, `illuminate/container`,
  `symfony/mailer`, and `symfony/mime` packages it uses.

## [0.1.0] - 2026-10-02

### Added

- `usesend` mail transport for useSend Cloud and self-hosted instances.
- Publishable configuration (`php artisan usesend:install`) with API key, base
  URL, timeouts, retries, idempotency, inline attachment policy, and user agent.
- Per-mailer overrides, so `mail.mailers.usesend.*` can point one mailer at a
  different instance, resolved per send.
- `HasUseSendTemplate` concern for sending a useSend template, with variables and
  an optional `inReplyToId` for threading.
- Opt-in retries that skip 4xx responses, and an `Idempotency-Key` header shared
  across the retries of a single send.
- `ApiRequestFailedException` carrying the HTTP status and response body, with
  useSend's error message folded into the exception message.
- Base URL normalisation: bare hosts, ports, path prefixes, and a `/api` suffix
  copied from the docs are all accepted.
- `HasUseSendIdempotencyKey` concern and `X-UseSend-Idempotency-Key` header for
  a caller-chosen idempotency key that survives queued job retries.
- The useSend email ID is recorded as the sent message ID and an
  `X-UseSend-Email-Id` header.
- `usesend:install --force` overwrites an existing config file.

### Fixed

- `BaseUrl` keeps a non-default port when cast to a string.
- `USESEND_INLINE_ATTACHMENTS=attach` now attaches inline images; the setting
  was read under the wrong key and ignored.
- Template sends include the HTML body, which useSend requires even with a
  template.
- Recipients and reply-to addresses are sent without display names, so
  useSend’s suppression list matches them.
- The `to` recipients come from the envelope, and a message with no `to`
  recipient or no body fails with a clear exception before any request.

### Removed

- Support for Laravel 10 and 11, which are past end of life and affected by
  unpatched security advisories. The package now requires PHP 8.2+ and Laravel
  12.60+ or 13.10+, the first releases with the fix for Laravel’s CRLF
  injection in the email validation rule.
- The `spatie/laravel-package-tools` dependency, and the install command's
  prompt to star the repository.

[Unreleased]: https://github.com/mattstein/usesend-laravel/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/mattstein/usesend-laravel/releases/tag/v0.1.0

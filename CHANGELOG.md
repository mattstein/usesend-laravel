# Changelog

All notable changes to `usesend-laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
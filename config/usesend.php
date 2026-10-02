<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    |
    | The API key for your useSend account or self-hosted instance. Create one
    | in the useSend dashboard, or copy it from the environment variable your
    | instance was seeded with.
    |
    */

    'api_key' => env('USESEND_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | The root URL of your useSend installation. useSend's cloud service lives
    | at https://app.usesend.com; self-hosted instances use their own domain,
    | optionally with a path prefix when useSend is mounted behind a proxy.
    |
    | Both "app.usesend.com" and "https://app.usesend.com" are accepted. The
    | deprecated USESEND_DOMAIN variable is honoured as a fallback.
    |
    */

    'base_url' => env('USESEND_BASE_URL', env('USESEND_DOMAIN', 'https://app.usesend.com')),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for the API to respond, and seconds to wait for the
    | connection to be established. Connection timeouts are kept well below the
    | total timeout so a dead host fails fast instead of stalling a queue
    | worker.
    |
    */

    'timeout' => (int) env('USESEND_TIMEOUT', 30),

    'connect_timeout' => (int) env('USESEND_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Number of additional attempts made when the API request fails, and the
    | delay between them. Retries are off by default because useSend has no
    | built-in de-duplication: enabling retries without an idempotency key can
    | send the same email twice if a response is lost in transit. Enable
    | 'idempotency' below at the same time and retries become safe.
    |
    */

    'retries' => (int) env('USESEND_RETRIES', 0),

    'retry_sleep' => (int) env('USESEND_RETRY_SLEEP', 200),

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | When enabled, every request carries an "Idempotency-Key" header. The key
    | is generated once per send and reused by the retry loop, so useSend
    | collapses a retried request into the original email instead of sending
    | it twice. useSend remembers a key for 24 hours.
    |
    | Note that the key is not derived from the message: sending the same
    | mailable twice still sends twice, and a retried queued job is a new
    | send with a new key. To cover those, give the message its own key with
    | the HasUseSendIdempotencyKey concern; that key is used whether or not
    | this option is on.
    |
    */

    'idempotency' => filter_var(env('USESEND_IDEMPOTENCY', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Inline attachments
    |--------------------------------------------------------------------------
    |
    | Inline attachments (the images behind "cid:" references) have no
    | equivalent in useSend's send API. "skip" leaves them out of the request;
    | "attach" sends them as ordinary attachments with a generated filename.
    |
    */

    'inline_attachments' => env('USESEND_INLINE_ATTACHMENTS', 'skip'),

    /*
    |--------------------------------------------------------------------------
    | User agent
    |--------------------------------------------------------------------------
    |
    | Sent with every request so useSend can tell package traffic apart.
    |
    */

    'user_agent' => env('USESEND_USER_AGENT', 'mattstein-usesend-laravel'),

];

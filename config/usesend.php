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
    | Additional attempts made after a connection failure, a server error, or
    | a rate limit, and the milliseconds to wait between them. A rate limit's
    | Retry-After is honoured. A rejected email (any other 4xx) is never
    | retried, because it would only be rejected again.
    |
    | Retried requests share an Idempotency-Key, so useSend answers a retry
    | with the original email rather than sending it twice.
    |
    */

    'retries' => (int) env('USESEND_RETRIES', 0),

    'retry_sleep' => (int) env('USESEND_RETRY_SLEEP', 200),

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

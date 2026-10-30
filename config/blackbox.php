<?php

use Blackbox\Sinks\StreamSink;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When disabled the middleware only propagates the request id; no audit
    | record is written.
    |
    */

    'enabled' => env('BLACKBOX_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Request id
    |--------------------------------------------------------------------------
    |
    | The incoming header is trusted when it matches `pattern` (nginx sets it
    | from $request_id). Otherwise a new 32-char hex id is generated, the same
    | format nginx uses.
    |
    */

    'request_id' => [
        'header' => 'X-Request-Id',
        'response_header' => 'X-Request-Id',
        'trust_incoming' => true,
        'pattern' => '/^[A-Za-z0-9._:\-]{1,64}$/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Client session
    |--------------------------------------------------------------------------
    |
    | Headers checked in order to find the client session. The frontend sends
    | X-Session-Id (e.g. OpenReplay's tracker.getSessionID()). Add more headers
    | as fallbacks if needed. Values longer than `max_length` are dropped.
    |
    */

    'session' => [
        'headers' => [
            'X-Session-Id',
        ],
        'max_length' => 512,
    ],

    /*
    |--------------------------------------------------------------------------
    | User
    |--------------------------------------------------------------------------
    |
    | Only guards that already resolved a user during the request are read, so
    | auditing never triggers an extra authentication. `guards` = null checks
    | every guard resolved so far.
    |
    | The id is never sent in a response header. It goes to the audit record
    | and, with `log_context`, to the context of every log line (`user_id`).
    |
    | `resolver` is an optional invokable class: fn (Request $request): ?array.
    |
    */

    'user' => [
        'guards' => null,
        'attributes' => [],
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    |
    | Optional invokable class: fn (Request $request): string|int|null.
    |
    */

    'tenant' => [
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recording
    |--------------------------------------------------------------------------
    */

    'except' => [
        'livez',
        'readyz',
        'horizon/*',
        'telescope/*',
        '_debugbar/*',
    ],

    // Per HTTP method, from 0.0 to 1.0. `*` applies to methods not listed.
    'sampling' => [
        '*' => 1.0,
    ],

    // Redacted values keep up to 3 characters at each end: "password" → "pas****ord".
    'headers' => [
        // Empty records every header; otherwise only the listed ones.
        'only' => [],
        'redact' => [
            'authorization',
            'proxy-authorization',
            'cookie',
            'set-cookie',
            'x-api-key',
            'x-csrf-token',
            'x-xsrf-token',
            'php-auth-pw',
        ],
    ],

    'payload' => [
        // Case-insensitive, `*` wildcards allowed. Applied to body and query.
        'redact' => [
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'pin',
            'token',
            'access_token',
            'refresh_token',
            'secret',
            'client_secret',
            'api_key',
            'card*',
            'cvv',
            'cvc',
        ],
        // Never truncated. Payloads whose JSON is larger than this are stored as
        // base64 gzip and `payload_encoding` becomes `json+gzip`. 0 disables it.
        'gzip_above_bytes' => 65536,
        // Keep the raw body for content types that are neither JSON nor form (XML, SOAP, text).
        'raw' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sink
    |--------------------------------------------------------------------------
    |
    | One JSON line per request, appended after the response is sent. Add
    | `{date}` to the path to get one file per UTC day. Use php://stderr
    | to write to the container log instead.
    |
    */

    'sink' => [
        'driver' => StreamSink::class,
        'path' => env('BLACKBOX_PATH', storage_path('logs/blackbox.jsonl')),
        'lock' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    */

    'log_context' => true,

    'sentry' => [
        'enabled' => true,
        'tags' => [
            'request_id' => 'request_id',
            'session_id' => 'session_id',
        ],
    ],

    'queue' => [
        'propagate' => true,
    ],
];

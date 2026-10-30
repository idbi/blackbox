# Blackbox

[![tests](https://github.com/idbi/blackbox/actions/workflows/tests.yml/badge.svg)](https://github.com/idbi/blackbox/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Request tracing and an audit trail for Laravel, written after the response is sent.

Every request gets:

- a **request id**, taken from nginx's `X-Request-Id` or generated, returned in the response and added to every log line;
- a **client session id** from `X-Session-Id` (OpenReplay or any session replay tool), so you can go from a replay to its backend requests and back;
- an **audit record**: who, route, query, payload, headers, status and duration. Secrets are partially masked (`password` → `pas****ord`), payloads are never truncated, and uploaded files are described but never stored.

The user id stays on the server: it goes to the audit record and to the log context, never to a response header.

Supports Laravel 8 to 13 on PHP 8.1 or newer.

## Install

```bash
composer require idbi/blackbox
php artisan vendor:publish --tag=blackbox-config   # optional
```

Register the middleware first in the global stack, so every log line of the request carries the ids.

Laravel 11 and later, in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(\Blackbox\Http\Middleware\TraceRequest::class);
})
```

Laravel 8 to 10, in `app/Http/Kernel.php`:

```php
protected $middleware = [
    \Blackbox\Http\Middleware\TraceRequest::class,
    // ...
];
```

## How it works

| Step | When | Cost (PHP 8.5, JIT) |
|---|---|---|
| `handle()`: request id, session id, log context, Sentry tags | before your controller; the client waits for it | 5–8 µs |
| `terminate()`: build the record and append one line to the file | after the response is sent | 35 µs for a typical request, 1.4 ms for a 120 KB payload |

Under PHP-FPM, LiteSpeed or Octane the response is already on its way when `terminate()` runs, so users don't wait for the audit record. It only keeps the worker busy for that long. With Apache `mod_php` or `php artisan serve` the connection can stay open until `terminate()` ends.

Measured with `php bench/middleware.php`:

| Request | Client waits | After the response | Log line |
|---|---|---|---|
| GET, no body | 6 µs | 34 µs | 0.6 KB |
| Login, 5 fields | 5 µs | 35 µs | 0.7 KB |
| Order, 20 items | 6 µs | 70 µs | 3.2 KB |
| Import, 1,000 items (gzipped) | 6 µs | 1.35 ms | 20 KB, from 124 KB |

## The audit record

One JSON line per request in `storage/logs/blackbox.jsonl`. `php://stderr` works too.

```json
{"ts":"2026-10-29T15:04:05.123Z","request_id":"4f0c2a9e…","session_id":"8812…",
 "user":{"id":7,"type":"user","guard":"api"},"tenant":null,"ip":"10.0.0.1","user_agent":"…",
 "method":"POST","path":"/api/orders","route":{"name":"orders.store","uri":"api/orders","action":"…"},
 "query":null,"headers":{"authorization":"Bea****J9c","content-type":"application/json"},
 "payload":{"email":"ana@example.com","password":"pas****ord","items":[…]},"payload_encoding":"json",
 "files":null,"status":201,"duration_ms":84.2,"response_bytes":512}
```

| Field | |
|---|---|
| `user` | id, type and guard of the user, read only from guards that already authenticated during the request; auditing never triggers authentication |
| `payload` | JSON or form body, or the raw body for XML, SOAP and text. Never truncated |
| `payload_encoding` | `json`, `json+gzip` (see [Large payloads](#large-payloads)) or `null` without a body |
| `files` | name, size and MIME type of each upload, never its content |
| `duration_ms` | from `LARAVEL_START` (or `REQUEST_TIME_FLOAT`) to `terminate()` |

## Logs, Sentry and queues

- **Log context.** Every log line of the request gets `request_id` and `session_id`. Once a guard has authenticated (session, Sanctum, Passport or any other), lines also get `user_id`. Turn it off with `log_context => false`.
- **Sentry.** If `sentry/sentry-laravel` is installed, events are tagged with `request_id` and `session_id`. Rename the tags with `sentry.tags`.
- **Queues.** Jobs dispatched during a request carry its `request_id` and `session_id`, and their log lines have them too. Disable it with `queue.propagate => false`.

Read the ids anywhere:

```php
app(\Blackbox\RequestTrace::class)->requestId;
```

## Masking

Sensitive keys (`payload.redact`, `headers.redact`) keep up to 3 characters at each end and hide the middle behind a fixed `****`, so the length isn't revealed. At least one character is always hidden.

| Value | Stored |
|---|---|
| `password` | `pas****ord` |
| `4111111111111111` | `411****111` |
| `123456` | `12****56` |
| `1234` | `1****4` |
| `ab` | `a****` |
| `a` | `****` |

Keys are case-insensitive and accept `*` wildcards (`card*`). A sensitive key holding an object is masked leaf by leaf, and `null` stays `null`.

## Large payloads

Payloads are stored as inline JSON so Loki can filter on them. When the JSON is larger than `payload.gzip_above_bytes` (64 KB), it's gzipped and base64-encoded instead, and `payload_encoding` becomes `json+gzip`. A 124 KB import with 1,000 items takes 20 KB. Decode one with:

```bash
jq -r 'select(.payload_encoding == "json+gzip") | .payload' blackbox.jsonl \
  | head -1 | base64 -d | gunzip | jq
```

Below about 1 KB, gzip plus base64 is larger than the JSON itself, which is why only large payloads are compressed.

## Shipping to Loki with Alloy

```alloy
local.file_match "blackbox" {
  path_targets = [{"__path__" = "/var/www/app/storage/logs/blackbox.jsonl"}]
}

loki.source.file "blackbox" {
  targets    = local.file_match.blackbox.targets
  forward_to = [loki.process.blackbox.receiver]
}

loki.process "blackbox" {
  stage.json {
    expressions = { ts = "", request_id = "", method = "", status = "" }
  }
  stage.timestamp {
    source = "ts"
    format = "RFC3339"
  }
  stage.static_labels {
    values = { job = "blackbox" }
  }
  stage.labels {
    values = { method = "", status = "" }
  }
  // High-cardinality ids go to structured metadata, not labels.
  stage.structured_metadata {
    values = { request_id = "" }
  }
  forward_to = [loki.write.default.receiver]
}

loki.write "default" {
  endpoint {
    url = "http://loki:3100/loki/api/v1/push"
  }
}
```

Query it with LogQL:

```logql
{job="blackbox"} | json | user_id = "7" and status >= 500
```

Keep local files small with logrotate; Alloy follows rotated files and Loki compresses on its side:

```
/var/www/app/storage/logs/blackbox.jsonl {
    daily
    maxsize 100M
    rotate 2
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```

Check Loki's `limits_config.max_line_size`: lines above it are rejected unless `max_line_size_truncate` is on.

## nginx

`php artisan vendor:publish --tag=blackbox-nginx` copies `nginx/blackbox.conf` with a `map`, a JSON `log_format` and the fastcgi lines. It passes nginx's `$request_id` to PHP, so the access log and the audit record share the id, and it writes a line even when PHP never answers (502, 504).

## Frontend

- Send the session id on every API call in `X-Session-Id`, e.g. OpenReplay's `tracker.getSessionID()`, and add it to CORS `allowed_headers`.
- Add `X-Request-Id` to CORS `exposed_headers` so it shows in the replay's network tab.

## Configuration

| Key | Default | |
|---|---|---|
| `enabled` | `BLACKBOX_ENABLED=true` | `false` keeps the ids and log context, writes no records |
| `request_id.trust_incoming` | `true` | reuse a valid incoming `X-Request-Id` |
| `session.headers` | `['X-Session-Id']` | first valid one wins |
| `user.guards` | `null` (all) | guards to read the user from |
| `user.attributes` | `[]` | extra user fields, e.g. `['uuid']` |
| `user.resolver`, `tenant.resolver` | `null` | invokable class, `fn (Request $request)` |
| `except` | livez, readyz, horizon, telescope, debugbar | `Request::is()` patterns that are not recorded |
| `sampling` | `['*' => 1.0]` | rate per HTTP method, e.g. `['GET' => 0.1]` |
| `headers.only` | `[]` (all) | record only these headers |
| `headers.redact`, `payload.redact` | auth, cookies, passwords, tokens, `card*`, `cvv`… | partially masked keys |
| `payload.gzip_above_bytes` | `65536` | gzip larger payloads; `0` disables |
| `payload.raw` | `true` | keep bodies that are neither JSON nor form |
| `sink.path` | `storage/logs/blackbox.jsonl` | add `{date}` for one file per UTC day |
| `log_context` | `true` | ids and `user_id` in every log line |
| `sentry.enabled`, `sentry.tags` | `true`, `request_id`, `session_id` | |
| `queue.propagate` | `true` | carry the ids into queued jobs |

To store records somewhere else, implement `Blackbox\Sinks\Sink` and set `sink.driver`.

## Development

```bash
composer test       # PHPUnit
composer coverage   # needs PCOV or Xdebug
composer lint       # Pint, dry run
composer format     # Pint, fix
php bench/middleware.php
```

CI runs the suite on every Laravel major from 8 to 13, checks style with Pint, and fails if line coverage drops below 95%.

## License

MIT. See [LICENSE](LICENSE).

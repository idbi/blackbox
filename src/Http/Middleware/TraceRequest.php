<?php

namespace Blackbox\Http\Middleware;

use Blackbox\Log\AddUserToContext;
use Blackbox\Recorder\RecordBuilder;
use Blackbox\RequestTrace;
use Blackbox\Sinks\Sink;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use WeakMap;

/**
 * handle() only does in-memory work (id, session, log context, headers).
 * terminate() runs after the response reaches the client and writes the audit record.
 */
final class TraceRequest
{
    private static int $failures = 0;

    /** @var WeakMap<Logger, true>|null Loggers that already have the user processor. */
    private static ?WeakMap $withUser = null;

    public function __construct(
        private Container $container,
        private Repository $config,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $trace = new RequestTrace(
            $this->requestId($request),
            $this->sessionId($request),
            $this->startedAt($request),
        );

        $this->container->instance(RequestTrace::class, $trace);
        $request->headers->set($this->config->get('blackbox.request_id.header', 'X-Request-Id'), $trace->requestId);

        if ($this->config->get('blackbox.log_context', true)) {
            Log::withContext($trace->toArray());
            $this->addUserToLogs();
        }

        $this->tagSentry($trace);

        $response = $next($request);

        if ($response instanceof Response) {
            $response->headers->set($this->config->get('blackbox.request_id.response_header', 'X-Request-Id'), $trace->requestId);
        }

        return $response;
    }

    public function terminate(Request $request, $response): void
    {
        if (! $this->config->get('blackbox.enabled', true) || ! $response instanceof Response) {
            return;
        }

        if (! $this->container->bound(RequestTrace::class) || $this->skipped($request)) {
            return;
        }

        try {
            $record = $this->container->make(RecordBuilder::class)
                ->build($request, $response, $this->container->make(RequestTrace::class));

            $this->container->make(Sink::class)->write($record);
        } catch (Throwable $e) {
            // Auditing must never break the request; log the first failure per worker only.
            if (self::$failures++ === 0) {
                error_log('[blackbox] audit record dropped: '.$e->getMessage());
            }
        }
    }

    public static function failures(): int
    {
        return self::$failures;
    }

    private function requestId(Request $request): string
    {
        $incoming = $request->headers->get($this->config->get('blackbox.request_id.header', 'X-Request-Id'));
        $pattern = $this->config->get('blackbox.request_id.pattern', '/^[A-Za-z0-9._:\-]{1,64}$/');

        if (
            is_string($incoming)
            && $this->config->get('blackbox.request_id.trust_incoming', true)
            && preg_match($pattern, $incoming) === 1
        ) {
            return $incoming;
        }

        return RequestTrace::generateId();
    }

    private function sessionId(Request $request): ?string
    {
        $max = (int) $this->config->get('blackbox.session.max_length', 512);

        foreach ($this->config->get('blackbox.session.headers', []) as $header) {
            $value = $request->headers->get($header);

            if (is_string($value) && $value !== '' && strlen($value) <= $max && ! preg_match('/[\x00-\x1F\x7F]/', $value)) {
                return $value;
            }
        }

        return null;
    }

    private function startedAt(Request $request): float
    {
        if (defined('LARAVEL_START')) {
            return (float) LARAVEL_START;
        }

        return (float) ($request->server('REQUEST_TIME_FLOAT') ?? microtime(true));
    }

    private function tagSentry(RequestTrace $trace): void
    {
        if (! $this->config->get('blackbox.sentry.enabled', true) || ! function_exists('Sentry\configureScope')) {
            return;
        }

        $tags = $this->config->get('blackbox.sentry.tags', []);

        \Sentry\configureScope(function ($scope) use ($trace, $tags) {
            foreach ($trace->toArray() as $key => $value) {
                if ($value !== null && isset($tags[$key])) {
                    $scope->setTag($tags[$key], $value);
                }
            }
        });
    }

    /**
     * Registered once per logger: workers that serve many requests (Octane)
     * keep the same logger, and the processor reads the current request.
     */
    private function addUserToLogs(): void
    {
        $logger = Log::getLogger();

        if (! $logger instanceof Logger) {
            return;
        }

        self::$withUser ??= new WeakMap;

        if (! isset(self::$withUser[$logger])) {
            $logger->pushProcessor($this->container->make(AddUserToContext::class));
            self::$withUser[$logger] = true;
        }
    }

    private function skipped(Request $request): bool
    {
        $except = $this->config->get('blackbox.except', []);

        if ($except !== [] && $request->is(...$except)) {
            return true;
        }

        $sampling = $this->config->get('blackbox.sampling', []);
        $rate = (float) ($sampling[$request->getMethod()] ?? $sampling['*'] ?? 1.0);

        return $rate < 1.0 && mt_rand() / mt_getrandmax() >= $rate;
    }
}

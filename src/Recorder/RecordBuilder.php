<?php

namespace Blackbox\Recorder;

use Blackbox\RequestTrace;
use Blackbox\Support\UserResolver;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class RecordBuilder
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private Redactor $payloadRedactor;

    private Redactor $headerRedactor;

    public function __construct(
        private Container $container,
        private UserResolver $users,
        private array $config,
    ) {
        $this->payloadRedactor = new Redactor($config['payload']['redact'] ?? []);
        $this->headerRedactor = new Redactor($config['headers']['redact'] ?? []);
    }

    public function build(Request $request, Response $response, RequestTrace $trace): array
    {
        $route = $request->route();

        return [
            'ts' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'request_id' => $trace->requestId,
            'session_id' => $trace->sessionId,
            'user' => $this->users->resolve($request),
            'tenant' => $this->tenant($request),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'method' => $request->getMethod(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => is_object($route) ? [
                'name' => $route->getName(),
                'uri' => $route->uri(),
                'action' => $route->getActionName(),
            ] : null,
            'query' => $this->payloadRedactor->redact($request->query->all()) ?: null,
            'headers' => $this->headers($request),
            ...$this->encodePayload($this->payload($request)),
            'files' => $this->files($request->allFiles()),
            'status' => $response->getStatusCode(),
            'duration_ms' => $trace->elapsedMs(),
            'response_bytes' => $this->responseBytes($response),
        ];
    }

    private function tenant(Request $request): string|int|null
    {
        $resolver = $this->config['tenant']['resolver'] ?? null;

        return $resolver ? $this->container->make($resolver)($request) : null;
    }

    private function headers(Request $request): array
    {
        $only = array_map('strtolower', $this->config['headers']['only'] ?? []);
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            if ($only !== [] && ! in_array($name, $only, true)) {
                continue;
            }

            if ($this->headerRedactor->isSensitive($name)) {
                $values = array_map(fn ($value) => Redactor::mask((string) $value), $values);
            }

            $headers[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $headers;
    }

    private function payload(Request $request): array|string|null
    {
        if ($request->isJson()) {
            return $this->payloadRedactor->redact($request->json()->all()) ?: null;
        }

        $form = $request->request->all();

        if ($form !== []) {
            return $this->payloadRedactor->redact($form);
        }

        if (($this->config['payload']['raw'] ?? true) && ! $request->isMethodSafe()) {
            $content = $request->getContent();

            return $content === '' ? null : $content;
        }

        return null;
    }

    /**
     * The payload is never truncated. It stays inline so Loki can query it;
     * above `gzip_above_bytes` it is stored as base64 gzip of its JSON. Level 1
     * is ~4x faster than 6 for a slightly larger result.
     */
    private function encodePayload(array|string|null $payload): array
    {
        if ($payload === null) {
            return ['payload' => null, 'payload_encoding' => null];
        }

        $threshold = (int) ($this->config['payload']['gzip_above_bytes'] ?? 65536);

        if ($threshold > 0 && function_exists('gzencode')) {
            $json = json_encode($payload, self::JSON_FLAGS);

            if (strlen($json) > $threshold) {
                return ['payload' => base64_encode(gzencode($json, 1)), 'payload_encoding' => 'json+gzip'];
            }
        }

        return ['payload' => $payload, 'payload_encoding' => 'json'];
    }

    private function files(array $files): ?array
    {
        if ($files === []) {
            return null;
        }

        $described = [];

        foreach ($files as $key => $file) {
            $described[$key] = $file instanceof UploadedFile
                ? Redactor::describeFile($file)
                : (is_array($file) ? $this->files($file) : null);
        }

        return $described;
    }

    private function responseBytes(Response $response): ?int
    {
        $length = $response->headers->get('Content-Length');

        if ($length !== null && is_numeric($length)) {
            return (int) $length;
        }

        $content = $response->getContent();

        return is_string($content) ? strlen($content) : null;
    }
}

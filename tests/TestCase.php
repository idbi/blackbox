<?php

namespace Blackbox\Tests;

use Blackbox\BlackboxServiceProvider;
use Blackbox\Http\Middleware\TraceRequest;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Queue\Queue;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $auditPath;

    protected function getPackageProviders($app): array
    {
        return [BlackboxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->auditPath = sys_get_temp_dir().'/blackbox-test-'.getmypid().'-'.uniqid().'.jsonl';
        $app['config']->set('blackbox.sink.path', $this->auditPath);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(TraceRequest::class)->group(function ($router) {
            $router->any('/echo', fn () => response()->json(['ok' => true]))->name('echo');
            $router->get('/livez', fn () => 'ok');
            $router->get('/readyz', fn () => 'ok');
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->auditPath);
        // Payload hooks are static and would leak into the next test's app.
        Queue::createPayloadUsing(null);
        parent::tearDown();
    }

    /**
     * Kernel::terminate() is not called by the HTTP test helpers, so call it like public/index.php does.
     */
    protected function callAndTerminate(string $method, string $uri, array $data = [], array $headers = [], array $files = [], ?string $content = null)
    {
        $server = $this->transformHeadersToServerVars($headers);
        $request = Request::create($uri, $method, $data, [], $files, $server, $content);

        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * The record payload decoded, whatever the configured encoding.
     */
    protected function payloadOf(array $record): mixed
    {
        return $record['payload_encoding'] === 'json+gzip'
            ? json_decode(gzdecode(base64_decode($record['payload'], true)), true)
            : $record['payload'];
    }

    protected function records(): array
    {
        if (! is_file($this->auditPath)) {
            return [];
        }

        return array_map(
            fn ($line) => json_decode($line, true),
            array_filter(explode("\n", file_get_contents($this->auditPath)))
        );
    }
}

<?php

// Cost of TraceRequest per request, split into what the client waits for
// (handle) and what runs after the response is sent (build + write).
// Run: php bench/middleware.php

require __DIR__.'/../vendor/autoload.php';

ini_set('memory_limit', '1G'); // inputs are prebuilt, see $measure

use Blackbox\BlackboxServiceProvider;
use Blackbox\Http\Middleware\TraceRequest;
use Blackbox\Recorder\RecordBuilder;
use Blackbox\RequestTrace;
use Blackbox\Sinks\Sink;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchestra\Testbench\Foundation\Application;

$path = sys_get_temp_dir().'/blackbox-bench-'.getmypid().'.jsonl';

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
// After register(): mergeConfigFrom() only merges top-level keys.
$app->register(BlackboxServiceProvider::class);
$app['config']->set('blackbox.sink.path', $path);

mt_srand(42);
$item = fn (int $i) => ['sku' => 'SKU-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'name' => 'Producto número '.$i,
    'qty' => mt_rand(1, 20), 'price' => mt_rand(100, 99999) / 100, 'tags' => ['a', 'b', 'promo'], 'active' => (bool) ($i % 2), 'notes' => null];

$json = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('x', 40), 'HTTP_X_SESSION_ID' => 'sess-1'];
$cases = [
    'GET, no body' => fn () => Request::create('/api/orders?page=2', 'GET', [], [], [], $json),
    'login, 5 fields' => fn () => Request::create('/api/login', 'POST', [], [], [], $json,
        json_encode(['email' => 'ana@example.com', 'password' => 'hunter2hunter2', 'remember' => true, 'device' => 'ios', 'v' => 3])),
    'order, 20 items' => fn () => Request::create('/api/orders', 'POST', [], [], [], $json,
        json_encode(['customer_id' => 77, 'currency' => 'PEN', 'items' => array_map($item, range(1, 20))])),
    'batch, 1000 items (gzip)' => fn () => Request::create('/api/import', 'POST', [], [], [], $json,
        json_encode(['batch' => array_map($item, range(1, 1000))])),
];

$middleware = $app->make(TraceRequest::class);
$builder = $app->make(RecordBuilder::class);
$sink = $app->make(Sink::class);
$response = new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']);
$next = fn () => $response;

// Median of per-iteration timings, in µs. Requests are built beforehand so
// parsing the body (done by Laravel anyway) is not cached between runs.
$measure = function (callable $make, callable $fn, int $iterations): float {
    $inputs = array_map(fn () => $make(), range(1, $iterations));
    $fn($make());
    $times = [];
    foreach ($inputs as $input) {
        $start = hrtime(true);
        $fn($input);
        $times[] = (hrtime(true) - $start) / 1000;
    }
    sort($times);

    return $times[intdiv(count($times), 2)];
};

$status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
printf("PHP %s | Laravel %s | opcache %s | JIT %s\n\n", PHP_VERSION, $app->version(),
    $status ? 'on' : 'off', ($status['jit']['on'] ?? false) ? 'on' : 'off');
printf("%-26s %12s %12s %12s %12s %10s\n", 'request', 'handle µs', 'build µs', 'write µs', 'after µs', 'line B');

foreach ($cases as $name => $make) {
    $iterations = str_contains($name, '1000') ? 50 : 2000;
    $trace = new RequestTrace('4f0c2a9e1b7d4c3a8e6f5d2c1b0a9e8f', 'sess-1', microtime(true));

    $handle = $measure($make, fn ($r) => $middleware->handle($r, $next), $iterations);
    $build = $measure($make, fn ($r) => $builder->build($r, $response, $trace), $iterations);
    $record = $builder->build($make(), $response, $trace);
    $write = $measure(fn () => $record, fn ($r) => $sink->write($r), $iterations);
    $terminate = $measure($make, function ($r) use ($middleware, $response, $next) {
        $middleware->handle($r, $next);
        $middleware->terminate($r, $response);
    }, $iterations) - $handle;

    $line = strlen(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) + 1;
    printf("%-26s %12.1f %12.1f %12.1f %12.1f %10d\n", $name, $handle, $build, $write, $terminate, $line);
}

@unlink($path);

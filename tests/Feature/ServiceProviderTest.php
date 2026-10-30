<?php

namespace Blackbox\Tests\Feature;

use Blackbox\BlackboxServiceProvider;
use Blackbox\Http\Middleware\TraceRequest;
use Blackbox\RequestTrace;
use Blackbox\Sinks\Sink;
use Blackbox\Sinks\StreamSink;
use Blackbox\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

class ServiceProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('blackbox.queue.propagate', false);
    }

    public function test_it_registers_shared_services(): void
    {
        $this->assertInstanceOf(StreamSink::class, $this->app->make(Sink::class));
        $this->assertSame($this->app->make(TraceRequest::class), $this->app->make(TraceRequest::class));
    }

    public function test_it_publishes_the_config_and_the_nginx_stub(): void
    {
        $config = ServiceProvider::pathsToPublish(BlackboxServiceProvider::class, 'blackbox-config');
        $nginx = ServiceProvider::pathsToPublish(BlackboxServiceProvider::class, 'blackbox-nginx');

        $this->assertFileExists(array_key_first($config));
        $this->assertFileExists(array_key_first($nginx));
    }

    public function test_queue_propagation_can_be_disabled(): void
    {
        $this->app->instance(RequestTrace::class, new RequestTrace('req', null, microtime(true)));

        $queue = $this->app['queue']->connection('sync');
        $method = new \ReflectionMethod($queue, 'createPayload');
        $method->setAccessible(true);
        $payload = json_decode($method->invoke($queue, new CapturesTrace, 'default'), true);

        $this->assertArrayNotHasKey('blackbox', $payload);
    }
}

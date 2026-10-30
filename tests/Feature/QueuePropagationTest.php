<?php

namespace Blackbox\Tests\Feature;

use Blackbox\Queue\PropagatesTrace;
use Blackbox\RequestTrace;
use Blackbox\Tests\TestCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobProcessed;

class QueuePropagationTest extends TestCase
{
    public function test_job_payload_carries_the_trace(): void
    {
        $this->app->instance(RequestTrace::class, new RequestTrace('req-1', 'sess-1', microtime(true)));

        $queue = $this->app['queue']->connection('sync');
        $method = new \ReflectionMethod($queue, 'createPayload');
        $method->setAccessible(true);
        $payload = json_decode($method->invoke($queue, new CapturesTrace, 'default'), true);

        $this->assertSame(
            ['request_id' => 'req-1', 'session_id' => 'sess-1'],
            $payload[PropagatesTrace::PAYLOAD_KEY]
        );
    }

    public function test_sync_jobs_see_the_trace_and_leave_the_outer_one_intact(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $outer = new RequestTrace('req-2', null, microtime(true));
        $this->app->instance(RequestTrace::class, $outer);
        CapturesTrace::$seen = null;

        CapturesTrace::dispatch();

        $this->assertSame('req-2', CapturesTrace::$seen);
        $this->assertSame($outer, $this->app->make(RequestTrace::class));
    }

    public function test_payload_has_no_trace_outside_a_request(): void
    {
        $queue = $this->app['queue']->connection('sync');
        $method = new \ReflectionMethod($queue, 'createPayload');
        $method->setAccessible(true);
        $payload = json_decode($method->invoke($queue, new CapturesTrace, 'default'), true);

        $this->assertArrayNotHasKey(PropagatesTrace::PAYLOAD_KEY, $payload);
    }

    public function test_jobs_without_a_trace_run_without_one(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        CapturesTrace::$seen = 'stale';

        CapturesTrace::dispatch();

        $this->assertNull(CapturesTrace::$seen);
        $this->assertFalse($this->app->bound(RequestTrace::class));
    }

    public function test_the_outer_trace_is_restored_when_a_job_fails(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $outer = new RequestTrace('req-3', null, microtime(true));
        $this->app->instance(RequestTrace::class, $outer);

        try {
            FailingJob::dispatch();
            $this->fail('The job should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($outer, $this->app->make(RequestTrace::class));
    }

    public function test_a_stray_processed_event_is_ignored(): void
    {
        $outer = new RequestTrace('req-4', null, microtime(true));
        $this->app->instance(RequestTrace::class, $outer);

        $this->app['events']->dispatch(new JobProcessed('sync', \Mockery::mock(Job::class)));

        $this->assertSame($outer, $this->app->make(RequestTrace::class));
    }
}

class CapturesTrace implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static ?string $seen = null;

    public function handle(): void
    {
        self::$seen = app()->bound(RequestTrace::class) ? app(RequestTrace::class)->requestId : null;
    }
}

class FailingJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        throw new \RuntimeException('boom');
    }
}

<?php

namespace Blackbox\Queue;

use Blackbox\RequestTrace;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Log;

/**
 * Carries request_id/session_id from the request into queued jobs, so job
 * logs can be traced back to the request that dispatched them.
 *
 * A stack keeps the outer trace intact for sync jobs run inside a request
 * and for nested jobs.
 */
final class PropagatesTrace
{
    public const PAYLOAD_KEY = 'blackbox';

    /** @var array<int, RequestTrace|null> */
    private array $stack = [];

    public function __construct(private Container $container) {}

    public function register(Dispatcher $events): void
    {
        Queue::createPayloadUsing(function () {
            return $this->container->bound(RequestTrace::class)
                ? [self::PAYLOAD_KEY => $this->container->make(RequestTrace::class)->toArray()]
                : [];
        });

        $events->listen(JobProcessing::class, function (JobProcessing $event) {
            $this->stack[] = $this->current();

            $trace = RequestTrace::fromArray($event->job->payload()[self::PAYLOAD_KEY] ?? []);

            $this->activate($trace);
        });

        $events->listen([JobProcessed::class, JobExceptionOccurred::class], function () {
            if ($this->stack !== []) {
                $this->activate(array_pop($this->stack));
            }
        });
    }

    private function current(): ?RequestTrace
    {
        return $this->container->bound(RequestTrace::class)
            ? $this->container->make(RequestTrace::class)
            : null;
    }

    private function activate(?RequestTrace $trace): void
    {
        if ($trace === null) {
            $this->container->offsetUnset(RequestTrace::class);
            Log::withContext(['request_id' => null, 'session_id' => null]);

            return;
        }

        $this->container->instance(RequestTrace::class, $trace);
        Log::withContext($trace->toArray());
    }
}

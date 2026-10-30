<?php

namespace Blackbox\Tests\Unit;

use Blackbox\RequestTrace;
use PHPUnit\Framework\TestCase;

class RequestTraceTest extends TestCase
{
    public function test_generated_ids_are_32_hex_chars(): void
    {
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', RequestTrace::generateId());
        $this->assertNotSame(RequestTrace::generateId(), RequestTrace::generateId());
    }

    public function test_it_round_trips_through_an_array(): void
    {
        $trace = RequestTrace::fromArray((new RequestTrace('req', 'sess', 0.0))->toArray());

        $this->assertSame(['request_id' => 'req', 'session_id' => 'sess'], $trace->toArray());
    }

    public function test_from_array_needs_a_string_request_id(): void
    {
        $this->assertNull(RequestTrace::fromArray([]));
        $this->assertNull(RequestTrace::fromArray(['request_id' => 123]));
    }

    public function test_from_array_drops_a_non_string_session_id(): void
    {
        $this->assertNull(RequestTrace::fromArray(['request_id' => 'req', 'session_id' => 42])->sessionId);
    }

    public function test_elapsed_time_is_in_milliseconds(): void
    {
        $trace = new RequestTrace('req', null, microtime(true) - 0.25);

        $this->assertEqualsWithDelta(250, $trace->elapsedMs(), 50);
    }
}

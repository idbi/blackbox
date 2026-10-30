<?php

namespace Blackbox\Tests\Feature;

use Blackbox\Http\Middleware\TraceRequest;
use Blackbox\Recorder\RecordBuilder;
use Blackbox\RequestTrace;
use Blackbox\Sinks\Sink;
use Blackbox\Support\UserResolver;
use Blackbox\Tests\Support\UserWithoutId;
use Blackbox\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class TraceRequestTest extends TestCase
{
    public function test_it_generates_a_request_id_and_writes_one_record(): void
    {
        $response = $this->callAndTerminate('GET', '/echo?q=1');

        $id = $response->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);

        $records = $this->records();
        $this->assertCount(1, $records);
        $this->assertSame($id, $records[0]['request_id']);
        $this->assertSame('GET', $records[0]['method']);
        $this->assertSame('/echo', $records[0]['path']);
        $this->assertSame('echo', $records[0]['route']['name']);
        $this->assertSame(['q' => '1'], $records[0]['query']);
        $this->assertSame(200, $records[0]['status']);
        $this->assertIsFloat($records[0]['duration_ms'] + 0.0);
    }

    public function test_it_keeps_a_valid_incoming_request_id(): void
    {
        $response = $this->callAndTerminate('GET', '/echo', [], ['X-Request-Id' => 'abc123-def']);

        $this->assertSame('abc123-def', $response->headers->get('X-Request-Id'));
        $this->assertSame('abc123-def', $this->records()[0]['request_id']);
    }

    public function test_it_replaces_an_invalid_incoming_request_id(): void
    {
        $response = $this->callAndTerminate('GET', '/echo', [], ['X-Request-Id' => "bad id\n".str_repeat('x', 80)]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $response->headers->get('X-Request-Id'));
    }

    public function test_it_records_the_client_session(): void
    {
        $this->callAndTerminate('GET', '/echo', [], ['X-Session-Id' => 'session-42']);

        $this->assertSame('session-42', $this->records()[0]['session_id']);
    }

    public function test_it_redacts_payload_and_headers(): void
    {
        $this->callAndTerminate('POST', '/echo', [], [
            'Authorization' => 'Bearer secret',
            'Content-Type' => 'application/json',
            'X-Custom' => 'visible',
        ], [], json_encode([
            'name' => 'Ana',
            'password' => 'hunter2',
            'payment' => ['card_number' => '4111111111111111', 'amount' => 10],
        ]));

        $record = $this->records()[0];
        $payload = $this->payloadOf($record);

        $this->assertSame('Bea****ret', $record['headers']['authorization']);
        $this->assertSame('visible', $record['headers']['x-custom']);
        $this->assertSame('json', $record['payload_encoding']);
        $this->assertSame('Ana', $payload['name']);
        $this->assertSame('hun****er2', $payload['password']);
        $this->assertSame('411****111', $payload['payment']['card_number']);
        $this->assertSame(10, $payload['payment']['amount']);
    }

    public function test_payloads_up_to_the_threshold_stay_inline(): void
    {
        $this->callAndTerminate('POST', '/echo', ['blob' => str_repeat('a', 60_000)]);

        $record = $this->records()[0];
        $this->assertSame('json', $record['payload_encoding']);
        $this->assertSame(60_000, strlen($record['payload']['blob']));
    }

    public function test_payloads_over_the_threshold_are_gzipped_whole(): void
    {
        $blob = str_repeat('abc', 40_000);

        $this->callAndTerminate('POST', '/echo', ['blob' => $blob]);

        $record = $this->records()[0];
        $this->assertSame('json+gzip', $record['payload_encoding']);
        $this->assertLessThan(10_000, strlen($record['payload']));
        $this->assertSame(['blob' => $blob], $this->payloadOf($record));
    }

    public function test_gzip_can_be_disabled(): void
    {
        $this->useConfig('blackbox.payload.gzip_above_bytes', 0);
        $blob = str_repeat('a', 100_000);

        $this->callAndTerminate('POST', '/echo', ['blob' => $blob]);

        $record = $this->records()[0];
        $this->assertSame('json', $record['payload_encoding']);
        $this->assertSame(['blob' => $blob], $record['payload']);
    }

    public function test_requests_without_body_have_no_payload(): void
    {
        $this->callAndTerminate('GET', '/echo');

        $record = $this->records()[0];
        $this->assertNull($record['payload']);
        $this->assertNull($record['payload_encoding']);
    }

    public function test_it_keeps_raw_bodies_that_are_not_json_or_form(): void
    {
        $this->callAndTerminate('POST', '/echo', [], ['Content-Type' => 'text/xml'], [], '<a>1</a>');

        $this->assertSame('<a>1</a>', $this->records()[0]['payload']);
    }

    private function useConfig(string $key, mixed $value): void
    {
        $this->app['config']->set($key, $value);
        $this->app->forgetInstance(RecordBuilder::class);
    }

    public function test_it_describes_files_without_their_content(): void
    {
        $file = UploadedFile::fake()->create('invoice.pdf', 12, 'application/pdf');

        $this->callAndTerminate('POST', '/echo', ['note' => 'x'], [], ['doc' => $file]);

        $files = $this->records()[0]['files'];
        $this->assertSame('invoice.pdf', $files['doc']['name']);
        $this->assertSame('application/pdf', $files['doc']['mime']);
        $this->assertSame(12 * 1024, $files['doc']['size']);
    }

    public function test_it_records_the_authenticated_user_without_exposing_it(): void
    {
        $this->actingAs(new GenericUser(['id' => 7]), 'web');

        $response = $this->callAndTerminate('GET', '/echo');

        $this->assertFalse($response->headers->has('X-Audit-User'));
        $this->assertSame(7, $this->records()[0]['user']['id']);
        $this->assertSame('web', $this->records()[0]['user']['guard']);
    }

    public function test_guests_have_no_user(): void
    {
        $response = $this->callAndTerminate('GET', '/echo');

        $this->assertFalse($response->headers->has('X-Audit-User'));
        $this->assertNull($this->records()[0]['user']);
    }

    public function test_excluded_paths_are_not_recorded(): void
    {
        $response = $this->callAndTerminate('GET', '/health');

        $this->assertTrue($response->headers->has('X-Request-Id'));
        $this->assertSame([], $this->records());
    }

    public function test_sampling_zero_records_nothing(): void
    {
        $this->app['config']->set('blackbox.sampling', ['GET' => 0.0]);

        $this->callAndTerminate('GET', '/echo');
        $this->callAndTerminate('POST', '/echo');

        $this->assertSame(['POST'], array_column($this->records(), 'method'));
    }

    public function test_a_failing_sink_does_not_break_the_request(): void
    {
        $this->app['config']->set('blackbox.sink.path', '/dev/null/cannot/write.jsonl');
        $this->app->forgetInstance(Sink::class);
        $before = TraceRequest::failures();

        $response = $this->callAndTerminate('GET', '/echo');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($before + 1, TraceRequest::failures());
    }

    public function test_untrusted_incoming_ids_are_replaced(): void
    {
        $this->app['config']->set('blackbox.request_id.trust_incoming', false);

        $response = $this->callAndTerminate('GET', '/echo', [], ['X-Request-Id' => 'abc123']);

        $this->assertNotSame('abc123', $response->headers->get('X-Request-Id'));
    }

    public function test_the_request_id_is_visible_to_the_app(): void
    {
        $response = $this->callAndTerminate('GET', '/echo');

        $this->assertSame($response->headers->get('X-Request-Id'), $this->app->make(RequestTrace::class)->requestId);
    }

    public function test_invalid_session_headers_fall_through_to_the_next_one(): void
    {
        $this->app['config']->set('blackbox.session.headers', ['X-Session-Id', 'X-Other-Session']);
        $this->app['config']->set('blackbox.session.max_length', 10);

        $this->callAndTerminate('GET', '/echo', [], ['X-Session-Id' => str_repeat('x', 11), 'X-Other-Session' => 'ok']);
        $this->callAndTerminate('GET', '/echo', [], ['X-Session-Id' => "bad\x01", 'X-Other-Session' => '']);

        $this->assertSame(['ok', null], array_column($this->records(), 'session_id'));
    }

    public function test_disabled_recording_still_propagates_the_id(): void
    {
        $this->app['config']->set('blackbox.enabled', false);

        $response = $this->callAndTerminate('GET', '/echo');

        $this->assertTrue($response->headers->has('X-Request-Id'));
        $this->assertSame([], $this->records());
    }

    public function test_terminate_ignores_non_responses_and_requests_it_never_saw(): void
    {
        $middleware = $this->app->make(TraceRequest::class);

        $middleware->terminate(Request::create('/echo'), 'not a response');
        $middleware->terminate(Request::create('/echo'), response('ok'));

        $this->assertSame([], $this->records());
    }

    public function test_handle_passes_through_non_symfony_responses(): void
    {
        $result = $this->app->make(TraceRequest::class)->handle(Request::create('/echo'), fn () => 'plain');

        $this->assertSame('plain', $result);
    }

    public function test_a_custom_user_resolver_is_recorded(): void
    {
        $this->app['config']->set('blackbox.user.resolver', UserWithoutId::class);
        $this->app->forgetInstance(UserResolver::class);
        $this->app->forgetInstance(RecordBuilder::class);

        $this->callAndTerminate('GET', '/echo');

        $this->assertSame(['name' => 'system'], $this->records()[0]['user']);
    }

    public function test_partial_sampling_records_some_requests(): void
    {
        $this->app['config']->set('blackbox.sampling', ['*' => 0.5]);
        mt_srand(7);

        for ($i = 0; $i < 40; $i++) {
            $this->callAndTerminate('GET', '/echo');
        }

        $count = count($this->records());
        $this->assertGreaterThan(5, $count);
        $this->assertLessThan(35, $count);
    }

    public function test_without_laravel_start_the_request_time_is_used(): void
    {
        $request = Request::create('/echo', 'GET', [], [], [], ['REQUEST_TIME_FLOAT' => microtime(true) - 1]);
        $kernel = $this->app->make(Kernel::class);
        $kernel->terminate($request, $kernel->handle($request));

        $this->assertGreaterThanOrEqual(1000, $this->records()[0]['duration_ms']);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_laravel_start_is_used_when_defined(): void
    {
        define('LARAVEL_START', microtime(true) - 2);

        $this->callAndTerminate('GET', '/echo');

        $this->assertGreaterThanOrEqual(2000, $this->records()[0]['duration_ms']);
    }
}

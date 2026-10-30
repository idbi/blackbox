<?php

namespace Blackbox\Tests\Feature;

use Blackbox\Http\Middleware\TraceRequest;
use Blackbox\Log\AddUserToContext;
use Blackbox\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

class LogContextTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('logging.default', 'blackbox_test');
        $app['config']->set('logging.channels.blackbox_test', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(TraceRequest::class)->get('/log', function () {
            Log::info('before auth');
            Auth::guard('web')->setUser(new GenericUser(['id' => 9]));
            Log::info('after auth', ['user_id' => request('override', 9)]);
            Log::info('later');

            return 'ok';
        });
    }

    /**
     * @return array<string, array> message => context
     */
    private function logged(): array
    {
        $records = [];

        foreach (Log::getLogger()->getHandlers()[0]->getRecords() as $record) {
            $records[$record['message']] = $record['context'];
        }

        return $records;
    }

    private function ourProcessors(): array
    {
        return array_values(array_filter(
            Log::getLogger()->getProcessors(),
            fn ($processor) => $processor instanceof AddUserToContext
        ));
    }

    public function test_log_lines_get_the_trace_and_the_user_once_authenticated(): void
    {
        $response = $this->callAndTerminate('GET', '/log', ['override' => 'explicit'], ['X-Session-Id' => 's-1']);

        $logged = $this->logged();
        $id = $response->headers->get('X-Request-Id');

        $this->assertSame(['request_id' => $id, 'session_id' => 's-1'], $logged['before auth']);
        $this->assertSame('explicit', $logged['after auth']['user_id']);
        $this->assertSame(9, $logged['later']['user_id']);
        $this->assertSame($id, $logged['later']['request_id']);
        $this->assertFalse($response->headers->has('X-Audit-User'));
    }

    public function test_the_processor_is_added_once_per_logger(): void
    {
        $this->callAndTerminate('GET', '/log');
        $this->callAndTerminate('GET', '/log');

        $this->assertCount(1, $this->ourProcessors());
    }

    public function test_log_context_can_be_disabled(): void
    {
        $this->app['config']->set('blackbox.log_context', false);

        $this->callAndTerminate('GET', '/log');

        $this->assertSame([], $this->logged()['before auth']);
        $this->assertSame([], $this->ourProcessors());
    }

    public function test_it_handles_monolog_2_array_records(): void
    {
        $this->actingAs(new GenericUser(['id' => 9]), 'web');
        $processor = $this->app->make(AddUserToContext::class);

        $this->assertSame(['context' => ['a' => 1, 'user_id' => 9]], $processor(['context' => ['a' => 1]]));
        $this->assertSame(['context' => ['user_id' => 'x']], $processor(['context' => ['user_id' => 'x']]));
    }

    public function test_guests_and_requests_outside_http_have_no_user(): void
    {
        $processor = $this->app->make(AddUserToContext::class);

        $this->assertSame(['context' => []], $processor(['context' => []]));

        $this->app->offsetUnset('request');
        $this->assertSame(['context' => []], $processor(['context' => []]));
    }
}

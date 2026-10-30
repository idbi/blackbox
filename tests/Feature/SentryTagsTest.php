<?php

namespace Blackbox\Tests\Feature;

use Blackbox\Tests\Support\FakeSentryScope;
use Blackbox\Tests\TestCase;

require_once __DIR__.'/../Support/sentry.php';

class SentryTagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeSentryScope::$current = new FakeSentryScope;
    }

    public function test_it_tags_the_scope_with_the_configured_names(): void
    {
        $this->app['config']->set('blackbox.sentry.tags', ['request_id' => 'trace.request', 'session_id' => 'trace.session']);

        $response = $this->callAndTerminate('GET', '/echo', [], ['X-Session-Id' => 's-1']);

        $this->assertSame(
            ['trace.request' => $response->headers->get('X-Request-Id'), 'trace.session' => 's-1'],
            FakeSentryScope::$current->tags
        );
    }

    public function test_missing_values_and_unmapped_keys_are_not_tagged(): void
    {
        $this->app['config']->set('blackbox.sentry.tags', ['request_id' => 'request_id']);

        $this->callAndTerminate('GET', '/echo');

        $this->assertSame(['request_id'], array_keys(FakeSentryScope::$current->tags));
    }

    public function test_it_can_be_disabled(): void
    {
        $this->app['config']->set('blackbox.sentry.enabled', false);

        $this->callAndTerminate('GET', '/echo');

        $this->assertSame([], FakeSentryScope::$current->tags);
    }
}

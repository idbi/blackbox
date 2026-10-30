<?php

namespace Blackbox\Tests\Feature;

use Blackbox\Recorder\RecordBuilder;
use Blackbox\RequestTrace;
use Blackbox\Tests\Support\TenantFromHeader;
use Blackbox\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecordBuilderTest extends TestCase
{
    private function build(Request $request, ?Response $response = null, array $config = []): array
    {
        foreach ($config as $key => $value) {
            $this->app['config']->set("blackbox.{$key}", $value);
        }

        $this->app->forgetInstance(RecordBuilder::class);

        return $this->app->make(RecordBuilder::class)
            ->build($request, $response ?? new Response('hello'), new RequestTrace('req', null, microtime(true)));
    }

    public function test_requests_outside_a_route_have_no_route(): void
    {
        $record = $this->build(Request::create('/nowhere'));

        $this->assertNull($record['route']);
        $this->assertSame('/nowhere', $record['path']);
        $this->assertNull($record['query']);
        $this->assertSame(5, $record['response_bytes']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $record['ts']);
    }

    public function test_response_bytes_prefer_content_length(): void
    {
        $record = $this->build(Request::create('/'), new Response('hello', 200, ['Content-Length' => '99']));

        $this->assertSame(99, $record['response_bytes']);
    }

    public function test_streamed_responses_have_unknown_size(): void
    {
        $record = $this->build(Request::create('/'), new StreamedResponse(fn () => null));

        $this->assertNull($record['response_bytes']);
    }

    public function test_the_tenant_resolver_is_used(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_TENANT' => 'acme']);

        $this->assertNull($this->build($request)['tenant']);
        $this->assertSame('acme', $this->build($request, null, ['tenant.resolver' => TenantFromHeader::class])['tenant']);
    }

    public function test_only_listed_headers_are_kept_and_multiple_values_stay_a_list(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Keep', ['one', 'two']);
        $request->headers->set('Authorization', 'Bearer abcdef');
        $request->headers->set('X-Drop', 'x');

        $headers = $this->build($request, null, ['headers.only' => ['X-Keep', 'Authorization']])['headers'];

        $this->assertSame(['x-keep' => ['one', 'two'], 'authorization' => 'Bea****def'], $headers);
    }

    public function test_query_strings_are_redacted(): void
    {
        $record = $this->build(Request::create('/?token=abcdefgh&page=2'));

        $this->assertSame(['token' => 'abc****fgh', 'page' => '2'], $record['query']);
    }

    public function test_empty_json_bodies_have_no_payload(): void
    {
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertNull($this->build($request)['payload']);
    }

    public function test_raw_bodies_can_be_disabled(): void
    {
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'text/xml'], '<a/>');

        $this->assertNull($this->build($request, null, ['payload.raw' => false])['payload']);
    }

    public function test_raw_bodies_of_safe_methods_and_empty_bodies_are_ignored(): void
    {
        $get = Request::create('/', 'GET', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'body');
        $empty = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '');

        $this->assertNull($this->build($get)['payload']);
        $this->assertNull($this->build($empty)['payload']);
    }

    public function test_raw_bodies_over_the_threshold_are_gzipped(): void
    {
        $body = str_repeat('<row/>', 20_000);
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'text/xml'], $body);

        $record = $this->build($request);

        $this->assertSame('json+gzip', $record['payload_encoding']);
        $this->assertSame($body, $this->payloadOf($record));
    }

    public function test_nested_file_arrays_are_described(): void
    {
        $files = ['docs' => [
            UploadedFile::fake()->create('a.pdf', 1, 'application/pdf'),
            UploadedFile::fake()->create('b.txt', 2, 'text/plain'),
        ]];

        $record = $this->build(Request::create('/', 'POST', [], [], $files));

        $this->assertSame(['a.pdf', 'b.txt'], array_column($record['files']['docs'], 'name'));
    }
}

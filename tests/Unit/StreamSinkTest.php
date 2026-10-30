<?php

namespace Blackbox\Tests\Unit;

use Blackbox\Sinks\StreamSink;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StreamSinkTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/blackbox-sink-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->dir.'/nested');
        @rmdir($this->dir);
    }

    public function test_it_creates_the_directory_and_names_the_file_by_utc_date(): void
    {
        $sink = new StreamSink(['path' => $this->dir.'/nested/audit-{date}.jsonl']);

        $sink->write(['a' => 1]);
        $sink->write(['a' => 2]);

        $file = $this->dir.'/nested/audit-'.gmdate('Y-m-d').'.jsonl';
        $this->assertSame("{\"a\":1}\n{\"a\":2}\n", file_get_contents($file));
    }

    public function test_it_writes_without_a_lock(): void
    {
        $sink = new StreamSink(['path' => $this->dir.'/nested/audit.jsonl', 'lock' => false]);

        $sink->write(['ñ' => 'ü/x']);

        $this->assertSame("{\"ñ\":\"ü/x\"}\n", file_get_contents($this->dir.'/nested/audit.jsonl'));
    }

    public function test_it_writes_to_php_streams(): void
    {
        $sink = new StreamSink(['path' => 'php://output']);

        ob_start();
        $sink->write(['a' => 1]);

        $this->assertSame("{\"a\":1}\n", ob_get_clean());
    }

    public function test_it_replaces_invalid_utf8_instead_of_failing(): void
    {
        $sink = new StreamSink(['path' => 'php://output']);

        ob_start();
        $sink->write(['a' => "\xFF"]);

        $this->assertSame("{\"a\":\"\u{FFFD}\"}\n", ob_get_clean());
    }

    public function test_it_throws_when_the_path_cannot_be_opened(): void
    {
        $this->expectException(RuntimeException::class);

        (new StreamSink(['path' => '/dev/null/nope.jsonl']))->write(['a' => 1]);
    }
}

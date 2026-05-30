<?php

namespace CoffeeR\Digtrace\Tests;

use CoffeeR\Digtrace\Sink\JsonlSink;
use PHPUnit\Framework\TestCase;

class JsonlSinkTest extends TestCase
{
    /** @var string */
    private $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'digtrace_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testWriteAppendsJsonlLine()
    {
        $sink  = new JsonlSink($this->tmpFile);
        $trace = ['schema_version' => 1, 'trace_id' => 'abc', 'app_name' => 'test'];
        $sink->write($trace);

        $content = file_get_contents($this->tmpFile);
        $this->assertStringEndsWith("\n", $content);
        $decoded = json_decode(trim($content), true);
        $this->assertSame(1, $decoded['schema_version']);
        $this->assertSame('abc', $decoded['trace_id']);
    }

    public function testWriteAppendsMultipleLines()
    {
        $sink = new JsonlSink($this->tmpFile);
        $sink->write(['trace_id' => 'line1']);
        $sink->write(['trace_id' => 'line2']);

        $lines = array_filter(explode("\n", file_get_contents($this->tmpFile)));
        $this->assertCount(2, $lines);
        $this->assertSame('line1', json_decode(array_values($lines)[0], true)['trace_id']);
        $this->assertSame('line2', json_decode(array_values($lines)[1], true)['trace_id']);
    }

    public function testWriteThrowsOnInvalidPath()
    {
        $this->expectException(\RuntimeException::class);
        $sink = new JsonlSink('/nonexistent_dir/digtrace.jsonl');
        $sink->write(['trace_id' => 'x']);
    }

    public function testWriteUsesUnescapedUnicode()
    {
        $sink = new JsonlSink($this->tmpFile);
        $sink->write(['label' => 'テスト']);
        $content = file_get_contents($this->tmpFile);
        $this->assertStringContainsString('テスト', $content);
    }
}

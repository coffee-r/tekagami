<?php

namespace CoffeeR\Tekagami\Tests;

use PHPUnit\Framework\TestCase;

class CliTest extends TestCase
{
    private function runCli($args)
    {
        $bin = __DIR__ . '/../bin/tekagami';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . $args . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        return [$code, implode("\n", $output)];
    }

    public function testExportJsonCommand()
    {
        $fixture = escapeshellarg(__DIR__ . '/fixtures/sample.jsonl');
        list($code, $output) = $this->runCli('export ' . $fixture . ' --format json');

        $this->assertSame(0, $code);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('sql_dictionary', $decoded);
        $this->assertArrayHasKey('legend', $decoded);
    }

    public function testExportRequiresFiles()
    {
        list($code, $output) = $this->runCli('export');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No JSONL files', $output);
    }

    public function testExportRejectsUnknownFormat()
    {
        $fixture = escapeshellarg(__DIR__ . '/fixtures/sample.jsonl');
        list($code, $output) = $this->runCli('export ' . $fixture . ' --format xml');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown format', $output);
    }

    public function testDecryptCommandIsRemoved()
    {
        list($code, $output) = $this->runCli('decrypt');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown command: decrypt', $output);
    }

    public function testCompareCommandIsRemoved()
    {
        list($code, $output) = $this->runCli('compare');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown command: compare', $output);
    }

    public function testHelpDoesNotListCompare()
    {
        list($code, $output) = $this->runCli('--help');

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('compare', $output);
    }
}

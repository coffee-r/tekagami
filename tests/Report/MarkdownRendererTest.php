<?php

namespace CoffeeR\Digtrace\Tests\Report;

use CoffeeR\Digtrace\Report\Aggregator;
use CoffeeR\Digtrace\Report\JsonlReader;
use CoffeeR\Digtrace\Report\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class MarkdownRendererTest extends TestCase
{
    private function makeReport()
    {
        $reader = new JsonlReader();
        $traces = $reader->read([__DIR__ . '/../fixtures/sample.jsonl']);
        return (new Aggregator())->aggregate($traces);
    }

    public function testRenderStartsWithH1()
    {
        $renderer = new MarkdownRenderer();
        $output   = $renderer->render($this->makeReport());
        $this->assertStringStartsWith('# 観測振る舞い証拠レポート', $output);
    }

    public function testRenderContainsEntrypointH2()
    {
        $renderer = new MarkdownRenderer();
        $output   = $renderer->render($this->makeReport());
        $this->assertStringContainsString('## GET /products/{id}', $output);
        $this->assertStringContainsString('## POST /orders', $output);
    }

    public function testRenderContainsPatternSection()
    {
        $renderer = new MarkdownRenderer();
        $output   = $renderer->render($this->makeReport());
        $this->assertStringContainsString('### 振る舞いパターン:', $output);
    }

    public function testRenderContainsStatusCodesTable()
    {
        $renderer = new MarkdownRenderer();
        $output   = $renderer->render($this->makeReport());
        $this->assertStringContainsString('### ステータスコード', $output);
        $this->assertStringContainsString('| ステータス | 件数 |', $output);
    }

    public function testRenderContainsSqlFlow()
    {
        $renderer = new MarkdownRenderer();
        $output   = $renderer->render($this->makeReport());
        $this->assertStringContainsString('SELECT', $output);
        $this->assertStringContainsString('PRODUCTS', $output);
    }

    public function testRenderEffectsSection()
    {
        $renderer = new MarkdownRenderer();
        $output   = $renderer->render($this->makeReport());
        $this->assertStringContainsString('**更新操作:**', $output);
        $this->assertStringContainsString('INSERT', $output);
        $this->assertStringContainsString('ORDERS', $output);
    }

    public function testRenderReadOnlyPatternShowsNone()
    {
        $renderer = new MarkdownRenderer();
        $output   = $renderer->render($this->makeReport());
        // GET /products/{id} は read-only
        $this->assertStringContainsString('_(なし — 読み取り専用パターン)_', $output);
    }

    public function testRenderEmptyReport()
    {
        $renderer = new MarkdownRenderer();
        $report   = [
            'generated_at'              => '2025-05-30T00:00:00+00:00',
            'trace_count'               => 0,
            'observed_entrypoint_count' => 0,
            'observed_started_at_min'   => null,
            'observed_started_at_max'   => null,
            'value_mode'                => 'normalized',
            'observed_entrypoints'      => [],
        ];
        $output = $renderer->render($report);
        $this->assertStringStartsWith('# 観測振る舞い証拠レポート', $output);
        $this->assertStringContainsString('トレース数: `0`', $output);
    }

    public function testRenderContainsObservedCount()
    {
        $renderer = new MarkdownRenderer();
        $output   = $renderer->render($this->makeReport());
        // 1トレースごとにエントリポイントがある
        $this->assertStringContainsString('観測リクエスト数: `1`', $output);
    }
}

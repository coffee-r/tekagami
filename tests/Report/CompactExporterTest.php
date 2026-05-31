<?php

namespace CoffeeR\Tekagami\Tests\Report;

use CoffeeR\Tekagami\Report\CompactExporter;
use PHPUnit\Framework\TestCase;

class CompactExporterTest extends TestCase
{
    private function report()
    {
        return [
            'generated_at' => '2026-05-31T00:00:00+00:00',
            'trace_count' => 2,
            'observed_entrypoint_count' => 1,
            'value_mode' => 'normalized',
            'observed_entrypoints' => [[
                'entrypoint_key' => 'GET /products/{id}',
                'method' => 'GET',
                'path' => '/products/{id}',
                'observed_count' => 2,
                'status_codes' => ['200' => 2],
                'error_count' => 0,
                'patterns' => [
                    [
                        'behavior_pattern_id' => 'pattern-1',
                        'observed_flow_signature' => 'SELECT:PRODUCTS:sha256:aaa -> STATUS:200',
                        'compressed_flow_signature' => 'SELECT:PRODUCTS:sha256:aaa x2 -> STATUS:200',
                        'truncated' => false,
                        'truncation_limit' => null,
                        'count' => 1,
                        'statuses' => ['200' => true],
                        'representative_trace_id' => 'trace-1',
                        'sql_flow' => [
                            $this->sqlStep(1),
                            $this->sqlStep(2),
                        ],
                        'effects' => [],
                    ],
                    [
                        'behavior_pattern_id' => 'pattern-2',
                        'observed_flow_signature' => 'SELECT:PRODUCTS:sha256:aaa -> STATUS:200',
                        'compressed_flow_signature' => 'SELECT:PRODUCTS:sha256:aaa -> STATUS:200',
                        'truncated' => false,
                        'truncation_limit' => null,
                        'count' => 1,
                        'statuses' => ['200' => true],
                        'representative_trace_id' => 'trace-2',
                        'sql_flow' => [
                            $this->sqlStep(1),
                        ],
                        'effects' => [],
                    ],
                ],
            ]],
        ];
    }

    private function sqlStep($step)
    {
        return [
            'step' => $step,
            'operation' => 'SELECT',
            'tables' => ['PRODUCTS'],
            'statement_hash' => 'sha256:aaa',
            'statement_normalized' => 'SELECT * FROM products WHERE id = {parameter}',
            'statement_fingerprint' => [
                'op' => 'SELECT',
                'tables' => ['PRODUCTS'],
                'filter_columns' => ['ID'],
                'write_columns' => [],
                'fp_hash' => 'fp1:products-id',
            ],
        ];
    }

    public function testSqlDictionaryDeduplicatesSql()
    {
        $export = (new CompactExporter())->export($this->report());

        $this->assertSame(
            ['S1' => 'SELECT * FROM products WHERE id = {parameter}'],
            $export['sql_dictionary']
        );
        $this->assertSame('S1', $export['observed_entrypoints'][0]['patterns'][0]['sql_flow'][0]['s']);
        $this->assertSame('S1', $export['observed_entrypoints'][0]['patterns'][1]['sql_flow'][0]['s']);
    }

    public function testPatternDropsFullSqlAndExactSignature()
    {
        $export = (new CompactExporter())->export($this->report());
        $pattern = $export['observed_entrypoints'][0]['patterns'][0];
        $encodedPattern = json_encode($pattern, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertArrayNotHasKey('observed_flow_signature', $pattern);
        $this->assertArrayNotHasKey('compressed_flow_signature', $pattern);
        $this->assertStringNotContainsString('statement_normalized', $encodedPattern);
        $this->assertStringNotContainsString('SELECT * FROM products', $encodedPattern);
        $this->assertSame('SELECT:PRODUCTS:S1 x2 -> STATUS:200', $pattern['signature']);
    }

    public function testEachStepContainsFingerprintHash()
    {
        $export = (new CompactExporter())->export($this->report());
        $step = $export['observed_entrypoints'][0]['patterns'][0]['sql_flow'][0];

        $this->assertSame('fp1:products-id', $step['fp']);
        $this->assertSame('SELECT', $step['op']);
        $this->assertSame(['PRODUCTS'], $step['tables']);
    }
}

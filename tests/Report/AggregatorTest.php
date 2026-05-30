<?php

namespace CoffeeR\Digtrace\Tests\Report;

use CoffeeR\Digtrace\Report\Aggregator;
use PHPUnit\Framework\TestCase;

class AggregatorTest extends TestCase
{
    private function makeTrace(array $overrides = [])
    {
        return array_merge([
            'trace_id'   => 'trace-' . uniqid(),
            'started_at' => '2025-05-30T10:00:00+00:00',
            'http'       => [
                'method'       => 'GET',
                'path'         => '/products/123',
                'path_pattern' => '/products/{id}',
                'status'       => 200,
                'response_kind' => 'json',
            ],
            'timeline' => [],
            'effects'  => [],
            'errors'   => [],
        ], $overrides);
    }

    private function makeSqlEvent(array $overrides = [])
    {
        return array_merge([
            'seq'                  => 1,
            'type'                 => 'sql',
            'operation'            => 'SELECT',
            'tables'               => ['PRODUCTS'],
            'statement_normalized' => 'SELECT * FROM products WHERE id = {parameter}',
            'statement_hash'       => 'sha256:abc123',
        ], $overrides);
    }

    public function testEntryKeyUsesPathPattern()
    {
        $agg = new Aggregator();
        $http = ['method' => 'GET', 'path' => '/products/123', 'path_pattern' => '/products/{id}'];
        $this->assertSame('GET /products/{id}', $agg->entryKey($http));
    }

    public function testEntryKeyFallsBackToPath()
    {
        $agg = new Aggregator();
        $http = ['method' => 'GET', 'path' => '/products/123'];
        $this->assertSame('GET /products/123', $agg->entryKey($http));
    }

    public function testEntryKeyUppercasesMethod()
    {
        $agg = new Aggregator();
        $http = ['method' => 'post', 'path' => '/orders', 'path_pattern' => '/orders'];
        $this->assertSame('POST /orders', $agg->entryKey($http));
    }

    public function testPatternSignatureForSameTimeline()
    {
        $agg    = new Aggregator();
        $trace1 = $this->makeTrace(['timeline' => [$this->makeSqlEvent()]]);
        $trace2 = $this->makeTrace(['timeline' => [$this->makeSqlEvent()]]);
        $this->assertSame($agg->patternSignature($trace1), $agg->patternSignature($trace2));
    }

    public function testPatternSignatureDiffersOnHash()
    {
        $agg    = new Aggregator();
        $trace1 = $this->makeTrace(['timeline' => [$this->makeSqlEvent(['statement_hash' => 'sha256:aaa'])]]);
        $trace2 = $this->makeTrace(['timeline' => [$this->makeSqlEvent(['statement_hash' => 'sha256:bbb'])]]);
        $this->assertNotSame($agg->patternSignature($trace1), $agg->patternSignature($trace2));
    }

    public function testPatternSignatureDiffersOnStatus()
    {
        $agg    = new Aggregator();
        $trace1 = $this->makeTrace(['http' => ['method' => 'GET', 'path' => '/p', 'status' => 200]]);
        $trace2 = $this->makeTrace(['http' => ['method' => 'GET', 'path' => '/p', 'status' => 404]]);
        $this->assertNotSame($agg->patternSignature($trace1), $agg->patternSignature($trace2));
    }

    public function testPatternSignatureDiffersOnOperation()
    {
        $agg    = new Aggregator();
        $trace1 = $this->makeTrace(['timeline' => [$this->makeSqlEvent(['operation' => 'SELECT'])]]);
        $trace2 = $this->makeTrace(['timeline' => [$this->makeSqlEvent(['operation' => 'UPDATE'])]]);
        $this->assertNotSame($agg->patternSignature($trace1), $agg->patternSignature($trace2));
    }

    public function testPatternSignatureIncludesCustomEvent()
    {
        $agg      = new Aggregator();
        $withCustom = $this->makeTrace(['timeline' => [
            ['seq' => 1, 'type' => 'custom', 'label' => 'cache_read'],
            $this->makeSqlEvent(['seq' => 2]),
        ]]);
        $withoutCustom = $this->makeTrace(['timeline' => [
            $this->makeSqlEvent(['seq' => 1]),
        ]]);
        $this->assertNotSame($agg->patternSignature($withCustom), $agg->patternSignature($withoutCustom));
    }

    public function testPatternSignatureContainsCUSTOMPrefix()
    {
        $agg   = new Aggregator();
        $trace = $this->makeTrace(['timeline' => [
            ['seq' => 1, 'type' => 'custom', 'label' => 'cache_read'],
        ]]);
        $this->assertStringContainsString('CUSTOM:cache_read', $agg->patternSignature($trace));
    }

    public function testAggregateGroupsByEntrypoint()
    {
        $agg    = new Aggregator();
        $traces = [
            $this->makeTrace(['http' => ['method' => 'GET', 'path' => '/a', 'path_pattern' => '/a', 'status' => 200]]),
            $this->makeTrace(['http' => ['method' => 'GET', 'path' => '/a', 'path_pattern' => '/a', 'status' => 200]]),
            $this->makeTrace(['http' => ['method' => 'POST', 'path' => '/b', 'path_pattern' => '/b', 'status' => 201]]),
        ];
        $report = $agg->aggregate($traces);

        $this->assertSame(2, $report['observed_entrypoint_count']);
        $this->assertSame(3, $report['trace_count']);
    }

    public function testAggregateCountsTracesPerPattern()
    {
        $agg    = new Aggregator();
        $sql    = $this->makeSqlEvent();
        $traces = array_fill(0, 5, $this->makeTrace(['timeline' => [$sql]]));
        $report = $agg->aggregate($traces);

        $ep = $report['observed_entrypoints'][0];
        $this->assertSame(5, $ep['observed_count']);
        $this->assertCount(1, $ep['patterns']);
        $this->assertSame(5, $ep['patterns'][0]['count']);
    }

    public function testAggregateStatusCodesCount()
    {
        $agg    = new Aggregator();
        $traces = [
            $this->makeTrace(['http' => ['method' => 'GET', 'path' => '/p', 'path_pattern' => '/p', 'status' => 200]]),
            $this->makeTrace(['http' => ['method' => 'GET', 'path' => '/p', 'path_pattern' => '/p', 'status' => 200]]),
            $this->makeTrace(['http' => ['method' => 'GET', 'path' => '/p', 'path_pattern' => '/p', 'status' => 404]]),
        ];
        $report = $agg->aggregate($traces);
        $ep     = $report['observed_entrypoints'][0];

        $this->assertSame(2, $ep['status_codes']['200']);
        $this->assertSame(1, $ep['status_codes']['404']);
    }

    public function testAggregateEffectsSummary()
    {
        $agg    = new Aggregator();
        $effect = ['op' => 'INSERT', 'table' => 'ORDERS', 'statement_hash' => 'sha256:eee', 'count' => 1];
        $traces = [
            $this->makeTrace(['effects' => [$effect]]),
            $this->makeTrace(['effects' => [$effect]]),
        ];
        $report  = $agg->aggregate($traces);
        $effects = $report['observed_entrypoints'][0]['patterns'][0]['effects'];

        $this->assertCount(1, $effects);
        $this->assertSame('INSERT', $effects[0]['op']);
        $this->assertSame('ORDERS', $effects[0]['table']);
        $this->assertSame(2, $effects[0]['count']);
    }

    public function testAggregateSortsPatternsByCount()
    {
        $agg    = new Aggregator();
        $sql1   = $this->makeSqlEvent(['statement_hash' => 'sha256:aaa', 'tables' => ['A']]);
        $sql2   = $this->makeSqlEvent(['statement_hash' => 'sha256:bbb', 'tables' => ['B']]);
        $traces = array_merge(
            array_fill(0, 3, $this->makeTrace(['timeline' => [$sql1]])),
            array_fill(0, 10, $this->makeTrace(['timeline' => [$sql2]]))
        );
        $report = $agg->aggregate($traces);
        $patterns = $report['observed_entrypoints'][0]['patterns'];

        $this->assertSame(10, $patterns[0]['count']);
        $this->assertSame(3, $patterns[1]['count']);
    }

    public function testAggregateErrorsRecorded()
    {
        $agg    = new Aggregator();
        $trace  = $this->makeTrace(['errors' => [['type' => 'capture_failure', 'message' => 'oops']]]);
        $report = $agg->aggregate([$trace]);
        $ep     = $report['observed_entrypoints'][0];

        $this->assertSame(1, $ep['error_count']);
        $this->assertCount(1, $ep['errors']);
    }

    public function testAggregateEmptyTraces()
    {
        $agg    = new Aggregator();
        $report = $agg->aggregate([]);

        $this->assertSame(0, $report['trace_count']);
        $this->assertSame(0, $report['observed_entrypoint_count']);
        $this->assertSame([], $report['observed_entrypoints']);
        $this->assertNull($report['observed_started_at_min']);
    }

    public function testAggregateTraceWithNoTimeline()
    {
        $agg    = new Aggregator();
        $trace  = $this->makeTrace(['timeline' => []]);
        $report = $agg->aggregate([$trace]);

        $signature = $report['observed_entrypoints'][0]['patterns'][0]['observed_flow_signature'];
        $this->assertSame('STATUS:200', $signature);
    }

    public function testAggregateFirstLastSeen()
    {
        $agg    = new Aggregator();
        $traces = [
            $this->makeTrace(['started_at' => '2025-05-30T08:00:00+00:00']),
            $this->makeTrace(['started_at' => '2025-05-30T12:00:00+00:00']),
            $this->makeTrace(['started_at' => '2025-05-30T10:00:00+00:00']),
        ];
        $report = $agg->aggregate($traces);

        $this->assertSame('2025-05-30T08:00:00+00:00', $report['observed_started_at_min']);
        $this->assertSame('2025-05-30T12:00:00+00:00', $report['observed_started_at_max']);
    }

    public function testAggregateWithFixtureFile()
    {
        $agg    = new Aggregator();
        $reader = new \CoffeeR\Digtrace\Report\JsonlReader();
        $traces = $reader->read([__DIR__ . '/../fixtures/sample.jsonl']);

        $report = $agg->aggregate($traces);

        $this->assertSame(2, $report['trace_count']);
        $this->assertSame(2, $report['observed_entrypoint_count']);
    }
}

<?php

namespace CoffeeR\Digtrace\Report;

/**
 * トレース配列をエントリポイント × 実行パターンで集計する。
 */
class Aggregator
{
    /** @var string  'normalized' | 'tokenized' */
    private $valueMode;

    /**
     * @param string $valueMode  'normalized'（デフォルト）または 'tokenized'
     */
    public function __construct($valueMode = 'normalized')
    {
        $this->valueMode = in_array($valueMode, ['normalized', 'tokenized'], true)
            ? $valueMode
            : 'normalized';
    }

    /**
     * トレース配列を集計してレポートデータを返す。
     *
     * @param array $traces  JsonlReader::read() の戻り値
     * @return array
     */
    public function aggregate(array $traces)
    {
        $entrypoints = [];
        $started     = [];

        foreach ($traces as $trace) {
            if (isset($trace['started_at'])) {
                $started[] = $trace['started_at'];
            }

            $http        = isset($trace['http']) && is_array($trace['http']) ? $trace['http'] : [];
            $entryKey    = $this->entryKey($http);
            $signature   = $this->patternSignature($trace);

            if (!isset($entrypoints[$entryKey])) {
                $entrypoints[$entryKey] = $this->emptyEntrypoint($http, $entryKey);
            }

            $entrypoints[$entryKey]['observed_count']++;

            if (isset($http['status'])) {
                $statusStr = (string) $http['status'];
                if (!isset($entrypoints[$entryKey]['status_codes'][$statusStr])) {
                    $entrypoints[$entryKey]['status_codes'][$statusStr] = 0;
                }
                $entrypoints[$entryKey]['status_codes'][$statusStr]++;
            }

            $errors = $this->errorEvents($trace);
            if (count($errors) > 0) {
                $entrypoints[$entryKey]['error_count']++;
                foreach ($errors as $error) {
                    $label = isset($error['type']) ? (string) $error['type'] : 'unknown';
                    if (isset($error['message'])) {
                        $label .= ': ' . $error['message'];
                    }
                    if (!isset($entrypoints[$entryKey]['errors'][$label])) {
                        $entrypoints[$entryKey]['errors'][$label] = [
                            'error'                  => $label,
                            'count'                  => 0,
                            'representative_trace_id' => isset($trace['trace_id']) ? $trace['trace_id'] : null,
                        ];
                    }
                    $entrypoints[$entryKey]['errors'][$label]['count']++;
                }
            }

            if (!isset($entrypoints[$entryKey]['patterns'][$signature])) {
                $patternId = 'pattern-' . (count($entrypoints[$entryKey]['patterns']) + 1);
                $entrypoints[$entryKey]['patterns'][$signature] = [
                    'behavior_pattern_id'     => $patternId,
                    'observed_flow_signature' => $signature,
                    'count'                   => 0,
                    'statuses'                => [],
                    'sql_flow'                => $this->buildSqlFlow($trace),
                    'effects_acc'             => [],
                    'representative_trace_id' => isset($trace['trace_id']) ? $trace['trace_id'] : null,
                ];
            }

            $entrypoints[$entryKey]['patterns'][$signature]['count']++;
            if (isset($http['status'])) {
                $entrypoints[$entryKey]['patterns'][$signature]['statuses'][(string) $http['status']] = true;
            }

            // effects 集計
            $effects = isset($trace['effects']) && is_array($trace['effects']) ? $trace['effects'] : [];
            foreach ($effects as $effect) {
                $op      = isset($effect['op']) ? $effect['op'] : '';
                $table   = isset($effect['table']) ? $effect['table'] : null;
                $hash    = isset($effect['statement_hash']) ? $effect['statement_hash'] : '';
                $eCount  = isset($effect['count']) ? (int) $effect['count'] : 1;
                $eKey    = json_encode([$op, $table, $hash]);
                if (!isset($entrypoints[$entryKey]['patterns'][$signature]['effects_acc'][$eKey])) {
                    $entrypoints[$entryKey]['patterns'][$signature]['effects_acc'][$eKey] = [
                        'op' => $op, 'table' => $table, 'statement_hash' => $hash, 'count' => 0,
                    ];
                }
                $entrypoints[$entryKey]['patterns'][$signature]['effects_acc'][$eKey]['count'] += $eCount;
            }
        }

        // 後処理: effects_acc → effects、パターンのソート（参照を使わず直接アクセス）
        foreach (array_keys($entrypoints) as $epKey) {
            foreach (array_keys($entrypoints[$epKey]['patterns']) as $sig) {
                $entrypoints[$epKey]['patterns'][$sig]['effects'] = array_values(
                    $entrypoints[$epKey]['patterns'][$sig]['effects_acc']
                );
                unset($entrypoints[$epKey]['patterns'][$sig]['effects_acc']);
            }
            uasort($entrypoints[$epKey]['patterns'], function ($a, $b) {
                return $b['count'] - $a['count'];
            });
            $entrypoints[$epKey]['patterns'] = array_values($entrypoints[$epKey]['patterns']);
        }

        // エントリポイントを observed_count 降順にソート
        uasort($entrypoints, function ($a, $b) {
            return $b['observed_count'] - $a['observed_count'];
        });

        sort($started);

        return [
            'generated_at'              => date('c'),
            'trace_count'               => count($traces),
            'observed_entrypoint_count' => count($entrypoints),
            'observed_started_at_min'   => count($started) > 0 ? $started[0] : null,
            'observed_started_at_max'   => count($started) > 0 ? $started[count($started) - 1] : null,
            'value_mode'                => $this->valueMode,
            'observed_entrypoints'      => array_values($entrypoints),
        ];
    }

    /**
     * HTTP エンベロープからエントリポイントキーを生成する。
     *
     * @param array $http  trace['http']
     * @return string  例: 'GET /products/{id}'
     */
    public function entryKey(array $http)
    {
        $method = isset($http['method']) ? strtoupper($http['method']) : 'UNKNOWN';
        $path   = isset($http['path_pattern']) ? $http['path_pattern']
                : (isset($http['path']) ? $http['path'] : 'unknown');
        return $method . ' ' . $path;
    }

    /**
     * 実行パターンのシグネチャ文字列を生成する。
     * 同じシグネチャのトレースは同一パターンとして集計される。
     *
     * 形式: "SELECT:PRODUCTS:sha256:abc -> INSERT:ORDERS:sha256:def -> STATUS:200"
     *
     * @param array $trace
     * @return string
     */
    public function patternSignature(array $trace)
    {
        $parts   = [];
        $timeline = isset($trace['timeline']) && is_array($trace['timeline']) ? $trace['timeline'] : [];

        foreach ($timeline as $event) {
            $type = isset($event['type']) ? $event['type'] : '';
            if ($type === 'sql') {
                $op     = isset($event['operation']) ? $event['operation'] : 'UNKNOWN';
                $tables = isset($event['tables']) && is_array($event['tables']) && count($event['tables']) > 0
                    ? implode('+', $event['tables'])
                    : 'NO_TABLE';
                $hash   = isset($event['statement_hash']) ? $event['statement_hash'] : '';
                $parts[] = $op . ':' . $tables . ':' . $hash;
            } elseif ($type === 'custom') {
                $label   = isset($event['label']) ? $event['label'] : 'unknown';
                $parts[] = 'CUSTOM:' . $label;
            }
        }

        $http   = isset($trace['http']) && is_array($trace['http']) ? $trace['http'] : [];
        $status = isset($http['status']) ? (string) $http['status'] : 'UNKNOWN';
        $parts[] = 'STATUS:' . $status;

        return implode(' -> ', $parts);
    }

    /**
     * @param array  $http
     * @param string $entryKey
     * @return array
     */
    private function emptyEntrypoint(array $http, $entryKey)
    {
        $method = isset($http['method']) ? strtoupper($http['method']) : 'UNKNOWN';
        $path   = isset($http['path_pattern']) ? $http['path_pattern']
                : (isset($http['path']) ? $http['path'] : 'unknown');

        return [
            'entrypoint_key' => $entryKey,
            'method'         => $method,
            'path'           => $path,
            'observed_count' => 0,
            'status_codes'   => [],
            'error_count'    => 0,
            'errors'         => [],
            'patterns'       => [],
        ];
    }

    /**
     * SQL イベントの一覧を表示用に整形する。
     *
     * @param array $trace
     * @return array
     */
    private function buildSqlFlow(array $trace)
    {
        $flow     = [];
        $timeline = isset($trace['timeline']) && is_array($trace['timeline']) ? $trace['timeline'] : [];
        $step     = 0;

        foreach ($timeline as $event) {
            if (!isset($event['type']) || $event['type'] !== 'sql') {
                continue;
            }
            $step++;
            $entry = [
                'step'                 => $step,
                'operation'            => isset($event['operation']) ? $event['operation'] : 'UNKNOWN',
                'tables'               => isset($event['tables']) && is_array($event['tables']) ? $event['tables'] : [],
                'statement_hash'       => isset($event['statement_hash']) ? $event['statement_hash'] : '',
                'statement_normalized' => '',
            ];

            if ($this->valueMode === 'tokenized' && isset($event['statement_tokens'])) {
                $entry['statement_normalized'] = $event['statement_tokens'];
            } elseif (isset($event['statement_normalized'])) {
                $entry['statement_normalized'] = $event['statement_normalized'];
            }

            $flow[] = $entry;
        }

        return $flow;
    }

    /**
     * @param array $trace
     * @return array
     */
    private function errorEvents(array $trace)
    {
        $errors = isset($trace['errors']) && is_array($trace['errors']) ? $trace['errors'] : [];
        return array_filter($errors, function ($e) {
            return is_array($e) && !empty($e);
        });
    }
}

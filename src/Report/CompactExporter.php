<?php

namespace CoffeeR\Tekagami\Report;

/**
 * Aggregator の出力を AI 送付用のコンパクト構造へ変換する。
 *
 * SQL 本文は辞書化して重複を除き、各パターンは短い参照 ID と層B fp_hash だけを持つ。
 */
class CompactExporter
{
    /**
     * @param mixed $fingerprinter  将来拡張用。現行スキーマでは statement_fingerprint 必須。
     */
    public function __construct($fingerprinter = null)
    {
    }

    /**
     * @param array $report Aggregator::aggregate() の戻り値
     * @return array
     */
    public function export(array $report)
    {
        $index = $this->buildSqlIndex($report);

        $entrypoints = [];
        $sourceEntryPoints = isset($report['observed_entrypoints']) && is_array($report['observed_entrypoints'])
            ? $report['observed_entrypoints']
            : [];

        foreach ($sourceEntryPoints as $ep) {
            $entrypoints[] = $this->exportEntrypoint($ep, $index);
        }

        return [
            'legend' => [
                'S'       => 'sql_dictionary の参照 ID。SQL 全文は末尾の辞書に 1 回だけ出ます。',
                'fp'      => '層B SQL 意味フィンガープリント。操作・対象テーブル・絞り込み列・書込列から作ります。',
                'layer_a' => '層A statement_hash は正規化 SQL 文字列の厳密同一性です。',
                'layer_b' => '層B fp_hash は CI3 生 SQL と Laravel/Eloquent SQL のような文字列差をまたぐ比較材料です。',
            ],
            'generated_at'              => isset($report['generated_at']) ? $report['generated_at'] : null,
            'trace_count'               => isset($report['trace_count']) ? $report['trace_count'] : 0,
            'observed_entrypoint_count' => isset($report['observed_entrypoint_count']) ? $report['observed_entrypoint_count'] : 0,
            'observed_started_at_min'   => isset($report['observed_started_at_min']) ? $report['observed_started_at_min'] : null,
            'observed_started_at_max'   => isset($report['observed_started_at_max']) ? $report['observed_started_at_max'] : null,
            'value_mode'                => isset($report['value_mode']) ? $report['value_mode'] : 'normalized',
            'observed_entrypoints'      => $entrypoints,
            'sql_dictionary'            => $index['dictionary'],
        ];
    }

    /**
     * @param array $export export() の戻り値
     * @return string
     */
    public function renderMarkdown(array $export)
    {
        $lines = [];
        $lines[] = '# tekagami compact export';
        $lines[] = '';
        $lines[] = '- トレース数: `' . (isset($export['trace_count']) ? $export['trace_count'] : 0) . '`';
        $lines[] = '- 観測エントリポイント数: `' . (isset($export['observed_entrypoint_count']) ? $export['observed_entrypoint_count'] : 0) . '`';
        $lines[] = '';

        $entrypoints = isset($export['observed_entrypoints']) && is_array($export['observed_entrypoints'])
            ? $export['observed_entrypoints']
            : [];
        foreach ($entrypoints as $ep) {
            $lines[] = '## ' . (isset($ep['entrypoint_key']) ? $ep['entrypoint_key'] : '-');
            $lines[] = '';
            $patterns = isset($ep['patterns']) && is_array($ep['patterns']) ? $ep['patterns'] : [];
            foreach ($patterns as $pattern) {
                $lines[] = '- `' . (isset($pattern['id']) ? $pattern['id'] : '-') . '` count='
                    . (isset($pattern['count']) ? $pattern['count'] : 0)
                    . ' signature=`' . (isset($pattern['signature']) ? $pattern['signature'] : '') . '`';
            }
            $lines[] = '';
        }

        $lines[] = '## SQL Dictionary';
        $lines[] = '';
        $dictionary = isset($export['sql_dictionary']) && is_array($export['sql_dictionary'])
            ? $export['sql_dictionary']
            : [];
        foreach ($dictionary as $id => $sql) {
            $lines[] = '- `' . $id . '`: `' . str_replace('`', "'", $sql) . '`';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array $report
     * @return array
     */
    private function buildSqlIndex(array $report)
    {
        $dictionary = [];
        $sqlToId = [];
        $hashToId = [];
        $next = 1;

        $entrypoints = isset($report['observed_entrypoints']) && is_array($report['observed_entrypoints'])
            ? $report['observed_entrypoints']
            : [];

        foreach ($entrypoints as $ep) {
            $patterns = isset($ep['patterns']) && is_array($ep['patterns']) ? $ep['patterns'] : [];
            foreach ($patterns as $pattern) {
                $flow = isset($pattern['sql_flow']) && is_array($pattern['sql_flow']) ? $pattern['sql_flow'] : [];
                foreach ($flow as $step) {
                    $sql = isset($step['statement_normalized']) ? (string)$step['statement_normalized'] : '';
                    if ($sql === '') {
                        continue;
                    }
                    if (!isset($sqlToId[$sql])) {
                        $id = 'S' . $next;
                        $next++;
                        $sqlToId[$sql] = $id;
                        $dictionary[$id] = $sql;
                    }
                    if (isset($step['statement_hash']) && $step['statement_hash'] !== '') {
                        $hashToId[$step['statement_hash']] = $sqlToId[$sql];
                    }
                }
            }
        }

        return [
            'dictionary' => $dictionary,
            'sql_to_id'  => $sqlToId,
            'hash_to_id' => $hashToId,
        ];
    }

    /**
     * @param array $ep
     * @param array $index
     * @return array
     */
    private function exportEntrypoint(array $ep, array $index)
    {
        $patterns = [];
        $sourcePatterns = isset($ep['patterns']) && is_array($ep['patterns']) ? $ep['patterns'] : [];
        foreach ($sourcePatterns as $pattern) {
            $patterns[] = $this->exportPattern($pattern, $index);
        }

        return [
            'entrypoint_key' => isset($ep['entrypoint_key']) ? $ep['entrypoint_key'] : '',
            'method'         => isset($ep['method']) ? $ep['method'] : '',
            'path'           => isset($ep['path']) ? $ep['path'] : '',
            'observed_count' => isset($ep['observed_count']) ? $ep['observed_count'] : 0,
            'status_codes'   => isset($ep['status_codes']) && is_array($ep['status_codes']) ? $ep['status_codes'] : [],
            'error_count'    => isset($ep['error_count']) ? $ep['error_count'] : 0,
            'patterns'       => $patterns,
        ];
    }

    /**
     * @param array $pattern
     * @param array $index
     * @return array
     */
    private function exportPattern(array $pattern, array $index)
    {
        $sqlFlow = [];
        $flow = isset($pattern['sql_flow']) && is_array($pattern['sql_flow']) ? $pattern['sql_flow'] : [];
        foreach ($flow as $step) {
            $sql = isset($step['statement_normalized']) ? (string)$step['statement_normalized'] : '';
            $fingerprint = isset($step['statement_fingerprint']) && is_array($step['statement_fingerprint'])
                ? $step['statement_fingerprint']
                : [];

            $sqlFlow[] = [
                's'      => isset($index['sql_to_id'][$sql]) ? $index['sql_to_id'][$sql] : null,
                'op'     => isset($step['operation']) ? $step['operation'] : 'UNKNOWN',
                'tables' => isset($step['tables']) && is_array($step['tables']) ? $step['tables'] : [],
                'fp'     => isset($fingerprint['fp_hash']) ? $fingerprint['fp_hash'] : null,
            ];
        }

        return [
            'id'                      => isset($pattern['behavior_pattern_id']) ? $pattern['behavior_pattern_id'] : '',
            'count'                   => isset($pattern['count']) ? $pattern['count'] : 0,
            'statuses'                => isset($pattern['statuses']) && is_array($pattern['statuses']) ? array_keys($pattern['statuses']) : [],
            'signature'               => $this->compactSignature(
                isset($pattern['compressed_flow_signature']) ? $pattern['compressed_flow_signature'] : '',
                $index['hash_to_id']
            ),
            'truncated'               => !empty($pattern['truncated']),
            'truncation_limit'        => isset($pattern['truncation_limit']) ? $pattern['truncation_limit'] : null,
            'representative_trace_id' => isset($pattern['representative_trace_id']) ? $pattern['representative_trace_id'] : null,
            'sql_flow'                => $sqlFlow,
            'effects'                 => isset($pattern['effects']) && is_array($pattern['effects']) ? $pattern['effects'] : [],
        ];
    }

    /**
     * @param string $signature
     * @param array  $hashToId
     * @return string
     */
    private function compactSignature($signature, array $hashToId)
    {
        arsort($hashToId);
        foreach ($hashToId as $hash => $id) {
            $signature = str_replace($hash, $id, $signature);
        }
        return $signature;
    }
}

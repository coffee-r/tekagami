<?php

namespace CoffeeR\Digtrace\Report;

/**
 * Aggregator の出力を Markdown 文字列にレンダリングする。
 */
class MarkdownRenderer
{
    /**
     * @param array $report  Aggregator::aggregate() の戻り値
     * @return string  Markdown テキスト
     */
    public function render(array $report)
    {
        $lines = [];
        $lines[] = '# 観測振る舞い証拠レポート';
        $lines[] = '';
        $lines[] = '- 生成日時: `' . (isset($report['generated_at']) ? $report['generated_at'] : '-') . '`';
        $lines[] = '- トレース数: `' . (isset($report['trace_count']) ? $report['trace_count'] : 0) . '`';
        $lines[] = '- 観測エントリポイント数: `' . (isset($report['observed_entrypoint_count']) ? $report['observed_entrypoint_count'] : 0) . '`';
        $lines[] = '- 観測期間: `' . (isset($report['observed_started_at_min']) ? $report['observed_started_at_min'] : '-') . '` ～ `' . (isset($report['observed_started_at_max']) ? $report['observed_started_at_max'] : '-') . '`';
        $lines[] = '- 値モード: `' . (isset($report['value_mode']) ? $report['value_mode'] : 'normalized') . '`';
        $lines[] = '';
        $lines[] = '> 観測エントリポイントは証拠ビューであり、移行単位の仮定ではありません。';
        $lines[] = '';

        $entrypoints = isset($report['observed_entrypoints']) && is_array($report['observed_entrypoints'])
            ? $report['observed_entrypoints']
            : [];

        foreach ($entrypoints as $ep) {
            $lines = array_merge($lines, $this->renderEntrypoint($ep));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array $ep
     * @return string[]
     */
    private function renderEntrypoint(array $ep)
    {
        $lines = [];
        $method = isset($ep['method']) ? $ep['method'] : '';
        $path   = isset($ep['path']) ? $ep['path'] : '';

        $lines[] = '## ' . $method . ' ' . $path;
        $lines[] = '';
        $lines[] = '- エントリポイントキー: `' . $this->escape(isset($ep['entrypoint_key']) ? $ep['entrypoint_key'] : '') . '`';
        $lines[] = '- 観測リクエスト数: `' . (isset($ep['observed_count']) ? $ep['observed_count'] : 0) . '`';
        $lines[] = '- エラー数: `' . (isset($ep['error_count']) ? $ep['error_count'] : 0) . '`';
        $lines[] = '';

        // Status Codes
        $lines[] = '### ステータスコード';
        $lines[] = '';
        $lines[] = '| ステータス | 件数 |';
        $lines[] = '|---|---:|';
        $statusCodes = isset($ep['status_codes']) && is_array($ep['status_codes']) ? $ep['status_codes'] : [];
        if (count($statusCodes) === 0) {
            $lines[] = '| - | 0 |';
        }
        foreach ($statusCodes as $status => $count) {
            $lines[] = '| ' . $this->cell($status) . ' | ' . $this->cell($count) . ' |';
        }
        $lines[] = '';

        // Errors
        $lines[] = '### エラー';
        $lines[] = '';
        $errors = isset($ep['errors']) && is_array($ep['errors']) ? $ep['errors'] : [];
        if (count($errors) === 0) {
            $lines[] = '_(なし)_';
        } else {
            $lines[] = '| エラー | 件数 | 代表トレース |';
            $lines[] = '|---|---:|---|';
            foreach ($errors as $error) {
                $lines[] = '| ' . $this->cell(isset($error['error']) ? $error['error'] : '') . ' | ' . $this->cell(isset($error['count']) ? $error['count'] : 0) . ' | ' . $this->cell(isset($error['representative_trace_id']) ? $error['representative_trace_id'] : '-') . ' |';
            }
        }
        $lines[] = '';

        // Patterns summary table
        $patterns = isset($ep['patterns']) && is_array($ep['patterns']) ? $ep['patterns'] : [];
        $lines[] = '### 観測実行パターン';
        $lines[] = '';

        if (count($patterns) === 0) {
            $lines[] = '_(パターンなし)_';
            $lines[] = '';
            return $lines;
        }

        $lines[] = '| パターン | 件数 | ステータス | SQL フロー |';
        $lines[] = '|---|---:|---|---|';
        foreach ($patterns as $pat) {
            $patId    = isset($pat['behavior_pattern_id']) ? $pat['behavior_pattern_id'] : '-';
            $count    = isset($pat['count']) ? $pat['count'] : 0;
            $statuses = isset($pat['statuses']) && is_array($pat['statuses']) ? implode(', ', array_keys($pat['statuses'])) : '-';
            $sqlFlow  = $this->sqlFlowLabel(isset($pat['sql_flow']) && is_array($pat['sql_flow']) ? $pat['sql_flow'] : []);
            $lines[]  = '| ' . $this->cell($patId) . ' | ' . $this->cell($count) . ' | ' . $this->cell($statuses) . ' | ' . $this->cell($sqlFlow) . ' |';
        }
        $lines[] = '';

        // Pattern details
        foreach ($patterns as $pat) {
            $lines = array_merge($lines, $this->renderPattern($pat));
        }

        return $lines;
    }

    /**
     * @param array $pat
     * @return string[]
     */
    private function renderPattern(array $pat)
    {
        $lines = [];
        $patId = isset($pat['behavior_pattern_id']) ? $pat['behavior_pattern_id'] : '-';

        $lines[] = '### 振る舞いパターン: ' . $patId;
        $lines[] = '';
        $lines[] = '- 観測数: `' . (isset($pat['count']) ? $pat['count'] : 0) . '`';
        $lines[] = '- 代表トレース: `' . (isset($pat['representative_trace_id']) ? $pat['representative_trace_id'] : '-') . '`';
        $lines[] = '- シグネチャ: `' . $this->escape(isset($pat['observed_flow_signature']) ? $pat['observed_flow_signature'] : '') . '`';
        $lines[] = '';

        // SQL Flow
        $sqlFlow = isset($pat['sql_flow']) && is_array($pat['sql_flow']) ? $pat['sql_flow'] : [];
        if (count($sqlFlow) > 0) {
            $lines[] = '**SQL フロー:**';
            $lines[] = '';
            foreach ($sqlFlow as $step) {
                $stepNum  = isset($step['step']) ? $step['step'] : '?';
                $op       = isset($step['operation']) ? $step['operation'] : '?';
                $tables   = isset($step['tables']) && is_array($step['tables']) ? implode(', ', $step['tables']) : '-';
                $hash     = isset($step['statement_hash']) ? $step['statement_hash'] : '';
                $normal   = isset($step['statement_normalized']) ? $step['statement_normalized'] : '';
                $lines[]  = $stepNum . '. `' . $op . '` on `' . $tables . '`';
                if ($normal !== '') {
                    $lines[] = '   ```sql';
                    $lines[] = '   ' . $normal;
                    $lines[] = '   ```';
                }
                if ($hash !== '') {
                    $lines[] = '   ハッシュ: `' . $hash . '`';
                }
            }
            $lines[] = '';
        }

        // Effects
        $effects = isset($pat['effects']) && is_array($pat['effects']) ? $pat['effects'] : [];
        $lines[] = '**更新操作:**';
        $lines[] = '';
        if (count($effects) === 0) {
            $lines[] = '_(なし — 読み取り専用パターン)_';
        } else {
            $lines[] = '| 操作 | テーブル | 件数 |';
            $lines[] = '|---|---|---:|';
            foreach ($effects as $effect) {
                $op    = isset($effect['op']) ? $effect['op'] : '-';
                $table = isset($effect['table']) ? $effect['table'] : '-';
                $cnt   = isset($effect['count']) ? $effect['count'] : 0;
                $lines[] = '| ' . $this->cell($op) . ' | ' . $this->cell($table) . ' | ' . $this->cell($cnt) . ' |';
            }
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param array $sqlFlow
     * @return string
     */
    private function sqlFlowLabel(array $sqlFlow)
    {
        if (count($sqlFlow) === 0) {
            return '(SQL なし)';
        }
        $parts = [];
        foreach ($sqlFlow as $step) {
            $op     = isset($step['operation']) ? $step['operation'] : '?';
            $tables = isset($step['tables']) && is_array($step['tables']) && count($step['tables']) > 0
                ? implode('+', $step['tables'])
                : 'NO_TABLE';
            $parts[] = $op . ' ' . $tables;
        }
        return implode(' → ', $parts);
    }

    /**
     * テーブルセルのパイプ記号をエスケープする。
     *
     * @param mixed $value
     * @return string
     */
    private function cell($value)
    {
        return str_replace('|', '\\|', (string) $value);
    }

    /**
     * バッククォート内の特殊文字をエスケープする。
     *
     * @param string $value
     * @return string
     */
    private function escape($value)
    {
        return str_replace('`', "'", (string) $value);
    }
}

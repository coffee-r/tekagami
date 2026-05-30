<?php

namespace CoffeeR\Digtrace;

use CoffeeR\Digtrace\Http\HttpInput;
use CoffeeR\Digtrace\Http\HttpResponse;
use CoffeeR\Digtrace\Redaction\Redactor;
use CoffeeR\Digtrace\Sink\SinkInterface;
use CoffeeR\Digtrace\Encryption\Encryptor;
use CoffeeR\Digtrace\Sql\SqlAnalyzerInterface;
use CoffeeR\Digtrace\Sql\SqlValueExtractor;

/**
 * CollectorInterface の標準実装。
 *
 * コンストラクタで Config / SinkInterface を受け取り、
 * トレースのライフサイクルを管理する。
 * アプリへの例外伝播ゼロが原則。
 */
class Collector implements CollectorInterface
{
    /** @var Config */
    private $config;

    /** @var SinkInterface */
    private $sink;

    /** @var Redactor */
    private $redactor;

    /** @var SqlAnalyzerInterface */
    private $sqlAnalyzer;

    /** @var SqlValueExtractor */
    private $valueExtractor;

    // ---- アクティブトレースの状態 ----

    /** @var string|null */
    private $traceId = null;

    /** @var string|null */
    private $startedAt = null;

    /** @var HttpInput|null */
    private $http = null;

    /** @var Flow|null */
    private $flow = null;

    /** @var array */
    private $timeline = [];

    /** @var array */
    private $errors = [];

    /** @var int  全イベント共通の seq カウンタ */
    private $seq = 0;

    /** @var bool  timeline 打ち切り済みフラグ（エラー追記を1回に限定） */
    private $timelineTruncated = false;

    /** @var Encryptor|null  encryptionPublicKey が設定されている場合に生成 */
    private $encryptor = null;

    /** @var string|null  トレース開始時に生成する AES キー（全暗号化フィールドで共有） */
    private $traceAesKey = null;

    public function __construct(
        Config $config,
        SinkInterface $sink,
        SqlAnalyzerInterface $sqlAnalyzer
    ) {
        $this->config      = $config;
        $this->sink        = $sink;
        $this->redactor    = new Redactor($config);
        $this->sqlAnalyzer = $sqlAnalyzer;
        $this->valueExtractor = new SqlValueExtractor();

        if ($config->encryptionPublicKey !== null) {
            try {
                $this->encryptor = new Encryptor($config->encryptionPublicKey);
            } catch (\Exception $e) {
                error_log('digtrace: invalid encryptionPublicKey, encryption disabled: ' . $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // CollectorInterface 実装
    // -------------------------------------------------------------------------

    public function start(HttpInput $http, Flow $flow)
    {
        $this->traceId           = $this->generateUuid();
        $this->startedAt         = date('c');
        $this->http              = $http;
        $this->flow              = $flow;
        $this->timeline          = [];
        $this->errors            = [];
        $this->seq               = 0;
        $this->timelineTruncated = false;
        $this->traceAesKey       = $this->encryptor !== null ? Encryptor::generateAesKey() : null;
    }

    public function getActiveTraceId()
    {
        return $this->traceId;
    }

    public function isSampled()
    {
        return true;
    }

    public function addSql($statement, array $binds = [], $source = 'unknown')
    {
        if ($this->isTimelineFull()) {
            return;
        }

        $this->seq++;
        $normalized = $this->sqlAnalyzer->normalize($statement);
        $hash       = $this->sqlAnalyzer->hash($normalized);
        $operation  = $this->sqlAnalyzer->extractOperation($statement);
        $tables     = $this->sqlAnalyzer->extractTables($statement);
        $analysis   = $this->sqlAnalyzer->buildAnalysis($statement, $operation, $tables, $source);

        // sqlValueAllowlist にマッチした列の実値を抽出する。
        // 空配列のときは json_encode で [] になりスキーマ（type:object）に違反するため
        // stdClass にフォールバックして {} を出力する。
        $observed = $this->valueExtractor->extract($statement, $tables, $this->config->sqlValueAllowlist);

        $event = [
            'seq'                  => $this->seq,
            'type'                 => 'sql',
            'operation'            => $operation,
            'tables'               => $tables,
            'statement_normalized' => $normalized,
            'statement_hash'       => $hash,
            'bind_shape'           => $this->redactor->shape($binds),
            'observed_values'      => empty($observed) ? new \stdClass() : $observed,
            'analysis'             => $analysis,
        ];

        if ($this->config->secret !== null) {
            $redactor = $this->redactor;
            $event['statement_tokens'] = $this->sqlAnalyzer->replaceWithCallback(
                $statement,
                function ($matched) use ($redactor) {
                    return $redactor->tokenize($matched);
                }
            );
            if (!empty($binds)) {
                $event['bind_tokens'] = $this->redactor->buildTokens($binds);
            }
        }

        if ($this->config->captureText) {
            $event['statement_text'] = $statement;
        }

        if ($this->encryptor !== null && !empty($binds)) {
            try {
                $event['bind_encrypted'] = $this->encryptor->encrypt($binds, $this->traceAesKey);
            } catch (\Exception $e) {
                // 暗号化失敗はアプリに伝播させない
                error_log('digtrace: bind encryption failed: ' . $e->getMessage());
            }
        }

        $this->timeline[] = $event;
    }

    public function addCustom($label, $data = null)
    {
        if ($this->isTimelineFull()) {
            return;
        }

        $this->seq++;
        $event = [
            'seq'        => $this->seq,
            'type'       => 'custom',
            'label'      => $label,
            'data_shape' => $this->redactor->shape($data),
        ];

        $this->timeline[] = $event;
    }

    public function addError($type, $message = null, $at = null)
    {
        if ($this->traceId === null) {
            return;
        }
        $entry = ['type' => $type];
        if ($message !== null) {
            $entry['message'] = $message;
        }
        if ($at !== null) {
            $entry['at'] = $at;
        }
        $this->errors[] = $entry;
    }

    public function finish(HttpResponse $response)
    {
        if ($this->traceId === null) {
            return;
        }

        $record = null;
        try {
            $record = $this->buildRecord($response);
        } catch (\Throwable $e) {
            $this->errors[] = [
                'type'    => 'capture_failure',
                'message' => 'record build failed: ' . $e->getMessage(),
                'at'      => 'Collector::finish',
            ];
        }

        if ($record === null) {
            try {
                $record = $this->buildMinimalRecord();
            } catch (\Throwable $e) {
                error_log('digtrace: buildRecord and fallback both failed: ' . $e->getMessage());
                $this->resetState();
                return;
            }
        } else {
            // buildRecord 後に errors が追加されていた場合は更新
            $record['errors'] = $this->errors;
        }

        try {
            $this->sink->write($record);
        } catch (\Throwable $e) {
            error_log('digtrace: sink write failed: ' . $e->getMessage());
        } finally {
            $this->resetState();
        }
    }

    // -------------------------------------------------------------------------
    // 内部ヘルパー
    // -------------------------------------------------------------------------

    /**
     * timeline が上限に達しているか確認し、到達済みなら errors に追記して true を返す。
     *
     * @return bool  true = 上限到達（呼び出し元は処理をスキップする）
     */
    private function isTimelineFull()
    {
        if ($this->config->maxTimelineSize === null) {
            return false;
        }
        if (count($this->timeline) < $this->config->maxTimelineSize) {
            return false;
        }
        if (!$this->timelineTruncated) {
            $this->timelineTruncated = true;
            $this->addError(
                'capture_failure',
                'timeline truncated: limit=' . $this->config->maxTimelineSize,
                'Collector'
            );
        }
        return true;
    }

    private function resetState()
    {
        $this->traceId           = null;
        $this->startedAt         = null;
        $this->http              = null;
        $this->flow              = null;
        $this->timeline          = [];
        $this->errors            = [];
        $this->seq               = 0;
        $this->timelineTruncated = false;
        $this->traceAesKey       = null;
    }

    /**
     * 完全な digtrace-v1 レコードを組み立てる。
     *
     * @param HttpResponse $response
     * @return array
     */
    private function buildRecord(HttpResponse $response)
    {
        $record = [
            'schema_version' => 1,
            'trace_id'       => $this->traceId,
            'app_name'       => $this->config->appName,
            'env'            => $this->config->env,
            'started_at'     => $this->startedAt,
            'flow'           => [
                'flow_id' => $this->flow ? $this->flow->flowId : null,
                'seq'     => $this->flow ? $this->flow->seq : null,
            ],
            'redaction' => [
                'tokenized'    => $this->config->secret !== null,
                'token_format' => $this->config->secret !== null
                    ? 'hmac-sha256:' . $this->config->tokenHmacLength
                    : null,
            ],
        ];

        if ($this->encryptor !== null && $this->traceAesKey !== null) {
            try {
                $record['encryption_envelope'] = [
                    'alg' => 'A256GCM+RSA-OAEP',
                    'k'   => $this->encryptor->encryptAesKey($this->traceAesKey),
                ];
            } catch (\Exception $e) {
                error_log('digtrace: envelope key encryption failed: ' . $e->getMessage());
            }
        }

        $record['http']     = $this->buildHttpEnvelope($response);
        $record['timeline'] = $this->timeline;
        $record['effects']  = $this->config->captureEffects ? $this->buildEffects() : [];
        $record['errors']   = $this->errors;

        return $record;
    }

    /**
     * buildRecord が失敗したとき用の最小限フォールバックレコード。
     *
     * @return array
     */
    private function buildMinimalRecord()
    {
        return [
            'schema_version' => 1,
            'trace_id'       => $this->traceId,
            'app_name'       => $this->config->appName,
            'env'            => $this->config->env,
            'started_at'     => $this->startedAt,
            'flow'           => [
                'flow_id' => $this->flow ? $this->flow->flowId : null,
                'seq'     => $this->flow ? $this->flow->seq : null,
            ],
            'redaction' => ['tokenized' => false, 'token_format' => null],
            'http'      => [
                'method' => $this->http ? $this->http->method : 'UNKNOWN',
                'path'   => $this->http ? $this->http->path   : '/',
            ],
            'timeline' => $this->timeline,
            'effects'  => [],
            'errors'   => $this->errors,
        ];
    }

    /**
     * HTTP エンベロープを構築する。
     *
     * @param HttpResponse $response
     * @return array
     */
    private function buildHttpEnvelope(HttpResponse $response)
    {
        $env = [
            'method' => $this->http->method,
            'path'   => $this->http->path,
        ];

        // パスパターンとトークン化パス
        if ($this->http->pathPattern !== null) {
            $env['path_pattern'] = $this->http->pathPattern;
            if ($this->config->secret !== null) {
                $env['path_tokens'] = $this->tokenizePath(
                    $this->http->path,
                    $this->http->pathPattern
                );
            }
        }

        $env['status']        = $response->status;
        $env['response_kind'] = $response->responseKind;
        $env['content_type']  = $response->contentType;

        // リクエストヘッダ
        if ($this->http->requestHeadersRaw !== null) {
            $env['request_headers_shape'] = $this->redactor->shape($this->http->requestHeadersRaw);
            if ($this->config->secret !== null) {
                $env['request_headers_tokens'] = $this->redactor->buildTokens($this->http->requestHeadersRaw);
            }
        }

        // クエリパラメータ
        if ($this->http->queryRaw !== null) {
            $env['query_shape'] = $this->redactor->shape($this->http->queryRaw);
            if ($this->config->secret !== null) {
                $env['query_tokens'] = $this->redactor->buildTokens($this->http->queryRaw);
            }
            $kept = $this->redactor->buildValues($this->http->queryRaw);
            if (!empty($kept)) {
                $env['query_values'] = $kept;
            }
            if ($this->encryptor !== null && $this->traceAesKey !== null) {
                try {
                    $env['query_encrypted'] = $this->encryptor->encrypt($this->http->queryRaw, $this->traceAesKey);
                } catch (\Exception $e) {
                    error_log('digtrace: query encryption failed: ' . $e->getMessage());
                }
            }
        }

        // リクエストボディ
        if ($this->http->requestRaw !== null) {
            $env['request_shape'] = $this->redactor->shape($this->http->requestRaw);
            if ($this->config->secret !== null) {
                $env['request_tokens'] = $this->redactor->buildTokens($this->http->requestRaw);
            }
            $kept = $this->redactor->buildValues((array)$this->http->requestRaw);
            if (!empty($kept)) {
                $env['request_values'] = $kept;
            }
            if ($this->encryptor !== null && $this->traceAesKey !== null) {
                try {
                    $env['request_encrypted'] = $this->encryptor->encrypt($this->http->requestRaw, $this->traceAesKey);
                } catch (\Exception $e) {
                    error_log('digtrace: request encryption failed: ' . $e->getMessage());
                }
            }
        }

        // レスポンスボディ（JSON）
        if ($response->responseBodyRaw !== null) {
            $env['response_shape'] = $this->redactor->shape($response->responseBodyRaw);
        }

        // ビュー（HTML レスポンス）
        if (!empty($response->views)) {
            $views = [];
            foreach ($response->views as $view) {
                $this->seq++;
                $viewEntry = [
                    'seq'      => $this->seq,
                    'template' => $view['template'],
                ];
                if (array_key_exists('vars_raw', $view)) {
                    $viewEntry['vars_shape'] = $this->redactor->shape($view['vars_raw']);
                }
                $views[] = $viewEntry;
            }
            $env['views'] = $views;
        }

        return $env;
    }

    /**
     * timeline の write ops から effects[] を集計する。
     *
     * @return array
     */
    private function buildEffects()
    {
        $writeOps   = ['INSERT', 'UPDATE', 'DELETE', 'MERGE', 'REPLACE', 'UPSERT'];
        $effectsMap = [];

        foreach ($this->timeline as $event) {
            if ($event['type'] !== 'sql') {
                continue;
            }
            if (!in_array($event['operation'], $writeOps, true)) {
                continue;
            }

            $op     = $event['operation'];
            $hash   = $event['statement_hash'];
            $tables = !empty($event['tables']) ? $event['tables'] : [null];

            foreach ($tables as $table) {
                $key = json_encode([$op, $table, $hash]);
                if (!isset($effectsMap[$key])) {
                    $effectsMap[$key] = [
                        'op'             => $op,
                        'table'          => $table,
                        'statement_hash' => $hash,
                        'count'          => 0,
                    ];
                }
                $effectsMap[$key]['count']++;
            }
        }

        return array_values($effectsMap);
    }

    /**
     * path の動的セグメント（pathPattern の {xxx}）を HMAC トークンに置換する。
     *
     * @param string $path
     * @param string $pathPattern  例: '/products/{id}'
     * @return string|null
     */
    private function tokenizePath($path, $pathPattern)
    {
        $pathParts    = explode('/', $path);
        $patternParts = explode('/', $pathPattern);

        if (count($pathParts) !== count($patternParts)) {
            return null;
        }

        $result = [];
        foreach ($patternParts as $i => $segment) {
            if (preg_match('/^\\{.+\\}$/', $segment)) {
                $result[] = $this->redactor->tokenize($pathParts[$i]);
            } else {
                $result[] = $pathParts[$i];
            }
        }

        return implode('/', $result);
    }

    /**
     * UUID v4 を生成する。外部ライブラリ不要。
     *
     * @return string
     */
    private function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}

# digtrace-php

> [!NOTE]
> このライブラリは実験中になります。

稼働中の PHP ウェブアプリの HTTP リクエスト振る舞いを観測し、1リクエスト1行の JSONL（`digtrace-v1`）として記録するライブラリ。

**目的**: 仕様調査・移行調査のための「証拠データ」生成。分析・要約は AI や外部ツールに委ねる。

- フレームワーク非依存・PHP 7.0 以上・Composer 配布
- APM ではない（速度・レイテンシは測らない）
- デフォルトで全値を shape（型構造）に変換（実値は出さない）。実値は白リスト（`keepKeys` / `sqlValueAllowlist`）で明示したものだけ残す
- HMAC 方式のトークン化で「同一値の追跡」を実値なしで可能に
- 公開鍵暗号（RSA+AES-256-GCM）で本番値を暗号化記録・秘密鍵で後から復元
- 書き込み失敗はアプリに伝播しない

## インストール

```bash
composer require coffee-r/digtrace-php
```

> Packagist 公開後に利用可。それまでは `composer.json` の `repositories` に VCS リポジトリを指定してインストールする。
>
> 動作要件は PHP 7.0 以上（`openssl` 拡張。暗号化機能を使う場合）。テストの実行は PHP 7.3 以上（dev 依存の PHPUnit ^9.5 が要求）。

## 基本的な使い方

```php
use CoffeeR\Digtrace\Collector;
use CoffeeR\Digtrace\Config;
use CoffeeR\Digtrace\Flow;
use CoffeeR\Digtrace\Http\HttpInput;
use CoffeeR\Digtrace\Http\HttpResponse;
use CoffeeR\Digtrace\Sink\JsonlSink;
use CoffeeR\Digtrace\Sql\SqliteSqlAnalyzer;

// Controllerの基底クラスのConstructorなど、1リクエスト単位で設定します
$collector = new Collector(
    new Config('your-app-name', 'your-app-env', getenv('your-digtrace-secret')),
    new JsonlSink('/var/log/digtrace/trace.jsonl'),// JSONL ファイルへ書き出し
    new SqliteSqlAnalyzer()                         // SQL 方言は必須・明示注入
);

// Httpレベルのリクエスト情報を指定します。
$http = new HttpInput($yourRequest->method(), $yourRequest->path());
$http->queryRaw          = $yourRequest->query();        // CI3: $this->input->get()  | Laravel: $request->query()
$http->requestRaw        = $yourRequest->requestBody();  // CI3: json_decode($this->input->raw_input_stream, true) | Laravel: $request->all()
$http->requestHeadersRaw = $yourRequest->headers();      // CI3: $this->input->request_headers() | Laravel: $request->headers->all()
$http->pathPattern       = '/products/{id}';             // フレームワークのルート定義などから

// これ以降、後続の証拠データ追加メソッドが使えます。
$collector->start($http, new Flow()); // Flow(flowId, seq) でシナリオ追跡も可

// SQL
$collector->addSql($db->last_query(), [], 'query_history');

// カスタム（キャッシュ・外部 API など）
$collector->addCustom('cache_read', ['key' => $cacheKey, 'hit' => $hit]);

// Httpレベルのレスポンス情報を指定します。
$response               = new HttpResponse();
$response->status       = http_response_code();
$response->responseKind = 'json';
$response->responseBodyRaw = $responseData;

// Controllerの基底クラスのDestructorやテンプレートファイルに変数を渡す直前などに差し込みます。
// このメソッドで指定したJSONLに書き込まれます。
$collector->finish($response);
```

## 設定オプション（Config）

コンストラクタ: `new Config($appName, $env, $secret = null, array $options = [])`

`$secret` は第3引数（位置引数）、それ以外は `$options` 配列で渡す。

| オプション | 型 | デフォルト | 説明 |
|---|---|---|---|
| `secret` *(第3引数)* | string\|null | null | HMAC-SHA256 の共有シークレット。設定時に `*_tokens` フィールドを記録。null = トークン化なし |
| `keepKeys` | array | `[]` | 実値を残すキー名の白リスト（query/body、完全一致・大小無視）。空 = 実値を一切残さない |
| `sqlValueAllowlist` | array | `[]` | SQL の実値を残す列名の白リスト（`'table.column'` または `'column'`） |
| `captureText` | bool | false | 生 SQL テキストを `statement_text` に保存（**平文**・開発用。本番は注意） |
| `captureEffects` | bool | true | INSERT/UPDATE/DELETE の集計を `effects[]` に出力 |
| `tokenHmacLength` | int | 12 | `*_tokens` フィールドの HMAC-SHA256 hex 桁数 |
| `encryptionPublicKey` | string\|null | null | RSA 公開鍵 PEM。設定時に `*_encrypted` フィールドを記録 |
| `maxDepth` | int | 10 | shape 生成の再帰深さ上限 |
| `maxShapeNodes` | int | 10000 | shape 生成のノード訪問数上限（メモリ対策） |
| `maxTimelineSize` | int\|null | 500 | timeline イベント数上限。超過で以降を無視し `errors[]` に記録（null = 無制限） |

## SQL 方言の選択

SQL の正規化・テーブル抽出・トークン化は RDBMS の方言ごとに挙動が変わる（文字列リテラルの `''` エスケープ、識別子クォート、Oracle の `q'[...]'` / `N'...'` / `FROM dual` / dblink、SQLite の `[ident]` など）。アナライザは **`Collector` の第4引数で明示注入する**（必須・null 不可）。同梱は `OracleSqlAnalyzer` / `SqliteSqlAnalyzer` の 2 種。

```php
$collector = new Collector(
    new Config('legacy-shop', 'production', getenv('DIGTRACE_SECRET')),
    new JsonlSink('...'),
    new OracleSqlAnalyzer()   // または new SqliteSqlAnalyzer()
);
```

注入したアナライザは各 SQL イベントの `analysis.dialect`（`oracle` / `sqlite`）に記録される。

他の RDBMS（PostgreSQL/MySQL/SQLServer など）を扱う場合は `SqlAnalyzerInterface` を実装（または `AbstractSqlAnalyzer` を継承して方言フックだけ上書き）し、同じく第4引数に渡す。

```php
$collector = new Collector($config, $sink, new MyPostgresSqlAnalyzer());
```

## CLI ツール（bin/digtrace）

```bash
# Markdown レポート生成（エントリポイント × 実行パターン集計）
php bin/digtrace report trace.jsonl
php bin/digtrace report --format json jan.jsonl feb.jsonl > report.json

# SQL 値の表示モードを指定（デフォルト: normalized / tokenized も選択可）
php bin/digtrace report --value-mode tokenized trace.jsonl

# 複数サーバのログをまとめてレポート（ロードバランス環境）
php bin/digtrace report server1.jsonl server2.jsonl server3.jsonl

# RSA 鍵ペア生成（公開鍵をサーバに、秘密鍵は手元で保管）
php bin/digtrace keygen           # RSA-2048
php bin/digtrace keygen --bits 4096

# 暗号化フィールドの復元（*_encrypted を秘密鍵で復号）
php bin/digtrace decrypt --private-key key.pem trace.jsonl > restored.jsonl
# JSON 配列として出力
php bin/digtrace decrypt --private-key key.pem --format json trace.jsonl > restored.json
```

## エラー・失敗の扱い

- キャプチャ中のエラーは JSONL の `errors[]` フィールドに記録される（`errors[]` が空 = クリーンキャプチャ）
- `sink->write()` の失敗のみ PHP の `error_log()` に出力し、アプリには伝播しない
- timeline が `maxTimelineSize` を超えた場合は以降を無視し `errors[]` に `capture_failure` を追記

## 2つの秘匿設定（`secret` と `encryptionPublicKey`）

両方とも**任意・独立**で、役割が違う。片方だけでも両方でも使える。

| | `secret` | `encryptionPublicKey` |
|---|---|---|
| 種別 | HMAC 共有シークレット（対称） | RSA 公開鍵（非対称） |
| 出力フィールド | `*_tokens`（同値 → 同トークン） | `*_encrypted` |
| 可逆性 | **不可逆**（実値は復元不可） | 秘密鍵で復号可（`bin/digtrace decrypt`） |
| 主用途 | 値の相関分析・AI へそのまま提示できる | 必要時に実値を復元する |
| 運用 | パスワード同様に厳重保護（漏れると低エントロピー値の総当たり相関の余地） | 公開鍵は本番配置可。秘密鍵だけ手元で厳重保護 |

「同じ user_id が複数トレースに出る」を見たい → `secret`。「実際の値そのものを後で読む」必要がある → `encryptionPublicKey`。

## ログボリュームの制御

本番でログが肥大化しないための主なレバー:

- **`maxTimelineSize`**（デフォルト 500）— N+1 等の暴走リクエストで 1 行が肥大化するのを防ぐ。
- **`maxShapeNodes`**（デフォルト 10000）— 巨大 JSON レスポンスの shape を打ち切る。
- **OS のログローテート**（logrotate 等）— JSONL ファイルの世代管理。

res 単位での重複 in/out 削除のような重複排除は行わない。1 リクエスト 1 行という証拠データの性質と `flow` 相関が壊れるため、ボリュームは上記レバーで制御する。

## 出力スキーマ

`docs/schema/digtrace-v1.schema.json` を参照。

## ライセンス

MIT License — Copyright 2026 coffee-r

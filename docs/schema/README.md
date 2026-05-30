# digtrace Schema v1

`digtrace-v1.schema.json` は JSONL の 1 行 = HTTP リクエスト 1 件を表すオブジェクトのスキーマ。

このスキーマは **言語に依存しない契約** として扱う。他言語実装が同じスキーマに沿った出力を出せば、同じ分析ツールで掘り起こしができる。

---

## トップレベルフィールド

| フィールド | 型 | 説明 |
|---|---|---|
| `schema_version` | `1` (const) | スキーマバージョン |
| `trace_id` | string | リクエスト 1 件の識別子 |
| `app_name` | string | アプリケーション名（例: `"coffee-api"`） |
| `env` | string | 環境名（例: `"production"`, `"staging"`） |
| `started_at` | string (date-time) | リクエスト開始時刻 |
| `flow` | object | ユーザーシナリオ相関（後述） |
| `redaction` | object | マスキングモードの記録（後述） |
| `http` | object | リクエスト/レスポンス情報（後述） |
| `timeline` | array | データ操作の時系列（後述） |
| `effects` | array | 書き込み操作のサマリ（後述） |
| `errors` | array | キャプチャ失敗またはアプリ例外 |

> **`sampled` フィールドはない。** JSONL に書き込まれた時点でそのリクエストは必ずサンプリングされているため、フィールドとして持つ意味がない。

---

## Value Classes

値の記録には 4 種類の表現が存在し、用途によって使い分ける。

| サフィックス | 内容 | AI export で残るか |
|---|---|---|
| `*_shape` | 構造とスカラー型のみ。実値なし | 残る |
| `*_tokens` | HMAC トークン。同じ値 → 同じ記号。実値は復元不可 | 残る |
| `*_values` (HTTP) / `observed_values` (SQL) | `keepKeys` / `sqlValueAllowlist` にマッチしたキーの**実値**。業務分岐（`amount >= 100000` など）を読むための非機微値 | **意図的に残す** |
| `*_encrypted` | 完全な実値を RSA+AES で暗号化。`encryptionPublicKey` 設定時のみ | 除去される（復号は別途） |
| `statement_text` | 生 SQL 文字列。`captureText=true` 時のみ・デフォルト off・**平文** | 除去される |

### 白リスト一本（実値の制御）

実値の出力は**デフォルトで一切なし**（`*_shape` のみ）。**明示した白リスト**に載せたキー／列だけが実値として残る:

- `keepKeys`（完全一致・大小無視、デフォルト空）→ HTTP の `*_values` に実値を残す。
- `sqlValueAllowlist`（`テーブル名.列名` または `列名`、大小無視、デフォルト空）→ SQL の `observed_values` に実値を残す。

旧 `deny_keys`（黒リスト）は**廃止**。実値は明示オプトインのみなので「黒が白に勝つ」という順序ルールは存在しない。`secret` 設定時の `*_tokens` は全キーに出る（不可逆 HMAC のため平文漏洩なし）。生の実値が必要なときは `encryptionPublicKey` による `*_encrypted`（`bin/digtrace decrypt` で復号）を使う。

> **設定キー名について**: ここでは PHP の `Config` プロパティ名（`keepKeys` / `captureText` / `encryptionPublicKey` など camelCase）で表記する。これらは実際の API 名（root `README.md` と一致）。

---

## Flow

`flow` は「複数の HTTP リクエストを 1 つのユーザーシナリオとして束ねる仕組み」。

**例**: 商品閲覧 → カート追加 → 注文という 3 リクエストはそれぞれ別 trace だが、`flow_id` が同じなら「同一フロー」として時系列で並べて読める。

| フィールド | 説明 |
|---|---|
| `flow_id` | シナリオ識別子。null のときはフロー追跡なし |
| `seq` | フロー内のステップ番号（任意）。null 可 |

`flow_id` の取得方法（header から取るか、session を使うかなど）はアダプタの実装詳細であり、スキーマには含まない。レポートは `flow_id` でトレースをグループし、`seq` → `started_at` 順に並べる。

---

## HTTP Envelope

`http` オブジェクトはリクエスト入力とレスポンス出力を記録する。

### パスの表現

| フィールド | 例 | 説明 |
|---|---|---|
| `path` | `/products/123` | 実際のリクエストパス |
| `path_pattern` | `/products/{id}` | エンドポイントパターン。ルーターまたは設定から導出。取れなければ null |
| `path_tokens` | `/products/{p-a1b2}` | HMAC トークン化パス。`path_pattern` が null または secret 未設定なら null |

### レスポンス種別

`response_kind` は `"json"` / `"html"` / `"other"` / `null`。

- `"json"` → `response_shape` にレスポンス構造が入る
- `"html"` → `views[]` にテンプレート名と変数の shape が入る。HTML 本文は取らない（大きくなるため）
- `"other"` / `null` → どちらも記録しない

### リクエストヘッダ

`request_headers_shape` はヘッダのキー名と型のみを記録する（値は入らない）。`Authorization`, `X-Api-Key` などの実値が `shape` に出ることはなく、ヘッダの**存在事実**（このエンドポイントは Bearer 認証を要求する、など）だけが残る。`secret` 設定時は `request_headers_tokens` に不可逆 HMAC トークンが入るのみ。

---

## Timeline

`timeline[]` は 1 リクエスト内のデータ操作を **発生順** に並べる。`seq` は SQL / カスタムを通じた単一カウンタ（1 始まり）。

### type: "sql"

SQL ステートメント 1 件。

- `statement_normalized` — リテラルを `{parameter}` に置換した正規化 SQL。同一パターンのグルーピング基準。
- `statement_hash` — `sha256:<hex>` (`statement_normalized` のハッシュ)。実行をまたいで安定。`effects[].statement_hash` と対応。
- `observed_values` — `sqlValueAllowlist` にマッチした列の実値。`{ "ORDERS.STATUS": { redacted: false, values: ["shipped"] } }` のような形式。実行された SQL に値が埋め込まれている場合（INSERT/UPDATE SET/WHERE）に best-effort で抽出する。現状は実値（`redacted: false`）のみ。トークン化版（`redacted: true`）は将来拡張。
- `analysis.analyzer` — 解析方式。現状は `regex`。
- `analysis.dialect` — 解析に使った SQL 方言（`oracle` / `sqlite`）。`Collector` に注入したアナライザに対応する。
- `analysis.warnings` — 信頼度が下がる原因を列挙。`query_history_capture_has_no_bind_values`（CI3 の query_history 経由では bind 分離ができない）など。

### type: "custom"

アプリ／アダプタが手動で埋め込む任意操作。`label` でイベント種別を識別する。

---

## Effects

`effects[]` は `timeline[type=sql]` の書き込みステートメント（INSERT / UPDATE / DELETE / MERGE / REPLACE / UPSERT）を `(op, table, statement_hash)` 単位で集約したサマリ。`effects[].statement_hash` は `timeline[type=sql].statement_hash` と対応する。

`captureEffects: false` で無効化可能。

---

## Production Validation

このスキーマはテスト / CI 上の契約チェックとして使う。`tests/SchemaConformanceTest.php` が fixture と実 `Collector` 出力の両方を検証する。本番リクエストごとのバリデーションは行わない（観測オーバーヘッドを上げないため）。

**AI 分析で効くフィールド**（そのまま AI に渡すとき注目するもの）:
- `statement_normalized` — SQLパターンの読み取り
- `statement_hash` — effects との突合
- `observed_values` — 実値ベースの業務分岐の推定（allowlist に入れた列のみ）
- `effects[]` — 書き込み操作のサマリ（timeline を全スキャンしなくて済む）
- `query_values` / `request_values` — リクエストの業務的な判断材料

---

## 将来の拡張予定（未実装）

実装が決まった時点で追加する。`timeline` 系はスキーマの `oneOf` に未収録。

- `type: "external_http"` — 外部 WebAPI 呼び出し（`url_pattern` / `url_encrypted`）。決済・配送など副作用を持つ呼び出しの移行調査で重要。
- `type: "file"` — ファイルパスの読み書き
- `type: "session"` — セッションへの書き込み
- AI export プロファイル — null・平文・`*_encrypted`・`*_tokens` を除去し、空の `{}`/`[]` を省いて AI 送付用にトークンを削減する JSONL 変換（`bin/digtrace` への将来コマンド）
- `observed_values` のトークン化版（`redacted: true`）

# tekagami Schema v1

`tekagami-v1.schema.json` は JSONL の 1 行 = HTTP リクエスト 1 件を表すオブジェクトのスキーマ。

このスキーマは **言語に依存しない契約** として扱う。他言語実装が同じスキーマに沿った出力を出せば、同じ分析ツールで掘り起こしができる。

---

## トップレベルフィールド

| フィールド | 型 | 説明 |
|---|---|---|
| `schema_version` | `1` (const) | スキーマバージョン |
| `trace_id` | string | リクエスト 1 件の識別子 |
| `started_at` | string (date-time) | リクエスト開始時刻 |
| `flow` | object | 任意の調査フロー相関情報（後述） |
| `redaction` | object | マスキングモードの記録（後述） |
| `http` | object | リクエスト/レスポンス情報（後述） |
| `timeline` | array | データ操作の時系列（後述） |
| `effects` | array | 書き込み操作のサマリ（後述） |
| `errors` | array | キャプチャ失敗またはアプリ例外 |

---

## Value Classes

値の記録には次の表現が存在し、用途によって使い分ける。

| サフィックス | 内容 | AI export で残るか |
|---|---|---|
| `*_shape` | 構造とスカラー型のみ。実値なし | 残る |
| `*_tokens` | HMAC トークン。同じ値 → 同じ記号。実値は復元不可 | 残る |
| `*_values` (HTTP) / `observed_values` (SQL) | `keepKeys` / `sqlValueAllowlist` にマッチしたキーの**実値**。業務分岐（`amount >= 100000` など）を読むための非機微値 | **意図的に残す** |
| `statement_text` | 生 SQL 文字列。`captureText=true` 時のみ・デフォルト off・**平文** | 除去される |

### 白リスト一本（実値の制御）

実値の出力は**デフォルトで一切なし**（`*_shape` のみ）。**明示した白リスト**に載せたキー／列だけが実値として残る:

- `keepKeys`（完全一致・大小無視、デフォルト空）→ HTTP の `*_values` に実値を残す。
- `keepHeaderKeys`（完全一致・大小無視、デフォルト空）→ HTTP ヘッダの shape/token を残す。空ならヘッダの存在情報も残さない。
- `sqlValueAllowlist`（`テーブル名.列名` または `列名`、大小無視、デフォルト空）→ SQL の `observed_values` に実値を残す。

旧 `deny_keys`（黒リスト）は**廃止**。実値は明示オプトインのみなので「黒が白に勝つ」という順序ルールは存在しない。`secret` 設定時の `*_tokens` は全キーに出る（不可逆 HMAC のため平文漏洩なし）。

> **設定キー名について**: ここでは PHP の `Config` プロパティ名（`keepKeys` / `captureText` など camelCase）で表記する。これらは実際の API 名（root `README.md` と一致）。

---

## Flow

`flow` は、複数の HTTP リクエストを調査用に紐づける任意の相関情報。

通常は Collector 導入側で指定しないため、`flow_id` / `seq` は null になる。開発者や QA が明示的に調査シナリオを流す場合だけ、ヘッダやテストコードなどから `flow_id` / `seq` を渡す。

| フィールド | 説明 |
|---|---|
| `flow_id` | 任意の相関識別子。null のときは flow 指定なし |
| `seq` | 明示されたステップ番号（任意）。null 可 |

本番のセッション由来 ID は、ブラウザバック、別タブ、非同期リクエスト、離脱などが混ざるため、業務シナリオそのものを表すとは限らない。report は `flow` から仕様や業務フローを断定しない。

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

`request_headers_shape` / `request_headers_tokens` は `keepHeaderKeys` に一致したヘッダだけを記録する。デフォルトは空なので、ヘッダの存在情報も残らない。`Authorization`, `Cookie`, `X-Api-Key` などは shape だけでも機微になりうるため、必要なヘッダ名だけを明示する。

---

## Timeline

`timeline[]` は 1 リクエスト内のデータ操作を **発生順** に並べる。`seq` は SQL / カスタムを通じた単一カウンタ（1 始まり）。

### type: "sql"

SQL ステートメント 1 件。

- `statement_normalized` — リテラルを `{parameter}` に置換した正規化 SQL。同一パターンのグルーピング基準。
- `statement_hash` — `sha256:<hex>` (`statement_normalized` のハッシュ)。層A。正規化 SQL 文字列の厳密同一性を表し、`effects[].statement_hash` と対応。
- `statement_fingerprint` — 層B。操作種別、対象テーブル、絞り込み列、書込列から作る意味レベルのフィンガープリント。現在の `tekagami-v1` では必須。
  - `fp_hash` — `fp1:<hex>`。CI3 の生SQLと Laravel/Eloquent のSQLのように文字列が変わる移行でも、意味レベルの比較材料にする。
  - `filter_columns` — `WHERE` / `ON` / `HAVING` の比較左辺から抽出した列。
  - `write_columns` — `INSERT` 列リスト / `UPDATE SET` 左辺から抽出した列。
- `observed_values` — `sqlValueAllowlist` にマッチした列の実値。`{ "ORDERS.STATUS": { redacted: false, values: ["shipped"] } }` のような形式。実行された SQL に値が埋め込まれている場合（INSERT/UPDATE SET/WHERE）に正規表現ベースの best-effort で抽出する。関数呼び出し、カンマを含む文字列、複雑なサブクエリ、DB 方言固有のリテラルでは抽出できないことがある。現状は実値（`redacted: false`）のみ。トークン化版（`redacted: true`）は将来拡張。
- `analysis.analyzer` — 解析方式。現状は `regex`。
- `analysis.dialect` — 解析に使った SQL 方言（`oracle` / `sqlite`）。`Collector` に注入したアナライザに対応する。
- `analysis.warnings` — 信頼度が下がる原因を列挙。`query_history_capture_has_no_bind_values`（CI3 の query_history 経由では bind 分離ができない）など。

層Aは「SQL文字列パターンが同じか」を見る署名で、層Bは「操作対象と列集合が同じか」を見る署名です。移行調査では、層Aの差分はノイズになりやすいため、`bin/tekagami export` が出す層B込みのコンパクトJSONを legacy / target で2回作り、AIや人が差分説明を読む運用を想定します。

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
- `statement_fingerprint` — 跨フレームワーク移行での SQL 意味比較
- `observed_values` — allowlist に入れた列の業務判断材料
- `effects[]` — 書き込み操作のサマリ（timeline を全スキャンしなくて済む）
- `query_values` / `request_values` — リクエストの業務的な判断材料

AI 送付用には、生の JSONL ではなく `bin/tekagami export <jsonl...>` の出力を使う。SQL全文は `sql_dictionary` に辞書化され、各実行パターンは短い SQL ID と `fp_hash` を参照するため、通常の `report.md` よりトークンを抑えられる。

旧系/新系の移行評価では、legacy / target を別々に `export` し、必要に応じて両プロジェクトのコード、DDL、fixture と一緒に AI や人間へ渡す。v1 では新旧自動比較コマンドは提供しない。

---

## 将来の拡張予定（未実装）

実装が決まった時点で追加する。`timeline` 系はスキーマの `oneOf` に未収録。

- `type: "external_http"` — 外部 WebAPI 呼び出し（`url_pattern` など）。決済・配送など副作用を持つ呼び出しの移行調査で重要。
- `type: "file"` — ファイルパスの読み書き
- `type: "session"` — セッションへの書き込み
- `observed_values` のトークン化版（`redacted: true`）

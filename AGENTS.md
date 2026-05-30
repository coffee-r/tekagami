# AGENTS.md

## このライブラリは何か

* 実行中のWebアプリの振る舞いを観測し、1リクエストを1行のJSON（JSONL）として記録するPHPライブラリ
* 目的は、**仕様調査・移行調査のための「証拠データ」を作る**こと
* 出すのは「観測された事実」であって「仕様」ではない。分析・要約・仕様の言語化は本ライブラリではやらず、生成AIや人に渡して任せる
* APMではない（速度・レイテンシは測らない）。見たいのは「何が起きたか」であって「速いか」ではない
* フレームワーク非依存・PHP 7.0以上・Composer配布。**どこに差し込むかは使う人が決める**。最初の実装対象はCodeIgniter3、次にLaravelを想定
* ログのJSONは**決まったスキーマ**で書く。こうすれば、別言語で同じスキーマを出すツールを作っても、同じやり方で掘り起こし・確認ができる

## 観測して記録すること

1つのhttp requestごとに、次の情報をJSON 1行にまとめて書き出す。

* スキーマのバージョン
* リクエストID（`trace_id`）
* 一連の利用者の流れを識別するもの（`flow`。HTTPヘッダ由来を主に、ハッシュ化したセッションIDも可。生のセッションIDは使わない）
* アプリの識別子・環境・採取時刻
* **HTTP入力**: URL、メソッド、ヘッダ、query parameter、request body
* **HTTP出力**: ステータス、種別（json / html / その他）、json本体、viewに渡す配列、ヘッダ
  * htmlは本文がでかくなるので解析せず、テンプレート名と「渡した変数の形」だけ記録する方針
* **時系列のデータ操作**（起きた順に並べる）
  * 発行されたSQL
  * 外部WebAPIの呼び出し（できれば。決済・配送など外部副作用は移行調査で重要）
  * 読み書きしたファイルパス（できるか検討中）
  * セッションの書き込み（できるか検討中）
  * 任意の操作（使う人が自前でロギングコードを埋め込む）

値はできる限り生のままでなく「**形（shape）**＝構造とスカラー型」で残す。同じキーで型がゆれる場合は `"string|number"` のように併記する。

## 値の記録のしかた（マスキング）

実データ（SQLやリクエスト値）には個人情報・認証情報・決済情報が混ざりうるので、**デフォルトでは実値を一切出さない**（shape のみ）。実値の保持は **白リストで明示オプトインしたものだけ**。値ごとに次の表現を使い分ける。

| 種類 | フィールド | 概要 |
|---|---|---|
| **伏せ字（shape）** | `*_shape`, `statement_normalized` | 構造とスカラー型のみ／具体値を `{parameter}` に置換。常に保存。実値なし。集計・パターン分類の土台 |
| **目印つき伏せ字** | `*_tokens` | `HMAC-SHA256(値, secret)` の先頭 N 桁（`tokenHmacLength` で変更可、デフォルト12）。同一値 → 同一記号。復元不可。共有シークレット（`secret`）設定時のみ |
| **暗号化** | `*_encrypted`, `encryption_envelope` | RSA+AES-256-GCM で暗号化。秘密鍵があれば後から実値を復元できる。`encryptionPublicKey` 設定時のみ |
| **あえて残す実値** | `query_values` / `request_values`（HTTP）、`observed_values`（SQL） | 機密でない業務判断材料（金額・区分コード等）を実値のまま保持。`keepKeys` / `sqlValueAllowlist` で列挙したものだけ |
| **生 SQL 平文** | `statement_text` | 生 SQL 文字列そのもの。デフォルト off（`captureText=true` で有効化・**平文**・開発用） |

実値を残す対象は、白リストで指定する（実値のオプトイン）。

* `keepKeys` … HTTP の query/request の**キー名**で指定（完全一致・大小無視）→ `*_values`
* `sqlValueAllowlist` … SQL の **`テーブル名.列名`（または列名だけ）** で指定（大小無視）→ `observed_values`

> **黒リスト（`denyKeys`）は持たない。** 実値は明示オプトインのみなので「黒が白に勝つ」順序ルールは存在しない。
> 注意: `keepKeys` / `sqlValueAllowlist` に `password` / `token` 等の機密キーを入れると**そのまま実値が残る**。何を白リストに載せるかは利用者の責任。機密値を相関のためだけに見たいなら `secret`（不可逆 HMAC トークン）を、後で実値を復元したいなら `encryptionPublicKey`（暗号化）を使う。
>
> なお `*_raw`（`query_raw` / `request_raw` / `bind_raw`）はキャプチャ時のモードではなく、**`bin/digtrace decrypt` が `*_encrypted` を復号した出力**としてのみ現れる。

## やらないこと・できないこと

* 観測データの分析機能は作らない（生成AIや人に渡して分析してもらう想定）
* アプリの速度は測らない
* 仕様の断定はできない。実データに基づくので、**観測できなかったケースは掘り起こせない**
* PHPのメソッド呼び出し順は追わない（`debug_backtrace` でできるし、本ツールの役目ではない）
* 完全なSQL構文解析、完全なcall graph、新旧の自動比較、HTML UIや図の自動生成 はしない

## スキーマと検証

* JSONの構造は **JSON Schema** で明示し、コードを読まなくても分かる場所（`docs/schema`）に置く
* スキーマは**言語に依存しない契約**として扱う（他言語で同じスキーマを出せば同じツールで掘れる、が核）
* 検証は **テスト/CI上の契約チェック**として行い、本番の毎リクエストではやらない（観測負荷を上げない）
* fixtureと実際に生成したログの両方をスキーマ検証する。redacted export に生の値が残っていないことも検証する

## SQL解析の信頼度

* 完全な構文解析はしない。正規表現ベースの best-effort（ほどほどに頑張る）
* 伏せ字SQL（`statement_normalized`）とそのハッシュ（`statement_hash`）は、同じSQLパターンをまとめるのに使える信頼度を保つ
* 操作種別（SELECT/INSERT/…）は先頭付近から推定し、判定できなければ `UNKNOWN`
* 対象テーブルは best-effort（サブクエリ・方言・動的SQLで漏れ・誤りが出うる）
* 各SQLに信頼度メタ（`analysis`: analyzer / 操作の確度 / テーブルの確度 / 警告）を付け、レポートでは確度が低いものに注記する

## サンプリングと本番負荷

* サンプリング率を設定できる（低い率から始め、必要に応じて上げる）。リクエスト単位の単純ランダムで、エンドポイント別サンプリングは将来拡張
* 引数の自動キャプチャ・DBスナップショットはしない
* **ログ書き込みに失敗してもアプリ本体は止めない／挙動も変えない**

## 組み込み方針

* 組み込みは**薄く・中央集約で**。フレームワークのフックやミドルウェアでトレース開始・終了を扱い、業務ロジックには手を入れない
* **差し込み箇所は使う人が決める**（フレームワーク非依存）
* ライブラリ本体（core）が持つもの: スキーマ定義、JSON Schema、サンプリング、収集、正規化、マスキング、公開鍵暗号、出力先（sink）、JSONL書き出し、集計レポート（`bin/digtrace report`）
* redacted export（raw/token を除去した AI 送付用 JSONL）は未実装（今後追加予定）
* フレームワーク固有の処理はアダプタに分ける

## Export / Report

* レポートは「観測された入口」（メソッド + URLパターン）と「実行パターン」でトレースをまとめる
* 実行パターンは、SQLの（操作 + テーブル + ハッシュ）の並び ＋ custom イベント ＋ ステータス から機械的に分類する
* **AIを通さず決定論的に作れる**レポートを基本にする。要約・命名・業務ルール推論・日本語仕様化は本体の外
* 新旧の自動比較はしない（同じスキーマのログが両側に揃えば、人やdiffや外部ツールで比べられる）
* 複数サーバのログは `cat` で JSONL を連結するか、`report` コマンドに複数ファイルを渡す

## 観測の限界（明示する）

* 出すのは「観測された事実」。**観測されなかった ＝ 存在しない、ではない**
* カバレッジはサンプリング率・観測期間・流したシナリオ・環境差で変わる。低頻度・月次・特定ユーザ・エラー時だけの分岐は漏れうる
* しきい値や業務ルールは、根拠が足りなければ「仕様候補」として扱う
* レポートには観測件数・初回/最終観測時刻・サンプリング率・環境など、カバレッジを判断できる材料を含める

---

## 実装メモ（v1 実装の設計判断）

### エラーと可観測性

エラーの記録方法を2種に分ける:

| 状況 | 記録先 |
|---|---|
| キャプチャ中のあらゆるエラー（SQL 解析失敗・shape 生成失敗・timeline 打ち切りなど） | JSONL の `errors[]` フィールド |
| `sink->write()` の失敗（JSONL 自体が書き出せない） | PHP の `error_log()`（Apache ログ） |

`errors[]` が空 = クリーンキャプチャ。非空 = 観測時に何らかの問題あり、というシグナルになる。
Apache ログは「ログが書けなかった」という運用上の障害のみに絞る。

これは CollectorInterface のコメント原案から変更している（元の案は `capture_failure` を常に `error_log()` に出力する設計だった）。

### メモリ対策

リクエストごとのメモリ使用を制限する設定を Config に追加:

* `maxDepth = 10`: shape 生成の再帰深さ上限。超えると `'...'` を返して打ち切り
* `maxShapeNodes = 10000`: shape 生成の総ノード訪問数上限。深さだけでなく横への広がりも制限する。大量キーの連想配列や数万件のレスポンスに対応
* `maxTimelineSize = 500`: timeline イベント数の上限（デフォルト 500・null = 無制限）。本番で SQL 発行数が膨大な場合のセーフティ

インデックス配列の shape は重複排除される（`[1,2,3]` → `["number"]`）ため、大量要素の配列は自動的に圧縮される。

### HttpInput の設計方針

`HttpInput` は値オブジェクト。コンストラクタで `method` と `path` を受け取り、残りはパブリックプロパティへの代入で渡す。

```php
$http = new HttpInput('POST', '/orders');
$http->queryRaw          = /* フレームワークが解析済みのクエリ */;
$http->requestRaw        = /* フレームワークが解析済みのボディ */;
$http->requestHeadersRaw = /* ヘッダ配列 */;
$http->pathPattern       = /* ルートパターン文字列 */;
```

**`fromGlobals()` は作らない**: `php://input` はストリームなので一度読むと消える。フレームワークが先に読んでいると空になるリスクがある。そのため「フレームワークが既にパースした値を渡す」設計にして、グローバルへの依存をライブラリ本体に持ち込まない。

フレームワーク別の推奨取得元:

| フレームワーク | queryRaw | requestRaw |
|---|---|---|
| CI3 | `$this->input->get()` | `json_decode($this->input->raw_input_stream, true)` または `$this->input->post()` |
| Laravel | `$request->query()` | `$request->all()` |
| 素の PHP | `$_GET` | `json_decode(file_get_contents('php://input'), true)` ※先読みに注意 |

**pathPattern について**: CI3 は公式 API でパターン文字列（`/products/{id}` 形式）を取り出せない。CI3 アダプタ実装時は `config/routes.php` にヘルパーを追加してもらう、または現 URI にマッチしたルート定義を逆引きして変換する実装を検討する。不明な場合は `null` のままにする（Aggregator は実 path でグルーピングにフォールバックする）。

### SQL 解析の best-effort 仕様

* 統合 regex（シングルクォート優先の alternation）で正規化。二重置換を防ぐため単一パスで処理
* ダブルクォートは識別子（テーブル名・列名）として扱い、リテラルとして正規化しない
* スキーマ付きテーブル名（`SHOP.ORDERS`）はフルで保持
* `CALL` / `EXECUTE` / `EXEC` はすべて operation `'CALL'` に正規化
* statement_tokens（HMAC トークン化 SQL）は元の SQL に対して同一 regex を `preg_replace_callback` で適用し生成する（normalized とは別パス）

### 公開鍵暗号（Encryptor / Decryptor）

HMAC トークンは一方向なので本番環境の実値を後から復元できない。それを補う機能として実装。

* 方式: **ハイブリッド RSA-OAEP + AES-256-GCM**（OpenSSL 拡張、PHP 7.0+ 標準バンドル）
* **RSA は 1 トレースにつき 1 回だけ**実行する設計。トレース開始時にランダム AES-256 キーを生成し RSA 暗号化して `encryption_envelope.k` に保存。各フィールドは同じ AES キーで AES-256-GCM 暗号化（IV はフィールドごとに独立）
* CPU 負荷: RSA-2048 で約 0.04ms/トレース（1% サンプリングなら平均 0.0004ms/req）
* Config に `encryptionPublicKey`（PEM 文字列）を設定すると Collector が次のフィールドを記録:
  * `encryption_envelope` — RSA 暗号化済み AES キー（トレース上位）
  * `http.query_encrypted`, `http.request_encrypted` — クエリ・ボディ全体を暗号化
  * `timeline[type=sql].bind_encrypted` — バインド値全体を暗号化
* 復号は `bin/digtrace decrypt --private-key key.pem`。`*_encrypted` → `*_raw` として展開して出力する（`*_encrypted` 自体は残す）
* 秘密鍵の管理は利用者の責任（ライブラリでは強制しない）

### CLI（bin/digtrace）

```
php bin/digtrace report <jsonl...> [--format md|json] [--value-mode normalized|tokenized]
php bin/digtrace decrypt <jsonl...> --private-key key.pem [--format jsonl|json]
php bin/digtrace keygen [--bits 2048|4096]
```

* 複数 JSONL ファイルを受け付けてマージ処理（ロードバランス環境での複数サーバログ集計に対応）
* 全出力は STDOUT（ファイル保存はシェルのリダイレクトで）
* `keygen` は `key.pem`（秘密鍵 600 権限）と `key.pub.pem`（公開鍵）をカレントディレクトリに生成
* plain PHP 実装（Symfony Console 等への依存なし）、`vendor/bin` 経由でも動作

### パターンシグネチャ算出（Aggregator）

実行パターンの同定に使う文字列キー。人間可読形式を採用:

```
SELECT:PRODUCTS:sha256:abc -> INSERT:ORDERS:sha256:def -> STATUS:201
```

* `timeline` を seq 順に走査し、`type=sql` → `{op}:{tables}:{hash}`、`type=custom` → `CUSTOM:{label}` を連結
* 末尾に `STATUS:{status}` を追加して `->` でつなぐ
* 同じ文字列のトレースを同一パターンとして集計（ハッシュは使わず文字列をそのまま ID として使用）

### スキーマ検証（契約チェック）

`tests/SchemaConformanceTest.php` が `docs/schema/digtrace-v1.schema.json`（JSON Schema draft 2020-12）に対して
fixture と実 `Collector` 出力の両方を検証する。本番の毎リクエストでは行わない（観測負荷を上げない）。
バリデータは `opis/json-schema`（`require-dev`）。

### 実装されていないもの（v1 のスコープ外）

* フレームワーク固有のアダプタ（CI3・Laravel）
* `timeline` の `type: "external_http"`（外部 WebAPI 呼び出し）・`type: "file"`・`type: "session"`（スキーマ未収録の将来拡張）
* redacted export コマンド／AI export プロファイル（raw/token フィールドを除去した AI 送付用 JSONL 生成）
* `observed_values` のトークン化版（`redacted: true`）。現状は `sqlValueAllowlist` にマッチした列の実値（`redacted: false`）のみ抽出する

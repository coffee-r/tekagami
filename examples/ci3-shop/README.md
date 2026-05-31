# CI3 Shop E2E Example

CodeIgniter3 + PHP 7.3 + Oracle Database Free のローカルE2Eサンプルです。ECサイトの注文分岐を実際のHTTP APIとして叩き、tekagami JSONL とレポートを生成します。

## 構成

- `web`: PHP 7.3 Apache、CodeIgniter3、OCI8、tekagami
- `oracle`: `gvenzl/oracle-free:23-slim`
- ログ出力: `examples/ci3-shop/var/tekagami.jsonl`

Oracle は 12c そのものではありません。arm64 Mac でも動かしやすい最小寄りの現実解として 23-slim を使い、SQLは保守的なOracle方言に寄せています。

## 起動

```bash
cd examples/ci3-shop
docker compose up -d --build
./scripts/wait-for-app.sh
./scripts/run-e2e.sh
./scripts/report.sh
./scripts/analyze.sh
```

生成物:

- `var/tekagami.jsonl`
- `var/flow-map.tsv`
- `var/report.md`
- `var/report.json`
- `var/export.json`
- `var/analysis.md`

`var/` には代表サンプル出力をコミットしています。exampleを実行しなくても、tekagami がどのような JSONL / report / export を出すか確認できます。再実行すると `trace_id`、時刻、flow id などが変わるため差分が出ます。

AI に業務挙動の仕様候補を整理させるときのプロンプト例は `ai/PROMPT.md` にあります。標準コンテキストは `var/export.json`、`var/analysis.md`、routes/controller/model、DDL/fixture、E2Eシナリオです。`var/flow-map.tsv` は人間の検証用対応表なので、AI 分析では通常渡しません。

## API

- `POST /api/cart/items`
- `GET /api/cart`
- `POST /api/checkout/quote`
- `POST /api/orders`
- `POST /api/orders/{id}/cancel`
- `POST /api/payments/credit/callback`

`scripts/run-e2e.sh` は各シナリオに8桁のランダム `flow_id` を割り当て、JSONL にはその ID だけを残します。人間が読むための対応表は `var/flow-map.tsv` に出ます。

## 注意

このサンプルは仕様調査用の観測データを作るためのデモです。業務ルールの完全なEC実装ではなく、分岐、SQLフロー、副作用、N+1、customイベントの観測を目的にしています。

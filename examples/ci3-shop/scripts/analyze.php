<?php

if ($argc < 3) {
    fwrite(STDERR, "Usage: php analyze.php <tekagami.jsonl> <report.json> [flow-map.tsv]\n");
    exit(1);
}

$jsonlPath = $argv[1];
$reportPath = $argv[2];
$flowMapPath = isset($argv[3]) ? $argv[3] : null;

$customCounts = [];
$flows = [];
$flowLabels = loadFlowMap($flowMapPath);
$flowSummaries = [];
$traceCount = 0;

$fh = fopen($jsonlPath, 'r');
if (!$fh) {
    fwrite(STDERR, "Cannot open JSONL: $jsonlPath\n");
    exit(1);
}

while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $trace = json_decode($line, true);
    if (!is_array($trace)) {
        continue;
    }
    $traceCount++;
    if (isset($trace['flow']['flow_id']) && $trace['flow']['flow_id'] !== null) {
        $flows[$trace['flow']['flow_id']] = true;
    }
    $rawFlowId = isset($trace['flow']['flow_id']) && $trace['flow']['flow_id'] !== null ? $trace['flow']['flow_id'] : '(none)';
    $flowKey = isset($flowLabels[$rawFlowId]) ? $flowLabels[$rawFlowId] : $rawFlowId;
    if (!isset($flowSummaries[$flowKey])) {
        $flowSummaries[$flowKey] = [
            'requests' => 0,
            'statuses' => [],
            'paths' => [],
            'events' => [],
            'updates' => [],
            'flow_ids' => [],
        ];
    }
    $flowSummaries[$flowKey]['requests']++;
    if ($rawFlowId !== '(none)') {
        $flowSummaries[$flowKey]['flow_ids'][$rawFlowId] = true;
    }
    if (isset($trace['http']['status'])) {
        $flowSummaries[$flowKey]['statuses'][(string) $trace['http']['status']] = true;
    }
    if (isset($trace['http']['method'], $trace['http']['path'])) {
        $flowSummaries[$flowKey]['paths'][$trace['http']['method'] . ' ' . $trace['http']['path']] = true;
    }
    foreach (isset($trace['timeline']) && is_array($trace['timeline']) ? $trace['timeline'] : [] as $event) {
        if (isset($event['type'], $event['label']) && $event['type'] === 'custom') {
            if (!isset($customCounts[$event['label']])) {
                $customCounts[$event['label']] = 0;
            }
            $customCounts[$event['label']]++;
            $flowSummaries[$flowKey]['events'][$event['label']] = true;
        }
        if (isset($event['type'], $event['operation']) && $event['type'] === 'sql') {
            $operation = $event['operation'];
            if ($operation === 'INSERT' || $operation === 'UPDATE' || $operation === 'DELETE') {
                $tables = isset($event['tables']) && is_array($event['tables']) ? implode('+', $event['tables']) : '';
                $flowSummaries[$flowKey]['updates'][$operation . ' ' . $tables] = true;
            }
        }
    }
}
fclose($fh);
ksort($customCounts);
ksort($flowSummaries);

$report = json_decode(file_get_contents($reportPath), true);
$entrypointCount = is_array($report) && isset($report['observed_entrypoint_count']) ? $report['observed_entrypoint_count'] : 0;

echo "# tekagami E2E 分析メモ\n\n";
echo "## 観測サマリ\n\n";
echo "- トレース数: `" . $traceCount . "`\n";
echo "- 観測エントリポイント数: `" . $entrypointCount . "`\n";
echo "- flow数: `" . count($flows) . "`\n\n";

if (count($flowLabels) > 0) {
    echo "## flow ID 対応表\n\n";
    echo "| flow_id | シナリオ |\n";
    echo "|---|---|\n";
    foreach ($flowLabels as $id => $label) {
        echo "| `" . mdCell($id) . "` | " . mdCell($label) . " |\n";
    }
    echo "\n";
}

echo "## customイベント\n\n";
if (count($customCounts) === 0) {
    echo "_なし_\n\n";
} else {
    echo "| イベント | 件数 |\n";
    echo "|---|---:|\n";
    foreach ($customCounts as $label => $count) {
        echo "| `" . str_replace('|', '\\|', $label) . "` | " . $count . " |\n";
    }
    echo "\n";
}

echo "## EC業務分岐ごとの証拠\n\n";
echo "| 業務分岐 | 観測flow | tekagamiで見える証拠 | customイベントの要否 |\n";
echo "|---|---|---|---|\n";

$branches = [
    ['購入制限: 1個限定の初回注文は通る', ['one-qty-cancelled-ok'], '注文作成が `201`。`SHOP_ORDERS` / `SHOP_ORDER_ITEMS` への `INSERT` とカート削除が残る。', '`purchase_limit_checked` があると、単なる注文成功ではなく購入制限チェック通過だと読める。'],
    ['購入制限: 注文済み商品は再注文不可', ['one-qty-after-order-rejected'], '`POST /api/orders` が `422`。過去注文参照の後、注文 `INSERT` が発生しない。', '`purchase_limit_rejected` が必要。SQLだけでは「購入制限」なのか他の注文拒否なのか名前付けしにくい。'],
    ['購入制限: キャンセル済み注文は制限対象外', ['one-qty-after-cancel-ok'], 'キャンセルAPIで `SHOP_ORDERS` が `UPDATE` され、その後の注文は `201` と注文 `INSERT`。', '`order_cancelled` と `purchase_limit_checked` があると、キャンセル後許可の根拠がつながる。'],
    ['購入制限: 一回限定商品は再購入不可', ['one-time-rejected'], '`POST /api/orders` が `422`。過去注文参照の後、注文 `INSERT` が発生しない。', '`purchase_limit_rejected` が必要。同じ422でも業務理由の区別に使う。'],
    ['購入制限: 同一注文内の数量超過は不可', ['one-qty-quantity-rejected'], '`POST /api/orders` が `422`。カート投入は成功し、注文作成は発生しない。', '`purchase_limit_rejected` が必要。数量超過と過去購入制限はSQLフローが似る。'],
    ['商品: マスタに存在しない商品コードはカート投入不可', ['product-not-found'], '`SHOP_PRODUCTS` の参照後、`POST /api/cart/items` が `404`。カート `INSERT` は発生しない。', 'customイベントなしでも比較的読める。業務名を揃えるなら `product_rejected` のようなイベントがあるとよい。'],
    ['商品: 販売期間内ならカート投入可', ['sale-period-ok'], '`SHOP_PRODUCTS` の参照後、`SHOP_CART_ITEMS` に `INSERT`。', 'customイベントなしでも成功/失敗差は読めるが、販売期間チェック通過だと明示するにはイベントがあるとよい。'],
    ['商品: 日付を跨いで販売期間終了後ならカート投入不可', ['sale-period-ended'], '`SHOP_PRODUCTS` の参照後、`POST /api/cart/items` が `422`。カート `INSERT` は発生しない。', 'customイベントが必要。SQLだけでは販売終了、在庫切れ、別の422理由を区別しにくい。'],
    ['ゆうメール: 単品ならP付き商品コードへ置換', ['yumail-single', 'cod-yumail-rejected'], '`SHOP_CART_ITEMS` の `INSERT` 後に同テーブル `UPDATE`。', '`yumail_product_code_swapped` があると、更新理由が配送用コード置換だと分かる。'],
    ['ゆうメール: 複数商品になるとP付きコードを戻す', ['yumail-revert'], '`SHOP_CART_ITEMS` の追加後、既存行に `UPDATE`。', '`yumail_product_code_reverted` が必要。SQLだけでは何の正規化か断定しにくい。'],
    ['支払方法: ゆうメールは代引き不可', ['cod-yumail-rejected'], '`POST /api/checkout/quote` が `422`。注文更新は発生しない。', '`shipping_method_selected` / `payment_method_filtered` が必要。'],
    ['支払方法: 別届け先は代引き不可', ['cod-other-address-rejected'], '`POST /api/checkout/quote` が `422`。住所参照後、注文更新なし。', '`payment_method_filtered` が必要。ゆうメール不可との区別にflowかイベントが要る。'],
    ['支払方法: 与信不足なら後払い不可', ['deferred-credit-limit-rejected'], '`POST /api/checkout/quote` が `422`。顧客・住所・カート参照後、注文更新なし。', '`payment_method_filtered` が必要。閾値や候補除外理由はSQLだけでは読めない。'],
    ['支払方法: 前払いなら通る', ['prepaid-only-ok'], '`POST /api/checkout/quote` が `200`。', '`payment_method_filtered` があると、利用可能支払方法を絞った後の成功と分かる。'],
    ['配送日: ゆうメールは日付指定不可', ['yumail-date-rejected'], '`POST /api/checkout/quote` が `422`。', '`delivery_date_rejected` が必要。'],
    ['配送日: 遅延都道府県は日付指定不可', ['delayed-area-date-rejected'], '`SHOP_DELAY_PREFECTURES` 参照後、`422`。', '`delivery_date_rejected` があると拒否理由を追える。'],
    ['配送日: 離島郵便番号は日付指定不可', ['remote-island-date-rejected'], '`SHOP_REMOTE_ISLAND_POSTALS` 参照後、`422`。', '`delivery_date_rejected` があると拒否理由を追える。'],
    ['配送日: 営業日計算の最短日より早い日は不可', ['working-day-window-rejected'], '`SHOP_WORKING_DAYS` 参照後、`422`。', '`delivery_date_rejected` が必要。'],
    ['配送日: 最大日数を超える日は不可', ['delivery-date-too-late-rejected'], '`SHOP_WORKING_DAYS` 参照後、`422`。', '`delivery_date_rejected` が必要。早すぎる日付との区別はflow名かイベント値が要る。'],
    ['配送時間: ゆうメールは時間指定不可', ['delivery-time-yumail-rejected'], '`POST /api/checkout/quote` が `422`。', '`delivery_time_rejected` が必要。'],
    ['送料: 初回顧客は低い閾値で送料無料', ['first-free-shipping'], '`POST /api/checkout/quote` が `200`。顧客参照とカート金額参照が残る。', '`free_shipping_applied` があると送料判定が発生したことは分かる。実際の閾値理由は分からない。'],
    ['送料: リピーターは同額でも送料あり', ['repeat-shipping-fee'], '`POST /api/checkout/quote` が `200`。', '`free_shipping_applied` は必要。ただしイベント値を見ないと送料0/300の差はflow名頼み。'],
    ['送料: リピーターも高額なら送料無料', ['repeat-free-shipping'], '`POST /api/checkout/quote` が `200`。', '`free_shipping_applied` は必要。ただし閾値の意味は外部分析対象。'],
    ['プレゼント: 対象商品で同梱品を自動追加', ['gift-attached'], '`SHOP_CART_ITEMS` に商品追加とギフト追加の2回 `INSERT`。', '`gift_attached` があると2回目のINSERTが特典同梱だと分かる。'],
    ['ポイント交換: ゲストは不可', ['point-guest-rejected'], '`POST /api/cart/items` が `422`。カート `INSERT` は発生しない。', '`point_exchange_rejected` が必要。'],
    ['ポイント交換: 残高十分ならカート投入と注文が通る', ['point-ok'], 'カート `INSERT` と注文 `INSERT`。注文時にもポイント確認が走る。', '`point_exchange_checked` があるとポイント分岐の通過だと分かる。'],
    ['ポイント交換: 残高不足は不可', ['point-insufficient'], '`POST /api/cart/items` が `422`。顧客参照後、カート `INSERT` なし。', '`point_exchange_rejected` が必要。'],
    ['バラエティ: 3点セットなら注文可', ['variety-ok'], '3回のカート `INSERT` 後、注文 `INSERT` が3明細ぶん発生。', '`variety_bundle_validated` があるとセット価格/成立条件の分岐だと分かる。'],
    ['バラエティ: 通常商品混在は不可', ['variety-mixed-rejected'], '`POST /api/orders` が `422`。注文 `INSERT` なし。', '`variety_bundle_rejected` が必要。'],
    ['バラエティ: 点数不足は不可', ['variety-quantity-rejected'], '`POST /api/orders` が `422`。注文 `INSERT` なし。', '`variety_bundle_rejected` が必要。混在NGとの区別はflow名かイベント値が要る。'],
    ['予約商品: 予約カートへ入る', ['reserved-cart'], '`SHOP_RESERVATION_CART_ITEMS` に `INSERT`。通常カートとは別テーブル。', '`reservation_cart_used` があると予約扱いの業務意図が明確。'],
    ['セット商品: 明細表示で構成品を商品ごとに読む', ['set-n-plus-one'], '`GET /api/cart` で `SHOP_PRODUCT_COMPONENTS` へのSELECTが複数回出る。', '`set_components_loaded` があるとN+1風の反復SELECTの理由が分かる。'],
    ['注文: メールなし顧客はチェックアウト不可', ['missing-email-rejected'], '`POST /api/orders` が `422`。顧客参照後、注文 `INSERT` なし。', '`checkout_blocked` が必要。'],
    ['決済: クレジット注文後にコールバックで決済状態更新', ['credit-order', 'credit-callback'], '注文 `INSERT` 後、callbackで `SHOP_ORDERS` が `UPDATE`。', '`order_created` / `credit_callback_recorded` があると外部決済連携の副作用として読める。'],
];

foreach ($branches as $branch) {
    echo '| ' . mdCell($branch[0]) . ' | ' . mdCell(flowCell($branch[1], $flowSummaries)) . ' | ' . mdCell($branch[2] . ' ' . evidenceCell($branch[1], $flowSummaries)) . ' | ' . mdCell($branch[3]) . " |\n";
}
echo "\n";

echo "## 分析できること\n\n";
echo "- APIエントリポイント、ステータス分布、レスポンスshape。\n";
echo "- SQLの実行順、参照/更新テーブル、注文・キャンセル・決済コールバックの副作用。\n";
echo "- セット構成商品の内訳取得で発生するN+1のような反復SELECT。\n";
echo "- `purchase_limit_rejected` や `payment_method_filtered` など、customイベントで明示した業務分岐の発生。\n\n";

echo "## 部分的に分析できること\n\n";
echo "- 配送方法、支払い方法、送料無料、購入制限、ポイント不足、バラエティ商品の条件分岐。\n";
echo "- これらはSQLフローだけでも痕跡は出るが、業務名や判定理由はcustomイベントのラベルがあって初めて読みやすくなる。\n\n";

echo "## 分析できないこと\n\n";
echo "- なぜ閾値が3000円、7000円、10000円なのかという業務上の理由。\n";
echo "- 観測していない分岐、低頻度ケース、月次処理、特定ユーザーだけの例外。\n";
echo "- 観測されなかった挙動が存在しないという断定。\n";
echo "- 法務・運用・顧客対応上の意図。\n";

function flowCell(array $flowIds, array $flowSummaries)
{
    $parts = [];
    foreach ($flowIds as $flowId) {
        $mark = isset($flowSummaries[$flowId]) ? '' : ' (未観測)';
        $idLabel = '';
        if (isset($flowSummaries[$flowId]['flow_ids']) && count($flowSummaries[$flowId]['flow_ids']) > 0) {
            $idLabel = ' / ' . implode(',', array_keys($flowSummaries[$flowId]['flow_ids']));
        }
        $parts[] = '`' . $flowId . $idLabel . '`' . $mark;
    }
    return implode('<br>', $parts);
}

function evidenceCell(array $flowIds, array $flowSummaries)
{
    $parts = [];
    foreach ($flowIds as $flowId) {
        if (!isset($flowSummaries[$flowId])) {
            continue;
        }
        $summary = $flowSummaries[$flowId];
        $statuses = array_keys($summary['statuses']);
        sort($statuses);
        $events = array_keys($summary['events']);
        sort($events);
        $updates = array_keys($summary['updates']);
        sort($updates);

        $chunks = [];
        if (count($statuses) > 0) {
            $chunks[] = 'status=' . implode('/', $statuses);
        }
        if (count($events) > 0) {
            $chunks[] = 'custom=' . implode(',', $events);
        }
        if (count($updates) > 0) {
            $chunks[] = '更新=' . implode(',', $updates);
        }
        if (count($chunks) > 0) {
            $parts[] = '`' . $flowId . '`(' . implode('; ', $chunks) . ')';
        }
    }
    return count($parts) > 0 ? '<br>' . implode('<br>', $parts) : '';
}

function mdCell($text)
{
    return str_replace(["\r", "\n", '|'], ['', '<br>', '\\|'], $text);
}

function loadFlowMap($path)
{
    if ($path === null || !is_file($path)) {
        return [];
    }

    $map = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $i => $line) {
        if ($i === 0 && strpos($line, "flow_id\t") === 0) {
            continue;
        }
        $parts = explode("\t", $line, 2);
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            $map[$parts[0]] = $parts[1];
        }
    }
    return $map;
}

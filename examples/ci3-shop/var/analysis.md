# tekagami E2E 分析メモ

## 観測サマリ

- トレース数: `69`
- 観測エントリポイント数: `7`
- flow数: `36`

## flow ID 対応表

| flow_id | シナリオ |
|---|---|
| `50abeba1` | reset |
| `c8d6549c` | one-qty-cancelled-ok |
| `647f2eb2` | one-qty-after-order-rejected |
| `ba987a40` | one-qty-after-cancel-ok |
| `551e795b` | one-time-rejected |
| `ae662cdd` | one-qty-quantity-rejected |
| `fabc0fac` | product-not-found |
| `d9931412` | sale-period-ok |
| `d9ce900b` | sale-period-ended |
| `ebc5f8f1` | yumail-single |
| `fb8371cf` | yumail-revert |
| `1308dbe5` | cod-yumail-rejected |
| `9a3fd9fb` | cod-other-address-rejected |
| `80a12831` | deferred-credit-limit-rejected |
| `4a298264` | prepaid-only-ok |
| `71a0512f` | yumail-date-rejected |
| `d2e0821a` | delayed-area-date-rejected |
| `6ff8dab6` | remote-island-date-rejected |
| `c6bde0ff` | working-day-window-rejected |
| `2d43abae` | delivery-date-too-late-rejected |
| `62ce81e6` | delivery-time-yumail-rejected |
| `5f445295` | first-free-shipping |
| `a1f5dc18` | repeat-shipping-fee |
| `40662b6e` | repeat-free-shipping |
| `7716c4f4` | gift-attached |
| `899be8d0` | point-guest-rejected |
| `4bb1b390` | point-ok |
| `ab519462` | point-insufficient |
| `dc63c524` | variety-ok |
| `d6896ce6` | variety-mixed-rejected |
| `9c4460d0` | variety-quantity-rejected |
| `75a88ab0` | reserved-cart |
| `63b356a2` | set-n-plus-one |
| `f75293c1` | missing-email-rejected |
| `8d288940` | credit-order |
| `1c6fd68c` | credit-callback |

## customイベント

| イベント | 件数 |
|---|---:|
| `checkout_blocked` | 1 |
| `credit_callback_recorded` | 1 |
| `delivery_date_rejected` | 5 |
| `delivery_time_rejected` | 1 |
| `fixtures_reset` | 1 |
| `free_shipping_applied` | 10 |
| `gift_attached` | 1 |
| `order_cancelled` | 1 |
| `order_created` | 5 |
| `payment_method_filtered` | 19 |
| `point_exchange_checked` | 2 |
| `point_exchange_rejected` | 2 |
| `purchase_limit_checked` | 2 |
| `purchase_limit_rejected` | 3 |
| `reservation_cart_used` | 1 |
| `set_components_loaded` | 2 |
| `shipping_method_selected` | 19 |
| `variety_bundle_rejected` | 2 |
| `variety_bundle_validated` | 1 |
| `yumail_product_code_reverted` | 1 |
| `yumail_product_code_swapped` | 2 |

## EC業務分岐ごとの証拠

| 業務分岐 | 観測flow | tekagamiで見える証拠 | customイベントの要否 |
|---|---|---|---|
| 購入制限: 1個限定の初回注文は通る | `one-qty-cancelled-ok / c8d6549c` | 注文作成が `201`。`SHOP_ORDERS` / `SHOP_ORDER_ITEMS` への `INSERT` とカート削除が残る。 <br>`one-qty-cancelled-ok`(status=200/201; custom=free_shipping_applied,order_created,payment_method_filtered,purchase_limit_checked,shipping_method_selected; 更新=DELETE SHOP_CART_ITEMS,DELETE SHOP_RESERVATION_CART_ITEMS,INSERT SHOP_CART_ITEMS,INSERT SHOP_ORDERS,INSERT SHOP_ORDER_ITEMS) | `purchase_limit_checked` があると、単なる注文成功ではなく購入制限チェック通過だと読める。 |
| 購入制限: 注文済み商品は再注文不可 | `one-qty-after-order-rejected / 647f2eb2` | `POST /api/orders` が `422`。過去注文参照の後、注文 `INSERT` が発生しない。 <br>`one-qty-after-order-rejected`(status=201/422; custom=purchase_limit_rejected; 更新=INSERT SHOP_CART_ITEMS) | `purchase_limit_rejected` が必要。SQLだけでは「購入制限」なのか他の注文拒否なのか名前付けしにくい。 |
| 購入制限: キャンセル済み注文は制限対象外 | `one-qty-after-cancel-ok / ba987a40` | キャンセルAPIで `SHOP_ORDERS` が `UPDATE` され、その後の注文は `201` と注文 `INSERT`。 <br>`one-qty-after-cancel-ok`(status=200/201; custom=free_shipping_applied,order_cancelled,order_created,payment_method_filtered,purchase_limit_checked,shipping_method_selected; 更新=DELETE SHOP_CART_ITEMS,DELETE SHOP_RESERVATION_CART_ITEMS,INSERT SHOP_CART_ITEMS,INSERT SHOP_ORDERS,INSERT SHOP_ORDER_ITEMS,UPDATE SHOP_ORDERS) | `order_cancelled` と `purchase_limit_checked` があると、キャンセル後許可の根拠がつながる。 |
| 購入制限: 一回限定商品は再購入不可 | `one-time-rejected / 551e795b` | `POST /api/orders` が `422`。過去注文参照の後、注文 `INSERT` が発生しない。 <br>`one-time-rejected`(status=201/422; custom=purchase_limit_rejected; 更新=INSERT SHOP_CART_ITEMS) | `purchase_limit_rejected` が必要。同じ422でも業務理由の区別に使う。 |
| 購入制限: 同一注文内の数量超過は不可 | `one-qty-quantity-rejected / ae662cdd` | `POST /api/orders` が `422`。カート投入は成功し、注文作成は発生しない。 <br>`one-qty-quantity-rejected`(status=201/422; custom=purchase_limit_rejected; 更新=INSERT SHOP_CART_ITEMS) | `purchase_limit_rejected` が必要。数量超過と過去購入制限はSQLフローが似る。 |
| 商品: マスタに存在しない商品コードはカート投入不可 | `product-not-found / fabc0fac` | `SHOP_PRODUCTS` の参照後、`POST /api/cart/items` が `404`。カート `INSERT` は発生しない。 <br>`product-not-found`(status=404) | customイベントなしでも比較的読める。業務名を揃えるなら `product_rejected` のようなイベントがあるとよい。 |
| 商品: 販売期間内ならカート投入可 | `sale-period-ok / d9931412` | `SHOP_PRODUCTS` の参照後、`SHOP_CART_ITEMS` に `INSERT`。 <br>`sale-period-ok`(status=201; 更新=INSERT SHOP_CART_ITEMS) | customイベントなしでも成功/失敗差は読めるが、販売期間チェック通過だと明示するにはイベントがあるとよい。 |
| 商品: 日付を跨いで販売期間終了後ならカート投入不可 | `sale-period-ended / d9ce900b` | `SHOP_PRODUCTS` の参照後、`POST /api/cart/items` が `422`。カート `INSERT` は発生しない。 <br>`sale-period-ended`(status=422) | customイベントが必要。SQLだけでは販売終了、在庫切れ、別の422理由を区別しにくい。 |
| ゆうメール: 単品ならP付き商品コードへ置換 | `yumail-single / ebc5f8f1`<br>`cod-yumail-rejected / 1308dbe5` | `SHOP_CART_ITEMS` の `INSERT` 後に同テーブル `UPDATE`。 <br>`yumail-single`(status=200/201; custom=yumail_product_code_swapped; 更新=INSERT SHOP_CART_ITEMS,UPDATE SHOP_CART_ITEMS)<br>`cod-yumail-rejected`(status=201/422; custom=payment_method_filtered,shipping_method_selected,yumail_product_code_swapped; 更新=INSERT SHOP_CART_ITEMS,UPDATE SHOP_CART_ITEMS) | `yumail_product_code_swapped` があると、更新理由が配送用コード置換だと分かる。 |
| ゆうメール: 複数商品になるとP付きコードを戻す | `yumail-revert / fb8371cf` | `SHOP_CART_ITEMS` の追加後、既存行に `UPDATE`。 <br>`yumail-revert`(status=200/201; custom=yumail_product_code_reverted; 更新=INSERT SHOP_CART_ITEMS,UPDATE SHOP_CART_ITEMS) | `yumail_product_code_reverted` が必要。SQLだけでは何の正規化か断定しにくい。 |
| 支払方法: ゆうメールは代引き不可 | `cod-yumail-rejected / 1308dbe5` | `POST /api/checkout/quote` が `422`。注文更新は発生しない。 <br>`cod-yumail-rejected`(status=201/422; custom=payment_method_filtered,shipping_method_selected,yumail_product_code_swapped; 更新=INSERT SHOP_CART_ITEMS,UPDATE SHOP_CART_ITEMS) | `shipping_method_selected` / `payment_method_filtered` が必要。 |
| 支払方法: 別届け先は代引き不可 | `cod-other-address-rejected / 9a3fd9fb` | `POST /api/checkout/quote` が `422`。住所参照後、注文更新なし。 <br>`cod-other-address-rejected`(status=201/422; custom=payment_method_filtered,shipping_method_selected; 更新=INSERT SHOP_CART_ITEMS) | `payment_method_filtered` が必要。ゆうメール不可との区別にflowかイベントが要る。 |
| 支払方法: 与信不足なら後払い不可 | `deferred-credit-limit-rejected / 80a12831` | `POST /api/checkout/quote` が `422`。顧客・住所・カート参照後、注文更新なし。 <br>`deferred-credit-limit-rejected`(status=201/422; custom=payment_method_filtered,shipping_method_selected; 更新=INSERT SHOP_CART_ITEMS) | `payment_method_filtered` が必要。閾値や候補除外理由はSQLだけでは読めない。 |
| 支払方法: 前払いなら通る | `prepaid-only-ok / 4a298264` | `POST /api/checkout/quote` が `200`。 <br>`prepaid-only-ok`(status=200; custom=free_shipping_applied,payment_method_filtered,shipping_method_selected) | `payment_method_filtered` があると、利用可能支払方法を絞った後の成功と分かる。 |
| 配送日: ゆうメールは日付指定不可 | `yumail-date-rejected / 71a0512f` | `POST /api/checkout/quote` が `422`。 <br>`yumail-date-rejected`(status=422; custom=delivery_date_rejected,payment_method_filtered,shipping_method_selected) | `delivery_date_rejected` が必要。 |
| 配送日: 遅延都道府県は日付指定不可 | `delayed-area-date-rejected / d2e0821a` | `SHOP_DELAY_PREFECTURES` 参照後、`422`。 <br>`delayed-area-date-rejected`(status=201/422; custom=delivery_date_rejected,payment_method_filtered,shipping_method_selected; 更新=INSERT SHOP_CART_ITEMS) | `delivery_date_rejected` があると拒否理由を追える。 |
| 配送日: 離島郵便番号は日付指定不可 | `remote-island-date-rejected / 6ff8dab6` | `SHOP_REMOTE_ISLAND_POSTALS` 参照後、`422`。 <br>`remote-island-date-rejected`(status=201/422; custom=delivery_date_rejected,payment_method_filtered,shipping_method_selected; 更新=INSERT SHOP_CART_ITEMS) | `delivery_date_rejected` があると拒否理由を追える。 |
| 配送日: 営業日計算の最短日より早い日は不可 | `working-day-window-rejected / c6bde0ff` | `SHOP_WORKING_DAYS` 参照後、`422`。 <br>`working-day-window-rejected`(status=201/422; custom=delivery_date_rejected,payment_method_filtered,shipping_method_selected; 更新=INSERT SHOP_CART_ITEMS) | `delivery_date_rejected` が必要。 |
| 配送日: 最大日数を超える日は不可 | `delivery-date-too-late-rejected / 2d43abae` | `SHOP_WORKING_DAYS` 参照後、`422`。 <br>`delivery-date-too-late-rejected`(status=201/422; custom=delivery_date_rejected,payment_method_filtered,shipping_method_selected; 更新=INSERT SHOP_CART_ITEMS) | `delivery_date_rejected` が必要。早すぎる日付との区別はflow名かイベント値が要る。 |
| 配送時間: ゆうメールは時間指定不可 | `delivery-time-yumail-rejected / 62ce81e6` | `POST /api/checkout/quote` が `422`。 <br>`delivery-time-yumail-rejected`(status=422; custom=delivery_time_rejected,payment_method_filtered,shipping_method_selected) | `delivery_time_rejected` が必要。 |
| 送料: 初回顧客は低い閾値で送料無料 | `first-free-shipping / 5f445295` | `POST /api/checkout/quote` が `200`。顧客参照とカート金額参照が残る。 <br>`first-free-shipping`(status=200/201; custom=free_shipping_applied,payment_method_filtered,shipping_method_selected; 更新=INSERT SHOP_CART_ITEMS) | `free_shipping_applied` があると送料判定が発生したことは分かる。実際の閾値理由は分からない。 |
| 送料: リピーターは同額でも送料あり | `repeat-shipping-fee / a1f5dc18` | `POST /api/checkout/quote` が `200`。 <br>`repeat-shipping-fee`(status=200/201; custom=free_shipping_applied,payment_method_filtered,shipping_method_selected; 更新=INSERT SHOP_CART_ITEMS) | `free_shipping_applied` は必要。ただしイベント値を見ないと送料0/300の差はflow名頼み。 |
| 送料: リピーターも高額なら送料無料 | `repeat-free-shipping / 40662b6e` | `POST /api/checkout/quote` が `200`。 <br>`repeat-free-shipping`(status=200/201; custom=free_shipping_applied,payment_method_filtered,shipping_method_selected; 更新=INSERT SHOP_CART_ITEMS) | `free_shipping_applied` は必要。ただし閾値の意味は外部分析対象。 |
| プレゼント: 対象商品で同梱品を自動追加 | `gift-attached / 7716c4f4` | `SHOP_CART_ITEMS` に商品追加とギフト追加の2回 `INSERT`。 <br>`gift-attached`(status=200/201; custom=gift_attached; 更新=INSERT SHOP_CART_ITEMS) | `gift_attached` があると2回目のINSERTが特典同梱だと分かる。 |
| ポイント交換: ゲストは不可 | `point-guest-rejected / 899be8d0` | `POST /api/cart/items` が `422`。カート `INSERT` は発生しない。 <br>`point-guest-rejected`(status=422; custom=point_exchange_rejected) | `point_exchange_rejected` が必要。 |
| ポイント交換: 残高十分ならカート投入と注文が通る | `point-ok / 4bb1b390` | カート `INSERT` と注文 `INSERT`。注文時にもポイント確認が走る。 <br>`point-ok`(status=201; custom=free_shipping_applied,order_created,payment_method_filtered,point_exchange_checked,shipping_method_selected; 更新=DELETE SHOP_CART_ITEMS,DELETE SHOP_RESERVATION_CART_ITEMS,INSERT SHOP_CART_ITEMS,INSERT SHOP_ORDERS,INSERT SHOP_ORDER_ITEMS) | `point_exchange_checked` があるとポイント分岐の通過だと分かる。 |
| ポイント交換: 残高不足は不可 | `point-insufficient / ab519462` | `POST /api/cart/items` が `422`。顧客参照後、カート `INSERT` なし。 <br>`point-insufficient`(status=422; custom=point_exchange_rejected) | `point_exchange_rejected` が必要。 |
| バラエティ: 3点セットなら注文可 | `variety-ok / dc63c524` | 3回のカート `INSERT` 後、注文 `INSERT` が3明細ぶん発生。 <br>`variety-ok`(status=201; custom=free_shipping_applied,order_created,payment_method_filtered,shipping_method_selected,variety_bundle_validated; 更新=DELETE SHOP_CART_ITEMS,DELETE SHOP_RESERVATION_CART_ITEMS,INSERT SHOP_CART_ITEMS,INSERT SHOP_ORDERS,INSERT SHOP_ORDER_ITEMS) | `variety_bundle_validated` があるとセット価格/成立条件の分岐だと分かる。 |
| バラエティ: 通常商品混在は不可 | `variety-mixed-rejected / d6896ce6` | `POST /api/orders` が `422`。注文 `INSERT` なし。 <br>`variety-mixed-rejected`(status=201/422; custom=variety_bundle_rejected; 更新=INSERT SHOP_CART_ITEMS) | `variety_bundle_rejected` が必要。 |
| バラエティ: 点数不足は不可 | `variety-quantity-rejected / 9c4460d0` | `POST /api/orders` が `422`。注文 `INSERT` なし。 <br>`variety-quantity-rejected`(status=201/422; custom=variety_bundle_rejected; 更新=INSERT SHOP_CART_ITEMS) | `variety_bundle_rejected` が必要。混在NGとの区別はflow名かイベント値が要る。 |
| 予約商品: 予約カートへ入る | `reserved-cart / 75a88ab0` | `SHOP_RESERVATION_CART_ITEMS` に `INSERT`。通常カートとは別テーブル。 <br>`reserved-cart`(status=200/201; custom=reservation_cart_used; 更新=INSERT SHOP_RESERVATION_CART_ITEMS) | `reservation_cart_used` があると予約扱いの業務意図が明確。 |
| セット商品: 明細表示で構成品を商品ごとに読む | `set-n-plus-one / 63b356a2` | `GET /api/cart` で `SHOP_PRODUCT_COMPONENTS` へのSELECTが複数回出る。 <br>`set-n-plus-one`(status=200/201; custom=set_components_loaded; 更新=INSERT SHOP_CART_ITEMS) | `set_components_loaded` があるとN+1風の反復SELECTの理由が分かる。 |
| 注文: メールなし顧客はチェックアウト不可 | `missing-email-rejected / f75293c1` | `POST /api/orders` が `422`。顧客参照後、注文 `INSERT` なし。 <br>`missing-email-rejected`(status=201/422; custom=checkout_blocked; 更新=INSERT SHOP_CART_ITEMS) | `checkout_blocked` が必要。 |
| 決済: クレジット注文後にコールバックで決済状態更新 | `credit-order / 8d288940`<br>`credit-callback / 1c6fd68c` | 注文 `INSERT` 後、callbackで `SHOP_ORDERS` が `UPDATE`。 <br>`credit-order`(status=201; custom=free_shipping_applied,order_created,payment_method_filtered,shipping_method_selected; 更新=DELETE SHOP_CART_ITEMS,DELETE SHOP_RESERVATION_CART_ITEMS,INSERT SHOP_CART_ITEMS,INSERT SHOP_ORDERS,INSERT SHOP_ORDER_ITEMS)<br>`credit-callback`(status=200; custom=credit_callback_recorded; 更新=UPDATE SHOP_ORDERS) | `order_created` / `credit_callback_recorded` があると外部決済連携の副作用として読める。 |

## 分析できること

- APIエントリポイント、ステータス分布、レスポンスshape。
- SQLの実行順、参照/更新テーブル、注文・キャンセル・決済コールバックの副作用。
- セット構成商品の内訳取得で発生するN+1のような反復SELECT。
- `purchase_limit_rejected` や `payment_method_filtered` など、customイベントで明示した業務分岐の発生。

## 部分的に分析できること

- 配送方法、支払い方法、送料無料、購入制限、ポイント不足、バラエティ商品の条件分岐。
- これらはSQLフローだけでも痕跡は出るが、業務名や判定理由はcustomイベントのラベルがあって初めて読みやすくなる。

## 分析できないこと

- なぜ閾値が3000円、7000円、10000円なのかという業務上の理由。
- 観測していない分岐、低頻度ケース、月次処理、特定ユーザーだけの例外。
- 観測されなかった挙動が存在しないという断定。
- 法務・運用・顧客対応上の意図。

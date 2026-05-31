<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Shop extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Shop_model', 'shop');
    }

    public function health()
    {
        try {
            $db = $this->shop->health();
            return $this->sendJson(200, array('ok' => true, 'db' => $db ? true : false));
        } catch (Throwable $e) {
            $this->tekagamiCollector->addError('app_error', 'application error', 'health');
            return $this->sendJson(503, array('ok' => false, 'error' => 'db_unavailable'));
        }
    }

    public function reset()
    {
        try {
            $this->shop->resetFixtures();
            $this->shop->commit();
            $this->customEvent('fixtures_reset', array('scope' => 'e2e'));
            return $this->sendJson(200, array('ok' => true));
        } catch (Throwable $e) {
            $this->shop->rollback();
            $this->tekagamiCollector->addError('app_error', 'application error', 'reset');
            return $this->sendJson(500, array('error' => 'internal_error'));
        }
    }

    public function add_cart_item()
    {
        try {
            $cartId = (string) $this->body('cart_id', 'guest-cart');
            $customerId = $this->nullableInt($this->body('customer_id'));
            $productCode = (string) $this->body('product_code');
            $quantity = max(1, (int) $this->body('quantity', 1));
            $baseDate = (string) $this->body('base_date', date('Y-m-d'));

            $product = $this->shop->product($productCode);
            if (!$product) {
                return $this->sendJson(404, array('error' => 'product_not_found'));
            }
            if (!$this->productOnSale($product, $baseDate)) {
                return $this->sendJson(422, array('error' => 'product_not_on_sale'));
            }

            if ($product['IS_POINT_EXCHANGE'] === 'Y') {
                if ($customerId === null) {
                    $this->customEvent('point_exchange_rejected', array('reason' => 'guest'));
                    return $this->sendJson(422, array('error' => 'point_exchange_requires_login'));
                }
                $customer = $this->shop->customer($customerId);
                $required = (int) $product['POINT_COST'] * $quantity;
                if (!$customer || (int) $customer['POINTS'] < $required) {
                    $this->customEvent('point_exchange_rejected', array('reason' => 'insufficient_points'));
                    return $this->sendJson(422, array('error' => 'insufficient_points'));
                }
                $this->customEvent('point_exchange_checked', array('result' => 'ok'));
            }

            list($ok, $reason) = $this->shop->addCartItem($cartId, $customerId, $productCode, $quantity);
            if (!$ok) {
                return $this->sendJson(422, array('error' => $reason));
            }

            if ($product['IS_RESERVED'] === 'Y') {
                $this->customEvent('reservation_cart_used', array('product_code' => $productCode));
            }

            $this->normalizeShippingProductCodes($cartId);
            if ($this->shop->addGiftIfNeeded($cartId)) {
                $this->customEvent('gift_attached', array('cart_id' => $cartId));
            }
            $this->shop->commit();

            return $this->sendJson(201, array('cart_id' => $cartId, 'added' => $productCode));
        } catch (Throwable $e) {
            $this->shop->rollback();
            $this->tekagamiCollector->addError('app_error', 'application error', 'add_cart_item');
            return $this->sendJson(500, array('error' => 'internal_error'));
        }
    }

    public function cart()
    {
        try {
            $cartId = (string) $this->input->get('cart_id');
            $items = $this->shop->cartItems($cartId);
            $payloadItems = array();
            foreach ($items as $item) {
                $entry = $this->cartItemPayload($item);
                if ($item['IS_SET'] === 'Y') {
                    // Intentional N+1: components are read per set product to make the report show repeated SELECTs.
                    $entry['components'] = $this->shop->componentsForSet($item['PRODUCT_CODE']);
                    $this->customEvent('set_components_loaded', array('product_code' => $item['PRODUCT_CODE']));
                }
                $payloadItems[] = $entry;
            }

            return $this->sendJson(200, array('cart_id' => $cartId, 'items' => $payloadItems));
        } catch (Throwable $e) {
            $this->tekagamiCollector->addError('app_error', 'application error', 'cart');
            return $this->sendJson(500, array('error' => 'internal_error'));
        }
    }

    public function checkout_quote()
    {
        try {
            $quote = $this->buildQuote();
            if (!$quote['ok']) {
                return $this->sendJson(422, $quote);
            }
            return $this->sendJson(200, $quote);
        } catch (Throwable $e) {
            $this->tekagamiCollector->addError('app_error', 'application error', 'checkout_quote');
            return $this->sendJson(500, array('error' => 'internal_error'));
        }
    }

    public function create_order()
    {
        try {
            $cartId = (string) $this->body('cart_id');
            $customerId = $this->nullableInt($this->body('customer_id'));
            $addressId = $this->nullableInt($this->body('address_id'));

            if ($customerId !== null) {
                $customer = $this->shop->customer($customerId);
                if (!$customer || $customer['EMAIL'] === null) {
                    $this->customEvent('checkout_blocked', array('reason' => 'missing_email'));
                    return $this->sendJson(422, array('ok' => false, 'error' => 'customer_email_required'));
                }
            }

            $items = $this->shop->cartItems($cartId, $customerId);
            $limit = $this->checkPurchaseLimits($customerId, $items);
            if ($limit !== true) {
                return $this->sendJson(422, array('ok' => false, 'error' => $limit));
            }

            $points = $this->checkPointBalance($customerId, $items);
            if ($points !== true) {
                return $this->sendJson(422, array('ok' => false, 'error' => $points));
            }

            $variety = $this->validateVarietyBundle($items);
            if ($variety !== true) {
                return $this->sendJson(422, array('ok' => false, 'error' => $variety));
            }

            $quote = $this->buildQuote();
            if (!$quote['ok']) {
                return $this->sendJson(422, $quote);
            }

            $orderId = $this->shop->createOrder($customerId, $addressId, $quote, $items);
            $this->shop->clearCart($cartId);
            $this->shop->commit();

            $this->customEvent('order_created', array('order_id' => $orderId, 'payment_status' => $quote['payment_method']));
            return $this->sendJson(201, array('ok' => true, 'order_id' => $orderId));
        } catch (Throwable $e) {
            $this->shop->rollback();
            $this->tekagamiCollector->addError('app_error', 'application error', 'create_order');
            return $this->sendJson(500, array('error' => 'internal_error'));
        }
    }

    public function cancel_order($orderId)
    {
        try {
            $this->shop->cancelOrder((int) $orderId);
            $this->shop->commit();
            $this->customEvent('order_cancelled', array('order_id' => (int) $orderId));
            return $this->sendJson(200, array('ok' => true, 'order_id' => (int) $orderId, 'status' => 'cancelled'));
        } catch (Throwable $e) {
            $this->shop->rollback();
            $this->tekagamiCollector->addError('app_error', 'application error', 'cancel_order');
            return $this->sendJson(500, array('error' => 'internal_error'));
        }
    }

    public function credit_callback()
    {
        try {
            $orderId = (int) $this->body('order_id');
            $success = (string) $this->body('status') === 'success';
            $this->shop->updateCreditPayment($orderId, $success);
            $this->shop->commit();
            $this->customEvent('credit_callback_recorded', array('order_id' => $orderId, 'success' => $success));
            return $this->sendJson(200, array('ok' => true, 'payment_status' => $success ? 'credit_success' : 'credit_failed'));
        } catch (Throwable $e) {
            $this->shop->rollback();
            $this->tekagamiCollector->addError('app_error', 'application error', 'credit_callback');
            return $this->sendJson(500, array('error' => 'internal_error'));
        }
    }

    private function buildQuote()
    {
        $cartId = (string) $this->body('cart_id');
        $customerId = $this->nullableInt($this->body('customer_id'));
        $addressId = $this->nullableInt($this->body('address_id'));
        $requestedPayment = (string) $this->body('payment_method', 'prepaid');
        $deliveryDate = $this->body('delivery_date');
        $deliveryTime = $this->body('delivery_time');
        $baseDate = (string) $this->body('base_date', '2026-06-01');

        $items = $this->shop->cartItems($cartId, $customerId);
        $address = $addressId ? $this->shop->address($addressId) : null;
        $customer = $customerId ? $this->shop->customer($customerId) : null;
        $shipping = $this->shippingMethod($items);
        $subtotal = $this->subtotal($items);

        $this->customEvent('shipping_method_selected', array('method' => $shipping));

        $availablePayments = $this->availablePayments($shipping, $subtotal, $customer, $address);
        $this->customEvent('payment_method_filtered', array('available' => $availablePayments));
        if (!in_array($requestedPayment, $availablePayments, true)) {
            return array('ok' => false, 'error' => 'payment_method_unavailable', 'available_payments' => $availablePayments);
        }

        $dateCheck = $this->validateDeliveryDate($shipping, $address, $deliveryDate, $baseDate);
        if ($dateCheck !== true) {
            return array('ok' => false, 'error' => $dateCheck);
        }

        if ($deliveryTime !== null && $shipping === 'yumail') {
            $this->customEvent('delivery_time_rejected', array('reason' => 'yumail'));
            return array('ok' => false, 'error' => 'delivery_time_unavailable_for_yumail');
        }

        $shippingFee = $this->shippingFee($subtotal, $customer);
        $this->customEvent('free_shipping_applied', array('subtotal' => $subtotal, 'shipping_fee' => $shippingFee));

        return array(
            'ok' => true,
            'cart_id' => $cartId,
            'shipping_method' => $shipping,
            'available_payments' => $availablePayments,
            'payment_method' => $requestedPayment,
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'delivery_date' => $deliveryDate,
            'delivery_time' => $deliveryTime,
        );
    }

    private function normalizeShippingProductCodes($cartId)
    {
        $items = $this->shop->cartItems($cartId);
        $nonGift = array_values(array_filter($items, function ($item) {
            return $item['IS_GIFT'] !== 'Y';
        }));

        if (count($nonGift) === 1) {
            $item = $nonGift[0];
            $product = $this->shop->product($item['PRODUCT_CODE']);
            if ($product && $product['YUMAIL_SINGLE_ELIGIBLE'] === 'Y' && strpos($item['PRODUCT_CODE'], 'P') !== 0) {
                $prefixed = 'P' . $item['PRODUCT_CODE'];
                if ($this->shop->product($prefixed)) {
                    $this->shop->replaceProductCode($cartId, $item['PRODUCT_CODE'], $prefixed);
                    $this->customEvent('yumail_product_code_swapped', array('from' => $item['PRODUCT_CODE'], 'to' => $prefixed));
                }
            }
            return;
        }

        foreach ($nonGift as $item) {
            if (strpos($item['PRODUCT_CODE'], 'P') === 0) {
                $plain = substr($item['PRODUCT_CODE'], 1);
                if ($this->shop->product($plain)) {
                    $this->shop->replaceProductCode($cartId, $item['PRODUCT_CODE'], $plain);
                    $this->customEvent('yumail_product_code_reverted', array('from' => $item['PRODUCT_CODE'], 'to' => $plain));
                }
            }
        }
    }

    private function productOnSale(array $product, $baseDate)
    {
        if ($product['SALE_START_DATE'] !== null && $baseDate < $product['SALE_START_DATE']) {
            return false;
        }
        if ($product['SALE_END_DATE'] !== null && $baseDate > $product['SALE_END_DATE']) {
            return false;
        }
        return true;
    }

    private function shippingMethod(array $items)
    {
        $nonGift = array_values(array_filter($items, function ($item) {
            return $item['IS_GIFT'] !== 'Y';
        }));
        return count($nonGift) === 1 && strpos($nonGift[0]['PRODUCT_CODE'], 'P') === 0 ? 'yumail' : 'takuhai';
    }

    private function availablePayments($shipping, $subtotal, $customer, $address)
    {
        $payments = array('prepaid', 'credit_card');
        if ($shipping !== 'yumail' && $address && $address['IS_SELF'] === 'Y') {
            $payments[] = 'cod';
        }
        if ($customer && (int) $customer['CREDIT_REMAINING'] >= $subtotal) {
            $payments[] = 'deferred';
        }
        sort($payments);
        return $payments;
    }

    private function validateDeliveryDate($shipping, $address, $deliveryDate, $baseDate)
    {
        if ($deliveryDate === null) {
            return true;
        }
        if ($shipping === 'yumail') {
            $this->customEvent('delivery_date_rejected', array('reason' => 'yumail'));
            return 'delivery_date_unavailable_for_yumail';
        }
        if ($address && $this->shop->delayedPrefecture($address['PREFECTURE'])) {
            $this->customEvent('delivery_date_rejected', array('reason' => 'delayed_prefecture'));
            return 'delivery_date_unavailable_for_delayed_area';
        }
        if ($address && $this->shop->remoteIslandPostalCode($address['POSTAL_CODE'])) {
            $this->customEvent('delivery_date_rejected', array('reason' => 'remote_island'));
            return 'delivery_date_unavailable_for_remote_island';
        }

        $window = $this->deliveryWindow($baseDate);
        if ($deliveryDate < $window['min'] || $deliveryDate > $window['max']) {
            $this->customEvent('delivery_date_rejected', array('reason' => 'outside_window', 'min' => $window['min'], 'max' => $window['max']));
            return 'delivery_date_outside_window';
        }
        return true;
    }

    private function deliveryWindow($baseDate)
    {
        $min = date('Y-m-d', strtotime($baseDate . ' +3 day'));
        $max = date('Y-m-d', strtotime($baseDate . ' +14 day'));
        while (true) {
            $nonWorking = 0;
            $days = $this->shop->workingDays(date('Y-m-d', strtotime($baseDate . ' +1 day')), $min);
            foreach ($days as $day) {
                if ($day['IS_WORKING'] !== 'Y') {
                    $nonWorking++;
                }
            }
            $nextMin = date('Y-m-d', strtotime($baseDate . ' +' . (3 + $nonWorking) . ' day'));
            if ($nextMin === $min) {
                break;
            }
            $min = $nextMin;
        }
        return array('min' => $min, 'max' => $max);
    }

    private function shippingFee($subtotal, $customer)
    {
        $threshold = $customer && $customer['IS_FIRST_TIME'] === 'Y' ? 3000 : 7000;
        return $subtotal >= $threshold ? 0 : 300;
    }

    private function subtotal(array $items)
    {
        $sum = 0;
        foreach ($items as $item) {
            $sum += (int) $item['UNIT_PRICE'] * (int) $item['QUANTITY'];
        }

        $varietyQty = 0;
        foreach ($items as $item) {
            if ($item['IS_VARIETY'] === 'Y') {
                $varietyQty += (int) $item['QUANTITY'];
            }
        }
        if ($varietyQty === 3) {
            return 3980;
        }
        return $sum;
    }

    private function checkPurchaseLimits($customerId, array $items)
    {
        if ($customerId === null) {
            return true;
        }
        foreach ($items as $item) {
            $product = $this->shop->product($item['PRODUCT_CODE']);
            if (!$product || $item['IS_GIFT'] === 'Y') {
                continue;
            }
            if ($product['ONE_PER_CUSTOMER_QTY'] === 'Y' && (int) $item['QUANTITY'] > 1) {
                $this->customEvent('purchase_limit_rejected', array('type' => 'one_quantity'));
                return 'one_quantity_limit_exceeded';
            }
            if ($product['ONE_PER_CUSTOMER_QTY'] === 'Y' || $product['ONE_PER_CUSTOMER_ONCE'] === 'Y') {
                $prior = $this->shop->priorActiveOrdersForProduct($customerId, $item['PRODUCT_CODE']);
                if (count($prior) > 0) {
                    $this->customEvent('purchase_limit_rejected', array('type' => $product['ONE_PER_CUSTOMER_ONCE'] === 'Y' ? 'one_time' : 'one_quantity'));
                    return $product['ONE_PER_CUSTOMER_ONCE'] === 'Y' ? 'one_time_limit_exceeded' : 'one_quantity_limit_exceeded';
                }
                $this->customEvent('purchase_limit_checked', array('result' => 'ok'));
            }
        }
        return true;
    }

    private function checkPointBalance($customerId, array $items)
    {
        $required = 0;
        foreach ($items as $item) {
            if ($item['IS_POINT_EXCHANGE'] === 'Y') {
                $required += (int) $item['POINT_COST'] * (int) $item['QUANTITY'];
            }
        }
        if ($required === 0) {
            return true;
        }
        if ($customerId === null) {
            $this->customEvent('point_exchange_rejected', array('reason' => 'guest_order'));
            return 'point_exchange_requires_login';
        }
        $customer = $this->shop->customer($customerId);
        if (!$customer || (int) $customer['POINTS'] < $required) {
            $this->customEvent('point_exchange_rejected', array('reason' => 'insufficient_points_order'));
            return 'insufficient_points';
        }
        $this->customEvent('point_exchange_checked', array('result' => 'ok_at_order'));
        return true;
    }

    private function validateVarietyBundle(array $items)
    {
        $varietyQty = 0;
        $nonVarietyQty = 0;
        foreach ($items as $item) {
            if ($item['IS_GIFT'] === 'Y') {
                continue;
            }
            if ($item['IS_VARIETY'] === 'Y') {
                $varietyQty += (int) $item['QUANTITY'];
            } else {
                $nonVarietyQty += (int) $item['QUANTITY'];
            }
        }
        if ($varietyQty === 0) {
            return true;
        }
        if ($varietyQty !== 3 || $nonVarietyQty > 0) {
            $this->customEvent('variety_bundle_rejected', array('variety_qty' => $varietyQty, 'non_variety_qty' => $nonVarietyQty));
            return 'invalid_variety_bundle';
        }
        $this->customEvent('variety_bundle_validated', array('price' => 3980));
        return true;
    }

    private function cartItemPayload(array $item)
    {
        return array(
            'product_code' => $item['PRODUCT_CODE'],
            'name' => $item['NAME'],
            'quantity' => (int) $item['QUANTITY'],
            'unit_price' => (int) $item['UNIT_PRICE'],
            'is_gift' => $item['IS_GIFT'] === 'Y',
        );
    }

    private function nullableInt($value)
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}

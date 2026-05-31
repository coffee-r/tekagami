<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Shop_model extends CI_Model
{
    /** @var resource|null */
    private $conn = null;

    public function health()
    {
        return $this->fetchOne('SELECT 1 AS ok FROM dual', array());
    }

    public function resetFixtures()
    {
        $this->execute('DELETE FROM shop_order_items', array());
        $this->execute('DELETE FROM shop_orders', array());
        $this->execute('DELETE FROM shop_reservation_cart_items', array());
        $this->execute('DELETE FROM shop_cart_items', array());

        $this->execute(
            "INSERT INTO shop_orders (id, customer_id, address_id, email, shipping_method, payment_method, subtotal, shipping_fee, status, payment_status, delivery_date, delivery_time, created_at)
             VALUES (9001, 1, 101, 'first@example.test', 'takuhai', 'prepaid', 1600, 300, 'ordered', 'payment_planned', NULL, NULL, SYSTIMESTAMP)",
            array()
        );
        $this->execute(
            "INSERT INTO shop_order_items (order_id, product_code, name, quantity, unit_price, is_gift)
             VALUES (9001, 'LIMIT_ONCE', '一回限定商品', 1, 1600, 'N')",
            array()
        );
        $this->execute(
            "INSERT INTO shop_orders (id, customer_id, address_id, email, shipping_method, payment_method, subtotal, shipping_fee, status, payment_status, delivery_date, delivery_time, created_at)
             VALUES (9002, 1, 101, 'first@example.test', 'takuhai', 'prepaid', 1200, 300, 'cancelled', 'payment_planned', NULL, NULL, SYSTIMESTAMP)",
            array()
        );
        $this->execute(
            "INSERT INTO shop_order_items (order_id, product_code, name, quantity, unit_price, is_gift)
             VALUES (9002, 'LIMIT_QTY', '一個限定商品', 1, 1200, 'N')",
            array()
        );
    }

    public function customer($customerId)
    {
        return $this->fetchOne('SELECT * FROM shop_customers WHERE id = :id', array('id' => $customerId));
    }

    public function address($addressId)
    {
        return $this->fetchOne('SELECT * FROM shop_addresses WHERE id = :id', array('id' => $addressId));
    }

    public function product($code)
    {
        return $this->fetchOne('SELECT * FROM shop_products WHERE code = :code', array('code' => $code));
    }

    public function cartItems($cartId, $customerId = null)
    {
        $rows = $this->fetchAll(
            'SELECT ci.cart_id, ci.customer_id, ci.product_code, ci.quantity, ci.unit_price, ci.is_gift, p.name, p.price, p.is_set, p.is_reserved, p.is_point_exchange, p.point_cost, p.is_variety, p.variety_group
             FROM shop_cart_items ci JOIN shop_products p ON p.code = ci.product_code
             WHERE ci.cart_id = :cart_id
             ORDER BY ci.id',
            array('cart_id' => $cartId)
        );

        $reserved = $this->fetchAll(
            'SELECT ci.cart_id, ci.customer_id, ci.product_code, ci.quantity, ci.unit_price, ci.is_gift, p.name, p.price, p.is_set, p.is_reserved, p.is_point_exchange, p.point_cost, p.is_variety, p.variety_group
             FROM shop_reservation_cart_items ci JOIN shop_products p ON p.code = ci.product_code
             WHERE ci.cart_id = :cart_id
             ORDER BY ci.id',
            array('cart_id' => $cartId)
        );

        foreach ($reserved as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function addCartItem($cartId, $customerId, $productCode, $quantity)
    {
        $product = $this->product($productCode);
        if (!$product) {
            return array(false, 'product_not_found');
        }

        $table = $product['IS_RESERVED'] === 'Y' ? 'shop_reservation_cart_items' : 'shop_cart_items';
        $this->execute(
            'INSERT INTO ' . $table . ' (cart_id, customer_id, product_code, quantity, unit_price, is_gift, created_at)
             VALUES (:cart_id, :customer_id, :product_code, :quantity, :unit_price, :is_gift, SYSTIMESTAMP)',
            array(
                'cart_id' => $cartId,
                'customer_id' => $customerId,
                'product_code' => $productCode,
                'quantity' => $quantity,
                'unit_price' => (int) $product['PRICE'],
                'is_gift' => 'N',
            )
        );

        return array(true, null);
    }

    public function clearCart($cartId)
    {
        $this->execute('DELETE FROM shop_cart_items WHERE cart_id = :cart_id', array('cart_id' => $cartId));
        $this->execute('DELETE FROM shop_reservation_cart_items WHERE cart_id = :cart_id', array('cart_id' => $cartId));
    }

    public function replaceProductCode($cartId, $fromCode, $toCode)
    {
        $product = $this->product($toCode);
        if (!$product) {
            return;
        }
        $this->execute(
            'UPDATE shop_cart_items SET product_code = :to_code, unit_price = :price WHERE cart_id = :cart_id AND product_code = :from_code',
            array('to_code' => $toCode, 'price' => (int) $product['PRICE'], 'cart_id' => $cartId, 'from_code' => $fromCode)
        );
    }

    public function addGiftIfNeeded($cartId)
    {
        $trigger = $this->fetchOne(
            "SELECT 1 AS found FROM shop_cart_items ci JOIN shop_products p ON p.code = ci.product_code WHERE ci.cart_id = :cart_id AND p.gift_trigger = 'Y' AND ROWNUM = 1",
            array('cart_id' => $cartId)
        );
        if (!$trigger) {
            return false;
        }

        $existing = $this->fetchOne(
            "SELECT 1 AS found FROM shop_cart_items WHERE cart_id = :cart_id AND product_code = 'GIFT_MINI' AND ROWNUM = 1",
            array('cart_id' => $cartId)
        );
        if ($existing) {
            return false;
        }

        $this->execute(
            "INSERT INTO shop_cart_items (cart_id, customer_id, product_code, quantity, unit_price, is_gift, created_at)
             SELECT :cart_id, MIN(customer_id), 'GIFT_MINI', 1, 0, 'Y', SYSTIMESTAMP FROM shop_cart_items WHERE cart_id = :cart_id",
            array('cart_id' => $cartId)
        );
        return true;
    }

    public function priorActiveOrdersForProduct($customerId, $baseProductCode)
    {
        return $this->fetchAll(
            "SELECT oi.product_code, o.status
             FROM shop_orders o JOIN shop_order_items oi ON oi.order_id = o.id
             WHERE o.customer_id = :customer_id
               AND o.status <> 'cancelled'
               AND REPLACE(oi.product_code, 'P', '') = REPLACE(:product_code, 'P', '')",
            array('customer_id' => $customerId, 'product_code' => $baseProductCode)
        );
    }

    public function componentsForSet($productCode)
    {
        return $this->fetchAll(
            'SELECT component_code, component_name FROM shop_product_components WHERE parent_code = :code ORDER BY sort_order',
            array('code' => $productCode)
        );
    }

    public function workingDays($startDate, $endDate)
    {
        return $this->fetchAll(
            "SELECT work_date, is_working FROM shop_working_days WHERE work_date BETWEEN TO_DATE(:start_date, 'YYYY-MM-DD') AND TO_DATE(:end_date, 'YYYY-MM-DD') ORDER BY work_date",
            array('start_date' => $startDate, 'end_date' => $endDate)
        );
    }

    public function delayedPrefecture($prefecture)
    {
        return $this->fetchOne(
            "SELECT 1 AS found FROM shop_delay_prefectures WHERE prefecture = :prefecture AND active = 'Y'",
            array('prefecture' => $prefecture)
        );
    }

    public function remoteIslandPostalCode($postalCode)
    {
        return $this->fetchOne(
            "SELECT 1 AS found FROM shop_remote_island_postals WHERE postal_code = :postal_code",
            array('postal_code' => $postalCode)
        );
    }

    public function createOrder($customerId, $addressId, $quote, $cartItems)
    {
        $idRow = $this->fetchOne('SELECT shop_orders_seq.NEXTVAL AS id FROM dual', array());
        $orderId = (int) $idRow['ID'];
        $paymentStatus = $quote['payment_method'] === 'credit_card' ? 'pending_3ds' : 'payment_planned';
        $customer = $customerId ? $this->customer($customerId) : null;

        $this->execute(
            "INSERT INTO shop_orders (id, customer_id, address_id, email, shipping_method, payment_method, subtotal, shipping_fee, status, payment_status, delivery_date, delivery_time, created_at)
             VALUES (:id, :customer_id, :address_id, :email, :shipping_method, :payment_method, :subtotal, :shipping_fee, 'ordered', :payment_status, :delivery_date, :delivery_time, SYSTIMESTAMP)",
            array(
                'id' => $orderId,
                'customer_id' => $customerId,
                'address_id' => $addressId,
                'email' => $customer ? $customer['EMAIL'] : null,
                'shipping_method' => $quote['shipping_method'],
                'payment_method' => $quote['payment_method'],
                'subtotal' => $quote['subtotal'],
                'shipping_fee' => $quote['shipping_fee'],
                'payment_status' => $paymentStatus,
                'delivery_date' => $quote['delivery_date'],
                'delivery_time' => $quote['delivery_time'],
            )
        );

        foreach ($cartItems as $item) {
            $this->execute(
                'INSERT INTO shop_order_items (order_id, product_code, name, quantity, unit_price, is_gift)
                 VALUES (:order_id, :product_code, :name, :quantity, :unit_price, :is_gift)',
                array(
                    'order_id' => $orderId,
                    'product_code' => $item['PRODUCT_CODE'],
                    'name' => $item['NAME'],
                    'quantity' => (int) $item['QUANTITY'],
                    'unit_price' => (int) $item['UNIT_PRICE'],
                    'is_gift' => $item['IS_GIFT'],
                )
            );
        }

        return $orderId;
    }

    public function cancelOrder($orderId)
    {
        $this->execute(
            "UPDATE shop_orders SET status = 'cancelled' WHERE id = :id",
            array('id' => $orderId)
        );
    }

    public function updateCreditPayment($orderId, $success)
    {
        $this->execute(
            'UPDATE shop_orders SET payment_status = :status WHERE id = :id',
            array('status' => $success ? 'credit_success' : 'credit_failed', 'id' => $orderId)
        );
    }

    public function commit()
    {
        oci_commit($this->connection());
    }

    public function rollback()
    {
        oci_rollback($this->connection());
    }

    private function fetchOne($sql, array $binds)
    {
        $rows = $this->fetchAll($sql, $binds);
        return count($rows) > 0 ? $rows[0] : null;
    }

    private function fetchAll($sql, array $binds)
    {
        $stmt = $this->execute($sql, $binds);
        $rows = array();
        while (($row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS)) !== false) {
            $rows[] = $row;
        }
        oci_free_statement($stmt);
        return $rows;
    }

    private function execute($sql, array $binds)
    {
        $CI =& get_instance();
        if (isset($CI->tekagamiCollector)) {
            $CI->tekagamiCollector->addSql($this->interpolate($sql, $binds), $binds, 'ci3-shop');
        }

        $stmt = oci_parse($this->connection(), $sql);
        if (!$stmt) {
            $e = oci_error($this->connection());
            throw new RuntimeException($e['message']);
        }

        $bound = array();
        foreach ($binds as $key => $value) {
            $bound[$key] = $value;
            oci_bind_by_name($stmt, ':' . $key, $bound[$key]);
        }

        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
            $e = oci_error($stmt);
            throw new RuntimeException($e['message']);
        }

        return $stmt;
    }

    private function connection()
    {
        if ($this->conn !== null) {
            return $this->conn;
        }

        $this->conn = oci_connect(
            getenv('DIGSHOP_DB_USER') ?: 'digshop',
            getenv('DIGSHOP_DB_PASSWORD') ?: 'digshop',
            getenv('DIGSHOP_DB_CONNECT') ?: 'oracle:1521/FREEPDB1',
            'AL32UTF8'
        );

        if (!$this->conn) {
            $e = oci_error();
            throw new RuntimeException($e['message']);
        }

        return $this->conn;
    }

    private function interpolate($sql, array $binds)
    {
        foreach ($binds as $key => $value) {
            $sql = str_replace(':' . $key, $this->literal($value), $sql);
        }
        return $sql;
    }

    private function literal($value)
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}

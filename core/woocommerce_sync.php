<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

function hsg_wc_get_api_credentials(PDO $pdo): array {
    return [
        'shop_url' => trim((string)setting_get($pdo, 'woocommerce_shop_url', '')),
        'consumer_key' => trim((string)setting_get($pdo, 'woocommerce_consumer_key', '')),
        'consumer_secret' => trim((string)setting_get($pdo, 'woocommerce_consumer_secret', '')),
    ];
}

function hsg_wc_sync_orders_api(PDO $pdo, int $limitPages = 5): array {
    $creds = hsg_wc_get_api_credentials($pdo);
    if (empty($creds['shop_url'])) {
        throw new RuntimeException('WooCommerce Webshop URL er ikke angivet under Indstillinger.');
    }

    $baseUrl = rtrim($creds['shop_url'], '/');
    if (!str_starts_with($baseUrl, 'http://') && !str_starts_with($baseUrl, 'https://')) {
        $baseUrl = 'https://' . $baseUrl;
    }

    $endpoint = $baseUrl . '/wp-json/wc/v3/orders';
    $params = [
        'per_page' => 100,
        'order' => 'desc',
        'orderby' => 'date',
    ];

    if (!empty($creds['consumer_key']) && !empty($creds['consumer_secret'])) {
        $params['consumer_key'] = $creds['consumer_key'];
        $params['consumer_secret'] = $creds['consumer_secret'];
    }

    $ordersSynced = 0;
    $itemsSynced = 0;

    // Load existing HSG product SKU mappings to enrich order items with brand/distillery/category
    $pMap = [];
    $stP = $pdo->query("SELECT p.sku, p.name, p.distillery, p.category, b.name brand_name FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id WHERE p.sku IS NOT NULL AND p.sku<>''");
    foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $pRow) {
        $skuKey = strtolower(trim((string)$pRow['sku']));
        if ($skuKey !== '') {
            $pMap[$skuKey] = $pRow;
        }
    }

    $stInsOrder = $pdo->prepare("INSERT INTO hsg_woocommerce_orders
        (wc_order_id, order_number, status, currency, total_amount, shipping_total, tax_total, discount_total, customer_name, customer_email, customer_country, payment_method, date_created)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        status=VALUES(status), total_amount=VALUES(total_amount), shipping_total=VALUES(shipping_total),
        tax_total=VALUES(tax_total), discount_total=VALUES(discount_total), customer_name=VALUES(customer_name),
        customer_email=VALUES(customer_email), customer_country=VALUES(customer_country), payment_method=VALUES(payment_method),
        date_created=VALUES(date_created), updated_at=NOW()");

    $stInsItem = $pdo->prepare("INSERT INTO hsg_woocommerce_order_items
        (order_id, wc_order_id, wc_product_id, sku, product_name, brand_name, distillery, category, quantity, line_total, line_tax, date_created)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stDelItems = $pdo->prepare("DELETE FROM hsg_woocommerce_order_items WHERE wc_order_id=?");

    for ($page = 1; $page <= $limitPages; $page++) {
        $pageParams = array_merge($params, ['page' => $page]);
        $url = $endpoint . '?' . http_build_query($pageParams);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'HSG-Administration-Reports/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        if (!empty($creds['consumer_key']) && !empty($creds['consumer_secret'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $creds['consumer_key'] . ':' . $creds['consumer_secret']);
        }

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("Kunne ikke forbinde til WooCommerce API: $err");
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("WooCommerce API returnerede HTTP-kode $code.");
        }

        $data = json_decode((string)$res, true);
        if (!is_array($data) || empty($data)) {
            break;
        }

        foreach ($data as $order) {
            if (empty($order['id'])) continue;
            $wcOrderId = (int)$order['id'];
            $orderNumber = (string)($order['number'] ?? $wcOrderId);
            $status = (string)($order['status'] ?? 'completed');
            $currency = (string)($order['currency'] ?? 'DKK');
            $totalAmount = (float)($order['total'] ?? 0);
            $shippingTotal = (float)($order['shipping_total'] ?? 0);
            $taxTotal = (float)($order['total_tax'] ?? 0);
            $discountTotal = (float)($order['discount_total'] ?? 0);

            $billing = (array)($order['billing'] ?? []);
            $custName = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
            $custEmail = trim((string)($billing['email'] ?? ''));
            $custCountry = trim((string)($billing['country'] ?? 'DK')) ?: 'DK';
            $paymentMethod = trim((string)($order['payment_method_title'] ?? $order['payment_method'] ?? 'Online'));

            $dateStr = (string)($order['date_created'] ?? $order['date_created_gmt'] ?? date('Y-m-d H:i:s'));
            $dateCreated = date('Y-m-d H:i:s', strtotime($dateStr));

            $stInsOrder->execute([
                $wcOrderId, $orderNumber, $status, $currency, $totalAmount,
                $shippingTotal, $taxTotal, $discountTotal, $custName, $custEmail,
                $custCountry, $paymentMethod, $dateCreated
            ]);

            $stGetId = $pdo->prepare("SELECT id FROM hsg_woocommerce_orders WHERE wc_order_id=?");
            $stGetId->execute([$wcOrderId]);
            $localOrderId = (int)$stGetId->fetchColumn();

            $stDelItems->execute([$wcOrderId]);

            $lineItems = (array)($order['line_items'] ?? []);
            foreach ($lineItems as $item) {
                $wcProdId = (int)($item['product_id'] ?? 0);
                $sku = trim((string)($item['sku'] ?? ''));
                $prodName = trim((string)($item['name'] ?? 'Ukendt produkt'));
                $qty = max(1, (int)($item['quantity'] ?? 1));
                $lineTotal = (float)($item['total'] ?? 0);
                $lineTax = (float)($item['total_tax'] ?? 0);

                $brandName = null;
                $distillery = null;
                $category = null;

                $skuKey = strtolower($sku);
                if ($skuKey !== '' && isset($pMap[$skuKey])) {
                    $brandName = $pMap[$skuKey]['brand_name'] ?? null;
                    $distillery = $pMap[$skuKey]['distillery'] ?? null;
                    $category = $pMap[$skuKey]['category'] ?? null;
                }

                $stInsItem->execute([
                    $localOrderId, $wcOrderId, $wcProdId, $sku, $prodName,
                    $brandName, $distillery, $category, $qty, $lineTotal, $lineTax, $dateCreated
                ]);
                $itemsSynced++;
            }
            $ordersSynced++;
        }
    }

    setting_set($pdo, 'woocommerce_last_synced_at', date('c'));
    return ['orders' => $ordersSynced, 'items' => $itemsSynced];
}

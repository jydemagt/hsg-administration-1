<?php
declare(strict_types=1);
require_once __DIR__.'/../session.php';
require_once __DIR__.'/../functions.php';
require_once __DIR__.'/../db.php';
require_once __DIR__.'/permissions.php';
require_once __DIR__.'/modules.php';
require_once __DIR__.'/audit.php';
require_once __DIR__.'/settings.php';

// Global Quick Search AJAX Handler
if (isset($_GET['action']) && $_GET['action'] === 'quick_search') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    $results = [];
    $like = '%' . $q . '%';

    if (can('products.view') || can('inventory.view') || can('catalog.view')) {
        $st = $pdo->prepare("
            SELECT id, sku, name, distillery, cask_number, status
            FROM lager_products
            WHERE name LIKE ? OR sku LIKE ? OR cask_number LIKE ? OR distillery LIKE ? OR call_name LIKE ?
            ORDER BY name ASC LIMIT 6
        ");
        $st->execute([$like, $like, $like, $like, $like]);
        $prods = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($prods as $p) {
            $results[] = [
                'type' => 'Produkt',
                'title' => $p['name'],
                'subtitle' => 'SKU: ' . $p['sku'] . ($p['cask_number'] ? ' · Cask: ' . $p['cask_number'] : ''),
                'url' => 'stock.php?q=' . urlencode($p['sku']),
            ];
        }
    }

    if (can('catalog.view')) {
        $stB = $pdo->prepare("SELECT id, name FROM lager_brands WHERE name LIKE ? ORDER BY name ASC LIMIT 3");
        $stB->execute([$like]);
        $brands = $stB->fetchAll(PDO::FETCH_ASSOC);
        foreach ($brands as $b) {
            $results[] = [
                'type' => 'Brand',
                'title' => $b['name'],
                'subtitle' => 'Producent / Mærke',
                'url' => 'catalog.php#brand-' . $b['id'],
            ];
        }
    }

    if (can('reservations.view')) {
        $stR = $pdo->prepare("
            SELECT r.id, r.customer_name, r.reference, r.quantity, p.name product_name
            FROM lager_reservations r
            JOIN lager_products p ON p.id = r.product_id
            WHERE r.customer_name LIKE ? OR r.reference LIKE ?
            ORDER BY r.id DESC LIMIT 3
        ");
        $stR->execute([$like, $like]);
        $res = $stR->fetchAll(PDO::FETCH_ASSOC);
        foreach ($res as $r) {
            $results[] = [
                'type' => 'Reservation',
                'title' => ($r['customer_name'] ?: 'Reservation #' . $r['id']) . ' (' . $r['quantity'] . ' stk)',
                'subtitle' => $r['product_name'] . ($r['reference'] ? ' · Ref: ' . $r['reference'] : ''),
                'url' => 'reservations.php?q=' . urlencode($r['customer_name'] ?: $r['reference']),
            ];
        }
    }

    echo json_encode(['results' => $results]);
    exit;
}

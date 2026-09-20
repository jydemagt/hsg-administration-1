<?php
declare(strict_types=1);
require_once __DIR__.'/../session.php';
require_once __DIR__.'/../functions.php';
require_once __DIR__.'/../db.php';
require_once __DIR__.'/permissions.php';
require_once __DIR__.'/modules.php';
require_once __DIR__.'/audit.php';
require_once __DIR__.'/settings.php';

// Global Quick Search & Command Palette AJAX Handler
if (isset($_GET['action']) && $_GET['action'] === 'quick_search') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    $results = [];
    $like = '%' . $q . '%';
    $lowerQ = mb_strtolower($q);

    // Command Palette Action Shortcuts (Filtered by Server-Side Permissions)
    $actions = [
        ['q' => ['opret produkt', 'nyt produkt', 'opret'], 'cap' => fn()=>is_admin() && can('products.view'), 'type' => 'Handling', 'title' => 'Opret nyt produkt', 'subtitle' => 'Åbn produktformular', 'url' => 'products.php#new-product'],
        ['q' => ['åbn lager', 'lager', 'lagerbeholdning'], 'cap' => fn()=>can('inventory.view'), 'type' => 'Handling', 'title' => 'Åbn lagerstyring', 'subtitle' => 'Oversigt over fysisk og disponibelt lager', 'url' => 'status.php'],
        ['q' => ['opret reservation', 'ny reservation', 'reservér'], 'cap' => fn()=>can('reservations.create'), 'type' => 'Handling', 'title' => 'Opret reservation', 'subtitle' => 'Reserver varer til kunde eller smagning', 'url' => 'status.php'],
        ['q' => ['brugere', 'brugeradgang', 'links'], 'cap' => fn()=>is_superadmin() || can('users.manage'), 'type' => 'Handling', 'title' => 'Administrer brugere & links', 'subtitle' => 'Tildel rettigheder og adgangslinks', 'url' => 'users.php'],
        ['q' => ['rapporter', 'salg', 'omsætning'], 'cap' => fn()=>can('reports.view'), 'type' => 'Handling', 'title' => 'Salgsrapporter & WooCommerce', 'subtitle' => 'Se omsætning og salgsstatistik', 'url' => 'reports.php'],
        ['q' => ['woocommerce', 'webshop', 'sync'], 'cap' => fn()=>is_admin() && can('reports.view'), 'type' => 'Handling', 'title' => 'WooCommerce Indstillinger', 'subtitle' => 'Synkroniser ordrer og test API', 'url' => 'reports.php?tab=settings'],
        ['q' => ['systemstatus', 'audit', 'aktivitet'], 'cap' => fn()=>is_admin(), 'type' => 'Handling', 'title' => 'Systemstatus & Auditlog', 'subtitle' => 'Overvåg system og seneste ændringer', 'url' => 'system.php']
    ];

    foreach ($actions as $act) {
        if (!($act['cap'])()) continue; // Server-side permission enforcement
        foreach ($act['q'] as $keyword) {
            if (str_contains($keyword, $lowerQ) || str_contains($lowerQ, $keyword)) {
                $results[] = [
                    'type' => $act['type'],
                    'title' => $act['title'],
                    'subtitle' => $act['subtitle'],
                    'url' => $act['url'],
                ];
                break;
            }
        }
    }

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

    if (is_admin()) {
        if (db_table_exists($pdo, 'lager_users') && (is_superadmin() || can('users.manage'))) {
            $stU = $pdo->prepare("SELECT id, name, email FROM lager_users WHERE name LIKE ? OR email LIKE ? ORDER BY name ASC LIMIT 3");
            $stU->execute([$like, $like]);
            $users = $stU->fetchAll(PDO::FETCH_ASSOC);
            foreach ($users as $u) {
                $results[] = [
                    'type' => 'Bruger (Link)',
                    'title' => $u['name'],
                    'subtitle' => $u['email'] ?: 'Personligt link-adgang',
                    'url' => 'users.php?q=' . urlencode($u['name']),
                ];
            }
        }

        if (db_table_exists($pdo, 'hsg_woocommerce_orders') && can('reports.view')) {
            $stO = $pdo->prepare("
                SELECT id, order_number, billing_first_name, billing_last_name, status, total
                FROM hsg_woocommerce_orders
                WHERE order_number LIKE ? OR billing_first_name LIKE ? OR billing_last_name LIKE ? OR billing_email LIKE ?
                ORDER BY id DESC LIMIT 3
            ");
            $stO->execute([$like, $like, $like, $like]);
            $orders = $stO->fetchAll(PDO::FETCH_ASSOC);
            foreach ($orders as $o) {
                $name = trim($o['billing_first_name'] . ' ' . $o['billing_last_name']);
                $results[] = [
                    'type' => 'Ordre',
                    'title' => 'Ordre #' . ($o['order_number'] ?: $o['id']) . ($name ? ' - ' . $name : ''),
                    'subtitle' => 'Status: ' . $o['status'] . ' · Total: ' . $o['total'] . ' kr.',
                    'url' => 'reports.php?q=' . urlencode((string)($o['order_number'] ?: $o['id'])),
                ];
            }
        }
    }

    echo json_encode(['results' => $results]);
    exit;
}

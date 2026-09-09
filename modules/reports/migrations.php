<?php
declare(strict_types=1);

return [
    '1.0.0' => function(PDO $pdo): void {
        if (!db_table_exists($pdo, 'hsg_woocommerce_orders')) {
            $pdo->exec("CREATE TABLE hsg_woocommerce_orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                wc_order_id BIGINT UNSIGNED NOT NULL,
                order_number VARCHAR(100) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'completed',
                currency VARCHAR(10) NOT NULL DEFAULT 'DKK',
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                shipping_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                tax_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                discount_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                customer_name VARCHAR(180) NULL,
                customer_email VARCHAR(190) NULL,
                customer_country VARCHAR(10) NULL DEFAULT 'DK',
                payment_method VARCHAR(80) NULL,
                date_created DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_wc_order_id (wc_order_id),
                INDEX idx_wc_orders_date (date_created),
                INDEX idx_wc_orders_status (status),
                INDEX idx_wc_orders_country (customer_country)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!db_table_exists($pdo, 'hsg_woocommerce_order_items')) {
            $pdo->exec("CREATE TABLE hsg_woocommerce_order_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                wc_order_id BIGINT UNSIGNED NOT NULL,
                wc_product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                sku VARCHAR(100) NULL,
                product_name VARCHAR(255) NOT NULL,
                brand_name VARCHAR(180) NULL,
                distillery VARCHAR(180) NULL,
                category VARCHAR(140) NULL,
                quantity INT NOT NULL DEFAULT 1,
                line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                line_tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                date_created DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_wc_items_order (order_id),
                INDEX idx_wc_items_wc_order (wc_order_id),
                INDEX idx_wc_items_sku (sku),
                INDEX idx_wc_items_date (date_created),
                INDEX idx_wc_items_brand (brand_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!db_table_exists($pdo, 'hsg_saved_reports')) {
            $pdo->exec("CREATE TABLE hsg_saved_reports (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(180) NOT NULL,
                description VARCHAR(500) NULL,
                filters_json TEXT NOT NULL,
                created_by_admin INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_saved_reports_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }
];

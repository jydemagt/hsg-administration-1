<?php
declare(strict_types=1);

return [
    // Trin 1: modtagertabel og standard "ingen adgang" for eksisterende login-brugere.
    '1.0.0' => static function(PDO $pdo): void {
        if (!db_table_exists($pdo, 'hsg_newsletter_recipients')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS hsg_newsletter_recipients (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NULL,
                email_hash CHAR(64) NOT NULL,
                name VARCHAR(180) NULL,
                company VARCHAR(180) NULL,
                segment ENUM('dealer','customer') NOT NULL DEFAULT 'customer',
                status ENUM('active','unsubscribed','stopped','deleted') NOT NULL DEFAULT 'active',
                source ENUM('import','manual') NOT NULL,
                token_hash CHAR(64) NULL,
                token_cipher TEXT NULL,
                unsubscribed_at DATETIME NULL,
                unsubscribe_via ENUM('link','one_click','admin') NULL,
                last_import_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_nl_rec_email_hash (email_hash),
                UNIQUE KEY uniq_nl_rec_token_hash (token_hash),
                INDEX idx_nl_rec_status_segment (status, segment)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Fluebenet i users.php skal stå korrekt fra start. Den egentlige sikring er
        // 'admin_default_access' => false i manifestet (se hsg_admin_can_view_module()).
        if (db_table_exists($pdo, 'hsg_admin_module_access') && db_table_exists($pdo, 'lager_admins') && db_column_exists($pdo, 'lager_admins', 'is_superadmin')) {
            $pdo->exec("INSERT IGNORE INTO hsg_admin_module_access(admin_id,module_id,can_view) SELECT id,'newsletter',0 FROM lager_admins WHERE is_superadmin=0");
        }
    },
];

<?php
declare(strict_types=1);
return [
    '1.1.0' => static function(PDO $pdo): void {
        $st=$pdo->prepare('INSERT IGNORE INTO hsg_settings(setting_key,setting_value,updated_at) VALUES(?,?,NOW())');
        $st->execute(['platform_name','HSG Administration']);
        $st->execute(['company_name','HSG Whisky ApS']);
    },
];

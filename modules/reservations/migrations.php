<?php
declare(strict_types=1);
// Indlæses ved hver forespørgsel (core/modules.php): filen må kun returnere arrayet.
return [
    // Ny status "Arkiveret" til reservationer, der arkiveres ved upload af Kims fil (spec 2026-10-26-kims-upload-kun-lagerantal, 5.1).
    // Den nye værdi står til sidst, så de eksisterende værdier beholder deres indeks. Friske installationer har den allerede
    // fra schema.sql. En kolonnetype, der hverken har 'archived' eller er præcis de tre gamle værdier, ændres ikke, så der
    // ikke mistes værdier (arkivering afvises så af vagten i hsg_supplier_archive_reservations).
    '1.3.0' => static function(PDO $pdo): void {
        $st = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $st->execute(['lager_reservations', 'status']);
        $type = strtolower(trim((string)$st->fetchColumn()));
        if (str_contains($type, "'archived'")) return;
        if ($type !== "enum('reserved','completed','cancelled')") return;
        $pdo->exec("ALTER TABLE lager_reservations MODIFY status ENUM('reserved','completed','cancelled','archived') NOT NULL DEFAULT 'reserved'");
    },
];

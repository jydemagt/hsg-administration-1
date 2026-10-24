<?php
declare(strict_types=1);
// Indlæses ved hver forespørgsel (core/modules.php): filen må kun returnere arrayet.
return [
    // Engangsoprydning: fadnumre uden ciffer, der beviseligt er udledt automatisk af navnet (spec 2026-10-22).
    '1.5.1' => static function(PDO $pdo): void {
        require_once __DIR__.'/../../core/product_enrichment.php';
        hsg_cask_number_cleanup($pdo);
    },
];

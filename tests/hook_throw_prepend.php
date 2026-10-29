<?php
/*
 * Kun til tests/supplier_archive_qa_test.php (Q20, spec 2026-10-26-kims-upload-opfoelgning-review, kriterie 188).
 * Testen starter en ekstra php -S med auto_prepend_file=denne fil. Filen lægger en hook på 'reservation.archived', der altid
 * kaster, så testen kan se, at en fejlende hook efter commit hverken vælter den gemte import eller skjuler success-beskeden.
 * Filen bruges aldrig af selve systemet (tests/ kommer ikke med i release).
 */
$GLOBALS['hsg_hooks']['reservation.archived'][10][] = static function (array $payload): void {
    throw new RuntimeException('QA: hooken fejler med vilje');
};

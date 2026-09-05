<?php
declare(strict_types=1);

return [
    'id' => 'reports',
    'name' => 'Rapporter / WooCommerce',
    'version' => '1.0.0',
    'sort' => 25,
    'core' => false,
    'enabled' => true,
    'nav' => true,
    'link_access' => true,
    'capability' => 'reports.view',
    'description' => 'WooCommerce salgsrapporter, mobilvenligt dashboard og brugerdefinerede rapport-skabeloner.',
    'routes' => [
        'reports.php' => 'Rapporter & Mobil-dashboard',
    ],
];

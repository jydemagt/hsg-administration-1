<?php
return [
    'id' => 'newsletter',
    'name' => 'Nyhedsbrev',
    'version' => '1.0.0',
    'nav' => true,
    'link_access' => false,
    // Almindelige login-brugere har ingen adgang, før en superadministrator giver den i users.php.
    'admin_default_access' => false,
    'href' => 'newsletter.php',
    'icon' => '✉',
    'capability' => 'newsletter.send',
    'description' => 'Månedligt nyhedsbrev til forhandlere og kunder med afmelding og katalog.',
    'core' => false,
    'sort' => 35,
];

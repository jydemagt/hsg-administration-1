<?php
declare(strict_types=1);
require __DIR__.'/auth.php';
require_module_enabled('newsletter');
require_capability('newsletter.send');

// Trin 1: Udsendelser kommer i trin 2. Indtil da er modtagerlisten startsiden.
redirect('newsletter_recipients.php');

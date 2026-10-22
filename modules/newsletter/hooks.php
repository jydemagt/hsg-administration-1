<?php
declare(strict_types=1);
/**
 * Nyhedsbrev – indlæses af auth.php (hsg_load_module_hooks()) på alle sider, når modulet er slået til.
 *
 * Importens forhåndsvisning ligger i sessionen (op til ca. 10 MB tekst), og sessionen indlæses på hver side.
 * En udløbet forhåndsvisning slettes derfor her, uanset hvilken side der åbnes, og ikke kun på nyhedsbrevssiderne.
 * core/newsletter.php indlæses kun, når der faktisk ligger en forhåndsvisning i sessionen.
 */
if (isset($_SESSION['newsletter_import'])) {
    require_once __DIR__.'/../../core/newsletter.php';
    hsg_newsletter_preview_expire();
}

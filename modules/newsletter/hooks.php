<?php
declare(strict_types=1);
/**
 * Nyhedsbrev – indlæses af auth.php (hsg_load_module_hooks()) på alle sider, når modulet er slået til,
 * før login og verify_csrf. Her laves derfor kun billigt arbejde i brugerens egen session.
 *
 * Importens forhåndsvisning ligger i sessionen (op til ca. 10 MB tekst), og sessionen indlæses på hver side.
 * En udløbet forhåndsvisning slettes derfor her, uanset hvilken side der åbnes, og ikke kun på nyhedsbrevssiderne.
 * Kun den lille core/newsletter_import_preview.php indlæses, og kun når der ligger en forhåndsvisning i sessionen.
 */
if (isset($_SESSION['newsletter_import'])) {
    require_once __DIR__.'/../../core/newsletter_import_preview.php';
    hsg_newsletter_preview_expire();
}

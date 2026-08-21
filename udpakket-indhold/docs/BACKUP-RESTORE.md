# HSG Administration – backup og disaster recovery

## Backupstrategi

- Natlig DATA-backup: database + mutable uploads.
- Ugentlig FULL-backup: hele applikationen (undtagen `config.php` og gamle backups) + database + uploads + restore-værktøj.
- Lokal kopi gemmes i `storage/backups/`, som er beskyttet mod direkte webadgang via `.htaccess`.
- Hvis OneDrive er aktiveret, uploades samme ZIP til OneDrive.
- OneDrive client secret og cron-nøgle udelades fra database-dumpet og skal genindtastes efter en fuld restore.

## Automatisk kørsel

Anbefalet: webhotellets cron kører `php /absolut/sti/til/hsg/cron/backup.php` hver nat.
Hvis webhotellet kun kan kalde en URL, kan `cron/backup.php?key=...` bruges. Nøglen genereres på Backup-siden.

## Restore

En FULL backup indeholder `README-RESTORE.txt`, `restore.php`, `database.sql`, `manifest.json` og `site/`.
Udpak pakken på et nyt webhotel, opret en tom database og åbn `restore.php`. Værktøjet kopierer sitefiler, importerer database og opretter ny `config.php`.

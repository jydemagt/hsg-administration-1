# OneDrive-backup – Microsoft 365 opsætning

HSG Administration bruger Microsoft Graph med client credentials, så natlig backup kan køre uden at en bruger er logget ind.

## Microsoft Entra

1. Opret en App registration i den Microsoft 365-tenant, hvor backup-OneDrive ligger.
2. Gem **Tenant ID** og **Application (client) ID**.
3. Opret et **Client secret** og gem værdien sikkert.
4. Tilføj Microsoft Graph **Application** file permission, som giver appen adgang til den valgte OneDrive. Den enkle opsætning er `Files.ReadWrite.All` med administrator-consent. I en mere låst Microsoft 365-opsætning kan rettighederne senere begrænses yderligere.
5. Vælg en Microsoft 365-bruger med OneDrive, fx `backup@hsg-whisky.dk`.

## HSG Administration

Gå til **Backup & disaster recovery** og indtast:

- Tenant ID
- Client ID
- Client secret
- OneDrive-brugerens UPN/e-mail
- Backupmappe, fx `HSG Administration/Backups`

Gem og tryk derefter **Test OneDrive**.

Systemet opretter mappestrukturen automatisk, hvis Graph-rettighederne tillader det. Små filer uploades direkte; større backups bruger en upload-session og sendes i chunks.

## Sikkerhed

Client secret gemmes server-side i HSG-databasen, men udelades bevidst fra disaster-recovery database-dumpet. Efter en restore skal secret derfor indtastes igen. Det samme gælder cron-nøglen.

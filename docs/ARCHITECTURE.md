# HSG Administration – arkitektur

HSG Administration er bygget som en lille modulær PHP/MySQL-platform til almindeligt webhotel.

## Principper

- `core/` indeholder kun fælles platformfunktioner.
- `modules/<id>/` beskriver hvert forretningsområde.
- Eksisterende lagerdata ligger fortsat i `lager_*`-tabeller, så v1.0-data kan opgraderes uden ny import.
- Fælles platformdata ligger i `hsg_*`-tabeller.
- Hvert modul har eget versionsnummer og kan have egne migrations.
- Nye funktioner skal så vidt muligt tilføjes som moduler eller som versionsopdateringer til et eksisterende modul.
- Administratorhandlinger logges i audit-loggen.

## Modulformat

Et modul har som minimum:

`modules/<id>/module.php`

Manifestet kan indeholde:

- `id`: stabil teknisk nøgle
- `name`: navn i menuen
- `version`: modulversion
- `nav`: om modulet vises i navigation
- `href`: startside
- `icon`: kort ikon/tegn
- `capability`: krævet rettighed
- `description`: beskrivelse
- `core`: kernemoduler kan ikke deaktiveres
- `sort`: rækkefølge i navigation
- `link_access`: om modulet kan tildeles et personligt direkte link

Valgfrie filer:

- `migrations.php`: versionerede databaseændringer
- `hooks.php`: hændelser og integrationer

## Migrations

`migrations.php` returnerer et array med versionsnummer => callable. Platformen kører kun migrationer nyere end den registrerede modulversion.

Eksempel:

```php
return [
    '1.1.0' => function(PDO $pdo): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS hsg_example (...)');
    },
];
```

## Hooks

Platformen har et simpelt event-system i `core/hooks.php`.

Eksempel:

```php
hsg_add_action('reservation.created', function(array $payload): void {
    // Reager på en ny reservation.
});
```

Det gør det muligt senere at lade fx WooCommerce-, salgs- eller økonomimoduler reagere på lagerhændelser uden at ændre reservationskoden.

## Sikkerhed

- Personlige lagerlinks har modulrettigheder pr. bruger og er ikke administrative.
- Linkbrugere kan kun udføre de sikre arbejdsoperationer, modulet udtrykkeligt tillader; aktuelt reservationer.
- Fuld administration kræver separat admin-login.
- Nye sider skal altid bruge `require_capability()` eller `require_admin()`.
- POST-formularer beskyttes af CSRF-kontrol.
- SQL skal bruge prepared statements ved brugerinput.

## Opdateringsflow

1. Tag en FULL disaster-recovery backup i systemet.
2. Upload ny kode oven i installationen.
3. Behold `config.php` og `uploads/`.
4. Ved første request køres core- og modulmigrations automatisk.
5. Kontroller `System` for versionsstatus og audit-log.
6. Kontroller `Backup` og lav gerne en manuel FULL-backup efter større opdateringer.

## Fremtidige moduler

Platformen er egnet til bl.a.:

- Indkøb og leverandører
- Kunder og salgsordrer
- Fade og anparter
- Events/smagninger
- Økonomi/prisberegning
- WooCommerce-integration
- Rapportering og ledelsesdashboard

Hver funktion bør få sit eget modul og egne database-tabeller, når det giver mening.


## Selvopgradering
Fra v1.2.3 er `updater` et kernemodul. En distributionspakke har `hsg-package.json` i roden med versionskrav og SHA-256 for pakkens filer. `update.php` uploader først pakken til `storage/tmp/updates`, hvorefter administratoren eksplicit installerer den. Installationen laver en FULL-backup, sætter en kort vedligeholdelseslås, overskriver kun programfiler og kører database-migrationer. `config.php`, `uploads/`, `storage/backups/` og mutable data er beskyttede.

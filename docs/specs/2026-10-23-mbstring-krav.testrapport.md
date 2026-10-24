# Testrapport: mbstring og openssl som krævede PHP-udvidelser, hurtigsøgningens fallback og SQL-rettelse

- **Branch:** `feature/mbstring-krav`, testet på commit `ed4dc5c` (basen er `origin/main` = `16cd3d0`). Spec: `docs/specs/2026-10-23-mbstring-krav.md`, kriterie 111-127 (113 og 114 er udgået) og afsnit 10.
- **Testet:** 23-10-2026, af uafhængig tester. Udviklerens tal er gentaget, ikke brugt som bevis.
- **Miljø:** Windows 11 / Git Bash, PHP 8.5.8 (`php -S`, `display_errors=On`, `E_ALL`), MariaDB 10.4.22 (tom database pr. kørsel). "Med alle udvidelser" er `PHPRC` med `mbstring, zip, pdo_mysql, openssl, gd, curl`. "Uden mbstring" er `php -c .../phpini-nomb/php.ini`. Miljøet er kontrolleret med `extension_loaded` hver gang (`mbstring=0 pdo_mysql=1 zip=1 gd=1 curl=1 openssl=1`).
- **Ikke testet:** PHP 8.1, MySQL 8.0, Linux/CI, rigtige browsere og **375 px** (se "Ikke kørt"), GitHub-hentning over nettet.

## Konklusion

**KLAR.**

- **Alle 15 gældende kriterier er bestået** (111, 112 og 115-127). Ingen SKAL-fund.
- **Kriterie 120 og 118 ved 375 px er ikke testet af mig.** Projektlederen laver den kontrol selv i en browser.
- Fund: **2 BØR** (testhuller, begge lukket med nye tests) og **5 KAN** (se "Fund").
- **Testhul fundet og lukket:** udviklerens tests alene fanger kun 9 af mine 21 mutanter. Kriterie 116-120 og 122 var kun manuelle i spec'en, så fx en omvendt rækkefølge af lås og tjek i `install.php`, fjernet fallback i `bootstrap.php` og en gammel hårdkodet liste i `build-release.php` slap igennem. Med mine tilføjelser fanges alle 21.

## Kørsler

| Kørsel | Resultat |
|---|---|
| `bash tests/run.sh` via `run-all.sh`, `HSG_TEST_PHP_INI_UDEN_MBSTRING` sat, **før** mine tilføjelser (`ed4dc5c`) | 162 OK-linjer, 1 FEJL. Trin 4 har 157 OK og 1 FEJL, som udvikleren melder. Den ene FEJL er den kendte `KAN afmeldingssiden ved databasefejl` (`tests/newsletter_funktionel.sh:224-227`, `RENAME COLUMN` findes ikke i MariaDB 10.4). |
| Samme kørsel **med** mine tilføjelser | **219 OK-linjer, 1 FEJL** (samme kendte). Trin 1: 1 OK. Trin 2: 3 OK (nyhedsbrev 143 tjek, PHP-krav 15 tjek, uden mbstring 16 tjek). Trin 3: 2 OK (negativtest og min release-test med 16 tjek). Trin 4: 213 OK = 145 (baseline) + 12 (udviklerens røgtest) + 56 (min `php_krav_tester.sh`). |
| `bash tests/run.sh` uden `HSG_TEST_PHP_INI_UDEN_MBSTRING` (kopi i Temp, uden nyhedsbrevets lange tests) | Grøn, 70 OK, 0 FEJL. Der står `[INFO] Test uden mbstring sprunget over (HSG_TEST_PHP_INI_UDEN_MBSTRING ikke sat)` og tilsvarende INFO fra min test (kriterie 125). |
| Kontrolkørsel af umuteret kopi i mutantværktøjet | Grøn, 116 OK, 0 FEJL. |
| 21 mutanter | Se "Mutanter". |

## Acceptkriterier

| Nr. | Kriterie | Resultat | Bevis |
|---|---|---|---|
| 111 | `php -r "require 'core/php_requirements.php'; ..."` giver listen og `1` | BESTÅET | Output med og uden mbstring: `["pdo","pdo_mysql","zip","json","gd","curl","mbstring","openssl"]1`. Udviklerens test tjekker også, at filen kun har funktionsdefinitioner (ingen `require`, `echo`, `exit`, HTML-tag). Mutant med `@session_start()` fanges. |
| 112 | `hsg_missing_extensions` og `hsg_missing_extensions_message` | BESTÅET | `php tests/php_requirements_test.php`: 15 bestået (alle udvidelser). `php -c <uden-mbstring> ... --uden-mbstring`: 16 bestået (`hsg_missing_extensions()` = `['mbstring']`). Uden flaget, men uden mbstring: 15 bestået. Med flaget og med mbstring: 2 FEJL som tilsigtet (testen afviser et ugyldigt miljø). Teksterne er tegn for tegn lig 3.4 ("gd, mbstring og zip", "gd og mbstring"). Mutanter på tomme navne, "og" i flertal og rækkefølge fanges. |
| 115 | Hurtigsøgningen som admin og uden login | BESTÅET | Udviklerens røgtest (12 OK) og mine egne kald: `q=%C3%85BN` giver HTTP 200, `application/json; charset=utf-8` og resultatet `Handling / Åbn lagerstyring`. `q=%C3%85` giver præcis `{"results":[]}`. Uden cookies giver `q=a` præcis `{"results":[]}`. Ingen `Fatal`, `Warning` eller `Uncaught` i svar eller log. |
| 116 | Samme tre kald uden mbstring | BESTÅET | Server uden mbstring, nyt admin-login: alle tre HTTP 200, gyldig JSON med `results`, ingen PHP-fejl, ingen fejl i serverloggen. Indholdet afviger som tilladt: `ÅBN` giver 0 resultater, og `Å` (2 bytes) tæller som to tegn og giver flere produkter. Nu automatisk i `tests/php_krav_tester.sh`. Fjernes fallbacken, fejler 5 tjek (`Call to undefined function mb_strlen()`). |
| 117 | Installation som i dag; med `config.php` kommer låsen først | BESTÅET | `run.sh`: `Installation gennemført`. Uden mbstring og med `config.php`: GET og POST (med alle felter) på `install.php` viser `Systemet er allerede installeret` og ingen `Webhotellet mangler`. Er rækkefølgen vendt (mutant), fejler 4 tjek. |
| 118 | Manglesiden ved GET i en ren kopi uden mbstring | BESTÅET | Ren `git archive HEAD` uden `config.php`: HTTP 200. Siden har `Webhotellet mangler PHP-udvidelsen mbstring.`, `Slå udvidelsen til i webhotellets kontrolpanel under PHP-indstillinger`, mbstring som `badge red Mangler` og de øvrige syv som `badge green OK` i rækkefølge, `<a class="button" href="install.php">Tjek igen</a>`, ingen `name="db_host"` og ingen `<form`. Ingen stier, `Warning`, `Fatal`, `on line` eller `Stack trace`. Titel og `<h1>` er "Installer HSG Administration". **375 px: ikke testet af mig.** |
| 119 | POST uden mbstring ændrer intet; 419 med alle udvidelser | BESTÅET | Uden mbstring: POST med alle felter (uden cookie og uden csrf, med session uden csrf, med forkert csrf, med `db_host[]`, `csrf[]`, `config` og listefelter, og med en tekst på 9 kB med `<script>` og `'; DROP DATABASE`) giver hver gang HTTP 200 og **præcis** manglesiden (`cmp` mod GET-siden). `config.php` findes ikke bagefter, `SHOW TABLES` giver 0 rækker, og MariaDBs `Connections`-tæller steg kun med min egen måling (ingen databaseforbindelse fra `install.php`). Alle udvidelser, session der har hentet formularen: POST uden csrf giver 419, POST med forkert csrf giver 419, og intet bliver oprettet. Sammenlignet med `16cd3d0` med alle udvidelser er GET og tre POST-varianter **byte for byte identiske** (efter normalisering af csrf-token). Se KAN 3 om POST helt uden session. |
| 120 | Systemstatus, kortet "PHP-udvidelser" | BESTÅET | Alle udvidelser: `badge green ✓ Alle aktiveret`, teksten `pdo, pdo_mysql, zip, json, gd, curl, mbstring, openssl`, ingen `flash error`. Uden mbstring: `badge red 🔴 Mangler: mbstring` og i en `flash error role="alert"`: `Webhotellet mangler PHP-udvidelsen mbstring. Slå udvidelsen til ... support om det. Næste opgradering kan ikke installeres, før det er gjort.` Uden både mbstring og openssl: `🔴 Mangler: mbstring, openssl` og beskeden i flertal. Ingen PHP-fejl. **375 px laves separat af projektlederen i en browser.** |
| 121 | `hsg_update_validate_package` bruger pakkens liste | BESTÅET | Udviklerens enhedstest (med og uden mbstring) og min ende-til-ende-test via `update.php` med admin-login og CSRF (se nedenfor). `['pdo','hsg_fake_ext']` giver præcis den spec'ede tekst. `[hsg_fake_a, pdo, hsg_fake_b]` giver "PHP-udvidelserne hsg_fake_a og hsg_fake_b". `[]` giver "Pakken mangler den nødvendige fil app_version.php." (uden mbstring også, så pakkens liste bruges). En liste med tom streng, en liste inde i listen og `null` giver kun `hsg_fake_c`. Mutant med den installerede liste fanges. |
| 122 | `php tools/build-release.php --output=...` | BESTÅET | Rigtig ZIP bygget (192 filer, `RELEASE BYGGET OG GODKENDT`). `required_extensions` i ZIP'ens `hsg-package.json` er lig `hsg_required_extensions()` (med `mbstring` og `openssl`). `core/php_requirements.php` er med, og SHA-256 i manifestet passer. Ingen `tests/`, `docs/specs/`, `.claude/` eller `config.php` i ZIP'en. `tools/build-release.php` indeholder ikke `'curl'`. Den committede `hsg-package.json` er uændret. ZIP'erne er slettet. |
| 123 | `--test-negative` | BESTÅET | `ALLE 7 NEGATIVE TESTS (A-G) BESTÅET SIKKERT!`. Test B: "Uventet fil: unmanifested_extra.php". Test C: "Expected SHA-256 ... Actual SHA-256". Ingen forekomst af "PHP-udvidelse" i logteksten. **Uden mbstring på bygge-maskinen** er B og C også "BESTÅET", men med teksten om mbstring, altså den forkerte grund. Det er netop det, kriteriet skal fange (se BØR 1). |
| 124 | README-afsnittet | BESTÅET | Afsnittet "KRAV / ANBEFALET PHP" i `README.txt` er tegn for tegn lig blokken i spec afsnit 6 (sammenlignet programmatisk). Listen i README er lig `hsg_required_extensions()`. Mutant uden openssl i README fanges af ny test. |
| 125 | `bash tests/run.sh` er grøn | BESTÅET | Se "Kørsler". Eneste FEJL er den kendte, som ikke er en regression. INFO-linjen står som forventet uden miljøvariablen. |
| 126 | Filer, der skal være uændrede | BESTÅET | `git diff 16cd3d0...HEAD` ændrer kun `README.txt`, `admin.php`, `core/bootstrap.php`, `core/php_requirements.php`, `core/updater.php`, `install.php`, `tools/build-release.php`, spec og `tests/`. Uændrede: `core/product_enrichment.php`, `core/supplier_import.php`, `import.php`, `core/newsletter.php`, `catalog_pdf.php`, `pdf_simple.php`, `.github/workflows/tests.yml`, `hsg-package.json`, `app_version.php`, `system.php`, `backup.php`, `update.php`. Ingen migration, ingen modulversion. |
| 127 | Dansk | BESTÅET | Alle nye tekster er på dansk: install-siden, Systemstatus, updater-beskeden, README, kommentarer og testtekster. |

### Afsnit 10: rettelsen af hurtigsøgningens SQL

BESTÅET. Kolonnerne findes i den installerede database (`lager_users`: `id, name`; `hsg_woocommerce_orders`: `order_number, customer_name, customer_email, total_amount`). Som admin, med og uden mbstring:

- Søgning efter en linkbruger giver `Bruger (Link)` med undertitlen "Personligt link-adgang".
- Søgning efter en ordre giver `Ordre #MB-880001 - <kundenavn>` og `Status: completed · Total: 199.95 kr.`. Det virker på ordrenummer, kundenavn (`Øjvind`) og kundens mail.
- En ordre med tomt ordrenummer og `customer_name` NULL giver `Ordre #<id>` uden fejl.
- `%`, `_`, `'`, `"`, `\`, ugyldig UTF-8, nulbyte, emoji, `<script>`, `' OR 1=1 --`, tom streng, kun mellemrum og 6.000 bytes (3.000 × Æ) giver HTTP 200 og gyldig JSON.
- En direkte linkbruger (`?k=`) får aldrig brugere eller ordrer (0 resultater for navn og ordrenummer), kun handlinger og produkter. Uden login er alt `{"results":[]}` (8 forskellige søgninger).
- Gammel SQL (mutant) giver en fatal fejl i svaret (ugyldig JSON), og udviklerens røgtest fejler.

### Opgradering via `update.php` (kriterie 121 ende til ende og D3)

Ren kopi af `HEAD` installeret mod en tom database, admin-login og CSRF. Snapshot før og efter: hash af alle filer (undtagen `storage/tmp`), rækker i `hsg_update_runs`, `schema_version`, `app_version.php`, `storage/backups/`, `.hsg-maintenance`, staging-ZIP'er og audit-hændelser `system.update`.

| Pakke (ændret manifest, version 99.0.0) | Server | Resultat |
|---|---|---|
| `["pdo","hsg_fake_ext"]` | alle udvidelser | Flash: `Opgraderingen kan ikke installeres. Webhotellet mangler PHP-udvidelsen hsg_fake_ext. Slå udvidelsen til ... Intet er installeret eller ændret.` |
| `["hsg_fake_a","pdo","hsg_fake_b"]` | alle udvidelser | "PHP-udvidelserne hsg_fake_a og hsg_fake_b. Slå udvidelserne til ..." |
| `["pdo","","hsg_fake_c",["nested"],null]` | alle udvidelser | Kun `hsg_fake_c` nævnes, ingen PHP-advarsel |
| Pakkens rigtige liste (med mbstring og openssl) | alle udvidelser | "Pakken er kontrolleret" (staged), og "Annullér" fjerner den igen |
| Pakkens rigtige liste | **uden mbstring** | Flash: "... Webhotellet mangler PHP-udvidelsen mbstring ... Intet er installeret eller ændret." |
| `["pdo","zip"]` | **uden mbstring** | Accepteres. Pakkens liste bruges, ikke den installerede (som indeholder mbstring) |
| Upload uden login / uden csrf | | HTTP 401 / 419 |

**Efter alle forsøg er snapshot identisk:** ingen backup, ingen filer ændret, ingen version, ingen række i `hsg_update_runs`, ingen vedligeholdelsesfil, ingen staging-ZIP og ingen audit-linje. Jeg kaldte også `hsg_install_staged_update()` direkte (som `action=install` gør) med de to afviste pakker, uden mbstring og med alle udvidelser: begge giver `RuntimeException` med den danske tekst, og snapshot er uændret. Kontrollen sker altså før backup og filkopiering. GitHub-stien (`hsg_github_download_and_stage`) er uændret og sletter ZIP'en ved fejl; selve hentningen er ikke testet.

**Flow A (første release med kravet).** En ZIP bygget fra branchen valideret af den **gamle** updater (`16cd3d0`): med alle udvidelser accepteres den (version 11.1.4, 192 filer). Uden mbstring afvises den med den gamle tekst `PHP-udvidelsen mbstring mangler på webhotellet.`, præcis som afsnit 2 beskriver.

### Hver udvidelse for sig i `install.php`

`install.php` indlæser fire filer før tjekket. Jeg har prøvet ini-filer uden hhv. `zip`, `pdo_mysql`, `gd`, `curl`, `openssl`, `mbstring + openssl` og seks på én gang (`pdo_mysql, zip, gd, curl, mbstring, openssl`). Alle viser manglesiden (HTTP 200, ingen formular, ingen PHP-fejl) med den rigtige enkelt- eller flertalsbesked og de rigtige røde badges. `pdo` og `json` er kompileret ind i denne PHP, så de kan ikke fjernes her.

## Mutanter

21 mutanter på kopier af arbejdstræet (git-repos i systemets Temp med kort sti), hver kørt gennem hele `tests/run.sh` med database og med `HSG_TEST_PHP_INI_UDEN_MBSTRING` (nyhedsbrevets lange tests er slået fra i kopierne for tiden). "Kun udvikler" betyder udviklerens commit `ed4dc5c` uden mine tilføjelser.

| # | Mutation | Kun udvikler | Med mine tests |
|---|---|---|---|
| 1 | `mbstring` fjernet fra listen | fanget (111, 112 uden mbstring) | fanget |
| 2 | **lås og tjek af udvidelser vendt i `install.php`** | **overlever** | fanget (117, 4 tjek) |
| 3 | updateren bruger den installerede liste | fanget (121) | fanget |
| 4 | **fallbacken fjernet i `bootstrap.php`** (begge kald) | **overlever** | fanget (116, 5 tjek) |
| 4a | kun `mb_strlen` uden fallback | **overlever** | fanget |
| 4b | kun `mb_strtolower` uden fallback | **overlever** | fanget |
| 5 | **gammel SQL** i hurtigsøgningen | fanget (115 og `Pkkrav`-tjek) | fanget |
| 6 | manglesiden springes over ved POST | **overlever** | fanget (119, 6 tjek: `config.php` skrevet, administrator oprettet) |
| 7 | admin-badge altid grøn | **overlever** | fanget (120) |
| 8 | **`build-release.php` med gammel hårdkodet liste** | **overlever** | fanget (122, 4 tjek) |
| 9 | `@session_start()` i `php_requirements.php` | fanget (111) | fanget |
| 10 | tomme navne springes ikke over | fanget (112) | fanget |
| 11 | flertalsbesked uden " og " | fanget (112, 121) | fanget |
| 12 | manglesiden giver HTTP 500 | **overlever** | fanget (118, 119) |
| 13 | advarselsboksen fjernet på `admin.php` | **overlever** | fanget (120) |
| 14 | gammel updater-tekst | fanget (121) | fanget |
| 15 | updaterens tjek fjernet | fanget (121) | fanget |
| 16 | rækkefølgen ændret (openssl før mbstring) | fanget (111) | fanget (111, 124) |
| 18 | README uden openssl | **overlever** | fanget (124) |
| 19 | `install.php` bruger en hårdkodet liste uden openssl | **overlever** | fanget (kun af den nye test med "mbstring og openssl" mangler) |
| 20 | `admin.php` tjekker kun zip, gd og pdo | **overlever** | fanget (120, 6 tjek) |

Resultat: **12 af 21 overlever udviklerens tests alene, 0 af 21 overlever med mine tests.** Ingen kendt overlevende mutant. Mutanter, der er rent kosmetiske (fx at `h()` mangler om en udvidelses navn, som er en konstant), kan ikke fanges via output og er ikke forsøgt.

## Sikkerhed

- **Manglesiden afslører ingen stier eller PHP-fejl** (tjekket mod `Fatal`, `Warning`, `Notice`, `Deprecated`, `Uncaught`, `Stack trace`, ` on line `, drevbogstaver og `/tmp/`, `/home/`) i alle kørsler, også med listefelter, 9 kB og SQL-tegn i POST.
- **Output:** `install.php`, `admin.php` og updaterbeskeden sender alt dynamisk gennem `h()`. Teksten fra `hsg_missing_extensions_message()` og navnene fra listen står i `h()`. `render_flash()` bruger `h()` til updaterbeskeden.
- **Adgang:** `admin.php` og `update.php` kræver admin (uden login: 401). Direkte linkbruger får aldrig brugere eller ordrer i hurtigsøgningen. Uden login giver hurtigsøgningen aldrig data. `core/php_requirements.php` kaldt direkte over HTTP giver HTTP 200 og 0 bytes.
- **CSRF:** `install.php` og `update.php` uden token giver 419 (med session). Uden udvidelser kommer manglesiden før `verify_csrf()`, som spec'en kræver. Byte-sammenligning med `16cd3d0` viser ingen ændring med alle udvidelser.
- **Databasen:** ingen migration, ingen skema- eller modulændring. Ingen berøring af `config.php`, `.env`, `uploads/` eller `storage/` i arbejdstræet.

## Fund

Ingen SKAL. Ingen kriterier fejlede, så der er ingen fejltrin at genskabe.

**BØR 1. Release-negativtesten kan bestå af den forkerte grund (`tests/run.sh:41-46`, `tools/build-release.php`).** Uden en krævet udvidelse på bygge-maskinen afvises test B og C af `hsg_update_validate_package()` med "Webhotellet mangler PHP-udvidelsen mbstring", og testen siger stadig `ALLE 7 NEGATIVE TESTS (A-G) BESTÅET`. Det er fund 4 i spec afsnit 9, som kriterie 123 skal fange, men `run.sh` kontrollerer kun exit-koden. Reproduceret: `php -c <uden-mbstring> tools/build-release.php --test-negative` giver exit 0. Min nye `tests/php_krav_release_test.php` kontrollerer årsagen (B skal indeholde "Uventet fil", C "Expected SHA-256", ingen af dem "PHP-udvidelse") og kræver alle udvidelser. Rettelsen kan også laves i selve bygge-scriptet, men det er uden for denne opgave.

**BØR 2. Udviklerens tests dækker ikke kriterie 116-120 og 122.** Se "Mutanter": 12 mutanter overlever, bl.a. alle ændringer i `install.php`, `admin.php` og `bootstrap.php`, som kun er synlige uden mbstring. Spec afsnit 7.1 gør dem manuelle. Lukket med `tests/php_krav_tester.sh` og `tests/php_krav_release_test.php` (se "Tilføjede tests").

**KAN 1. Miljøfælde: to `php -S` med forskellige udvidelser på samme filer deler opcache på Windows.** Spec 7.1 trin 4 beskriver at genstarte serveren uden mbstring mod samme kopi. Står den første server stadig, eller lever dens opcache-hukommelse, bruger den nye proces bytekode fra den gamle. Jeg så `curl_init()` blive kaldt som `imagecolorset()` (`Uncaught ArgumentCountError`) og `php -S` uden mbstring gå ned uden svar på `update.php` i samme kopi, mens en fresh kopi virkede. Med `-d opcache.enable=0` forsvandt begge. Det er et testmiljøproblem, ikke en kodefejl, og påvirker ikke produktion. Foreslået tilføjelse til 7.1: kør alle `php -S` med `-d opcache.enable=0`. Mine tests gør det.

**KAN 2. CI nævner hverken mbstring eller openssl (`.github/workflows/tests.yml:27`).** `extensions: pdo, pdo_mysql, zip, json, gd, curl, simplexml`. `setup-php` har normalt begge som standard, men det er ikke verificeret her. Mangler en af dem, stopper røgtestens installation med manglesiden, og pakken bliver rød. Spec afsnit 8 lader #178 ændre filen, så det er bevidst udeladt.

**KAN 3. `verify_csrf()` godtager en tom token uden session (`functions.php:9`), så en POST til `install.php` helt uden cookie og uden `csrf` giver HTTP 200, ikke 419.** Det er fund 3 i spec afsnit 9 og identisk på `16cd3d0`. Med alle udvidelser gik POST'en videre til feltkontrollen (afvist af "Database ... skal udfyldes", intet oprettet). Uden mbstring er det uden betydning, for manglesiden kommer før. Ikke en regression.

**KAN 4. `hsg_missing_extensions()` springer værdier over, der hverken er tekst eller tal (`core/php_requirements.php:25`).** En liste inde i pakkemanifestets `required_extensions` blev før til "PHP-udvidelsen Array mangler" med en `Array to string conversion`-advarsel; nu springes den over uden advarsel. Det er bedre og står i kommentaren, men er en lille adfærdsændring i forhold til "som i dag" i spec 3.3. Manifestet er hash-kontrolleret og kommer fra byggeren, så risikoen er nul.

**KAN 5. Hurtigsøgningen (`core/bootstrap.php`, uden for scope).** (a) `q[]=ab` giver `Array to string conversion` (linje 14), som på `main`; spec afsnit 8 lader den være. (b) JSON-svaret indeholder titler uden HTML-escape (fx et kundenavn med `<script>`). Svaret er `application/json`, ingen side bruger det i dag, og der er ingen fare, men en fremtidig brugerflade skal escape. Begge fandtes før denne ændring.

*Til info (ikke fra grenen):* `tools/build-release.php` skriver `Den angivne sti blev ikke fundet.` og `commit: unknown` på Windows, fordi `shell_exec('git rev-parse HEAD 2>/dev/null')` ikke virker i cmd. Bygget lykkes alligevel.

## Tilføjede tests (kun i `tests/`)

- **`tests/php_krav_release_test.php`** (16 tjek, læses ind fra `tests/run.sh` i trin 3, uden database):
  - Kriterie 122: `build-release.php` indeholder ikke `'curl'`. En rigtig ZIP har `required_extensions` lig `hsg_required_extensions()`, `core/php_requirements.php` med rigtig SHA-256 og ingen dev-filer.
  - Kriterie 123: negativtestens B og C afvises af den tilsigtede grund.
  - Kriterie 124: README-listen er lig `hsg_required_extensions()`.
  - Kræver alle udvidelser og en Git-arbejdskopi, ellers fejler eller springes den over med en klar tekst.
- **`tests/php_krav_tester.sh`** (56 OK-linjer, læses ind fra `tests/run.sh` i trin 4 efter udviklerens røgtest):
  - Med alle udvidelser: Systemstatus (120) og `install.php` i en ren kopi (formular, 419 uden og med forkert csrf, intet oprettet).
  - Hvis `HSG_TEST_PHP_INI_UDEN_MBSTRING` er sat: miljøkontrol, 117 (lås først, GET og POST), 116 (hurtigsøgning uden mbstring), 120 (uden mbstring og med både mbstring og openssl væk), 118 (hele manglesiden, ingen stier) og 119 (fire forfalskede POST'er giver præcis manglesiden, ingen `config.php`, ingen administrator i databasen).
  - Ellers: `[INFO] ... sprunget over`.
  - Den afleder selv en ini-fil uden openssl ved at fjerne `extension=openssl` og springer testen over med en INFO, hvis det ikke kan lade sig gøre.
  - Alle servere startes med `-d opcache.enable=0` (KAN 1).
- **`tests/run.sh`:** to nye, selvstændige linjer (release-testen efter negativtesten, og `source` af `php_krav_tester.sh` efter udviklerens røgtest).
- Testerne fejler under mutanterne i tabellen og er grønne på branchen. Alt er prøvet i en ren kopi uden `config.php`, og scriptene rydder deres egne kopier, servere og ZIP'er.

## Ikke kørt

- **375 px** for `install.php` (118) og kortet på `admin.php` (120): laves separat af projektlederen i en browser. Jeg har kun kontrolleret, at siden bruger de eksisterende klasser (`.card.narrow`, `.badge`, `.flash`) og ingen ny CSS, og at `.badge` ikke har `nowrap`.
- **PHP 8.1** (produktion), MySQL 8.0 og Linux. Især Linux med mbstring i `conf.d` (`PHP_INI_SCAN_DIR=`) er ikke afprøvet.
- **Hentning fra GitHub** og en rigtig opgradering i produktion. Å1 (har webhotellet mbstring i dag) afgør brugeren selv.
- **Et rigtigt webhotel** uden openssl: efterlignet med en ini-fil uden `extension=openssl`.
- Tjek af `h()` på tekster, der kun består af konstanter (kan ikke ses i output).
- Nyhedsbrevets lange tests er ikke kørt i mutantkopierne (de er kørt i fuld pakke, se "Kørsler").

## Tillæg: 375 px (projektleder, 2026-10-24)

Kontrolleret i den indbyggede browser med viewport 375×812 (mobil), mod rene `git archive`-kopier af `91e57a0` på lokale `php -S`-servere (`-d opcache.enable=0`) og testdatabasen på MariaDB 10.4.

| Kriterie | Side og tilstand | Resultat |
|---|---|---|
| 118 | `install.php` uden config.php, flere udvidelser mangler (mbstring, openssl m.fl.) | BESTÅET. `scrollWidth` 375 = viewport, ingen elementer går ud over højre kant. Fejlboksen, forklaringen og listen med OK/Mangler ombrydes pænt. |
| 120 | `admin.php` med alle udvidelser | BESTÅET. Ingen vandret scroll. Kortet viser "✓ Alle aktiveret", "PHP-udvidelser" og listen på to linjer, og der er ingen `flash error`. |
| 120 | `admin.php` på samme installation uden mbstring | BESTÅET. Ingen vandret scroll. Badgen er "🔴 Mangler: mbstring", og advarselsboksen med "… Næste opgradering kan ikke installeres, før det er gjort." ombrydes inden for kortet. |

Dermed er 375 px-delen af kriterie 118 og 120 også bestået.

## Tillæg: retest efter reviewets KAN 1, 3, 4 og 5 (projektleder, 2026-10-24)

Rettelserne står i `2927deb`, `3e4fedb` og `2b7395e` (spec afsnit 11). Revieweren har godkendt dem uden fund. Revieweren kontrollerede også, at en gyldig pakke accepteres af den installerede updater fra `origin/main`, og at en pakke uden `core/php_requirements.php` afvises.

`run-all.sh` på `2b7395e` med `HSG_TEST_PHP_INI_UDEN_MBSTRING` sat:

| Trin | Resultat |
|---|---|
| 1 Syntaks | OK |
| 2 Enhedstest | Nyhedsbrev 143/0, PHP-krav 15/0, uden mbstring 16/0 |
| 3 Release | Negativtest OK, release-test 16/0 |
| 4 Installation og røgtest | Alle OK undtagen det kendte tjek `KAN afmeldingssiden ved databasefejl`, der fejler, fordi MariaDB 10.4 ikke har `RENAME COLUMN`. Det fejler også på main. |
| I alt | 219 OK, 1 kendt FEJL |

Konklusionen er stadig **KLAR**.

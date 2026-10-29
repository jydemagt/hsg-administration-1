# Testrapport: CSRF – et tomt session-token må aldrig godtages

- **Branch:** `feature/csrf-tom-token`, testet på commit `2fbfcf3` (basen er `origin/main` = `8c822fe`, v11.2.0). Testerens supplement er commit `2c0196d`. Spec: `docs/specs/2026-10-24-csrf-tom-token.md`, kriterie 1-19 og afsnittet "Udviklerens noter".
- **Testet:** 28-10-2026, af uafhængig tester. Udviklerens tal er gentaget, ikke brugt som bevis.
- **Miljø:** Windows 11 / Git Bash. PHP 8.5.8 (`php -S`, `display_errors=On`, `E_ALL`) til testpakken og PHP 8.1.2 (XAMPP, ZTS) til enhedstesten og en gentagelse af sort-boks-afprøvningen. MariaDB 10.4.22 (egen instans på port 3327 med egen datamappe, tom database pr. kørsel; XAMPPs egen MariaDB på port 3306 er ikke rørt). `PHPRC` med `mbstring, zip, pdo_mysql, openssl, gd, fileinfo, curl` (XAMPPs PHP 8.1 har zip indbygget).
- **Isolation:** `run.sh` bruger faste `/tmp/hsg-*`-stier, og en anden session kørte tests samtidig. Jeg kørte derfor `run.sh` i git-kloner (`git clone --local`, `git checkout`) i en kort sti i Temp, hvor kun teksten `/tmp/hsg-` i `tests/*.sh` er skrevet om til klonens egen mappe (kontrolleret med `diff`: ingen anden forskel). Arbejdstræet er kun brugt til enhedstesten (ingen skrivning) og til mine commits; `git status` var rent før og efter kørslerne.
- **Ikke testet:** MySQL 8.0 og CI (kriterie 14), Linux, rigtige browsere. Rettelsen har ingen ny skærm og ingen CSS, så der er ingen 375 px-kontrol.

## Konklusion

**KLAR.**

- **Alle 18 kriterier, der kan prøves lokalt, er bestået** (1-13 og 15-19). Ingen SKAL- og ingen BØR-fund.
- **Kriterie 14 (CI med MySQL 8.0) er ikke kørt.** Rettelsen ændrer ingen SQL og ingen databaseadfærd, og samme pakke er grøn på MariaDB 10.4 undtagen det kendte tjek. GitHub Actions på PR'en skal alligevel være grøn før merge.
- **Udviklerens tal passer:** 242 OK og 1 FEJL (det kendte `RENAME COLUMN`-tjek), 12 nye tjek, og med den gamle `verify_csrf()` bliver 9 nye tjek røde (plus det kendte, altså 10).
- **Testene fanger fejlen:** med `functions.php` fra `8c822fe` fejler præcis de tjek, som kriterie 16 nævner (3-7 og 9, og enhedstestens tilfælde 1, 2, 3, 11 og 12).
- **Sort-boks via HTTP:** 133 egne tjek mod en kørende installation på PHP 8.5 (og samme 126 af dem på PHP 8.1), 161 afvisninger med 419, ingen HTTP 500 og ingen PHP-advarsel i serverloggen. Alle de ønskede 419/401-udfald og alle positive flows er som før.
- **Fund:** 4 KAN (se "Fund"), ingen kræver rettelse i produktionskoden. Jeg har tilføjet et supplement i `tests/` (51 tjek).

## Kørsler

| Kørsel | Resultat |
|---|---|
| `bash tests/run.sh` på `2fbfcf3` (klon, tom database `hsg_csrf_test`, `config.php` fandtes ikke før kørslen) | **242 OK, 1 FEJL.** Den ene FEJL er `KAN afmeldingssiden ved databasefejl` (`tests/newsletter_funktionel.sh:224-227`). MariaDB 10.4.22 svarer `ERROR 1064 ... near 'COLUMN token_hash TO token_hash_test'` på `ALTER TABLE ... RENAME COLUMN` (ny i MariaDB 10.5.2). Serverloggen har ingen `Fatal`/`Uncaught`, og `config.php` er slettet igen bagefter. Trin 2 viser `CSRF: 14 bestået, 0 fejlet`. |
| `bash tests/run.sh` på `8c822fe` (main, baseline) | **230 OK, 1 FEJL** (samme `KAN`-tjek). 242 - 230 = 12 nye tjek, som udvikleren melder. |
| `bash tests/run.sh` på `2fbfcf3` med kun `functions.php` sat tilbage til `8c822fe` | **233 OK, 10 FEJL** (9 nye + det kendte). Se "At testene fanger fejlen". |
| `bash tests/run.sh` på `2c0196d` (med mit supplement) | **293 OK, 1 FEJL** (samme kendte). 293 - 242 = 51 nye OK-linjer. |
| `php tests/csrf_test.php` med PHP 8.5.8 (`C:/tools/php85/php`) og PHP 8.1.2 (`C:/xampp/php/php.exe`), med `php.ini` | `CSRF: 14 bestået, 0 fejlet`, exit 0 begge steder. |
| Samme med `php -n` (ingen `php.ini`, ingen udvidelser) | Samme resultat på begge versioner. |
| `php tools/build-release.php --test-negative` | `ALLE 7 NEGATIVE TESTS (A-G) BESTÅET SIKKERT!`, exit 0. |
| Rigtig build: `php tools/build-release.php 11.2.0 --output=<sti>` i en ren klon | `RELEASE BYGGET OG GODKENDT`, 193 filer. |

## Acceptkriterier

| Nr. | Kriterie | Resultat | Bevis |
|---|---|---|---|
| 1 | `php tests/csrf_test.php`: 14 tilfælde, `CSRF: <n> bestået, 0 fejlet`, exit 0, ingen advarsler | BESTÅET | Se "Kørsler": 14 bestået på PHP 8.1.2 og 8.5.8, med og uden `php.ini`. Filen afviser ikke-CLI med 404 (`GET /tests/csrf_test.php` giver 404 på `php -S`). Testens egen skarphed er prøvet med 8 mutanter af `verify_csrf()`, se nedenfor. |
| 2 | Liste i sessionen + `csrf=Array` giver 419 uden advarsel (tilfælde 11) | BESTÅET | Tilfælde 11 er grønt. Mutant M1 (`(string)`-cast af sessionen, som spec'en forbyder) fejler i tilfælde 11 med `Warning: Array to string conversion` og godtagelse af `Array`, og i tilfælde 12. Den gamle kode giver `TypeError` (exit 255) i 11 og 12. |
| 3 | `admin-login.php` uden cookie og uden `csrf`: 419, byte-identisk tekst, ingen række i `lager_login_attempts` | BESTÅET | `run.sh`: `admin-login.php uden cookie og uden csrf afvist (HTTP 419, samme svar som før)` og `login-logikken blev ikke nået (0 loginforsøg for csrf-test)`. Sort boks (`bb3` A): også med **gyldigt** brugernavn og adgangskode giver alle 6 varianter 419, sessionen er bagefter ikke logget ind (`users.php` giver 401), og antallet af loginforsøg er uændret. |
| 4 | Samme med `csrf=` (tomt) | BESTÅET | `run.sh`: `admin-login.php uden cookie med tomt csrf afvist ...` og 0 loginforsøg. Sort boks som ovenfor. |
| 5 | Tom POST (`-X POST`) til `admin-login.php`: 419 | BESTÅET | `run.sh`: `admin-login.php uden cookie og uden body afvist (HTTP 419, samme svar som før)`. Sort boks (`bb1` f) giver samme tekst på `install.php`. |
| 6 | Linksession uden token (302, intet token) + `x=1` til `status.php`: 419, byte-identisk | BESTÅET | `run.sh`: `Direkte link uden at følge viderestillingen giver HTTP 302` og `status.php fra linksession uden token, uden csrf afvist (HTTP 419, samme svar som før)`. Sort boks (`bb3` B): 5 varianter (uden felt, tomt, `csrf[]=x`, forkert, `Array`) giver alle 419. |
| 7 | Anden linksession med `csrf=` (tomt): 419 | BESTÅET | `run.sh`: `status.php fra linksession uden token, med tomt csrf afvist`. |
| 8 | Linksession efter GET af `status.php` + sidens `csrf`: 200 | BESTÅET | `run.sh`: `status.php fra linksession med sidens csrf godtaget (HTTP 200)`. |
| 9 | `install.php` uden cookie og uden `csrf` (`db_name=csrf-test`): 419, ingen `config.php`; derefter normal installation | BESTÅET | `run.sh`: `install.php: POST uden session og uden csrf afvist (HTTP 419), ingen config.php`, derefter `Installation gennemført`. Sort boks (`bb1`): jeg sendte et **komplet, gyldigt installationssæt** (rigtig database, root, administrator) i 13 varianter: ingen cookie med uden felt, `csrf=`, `csrf[]=x`, `csrf=abc`, `csrf[a]=b&csrf[]=c`, tom POST, `csrf=Array`, `csrf=0`; session med token og uden felt, tomt, liste og forkert; session oprettet af en afvist POST + tomt. Alle gav 419 med identisk tekst, `config.php` blev ikke oprettet og databasen havde 0 tabeller efter alle forsøg. Bagefter lykkedes installationen gennem formularen. |
| 10 | Normale browserflows: installation og admin-login | BESTÅET | `run.sh`: `Installation gennemført` og `Admin-login lykkedes`. Sort boks (`bb2`): token (48 tegn) fra GET, POST giver "Installationen er færdig", derefter admin-login giver 302 og `users.php` åbner som administrator. |
| 11 | Uden cookie giver sider bag `auth.php` fortsat 401, ikke 419 | BESTÅET | `run.sh`: `newsletter*.php uden login afvist (HTTP 401)` og `supplier_upload.php ... uden login afvist (HTTP 401)`. Sort boks (`bb3` C): `locations.php`, `products.php`, `status.php`, `users.php`, `reservations.php`, `stock.php` og `newsletter_recipients.php` × 4 varianter (uden felt, tomt, `csrf[]=x`, forkert) = 28 gange HTTP 401. |
| 12 | Ét-klik uden cookie og `csrf`: 200 `Afmeldt`, `via=one_click` | BESTÅET | `run.sh`: `K20`, `Afv2 stoppet modtager` og `K23 modul slået fra` er grønne. Sort boks (`bb4` 6): `List-Unsubscribe=One-Click` som urlencoded og som rå body (`Content-Type: text/plain`) giver `Afmeldt`, HTTP 200, `text/plain; charset=utf-8`, og modtageren står som `unsubscribed/one_click`. En POST uden `One-Click` og uden cookie afmelder ikke (modtageren er stadig `active`). |
| 13 | Afmeldingsformularen: gyldigt token afmelder (K19); uden, tomt eller forkert giver sidens egen 419 (K22) | BESTÅET | `run.sh`: `K19`, `K22` og `K24` er grønne. Sort boks (`bb4` 5): uden felt, tomt, `csrf[]=x` og forkert giver 419 med sidens egen tekst ("Formularen er udløbet"), og modtageren er stadig aktiv. Med sidens token: 200 "Du er nu afmeldt", `unsubscribed/link`. |
| 14 | CI (MySQL 8.0): alle tjek grønne | IKKE KØRT | Kræver GitHub Actions. Ingen SQL er ændret. Lokalt på MariaDB 10.4 er alt grønt undtagen det kendte tjek (se kriterie 15). |
| 15 | Lokalt med MariaDB 10.4: eneste røde tjek er `KAN afmeldingssiden ved databasefejl` | BESTÅET | `run.sh` på `2fbfcf3`: 242 OK, 1 FEJL, og den er det navngivne tjek. Samme FEJL på `8c822fe` (230 OK, 1 FEJL) og på den gamle `functions.php`. Årsagen er eftervist direkte: `ALTER TABLE ... RENAME COLUMN` giver `ERROR 1064` på MariaDB 10.4.22. |
| 16 | Med `functions.php` fra main fejler kriterie 3-7 og 9, og enhedstestens 1, 2, 3, 11, 12 | BESTÅET | Se næste afsnit. Præcis disse fejler, og intet andet end det kendte tjek. |
| 17 | Diff: kun `verify_csrf()` ændret i `functions.php`; `csrf_token()`/`csrf_field()` byte-identiske; `nyhedsbrev-afmeld.php` kun kommentarer; `auth.php` uændret; ingen eksisterende linje i `run.sh` ændret | BESTÅET | `git diff --numstat 8c822fe 2fbfcf3`: `functions.php 1/1`, `nyhedsbrev-afmeld.php 2/2`, `tests/run.sh 53/0` (kun tilføjelser), `tests/csrf_test.php 98/0`, `docs/ARCHITECTURE.md 1/0`, spec 272/0. Linje 7 og 8 i `functions.php` er byte-identiske; resten af filen er identisk, når linje 9 fjernes. For `nyhedsbrev-afmeld.php` er PHP-tokens uden kommentarer og mellemrum identiske (`token_get_all`). `git diff --quiet` på `auth.php`, `session.php`, `admin-login.php`, `install.php`, `admin-setup.php`, `app_version.php`, `hsg-package.json`, `README.txt`, `modules/` og `migrations.php` er stille. |
| 18 | Release-negativtesten er grøn, og `tests/csrf_test.php` fjernes af bygget | BESTÅET | `--test-negative`: alle 7 grønne. Rigtig ZIP (193 filer, 192 i manifestet): ingen sti med `tests/`, `docs/specs`, `.claude`, `.github`, `.git/`, `config.php`, `uploads/`, `storage/data`, `storage/backups` eller `.log`, ingen `csrf_test` i ZIP'en eller manifestet. `functions.php` i ZIP'en er identisk med branchens og indeholder den nye kontrol. `hsg-package.json` i klonen er uændret af bygget. |
| 19 | Session med token fra før opdateringen kan poste med det token | BESTÅET | Tilfælde 6 og 13 i enhedstesten. Sort boks (`bb5`): en admin-session, der blev logget ind og fik sit token mange anmodninger tidligere, poster stadig med det (302) uden nyt login. Et token fra en anden session afvises (419). |

## At testene fanger fejlen (kriterie 16)

`2fbfcf3` med kun `functions.php` sat tilbage til `git show 8c822fe:functions.php` (i en klon, så arbejdstræet ikke er rørt). Dette er de røde tjek i `run.sh`:

| Rødt tjek | Kriterie |
|---|---|
| `Enhedstest af verify_csrf fejlede`: tilfælde 1, 2, 3 (godtaget i stedet for afvist), 11 og 12 (`Uncaught TypeError: hash_equals() ...`, exit 255) | Enhedstest 1, 2, 3, 11, 12 |
| `install.php: POST uden session og uden csrf gav HTTP 200` | 9 |
| `admin-login.php uden cookie og uden csrf gav HTTP 200, ikke 419` og `login-logikken blev nået (1 loginforsøg)` | 3 |
| `admin-login.php uden cookie med tomt csrf gav HTTP 200 ...` og `login-logikken blev nået (2 loginforsøg)` | 4 |
| `admin-login.php uden cookie og uden body gav HTTP 200` | 5 |
| `status.php fra linksession uden token, uden csrf gav HTTP 200` | 6 |
| `status.php fra linksession uden token, med tomt csrf gav HTTP 200` | 7 |
| `KAN afmeldingssiden ved databasefejl` (kendt, uafhængig af rettelsen) | 15 |

De tre nye tjek, der er grønne både før og efter (referencen fra `locations.php`, `302` på det direkte link og kontrollen med token i kriterie 8), er netop kontrollerne. Resultat: 233 OK, 10 FEJL, altså 9 nye røde.

Enhedstestens egen skarphed, prøvet med mutanter af linje 9 i en kopi (kun `tests/csrf_test.php` og `functions.php`):

| # | Mutation af `verify_csrf()` | Enhedstesten |
|---|---|---|
| M0 | Den gamle funktion fra `8c822fe` | fejler i 1, 2, 3, 11, 12 (som spec'en siger) |
| M1 | `(string)`-cast af sessionens værdi | fejler i 11 (advarsel + godtaget) og 12 |
| M2 | `is_string()` på formularens token fjernet | fejler i 8 og 10 (TypeError) |
| M3 | kalder `csrf_token()` først (skriver i sessionen) | fejler i 1-5 og 11 |
| M4 | kortere 419-tekst | fejler i 11 tilfælde |
| M5 | 403 i stedet for 419 | fejler i 11 tilfælde |
| M6 | kontrollerer også GET | fejler i 14 |
| M7 | sammenligningen med `hash_equals` fjernet | fejler i 7 |
| M8 | `empty()` i stedet for `=== ''` | **overlever**, se KAN 2 |

## Sort-boks via HTTP

Scriptene `bb1` til `bb6` (installation, login, auth-sider, positive flows, multipart, `admin-setup.php`) lå i scratchpad og er ikke committet. Det, der egner sig til CI, er flyttet over i `tests/csrf_tester.sh`. En separat kopi af `2fbfcf3` (`git archive`) med `php -S` (`display_errors=1`, `error_reporting=-1`), tom database, alle POST'er med `curl`. Resultater gentaget på PHP 8.1.2 mod en nyinstalleret database.

| Scenarie | Antal tjek | PHP 8.5 | PHP 8.1 |
|---|---|---|---|
| `install.php` før installationen, komplet gyldigt installationssæt, 13 varianter (se kriterie 9) | 13 | 13 bestået | 13 bestået |
| `admin-login.php` med **gyldige** loginoplysninger uden cookie: uden felt, `csrf=`, `csrf[]=x`, `csrf=abc`, `csrf=Array`, `csrf[a]=b` = 419, ikke logget ind, ingen loginforsøg; samt session med token og uden/tomt/liste/forkert | 16 | bestået | bestået |
| `status.php` fra linksession uden token (302 uden `-L`): 5 varianter | 5 | bestået | bestået |
| 7 sider bag `auth.php` uden login × 4 varianter: 401 | 28 | bestået | bestået |
| `locations.php` og `products.php` som admin: uden felt, `csrf=`, `csrf[]=x`, forkert = 419, intet oprettet | 8 | bestået | bestået |
| Linksession uden token mod `locations.php`, `products.php`, `reservations.php`: 419 (før rettighedstjekket) | 3 | bestået | bestået |
| Linksession **med** token mod `locations.php`: 403 (read-only, intet oprettet) | 1 | bestået | bestået |
| Positive flows, specialtegn, lange værdier, reservation, hurtigsøgning, nyhedsbrev (`bb4`) | 35 | 35 bestået | 33 + 2, se note |
| multipart/form-data, `csrf[]` med det rigtige token, gentagne felter, JSON-body, PUT, opgradering (`bb5`) | 17 | 17 bestået | 17 bestået |
| `admin-setup.php` (ældre opgraderingsflow, scratch-database uden administratorer og en linkbruger med rollen `admin`): 4 varianter = 419 og ingen administrator oprettet; med sidens token oprettes administratoren (302) | 7 | 7 bestået | ikke gentaget |

Note til PHP 8.1: to af `bb4`-tjekkene (byte-for-byte kontrol af gemte `æøå`-værdier) fejlede i mit script, fordi min hjælpe-`php` (8.5) blev startet med 8.1's `php.ini`. De gemte bytes er sammenlignet bagefter og er identiske med dem fra PHP 8.5, så der er ingen forskel i produktionskoden.

Serverlog for PHP 8.5-serveren (alle afprøvninger og gentagelser samlet): 417 anmodninger, 161 × 419, 112 × 200, 80 × 401, 57 × 302, 7 × 403, **ingen 500** og ingen `Fatal`, `Warning`, `Notice`, `Deprecated` eller `Uncaught`. PHP 8.1: ingen fejl i loggen.

419-svaret er uændret: `HTTP/1.1 419`, `Content-type: text/html; charset=UTF-8`, brødtekst præcis `Ugyldig formularsession. Genindlæs siden og prøv igen.` (byte-sammenlignet med `cmp` i alle ovenstående kald) og ingen ekstra headere.

### Specialtegn, tomme og meget lange værdier

- `csrf` med mellemrum før eller efter, `%00`, store bogstaver, 47 tegn, token to gange, `null`, `true` og `csrf=<gyldigt>&csrf[]=x`: alle 419. Det gyldige token: 302.
- `csrf` på 100 kB: 419 (ikke 500). `csrf=æøå<script>'`: 419.
- Lokationsnavn med `æøå ÆØÅ <script>alert(1)</script> ' " ; -- %` og det rigtige token gemmes uskadt (kontrolleret med `HEX(name)`), og `locations.php` skriver script-tagget escapet. Tomt navn giver fejl-flash (200, ingen 500). Navn på 20.000 tegn giver ikke 500.
- Reservation fra linkbruger med kundenavn `BB Kunde ÆØÅ <b>` gemmes byte-for-byte.
- AJAX (FormData) mod `image_action.php` og `product_enrich.php`: gyldigt token passerer CSRF-kontrollen (forretningssvar som JSON), uden eller med tomt `csrf` giver 419.
- Andre metoder end POST er uændrede: `PUT` til `locations.php` udfører intet (siden vises som GET), og `GET` er 200.

## Fund

Ingen SKAL og ingen BØR. Ingen kriterier fejlede, så der er ingen fejltrin at genskabe.

**KAN 1. Spec'ens kriterie 15 og 16 og "Grundlag" henviser til `16cd3d0`, men grenen bygger på `8c822fe`.** Udvikleren har noteret det under "Udviklerens noter". Jeg har brugt `8c822fe` (baseline og negativkørsel). `verify_csrf()` er byte-identisk i de to commits. Kun en oplysning.

**KAN 2. Mutant M8 (`empty()` i stedet for `=== ''`) overlever enhedstesten.** De to udtryk adskiller sig kun for tokenet `"0"`, som spec'ens sandhedstabel ikke dækker, og som `csrf_token()` aldrig laver (48 hex-tegn). Ingen handling nødvendig.

**KAN 3. `install.php` med liste (`csrf[]=x`) eller forkert token før installationen er kun prøvet manuelt.** Blok A i `run.sh` har ét tilfælde (uden cookie og uden `csrf`). Efter installationen kan siden ikke nås, så mit supplement kan ikke dække den. De 13 varianter er prøvet i sort boks (se kriterie 9). Den fælles `verify_csrf()` er dækket af enhedstesten for liste og forkert token, så risikoen er lav.

**KAN 4. Mit supplement fanger ikke det oprindelige hul.** Den gamle kode afviser også liste, forkert token, multipart uden token og et 100 kB token (kun tomt og manglende token slap igennem, og det dækker udviklerens blok A, B og C). Supplementet er et regressionsværn mod over-rettelser: det fejler fx med 11 røde tjek, hvis `is_string()` på formularens token fjernes (TypeError, HTTP 200 med fejltekst i stedet for 419).

### Miljønoter (ikke fund)

- Scratchpad-stien er 238 tegn lang. Det giver `tempnam(): file created in the system's temporary directory` og `ZIP kunne ikke oprettes` i PHP på Windows (grænsen er 260 tegn minus filnavnet), og røgtesten fik en række falske røde tjek, da jeg første gang lagde kopierne og `TMP` dér. Kopier og midlertidige mapper ligger derfor i en kort sti i Temp. Det er et miljøforhold, ikke en fejl i grenen.
- `tests/php_krav_tester.sh` bruger `git ls-files` til at lave rene kopier, så kopien af træet skal være et git-repo (en `git archive`-kopi giver `fatal: not a git repository` og røde PHP-krav-tjek).
- `tools/build-release.php` skriver `Den angivne sti blev ikke fundet.` og `commit: unknown` på Windows (`shell_exec('git rev-parse HEAD 2>/dev/null')`). Bygget lykkes alligevel. Kendt fra den forrige rapport.
- `run.sh` bruger faste `/tmp/hsg-*`-stier. To samtidige kørsler (fx to sessioner) kan ødelægge hinandens filer. Det gælder både før og efter denne gren.

## Tilføjede tests (kun i `tests/`, commit `2c0196d`)

- **`tests/csrf_tester.sh`** (51 OK-linjer, læses ind fra `tests/run.sh` i trin 4 efter `php_krav_tester.sh`, kan ikke køres alene):
  - `admin-login.php` med gyldige loginoplysninger uden cookie og med csrf som `csrf[]=x`, `csrf[a]=b`, `csrf[0]=x&csrf[1]=y`, `csrf=forkert`, `csrf=Array`, `csrf=0`: 419 med samme tekst, sessionen er ikke logget ind, og antallet af loginforsøg er uændret.
  - `status.php` fra en linksession uden token med de samme seks varianter.
  - `locations.php` og `products.php` som admin med de seks varianter plus `csrf=` og uden felt, et 100 kB langt token og et token med `æøå<script>'`: 419. Uden login giver de fortsat 401.
  - multipart/form-data (som FormData i AJAX-kaldene): `csrf[]=x`, `csrf[a]=b`, `csrf=forkert`, `csrf=`, uden felt = 419.
  - Ingen afvist POST opretter en lokation. Sessionens eget token virker stadig, urlencoded og multipart (302, lokationen oprettes og ryddes op igen).
- **`tests/run.sh`:** én ny `source`-linje (plus kommentar) efter `php_krav_tester.sh`. Ingen eksisterende linje er ændret.
- Prøvet: 51 OK på branchen, 51 OK mod den gamle `functions.php` (det er et regressionsværn, se KAN 4), og 40 OK og 11 røde mod mutant M2.

## Ikke kørt

- **CI på GitHub (MySQL 8.0, Ubuntu, PHP 8.2)** – kriterie 14.
- **Rigtige browsere.** Rettelsen ændrer ingen HTML. Browserflowene er kun prøvet med `curl` (GET → formular → POST).
- **Hele `run.sh` på PHP 8.1.** Enhedstesten og sort-boks-afprøvningen er kørt på 8.1.2; hele testpakken er kun kørt på 8.5.8.
- **`reports.php`s fem ekstra `verify_csrf()`-kald** (ligger efter `auth.php`'s kald og kan ikke nås uden at have passeret det).

## Reproduktion

```
# Enhedstest
PHPRC=<mappe med php.ini> php tests/csrf_test.php
# Hele pakken, i en klon uden config.php (tom database, egen port)
HSG_TEST_DB_HOST='127.0.0.1;port=3327' HSG_TEST_DB_NAME=hsg_csrf_test HSG_TEST_DB_USER=root HSG_TEST_PORT=8927 bash tests/run.sh
# Kriterie 16: i en kopi
git show 8c822fe:functions.php > functions.php && bash tests/run.sh
```

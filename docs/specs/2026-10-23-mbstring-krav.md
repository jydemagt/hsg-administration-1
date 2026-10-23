# mbstring bliver et krav, og hurtigsøgningen får en fallback

- **Dato:** 2026-10-23
- **Status:** Godkendt af brugeren 2026-10-23. Svarene står i afsnit 0.
- **Branch:** `feature/mbstring-krav`, lavet fra `main` (`16cd3d0`). Linjenumrene passer på `16cd3d0`.
- **Baggrund:** Fundet under PR #178 (fadnummer-udledning). Uden mbstring tæller fallbackene bytes og laver ikke Æ, Ø og Å om til små bogstaver. Derfor giver samme data forskellige resultater på forskellige webhoteller. Spec'en for #178 (afsnit 9 og D2) kunne ikke læses herfra, fordi den ligger på en anden branch. Spec'en bygger derfor på projektlederens verificerede fakta og egen gennemgang af `main`.
- **Ingen databaseændring, ingen migration og ingen modulversion.** App-versionen hæves ved release (afsnit 4).

---

## 0. Åbne spørgsmål (besvaret 2026-10-23)

1. **Å1: Har produktionens webhotel mbstring i dag?** **Svar:** Det tjekker brugeren selv, inden releasen bygges.
   Hvis mbstring mangler, afviser den første opgradering med denne ændring pakken (afsnit 2, flow A). Der sker intet med systemet, men opgraderingen kan ikke installeres, før udvidelsen er slået til.
   **Sådan tjekkes det** uden kodeændring og uden login: åbn `https://<domæne>/<sti>/core/bootstrap.php?action=quick_search&q=a` i en browser.
   - Svarer siden `{"results":[]}`, er mbstring slået til.
   - Er siden blank eller giver fejl 500, mangler mbstring. Slå den til i webhotellets kontrolpanel under PHP-indstillinger, eller bed supporten om det.

   Tjekket virker kun, *før* denne ændring er installeret. Bagefter har filen en fallback, og så svarer den med JSON uanset hvad. Efter installationen viser Systemstatus i stedet, om mbstring er slået til (3.2).
   Risikoen vurderes som lav. mbstring er standard på danske webhoteller. Simply.com skriver fx, at alle standardmoduler er installeret. phpMyAdmin og de fleste WordPress/WooCommerce-opsætninger kræver også mbstring.
2. **Å2: Skal `openssl` også være et krav?** **Svar: Ja.** `openssl` kommer sidst i `hsg_required_extensions()`.
   `install.php` kalder `hsg_access_link_encrypt()` (`core/access_links.php:29`). Uden OpenSSL fejler installationen *efter* tabellerne og administratoren er oprettet, så databasen står halvt installeret. Risikoen for, at en opgradering afvises, er i praksis nul, for uden OpenSSL virker de personlige links heller ikke i dag.
3. **Å3: Hurtigsøgningen er forældreløs.** **Svar:** Den får kun fallbacken, som anbefalet.
   `core/bootstrap.php` bliver ikke inkluderet af nogen fil, og ingen side eller JavaScript kalder `action=quick_search`. Det gælder både på `main` og i alle lokale worktrees. Endpointet kan kun nås direkte som `core/bootstrap.php?action=quick_search`. Det har intet login-krav og ingen vedligeholdelseslås, men hvert resultat er filtreret med `can()`. Om søgningen skal kobles på brugerfladen, sikres med login eller fjernes, afgøres i en separat opgave.
4. **Hurtigsøgningens fallback.** **Svar: Den simple fallback** med samme mønster som resten af koden (3.5). Den skal kun forhindre HTTP 500. Den skal ikke give samme søgeresultat uden mbstring.
5. **D4: Skal installationen stoppe, når en udvidelse mangler?** **Svar: Ja**, bekræftet.

---

## Beslutninger

| # | Beslutning | Begrundelse |
|---|---|---|
| D1 | **Løsning C: krav og fallback.** mbstring og openssl kommer på listen over krævede udvidelser (pakkemanifest, installation, systemstatus og README). De to eneste kald uden fallback (`core/bootstrap.php:15` og `:22`) får en simpel fallback, der kun forhindrer HTTP 500. | **Kun B:** En fallback kan ikke efterligne `mb_strtolower` for hele Unicode, og fallbackene i de andre filer opfører sig fortsat anderledes. #178 viser, at det giver stille forskelle i data. Et klart stop er bedre end forkerte data. **Kun A:** Uploader nogen koden via FTP til en server uden mbstring, giver kaldene i `bootstrap.php` HTTP 500. Fallbacken koster to linjer. |
| D2 | **Én sandhed:** en ny fil `core/php_requirements.php` uden afhængigheder. Den bruges af `tools/build-release.php` (CLI), `install.php`, `admin.php` og `core/updater.php`. | Listen findes i dag kun hårdkodet i `build-release.php:209`. Den nye fil må ikke starte en session, åbne databasen eller skrive output, så den kan bruges fra CLI og før `config.php` findes. Det er en ny fil, så den giver ingen merge-konflikter med PR-kæden. |
| D3 | **Updateren tjekker fortsat den nye pakkes manifest.** Listen i `hsg_required_extensions()` bruges ikke. | `hsg_update_validate_package()` læser `required_extensions` fra `hsg-package.json` *i ZIP'en* (`core/updater.php:108-124`). Det er korrekt, fordi det er den nye kode, der har kravene. Kontrollen kører, før noget ændres: ved "Kontrollér pakke" og ved GitHub-hentning slettes ZIP'en, og ved installation sker kontrollen før backup og filkopiering. |
| D4 | **Installationen stopper**, hvis en krævet udvidelse mangler. Det gælder alle udvidelser på listen. Den advarer ikke bare. (Bekræftet af brugeren.) | Pakkemanifestet kræver allerede `gd` og `curl`. En installation uden dem kan derfor aldrig opgraderes via Opgradering, og det er bedre at sige det før installationen end efter. |
| D5 | **Versionering ved release**, som i de øvrige specs. Hverken migration eller modulversion ændres. | Ingen databaseændring. Ændringen går på tværs af platformen og hører ikke til ét modul. |

---

## 1. Formål

**Administrator** (installation, opgradering og drift på webhotellet) skal kunne stole på, at HSG Administration opfører sig ens på alle webhoteller. Tekst med æ, ø og å, fadnumre og navnematch ved import må ikke give andre resultater, fordi en PHP-udvidelse mangler. Mangler en krævet udvidelse, skal systemet sige det tydeligt på dansk, før noget ændres, og forklare, hvad man skal gøre. Linkbrugere og lagermedarbejdere mærker ingen forskel.

---

## 2. Brugerflow

**A. Opgradering på et webhotel uden mbstring (første release med kravet)**
1. Admin vælger Opgradering og uploader ZIP'en eller henter den fra GitHub.
2. Den *installerede* updater kontrollerer pakken og stopper med den nuværende tekst: **"PHP-udvidelsen mbstring mangler på webhotellet."** Ingen filer, ingen database og ingen backup røres, og ZIP'en slettes.
3. Admin slår mbstring til i webhotellets PHP-indstillinger (README forklarer hvordan) og prøver igen.
4. Fra den efterfølgende release viser updateren den nye, længere tekst fra afsnit 3.3. Den kommer først med, når en version med denne ændring er installeret.

Det samme gælder openssl, hvis den mangler.

**B. Koden uploades via FTP til et webhotel uden mbstring**
1. Systemet kører videre. Hurtigsøgningen bruger fallbacken og giver ikke HTTP 500. Søgeresultatet kan afvige ved æ, ø og å, og det er accepteret.
2. Systemstatus (`admin.php`) viser en rød markering og en dansk forklaring (afsnit 3.2).

**C. Ny installation uden en krævet udvidelse**
1. `install.php` viser "Webhotellet mangler …" og en liste over de krævede udvidelser med deres status. Formularen vises ikke.
2. Admin slår udvidelsen til og trykker **Tjek igen**. Nu vises installationsformularen som i dag.

**D. Normal drift med alle udvidelser:** Alt fungerer som i dag. Det eneste synlige er, at kortet "PHP-udvidelser" på Systemstatus nu viser hele listen.

---

## 3. Skærme

Alle tekster er på dansk, og al output går gennem `h()`. Der genbruges kun eksisterende CSS: `.public`, `.card.narrow`, `.flash.error`, `.badge.green/.red`, `.metric` og `a.button`. Der kommer ingen ny CSS.

### 3.1 `install.php`, når en udvidelse mangler

Rækkefølgen i filen bliver:
1. Låsen "Systemet er allerede installeret" (uændret, `install.php:8`).
2. Tjek af udvidelser.
3. Ved POST: `verify_csrf()` og den eksisterende behandling (uændret).

Mangler der en udvidelse, vises siden herunder ved både GET og POST, og POST-data læses ikke. Siden har ingen formular og ændrer intet, så CSRF ikke er relevant. Med alle udvidelser er siden og CSRF-tjekket uændrede.

```
┌─ Installer HSG Administration ───────────────────┐
│ ┌──────────────────────────────────────────────┐ │
│ │ Webhotellet mangler PHP-udvidelsen mbstring. │ │  .flash.error
│ │ Slå udvidelsen til i webhotellets kontrol-   │ │
│ │ panel under PHP-indstillinger, eller bed     │ │
│ │ webhotellets support om det.                 │ │
│ └──────────────────────────────────────────────┘ │
│ Installationen kan først starte, når alle        │
│ krævede udvidelser er slået til. Intet er gemt,  │
│ og databasen er ikke rørt.                       │
│                                                  │
│ Krævede PHP-udvidelser                    (h2)   │
│ [OK] pdo                                         │  .badge.green
│ [OK] pdo_mysql                                   │
│ [OK] zip                                         │
│ [OK] json                                        │
│ [OK] gd                                          │
│ [OK] curl                                        │
│ [Mangler] mbstring                               │  .badge.red
│ [OK] openssl                                     │
│                                                  │
│ [ Tjek igen ]                                    │  a.button href="install.php"
└──────────────────────────────────────────────────┘
```
- `<title>` og `<h1>` er uændrede: "Installer HSG Administration".
- Listen er `hsg_required_extensions()` i rækkefølge med én udvidelse pr. linje.
- **Mobil:** `.card.narrow` som i dag. Ved 375 px er der ingen vandret scroll.

### 3.2 `admin.php`, kortet "PHP-udvidelser" (i dag `admin.php:73-77`)

```
┌──────────────────────────────┐
│ [✓ Alle aktiveret]           │  .badge.green   (uændret tekst)
│ PHP-udvidelser               │  <strong> (i dag "PHP Udvidelser")
│ pdo, pdo_mysql, zip, json,   │  <small class="muted">, listen fra
│ gd, curl, mbstring, openssl  │  hsg_required_extensions()
└──────────────────────────────┘
```
- **Mangler noget:** Badgen bliver `.badge.red` med teksten `🔴 Mangler: mbstring`. Mangler flere, listes de kommasepareret i listens rækkefølge.
- Under kortgitteret, inde i samme `.card`, vises `<div class="flash error" role="alert">` med beskeden fra `hsg_missing_extensions_message()` efterfulgt af ` Næste opgradering kan ikke installeres, før det er gjort.` Mangler intet, vises boksen ikke.
- Variablerne `$extZip`, `$extGd` og `$extPdo` erstattes af `$missingExt = hsg_missing_extensions()`. Resten af siden er uændret.
- **Mobil:** Gitteret har allerede `auto-fit, minmax(180px,1fr)`. Badgen og listen må ombrydes. Ved 375 px er der ingen vandret scroll.

### 3.3 Fejlbesked i updateren (`core/updater.php:121-124`)

Løkken samler *alle* manglende udvidelser fra pakkens `required_extensions` i manifestets rækkefølge, med `hsg_missing_extensions($manifestListe)`. Den kaster én `RuntimeException`:

> Opgraderingen kan ikke installeres. {hsg_missing_extensions_message($missing)} Intet er installeret eller ændret.

Tomme navne springes over som i dag. Mangler `required_extensions` i manifestet, sker der intet tjek, også som i dag. `update.php` viser beskeden som flash, uændret.

### 3.4 Fællesfunktionerne i `core/php_requirements.php` (ny fil)

Filen indeholder kun `declare(strict_types=1)` og funktionsdefinitioner. Den har ingen `require`, ingen session, ingen database og ingen output.

| Funktion | Kontrakt |
|---|---|
| `hsg_required_extensions(): array` | `['pdo','pdo_mysql','zip','json','gd','curl','mbstring','openssl']` i den rækkefølge. Rækkefølgen er den nuværende plus nye sidst, så `hsg-package.json` ændres mindst muligt. |
| `hsg_missing_extensions(?array $required = null): array` | De navne fra `$required` (som standard `hsg_required_extensions()`), som `extension_loaded()` ikke kender, i samme rækkefølge og som liste (`array_values`). Tomme strenge springes over. |
| `hsg_missing_extensions_message(array $missing): string` | Ved `[]` returneres `''`. **Én:** `Webhotellet mangler PHP-udvidelsen {a}. Slå udvidelsen til i webhotellets kontrolpanel under PHP-indstillinger, eller bed webhotellets support om det.` **Flere:** `Webhotellet mangler PHP-udvidelserne {a, b} og {c}. Slå udvidelserne til i webhotellets kontrolpanel under PHP-indstillinger, eller bed webhotellets support om det.` Navnene står i den givne rækkefølge, adskilt med ", ", og " og " før det sidste. |

### 3.5 Hurtigsøgningen (`core/bootstrap.php:15` og `:22`)

Kaldene får samme fallback-mønster som resten af koden:
- Linje 15: `function_exists('mb_strlen') ? mb_strlen($q,'UTF-8') : strlen($q)`
- Linje 22: `function_exists('mb_strtolower') ? mb_strtolower($q,'UTF-8') : strtolower($q)`

Fallbacken skal kun forhindre HTTP 500 uden mbstring. Den skal ikke give samme søgeresultat som med mbstring. Uden mbstring tæller fx `Å` som 2 tegn, og `ÅBN` laves ikke om til `åbn`. Det er accepteret.

Alt andet er uændret: rettigheder, SQL, JSON-format og håndteringen af ugyldig UTF-8. Filen får ingen nye `require`.

---

## 4. Data

Ingen nye eller ændrede tabeller eller kolonner, ingen migration og ingen modulversion.

**Ved release** (CLAUDE.md, release-tjeklisten), ikke på branchen:
- `app_version.php`, versionslinjen øverst i README og en release-note: "Kræver nu PHP-udvidelserne mbstring og openssl …".
- `hsg-package.json` laves igen med `php tools/build-release.php <version>`. Byggemaskinen skal have alle krævede udvidelser. Ellers afviser `hsg_build_validate_zip()` pakken, fordi den bruger updaterens tjek.

---

## 5. Rettigheder

Rettighederne er uændrede, og der kommer ingen nye capabilities.

| Sted | Adgang |
|---|---|
| `install.php` | Ingen login, som i dag (før installation). Låsen via `config.php` kommer stadig først. |
| `admin.php` | `require_admin()`, uændret. |
| `update.php` | `require_admin()`, uændret. |
| Hurtigsøgningen | Uændret: hvert resultat er filtreret med `can()` (se Å3). |

- Direkte brugerlinks får ingen ny adgang og kan ikke se systemstatus.
- Der er ingen nye administratorhandlinger, og derfor ingen nye audit-hændelser. En afvist pakke ændrer intet og logges ikke, ligesom i dag.

---

## 6. Påvirkede filer

| Fil | Ændring |
|---|---|
| `core/php_requirements.php` | **Ny.** Funktionerne i 3.4. |
| `tools/build-release.php` | `require_once HSG_ROOT.'/core/php_requirements.php'`, og `required_extensions` sættes til `hsg_required_extensions()`. Listen må ikke længere stå hårdkodet. |
| `core/updater.php` | Tjekket og beskeden i 3.3. Filen får `require_once __DIR__.'/php_requirements.php'`. |
| `install.php` | Tjekket og siden i 3.1. |
| `admin.php` | Kortet og advarslen i 3.2. |
| `core/bootstrap.php` | Kun de to fallbacks i 3.5, inline. |
| `README.txt` | Kun afsnittet "KRAV / ANBEFALET PHP" (linje 45-54). Teksten står herunder. |
| `tests/php_requirements_test.php` | **Ny** enhedstest (kriterie 111, 112 og 121), samme stil som `tests/newsletter_test.php`. |
| `tests/php_krav_smoke.sh` | **Ny** røgtest (kriterie 115). `tests/run.sh` source'r den i database-trinet, som nyhedsbrevets filer. |
| `tests/run.sh` | Højst tre små tilføjelser: enhedstesten i enhedstest-trinet, `source` af røgtesten og den valgfrie kørsel uden mbstring (afsnit 7.1). Kæden ændrer også filen, så tilføjelserne holdes som selvstændige linjer. |

**Røres ikke:**
- `core/product_enrichment.php`, `core/supplier_import.php`, `import.php` og `core/newsletter.php`. De ændres i kæden, og #178 D2 bygger på fallbacken i `product_enrichment`.
- `catalog_pdf.php`, `pdf_simple.php`, `system.php`, `backup.php` og `update.php`.
- `.github/workflows/tests.yml`. #178 tilføjer mbstring dér, og setup-php har den som standard.
- Den committede `hsg-package.json` og `app_version.php` (ved release).

**README, afsnittet "KRAV / ANBEFALET PHP" (ny tekst):**
```
- PHP 8.1 eller nyere.
- Krævede PHP-udvidelser: pdo, pdo_mysql, zip, json, gd, curl, mbstring og openssl.
  zip bruges til XLSX og backup, gd til billeder, curl til OneDrive og GitHub-opdatering,
  mbstring til tekst med æ, ø og å (fx fadnumre og navnematch ved import),
  og openssl til personlige adgangslinks.
  Mangler en af dem, stopper installationen og opgraderingen med en dansk besked, før noget ændres.
- SimpleXML og XMLReader (XLSX-import, også i Nyhedsbrev). Følger normalt med libxml.
- HTTPS anbefales stærkt.

Mangler en udvidelse: slå den til i webhotellets kontrolpanel under PHP-indstillinger,
eller bed webhotellets support om det. Prøv derefter igen (Opgradering > Kontrollér pakke,
eller genindlæs install.php). Systemstatus under Administration viser, om alle krævede
udvidelser er slået til.
```

---

## 7. Acceptkriterier

Hvor ikke andet står, køres kriterierne i et miljø med **alle** krævede udvidelser. Lokalt betyder det `PHPRC` med en ini-fil, der også indlæser `gd`, `curl` og `openssl`. Ellers stopper `install.php` røgtestens installation, og det er tilsigtet. "Uden mbstring" betyder fremgangsmåden i 7.1.

**Fællesfunktioner (enhedstest, `tests/php_requirements_test.php`)**

111. **Givet** `core/php_requirements.php`, **når** `php -r "require 'core/php_requirements.php'; echo json_encode(hsg_required_extensions()), session_status();"` køres fra roden, **så** er output præcis `["pdo","pdo_mysql","zip","json","gd","curl","mbstring","openssl"]1`, altså ingen session (`PHP_SESSION_NONE` = 1) og intet andet output.
112. **Givet** `hsg_missing_extensions` og `hsg_missing_extensions_message`, **så** gælder:
    - `hsg_missing_extensions(['pdo','','hsg_test_a','zip','hsg_test_b'])` er lig `['hsg_test_a','hsg_test_b']`, og `hsg_missing_extensions([])` er lig `[]`.
    - I et miljø med alle udvidelser er `hsg_missing_extensions()` lig `[]`. Uden mbstring er den lig `['mbstring']`.
    - `hsg_missing_extensions_message([])` er `''`.
    - `hsg_missing_extensions_message(['mbstring'])` og `hsg_missing_extensions_message(['gd','mbstring','zip'])` er tegn for tegn lig teksterne i 3.4, den sidste med "gd, mbstring og zip". Med `['gd','mbstring']` står der "gd og mbstring".
113. Udgået (brugerens valg: simpel fallback).
114. Udgået (brugerens valg: simpel fallback).

**Hurtigsøgningen**

115. **Givet** en installation og et admin-login (røgtestens cookie-jar), **når** følgende kaldes, **så** svarer alle med HTTP 200 og `Content-Type: application/json; charset=utf-8`, og der står ingen `Fatal|Warning|Uncaught` i svaret eller i serverloggen:
     - `core/bootstrap.php?action=quick_search&q=%C3%85BN`: det JSON-dekodede svar indeholder et resultat med `title` = `Åbn lagerstyring`.
     - `…&q=%C3%85` (ét tegn) giver præcis `{"results":[]}`.
     - Uden cookies giver `…&q=a` præcis `{"results":[]}`.
116. **Givet** samme installation og en server startet uden mbstring (7.1), **når** de tre kald fra 115 gentages med et nyt admin-login, **så** svarer alle med HTTP 200 og gyldig JSON med nøglen `results`, og der er ingen `Fatal|Uncaught` i svarene eller i serverloggen. Indholdet af `results` må afvige fra 115. Testes manuelt.

**Installation**

117. **Givet** et miljø med alle udvidelser, **når** `tests/run.sh` installerer, **så** lykkes installationen som i dag. **Givet** uden mbstring og at `config.php` findes, **når** `install.php` åbnes, **så** vises "Systemet er allerede installeret", fordi låsen kommer først.
118. **Givet** uden mbstring og en ren kopi uden `config.php`, **når** `install.php` hentes med GET, **så** gælder:
     - Svaret er HTTP 200.
     - Siden indeholder "Webhotellet mangler PHP-udvidelsen mbstring." og "Slå udvidelsen til i webhotellets kontrolpanel under PHP-indstillinger".
     - Listen viser mbstring med "Mangler" og de øvrige syv (også openssl) med "OK".
     - Der er et link med teksten "Tjek igen" til `install.php`.
     - Der er intet `name="db_host"` og ingen `<form`.
     - Ved 375 px er der ingen vandret scroll.
119. **Givet** samme miljø og en tom testdatabase, **når** `install.php` modtager en POST med alle installationsfelter udfyldt mod testdatabasen (med eller uden `csrf`), **så** gælder:
     - Svaret er manglesiden fra 118.
     - `config.php` findes ikke bagefter.
     - `SHOW TABLES` i testdatabasen giver 0 rækker.

     Med alle udvidelser og en session, der først har hentet formularen med GET, giver en POST uden `csrf` stadig HTTP 419. Sessionen skal have en token, se fund 3 i afsnit 9.

**Systemstatus**

120. **Givet** admin-login og alle udvidelser, **når** `admin.php` vises, **så** viser kortet "PHP-udvidelser" badgen "✓ Alle aktiveret" og teksten `pdo, pdo_mysql, zip, json, gd, curl, mbstring, openssl`, og der er ingen `flash error` i kortet. **Givet** uden mbstring (manuelt), **så** viser badgen "🔴 Mangler: mbstring", og boksen viser beskeden fra 112 efterfulgt af "Næste opgradering kan ikke installeres, før det er gjort." Ved 375 px er der ingen vandret scroll.

**Opgradering og release**

121. **Givet** en ZIP, der kun indeholder `hsg-package.json` med `product` = `HSG Administration`, `package_type` = `distribution`, `version` = `99.0.0`, `min_php` = `8.1.0` og en `required_extensions`, **når** `hsg_update_validate_package()` kaldes (enhedstest, ZIP i `sys_get_temp_dir()`, slettes bagefter), **så** gælder:
     - `['pdo','hsg_test_a']` giver en `RuntimeException` med præcis teksten `Opgraderingen kan ikke installeres. Webhotellet mangler PHP-udvidelsen hsg_test_a. Slå udvidelsen til i webhotellets kontrolpanel under PHP-indstillinger, eller bed webhotellets support om det. Intet er installeret eller ændret.`
     - `['hsg_test_b','pdo','hsg_test_a']` giver én besked med "PHP-udvidelserne hsg_test_b og hsg_test_a" og "Slå udvidelserne til".
     - `[]` giver *ikke* en besked om udvidelser, men "Pakken mangler den nødvendige fil app_version.php.". Det viser, at pakkens egen liste bruges, ikke den installerede.
122. **Givet** branchen, **når** `php tools/build-release.php --output=<scratch>/hsg.zip` køres (versionen er lig `app_version.php`), **så** lykkes bygget, og `required_extensions` i ZIP'ens `hsg-package.json` er lig `hsg_required_extensions()` (indeholder `"mbstring"` og `"openssl"`). `tools/build-release.php` indeholder ikke længere strengen `'curl'`.
123. **Givet** `php tools/build-release.php --test-negative`, **så** står der "ALLE 7 NEGATIVE TESTS (A-G) BESTÅET". Test B og C skal afvises af den tilsigtede grund (uventet fil og filhash), så logteksten for B og C må ikke indeholde "PHP-udvidelse".

**Øvrigt**

124. **Givet** `README.txt`, **så** er afsnittet "KRAV / ANBEFALET PHP" lig teksten i afsnit 6, og listen af krævede udvidelser er den samme som `hsg_required_extensions()`.
125. **Givet** `bash tests/run.sh`, **så** er den grøn. Enhedstesten kører i enhedstest-trinet, og røgtesten fra 115 kører i database-trinet. Er `HSG_TEST_PHP_INI_UDEN_MBSTRING` ikke sat, skrives `[INFO] Test uden mbstring sprunget over (HSG_TEST_PHP_INI_UDEN_MBSTRING ikke sat)`.
126. **Givet** `git diff --stat main...feature/mbstring-krav`, **så** er disse filer uændrede:
     - `core/product_enrichment.php`, `core/supplier_import.php`, `import.php` og `core/newsletter.php`
     - `catalog_pdf.php`, `pdf_simple.php` og `.github/workflows/tests.yml`
     - `hsg-package.json` og `app_version.php`
127. **Givet** alle nye og ændrede tekster (install-siden, Systemstatus, updater-beskeden og README), **så** er de på dansk.

### 7.1 Sådan testes "uden mbstring" deterministisk

1. Find den ini-fil, testene bruger (`php --ini` med `PHPRC` sat). Kopiér den til scratchpad som `php-uden-mbstring.ini`, og udkommentér `extension=mbstring`. Alt andet beholdes, også `extension_dir` og `session.save_path`.
   - På Linux indlæses mbstring ofte fra `conf.d`. Kør da også med `PHP_INI_SCAN_DIR=` (tom).
   - Er mbstring kompileret statisk ind, kan testen ikke laves på den PHP. Brug i stedet lokal Windows-PHP.
2. **Kontrol af miljøet** (enkelte anførselstegn yderst, så hverken bash eller PowerShell rører `$e`):
   `php -c <ini> -r 'foreach(["mbstring","pdo_mysql","zip","gd","curl","openssl"] as $e) echo $e,"=",extension_loaded($e)?1:0," ";'`
   skal give `mbstring=0 pdo_mysql=1 zip=1 gd=1 curl=1 openssl=1`. Ellers er testen ugyldig.
3. **Enhedstest:** `php -c <ini> tests/php_requirements_test.php` skal være grøn og dække grenen "uden mbstring" i 112. Er `HSG_TEST_PHP_INI_UDEN_MBSTRING=<sti til ini>` sat, kører `tests/run.sh` denne linje selv.
4. **Kriterie 116, 117 (anden del) og 120 (anden del):** Installér først med normal PHP. Stop serveren, og start den igen med `php -c <ini> -S 127.0.0.1:8788 -t .` mod samme kopi og `config.php`. Log ind på ny.
5. **Kriterie 118-119:** Brug en ren worktree uden `config.php` og serveren fra trin 4.
6. Slet bagefter `config.php` i testkopien, ligesom `run.sh` gør (aldrig i en rigtig installation).

---

## 8. Uden for scope

- **Eksisterende fallbacks fjernes eller ændres ikke**, heller ikke selvom mbstring nu er et krav. Det gælder `product_enrichment`, `supplier_import`, `import`, `newsletter`, `catalog_pdf` og `pdf_simple`. #178 D2 bruger `product_enrichment`-fallbacken som bevis, og kæden ændrer flere af filerne.
- **At hurtigsøgningen giver samme resultat uden mbstring** (fx at `ÅBN` matcher `åbn`). Fallbacken skal kun forhindre HTTP 500 (Å4).
- **Hurtigsøgningen** kobles ikke på brugerfladen og får hverken login-krav, vedligeholdelseslås, håndtering af ugyldig UTF-8 eller af `q[]=` (Å3).
- `simplexml` og `xmlreader` kommer ikke på listen over krævede udvidelser.
- Tjek af udvidelser i `restore.php` i FULL-backuppen.
- Minimumsversionen af PHP tjekkes ikke i `install.php`.
- Kortene på `backup.php`, `update.php` og `system.php` ændres ikke.
- CI-ændringer (#178 ændrer `.github/workflows/tests.yml`).
- At den *installerede* updater viser den nye tekst allerede ved første release. Det kan ikke lade sig gøre, fordi den gamle kode laver tjekket (flow A).
- At rette `verify_csrf()` (fund 3 i afsnit 9).

---

## 9. Fund under analysen (til projektlederen)

1. **`core/bootstrap.php` inkluderes ikke på alle sider.** Den inkluderes slet ikke: `auth.php` indlæser den ikke, og intet kalder `quick_search`. Fatal error rammer derfor kun det direkte URL og ingen normal side (se Å3).
2. **`install.php` uden OpenSSL** fejler halvvejs, efter schema og admin er oprettet. Det er løst her: openssl er nu på listen (Å2), så installationen stopper, før databasen røres.
3. **`verify_csrf()` godtager en tom token**, hvis sessionen ikke har nogen token endnu (`hash_equals('', '')` er sand, `functions.php:9`). Risikoen er lav: cookien er `SameSite=Lax`, og næsten alle sider skriver et `csrf_field()`, før der kan postes. Det bør dog have sin egen opgave. Spec'en her bygger ikke på fejlen.
4. **Lokal testkørsel:** Når installationen kræver alle udvidelser, skal den lokale `PHPRC`-ini også indlæse `gd`, `curl` og `openssl`. Ellers stopper røgtestens installation. Testrapporterne til Kims upload (`2026-10-20-kims-upload-review-rettelser.testrapport.md` og `2026-10-22-kims-upload-lagertal-q1.testrapport.md`) viste, at release-negativtest B og C blev afvist af den forkerte grund ("pdo_mysql mangler"). Kriterie 123 fanger det samme for mbstring.

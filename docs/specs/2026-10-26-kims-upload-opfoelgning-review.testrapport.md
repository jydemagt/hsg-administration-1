# Testrapport: Kims uploadfil – opfølgning på review af PR #181 (modul `supplier_upload` 1.2.1)

- **Spec:** `docs/specs/2026-10-26-kims-upload-opfoelgning-review.md` (acceptkriterie 167–191). Tillæg til `2026-10-26-kims-upload-kun-lagerantal.md` (PR #181).
- **Branch:** `feature/kims-upload-opfoelgning-review`, testet på `daab44b` (ændringerne er `e9a8f7c..daab44b`, commits `6557ce1`…`daab44b`). Testerens supplement ligger i et separat commit oven på `daab44b`.
- **Testet:** 28-10-2026, af uafhængig tester. Udviklerens scripts og selvrapportering er ikke brugt som bevis; alt nedenfor er kørt af testeren.
- **Brugerens beslutning** (a) i Å1 er lagt til grund: ved en negativ celle tilbydes arkiveringen, men den er ikke forvalgt. (b) og N3b gælder ikke og er ikke testet.

## Konklusion

**KLAR.** (Tillæg 28-10-2026 om kriterie 192, O4 og tekstrettelserne efter reviewet: **KLAR**, se nederst.)

- **Ingen fejl i produktionskoden fundet.** Alle 25 kriterier (167–191) er bestået, og hvert kriterie er dækket af mindst én test, der fejler, hvis adfærden er forkert (mutationstest, se nedenfor).
- Den fulde testpakke (`bash tests/run.sh`, frisk database) er grøn. Det eneste røde tjek er den kendte baseline-fejl på MariaDB 10.4 ("KAN afmeldingssiden ved databasefejl: HTTP 500 …" i nyhedsbrevstesten), som også fejlede før ændringerne og ikke tæller.
- De tre afvigelser, udvikleren selv har meldt, er efterprøvet og er i orden (afsnittet "Udviklerens afvigelser"). Afvigelse 1 havde et hul i testdækningen (den ugyldige **total** var ikke testet, kun den ugyldige lokation). Det er lukket med nye tests.
- Testeren har tilføjet 190 enhedstjek (ny fil), 146 flowtjek (Q14–Q21 i den eksisterende QA-fil, bl.a. en test af hook-fejl med en ekstra server) og én linje i `tests/run.sh`. Mutationstesten viser, at de fanger fejl, som udviklerens tests ikke fanger (M03, M06, M07, M12, M17, M20, M28 og M29).
- Fire **ikke-blokerende observationer** (O1–O4) står nederst. Ingen af dem ændrer konklusionen.

## Miljø og metode

- PHP 8.5.8 (CLI og `php -S`) med udvidelserne zip, mbstring, openssl, pdo_mysql, gd, curl. **PHP 8.1 (produktionens minimum) er ikke prøvet.** MariaDB 10.4.22 på 127.0.0.1, Windows 11.
- Fuld kørsel: `HSG_TEST_DB_* … bash tests/run.sh` mod en ny, tom database (`hsg_opf_test`). Mutationstestene er kørt i en **kopi** af projektet i scratchpad (`git archive HEAD`, egen database og port), aldrig i worktree'en.
- `config.php`, `config.local.php`, `.env`, `uploads/` og `storage/` er ikke rørt af testeren. Testpakken (både udviklerens og min) lader selv appen skrive previews i `storage/import-previews/`; Q11 (udvikler) og Q17 (tester) lægger og sletter selv en `<40 hex>.json`-testfil dér.
- Der er ikke ændret noget uden for `tests/` og `docs/specs/`. Produktionskoden er uændret.
- **Fejlkilde i testforløbet (kassérede kørsler):** En afbrydelse ramte scratchpad (php.ini til testmiljøet blev slettet) og MariaDB. To tidlige fulde kørsler overlappede og delte `/tmp`-filer, port og `config.php`, så de er **ikke brugt**. Resultaterne nedenfor kommer fra én enkelt `run.sh` på en frisk database, startet efter at alt andet var stoppet, og fra mutationskørsler i en separat kopi.

### Samlede kørsler

| Kørsel | Resultat |
|---|---|
| Basis: `bash tests/run.sh` på `daab44b` (udviklerens tests, før mine tillæg) | Kims enhedstest **653**, DB-test 108 + 123, flowtest **175**, QA-test **147** tjek, alle 0 fejlede. Eneste røde tjek: den kendte baseline-fejl. |
| **Endelig: `bash tests/run.sh` med mine tillæg, frisk database** | Syntaks OK. Nyhedsbrev 143. Kims enhedstest **653**. **Testerens enhedstest 190.** Fadnummer 153. PHP-krav 15 + 16. Release-negativtest OK. DB-test 108 + 123. Flowtest **175**. QA-test **293** (147 + 146 nye). 191 `[OK]`, **1 `[FEJL]`-tjek: den kendte baseline-fejl** (`tests/newsletter_sql.php` på MariaDB 10.4; slutlinjen "TESTS FEJLEDE" skyldes kun den). Smoke-tjekket `reservations.php med status[] og q[] viser standardfanen (HTTP 200)` er grønt. |
| Mutationstest (33 mutanter i produktionskoden, kopi) | Se afsnittet "Mutationstest". **32 af 33 fanges.** Den ene overlevende er symlink-tjekket (M10), som ikke kan prøves på Windows uden administratorrettigheder. |
| Kriterie 177 manuelt | `git check-ignore -v storage/import-previews/x.json` → `.gitignore:7:storage/import-previews/` (exit 0); `storage/import-previews.txt` → exit 1. `git ls-files storage` → kun `storage/.htaccess`. |

## Acceptkriterier

| Nr. | Kriterie (kort) | Resultat | Bevis |
|---|---|---|---|
| 167 | `stock_cell`: `negative`-nøgle | BESTÅET | Test 167 (udvikler) + QO1 (tester, 48 tjek): `-1`, `-1.0`, `-1,0`, `-0,5`, `-0.5`, `-0,50`, `-0,6`, `-1,5`, `-2,4999`, `-99999`, `-99999,9` er negative; `-0`, `-00`, `-0,0`, `-00000`, `-0,49`, `-0,4`, `-0,4999`, `0`, `41` er det ikke; ` -1 `, hårde mellemrum (U+00A0, U+202F) og tabulator omkring minus-tallet giver `text '-1'`. Ugyldige (`- 1`, `−1` (U+2212), `--1`, `-`, `+1`, `-1,234`, `-1.234`, `-1e3`, `-100000`, `(1)`, …) giver præcis de gamle arrays uden `negative`. Heltals-/kommatalsceller fra Excel (`-1`, `-1.5`, `-0.4`) og `null` virker. **M04** (`<=0`) fanges af 31 + 28 tjek. |
| 168 | `location_cells` returnerer `negative` sidst | BESTÅET | Test 168 + QO2: nøglerne er `values, blank, invalid, negative` i den rækkefølge; uden lokationskolonner er alle fire tomme; en negativ celle i en umappet kolonne ignoreres; negativ + ugyldig i samme række fordeles rigtigt; teksten afkortes til 40 tegn + "…" (**M06** fanges). |
| 169 | Eksempel A med negativ lokation (`add`) | BESTÅET | Test 169: `archive` = `spog_archive()` med `default` falsk, ingen `stock_loc_3`, `selected === (count(changes) > 0)`, `flags = []`, N1 + N3a, præcis én N1. **M01** (`!$hasNegative` fjernet) fanges af 8 + 9 tjek, **M05** (N3a udeladt) af 4 + 3. |
| 170 | Samme med `none` | BESTÅET | Test 170 + QO3f (`merge_opts(false)` i tilstand T: `archive.default` falsk, advarsel om reserveret, lager 2 → 0 uden `archived`). |
| 171 | Negativ "Lagerantal i alt", L og T | BESTÅET | Test 171 (begge tilstande, ingen intern nøgle i `source`/`changes`, også uden fallback og for nyt produkt) + QO3c (L: `-5`; `-1` med sum 3 giver `stock_mismatch` og stadig N2) + QO3f (T med flere rækker: `-1`+`3` og `3`+`-1` giver total 3, `total_negative '-1'`, N2, ingen arkivering; T nyt produkt og T uden fallback). **M08** (total registreres ikke) og **M09** (intern nøgle lækker) fanges. |
| 172 | Fletning og upåvirkede varer | BESTÅET | Test 172 + QO3d (første negative tekst vinder uanset rækkefølge; negativ i midterste af tre rækker; bogstavvarianten `50-1a` med negativ celle, begge rækkefølger; `-1`+`3` giver 3 på Gert og N1 bevares) + QO3e (**tester**: ugyldig + negativ **total** i begge rækkefølger og begge tilstande: `total_invalid` vinder, `total_negative` er `null`, ingen N2). **M02** fanges af test 172, **M03** (total_negative ikke nulstillet) kun af QO3e (8 tjek), **M07** (sidste tekst vinder) kun af QO3d. QO3h: JSON-runderejse af previewet er identisk, og nøglerne er altid med. |
| 173 | Flow Q10, negativ celle, `add` | BESTÅET | Q10 (udvikler) + Q19 (tester, 19 tjek): `-0,5` og `" -1 "` (hårde mellemrum) giver N1 og N3a og felter uden `checked`; `-0` giver ingen N1 og forvalgt arkivering som før; oversigten siger "… på varer, der kræver din vurdering, er ikke valgt"; Anvend uden `archive_res[]` arkiverer intet; med `archive_res=[…]` arkiveres kun de afkrydsede. **Tilstand T** (`Lagerbeholdning = -1`, reservationer på første lokation): N2 + N3a, begge felter uden `checked`, uden afkrydsning er lageret uændret (0 + 2), med begge afkrydset er lageret 0 og begge arkiveret. |
| 174 | Oprydningsfunktionen | BESTÅET | Test 174 + QO5a/QO5b (tester, 27 tjek): 24-timers-grænsen (86.402 s slettes, 86.395 s bevares, **præcis 86.400 s bevares**, mtime i fremtiden bevares), skæve filnavne (skjult, 39/41 tegn, `.JSON`, `.json.json`, mellemrum, uden endelse, `.gitkeep`, `.htaccess`) røres ikke, undermappe med gyldigt navn røres ikke, ikke-mapper/tom streng/NUL-byte/mappe der ikke findes giver 0 uden undtagelse, en skrivebeskyttet fil, der ikke kan slettes, stopper ikke løkken (de andre slettes, `error_log` skrives, returværdi = antal faktisk slettede). Manuelt (Windows ACL `deny RD` på mappen): 0, ingen undtagelse. **M11, M12, M13** fanges. **M10** (symlink) kan ikke prøves her, se "Ikke testet". |
| 175 | Upload rydder op (Q11) | BESTÅET | Q11 + Q17 (tester): en anonym upload, en upload uden CSRF, med forkert CSRF og med `csrf[]` (alle afvist: 401/419) rydder **ikke** op; en gyldig upload gør. (Stat-cachen i testprocessen ryddes før hvert tjek – ellers kunne en sletning gå ubemærket hen.) **M22** (kaldet fjernet) fanges. |
| 176 | Backup | BESTÅET | Test 176 + QO6a/QO6b (tester): `storage/import-previews/…`, `storage\import-previews\…`, `/storage/import-previews/…` og undermapper udelades; `storage/import-previews.txt`, `…json`, `-old/`, `storage/data/import-previews/`, `uploads/storage/import-previews/` og `storage/.htaccess` kommer med; de øvrige udelukkelser er uændrede. **Rigtig ZIP** via `hsg_add_site_to_zip` på et midlertidigt site: previews, backups, tmp, `config.php` og `.env` er ikke med; FULL har præcis 6 filer, DATA kun uploads og `storage/data`. **M32, M33** fanges (2 + 6 og 1 + 5 tjek). |
| 177 | `.gitignore` | BESTÅET | Test 177 + manuel kontrol (se tabellen over kørsler). |
| 178 | `product_<i>='0'` (Q8) | BESTÅET | Q8 (udvikler, `'0'` og `' 0 '`, kontrol uden felt) + Q18 (tester, 65 tjek, begge scopes): `product_<i>[]=`, `[]=<id>`, `[x]=<id>` (liste), `-5`, `abc`, `99999999999999999999`, `0x1F`, `0.0`, `+0` og NBSP-omsluttet `0` springer rækken over uden fejl-flash, uden oprettelse, uden lagerændring og uden arkivering; en anden vare som værdi skriver lageret på den vare, men arkiverer aldrig previewets reservationer. **M21** fanges. |
| 179 | `product_<i>='0'` (Q9, Produktdata og lager) | BESTÅET | Q9 + Q18 (`full`). |
| 180 | Gæt uden blokering (4a) | BESTÅET | Test 180 + QO4: delvise match er stadig blokeret (`Kundenummer` bliver ikke varenummer). **M16** fanges. |
| 181 | Redning af husket "Ignorer" (4b, hændelsen) | BESTÅET | Test 181 + QO4 (tester): kun varenummer husket som Ignorer og ingen navnekolonne → `Varenr.` reddes; kun produktnavn husket og ingen varenummerkolonne → `Variant:` reddes; dubletter (`varenr`/`varenr|2`) → første kolonne vinder; `allowedFields` uden sku/name reddes ikke; sku allerede valgt på en anden kolonne blokerer; kun præcise alias redder (`Varenummer (Kim)` gør ikke); `  VARENR ` og `VARIANT` normaliseres; `fadnr` husket som Ignorer redder `Varenr.`; `reguess` giver aldrig restored. **M14** (redning slået fra), **M19** og **M20** fanges, **M17** (restored uden husket-tom-tjek) kun af tester. |
| 182 | Ingen redning når anden nøglekolonne er valgt | BESTÅET | Test 182 + test 6/1/8 uændret grønne. **M15** (fadnummer tæller ikke) fanges af 2 tjek. QO4 dokumenterer også, at `Varenr.` (husket Ignorer) forbliver Ignorer, når `Variant:` er ny og gættes (spec: "ellers respekteres Ignorer"). |
| 183 | Flow: hændelsen | BESTÅET | Q12 (udvikler) + Q14, Q15 (tester): `col_mapping`, `col_key_restored`, panel åbent, T3 ×2, "Anvend", ingen T1/T2; uændret efter `reservation_mode` og `import_scope`; `[]` efter `remap` og efter **`reguess`** (Q14, ikke dækket af udvikleren); det gemte valg har `varenr → sku`. **Ingen lækage:** efter at valget er rettet, har upload B ingen T3, et lukket panel og "Anvend"; preview A viser stadig sin egen T3; siden uden token viser ingen. **M18, M25, M26, M27, M31** (se HTTP-mutanter) fanges. |
| 184 | Flow: tomt preview | BESTÅET | Q12 (K1: `remap` med Ignorer på begge, også efter `import_scope=stock_only`; K2: nøglekolonner tomme) + Q16 + Q21 (tester): bevidst Ignorer på begge respekteres (T1, ingen T3), ændres ikke af `reservation_mode`/`import_scope` på det tomme preview, **næste upload redder dem igen** (som spec: "Genberegn gemmer stadig et manuelt valg uden nøglekolonner. Næste upload redder det"), og **"Gæt kolonner forfra" på det tomme preview giver rækkerne tilbage** (T1 forsvinder, "Anvend" kommer). Q21: kun fadnummer valgt og tomt i alle rækker giver **T2, ikke T1**, i begge scopes. **M29, M30** fanges. |
| 185 | Vagten i Anvend | BESTÅET | Q12: flash "Kunne ikke anvende ændringer: Previewet har ingen rækker, der kan anvendes.", ingen ny `hsg_supplier_import_runs`, ingen ny `supplier_import.apply`, `supplier_import_header_map` uændret. **M24** fanges. Q17: `apply` uden login (401), uden CSRF og med forkert CSRF (419). |
| 186 | KAN-1 `reservations.php` | BESTÅET (med afvigelse 2) | Smoke-tjekket i `run.sh` er grønt. Manuelt: `status[]=archived&q[]=x`, `status=archived&q[]=x`, `status[]=archived&q=x`, `status[a][b]=1&q[a][b]=1`, `product[]=1` svarer 200 uden advarsel, og `status=<script>`/`q=<script>…` er escapet (0 forekomster af `<script>alert`). **Mutationsbevis:** med `reservations.php` fra `e9a8f7c` giver `q[]=x` linket `reservations.php?q=Array` og `class="button secondary"` på "Alle reservationer", så smoke-tjekket ville fejle – også med `display_errors=Off`, hvor "Warning:" ikke ses (tab-tjekket tager over). |
| 187 | KAN-2 dobbelte rækker | BESTÅET | Q13: `apply_rows=[i,i,j,j]` arkiverer én gang, `reservations_archived 1`, `rows_skipped 1`, ingen "blev ikke arkiveret", lageret skrives én gang. **M23** fanges. |
| 188 | KAN-3 hook efter commit | BESTÅET | Kriteriet var "kontrolleres ved review", fordi intet modul lytter. Testeren har lavet en test (Q20): en ekstra `php -S` med `auto_prepend_file=tests/hook_throw_prepend.php`, der lægger en hook på `reservation.archived`, som altid kaster. Resultat: reservationerne er arkiveret, importkørslen registreret, success-flashen "2 reservationer (2 stk.) er arkiveret" vises, ingen fejl-flash, og fejlloggen har en linje **pr. reservation** ("hooken reservation.archived fejlede for reservation #…: QA: hooken fejler med vilje"), så den første kastende hook ikke springer den anden over. **M28** (try/catch fjernet) fanges. |
| 189 | Bevidst ændrede tjek | BESTÅET | `git diff e9a8f7c..daab44b` på testfilerne: `supplier_import_test.php` er kun ændret i test 47 og 65 (`'negative'=>[]`, og `'-2'` → `['stock_loc_1'=>'-2']`) samt de nye cases; `product_enrichment_test.php:141` og `supplier_import_db_test.php:403` kun versionen `1.2.1`. `supplier_upload_flow_test.php` er **ikke** ændret og er grøn (175 tjek), så punkt 4 har ikke ændret tjek 5/30. |
| 190 | Versioner | BESTÅET | `modules/supplier_upload/module.php`: `1.2.1`; `modules/reservations/module.php`: `1.3.0` (uændret); ingen `migrations.php` i `modules/supplier_upload/`; `app_version.php`, README og `hsg-package.json` er ikke rørt (hæves ved release). `supplier_import_db_test.php` tjekker `hsg_module_versions.supplier_upload = 1.2.1` på en frisk installation (grøn). |
| 191 | Testpakken er grøn | BESTÅET | Se "Samlede kørsler": kun den kendte baseline-fejl er rød. |

## Udviklerens afvigelser

1. **En lokation, der er ugyldig i én flettet række, kommer ikke i `stock_file.negative` (tilsvarende `total_negative` ved ugyldig total).** **I orden.** Koden (`core/supplier_import.php:709` og `:716`) følger den eksisterende regel, at en ugyldig celle i blot én af de flettede rækker fjerner lokationen (`unset($src[$k])`) eller totalen: tallet bruges slet ikke, så en bemærkning om, at det "behandles som 0", ville være forkert. Arkiveringen påvirkes ikke, fordi `stock_file_is_zero` allerede er falsk ved en ugyldig celle/total. Test 172 dækker kun **lokations**varianten. **Totalvarianten var ikke testet** (mutant M03 overlevede udviklerens tests). Testeren har lukket hullet med QO3e (begge tilstande, begge rækkefølger). Konklusion: afvigelsen er i overensstemmelse med spec'ens hensigt (ingen celle både "behandles som 0" og "ændres ikke").
2. **Fanen "Aktive" findes ikke; standardfanen hedder "Alle reservationer".** **I orden, og spec'en bør rettes.** `reservations.php:151–155` har fanerne "Alle reservationer", "Reserverede", "Solgt / Afsluttet", "Annulleret" og "Arkiveret". Der er ingen "Aktive". Smoke-tjekket i `run.sh` tester den rigtige fane og kan faktisk fejle (se kriterie 186). Teksten "Aktive" i kriterie 186 er en skrivefejl i spec'en.
3. **Ekstra: `hsg_supplier_preview_cleanup` springer symlinks over; Q8 tester også `' 0 '`.** **I orden.** Spec'en siger "sletter kun almindelige filer", og `is_file()` følger symlinks, så `is_link()`-tjekket er nødvendigt for at overholde den (en symlink med et gyldigt navn kunne ellers få en fil uden for mappen slettet). Symlink-testen i QO5b kører kun, hvor systemet tillader symlinks. `' 0 '` er netop det, spec'en kræver ("også `' 0 '` efter trim", kriterie 178).

## Testerens supplerende tests

Alle kører fra `tests/run.sh` og rydder op efter sig selv (midlertidige filer under `sys_get_temp_dir()`, testdata med præfikset `TSTKIM`, indstillinger sat tilbage).

**Ny fil `tests/supplier_opfoelgning_qa_test.php` (enhedstest, 190 tjek, ingen database/server):**

| Case | Dækker |
|---|---|
| QO1 | Grænsetilfælde for negative celler (decimaler, `-0`, `-00`, hårde mellemrum, ugyldige varianter, ikke-tekst). |
| QO2 | `location_cells`: flere negative, nøglens placering, umappede kolonner, negativ + ugyldig, afkortet tekst. |
| QO3a–h | Fletning i L og T: flere negative celler, N3a kun én gang, vare uden reservationer, lang tekst, negativ total (+ mismatch), første tekst vinder, bogstavvarianter, ugyldig + negativ total i begge rækkefølger, T med flere rækker, "brug dem uændret", nyt produkt, uden fallback, Kun lagerantal, uden lagerkolonner, JSON-runderejse. |
| QO4 | Redning: kun sku eller kun name husket som Ignorer, dubletter, `allowedFields`, delvise match, normalisering, `reguess`. |
| QO5a–b | Preview-oprydning: præcis 24 timer, skæve filnavne, mtime i fremtiden, negativ grænse, skrivebeskyttet fil, ikke-mapper, undermappe, symlink (hvis muligt). |
| QO6a–b | Backup-udelukkelsen på stier og på en rigtig ZIP (FULL og DATA). |

**Tilføjet til `tests/supplier_archive_qa_test.php` (flow via HTTP, +146 tjek):** Q14 (reguess nulstiller restored), Q15 (ingen lækage mellem uploads), Q16 (bevidst Ignorer + vejen ud), Q17 (afvisning uden login/CSRF, oprydning kan ikke udløses af andre), Q18 (`product_<i>` som liste og skæve tal), Q19 (negative tal i hele flowet, L og T), Q20 (hook der kaster, egen server), Q21 (T2 ved kun fadnummer). Hjælpefilen `tests/hook_throw_prepend.php` bruges kun af Q20.

**`tests/run.sh`:** kalder den nye enhedstest efter de øvrige Kims-enhedstests.

## Mutationstest

33 bevidste fejl i produktionskoden, hver i en frisk kopi, kørt mod udviklerens enhedstest (`supplier_import_test.php`), testerens enhedstest og (for HTTP-mutanterne) QA-testen. Tallene er antal fejlende tjek.

| Mutant | Udvikler (enhed) | Tester (enhed) | HTTP (QA) | Resultat |
|---|---|---|---|---|
| M01 `!$hasNegative` fjernet fra `safeMatch` | 8 | 9 | – | fanget |
| M02 negative fjernes ikke ved ugyldig lokation | 1 | 0 | – | fanget (kun af udvikler) |
| M03 `total_negative` nulstilles ikke ved ugyldig total | 0 | 8 | – | fanget (**kun af tester**) |
| M04 `-0` regnes som negativ | 31 | 28 | – | fanget |
| M05 N3a udeladt | 4 | 3 | – | fanget |
| M06 negativ tekst afkortes ikke | 0 | 2 | – | fanget (**kun af tester**) |
| M07 sidste negative tekst vinder | 0 | 2 | – | fanget (**kun af tester**) |
| M08 negativ total registreres ikke | 10 | 17 | – | fanget |
| M09 intern nøgle lækker til `source` | 1 | 5 | – | fanget |
| M10 oprydning følger symlinks | 0 | 0 | – | **overlever** (symlinks kan ikke oprettes på denne maskine; QO5b skipper) |
| M11 oprydning: regex med `/i` | 2 | 2 | – | fanget |
| M12 oprydning: `>` i stedet for `>=` | 0 | 1 | – | fanget (**kun af tester**, grænsetjekket på præcis 24 timer) |
| M13 oprydning ignorerer alder | 2 | 5 | – | fanget |
| M14 redning slået fra | 6 | 7 | – | fanget |
| M15 redning ignorerer fadnummer-betingelsen | 2 | 0 | – | fanget (kun af udvikler) |
| M16 4a: sku/name blokeres i gennemløb 1 | 1 | 2 | – | fanget |
| M17 `restored_key_columns` tjekker ikke husket tom | 0 | 2 | – | fanget (**kun af tester**) |
| M19 redning kan bruge et allerede brugt felt | 2 | 1 | – | fanget |
| M20 redning ignorerer tilladte felter | 0 | 3 | – | fanget (**kun af tester**) |
| M32 backup udelukker ikke previews | 2 | 6 | – | fanget |
| M33 backup-præfiks uden skråstreg | 1 | 5 | – | fanget |
| M21 `product_<i>='0'` falder tilbage til matchet | 0 | 0 | 16 (Q8, Q9) | fanget |
| M22 upload rydder ikke op | 0 | 0 | 2 (Q11, Q17) | fanget |
| M23 `apply_rows` dedupliceres ikke | 0 | 0 | 2 (Q13) | fanget |
| M24 vagten mod tomt preview fjernet | 0 | 0 | 3 (Q12) | fanget |
| M18 `recalculate_preview` nulstiller ikke `col_key_restored` | 0 | 0 | 4 (Q12, Q14) | fanget |
| M25 `reservation_mode` bevarer ikke `col_key_restored` | 0 | 0 | 2 (Q12) | fanget |
| M26 `import_scope` bevarer ikke `col_key_restored` | 0 | 0 | 1 (Q12) | fanget |
| M27 panelet åbner ikke ved `col_key_restored` | 0 | 0 | 2 (Q12, Q14) | fanget |
| M28 hook uden `try/catch` | 0 | 0 | 4 (Q20) | fanget (**kun af tester**) |
| M29 T1 ignorerer fadnummer | 0 | 0 | 2 (Q21) | fanget (**kun af tester**) |
| M30 tom tabel og "Anvend" vises stadig | 0 | 0 | 13 (Q12, Q16, Q21) | fanget |
| M31 T3 vises ikke ved reddet kolonne | 0 | 0 | 5 (Q12, Q14, Q15, Q16) | fanget |

M01, M05 og M14 er allerede fanget af enhedstestene, så deres HTTP-kørsel er ikke afventet.

(M34, `reservations.php` uden KAN-1-rettelsen, er afprøvet manuelt og står under kriterie 186. M10 er den eneste mutant, der overlever; M12 overlevede udviklerens tests, indtil testeren tilføjede grænsetjekket på præcis 24 timer.)

## Kanttilfælde ud over udviklerens tests

| Kanttilfælde | Resultat |
|---|---|
| Negative tal med decimaler og mellemrum | Se QO1 og Q19. `-0,5` rundes væk fra 0 til −1 og er negativ (N1 viser `-0,5`); `-0,49` og `-0` er det ikke. |
| Negativ total i tilstand T med flere rækker | `-1`+`3` og `3`+`-1` giver total 3, N2 og ingen arkivering; `-1`+`?` og `?`+`-1` giver kun den ugyldige total (QO3e/QO3f). |
| Preview-oprydning, præcis 24 timer | Bevares (kun *ældre end* grænsen slettes); 2 sekunder over slettes. |
| Preview-oprydning, ulæselig mappe | Windows-ACL `deny RD` på mappen: returnerer 0, ingen undtagelse, **ingen** `error_log`-linje (O2). Uden for en mappe (en fil, NUL-byte, tom streng) → 0. |
| `product_<i>` som liste | Springes over uden fejl (Q18, begge scopes). |
| Redning, kun sku ELLER kun name huskes som Ignorer | Reddes kun, hvis den anden nøglekolonne ikke findes; ellers gættes den anden (4a) og "Ignorer" respekteres (QO4). |
| `remap` med bevidst Ignorer på begge | Respekteres, giver T1 (Q12 K1, Q16). Ændres ikke af `reservation_mode`/`import_scope`. Næste upload redder dem igen. |
| `col_key_restored` lækker ind i en anden upload | Nej (Q15). |
| Backup rammer `storage/import-previews.txt` eller lignende | Nej (QO6a/b). |
| Release-ZIP | `tools/build-release.php` bruger `git ls-files`; previewmappen er ikke sporet og ignoreret, så previews kommer aldrig med (negativtesten i `run.sh` er grøn). |
| POST uden CSRF / uden login | `upload`, `remap`, `apply`, `reguess`, `reservation_mode`, `import_scope`: 419 uden/med forkert token, 401 uden login (Q17). Direkte linkbrugere: Q5 (udvikler) uændret grøn. |
| Specialtegn | N1/N2 indeholder kun tal, der har bestået tal-mønstret, og alt output går gennem `h()`. T1/T2/T3 er faste tekster. `reservations.php?status=<script>` er escapet. |
| Mobilbredde | De ændrede sider er ikke lagersider. De nye elementer (kortet "Der er ingen rækker at anvende.", de amber bemærkninger) bruger eksisterende klasser og ingen faste bredder; ikke prøvet i en browser (se "Ikke testet"). |

## Observationer (ikke-blokerende)

- **O1 – `supplier_upload.php:50`** (ældre kode, ikke en del af denne ændring): `$token=trim((string)($_GET['preview']??…))`. `supplier_upload.php?preview[]=x` giver `Warning: Array to string conversion` (siden svarer stadig 200, og tokenet bliver tomt). Samme klasse som KAN-1. Skjules af `display_errors=Off`. Forslag: `is_string(...)`-tjek i en senere rettelse.
- **O2 – `core/supplier_import.php:1036`:** `@scandir($dir)?:[]` skriver intet i fejlloggen, når mappen ikke kan læses, selv om spec'en siger, at fejl skrives med `error_log`. Oprydningen kaster ikke og er harmløs, men en permanent læsefejl ville aldrig blive set. Lav prioritet.
- **O3 – `core/supplier_import.php:135`:** negativitet afgøres med float-afrunding, så `-0,49999999999999999` (17 niner) regnes som negativ (floaten bliver −0,5), mens matematisk afrunding giver 0. Værdien er 0 i begge tilfælde; det påvirker kun, om N1 vises, og om arkiveringen er forvalgt. Urealistisk i en Excel-fil.
- **O4 – oversigtslinjen i `supplier_upload.php` (ca. l. 432; rettet i `33b89ed`, se tillægget nederst):** når alle arkiveringer er ikke-forvalgte (fx på grund af negative tal), står der "Arkiveres: 0 reservationer (0 stk.) på 0 varer, fordi Kims lager i alt er 0 … N reservationer (N stk.) på varer, der kræver din vurdering, er ikke valgt." Den første halvdel er misvisende, men er den eksisterende tekst for alle varer, der kræver vurdering (kriterie 135), og ikke noget nyt i denne ændring. Kosmetisk.
- **Spec-rettelse:** kriterie 186 skal sige "Alle reservationer" i stedet for "Aktive" (afvigelse 2).

## Ikke testet

- **PHP 8.1** (kun 8.5.8). Koden bruger ikke konstruktioner nyere end 8.0.
- **MySQL** (kun MariaDB 10.4.22).
- **Symlink-tjekket i oprydningen** (M10): kræver administratorrettigheder på Windows. QO5b kører det automatisk på Linux/macOS og på Windows med udviklertilstand.
- **Filnavne med linjeskift** (`<hex>.json\n`, som `\z` i regex'en afviser): kan ikke oprettes på Windows.
- **Ulæselig mappe** er kun prøvet manuelt (Windows-ACL), ikke automatisk.
- **Kims rigtige fil** (`lager 19-10-2026.xlsx`) findes ikke på maskinen; hændelsen er genskabt med syntetiske data i samme kolonnestruktur (Q12/Q14).
- **Browser og mobilbredde:** HTML'en er kontrolleret (klasser, `open`-attribut, checkbox-markup), men siden er ikke åbnet i en browser.
- **Variant (b)/N3b** er ikke testet (brugeren valgte (a)).

## Tillæg 28-10-2026: kriterie 192 (O4) og tekstrettelser efter review

**Konklusion for tillægget: KLAR.** Ingen fejl fundet. Produktionskoden er ikke rørt af testeren.

- **Testet:** `33b89ed` (oversigtslinjen og paneltekst i `supplier_upload.php`, testtrin i Q8 og Q10), `8da293f` (docs og kriterie 192 i afsnit 9 i spec-tillægget) og `9af3c88` (præcise knapnavne i `docs/SUPPLIER-UPLOAD.md`), oven på reviewerens godkendte `560117a`. Den eneste kodeændring er i `supplier_upload.php`: oversigtslinjen (l. 386–387) og paneltekst (l. 410).
- **Fuld kørsel** (`bash tests/run.sh`, frisk database, port 8793, én enkelt run.sh): 191 `[OK]`, og den **eneste** røde er den kendte nyhedsbrevsfejl ("KAN afmeldingssiden ved databasefejl: HTTP 500 …"). Kims enhedstest 653, testerens enhedstest 190, DB-test 108 + 123, flowtest 175, QA-test **328** (var 293: +4 fra udviklerens trin i Q8/Q10, +22 fra Q22, +9 fra Q23). Smoke-tjekket for `reservations.php?status[]=…&q[]=…` er grønt.

### Kriterie 192: oversigtslinjen "Arkiveres: …"

| Tilstand | Forventet | Resultat | Bevis |
|---|---|---|---|
| Kun forvalgt (1 vare, 2 reservationer) | "Arkiveres: 2 reservationer (2 stk.) på 1 vare, fordi Kims lager i alt er 0. Du kan fravælge dem på de enkelte varer." uden tilføjelse og uden den nye sætning | BESTÅET | Q8 (udviklerens nye trin). |
| Kun forvalgt (2 varer, 3 reservationer) | "Arkiveres: 3 reservationer (3 stk.) på 2 varer …", ingen "kræver din vurdering, er ikke valgt" | BESTÅET | Q22. |
| Blandet (én vare forvalgt, én til vurdering) | Den gamle sætning for den forvalgte + "1 reservation (1 stk.) på varer, der kræver din vurdering, er ikke valgt." (ental). Ikke den nye sætning, ikke "Arkiveres: 0" | BESTÅET | Q22. |
| Kun til vurdering, negativ værdi | "Ingen reservationer er valgt til arkivering. N reservationer (N stk.) på varer, der kræver din vurdering, er ikke valgt. Du kan vælge dem på de enkelte varer." og ingen "Arkiveres:"-linje | BESTÅET | Q10 (udvikler, flertal) + Q22 (ental og to varer; sætningen står i sit eget `<p>…</p>`). |
| Kun til vurdering, usikkert match (81 %, ingen negativ værdi) | Samme nye sætning, ingen N3a | BESTÅET | Q22 (forudsætningen er tjekket: match under 90 %, `archive.default` falsk). |
| Ingen arkivering overhovedet (vare uden reservationer; lager ≠ 0; begge) | Ingen af de tre sætninger | BESTÅET | Q22. |
| Produktdata og lager (ikke kun Kun lagerantal) | Samme nye sætning | BESTÅET | Q22. |
| "Arkiveres: 0 reservationer … på 0 varer" | Vises aldrig | BESTÅET | Q10 og Q22 tjekker både "Arkiveres: 0", "på 0 varer" og "0 reservationer". |

**Mutationstest af ændringen** (kopi, aldrig i repoet): N1 (gammel betingelse), N3 (forvalgt-grenen slået fra), N5 (ny sætning udeladt) og N6 (forkert antal i den nye sætning) fanges alle (9, 4, 7 og 6 fejlende tjek, i Q8, Q10 og Q22).

**Escaping og dansk:**
- De to oversigtssætninger består af fast dansk tekst, `h(supplier_upload_count(…))` og `intval(…)`. Ingen brugerinput kommer ind i dem. Kundenavnet i afkrydsningsetiketten ved siden af er stadig HTML-escapet; Q22 prøver `TSTKIM <b>Fed</b> & 'æøå' <script>alert(1)</script>` og finder `&lt;b&gt;…`, `&amp;`, `&#039;`, ingen rå `<b>` eller `<script>`.
- PHP-kommentaren i skabelonen (`<?php elseif(…): // O4: … ?>`) ender ved `?>` og kommer ikke ud i HTML'en (Q22 tjekker, at "O4:" ikke findes i siden).
- Paneltekst (l. 410) og docs er på dansk. Knapnavnene i docs matcher knapperne i `supplier_upload.php`: "Genberegn preview med tilpasset kolonnemapping" (l. 440), "Genberegn preview" ved "Hvad skal opdateres?" og "Reservationer" (l. 392 og 397), "Gæt kolonner forfra" (l. 443), "Anvend valgte ændringer" (l. 494).
- **Q23 (tester) bekræfter docs' udsagn om, hvornår kolonnevalget gemmes:** selve uploaden, "Hvad skal opdateres?", "Reservationer" og "Gæt kolonner forfra" gemmer det ikke; "Genberegn preview med tilpasset kolonnemapping" og "Anvend valgte ændringer" gemmer det rettede valg (`varenr → sku`, `variant → name`).

**Observation (ikke-blokerende), O5:** paneltekstens "(undtagen varenummer og produktnavn – se ⚠ ved kolonnen)" er lidt bredere end reglen (redningen sker kun, når ingen anden nøglekolonne er valgt). Docs beskriver reglen præcist, og ⚠ vises kun, når redningen faktisk er sket, så det er ikke en fejl.

## Ændrede filer (testeren)

- `tests/supplier_opfoelgning_qa_test.php` (ny)
- `tests/hook_throw_prepend.php` (ny, kun brugt af Q20)
- `tests/supplier_archive_qa_test.php` (Q14–Q21 + hoved; Q22–Q23 i tillægget)
- `tests/run.sh` (kalder den nye enhedstest)
- `docs/specs/2026-10-26-kims-upload-opfoelgning-review.testrapport.md` (denne rapport)

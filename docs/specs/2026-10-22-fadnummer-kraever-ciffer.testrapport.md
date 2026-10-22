# Testrapport: Fadnummer kræver et ciffer – udledning og oprydning (modul `products` 1.5.1)

- **Spec:** `docs/specs/2026-10-22-fadnummer-kraever-ciffer.md` (acceptkriterie 60–80). Brugerens svar: Q1 = A, Q2 = ja, Q3 = nej.
- **Branch:** `feature/kims-upload-fadnummer-ciffer`, testet på commit `594a365` (udviklerens commits `411613d`, `a938f83`, `cfc9349`, `594a365` oven på basen `df886b4` = HEAD af `feature/kims-upload-review-rettelser`).
- **Testet:** 22-10-2026, af uafhængig tester. Udviklerens rapport er ikke brugt som bevis; alt nedenfor er kørt af testeren.

## Konklusion

**KLAR** – med tre forbehold, som ikke er fejl i koden, men ting der ikke kunne køres her (se "Hvad er IKKE kørt"): (1) kriterie 78 er kun kørt på den ældre fil `lager 18-10-2026.xlsx`, fordi `lager 19-10-2026.xlsx` ikke findes på maskinen. Den skal køres manuelt af brugeren. (2) Selve opgraderingen via update.php (rigtig ZIP) og FTP-varianten med fuld `ensure_schema_updates` er ikke kørt. (3) PHP 8.1 er ikke prøvet (kun 8.5.8).

- **Ingen fejl fundet i produktionskoden.** Alle kriterier 60–77 og 79–80 er bestået. Kriterie 78 er delvist bestået (stand-in-fil).
- `bash tests/run.sh` er **grøn** (enhedstest 248 + 84 tjek, databasetest af Kims uploadfil 56, databasetest af oprydningen 110, flowtest 66; alle sider i røgtesten uden 500, inklusive den nye `products.php?status=all&filter=cask_no_digit`).
- Opgraderingen på skrømt (products 1.5.0 → 1.5.1) giver præcis det forventede: 7 ryddet, 3 bevaret, én audit-post, ingen ny post ved efterfølgende forespørgsler.
- Udviklerens syv afvigelser og de to tvivlspunkter er vurderet hver for sig (se afsnittet "Udviklerens afvigelser"). **Alle er i overensstemmelse med spec'en eller strengere end den.**
- **5 bemærkninger** (ingen blokerer) og **6 overlevende mutanter** (testhuller; se "Testhuller"). Den mest synlige bemærkning er layout: en uafbrudt fadnummerværdi på 40 tegn eller mere gør produktlisten bredere end skærmen på 390 px (kun i det nye filter, kun ved urealistiske værdier).

## Miljø og metode

- PHP 8.5.8 (CLI med `pdo_mysql`, `mbstring`, `zip`, `gd`). Midlertidig MariaDB 10.4.22 på 127.0.0.1:3317. Kun databaser med navnet `hsg_test_q2*` er brugt (`hsg_test_q2`, `hsg_test_q2b`, `hsg_test_q2c`, `hsg_test_q2m`). Kun `hsg_test_q2` er efterladt (frisk installation efter sidste kørsel); de andre er droppet.
- Alle egne scripts, HTML-udtræk og skærmbilleder ligger i scratchpad, undermappen `qa/` (ikke i repoet). Basen `df886b4` er hentet med `git archive` til `qa/oldcore`, og `HEAD` til `qa/mutbase` (kopi til mutationstest, aldrig i worktree).
- **HTTP-test:** `php -S 127.0.0.1:8788` mod en separat installeret database (`hsg_test_q2b`, `seed_stock=1`), login som `testadmin`, `curl` med cookies. Serveren er stoppet, og `config.php` er slettet i worktree-roden (`git status` er tom).
- **Mobilbredde:** Chrome headless (`--headless=new`), siden lagt i en `<iframe width=390>`, så layoutbredden er præcis 390 px (headless Chrome kan ikke gå under ca. 500 px direkte). Målt `scrollWidth` i forhold til `clientWidth` og taget skærmbillede. Det er ikke en rigtig telefon.
- **Mutationstest:** 21 bevidste fejl lagt ind i en kopi af `core/product_enrichment.php`, `modules/products/migrations.php` og `module.php`, og de to nye testfiler kørt mod hver (databasen gendannet fra dump mellem hver). Se "Testhuller".
- **Differentialtest af udledningen:** `hsg_cask_number_candidates(x)[0]` sammenlignet med den gamle funktion fra `df886b4` (omdøbt og indlæst i samme proces) på 30.181 tekster: alle navne fra `initial-stock.json` og 30.000 tilfældige kombinationer af cask/fad/barrel/#/batch/årstal/æøå/–/tal. Se kriterie 60–61.
- **Bemærkning om miljøet:** Af i alt fire kørsler af hele pakken fejlede to (kørsel 2 og 3) med `Installation fejlede` på grund af PHP's 30-sekunders grænse (`max_execution_time`), fordi databasens disk periodisk var meget langsom (en `DROP DATABASE` tog 10–14 s). Fejlen lå i `install.php:20` og `core/catalog_image_seed.php:23`, ikke i branchens kode. Kørsel 1 og kørsel 4 (med `max_execution_time = 900` i en kopi af scratchpad-`php.ini`, ellers samme indstillinger) var grønne. Det er ikke en fejl i branchen, men kan ramme andre på langsomme maskiner. På den midlertidige MariaDB er `innodb_flush_log_at_trx_commit` og `sync_binlog` desuden sat til 0 (`SET GLOBAL`) for at gøre installationerne hurtigere; det påvirker ingen af testene.

### Samlede kørsler

| Kørsel | Resultat |
|---|---|
| `bash tests/run.sh` (frisk tom DB, kørsel 1) | Trin 1 (php -l alle filer), 2 (release-negativtest A–G), 3 (Kims uploadfil **248 tjek**, fadnummer-udledning **84 tjek**) og 4 (installation, login, alle sider, uden login 401, POST uden CSRF 419, `reservation_mode`/`reguess` 419 og 401, `supplier_import_db_test.php` **56 tjek**, `cask_number_cleanup_db_test.php` **110 tjek**, `supplier_upload_flow_test.php` **66 tjek**) er grønne. Slutlinje: ALLE TESTS BESTÅET. |
| Samme, kørsel 2 og 3 (frisk tom DB hver gang) | **Installation fejlede** (PHP-grænse på 30 s ramt i `install.php:20` / `core/catalog_image_seed.php:23` på grund af langsom disk; se "Miljø og metode"). Trin 1–3 var grønne. Ikke en fejl i branchen. |
| Samme, kørsel 4 (frisk tom DB, `max_execution_time = 900`) | Identisk resultat som kørsel 1: ALLE TESTS BESTÅET (47 s i alt). |
| `tests/cask_number_cleanup_db_test.php` kørt igen oven på en DB, hvor den allerede har kørt | Grøn (110 tjek): testen rydder selv op og kan køres igen. |
| `git diff df886b4 -- core/supplier_import.php supplier_upload.php migrations.php import.php install.php tests/supplier_import_test.php` | Tom (ingen ændringer). |
| Egne scripts (se afsnittene herunder) | `diff60.php`, `robust.php`, `upg_setup.php` + `upg_check.php`, `race.php`, `trimcmp.php`, `kim_dump.php` + `kim_cmp.php`, `mutate.php`, HTTP-kald med `curl` |

## Acceptkriterier

| Nr. | Kriterie (kort) | Resultat | Bevis |
|---|---|---|---|
| 60 | `hsg_extract_cask_number` for tabellen a–p | BESTÅET | Case 60 grøn. Eget script `diff60.php` med tabellen skrevet fra spec'en: alle 16 rækker som i kolonnen "Efter" (a `null`, b `null`, c `null`, d `231584`, e `200`, f `null`, g `R10/123`, h `R10/123`, i `null`, j `null`, k `null`, l `231584`, m `43`, n `null`, o `1ST`, p `null`). Differentialtest på 30.181 tekster: ny funktion = første kandidat med mindst ét ciffer i alle tilfælde, og ingen tekst, hvor den gamle gav en værdi med ciffer, har fået et andet resultat. Ny funktion returnerer aldrig en værdi uden ciffer. |
| 61 | `hsg_cask_number_candidates` gengiver den gamle udledning | BESTÅET | Case 61 grøn. `diff60.php`: `[0] ?? null` = kolonnen "I dag" for a–p og **= den gamle funktion fra `df886b4` på alle 30.181 tekster** (0 afvigelser). Alle elementer med store bogstaver (0 afvigelser). `m` giver `["FINISH","43"]`, `l` `["STRENGTH","231584"]`, `j` og `p` `[]`. |
| 62 | `hsg_product_parse_text` | BESTÅET | Case 62 grøn: "Cask Strength" uden `cask_number` i `fields` og `confidence` og uden "fadnummer" i `reason`; "Cask Craft" uden; "Cask No. 231584" giver `'231584'`, konfidens 99 og begrundelsen "fadnummer aflæst direkte efter #/Cask No."; Knockdhu giver `'43'`. Case 62n tester normaliseringen (– og — bliver -, mellemrum samles) separat. |
| 63 | `hsg_supplier_source_fields` | BESTÅET | Case 63 grøn: "Glenfarclas 15 Cask Strength" uden `cask_number`, "Glen Test Sherry Cask #100" giver `'100'`. Testens egne hjælpere `pe_kim_row`/`pe_kim_map` er sammenlignet med `kim_row`/`kim_map(false)` i `supplier_import_test.php`: samme kolonner (4, 5, 7 og lokationer 17–19), så kaldet er identisk med spec'ens. Se udviklerens afvigelse 7. |
| 64 | Eksisterende tests uændret grønne | BESTÅET | `tests/supplier_import_test.php` er **uændret** (tom `git diff`) og giver 248 tjek bestået, 0 fejlede. |
| 65 | Oprydning, kørsel 1 og 2 | BESTÅET | Case 65/66 (kørsel 1 og 2) grønne: A `NULL`, B `'43'`, C–G og H uændrede, `cleared` og `kept` som spec'en. Kørsel 2 med seed-kortet giver C `'143'` (`initial_stock`), A og B uændrede. Dækket yderligere af opgraderingen på skrømt (se nedenfor) og af mutationstesten: 10 af de 21 mutanter (M1, M2, M3, M5, M6, M8, M9, M12, M14 og M19) rammes af case 65 og/eller 67. |
| 66 | Præcis én audit-post pr. kørsel med de rigtige kolonner | BESTÅET | Case 65/66 og 68 tjekker `action`, `actor_type='system'`, `actor_id IS NULL`, `actor_name='System (opdatering)'`, `entity_type='product'`, `entity_id IS NULL`, `ip_hash IS NULL`, `cleared`/`kept` = returværdien, `cleared_count`/`kept_count`. Manuelt: audit-posterne vises under System → Auditlog → filter "Produkter" (`system.php?tab=audit&entity=product`) med "System (opdatering)" og detaljer. |
| 67 | Idempotens (kørsel 3) | BESTÅET | Case 67 grøn. Manuelt: to efterfølgende forespørgsler (login-side, admin-side) efter migrationen skriver ingen ny post, og `hsg_module_versions.products` er `1.5.1`. |
| 68 | 12-1016 med den rigtige `initial-stock.json` | BESTÅET | Case 68 grøn: `FINISH` → `'43'`, `{sku:'12-1016', old:'FINISH', new:'43', source:'initial_stock'}` i returværdi og audit. Navn og fadnummer sættes tilbage i `finally`. Manuelt (opgraderingen på skrømt): samme resultat, plus 12-1104 (helt andet navn) → `300223` via `initial_stock`. |
| 69 | Frisk installation: migrationen kørt præcis én gang | BESTÅET | Case 69 grøn. Manuelt på en frisk installation (`hsg_test_q2b`): `products` = `1.5.1`, præcis én post `product.cask_number_cleanup` med `cleared_count` 0 og `kept_count` 0, aktør "System (opdatering)". 12-1016/12-1087/12-1098/12-1104 = 43/143/71324/300223, 11-2010 og 18-1015 tomme. **Case 69 er det eneste tjek, der fanger, at migrationen ikke kaldes ved installationen.** Mutanterne M7 (ingen audit når intet ryddet) og M18 (migrationen kalder ikke oprydningen) overlever i mutationsprogrammet med en gendannet DB, men blev begge opdaget i **fuld installation** (`run.sh` i kopien: "præcis én audit-post … forventet 1, fik 0"). |
| 70 | `modules/products/migrations.php` | BESTÅET | Case 70 grøn (kun nøglen `1.5.1`, callable, intet output og ingen nye funktioner ved to indlæsninger, `products` = `1.5.1`, `supplier_upload` = `1.1.1`). Mutanterne M16 (nøgle `1.5.2`) og M17 (version `1.5.0`) opdaget. |
| 71 | Listen i Produkter (4 produkter) | BESTÅET | HTTP-test mod `hsg_test_q2b`: 3 aktive (`STRENGTH`, `N/A`, `strength`), 1 inaktivt (`FINISH`), 1 med `231584`, ingen andre uden ciffer. `products.php` viser i rækkefølgen **"Mangler fadnr. (114)", "Fadnr. uden ciffer (4)", "Negativt lager (0)"**, linket er præcis `products.php?status=all&filter=cask_no_digit`. Listen bag linket viser QA-1 til QA-4 og **ikke** QA-5 (`231584`), knappen har `class="button "` (ikke `secondary`), hjælpeteksten står én gang som `<p class="muted">`. Hvert mobilkort har `<div class="mobile-stock-line"><span>Fadnr.</span><span>#FINISH</span></div>` (tilsvarende `#strength`, `#N/A`, `#STRENGTH`). Uden filteret (`status=all` og `filter=missing_cask`) er der 0 sådanne linjer og ingen hjælpetekst. Redigér-og-gem-flowet er også kørt: at tømme feltet på 12-1098 og sætte `77-A` på et andet produkt får begge til at forsvinde fra listen (N 3 → 2 → 1), og "Mangler fadnr." stiger tilsvarende. |
| 72 | Ingen produkter uden ciffer | BESTÅET | På en frisk installation: knappen vises ikke (0 forekomster af "Fadnr. uden ciffer"), og `products.php?status=all&filter=cask_no_digit` svarer **HTTP 200** med hjælpeteksten, ingen mobilkort og ingen fejl/advarsler. Se også bemærkning 3. |
| 73 | Ingen ny POST; linkbruger afvises | BESTÅET | `git diff` på `products.php` viser kun GET-dele (tre handlinger `toggle_flag`, `merge_products`, `delete_negative` er uændrede). Uden login: `products.php` og med filter **401**. Linkbruger (adgangslink `?k=`, med `can_view`/`can_operate` på `products`, `import_export` og `supplier_upload`): `products.php`, `products.php?status=all&filter=cask_no_digit`, `product_edit.php`, `product_enrich.php`, `supplier_upload.php`, `admin.php` og `stock.php` giver alle **403**, og svaret indeholder ingen produktlinjer. POST uden CSRF: admin **419**, linkbruger **419**; POST med gyldig CSRF som linkbruger til `products.php` (`toggle_flag`) **403**, og produktet er uændret. |
| 74 | Røgtesten indeholder filter-URL'en | BESTÅET | `tests/run.sh` har `products.php?status=all&filter=cask_no_digit` i `PAGES`. Output i begge fulde kørsler: `[OK] products.php?status=all&filter=cask_no_digit → HTTP 200`. Se udviklerens afvigelse 6. |
| 75 | `bash tests/run.sh` helt grøn | BESTÅET | Se "Samlede kørsler". To kørsler grønne (kørsel 1 og 4); to fejlede i installationen på grund af miljøet (langsom disk, 30 s-grænse), ikke på grund af branchen. |
| 76 | Manifester og dokumentation | BESTÅET | `modules/products/module.php`: `'version' => '1.5.1'`. `modules/supplier_upload/module.php`: `1.1.1`. `docs/SUPPLIER-UPLOAD.md` (afsnittet om udledning, `:24`, og "Produktmatch", `:79`) beskriver reglen og R2 som ekstra værn, som spec'en afsnit 7 kræver. `docs/PRODUCT-DATA-ASSISTANT.md` har punkt 1 og den nye sektion "Fadnumre uden ciffer" (engangsoprydning, audit-log, listen, Datakvalitet). `README.txt` har tilføjelsen på linjen om fadnummer og release-noten, som er ordret den fra spec'ens afsnit 5. `docs/ARCHITECTURE.md` har KAN-2. |
| 77 | R2 og backfill uændrede | BESTÅET | `git diff df886b4 -- core/supplier_import.php supplier_upload.php migrations.php import.php install.php` er tom. |
| 78 | Kims rigtige fil, før/efter | **DELVIST – IKKE KØRT PÅ 19-10-FILEN** | `lager 19-10-2026.xlsx` findes ikke på maskinen. Kørt på `lager 18-10-2026.xlsx` (samme fil som den forrige testrapport brugte, og som rækkenumrene 21, 22, 27, 28, 115, 159, 185, 205 stammer fra). Se afsnittet "Kriterie 78". |
| 79 | KAN-1: hjælpetekst under Fadnummer | BESTÅET | `product_edit.php?id=55` indeholder præcis `Nummeret efter # / Cask No. bruges som stærkt match ved leverandørupload og billedtjek. Det skal indeholde mindst ét ciffer – ord som "Strength" eller "Finish" er ikke et fadnummer.` |
| 80 | KAN-2: note i ARCHITECTURE.md | BESTÅET | Afsnittet "Migrations" i `docs/ARCHITECTURE.md` har et nyt stykke: engangsrettelser af data lægges som modulmigration, fordi `ensure_schema_updates` kører med den gamle kode i opgraderingsforespørgslen (`core/updater.php`) og returnerer tidligt bagefter. Passer med `core/updater.php:320-323`. |

## Opgraderingen på skrømt (products 1.5.0 → 1.5.1)

Frisk installation (`hsg_test_q2b`, startlager). Derefter via script (`qa/upg_setup.php`): `hsg_module_versions.products` sat til `1.5.0`, og fadnumre lagt ind som herunder. Så **én** HTTP-forespørgsel (`GET admin-login.php`), derefter `qa/upg_check.php`.

| SKU | Før (fadnr. / navn) | Forventet | Faktisk |
|---|---|---|---|
| 11-2010 | `CRAFT` / startlagerets navn | ryddet til tomt, kilde `name` | **ryddet**, `new:null`, `source:name` |
| 12-1016 | `FINISH` / standardiseret navn "Knockdhu (2012) – 10 år – 58,2 %" | `43` via `initial_stock` | **ryddet**, `new:"43"`, `source:initial_stock` |
| 12-1087 | `FINISH` / startlagerets navn | `143` via `name` | **ryddet**, `new:"143"`, `source:name` |
| 12-1104 | `FINISH` / helt andet navn "Deanston Specialudgave" | `300223` via `initial_stock` | **ryddet**, `new:"300223"`, `source:initial_stock` |
| 18-1015 | `MATURED` / standardiseret navn | tomt via `initial_stock` | **ryddet**, `new:null`, `source:initial_stock` |
| QA-20 | `STRENGTH` / "Glenfarclas 15 Cask Strength, 60%" | tomt via `name` | **ryddet** |
| QA-23 | ` FINISH ` (med mellemrum), udgået / "Test Cask Finish" | tomt via `name` | **ryddet**, `old:" FINISH "` (utrimmet) |
| 12-1098 | manuelt `N/A` | bevaret | **bevaret** (`kept`) |
| QA-21 | `Ukendt` / standardiseret navn | bevaret | **bevaret** |
| QA-24 | `strength` (små bogstaver), inaktiv | bevaret | **bevaret** |
| QA-22 | `231584` (har ciffer) | urørt | **urørt**, i ingen af listerne |

- **Præcis én ny audit-post** (id 2, efter id 1 fra installationen): `actor_type=system`, `actor_id=NULL`, `actor_name=System (opdatering)`, `entity_type=product`, `entity_id=NULL`, `ip_hash=NULL`, `cleared_count=7`, `kept_count=3`. Sammenligning af alle 186 produkters `cask_number` før/efter: præcis de 7 ryddede rækker er ændret, intet andet.
- `hsg_module_versions.products` = `1.5.1` efter første forespørgsel.
- **To efterfølgende forespørgsler** (en uautentificeret og to som admin): ingen ny audit-post (stadig 2), ingen ændringer. Migrationen kører altså ikke igen.
- Listen i Produkter viser bagefter præcis de tre bevarede (`#Ukendt`, `#N/A`, `#strength`).
- **Kapløb (to samtidige første forespørgsler), 3 runder** (`qa/race.php`, to PHP-processer startet på samme tidspunkt): to audit-poster, hvor den ene har `cleared=7 kept=3` og den anden `cleared=0 kept=3`. Ingen række optræder i `cleared` to gange, og data er identiske med enkeltkørslen. Det er præcis det, `rowCount()>0` er til for (udviklerens afvigelse 2).
- **Robusthed** (`qa/robust.php`, scratch-database): manglende `lager_products` eller manglende kolonne `cask_number` giver `{"cleared":[],"kept":[]}` uden undtagelse og uden audit-post. Manglende `hsg_audit_log` giver ingen undtagelse, og oprydningen virker alligevel. Ugyldige eller manglende `initial-stock.json` (ikke findes, tom, ugyldig JSON, forkert form, `products` som streng, ikke-skalare `sku`/`name`) giver `[]` eller kun de gyldige rækker.

## Kriterie 78: før/efter på Kims fil (stand-in: `lager 18-10-2026.xlsx`)

**Metode** (`qa/kim_dump.php`, `qa/kim_cmp.php`): `hsg_supplier_prepare_preview()` er kørt i to separate PHP-processer mod **samme** installerede testdatabase (181 startlagerprodukter, oprydningen kørt): én med kernen fra `df886b4` og én med `HEAD`. Kolonnevalget er det automatiske gæt, og Kims lokationer R og S er derefter lagt på som lokation 1 og 2 via `hsg_supplier_recalculate_preview` (testdatabasen har kun to aktive lokationer). Reservationsvalg `add`. Filen er kun læst og ikke kopieret ind i repoet.

- **Kolonnevalg identisk:** `sku=E`, `name=F`, `age_text=G`, `distillery=H`, `brand_name=J`, `abv=K`, `stock_quantity=Q`, `wholesale_price=Z`, `retail_price=AA`. 291 varer før og efter.
- **Match og forvalg er identiske** for alle 291 varer: der er ingen forskel i `match`, `selected`, `flags`, `notes`, `stock_file` eller andre felter.
- **13 varer har en forskel, og alle forskelle er kun i `source.cask_number` (13) og `changes.cask_number` (2):**

| Excel-række | Varenr. | Navn (afkortet) | `source.cask_number` før → efter | `changes.cask_number` |
|---|---|---|---|---|
| 21 | (ingen) | Murray McDavid "Cask Craft Series" | `CRAFT` → ingen | – |
| 22 | 11-2007 | Inchgower, Cask Craft, Madeira | `CRAFT` → ingen | – |
| 23 | 11-2008 | Benrinnes, Cask Craft, Port | `CRAFT` → ingen | – |
| 25 | 11-2009 | Tullibardine, Cask Craft | `CRAFT` → ingen | – |
| 27 | 11-2010 | Auchroisk, Cask Craft, Koval/Bourbon | `CRAFT` → ingen | `new:"CRAFT"` forsvinder |
| 28 | 11-2011 | Croftengea, Cask Craft, Double Oaks | `CRAFT` → ingen | – |
| 115 | 12-1016 | Knockdhu, 2012 – #43 – Virgin Oak Barrel Finish | `FINISH` → **`43`** | – |
| 159 | 12-1087 | Royal Brackla, 2013 – #143 – Peated Whisky Cask Finish | `FINISH` → **`143`** | – |
| 164 | 12-1098 | Teaninich, 2014 – #71324 – Madeira Qtr Cask Finish | `FINISH` → **`71324`** | – |
| 185 | 12-1124 | Inchdairnie (Finglassie), 2018, #1a – … | `FINISH` → **`1A`** | – |
| 200 | 12-1104 | Deanston, 2014 – #300223, PX Quarter Cask Finish | `FINISH` → **`300223`** | – |
| 205 | 12-1128 | Aultmore, 2013, #300444 – Rum Cask Finish | `FINISH` → **`300444`** | – |
| 265 | 18-1015 | Invergordon, Double ex-bourbon barrel matured | `MATURED` → ingen | `new:"MATURED"` forsvinder |

Rækkerne 21, 22, 27, 28, 115, 159, 185 og 205 fra spec'en har alle fået intet eller et rigtigt nummer. De fem ekstra rækker (23, 25, 164, 200, 265) er af samme art (CRAFT, FINISH og MATURED udledt som ord) og følger samme regel; de er ikke nævnt i spec'ens liste, men er forklaret af F1. I `changes` er der kun forskel på to rækker (27 og 265), hvor HSG-feltet er tomt, og hvor den gamle kerne ville have skrevet `CRAFT` og `MATURED` ind i det tomme felt.

**Hvad der stadig skal gøres manuelt:** kør samme sammenligning på **`lager 19-10-2026.xlsx`** og skriv forskellene i PR'en, som spec'en kræver. De rækkenumre, der er brugt her, er fra 18-10-filen.

## Udviklerens afvigelser og tvivlspunkter

| # | Udviklerens tolkning | Vurdering mod spec'en | Bevis |
|---|---|---|---|
| 1 | Mangler kolonnen `cask_number`, returneres tomme lister **uden** audit-post | **I orden.** Spec'en afsnit 3.3 "Robusthed": "returnerer funktionen tomme lister uden at gøre noget". Afsnittet "Audit" siger "hvert kald skriver præcis én række … også når intet er ryddet", så de to sætninger kan læses som modstridende, men den specifikke regel er "uden at gøre noget". Situationen kan ikke opstå via en forespørgsel, fordi `ensure_schema_updates` (`db.php:11`) tilføjer kolonnen **før** modulmigrationen (`db.php:13`). Spec'en bør præcisere sætningen. | `qa/robust.php`: tomme lister, 0 audit-poster, ingen undtagelse. |
| 2 | `cleared` indeholder kun rækker, hvor `UPDATE` ændrede noget (`rowCount()>0`) | **I orden, og bedre end bogstaveligt.** Den gamle værdi har aldrig et ciffer, og den nye har altid et ciffer eller er `NULL`, så en vellykket opdatering ændrer altid værdien (tæller som ændret række). En række, hvor en anden forespørgsel nåede først, står dermed ikke to gange i audit. | Kapløbstesten ovenfor (3 runder): den anden post har `cleared=0`, og ingen række står to gange. Mutant M11 (uden `rowCount`-tjek) overlever i testene, fordi det kræver samtidighed (se "Testhuller"). |
| 3 | `old`/`value` i audit er den gemte værdi **uden** `trim` | **I orden.** Spec'en siger "den gamle værdi", og `UPDATE … AND cask_number=?` skal bruge den gemte værdi, for at betingelsen passer. Beviset sammenligner med `trim(cask_number)`, som spec'en kræver. | Audit-JSON for QA-23: `"old":" FINISH "`, ryddet. Ingen test dækker det (M4/M15 overlever, se "Testhuller"). |
| 4 | TSTCASK-1 (testprodukt A) oprettes som `discontinued` | **I orden.** Spec'ens tabel angiver ikke en status, men teksten siger "alle statusser". Valget gør testen strengere (en udgået række skal også ryddes). Mutant M5 (kun aktive) opdaget af case 65 og 67. QA-23 (udgået) og QA-24 (inaktiv) bekræfter i skrømtetesten. | Case 65/66, M5 opdaget. |
| 5 | Databasetesten sletter egne audit-poster og nulstiller andre produkter uden ciffer i `finally` | **I orden.** Spec'en siger, at testdata "ryddes op bagefter". Testen sletter kun `action='product.cask_number_cleanup'` med `id` over det højeste id ved teststart og afviser at køre, hvis `HSG_TEST_DB_NAME` ikke passer til `config.php`. | Testen er kørt to gange i træk på samme DB og er grøn begge gange. |
| 6 | `run.sh` bruger `${p%%\?*}` i filtjekket | **I orden.** Filtjekket skal bruge sidens filnavn uden query-streng, ellers ville `products.php?status=all&filter=cask_no_digit` blive sprunget over (`[ -f … ] \|\| continue`). Almindelige sider uden `?` er uændrede. | Begge fulde kørsler viser `[OK] products.php?status=all&filter=cask_no_digit → HTTP 200`. Den ubeskyttede `for p in $PAGES` kunne i teorien lave filnavnsudvidelse på `?`, men ingen fil matcher. |
| 7 | Kriterie 63 testes i `product_enrichment_test.php` med egne hjælpere | **I orden.** Spec'en placerer 60–64 og 70 i den fil. De egne hjælpere (`pe_kim_row`, `pe_kim_map`) giver de samme kolonner som `kim_row`/`kim_map(false)`, og det kaldte `hsg_supplier_source_fields` er det samme. Ulempen er en lille kopi af hjælperne. | Sammenlignet linje for linje med `tests/supplier_import_test.php:59-72`. |
| T1 | SQL `TRIM` (kun mellemrum) i filteret vs. PHP `trim` i oprydningen | **Harmløs forskel**, se bemærkning 2. | `qa/trimcmp.php`. |
| T2 | Hjælpeteksten vises, når filteret er valgt, også ved N = 0 | **I overensstemmelse med spec'en** (afsnit 2.2: "kun når filteret er valgt"; kriterie 72: HTTP 200 med en tom liste). Se bemærkning 3. | `products.php?status=all&filter=cask_no_digit` på en frisk installation. |

## Bemærkninger (blokerer ikke)

1. **Mobil: lange fadnumre uden mellemrum gør siden bredere end skærmen.** `<span>#<værdi></span>` i den nye `mobile-stock-line` kan ikke ombrydes uden mellemrum. Målt i en rigtig 390 px-visning (`qa/` iframe-test, `scrollWidth` vs. `clientWidth` = 375): 31 store bogstaver i træk: **ingen** overløb. 40 store bogstaver: `scrollWidth` 444. 80 store bogstaver (kolonnen er `VARCHAR(80)`): `scrollWidth` 751, kortet løber ud over skærmen, og hele siden kan scrolles sidelæns. 78 tegn **med** mellemrum: ingen overløb. De rigtige værdier (`STRENGTH`, `N/A`, `FINISH`, `Ukendt`) og alt, hvad den gamle udledning kunne gemme (højst 31 tegn), er uden problem, så det rammer kun urealistiske manuelle værdier, og kun i det nye filter. Reproduktion: sæt `cask_number` til 40 gange `X` på et produkt, åbn `products.php?status=all&filter=cask_no_digit` på 390 px. Forslag: `overflow-wrap:anywhere` på værdi-spanen (CSS er uden for tester-området).
2. **SQL `TRIM` og PHP `trim` er ikke identiske, men uskadeligt.** Et fadnummer, der kun består af tabulator eller linjeskift, tælles og vises i listen (SQL `TRIM` fjerner kun mellemrum), men oprydningen springer det over uden at lægge det i hverken `cleared` eller `kept` (PHP `trim` fjerner det). Et fadnummer med kun mellemrum eller kun hårdt mellemrum (U+00A0) tælles som tomt af begge (kollationen `utf8mb4_unicode_ci` ligestiller U+00A0 med mellemrum). Konsekvensen er højst en værdi, som står på listen, men ikke i audit-posten. Målt i `qa/trimcmp.php`: på listen `TRIM-tab, TRIM-nl, TRIM-ord`; i `kept` kun `TRIM-ord`.
3. **Tom liste har ingen "ingen produkter"-tekst.** Ved N = 0 og valgt filter vises hjælpeteksten og ingen kort eller rækker, men ingen sætning om, at listen er tom. Det er som spec'en (og som de andre filtre i dag), men en admin, der åbner et gammelt link, ser en tom side med en forklaring af noget, der ikke findes. Forslag, ikke krav.
4. **To samtidige første forespørgsler giver to audit-poster** (den anden med `cleared=0`). Hvert kald skriver præcis én post, som spec'en siger, men der kan altså være to poster efter én opgradering på en travl installation. Data er korrekte (se kapløbstesten).
5. **Kriterie 78 nævner otte rækker, men før/efter-testen viser 13.** De fem ekstra (række 23, 25, 164, 200 og 265 = 11-2008, 11-2009, 12-1098, 12-1104 og 18-1015) er af samme art (CRAFT, FINISH og MATURED som ord, nu intet eller det rigtige nummer), og kun `source.cask_number` og `changes.cask_number` er ændret, så kriteriets krav holder. Det er kun en dokumentationsdetalje, som kan stå i PR'en.

## Testhuller og anbefalede tests

21 mutanter af produktionskoden (`qa/mutate.php`, database gendannet fra dump mellem hver). **15 blev opdaget, 6 overlevede.** M7 og M18 tæller som opdaget, fordi de blev fanget af case 69 i en fuld installation (`run.sh` i en kopi; i mutationsprogrammet er databasen allerede installeret).

| Mutant | Resultat | Opdaget af |
|---|---|---|
| M1 filteret ikke i løkken | opdaget | case 60, 62, 65, 67, 68 |
| M2 `UPDATE` uden `AND cask_number=?` | opdaget | case 65, 67, 68 |
| M3 bevis uden skelnen mellem store og små bogstaver | opdaget | case 65, 67 |
| M4 `trim` fjernet ved bevis | **overlever** | – |
| M5 kun aktive produkter | opdaget | case 65, 67 |
| M6 `actor_name` ændret | opdaget | case 65, 67, 68 |
| M7 ingen audit-post, når intet er ryddet eller bevaret | opdaget (kun i fuld installation) | case 69 |
| M8 ny værdi altid `NULL` (ingen genudledning, Q2) | opdaget | case 65, 67, 68 |
| M9 startlagerets navne ignoreres | opdaget | case 65, 67, 68 |
| M10 startlagerets navn prøves før produktets eget navn | **overlever** | – |
| M11 `cleared` uden `rowCount`-tjek | **overlever** | – (kræver samtidighed) |
| M12 `#`-mønstret prøves før cask/fad/barrel | opdaget | case 60, 61, 65, 67, 68 |
| M13 `[1-9]` i stedet for `\d` i `hsg_extract_cask_number` | **overlever** | – |
| M14 oprydningen ryddede også værdier med ciffer | opdaget | case 65, 67 |
| M15 `value` i `kept` trimmet | **overlever** | – |
| M16 migration nøgle `1.5.2` | opdaget | case 70 |
| M17 modulversion `1.5.0` | opdaget | case 70 |
| M18 migrationen kalder ikke oprydningen | opdaget (kun i fuld installation) | case 69 |
| M19 `entity_type` = `system` | opdaget | case 65, 67, 68 |
| M21 batch-filteret fjernet | opdaget | case 60, 61 |
| M22 årstalsfilteret (19xx/20xx) fjernet | **overlever** | – |

(M20, en test af normaliseringen, kunne ikke lægges ind med den benyttede erstatningsstreng og er sprunget over. Normaliseringen dækkes direkte af case 62n.)

Foreslåede tests (ingen er lagt ind i `tests/`, fordi opgaven var at committe kun rapporten):

- **M4 og M15:** et `TSTCASK`-produkt med `cask_number` `' STRENGTH '` og et navn, der giver `STRENGTH`, skal blive ryddet med `old:" STRENGTH "`. Et andet med `' N/A '` skal stå i `kept` med `value:" N/A "`.
- **M10:** et produkt, hvor både produktets navn ("X Cask Finish") og startlagerets navn ("… #143 … Cask Finish") beviser værdien `FINISH`. Forventet `source:"name"` og `new:null`, ikke `143`.
- **M13:** `hsg_extract_cask_number('Barrel 0')` og `'#00'` skal give `'0'` og `'00'` (har ciffer).
- **M22:** `hsg_extract_cask_number('Cask 2012')`, `'#2012'` og `'Cask 2012, #43'` skal give `null`, `null` og `'43'`. Årstalsfilteret er gammel kode, men det er nu den eneste forsvarslinje mod, at et årstal bliver et fadnummer.
- **M11:** kan kun prøves med to forbindelser (ændr værdien mellem `SELECT` og `UPDATE`). Dækket af kapløbstesten ovenfor, men ikke af en automatisk test.

Scripts og rå output ligger i scratchpad under `qa/` (bl.a. `diff60.php`, `upg_setup.php`/`upg_check.php`, `race.php`/`race_check.php`, `robust.php`, `trimcmp.php`, `kim_dump.php`/`kim_cmp.php`, `mutate.php`, `mut_result.txt`).

## Hvad er IKKE kørt

| Hvad | Hvorfor | Konsekvens |
|---|---|---|
| Kriterie 78 på **`lager 19-10-2026.xlsx`** | Filen findes ikke på maskinen | Kørt på `lager 18-10-2026.xlsx` med samme metode. **Brugeren skal køre den på 19-10-filen** og skrive forskellene i PR'en. |
| Rigtig opgradering via **update.php** (ZIP) og FTP-varianten, hvor første forespørgsel kører hele `ensure_schema_updates` (backfill, navnestandardisering, import af katalogbilleder til `uploads/`) før migrationen | Ville skrive i `uploads/` og kræver en rigtig release-ZIP (version er ikke hævet på branchen) | Opgraderingen er simuleret ved at sætte modulversionen til `1.5.0`. At backfillen ikke kan skrive ord uden ciffer er dækket af kriterie 62 (enhedstest). At navnet er standardiseret før oprydningen, er dækket af 12-1016 og 18-1015 (via `initial_stock`). |
| Release-ZIP bygget med `tools/build-release.php <version>` | Version og `hsg-package.json` hæves først ved release | `--test-negative` (A–G) er grøn i `run.sh`. `modules/products/migrations.php` og `initial-stock.json` er sporet af git og ikke på bygger-udelukkelseslisten (`tests`, `docs/specs`, `.claude`, `CLAUDE.md`), så de kommer med; `tests/*` og `docs/specs/*` kommer ikke med. ZIP'en er ikke bygget og pakket ud. |
| PHP 8.1 | Kun 8.5.8 installeret | Koden bruger ikke konstruktioner nyere end 8.1 (`array_is_list` og `str_starts_with` i testene findes fra 8.1/8.0). Ikke prøvet. |
| Rigtig telefon | Kun Chrome headless i en 390 px-iframe | Layoutet er målt (se bemærkning 1), men ikke set på en enhed. |
| Test med ægte produktionsdata | Ingen adgang | Antallet af "bevaret"-rækker i produktion kan ikke ses før opgraderingen (spec'en afsnit 10). |

## Før release

- Opdater `app_version.php` (nu `11.1.4`), README og `hsg-package.json` via `php tools/build-release.php <version>` (release-tjeklisten i `CLAUDE.md`). Det er ikke gjort på branchen, som spec'en afsnit 5 beskriver ("ved release").
- Kør kriterie 78 på `lager 19-10-2026.xlsx`.
- Efter opgraderingen: kig i System → Auditlog (filter "Produkter") efter `product.cask_number_cleanup`, og åbn Produkter → "Fadnr. uden ciffer" for at rette det, der er bevaret.
- Overvej at lægge de fem foreslåede tests ind (især M4/M15, M10 og M22).
- I testmiljøet: `uploads/products/` (75 filer fra katalogbilledimporten ved installation), `storage/data/access-link.key`, `storage/import-previews/` og `storage/tmp/` er gitignorerede kørselsfiler, som testinstallationerne har lavet i worktree. De er ikke committet.

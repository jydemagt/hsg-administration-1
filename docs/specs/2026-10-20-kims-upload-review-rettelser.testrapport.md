# Testrapport: Kims uploadfil – rettelser efter review af PR #172 (modul `supplier_upload` 1.1.1)

- **Spec:** `docs/specs/2026-10-20-kims-upload-review-rettelser.md` (acceptkriterie 39–59). Hovedspec: `docs/specs/2026-10-19-kims-upload-lagerfejl.md`.
- **Branch:** `feature/kims-upload-review-rettelser` (testet på commit `98106a3`; ændringerne er `e47e745..98106a3` oven på grundlaget `040aa79`, PR #172).
- **Testet:** 21-10-2026, af uafhængig tester. Udviklerens rapport er ikke brugt som bevis; alt nedenfor er kørt af testeren.
- **Åbne spørgsmål Q1 og Q2** i spec'en er ikke en del af tillægget og er ikke testet.

## Konklusion

**KLAR** – med ét forbehold: de trin, der kræver MySQL, er ikke kørt (se "Hvad er IKKE kørt"). Kør `bash tests/run.sh` med `HSG_TEST_DB_*` sat, før der merges.

- **Ingen fejl fundet.** Alle kriterier, der kan prøves uden database, er bestået (nr. 39–52, 54–56 og 58). Kriterie 53, 57 og 59 er kun delvist afprøvet, fordi de også kræver databasen.
- Kriterie 54 (før/efter på Kims rigtige fil): **kolonnevalget er identisk**. Alle forskelle i varer, match og forvalg er forklaret og bevist af R1, R2 eller KAN-1 (se afsnittet "Kriterie 54"). R3 giver ingen forskel på Kims fil, fordi filen ikke har en eneste lokationscelle, der ikke er et heltal.
- Udviklerens to afvigelser (R3 komma og KAN-4 regex) er efterprøvet og ikke et brud på et kriterie. Den ene (R3) er nødvendig og bør skrives ind i spec'en.
- Der er **8 bemærkninger** og **ét hul i testdækningen** (3 overlevende mutanter, se "Bemærkninger" og "Testhuller"). Ingen blokerer.

## Miljø og metode

- PHP 8.5.8 (CLI, uden `mbstring` og uden `pdo_mysql`). Der er **ingen MySQL** lokalt. PHP 8.1 (produktionens minimum) er ikke prøvet; koden bruger ingen nyere konstruktioner end 8.1.
- Testkommando: `PHPRC=<scratchpad>/phpini bash tests/run.sh`.
- Alle egne scripts ligger i scratchpad, undermappen `qa/` (ikke i repoet). Kernen fra `040aa79` og fra `HEAD` er hentet med `git archive` til hver sin mappe (`qa/old`, `qa/new`). De er kørt i **hver sin PHP-proces**, fordi funktionsnavnene er ens.
- Der er ikke lavet ændringer i repoet ud over denne rapport. `config.php`, `config.local.php`, `.env`, `uploads/` og `storage/` er ikke rørt. Kims fil er kun læst fra `Downloads` og ikke kopieret ind i repoet.
- **Siden uden database:** `supplier_upload.php` er kørt i en stub (`qa/web_head/`, `qa/web_040/`) med en falsk `auth.php` og en falsk PDO. Stubben fanger `flash()` og `redirect()` og skriver preview-filer i scratchpad, ikke i `storage/`. Den dækker sidens egen logik (token, fejlbesked, redirect, visning), men **ikke** CSRF, login, rettigheder og SQL.
- **Mutationstest:** 18 bevidste fejl er lagt ind i en kopi af kernen, og enhedstesten er kørt mod hver (se "Testhuller").
- Før/efter af alle de nye tests: de nye tests er også kørt mod kernen fra `040aa79` (se kriterie 39 og 40).

### Samlede kørsler

| Kørsel | Resultat |
|---|---|
| `bash tests/run.sh` på `98106a3` | trin 1 (php -l alle filer), 2 (release-negativtest) og 3 (enhedstest: **248 tjek bestået, 0 fejlede**) er grønne. Trin 4 (installation, røgtest, `supplier_import_db_test.php`, `supplier_upload_flow_test.php`) **sprunget over** (`HSG_TEST_DB_*` ikke sat). Slutlinje: ALLE TESTS BESTÅET. |
| `php -l` på de 5 ændrede PHP-filer | ingen syntaksfejl |
| `php tools/build-release.php --test-negative` | ALLE 7 NEGATIVE TESTS (A–G) BESTÅET. Se bemærkning 8 om test B og C. |
| Den nye `tests/supplier_import_test.php` kørt mod kernen fra `040aa79` | **210 bestået, 34 fejlede.** Fejlene ligger i case 39, 40, 44, 46, 47, 48, 49, 50, 56, 57, 58 og 59. Case 41, 42, 45 og 51 består også på den gamle kerne, som spec'en siger ("uændret"). |
| Egne scripts R1, R2, R3, R4, KAN-3, KAN-4 og kriterie 54 | se nedenfor |
| Mutationstest | 15 af 18 fejl opdaget, 3 overlevede (se "Testhuller") |

## Acceptkriterier

| Nr. | Kriterie (kort) | Resultat | Bevis |
|---|---|---|---|
| 39 | R1a: brand-filter + gammel alias 12-1123a → 21 | BESTÅET | Case 39 grøn på `HEAD` og **rød på `040aa79`** (4 tjek: `match.id ≠ 21`, flag, bemærkning, ikke forvalgt). Testdata svarer til spec'en (produkt 21 brand 9, brand-filter 5, fake-PDO med aliaset, `clynelish_rows()[1]`). Eget script `qa/r1.php` case A: `match=0 flags=["variant_split"] sel=nej`; på `040aa79`: `match=21 score=100 sel=JA`. |
| 40 | R1b: basen kun koblet via alias HSG-55 | BESTÅET | Case 40 grøn på `HEAD`, **rød på `040aa79`** (3 tjek: `12-1123` fik `match=0`, `match_conflict`, `12-1123a` beholdt 55). Eget script case F og G (med og uden brand-filter): `12-1123 match=55 sel=JA`, `12-1123a match=0 variant_split`. |
| 41 | Brand-filter respekteres: kompatibel variant flettes ikke | BESTÅET | Case 41 grøn (også på `040aa79`, som spec'en forventer). `merged_skus=[]`, `source.sku=17-1030a`, intet `variant_split`, `match.id ≠ 30`. Eget script `qa/r1b.php` (Jane Street, brand 5): identisk resultat på `040aa79` og `HEAD`. Med produktets eget brand (9) og uden brand-filter flettes varianten som før (`merged=["17-1030a"] match=30`). |
| 42 | Gemt alias for basen: varianten flettes ind | BESTÅET | Case 42 grøn: `match.id=30`, `source.sku=17-1030`, `merged_skus=['17-1030a']`. Eget script `qa/r1b.php` (alias 17-1030 → 30, brand 5): `source.sku=17-1030 merged=["17-1030a"] match=30 score=100`, som på `040aa79`. Eget script `qa/r1.php` case L og M (HSG-55, brand 5 og uden filter): `merged=["12-1123a"] match=55 sel=JA`. |
| 43 | Eksisterende test 21, 22, 23, 23b, F2, 26 uændret grønne | BESTÅET | Alle 248 tjek grønne. Testfilen er kun udvidet (ingen af de gamle cases er ændret; `git diff` viser kun tilføjelser og kommentaren i filhovedet). |
| 44 | R2: FINISH/CRAFT tæller som tomt | BESTÅET | Case 44 grøn, rød på `040aa79`. Eget script `qa/r2.php`: 276 par (23 × 12 fadnumre), før/efter. Alle forskelle er par, hvor den ene side er ord uden ciffer (FINISH, CRAFT, STRENGTH, NO CASK, #). |
| 45 | R2: rigtige fadnumre afviser stadig | BESTÅET | Case 45 og gammel case 20 grønne. Eget script: `R10/123` mod `R10/124`, `100` mod `#200`, `1st fill` mod `2nd fill`, `0` mod `00`, `4567` mod `R10/123` giver stadig `AFVIST`. `1st fill` og `0` tæller som fadnumre (har et ciffer). |
| 46 | R2: `match_score` | BESTÅET | Case 46 grøn, rød på `040aa79` (98, "fadnummer er identisk"). Nu `0 / svagt match`. `231584/231584` giver stadig `98`. Eget script: STRENGTH, FINISH, CRAFT og `#` giver ikke længere 98; `R10/123`, `#200`, `1ST FILL`, `0` giver stadig 98; `#200` mod `200` giver (uændret) ikke 98. |
| 47 | R3: tabellen (10 ugyldige, 9 tal) | BESTÅET | Case 47 grøn, rød på `040aa79` (10 tjek). Tabellen er kørt cellevis i `qa/r3.php`: se "Ekstra kanttilfælde, R3". |
| 48 | R3: `3 + 2` i en række med produkt | BESTÅET | Case 48 grøn, rød på `040aa79`. `stock_file.locations = [stock_loc_2=>0, stock_loc_3=>12]`, `invalid=[stock_loc_1=>'3 + 2']`, ingen `stock_loc_1` i `changes`, bemærkning med "Bemærk: Ugyldig værdi „3 + 2“ for Christiansfeld – lokationen ændres ikke." |
| 49 | R4: `SKU, Product, Stock, Price, Trade Price, Retail Price (inc VAT)` | BESTÅET | Case 49 grøn, rød på `040aa79`. Resultat `[sku, name, stock_quantity, '', wholesale_price, retail_price]`. |
| 50 | R4: brugerens eksempel (kolonne 2–4) | BESTÅET | Case 50 grøn, rød på `040aa79`: `''`, `wholesale_price`, `retail_price`. |
| 51 | R4: tre uændrede overskriftssæt | BESTÅET | Case 51 grøn (også på `040aa79`). Se også 4000 tilfældige sæt i "Ekstra kanttilfælde, R4". |
| 52 | Kims overskrifter: test 1, 2, 4, 5, 6, 8 uændrede, samme kolonnevalg | BESTÅET | Alle grønne. Desuden `qa/r4.php`: kolonnevalg for `kim_headers()` er **identisk** før og efter, og for filens egne overskrifter (kriterie 54). |
| 53 | `bash tests/run.sh` helt grøn inkl. database- og flowtest | **DELVIST – IKKE FULDT KØRT** | Trin 1–3 grønne. Trin 4 (installation, røgtest, `supplier_import_db_test.php`, `supplier_upload_flow_test.php`) kræver MySQL og er **ikke kørt**. |
| 54 | Før/efter på Kims rigtige fil | BESTÅET | Se afsnittet "Kriterie 54". Brugt: `lager 18-10-2026.xlsx` (filen "lager 19-10-2026.xlsx" findes ikke på maskinen). |
| 55 | Modulversion 1.1.1 og dokumentation | BESTÅET | `modules/supplier_upload/module.php`: `'version'=>'1.1.1'`. `docs/SUPPLIER-UPLOAD.md` har alle fire ændringer fra spec'ens afsnit 7 (se nedenfor). Hovedspec'ens tabel 3.4 har den nye tekst. Intet `migrations.php`; `core/modules.php:139` hæver versionen uden migrations. |
| 56 | KAN-1: amber bemærkning | BESTÅET | Case 56 grøn, rød på `040aa79`. Noten er præcis "Bemærk: Ingen tal i lokationskolonnerne – lageret ændres ikke." I stub-siden står den som `<small style="color:var(--amber);font-weight:600">`, fordi siden farver alle noter, der starter med "Bemærk". På Kims fil giver ændringen 40 rækker med ny tekst (se kriterie 54). |
| 57 | KAN-2: hjælpetekst under reservationsvalget | BESTÅET (kilde og stub) – flowtest IKKE KØRT | Case 57 grøn (kilde), rød på `040aa79`. Stub-siden med et gyldigt preview viser `<small>Et nyt valg bruges først, når du har trykket 'Genberegn preview'. Tallene beregnes igen med de aktuelle reservationer, når du trykker 'Anvend'.</small>` efter formularen. Teksten på den **rigtige** side (flowtestens nye tjek i case "upload") er ikke kørt. |
| 58 | KAN-3: CSV med eksplicit escape | BESTÅET | Case 58 grøn, rød på `040aa79`. Eget script `qa/csv.php`: 10 CSV-varianter (kvoter, `\"`, `""`, æøå, flerlinjefelt, BOM, tom, blank linje) giver **identisk** resultat før og efter. |
| 59 | KAN-4: ét tokentjek | BESTÅET (enhed og stub) – flowtest IKKE KØRT | Case 59 grøn: sand for 40 små hex-tegn, falsk for `''`, `../x` og 40 store. Stub (`qa/web_driver.php`), POST `action=apply` med `../x`: fejlbesked "Kunne ikke anvende ændringer: Ugyldigt preview-token.", redirect til `supplier_upload.php` **uden** token, ingen SQL-skrivning (ingen INSERT/UPDATE/DELETE/BEGIN/COMMIT). På `040aa79` var redirect `supplier_upload.php?preview=..%2Fx`. Samme resultat for `remap`, `reguess`, `reservation_mode`, tom streng, 39 og 41 tegn, 40 store tegn og `<40 tegn>/../x`. GET med `../x` viser upload-siden uden preview og uden fejl-flash. Flowtestens nye case 59 mod rigtig server, CSRF og database er **ikke kørt** (gennemlæst: hjælperne `item_index`, `db_stock`, `q1`, `has_flash`, `Http::post/get/follow` findes, og `loc` er header-værdien). |

### Kriterie 55: dokumentationen mod spec'ens afsnit 7

| Krav i spec'en | Fundet i `docs/SUPPLIER-UPLOAD.md` |
|---|---|
| "Kolonnegæt" punkt 3: delvise match i to trin | Ja, med de to trin og eksemplerne `SKU, Product, Stock, Price, Trade Price, Retail Price (inc VAT)`, `SKU, Price`, `SKU, Price, Retail Price (inc VAT)` og `SKU, Trade Price, Price`. Eksemplerne passer med case 49 og 51. |
| "Varenumre med bogstav": basens ejer uden brand-filter, kun til at udelukke, og brand-filteret respekteres ved fletning | Ja, to nye punkter. Passer med eget script `r1.php` (case A, E, F, K, L). |
| "Produktmatch": fadnummer kræver et ciffer | Ja ("Finish", "Strength", "Craft"). |
| "Tomme lokationsceller": hvad er et tal, eksempler, tusindtalsformater ugyldige | Ja, med tabel. Alle rækker i tabellen er kørt (se R3). |

## Kriterie 54: før og efter på Kims fil

**Fil:** `lager 18-10-2026.xlsx` (1 ark, 378 rækker, overskrifter i række 1, 377 datarækker). Kun læst. Intet af indholdet er gemt i repoet.

**Metode** (`qa/inspect.php`, `qa/compare.php`, `qa/cmpdiff.php`, `qa/cmpnorm.php`, `qa/mkhybrid.php`):
1. Filen er læst med `hsg_supplier_read_xlsx`. Bedste ark og overskriftsrække er fundet som i `hsg_supplier_prepare_preview` (`hsg_supplier_detect_header`, `start = række + 1`).
2. `hsg_supplier_suggest_columns` er kørt uden husket valg, med `hsg_supplier_allowed_fields([1,2,3])`. Kims lokationer R/S/T (kolonne 17, 18, 19) er derefter sat til `stock_loc_1/2/3`, som i testene.
3. `hsg_supplier_merge_raw_items` er kørt med `$pdo = null` og samme kolonnevalg, i to PHP-processer (kerne fra `040aa79` og fra `HEAD`).
4. Tre produktsæt: **(a)** tomt. **(b)** 206 syntetiske HSG-produkter bygget af filens egne varenumre og navne (SKU-match, navnematch med og uden støj, samme SKU med andet navn, og et basisprodukt for bogstav-varianter, halvdelen med samme navn og halvdelen med et andet). **(c)** de samme 206 produkter fordelt på to brands, med brand-filter på det ene (for at udløse R1).
5. Dumpet som JSON (kolonnevalg, `source`, `stock_file`, `notes`, `flags`, `match`, `selected`, `changes`, `merged_skus`, `reserved`) og sammenlignet nøgle for nøgle.

**Kolonnevalget er identisk** (før og efter lokationerne er lagt på):
`sku=E(4)`, `name=F(5)`, `age_text=G(6)`, `distillery=H(7)`, `brand_name=J(9)`, `abv=K(10)`, `stock_quantity=Q(16)`, `wholesale_price=Z(25)`, `retail_price=AA(26)`, og R/S/T som lokationer. Det er præcis den liste, spec'en forventer under R4. De øvrige kolonner er `''`.

**Lokationscellerne (R3):** 377 rækker × 3 lokationer = 1131 celler: 649 tomme, 482 heltal, **0 decimaltal og 0 ugyldige**. `hsg_supplier_location_cells` giver identisk resultat for alle 377 rækker før og efter. Derfor er der ingen R3-forskel i filen. De to andre uploadfiler i `Downloads` (`Kopi af lager 18-10-2026 (003).xlsx` og `lager liste d. 06-09-2026.xlsx`) er kun læst for celletyperne: også dér 0 decimaltal og 0 ugyldige.

### Resultat

| Produktsæt | Varer (gammel / ny) | Forskelle i alt | Forskelle efter KAN-1-tekst er trukket fra | Forklaring |
|---|---|---|---|---|
| (a) tomt | 291 / 291 | 40 | **0** | 40 rækker: KAN-1 |
| (b) syntetiske produkter | 290 / 290 | 47 | **8** | 40 × KAN-1, 8 × R2 (række 21 er med i begge tal) |
| (c) som (b), brand-filter | 289 / 289 | 48 | **9** | 40 × KAN-1, 8 × R2, 1 × R1 (række 21 er med i både KAN-1 og R2) |

Der er **ingen forskel i** `source`, `stock_file`, `reserved`, `total_stock` eller `is_create_new` i nogen af de tre sæt. Alle forskelle ligger i `notes`, `match`, `changes`, `flags` og `selected`.

**Forskel 1 – KAN-1 (40 rækker, i alle tre sæt).** Rækker uden tal i R/S/T får noten "Bemærk: Ingen tal i lokationskolonnerne – lageret ændres ikke." i stedet for den uden "Bemærk:". Rækkerne (Excel-rækkenumre): 2, 21, 30, 35, 192, 221, 230, 246, 250, 275, 281, 291, 313, 318, 319, 320, 323, 328, 329, 330, 331, 333, 341, 342, 343, 344, 346, 351, 352, 353 og 361–370. Ingen af dem har varenummer. Bemærkning: spec'ens kriterie 54 nævner kun R2 og R3 som tilladte årsager, men KAN-1 (kriterie 56) er en bevidst tekstændring og forklarer forskellen fuldt ud.

**Forskel 2 – R2 (8 rækker i (b), 8 i (c)).** Rækkens fadnummer er udledt som et ord uden ciffer (CRAFT eller FINISH) og er tidligere talt som et fadnummer. Alle 8 har et fadnummer uden ciffer:

| Række | Varenummer | Fadnummer (udledt) | Før | Efter |
|---|---|---|---|---|
| 21 | (ingen) | CRAFT | match 98 "fadnummer er identisk" | match 96 "produktnavn er identisk" (samme produkt) |
| 22 | 11-2007 | CRAFT | reason med "fadnummer er identisk" | samme produkt og score 99, reason uden fadnummer |
| 27 | 11-2010 | CRAFT | falsk match på 11-2007's produkt, `match_conflict`, ikke forvalgt | eget produkt (99), ingen konflikt, **forvalgt** med 5 feltændringer |
| 28 | 11-2011 | CRAFT | som 11-2010 | som 11-2010 |
| 159 | 12-1087 | FINISH | reason med "fadnummer er identisk" | samme produkt, reason uden |
| 115 | 12-1016 | FINISH | falsk match på et andet produkt, `match_conflict` | intet match (score 9), "Nyt produkt" forvalgt |
| 185 | 12-1124 | FINISH | som 12-1016 | som 12-1016 (score 8) |
| 205 | 12-1128 | FINISH | som 12-1016 | som 12-1016 (score 5) |

I (c) er forskellene af samme art, men fordelingen er en anden, fordi brand-filteret ændrer, hvilke produkter der kan matches (rækkerne 21, 27 og 159 mister en falsk konflikt uden at få et nyt match). Det er den fejl, R2 skal rette: to forskellige varer blev matchet og forvalgt, fordi de begge havde fadnummeret CRAFT eller FINISH. Rækkefølgen af varerne i listen ændres i (b) og (c), fordi en konfliktrække ikke længere lægges sidst.

**Forskel 3 – R1 (1 række i (c)).** Række 5, varenummer **11-1004a**: nu `variant_split` og bemærkningen "11-1004a er ikke flettet med 11-1004 …"; før var den en almindelig række. Basen 11-1004 ligger i HSG uden for det valgte brand, og varianten er ikke samme vare. Det er R1's F2-regel (ejeren findes uden brand-filter og bruges til at udelukke).

**Bevis for forklaringerne.** Der er bygget en hybrid af den gamle kerne med **kun** R2 (to linjer) og KAN-1-teksten (`qa/mkhybrid.php`). Hybriden er kørt på alle tre sæt og sammenlignet med `HEAD` varer for vare:

| Sæt | Forskelle mellem "gammel + R2 + KAN-1" og `HEAD` |
|---|---|
| (a) | 0 |
| (b) | 0 |
| (c) | 1 – række 5, varenummer 11-1004a, som er R1 |

Der er altså **ingen uforklarede forskelle**. Kriterie 54 er bestået.

## Udviklerens afvigelser

### R3: komma erstattes med punktum før læsning – godkendt, bør stå i spec'en

Spec'en siger, at værdien "læses som i dag med `hsg_supplier_parse_decimal`". Koden kalder den med `str_replace(',','.',$s)`.

- Uden erstatningen læser `parse_decimal` et komma med andet end 1–2 decimaler som tusindtalsseparator. Målt på den gamle kerne: `40,999999999999993` → **40999999999999992**, og `2,5000` → **25000**. Mutation M3 (erstatningen fjernet) får case 47 til at fejle.
- Ingen celle, der er gyldig efter spec'ens regel, får et andet resultat. Der er kun forskel for komma med 4 eller flere decimaler. Disse kan efter reglen ikke være tusindtalsformater (præcis tre cifre er ugyldige).
- Kun `40,999999999999993` er dækket af case 47. `2,5000` er ikke i en test (se "Testhuller").

### KAN-4: regex med `$` i stedet for `\z` – i overensstemmelse med spec'en, men se bemærkning 2

Spec'en foreskriver "den eksisterende regex `/^[a-f0-9]{40}$/`", så `$` er korrekt. En konsekvens er, at `hsg_supplier_preview_token_valid("<40 hex>\n")` giver **sand**, fordi `$` også matcher før en afsluttende linjeskift. I praksis er det harmløst, fordi alle kald i `supplier_upload.php` bruger det `trim`-ede token, og et linjeskift kan ikke give en sti uden for mappen.

## Ekstra kanttilfælde

### R3: lokationsceller (`qa/r3.php`, gammel mod ny)

| Celle | Før | Efter |
|---|---|---|
| `3 + 2` / `2 (1 i bil)` / `1/2` / `ca. 5` / `se note 2` / `3 2` | 32 / 21 / 12 / 1 / 2 / 32 | alle ugyldige |
| `1.234` / `1,234` / `1 234` / `1 000` / `12,345` / `123.456` / `2,125` / `0.999` | 1 / 1234 / 1234 / 1000 / 12345 / 123 / 2125 / 1 | alle ugyldige |
| `1,2,3` / `1.2.3` / `1.234,5` / `1,234.5` | 123 / ugyldig / 1235 / 1235 | alle ugyldige |
| `−2` (Unicode-minus) | **2** | ugyldig |
| `5kr` / `5 stk` / `5 flasker` / `l0` / `1e3` / `2.5E+2` | 5 / 5 / 5 / 0 / 13 / 3 | alle ugyldige |
| `41.` / `5.` / `5,` / `.5` / `+5` | 41 / 5 / 5 / 1 / 5 | alle ugyldige (følger spec'ens regex) |
| `41` / `0` / `41.0` / `007` / `0,0` / `0.00` | 41 / 0 / 41 / 7 / 0 / 0 | uændret |
| `2,4` / `2.6` / `-2` / `-0,4` / `-5,5` | 2 / 3 / 0 / 0 / 0 | uændret |
| `0,5` / `0.5` | 1 / 1 | uændret (afrunding op) |
| NBSP, U+202F og U+2003 i enderne, tabulator | 5 / 7 / 5 | uændret |
| `40.999999999999993` / `40,999999999999993` | 41 / **40999999999999992** | 41 / 41 |
| `2,5000` / `2.5000` | **25000** / 3 | 3 / 3 |
| `1.2345` (fire decimaler) | 1 | 1 |
| tom / kun mellemrum | tom | tom |
| `5\n`, `3\n2` | 5 / 32 | 5 / ugyldig |
| `99999999999999999999` | PHP-advarsel | PHP-advarsel (uændret, se bemærkning 6) |

Ægte XLSX (en syntetisk fil lavet med `qa/mkxlsx.php`, læst med `hsg_supplier_read_xlsx`): `41`, `40.999999999999993`, `2.5`, `0`, `-2`, `0.30000000000000004`, `2.9999999999999996`, `41.0` og `2,5` opfører sig som i tabellen. Et rent tal med **præcis tre decimaler** (`1.875`) i en talcelle bliver nu ugyldigt (før 2); se bemærkning 3.

### R2: fadnumre (`qa/r2.php`, 276 par og 16 score-tilfælde)

Alle forskelle mellem gammel og ny kerne er par, hvor mindst den ene side er et ord uden ciffer. Ingen par med to rigtige fadnumre har ændret resultat. `hsg_extract_cask_number` er uændret og giver stadig FINISH, CRAFT og STRENGTH for "Cask Finish", "Cask Craft" og "Cask Strength" (Q2).

### R4: kolonnegæt (`qa/r4.php`, `qa/r4fuzz.php`, `qa/r4ref.php`)

- 30 overskriftssæt (danske og engelske leverandørfiler, Kims overskrifter og spec'ens fem eksempler): **kun de to sæt fra spec'en ændrer sig** (kriterie 49 og 50). De øvrige 28, inklusive Kims, er identiske.
- 4000 tilfældige sæt på 2–9 overskrifter: koden er sammenlignet med en uafhængig referenceimplementering af spec'en (skrevet af testeren ud fra spec'en). **0 afvigelser.** 21 af de 4000 sæt ændrer sig i forhold til `040aa79`: 5 er "Price/Pris" som i spec'en, 16 er en sideeffekt (bemærkning 4).

### R1: basens ejer (`qa/r1.php`, 16 scenarier, gammel mod ny)

| Scenarie | Før | Efter |
|---|---|---|
| A: brand 5, alias 12-1123a → 21, kun varianten i filen (kriterie 39) | match 21, forvalgt | `variant_split`, intet match, ikke forvalgt |
| B/C: samme uden brand-filter og med produktets brand | `variant_split` | uændret |
| D: som A, men et andet produkt P22 i brand 5 med varianten's navn | match 21 | `variant_split`, match **P22** (99), ikke forvalgt: reglen "falder tilbage til almindelig score" |
| E/P: brand 5, ingen alias, basen ligger i brand 9 (også med `$pdo = null`) | almindelig række, "Nyt produkt" **forvalgt** | `variant_split`, ikke forvalgt (bemærkning 5) |
| F/G: kriterie 40, med og uden brand-filter | `match_conflict`, 12-1123a beholder 55 | 12-1123 beholder 55, 12-1123a står alene |
| H/I: basen i filen, basen i HSG i et andet brand | `variant_split` | uændret |
| J: to varianter af samme base, begge med alias | match 21 + `match_conflict` | begge `variant_split`, intet match |
| K: basen kun alias-koblet (HSG-55), uforenelig variant uden alias | `variant_split` | uændret (bemærkningen bruger HSG-55 som base) |
| L/M: som K, men varianten er samme vare | flettet ind i HSG-55 | uændret, med og uden brand-filter |
| N: versaler `12-1123A` | match 21 | `variant_split` |
| O: alias peger på produkt, der ikke findes | almindelig række | `variant_split` |

Ejeren bruges kun til at udelukke: ingen scenarie giver en fletning eller et match, der ikke kunne ske før. Antallet af alias-opslag stiger med 1 pr. base (cachet pr. base).

## Bemærkninger (blokerer ikke)

1. **R3-afvigelsen bør skrives ind i spec'en** (komma er altid decimaltegn, når cellen har højst ét tegn). Se ovenfor.
2. **KAN-4:** `hsg_supplier_preview_token_valid` accepterer `<40 hex>\n`. Harmløst i dag (alle kald bruger et `trim`-et token), men en fælles validator, der "afviser alt andet, før der bygges en filsti", kunne bruge `\z` eller modifikatoren `D`. Spec'en foreskriver `$`.
3. **Spec'ens påstand om XLSX er upræcis.** Der står, at tusindtalsreglen "kun rammer tekstceller og CSV". En talcelle i XLSX med præcis tre decimaler (`1.875`, fx et formelresultat) rammes også, fordi læseren gemmer tal som tekst. Brøkdele af flasker er ikke realistiske, så "ugyldig" er det sikre udfald. Kims filer har ingen sådanne celler.
4. **R4-sideeffekt ud over "Price".** Reglen "overskrift indeholder alias før alias indeholder overskrift" gælder alle felter. Står både "Name" og "Item Description" i filen, bliver produktnavn nu "Item Description" (2a); før var det "Name" (2b), som nu bliver ignoreret. 16 af 4000 tilfældige sæt. Kims fil og de to eksempler i spec'en er upåvirket. Spec'ens tabel nævner kun "Price".
5. **R1 ændrer også sager uden alias.** Med brand-filter og en uforenelig variant, hvis base ligger i et andet brand, er varianten nu `variant_split` og ikke forvalgt; før var den en almindelig række med "Nyt produkt" forvalgt (scenarie E/P og række 11-1004a i kriterie 54). Det følger af F2-reglen og er sikrere, men spec'ens afsnit 1 ("Bagudkompatibelt") nævner kun R2 og R3 som tilladte forskelle. R1, R4 (Price) og KAN-1 ændrer også resultater. Afsnit 1 bør rettes.
6. **Uændret fra før, men set under testen:** (a) en celle med kun et hårdt mellemrum (U+00A0) er ugyldig, ikke tom, fordi PHP's `trim()` ikke fjerner den. (b) En tekst på 100.000 tegn lander i fuld længde i noten og to gange i preview-JSON (200 KB); R3 gør, at flere tekster ender her, fordi tekst med ciffer tidligere blev til et tal. (c) 20 cifre i en celle giver en PHP-advarsel ("not representable as an int"). En afkortning af den rå tekst i noten er et forslag til en opfølgning.
7. **GET med et ugyldigt token** viser nu upload-siden uden fejlbesked (før: "Ugyldigt preview-token."). Det er som spec'en siger, men brugeren får ingen forklaring på et ødelagt link.
8. **Release-negativtest B og C** (`--test-negative`) blev afvist med "PHP-udvidelsen pdo_mysql mangler", ikke af den tilsigtede årsag, fordi `pdo_mysql` mangler lokalt. De to er altså ikke bevist af denne kørsel. Ændringen berører ikke release-værktøjet. Hovedspec'ens kriterie 10 (linje 508) citerer stadig noten uden "Bemærk:" (den er stadig en delstreng, så testen består).

## Testhuller og anbefalede tests

18 mutanter af den nye kerne er kørt mod `tests/supplier_import_test.php`. **15 blev opdaget**: M1 (tre-cifre-reglen fjernet) og M2 (NBSP ikke strippet) og M3 (komma ikke erstattet) og M15 (negativt tal) alle i case 47; M4 i case 44; M5 i case 46; M6 (2a/2b byttet) i case 49 og 50; M7 (F2 uden ejer) i case 39; M8 (F1-udelukkelse uden ejer) i case 40; M9 (kun-ejer flettes) i case 41; M10 i case 56; M11 i case 59; M12 (ejer slås op med brand-filter) i case 39; M14 (`fgetcsv` uden escape) i case 58; M16 (minimumslængde 8 for `name`) i case 2 og 4.

**3 overlevede** (M13, M17, M18): `exclude` i F2-grenen (`$matchPid` og `$ownerPid`) kan fjernes uden at en test fejler. Det er det gamle udelukkelses-hul, som R1 skulle lukke i F2. Foreslået test (scenarie, som er kørt i `qa/r1gap.php` og er rigtigt på `HEAD`): produktet `HSG-55` (brand 9), aliaser `12-1123 → 55` og `12-1123a → 55`, kun varianten 12-1123a i filen (et andet navn end HSG-55), med `$brandId` 9, 5 og `null`. Forventet: `variant_split`, `match.id ≠ 55`, ikke forvalgt. På en mutant uden udelukkelsen får varianten `match=55`.

Øvrige forslag til tests (kanttilfælde, der er målt ovenfor, men ikke ligger i en test):
- R3: `−2` (Unicode-minus), `2,5000`, `0,5`, `1.2345`, `41.` og `1.234,5`.
- R4: et sæt med "Name" og "Item Description" (hvis sideeffekten skal fastholdes) og `['SKU','Retail Price','Price']`.
- R2: `1st fill` og `0` tæller som fadnumre.

Jeg har **ikke** lagt tests ind i `tests/`, fordi opgaven var at committe kun rapporten. Scripts og rå output ligger i scratchpad under `qa/`.

## Hvad er IKKE kørt

| Hvad | Hvorfor | Konsekvens |
|---|---|---|
| `tests/run.sh` trin 4: installation, røgtest af alle sider, 419 uden CSRF, 401 uden login for `reservation_mode`/`reguess` | ingen MySQL | Siden er kun kørt i stub. Sidens guards (`require_capability('imports.manage')`, CSRF i `auth.php:75`) er ikke ændret af branchen (`git diff` viser kun to ændringer i `supplier_upload.php`: tokentjekket og hjælpeteksten), men er ikke prøvet over HTTP. |
| `tests/supplier_import_db_test.php` | ingen MySQL | Ikke kørt. |
| `tests/supplier_upload_flow_test.php` (inkl. kriterie 57 på den viste side og POST-delen af kriterie 59 mod rigtig server) | ingen MySQL | Erstattet af stub-kørsel (se kriterie 57 og 59). |
| Adgang som direkte linkbruger og uden login | ingen MySQL | Ikke prøvet. Koden er uændret. |
| Mobilbredde | ingen layoutændring | Eneste HTML-ændring er en sætning i en eksisterende `<small>` på en adminside. Ikke målt. |
| PHP 8.1 | kun 8.5.8 installeret | Ikke prøvet. |
| Test B og C i release-negativtesten | `pdo_mysql` mangler | Se bemærkning 8. |

## Før release

- Kør `bash tests/run.sh` med MySQL (`HSG_TEST_DB_*`), og få trin 4 grønt.
- Opdater `app_version.php` (nu `11.1.4`), README og `hsg-package.json` via `php tools/build-release.php <version>`. Det er ikke gjort på branchen, og det er som spec'ens afsnit 5 beskriver ("ved release").
- Der kommer ingen test- eller dev-filer med i en release: `tools/build-release.php` fjerner `tests`, `docs/specs` og `.claude`. Branchen tilføjer ingen nye filer uden for dem (kun `docs/specs/…`, ændrede filer og denne rapport).

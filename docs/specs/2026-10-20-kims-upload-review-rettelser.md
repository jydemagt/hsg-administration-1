# Tillæg: Kims uploadfil – rettelser efter review af PR #172

- **Dato:** 2026-10-20
- **Status:** Godkendt af brugeren 2026-10-20. Q1 og Q2 tages som separate opfølgninger og er ikke en del af dette tillæg. KAN-1–KAN-4 laves.
- **Hovedspec:** [`docs/specs/2026-10-19-kims-upload-lagerfejl.md`](2026-10-19-kims-upload-lagerfejl.md). Tillægget ændrer kun det, der står her: afsnit 4.1 (K1 punkt 3), 4.3 (cellen "Tekst"), 4.4 (F1–F3) og bemærkningstabellen i 3.4. Alt andet gælder uændret.
- **Branch:** `feature/kims-upload-review-rettelser`. Den bygger på `feature/kims-upload-lagerfejl` (PR #172), som ikke er merget endnu.
- **Modul:** `supplier_upload` ("Kims uploadfil"), capability `imports.manage`
- **Kode:** `core/supplier_import.php` og `supplier_upload.php`. Linjenumrene passer på HEAD af branchen.

---

## 0. Åbne spørgsmål

1. **Q1 – "Lagerantal i alt" med fritekst:** Skal reglen i R3 (cellen skal være ét tal) også gælde kolonnen "Lagerantal i alt"? I tilstand L er tallet kun et kontroltal, så "3 + 2" giver i dag bare en kontrolafvigelse. I tilstand T skrives det derimod på første lokation som 32. **Anbefaling:** ja, men i en lille opfølgning med sin egen bemærkning. Det er ikke med i dette tillæg.
2. **Q2 – Fadnumre uden ciffer i HSG:** `hsg_extract_cask_number` læser stadig "Cask Strength" som fadnummer STRENGTH og "Cask Finish" som FINISH. Importen kan derfor skrive værdien i et tomt fadnummerfelt på et HSG-produkt. Det samme sker i `import.php` og ved opgraderingens backfill i `migrations.php`. Skal det stoppes, og skal eksisterende værdier ryddes op, i en separat opfølgning? R2 gør dem kun uskadelige ved matching.

---

## 1. Formål

Revieweren godkendte PR #172 med fire ikke-blokerende BØR-fund. De rettes her, så **admin** kan stole på previewet:

- En gammel, forkert alias-kobling eller et brand-filter må ikke føre en anden whisky ind på et HSG-produkt (R1).
- Ord som "Finish" og "Craft" i navnet må ikke tælle som fadnummer (R2).
- Fritekst i en lokationscelle må ikke blive til et forkert lagertal (R3).
- En overskrift, der bare hedder "Price", må ikke tage udsalgsprisen fra en mere præcis kolonne (R4).

**Lagermedarbejdere og linkbrugere** bliver berørt indirekte, fordi forkerte lagertal ikke længere når frem til Lagerstatus.

**Bagudkompatibelt:** Kims rigtige fil skal give samme kolonnevalg og samme resultat som efter PR #172. Der må kun være forskelle i to tilfælde:
- rækker med fritekst i en lokationscelle (R3)
- rækker, hvor et fadnummer uden ciffer i dag giver et falsk split, en falsk konflikt eller et falsk match (R2). Den forskel er tilsigtet.

## 2. Brugerflow og skærme

Flowet er uændret, og der er ingen nye skærme, knapper, CSS-klasser eller ændringer i mobilvisningen. Brugeren ser kun:

- færre forkerte forslag i previewet,
- bemærkningen "Ugyldig værdi „…“ for <lokation> – lokationen ændres ikke." i flere tilfælde end i dag (R3). Teksten er uændret.
- de valgfrie tekstændringer i afsnit 4 (KAN-1 og KAN-2).

---

## 3. Regler

### R1 – Basens ejer findes uden brand-filter (ændrer hovedspec 4.4 F1–F3)

**Fejl i dag** (`core/supplier_import.php:464-478`). Rodårsagen ligger i `hsg_supplier_find_match` (`:195-212`): alias-opslaget ignorerer `$brandId` og giver score 100, når alias-produktet findes i `$products`.

| | Situation | I dag | Efter |
|---|---|---|---|
| a | Brand-filter = Clynelish-brandet. Kun 12-1123a (Clynelish) står i filen. HSG-produktet 12-1123 (St. Bridget Kirk) hører til et andet brand. Der findes en gammel alias-kobling 12-1123a → 12-1123. | Basen findes ikke med brand-filter, så split-tjekket springes over. Bagefter matcher alias-opslaget 12-1123a til 12-1123 med 100 %, og rækken er forvalgt. | 12-1123a er ikke flettet. Den får flaget `variant_split`, matcher ikke 12-1123 og er ikke forvalgt. |
| b | 12-1123 og 12-1123a står begge i filen og er forskellige varer. HSG-produktet for basen har SKU HSG-55 og er kun koblet via aliaserne 12-1123 → HSG-55 og 12-1123a → HSG-55. | `exclude` indeholder kun produkter med SKU 12-1123, dvs. ingen. Begge rækker matcher HSG-55. Står 12-1123a først i filen, beholder den matchet, og 12-1123 får `match_conflict`. | 12-1123 matcher HSG-55. 12-1123a matcher ikke HSG-55, får flaget `variant_split` og er ikke forvalgt. |

**Ny regel.** Basens **ejer** er resultatet af `hsg_supplier_find_match(['sku' => <base>], $products, null, $pdo)`, altså opslaget **uden** brand-filter: gemt alias for basen, ellers et produkt med præcis basens SKU. Ejeren beregnes én gang pr. base, ligesom `$baseMatches`. Uden brand-filter er ejeren det samme som matchet, så der er ikke brug for et ekstra opslag.

- **F1 – basen står i filen:** Kompatibilitetstjekket mod basens filrække er uændret. Er varianten ikke kompatibel, er `exclude` = produkter med SKU = basen **plus ejeren**.
- **F2 – basen findes kun i HSG:**
  1. `match` = opslaget **med** brand-filter, som i dag (`$baseMatches`).
  2. Målet er `match`, hvis det har id > 0, ellers ejeren. Er begge 0, gør koden som i dag: ingen fletning og intet split.
  3. Varianten tjekkes mod målet med `hsg_supplier_variant_compatible`.
     - **Ikke kompatibel:** split. Bemærkningen bruger målets SKU som base, og `exclude` = produkter med SKU = basen + `match`-id + ejer-id (de id'er, der er > 0).
     - **Kompatibel, og `match` har id > 0:** fletning som i dag.
     - **Kompatibel, men kun ejeren fandtes** (ejeren ligger uden for det valgte brand): ingen fletning og intet split. Varianten matches som en almindelig række med brand-filteret, som i dag.

**Beslutning: brand-filteret respekteres.** Ejeren uden brand bruges **kun** til at udelukke (split/exclude) og til kompatibilitetstjekket. Varianten flettes ind i basen, præcis som i dag, kun når opslaget med brand-filter (eller uden filter) giver ejeren. Begrundelse:
- Det er den mindste ændring. Den tilføjer kun udelukkelser, så den kan aldrig få lager til at blive skrevet på et produkt, som ikke allerede kunne få det i dag.
- Når admin har begrænset uploaden til ét brand, må den ikke skrive lager på et produkt fra et andet brand.

En gemt alias-kobling for basen giver fortsat ejeren, også med brand-filter, fordi alias-opslaget ikke ser på brand. Fletningen sker så som i dag (kriterie 42).

**Alias-opslaget ser kun én række** (`ORDER BY id DESC LIMIT 1`). `hsg_supplier_remember_alias` sletter ældre koblinger fra samme leverandør-SKU, så der er normalt højst én række. Peger den nyeste række på et udelukket produkt, prøves ældre rækker ikke. Matchet falder tilbage til almindelig score (SKU, fadnummer, navn) med brand-filter. Det er tilsigtet.

### R2 – Fadnummer uden ciffer tæller som tomt

**Fejl i dag:** `hsg_extract_cask_number` (`core/product_enrichment.php:10`) giver FINISH for "… Sherry Cask Finish", CRAFT for "Cask Craft" og STRENGTH for "Cask Strength". Det giver falske resultater:
- I `hsg_supplier_variant_compatible` (`$caskOf`, `:383-385`) giver det et falsk "forskelligt fadnummer (#FINISH / #4567)" og dermed et falsk `variant_split` eller `match_conflict`.
- I `hsg_supplier_match_score` (`:180-181`) giver det et falsk "fadnummer er identisk" med score 98, når både filrækken og HSG-produktet har fx STRENGTH. To forskellige "Cask Strength"-whiskyer bliver dermed matchet og forvalgt. HSG-produkter kan allerede have den slags værdier fra tidligere import eller backfill.

**Ny regel:** Et fadnummer, der ikke indeholder mindst ét ciffer (0–9), tæller som tomt. Reglen gælder to steder:
- **`hsg_supplier_variant_compatible`:** `$caskOf` returnerer `''`, når værdien efter den nuværende normalisering (uden `#` og mellemrum, store bogstaver) ikke har noget ciffer. Stopregel S1 rammer derfor kun rigtige fadnumre.
- **`hsg_supplier_match_score`:** "fadnummer er identisk" (98) kræver desuden, at fadnummeret indeholder et ciffer. Sammenligningen er ellers uændret. Der indføres ingen ny fjernelse af `#` eller mellemrum, for at holde ændringen smal.

**Ikke ændret:** `hsg_supplier_source_fields` og `hsg_extract_cask_number`. Værdien FINISH står stadig i `source` (se Q2). Rækker, der matcher på SKU (100), rammes ikke, fordi SKU-tjekket kommer før fadnummeret.

| Eksempel | I dag | Efter |
|---|---|---|
| Variant "Benriach 2010 Sherry Cask Finish" (FINISH) over for HSG-produktet med samme navn og fad 4567 | ikke kompatibel: forskelligt fadnummer | kompatibel: samme navn |
| CRAFT over for FINISH, samme navn | ikke kompatibel | kompatibel: samme navn |
| 100 over for #200 / R10/123 over for R10/124 | ikke kompatibel | ikke kompatibel (uændret) |
| match_score: "Glenfarclas 15 Cask Strength" over for "Ardbeg Corryvreckan Cask Strength", begge med fad STRENGTH | 98, "fadnummer er identisk" | under 90, uden "fadnummer er identisk" |

### R3 – En lokationscelle skal være ét tal (ændrer hovedspec 4.3, cellen "Tekst")

**Fejl i dag:** `hsg_supplier_location_cells` (`:117-137`) bruger `hsg_supplier_parse_decimal` (`:93`), som fjerner alt andet end cifre, komma, punktum og minus. Fritekst bliver derfor til forkerte tal, der skrives som lager.

**Ny regel** for hver valgt lokationscelle:
1. **Tom:** `''` efter `trim`, som i dag. Reglen for tomme celler i 4.3 er uændret.
2. **Tal:** Cellen er et tal, når to betingelser er opfyldt, efter at mellemrum, NBSP (U+00A0) og smalt NBSP (U+202F) er fjernet **i begyndelsen og slutningen**:
   - Den passer på `^-?\d+(?:[.,]\d+)?$`, dvs. valgfrit minus, cifre og højst én decimal-separator efterfulgt af cifre.
   - Der står **ikke præcis tre cifre** efter separatoren.

   Værdien læses derefter som i dag med `hsg_supplier_parse_decimal` og afrundes. Negative tal bliver 0.
3. **Ellers ugyldig:** Den trimmede rå tekst lægges i `invalid`, og den eksisterende bemærkning vises.

**Afvigelse fra reviewerens forslag:** Mellemrum **inde i** værdien fjernes ikke. Ellers ville "3 2" blive til 32, hvilket er samme fejlklasse.

**Tusindtalsseparator (undersøgt):**
- `hsg_supplier_parse_decimal("1.234")` giver 1,23, fordi punktum alene læses som decimaltegn. Det bliver til **1** flaske.
- `"1,234"` giver **1234**, og `"1 234"` giver 1234.

Læsningen er altså tvetydig og inkonsekvent. **Beslutning:** Tusindtalsformater er **ugyldige** i lokationsceller: præcis tre cifre efter `.` eller `,`, mellemrum inde i tallet, eller både `.` og `,`. Begrundelse: Ingen af Kims lokationer har 1000 flasker eller mere. XLSX gemmer tal uden tusindtalsseparator, så reglen rammer kun tekstceller og CSV. "Ugyldig" er den sikre tolkning fra 4.3: lokationen ændres ikke, og der vises en bemærkning. `hsg_supplier_parse_decimal` ændres ikke, fordi den bruges til priser.

| Celle | I dag | Efter |
|---|---|---|
| `3 + 2` | 32 | ugyldig |
| `2 (1 i bil)` | 21 | ugyldig |
| `1/2` | 12 | ugyldig |
| `ca. 5` | 1 | ugyldig |
| `se note 2` | 2 | ugyldig |
| `3 2` | 32 | ugyldig |
| `1.234` / `1,234` / `1 234` | 1 / 1234 / 1234 | ugyldig |
| `?` | ugyldig | ugyldig |
| `41` / `0` / `41.0` | 41 / 0 / 41 | 41 / 0 / 41 |
| `2,4` / `2.6` / `-2` | 2 / 3 / 0 | 2 / 3 / 0 |
| `5` + NBSP / U+202F + `7` | 5 / 7 | 5 / 7 |
| `40.999999999999993` (formelresultat) | 41 | 41 |

### R4 – Delvist kolonnegæt i to trin (ændrer hovedspec 4.1, K1 punkt 3)

**Fejl i dag** (`hsg_supplier_suggest_columns`, gennemløb 2, `:316-330`): For hver kolonne godtages det første felt, hvor overskriften indeholder et alias, **eller** hvor et alias indeholder overskriften. En bar "Price" (`price`) står i aliaset `retailprice` og tager derfor udsalgsprisen, før kolonnen "Retail Price (inc VAT)" bliver undersøgt.

**Ny regel:** Gennemløb 2 deles op i to trin:
- **2a:** For alle stadig tomme kolonner fra venstre mod højre: felter, hvor den normaliserede **overskrift indeholder et alias**.
- **2b:** Derefter for de kolonner, der stadig er tomme, fra venstre mod højre: felter, hvor **aliaset indeholder overskriften**.

Følgende er uændret i begge trin: feltrækkefølgen i `hsg_supplier_aliases()`, minimumslængden på aliaset (8 for `name`, ellers 4), `$available` og `hsg_supplier_guess_allowed` (K2 og K4). En kolonne uden match efter 2b sættes til `''`.

**Forventet resultat:**

| Overskrifter | I dag | Efter |
|---|---|---|
| `SKU, Product, Stock, Price, Trade Price, Retail Price (inc VAT)` | sku, name, stock_quantity, **retail_price**, wholesale_price, **''** | sku, name, stock_quantity, **''**, wholesale_price, **retail_price** |
| `Product, Stock, Price, Trade Price, Retail Price (inc VAT)` (brugerens eksempel) | name, sku, retail_price, wholesale_price, '' | name, sku, '', wholesale_price, retail_price |

Bemærk ved brugerens eksempel: "Stock" bliver gættet som **SKU** både i dag og efter rettelsen, fordi aliaset `stockcode` indeholder "stock", når filen ikke har nogen SKU-kolonne. Det er en kendt svaghed og uden for scope. Testen kontrollerer derfor kun kolonne 2–4 i det eksempel.

**Beslutning – en bar "Price":** Den får det første ledige prisfelt i feltrækkefølgen, først engrospris og så udsalgspris, men først **efter** at de præcise overskrifter har taget deres felt. Er begge felter taget, ignoreres kolonnen. Begrundelse:
- Uden andre priskolonner er det samme resultat som i dag (`SKU, Price` → engrospris), så ældre leverandørfiler opfører sig ens.
- Ved siden af "Retail Price" er "Price" typisk handelsprisen, og ved siden af "Trade Price" er den typisk udsalgsprisen. Står begge, er "Price" tvetydig.

**Kims fil** (`kim_headers()`) giver **præcis** samme kolonnevalg som i dag. Begrundelse: Kun Z og AA bliver matchet i gennemløb 2, og de matcher begge i 2a (`engros` og `salgspris`). De øvrige kolonner er i dag tomme, selvom de bliver undersøgt i begge retninger med flere ledige felter. Det giver forventet E=sku, F=name, G=age_text, H=distillery, J=brand_name, K=abv, Q=stock_quantity, Z=wholesale_price og AA=retail_price. Alle andre er `''`.

---

## 4. Valgfrie KAN-punkter (laves, hvis de er billige)

- **KAN-1 – Amber bemærkning:** `:609` ændres til `Bemærk: Ingen tal i lokationskolonnerne – lageret ændres ikke.`, så den vises i amber som de andre. Rækken i hovedspec'ens tabel i 3.4 ændres tilsvarende. Kontrolafvigelsen i `:612` starter allerede med "Bemærk:".
- **KAN-2 – Hjælpetekst ved reservationsvalget** (`supplier_upload.php:267`): `<small>` får teksten "Et nyt valg bruges først, når du har trykket 'Genberegn preview'. Tallene beregnes igen med de aktuelle reservationer, når du trykker 'Anvend'."
- **KAN-3 – CSV:** `hsg_supplier_read_csv` (`:51`) kalder `fgetcsv` med eksplicit enclosure `'"'` og escape `'\\'`. Det giver præcis samme opførsel som i dag, men uden deprecation i PHP 8.4+.
- **KAN-4 – Preview-token valideres ét sted:**
  - Ny ren funktion `hsg_supplier_preview_token_valid(string $token): bool`, der bruger den eksisterende regex `/^[a-f0-9]{40}$/`.
  - Den bruges i `hsg_supplier_preview_save`, `hsg_supplier_preview_load` og `hsg_supplier_preview_delete` (`:799-806`), der i dag har hver sin kopi af regexen.
  - Den bruges også i `supplier_upload.php` lige efter `:45`: et ugyldigt token sættes til `''`.
  - Konsekvens:
    - POST-handlinger med et ugyldigt token får fejlbeskeden "… Ugyldigt preview-token." og omdirigeres til `supplier_upload.php` uden token i URL'en.
    - GET med et ugyldigt token viser upload-siden uden preview.

---

## 5. Data

- Ingen databaseændringer, ingen migration og ingen nye indstillinger i `hsg_settings`.
- Preview-JSON er uændret (`format: 2`).
- **Modulversion:** `modules/supplier_upload/module.php` hæves fra `1.1.0` til `1.1.1` (patch-bump, som i PR #172, uden `migrations.php`).
- App-versionen hæves ved release efter release-tjeklisten i CLAUDE.md.

## 6. Rettigheder

Uændret:
- `require_module_enabled('supplier_upload')` og `require_capability('imports.manage')`.
- CSRF på alle POST-formularer, prepared statements og `h()` på al output.
- Direkte brugerlinks har ingen adgang.
- Der er ingen nye administratorhandlinger og derfor ingen nye audit-hændelser.

## 7. Påvirkede filer

| Fil | Ændring |
|---|---|
| `core/supplier_import.php` | R1 i `hsg_supplier_merge_raw_items` (`:461-478`). R2 i `hsg_supplier_variant_compatible` (`:383`) og `hsg_supplier_match_score` (`:180-181`). R3 i `hsg_supplier_location_cells` (`:118-128`). R4 i `hsg_supplier_suggest_columns` (`:315-330`). KAN-1 (`:609`), KAN-3 (`:51`) og KAN-4 (`:799-806`). Signaturerne er uændret. |
| `supplier_upload.php` | KAN-2 (`:267`) og KAN-4 (`:45`) |
| `tests/supplier_import_test.php` | Nye tests (afsnit 8), fake-PDO og stub af `db_table_exists` |
| `docs/SUPPLIER-UPLOAD.md` | "Kolonnegæt" punkt 3: delvise match i to trin. "Varenumre med bogstav": basens ejer findes uden brand-filter og bruges kun til at udelukke, og brand-filteret respekteres ved fletning. "Produktmatch": fadnummer kræver et ciffer. "Tomme lokationsceller": hvad der tæller som et tal, med eksempler, og at tusindtalsformater er ugyldige. |
| `modules/supplier_upload/module.php` | `version` → `1.1.1` |
| *Ved release* | `app_version.php`, README og `hsg-package.json` (release-tjeklisten) |

---

## 8. Acceptkriterier

Nummereringen fortsætter efter hovedspec'ens 1–38. Testdata og hjælpere (`kim_headers`, `kim_row`, `kim_map`, `hsg_product`, `merge_opts` og `clynelish_rows`) er dem, der findes i `tests/supplier_import_test.php`.

**Testhjælpere til R1.** De er udviklerens implementeringsdetalje, men kriterie 39–42 skal kunne testes sådan:
- **Fake-PDO:** en subklasse af `PDO` med tom konstruktør og et alias-kort `[leverandør-SKU => product_id]`.
  - `prepare()` returnerer en fake statement, fx en klasse, der udvider `PDOStatement` og overskriver `execute()` og `fetchColumn()`.
  - Den svarer kun på alias-opslaget `SELECT product_id FROM hsg_supplier_sku_aliases …`, uden forskel på store og små bogstaver, som MySQL-kollationen. Andre forespørgsler kaster en undtagelse.
- **Stub af `db_table_exists`:** Funktionen ligger i `migrations.php` (ikke i `functions.php`) og er ikke indlæst i enhedstesten. Testen definerer den kun, hvis `function_exists('db_table_exists')` er falsk. Stubben returnerer sand for `hsg_supplier_sku_aliases`.

### R1 – basens ejer og brand-filter

39. **Givet**
    - HSG-produktet `hsg_product(['id'=>21,'sku'=>'12-1123','name'=>'St. Bridget Kirk, John Barleycorn Edition','distillery'=>'N/A','brand_id'=>9,'stock_loc_1'=>15])`,
    - en fake-PDO med aliaset `12-1123a → 21`,
    - kun filrækken 12-1123a fra `clynelish_rows()` (Clynelish, R=9) og `$brandId = 5`,

    **når** `hsg_supplier_merge_raw_items(…, kim_map(false), [P21], 5, 1, $fakePdo, merge_opts())` kaldes, **så**
    - er der én vare med `source.sku` `12-1123a` og `match.id ≠ 21`,
    - indeholder `flags` `variant_split`,
    - har varen en bemærkning med "12-1123a er ikke flettet med 12-1123",
    - er `selected` falsk.

    Testen fejler på den nuværende kode, hvor varen matcher 21 med score 100 og er forvalgt.
40. **Givet**
    - HSG-produktet `hsg_product(['id'=>55,'sku'=>'HSG-55','name'=>'St. Bridget Kirk, John Barleycorn Edition','distillery'=>'N/A','brand_id'=>9,'stock_loc_1'=>24])`,
    - en fake-PDO med aliaserne `12-1123 → 55` og `12-1123a → 55`,
    - `$brandId = 9` og filrækkerne i rækkefølgen 12-1123a (Clynelish, R=9) og 12-1123 (St. Bridget Kirk, R=15),

    **når** rækkerne flettes, **så**
    - har 12-1123 `match.id = 55` og intet `match_conflict`,
    - har 12-1123a `match.id ≠ 55` og flaget `variant_split`, og den er ikke forvalgt.

    Testen fejler på den nuværende kode, hvor 12-1123a beholder 55, og 12-1123 får `match_conflict`.
41. **Givet**
    - HSG-produktet `hsg_product(['id'=>30,'sku'=>'17-1030','name'=>'Jane Street 2015','distillery'=>'Jane','brand_id'=>9,'stock_loc_1'=>1])`,
    - en fake-PDO **uden** aliaser og `$brandId = 5`,
    - filrækken 17-1030a (Jane Street 2015, Jane, R=4),

    **når** rækkerne flettes, **så** er varianten ikke flettet:
    - `merged_skus = []`,
    - `source.sku = '17-1030a'`,
    - intet `variant_split`,
    - `match.id ≠ 30`.

    Det er beslutningen om at respektere brand-filteret og samme resultat som i dag.
42. **Givet** samme data som i 41, men med aliaset `17-1030 → 30`, **når** rækkerne flettes, **så** flettes varianten ind i produktet som i dag: `match.id = 30`, `source.sku = '17-1030'` og `merged_skus = ['17-1030a']`.
43. **Givet** de eksisterende tests 21, 22, 23, 23b, F2 og 26 (`$pdo = null`, intet brand-filter), **så** er de uændret grønne.

### R2 – fadnummer uden ciffer

44. **Givet** `hsg_supplier_variant_compatible(['name'=>'Benriach 2010 Sherry Cask Finish','cask_number'=>'FINISH'], ['name'=>'Benriach 2010 Sherry Cask Finish','cask_number'=>'4567'])`, **så** er resultatet `['ok'=>true,'reason'=>'samme navn']`. **Givet** samme navn på begge sider med `CRAFT` over for `FINISH`, **så** er `ok` sand.
45. **Givet** samme navn med fadnumrene `R10/123` og `R10/124`, **så** er `ok` falsk, og `reason` indeholder "forskelligt fadnummer". Den eksisterende test 20 (`100` over for `#200`) er uændret grøn.
46. **Givet** `hsg_supplier_match_score(['name'=>'Glenfarclas 15 Cask Strength','cask_number'=>'STRENGTH'], ['id'=>1,'sku'=>'X-1','name'=>'Ardbeg Corryvreckan Cask Strength','cask_number'=>'STRENGTH'])`, **så** er `score` under 90, og `reason` indeholder ikke "fadnummer er identisk". **Givet** `hsg_supplier_match_score(['name'=>'Clynelish 2013','cask_number'=>'231584'], ['id'=>2,'sku'=>'X-2','name'=>'St. Bridget Kirk','cask_number'=>'231584'])`, **så** er `score` 98, og `reason` er "fadnummer er identisk" (uændret).

### R3 – lokationsceller

47. **Givet** `hsg_supplier_location_cells(kim_row(['R'=>X]), ['stock_loc_1'=>17])` for hver celle X i tabellen i R3, **så**
    - giver hver ugyldig værdi `['values'=>[],'blank'=>[],'invalid'=>['stock_loc_1'=>X]]`. X er trimmet, fx `'3 + 2'`.
    - giver hvert tal `['values'=>['stock_loc_1'=>N],'blank'=>[],'invalid'=>[]]` med N som i kolonnen "Efter".
48. **Givet** et HSG-produkt med samme SKU som filrækken og Christiansfeld 5, og filrækken R=`3 + 2`, S tom og T=12 (`kim_map(false)`), **når** rækkerne flettes, **så**
    - er `stock_file.locations = ['stock_loc_2'=>0,'stock_loc_3'=>12]` og `stock_file.invalid = ['stock_loc_1'=>'3 + 2']`,
    - har `changes` ingen nøgle `stock_loc_1`,
    - har varen bemærkningen "Bemærk: Ugyldig værdi „3 + 2“ for Christiansfeld – lokationen ændres ikke."

### R4 – kolonnegæt

49. **Givet** `hsg_supplier_suggest_columns(['SKU','Product','Stock','Price','Trade Price','Retail Price (inc VAT)'])`, **så** er resultatet præcis `[0=>'sku',1=>'name',2=>'stock_quantity',3=>'',4=>'wholesale_price',5=>'retail_price']`.
50. **Givet** `['Product','Stock','Price','Trade Price','Retail Price (inc VAT)']`, **så** er kolonne 2 = `''`, kolonne 3 = `wholesale_price` og kolonne 4 = `retail_price`. Kolonne 1 testes ikke (se R4).
51. **Givet** følgende overskrifter, **så** er resultatet uændret fra i dag:

    | Overskrifter | Resultat |
    |---|---|
    | `['SKU','Price','Retail Price (inc VAT)']` | `[sku, wholesale_price, retail_price]` |
    | `['SKU','Trade Price','Price']` | `[sku, wholesale_price, retail_price]` |
    | `['SKU','Price']` | `[sku, wholesale_price]` |
52. **Givet** Kims overskrifter (`kim_headers()`), **så** er de eksisterende tests 1, 2, 4, 5, 6 og 8 uændret grønne. Test 1 giver dermed præcis samme kolonnevalg som efter PR #172.

### Generelt

53. **Når** `bash tests/run.sh` køres, **så** er alle trin grønne, inklusive enhedstesten, databasetesten og flowtesten af Kims uploadfil.
54. **Givet** den rigtige fil "lager 19-10-2026.xlsx" (manuel test; filen må ikke lægges i repoet), **når** previewet laves på PR #172 og på denne branch med samme kolonnevalg og reservationsvalg, **så** er kolonnevalget identisk. Varer, match, ændringer og forvalg er også identiske. Eventuelle forskelle skal kunne forklares med R2 (fadnummer uden ciffer) eller R3 (fritekst i en lokationscelle), og testeren skriver dem i PR'en.
55. **Givet** modulmanifestet, **så** er `version` lig `1.1.1`, og `docs/SUPPLIER-UPLOAD.md` beskriver R1–R4 som angivet i afsnit 7.

### Valgfrie KAN-punkter (kun hvis de laves)

56. **KAN-1:** **Givet** testen 10 (alle lokationsceller tomme), **så** indeholder `notes` præcis "Bemærk: Ingen tal i lokationskolonnerne – lageret ændres ikke.", og bemærkningen vises i amber i previewet.
57. **KAN-2:** **Givet** et preview, **så** står teksten "Et nyt valg bruges først, når du har trykket 'Genberegn preview'." under reservationsvalget.
58. **KAN-3:** **Givet** en midlertidig CSV-fil med indholdet `SKU;Navn` / `12-1;"A;B"`, **når** `hsg_supplier_read_csv` læser den, **så** er resultatet `['CSV'=>[['SKU','Navn'],['12-1','A;B']]]`, og koden kalder `fgetcsv` med eksplicit escape-parameter.
59. **KAN-4:**
    - **Givet** `hsg_supplier_preview_token_valid`, **så** er den sand for 40 små hex-tegn og falsk for `''`, `'../x'` og 40 store hex-tegn.
    - **Givet** en POST `action=apply` med gyldig CSRF og `preview_token=../x`, **så** vises en fejlbesked med "Ugyldigt preview-token.", omdirigeringen går til `supplier_upload.php` uden token, og der ændres ingen data.

---

## 9. Uden for scope

- `hsg_extract_cask_number` og `hsg_supplier_source_fields` udleder stadig fadnumre uden ciffer, og eksisterende værdier i HSG ryddes ikke op (Q2).
- Samme talregel for "Lagerantal i alt" (Q1).
- Alias-opslaget i `hsg_supplier_find_match` respekterer generelt stadig ikke brand-filteret. Med brand-filter kan en vare, hvis HSG-produkt ligger uden for brandet, stadig blive foreslået som "Nyt produkt". Det gælder alle rækker, ikke kun varianter.
- "Stock" gættes som SKU via aliaset `stockcode`, når filen ikke har nogen SKU-kolonne. Logikken for delvise match i `hsg_supplier_detect_header` ændres heller ikke.
- Ændringer i `hsg_supplier_parse_decimal` (den bruges til priser).
- En brugerflade til alias-tabellen og automatisk oprydning i gamle koblinger.

## 10. Åbne spørgsmål til brugeren (opsummering)

1. **Q1:** Skal "Lagerantal i alt" også kræve ét tal, som lokationscellerne gør efter R3? Anbefaling: ja, i en lille opfølgning.
2. **Q2:** Skal fadnumre uden ciffer (STRENGTH, FINISH …) holdes ude af HSG og ryddes op i en separat opfølgning?

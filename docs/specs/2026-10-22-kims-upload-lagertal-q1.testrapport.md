# Testrapport: Kims uploadfil – "Lagerantal i alt" skal være ét tal (Q1) og strammere lagerceller (modul `supplier_upload` 1.1.2)

- **Spec:** `docs/specs/2026-10-22-kims-upload-lagertal-q1.md` (acceptkriterie 60–74). Forgængere: `2026-10-19-kims-upload-lagerfejl.md` og `2026-10-20-kims-upload-review-rettelser.md`.
- **Omnummereret efter testen:** Tillæg 2026-10-20 fik kriterie 60–62 efter sin retest. Derfor er kriterierne i denne spec og testenes `t_case`-navne flyttet 3 op: 60–74 hedder nu 63–77, og 71u hedder 74u. Rapporten nedenfor bruger de oprindelige numre (60 → 63 … 74 → 77). Testindholdet er uændret.
- **Branch:** `feature/kims-upload-lagertal-q1` (testet på commit `d298d44`; ændringerne er `e1cc91f..d298d44` oven på grundlaget `df886b4`).
- **Testet:** 22-10-2026, af uafhængig tester. Udviklerens egne scripts og bemærkninger er ikke brugt som bevis; alt nedenfor er kørt af testeren.
- **Brugerens beslutninger** (5 cifre, ingen nyt flag, `53 stk.`/`-`/`?` er ugyldige) er lagt til grund. De er ikke genåbnet.

## Konklusion

**KLAR** – med to forbehold: trinnene, der kræver MySQL, er ikke kørt, og kriterie 73 er ikke kørt på den rigtige fil (se "Hvad er IKKE kørt"). Kør `bash tests/run.sh` med `HSG_TEST_DB_*` sat, før der merges.

- **Ingen fejl i produktionskoden fundet.** Alle kriterier, der kan prøves uden database, er bestået (60–70, 71 via substitut, 74). Kriterie 72 og 73 er kun delvist afprøvet.
- Bagudkompatibiliteten er målt tre uafhængige steder og giver **0 afvigelser** for rene tal: 3.049 celler (2.525 rene tal), 9.744 syntetiske varer og 10.410 varer fra tre rigtige Kim-filer.
- De nye tests **fejler på den gamle kerne** (`df886b4`): case 60–69 er røde. Case 70 er grøn på begge, fordi den kun vogter, at `merge_source` og `parse_decimal` er uændrede.
- Udviklerens tre bemærkninger er efterprøvet og er i orden (se "Udviklerens bemærkninger"). Bemærkning 1 afslører en fejl i **spec'ens testdata** (ikke i koden).
- Mutationstest: 30 bevidste fejl i kernen. De nye tests fanger **27**. De 3 overlevende (M14, M25, M30) er lukket med nye tests, jeg har lagt i `tests/supplier_import_test.php` (case QA1–QA7 og 71u). Alle 30 fanges nu.

## Miljø og metode

- PHP 8.5.8 (CLI) med den forberedte `php.ini` (har `zip`; ingen `mbstring`, ingen `pdo_mysql`). **Ingen MySQL.** PHP 8.1 (produktionens minimum) er ikke prøvet; koden bruger ingen nyere konstruktioner end 8.0.
- Testkommando: `PHPRC=<scratchpad>/phpini bash tests/run.sh`.
- Egne scripts ligger i scratchpad under `qa/` (ikke i repoet). Kernen fra `df886b4` (`qa/old`) og fra `HEAD` (`qa/new`) er hentet med `git archive` og kørt i **hver sin PHP-proces**, fordi funktionsnavnene er ens.
- **Siden uden database:** den rigtige `supplier_upload.php` er kørt i en stub (`qa/web`, `qa/web_old`) med en falsk `auth.php` og en falsk PDO, der svarer på sidens egne SQL-sætninger og logger alle skrivende sætninger. Stubben dækker hele sessionen upload → remap → visning → anvend (inkl. opret nyt) og sidens egen HTML og escaping. Den dækker **ikke** login, rettigheder, CSRF og SQL mod en rigtig database.
- Der er ikke ændret noget i repoet ud over denne rapport og `tests/supplier_import_test.php`. `config.php`, `config.local.php`, `.env`, `uploads/` og `storage/` er ikke rørt. Kims filer i `Downloads` er kun læst.

### Samlede kørsler

| Kørsel | Resultat |
|---|---|
| `bash tests/run.sh` på `d298d44` (udviklerens tests) | trin 1 (php -l alle filer), 2 (release-negativtest) og 3 (enhedstest: **371 tjek bestået, 0 fejlede**) grønne. Trin 4 (installation, røgtest, `supplier_import_db_test.php`, `supplier_upload_flow_test.php`) **sprunget over** (`HSG_TEST_DB_*` ikke sat). |
| `bash tests/run.sh` efter mine tillæg | samme trin grønne; enhedstest: **465 tjek bestået, 0 fejlede** (94 nye). Slutlinje: ALLE TESTS BESTÅET. |
| De gamle tests (testfilen fra `df886b4`, case 1–59) mod **ny** og **gammel** kerne | **248 tjek bestået, 0 fejlede** i begge. `git diff df886b4..HEAD -- tests/supplier_import_test.php` viser 0 fjernede linjer: case 1–59 er urørte. |
| Udviklerens nye testfil mod kernen fra `df886b4` | **282 bestået, 47 fejlede** (case 60: 1, 61: 1, 62: 4, 63: 2, 64: 3, 65: 7, 66: 6, 67: 10, 68: 8, 69: 5; case 70 består). |
| Min udvidede testfil mod kernen fra `df886b4` | **300 bestået, 74 fejlede** (de ovenstående + 71u: 4, QA1: 1, QA2: 1, QA3: 12, QA4: 5, QA5: 2, QA6: 1, QA7: 1). |
| Orakel-fuzz af `hsg_supplier_stock_cell` | 300.042 celler, **0 afvigelser** (se "Grænsetilfælde"). |
| Mutationstest | 30 mutanter: 27 fanget af udviklerens tests, 3 overlevede; **30 af 30** fanget efter mine tillæg. |

## Acceptkriterier

| Nr. | Kriterie (kort) | Resultat | Bevis |
|---|---|---|---|
| 60 | `hsg_supplier_stock_cell`: 5 tomme, 17 tal, 15 ugyldige, ingen advarsel | BESTÅET | Case 60 grøn; rød på `df886b4` (`Call to undefined function hsg_supplier_stock_cell()`). Listerne i testen er identiske med spec'ens. Uafhængigt orakel (skrevet af testeren ud fra spec 3.1/3.4, kodepunkt for kodepunkt uden kodens regex): 300.042 celler (150.000 tilfældige tegnblandinger, 150.000 tal-lignende, 42 faste), fordeling 3.961 tomme / 161.239 tal / 134.842 ugyldige, **0 afvigelser** i `state`, `value` og `text`. |
| 61 | `hsg_supplier_short_text`: 40/41 tegn, `æøå`, gyldig UTF-8 | BESTÅET | Case 61 grøn; rød på `df886b4`. Orakel-fuzz dækker tekster på 39, 40, 41 og 42 tegn af `x`, `ø` og `æøå`. |
| 62 | Lokationsceller: hårde mellemrum er tomme, 5 cifre, én afrunding | BESTÅET | Case 62 grøn; rød på `df886b4` (4 tjek, plus PHP-advarsel `The float 9.223372036854776E+18 is not representable as an int` i den gamle kerne ved `9223372036854775808`). Nye kerne: ingen advarsel. |
| 63 | Lang ugyldig tekst i lokationscelle afkortes | BESTÅET | Case 63 grøn; rød på `df886b4` (2 tjek). Bemærkningen er ordret `Bemærk: Ugyldig værdi „øøøø…(40)…“ for Christiansfeld – lokationen ændres ikke.` |
| 64 | Tilstand L: `source_fields` med ugyldig total | BESTÅET | Case 64 grøn; rød på `df886b4` (3 tjek). `Q=0.4999` giver `stock_file_total=0` (gammel: 1). |
| 65 | Tilstand L: `3 + 2` giver ingen falsk afvigelse | BESTÅET (med rettet testdata) | Case 65 grøn; rød på `df886b4` (7 tjek: kontroltal 32, `stock_mismatch`, ikke forvalgt). Udviklerens ændring af testdata er berettiget, se bemærkning 1. |
| 66 | Tilstand T: `source_fields`, med/uden fallback, NBSP, `0.4999` | BESTÅET | Case 66 grøn; rød på `df886b4` (6 tjek). Uden fallback-id er der ingen `stock_quantity`. Test 12 (`Q=53`) er uændret grøn. |
| 67 | Tilstand T: `3 + 2` skrives ikke som 32 | BESTÅET | Case 67 grøn; rød på `df886b4` (10 tjek: Christiansfeld 41 → 32). Uden match: `total_stock=0`, `is_create_new` falsk, ikke forvalgt. |
| 68 | Fletning i tilstand L: én ugyldig total gør gruppens total ugyldig | BESTÅET | Case 68 grøn; rød på `df886b4` (8 tjek: total 34 og 5 som delsum). |
| 69 | Fletning i tilstand T: ingen delsum | BESTÅET | Case 69 grøn; rød på `df886b4` (5 tjek). `Q=5` og `Q=2` giver stadig 7 som før. |
| 70 | `merge_source` og `parse_decimal` uændrede | BESTÅET | Case 70 grøn. Den er også grøn på `df886b4`, som den skal være (kriteriet vogter, at intet er ændret). `git diff` viser kun en ændret kommentar ved `hsg_supplier_merge_source`. |
| 71 | "Fil:"-linjen med ugyldig total | BESTÅET via substitut – **flowtesten IKKE KØRT** | Flowtest-case 71 kræver MySQL og er kun gennemlæst (hjælperne `Http::upload/post/follow`, `token_of`, `preview_json`, `item_index`, `page_text` findes; variablerne i `use (…)` er defineret; `php -l` er grøn). Substitutter: (1) `supplier_upload_file_line` kørt direkte (12 tjek, plus tilføjet enhedstest 71u i `tests/`). (2) Den rigtige side kørt i stub: se afsnittet "Kriterie 71 og siden". |
| 72 | `bash tests/run.sh` grøn; kriterie 1–59 uændret grønne | DELVIST – **trin 4 IKKE KØRT** | Trin 1–3 grønne (465 tjek). Kriterie 1–59: 248 tjek bestået på både gammel og ny kerne. `tests/supplier_import_db_test.php` er ikke ændret; `tests/supplier_upload_flow_test.php` har kun fået en kommentarlinje og case 71 (ingen eksisterende linjer er ændret ud over kommentaren). Cellerne i DB- og flowtesten er kun små hele tal (0–53), så de rammes ikke af de strammere regler. |
| 73 | Før/efter på Kims rigtige fil `lager 19-10-2026.xlsx` | **IKKE KØRT på den fil** (den findes ikke på maskinen) – substitut BESTÅET | Substitut: tre ældre rigtige Kim-filer, se afsnittet "Kriterie 73". 10.410 varer, 0 afvigelser. |
| 74 | Modulversion 1.1.2 og dokumentation | BESTÅET | `modules/supplier_upload/module.php`: `'version'=>'1.1.2'`, intet `migrations.php` (som ved 1.1.1). `docs/SUPPLIER-UPLOAD.md` har alle punkter fra spec'ens afsnit 6: "Hvad er et tal i en lagercelle?", konsekvensen i tilstand L og T, 5 cifre, kun hårde mellemrum = tom, én afrunding (`0.4999` → 0), 40 tegn + "…", og de nye tabelrækker. Alle tabelrækkerne er kørt (se oraklet og "Grænsetilfælde"). Ingen andre steder har den gamle versions `1.1.1`. |

## Kriterie 71 og siden (stub)

Den rigtige `supplier_upload.php` er kørt i stub med et CSV-preview (8 rækker). Rækkerne har bl.a. total `3 + 2`, `<script>alert(1)</script> ' " & æøå`, en tekst på 50 tegn med `ÆØÅ æøå`, `100000`, EAN `5010494560018` og `?`. Fire HSG-produkter findes i den falske database (41/2/12).

| Tilstand | Resultat på **ny** kode |
|---|---|
| L (R/S/T + Q) | `Fil: Christiansfeld 41 · Michael 0 (tom) · Gert 12 · i alt „3 + 2“ (ugyldig)` og `Bemærk: Ugyldig værdi „3 + 2“ for lagerantal i alt – bruges ikke som kontroltal.` Varen har `flags=[]`, `selected=true`, intet `stock_file_total` i `source`. Gyldig total `53` giver stadig `… i alt 53`. 6 amber-bemærkninger. |
| T (kun Q) | `Fil: lagerantal i alt „3 + 2“ (ugyldig)` og `Bemærk: Ugyldig værdi „3 + 2“ for lagerantal i alt – lageret ændres ikke.` Rækken er ikke forvalgt, `changes=[]`. Gyldig total `53` giver `Fil: lagerantal i alt 53 → skrives på Christiansfeld`. |
| Escaping | `<script>` står som `&lt;script&gt;` i HTML (både bemærkning og "Fil:"-linje). Teksten på 50 tegn vises som `ÆØÅ æøå 0123456789 0123456789 0123456789…` (40 tegn + "…"). |
| Anvend (L, forvalgte rækker) | Kun `INSERT INTO lager_stock` for lokationstal (fx produkt 11: Michael 2 → 0). Ingen SQL eller audit-post indeholder `total_invalid`, `3 + 2`, `100000`, `<script>` eller EAN'en. |
| Anvend (T, forvalgt række + manuelt valgte rækker) | Kun den gyldige total (53 på produkt 12) skrives. Når admin selv vælger rækkerne med ugyldig total (match og "opret nyt"), oprettes produkterne **uden lager**, og intet lager ændres på det matchede produkt. Ingen lækage i SQL eller audit. |

**Samme session på `df886b4` (kontrol):** tilstand T skrev `32`, `1`, `0`, `100000` og EAN'en `5010494560018` som lager på hhv. de matchede og de nye produkter. I tilstand L fik `3 + 2` en falsk `stock_mismatch` og var ikke forvalgt. Overskriften viste "Lager i filen: 5010494660104 stk. i alt". Det er præcis P1/P2 i spec'en, og den er nu rettet.

## Kriterie 73: før/efter på Kim-filer (substitut)

Filen `lager 19-10-2026.xlsx` findes ikke på maskinen. Brugt i stedet (kun læst): `lager 18-10-2026.xlsx`, `Kopi af lager 18-10-2026 (003).xlsx` og `lager liste d. 06-09-2026.xlsx`.

- Metode: filen læses med `hsg_supplier_read_xlsx`; bedste ark og overskriftsrække findes som i `hsg_supplier_prepare_preview`; `hsg_supplier_suggest_columns` giver kolonnevalget; Kims lokationer (Christiansfeld, hos Michael, hos Gert) sættes til `stock_loc_1/2/3`. `hsg_supplier_merge_raw_items` køres i gammel og ny kerne (hver sin proces) i **12 kørsler pr. fil**: tilstand L, L uden total, T × uden og med 166–167 syntetiske HSG-produkter bygget af filens rækker × reservationsvalg `add`/`none`.
- **Kolonnevalget er identisk** i gammel og ny kerne i alle tre filer.
- Celletyper i kolonnerne Q/R/S/T: 0 ugyldige og 0 decimaltal i alle tre filer (fx 755 tal og 753 tomme i den første).
- Resultat: **10.410 varer sammenlignet, 0 afvigende** (varer, match, ændringer, noter, flag, forvalg, `stock_file`). Eneste strukturelle forskel er den nye nøgle `stock_file.total_invalid: null`, som er fjernet før sammenligningen. Ingen PHP-advarsler i nogen af kørslerne.

## Udviklerens bemærkninger

| # | Bemærkning | Vurdering |
|---|---|---|
| 1 | Testdata i kriterie 65 er ændret (produkt og filrække får destilleri `Testdestilleri`). | **I orden – men spec'en har en fejl i sit eksempel.** Spec'ens ordrette data (uden destilleri) er kørt: `changes` bliver `{"distillery":{"old":"","new":"Kontrol Whisky"},"stock_loc_2":{…}}`, fordi navneparseren udleder destilleriet "Kontrol Whisky" af navnet. Kriteriets krav "`changes` er præcis `stock_loc_2`" kan altså ikke opfyldes med spec'ens data, uanset koden. Udviklerens ændring bevarer hensigten (ingen lagerændring ud over Michael, ingen falsk afvigelse). Kriterie 65 i spec'en bør rettes tilsvarende. Kriterie 67 bruger allerede `dist` og er ikke berørt. |
| 2 | Ugyldig tekst trimmes også for hårde mellemrum i bemærkningen (`?` + NBSP vises som `„?“`). | **I orden og i overensstemmelse med spec 3.1 trin 5** (`text = short_text($t)`, hvor `$t` er den trimmede tekst). Før blev NBSP vist som en usynlig del af teksten. Dækket af ny test QA1 (`"?\u{00A0}"` → `'?'`). |
| 3 | Ugyldig UTF-8 stopper stadig gemningen af previewet. | **Bekræftet, uændret og ikke en regression.** Upload af en CSV med Windows-1252-byte (`\xE6`) i "Lagerantal i alt" og i et produktnavn giver **samme** fejl `Malformed UTF-8 characters, possibly incorrectly encoded` på `df886b4` og `HEAD` (stub). Årsagen er `raw_rows` i previewet, ikke de nye felter. Uden for scope iflg. spec 8. Meldingen er på engelsk og forklarer ikke brugeren, hvad der er galt (eksisterende forhold). |

## Grænsetilfælde

Alle er kørt mod den nye kerne. Hvor intet andet er nævnt, er resultatet som spec'en.

### Lagercellen (`qa/fuzz_cell.php`, `qa/dump_cells.php`)

| Input | Resultat |
|---|---|
| `99999` / `100000` | tal 99999 / ugyldig |
| `-99999` / `-100000` | tal 0 / ugyldig |
| `00041` / `000041` | tal 41 / ugyldig (6 cifre) |
| `-0` | tal 0 |
| `1e3`, `1E3`, `+5`, `.5`, `41.`, `1,2,3` | ugyldig |
| `−2` (Unicode-minus), `٣`, `４１`, `²` | ugyldig |
| NBSP, U+202F, U+2003, tabulator i begge ender, også blandet | trimmes, tal eller tom |
| `5` + tabulator + NBSP + `2` | ugyldig (mellemrum midt i) |
| `?` + NBSP | ugyldig, tekst `?` |
| 40 tegn `æøå…` / 41 tegn | uændret / 40 tegn + `…`; resultatet er gyldig UTF-8 |
| ugyldig UTF-8 (`\xff`, `5\xff`, `41\x80`, `\xc3`) | ugyldig, aldrig tom; tekst = `trim(rå)`, ikke afkortet; ingen advarsel |
| `0.4999`, `2.4999`, `7.4951` | 0, 2, 7 (én afrunding) |
| `99999.5` | 100000 (se bemærkning 4) |
| 1.000.000 tegn (tegn, cifre, mellemrum, NBSP), `12345.` + 1.000.000 decimaler | 0,7–15 ms, ingen `preg_last_error` |

### Fletning, tilstand T uden fallback-id og reservationer (`qa/edge_merge.php`: 162 tjek, 0 fejl)

- **Begge retninger:** primær ugyldig + ekstra gyldig og omvendt, i tilstand L, T (fallback 1) og T uden fallback-id. Resultat: ingen lagernøgler i `source`, `total_stock=0`, `stock_file.total=null`, ingen `stock_mismatch`; lokationerne beholdes i tilstand L.
- **To ugyldige:** den primære rækkes tekst bevares. **Tom + ugyldig:** ugyldig. **Tom + gyldig:** som før. **Tre rækker** (gyldig, ugyldig, gyldig): gruppens total er ugyldig.
- **Trin 2** (to enheder `C-1`/`C-2` matcher samme HSG-produkt): samme resultat, ingen `stock_loc_*` i `changes`.
- **Tilstand T uden fallback-id** (`stock_quantity`-vejen): gyldig total giver stadig `source.stock_quantity` og `changes.stock_quantity`. Ugyldig total giver ingen `stock_quantity`, heller ikke ved fletning.
- **Reservationer:** i tilstand T med valget `none` og ugyldig total vises hverken "skrives på" eller reservationsadvarsel. I tilstand L med `none` og ugyldig total vises reservationsadvarslen for lokationerne stadig, fordi lokationerne skrives (se bemærkning 2). Med `add` er `stock_loc_1` = 2 + 4 reserveret = 6.
- **Specialtilfælde:** ugyldig lokationscelle + ugyldig total i samme række giver begge bemærkninger. Rækker uden navn og varenummer springes over som før. Ingen lagerkolonner: tilstand `none`, ingen bemærkning. `3\n2` og `3<tab>2` er ugyldige (ikke 32).

### Den interne nøgle `stock_file_total_invalid`

- `source` og `changes` indeholder aldrig nøglen, i nogen kombination af tilstand L/T/T uden fallback, med og uden produkt, begge fletteretninger (matrix i test QA6).
- `hsg_supplier_changes_for_product` ignorerer nøglen, selv hvis den lå i `source`.
- `hsg_supplier_apply_product` og `hsg_supplier_create_product` nævner ikke nøglen i kildeteksten (QA6) og læser kun kendte felter. Bevist i praksis i stubben: ingen SQL-parameter og ingen audit-post indeholder nøglen eller den ugyldige tekst (se "Kriterie 71 og siden").
- Apply genberegner ikke fra filen; den bruger `item['source']` fra det gemte preview. Genberegning sker kun ved `remap`, `reguess` og `reservation_mode` (via `hsg_supplier_recalculate_preview` → `hsg_supplier_merge_raw_items`), og det er kørt i stubben.

### Bagudkompatibilitet (rene tal)

- **Celler (`qa/dump_cells.php`):** 3.049 celler, gammel mod ny kerne, via `location_cells` og `source_fields` i tilstand L, T og T uden fallback. De 2.525 rene tal (højst 5 cifre, 0–2 decimaler, evt. NBSP/mellemrum) giver **identisk** resultat. De øvrige afvigelser ligger alle i de klasser, spec'en nævner: brøkdel i [0,495; 0,5) (dobbelt afrunding), mere end 5 cifre, tekst/enheder, præcis tre decimaler og komma/punktum med mindst 4 decimaler (gammel `parse_decimal` læste fx `11771,2691377` som tusindtalsseparator og gav 117.712.691.377).
- **Varer (`qa/fuzz_merge.php`):** 400 tilfældige rækker (varenumre med bogstav, flettede varianter, gyldige tal, decimaler og tomme celler) × 32 kørsler (4 kolonnevalg × med/uden produkter × `add`/`none` × med/uden fallback-id) = **9.744 varer, 0 afvigende**. En første kørsel, der også indeholdt celler med kun NBSP, gav 560 afvigende; alle var P3 (NBSP-celle er nu tom, ikke ugyldig).
- **Rigtige filer:** se kriterie 73.

## Bemærkninger (blokerer ikke)

1. **Spec'ens kriterie 65 har forkerte testdata** (se udviklerens bemærkning 1). Koden er rigtig; spec'en bør rettes.
2. **Spec 3.6, sidste afsnit er upræcist.** Der står, at advarslen om reservationer ved `none` ikke vises, når totalen er ugyldig. Det gælder i tilstand T (intet skrives). I tilstand L skrives lokationerne som normalt, og advarslen om reserverede flasker pr. lokation vises stadig. Det er det rigtige, men sætningen bør præciseres.
3. **Afrunding og PHP-version.** `round()` i PHP 8.4 og nyere har fået fjernet "pre-rounding". Tal inden for ca. 1e-15 af et x,5 kan derfor runde forskelligt i PHP 8.1 (produktionens minimum) og 8.5 (testet). Ingen eksempel i spec'en rammes (`0.4999`, `2.4999` og `40.999999999999993` giver samme resultat), og Kims filer har ingen decimaltal. **Ikke afprøvet på PHP 8.1.**
4. **`99999.5` giver 100000.** Loftet gælder cifrene før decimaltegnet, ikke værdien efter afrunding. Harmløst (langt under INT-grænsen), men "op til 99.999" i docs er strengt taget før afrunding.
5. **Formelstøj i en talcelle** (fx `1.7E-15`, som Excel kan give for et "nul") er nu ugyldig i "Lagerantal i alt" (var før ignoreret uden bemærkning). Det er et bevidst valg i spec 8. Kims tre filer har ingen sådanne celler.
6. **Opsummeringen "Lager i filen"** tæller kun gyldige totaler, og badget "Afviger" kan derfor vises i tilstand L, selvom årsagen er en ugyldig total (spec 8). Set i stubben: "Lager i filen: 53 stk. i alt · Sum af lokationskolonner: 154 stk. Afviger – se bemærkningerne på de enkelte varer".
7. **Docs:** overskriften "En ugyldig celle ændrer aldrig lageret" er lidt bred, fordi lokationerne i tilstand L stadig skrives, når kun totalen er ugyldig. Selve punkterne under overskriften er korrekte.
8. **Miljø, ikke kode:** `tests/supplier_import_test.php` case 58 (`tempnam`) fejler, hvis testen køres med arbejdsmappen inde i Windows' temp-mappe (PHP 8.5-notice "file created in the system's temporary directory"). Fra repoet består den. Fejlen er uafhængig af ændringen.
9. **Release-negativtest B og C** kan som ved forrige test ikke bevises uden `pdo_mysql`. Ændringen berører ikke release-værktøjet.

## Testhuller og tilføjede tests

**30 mutanter** er lagt ind i en kopi af kernen (`qa/mutate.php`), og enhedstesten er kørt mod hver: loft 6 og 4 cifre, tre-cifre-reglen fjernet (hele og kun for punktum), tomhed før NBSP-trim, negative ikke 0, dobbelt afrunding, `floor`, afkortning 39/41/bytes/ikke afkortet, `total_invalid` ikke sat/ikke i `stock_file`/ikke fjernet fra `source`, `stock_file_total`/`stock_quantity`/`stock_loc_*` ikke fjernet i tilstand T, `stock_loc_*` fjernet også i L, bemærkningstekster ændret, T-bemærkning kun med produkt, ugyldig total som 0, `merge_source` summerer nøglen, ugyldig UTF-8 som tom, rå tekst i lokationscelle, foranstillede nuller, `\d` med `/u`.

- **27 blev fanget** af udviklerens tests.
- **3 overlevede**, og er nu lukket:
  - **M14** (`stock_quantity` ikke fjernet ved ugyldig total): ingen test kørte tilstand T uden fallback-id med fletning. → QA3.
  - **M25** (ugyldig UTF-8 bliver "tom"): spec 3.1 trin 1 har ingen test. → QA1.
  - **M30** (`\d` med `/u` i stedet for `[0-9]`): arabisk-indiske og fuldbredde-cifre blev accepteret og læst som 0. → QA1.

**Tilføjet i `tests/supplier_import_test.php`** (94 nye tjek, alle grønne på `HEAD`, røde på `df886b4`):

| Case | Hvad |
|---|---|
| QA1 | ikke-ASCII-cifre, ugyldig UTF-8, `00041`, `-99999`, NBSP+tabulator, `?`+NBSP, 40/41 tegn `æøå` |
| QA2 | rene tal giver samme værdi som den gamle formel `(int)max(0, round(parse_decimal(…)))` (bagudkompatibilitet), også via `source_fields` |
| QA3 | tilstand T uden fallback-id (`stock_quantity`-vejen), også ved fletning begge veje |
| QA4 | fletning i tilstand T: primær ugyldig/ekstra gyldig, tom + ugyldig, tre rækker |
| QA5 | fletning trin 2 (to enheder matcher samme HSG-produkt) |
| QA6 | `stock_file_total_invalid` når aldrig `source`/`changes`; apply og create nævner den ikke |
| QA7 | tilstand T med `none` og ugyldig total: ingen reservationsadvarsel og ingen "skrives på" |
| 71u | "Fil:"-linjen (kriterie 71) som enhedstest uden database: funktionen hentes ud af `supplier_upload.php` og køres (tilstand L, T, ældre preview uden nøglen, total 0, fra celle til linje) |

## Hvad er IKKE kørt

| Hvad | Hvorfor | Konsekvens |
|---|---|---|
| `tests/run.sh` trin 4: installation, røgtest af alle sider, 419 uden CSRF, 401 uden login for `reservation_mode`/`reguess` | ingen MySQL | Sidens guards er ikke prøvet over HTTP. `git diff df886b4..HEAD -- supplier_upload.php` viser kun 5 ændrede linjer (4 tilføjelser, 1 sletning), alle i `supplier_upload_file_line`: en ændret kommentar og tre nye linjer. `require_capability('imports.manage')` (linje 2) og al CSRF-/POST-logik er urørt. |
| `tests/supplier_import_db_test.php` | ingen MySQL | Ikke kørt. Filen er ikke ændret, og dens celler er små hele tal. |
| `tests/supplier_upload_flow_test.php` (inkl. nyt case 71 mod rigtig server) | ingen MySQL | Erstattet af stub-kørsel og enhedstest 71u. Case 71 er kun læst igennem (ikke kørt). |
| Kriterie 73 på `lager 19-10-2026.xlsx` | filen findes ikke på maskinen | Substitut: tre ældre rigtige Kim-filer, 0 afvigelser. Filens rigtige indhold i kolonne Q–T er ikke set. |
| Adgang uden login og som direkte linkbruger | ingen MySQL | Ikke prøvet. Koden er uændret. |
| Mobilbredde | ingen layoutændring | Ingen HTML-, CSS- eller klasseændring; kun tekst i eksisterende `<small>`-elementer på en adminside, og lange ugyldige tekster er nu afkortet til 40 tegn. Ikke målt i en browser. |
| PHP 8.1 | kun 8.5.8 installeret | Ikke prøvet (se bemærkning 3). |
| Test B og C i release-negativtesten | `pdo_mysql` mangler | Se bemærkning 9. |

## Før release

- Kør `bash tests/run.sh` med MySQL (`HSG_TEST_DB_*`), og få trin 4 grønt, især `supplier_upload_flow_test.php` case 71 og `supplier_import_db_test.php`.
- Kør previewet på Kims rigtige fil `lager 19-10-2026.xlsx` på både `feature/kims-upload-review-rettelser` og denne branch, og skriv eventuelle forskelle i PR'en (kriterie 73). Forventning ud fra de tre ældre filer: ingen forskelle.
- Ret kriterie 65 i spec'en (testdata) og præciser spec 3.6 sidste afsnit (bemærkning 1 og 2).
- Opdater `app_version.php`, README og `hsg-package.json` via `php tools/build-release.php <version>` ved release (ikke gjort på branchen, som spec'ens afsnit 4 beskriver). `tools/build-release.php` udelader `tests`, `docs/specs` og `.claude`, så ingen test- eller dev-filer kommer med i ZIP'en.

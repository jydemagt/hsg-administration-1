# Testrapport: batch-filterets byte-offset i fadnummer-udledningen (ingen modulversionsændring)

- **Spec:** `docs/specs/2026-10-22-fadnummer-batch-byteoffset.md` (acceptkriterie 101-110). Brugerens svar: Q-B1 = nej.
- **Branch:** `feature/fadnummer-batch-byteoffset`, testet på HEAD `e1b8c32` (udviklerens commits `1a02b9a`, `a069dfb`, `e1b8c32` oven på spec-committen `e32fe4a`). Basen er `origin/feature/kims-upload-fadnummer-ciffer` = PR #177, `e066862`.
- **Testet:** 22-10-2026, af uafhængig tester. Udviklerens egne tal er ikke brugt som bevis. Alt nedenfor er kørt af testeren, og tabel T er læst direkte ud af spec'en (markdown-tabellen) og ikke skrevet af i hånden.
- **Miljø:** PHP 8.5.8 (CLI). Med mbstring: `PHPRC` peger på scratchpad-`php.ini` (mbstring, zip, pdo_mysql, gd m.m.). Uden mbstring: `php` uden PHPRC, og til databasetesten en ini uden mbstring, men med pdo_mysql. MariaDB på 127.0.0.1:3327. Hele pakken er kørt mod `hsg_test`. Mutanter og HTTP-test er kørt mod en klon, `hsg_test_qb`, som er droppet igen. `hsg_test` er ikke ændret (ingen `TSTCASK-`-produkter tilbage, én audit-post fra installationen).

## Samlet konklusion: KLAR

Ingen fejl fundet. Alle kriterier 101-109 er bestået. Kriterie 110 er delvist kørt, fordi `lager 19-10-2026.xlsx` ikke findes på maskinen. Samme sammenligning på de to 18-10-filer, der findes, giver **ingen forskelle** (se kriterie 110).

- `bash tests/run.sh` er grøn i alle 4 trin: **275 / 143 / 56 / 123 / 66** tjek. Tallene er identiske med udviklerens.
- Tabel T (12 rækker) er kørt med og uden mbstring og er som i spec'en i begge tilfælde.
- `hsg_cask_number_candidates` er **identisk med basens funktion på 288.152 tekster**, både med og uden mbstring (0 afvigelser).
- `hsg_extract_cask_number` giver **bit-for-bit samme resultat med og uden mbstring** på samme korpus (md5 af alle resultater er ens), er lig en uafhængig reference-implementation på alle tekster, og er lig basens funktion på alle tekster uden multibyte-tegn før `#` (48.005 tekster, 0 afvigelser).
- 20 egne mutanter: de to fra kriterie 107 og `substr($before,-18)`-mutanten er alle opdaget. 15 af 20 opdages af testpakken. De 5 overlevende er testhuller uden produktionsfejl (se "Mutanter" og "Bemærkninger").
- mbstring-vagten virker: uden PHPRC kommer **præcis én FEJL** med beskeden fra D5, og exit-koden er 1.

## Testpakken, `bash tests/run.sh`

Kørt via `run-all.sh`: frisk database `hsg_test`, derefter hele `tests/run.sh` med PHPRC. Exit 0, "ALLE TESTS BESTÅET".

| Trin | Resultat | Tjek | Baseline (spec-commit) | Udvikleren |
|---|---|---|---|---|
| 1 PHP-syntaks | OK | - | - | - |
| 2 Release-negativtest | OK (A-G) | - | - | - |
| 3 Enhedstest, Kims uploadfil | OK | **275** | 275 | 275 |
| 3 Enhedstest, fadnummer-udledning | OK | **143** | 99 | 143 |
| 4 Installation og røgtest | OK: installation, admin-login, alle 19 sider (inkl. `products.php?status=all&filter=cask_no_digit`) HTTP 200, uden login 401, POST uden CSRF 419, `reservation_mode` og `reguess` 419 og 401 | - | - | - |
| 4 Databasetest, Kims uploadfil | OK | **56** | 56 | 56 |
| 4 Databasetest, fadnummer-oprydning | OK | **123** | 113 | 123 |
| 4 Flowtest, Kims uploadfil | OK | **66** | 66 | 66 |

Fadnummer-udledning er gået fra 99 til 143 (+44, kriterie 101-106). Oprydningen er gået fra 113 til 123 (+10, produkt J og K).

**Uden PHPRC** (ingen mbstring):

| Kommando | Resultat |
|---|---|
| `php tests/product_enrichment_test.php` | 131 bestået, **1 fejlede**, exit **1**. Den ene FEJL står under kriterie 102 og har præcis teksten fra D5: "mbstring er ikke indlæst: den gamle udledning med æ, ø, å og – (kriterie 102) kan kun testes med mbstring. Kør med en php.ini, der indlæser mbstring (PHPRC)." Kriterie 101 (alle 12 rækker) er grøn uden mbstring. |
| `php tests/cask_number_cleanup_db_test.php` (ini uden mbstring, men med pdo_mysql) | 113 bestået, **1 fejlede**, exit **1**. FEJL: "[107 J og K] mbstring er ikke indlæst: oprydningen med æ, ø, å og – før # (kriterie 107) kan kun testes med mbstring, så J og K er ikke indsat." Efter kørslen: 0 `TSTCASK-`-produkter, én audit-post. Resten kører som før (113 = baseline). |
| `bash tests/run.sh` uden PHPRC og uden `HSG_TEST_DB_NAME` | Trin 3 viser den ene FEJL, `[FEJL] Enhedstest: fadnummer-udledning fejlede`, "TESTS FEJLEDE", exit 1. (Trin 2 fejler her af en anden grund: `ZipArchive` findes ikke uden php.ini. Det er miljøet og ikke branchen.) |

## Acceptkriterier

| Nr. | Kriterie (kort) | Resultat | Bevis |
|---|---|---|---|
| 101 | `hsg_extract_cask_number` for tabel T, med og uden mbstring | BESTÅET | Eget script `tableT.php` læser tabellen ud af spec'en (12 rækker a-l) og kalder funktionen. Med og uden mbstring giver alle 12 rækker kolonnen "Efter" (a-g, j og k = `null`; h og i = `'12'`; l = `'707145'`). Testens egen case 101 er grøn i begge tilfælde. Se "Tabel T". |
| 102 | `hsg_cask_number_candidates` = kolonnen "Gammel" med mbstring; én FEJL uden; `mbstring` i `extensions` | BESTÅET | Med mbstring er alle 12 rækker som i kolonnen "Gammel" (fx a `['12']`, j `['STRENGTH','12']`, h `['XO']`). Uden mbstring: præcis én FEJL med D5-teksten, tjekket springes over, resten kører, exit 1 (se ovenfor). `.github/workflows/tests.yml:27`: `extensions: pdo, pdo_mysql, zip, json, gd, curl, simplexml, mbstring`. Mutant B19 (mbstring fjernet fra yml) er opdaget. |
| 103 | ASCII-tekster og startlager uændrede | BESTÅET | Tabellen i kriterie 78 (20 rækker a-t, læst fra Q2-spec'en): ny `extract` = kolonnen "Efter" og `candidates[0] ?? null` = kolonnen "I dag", med og uden mbstring. Tabellen i 79 (`m` = `['FINISH','43']`, `l` = `['STRENGTH','231584']`, `j` og `p` = `[]`) er som før. Alle 362 navne (rå og normaliseret) fra `initial-stock.json`: `extract(N)` = første værdi med ciffer i `candidates(N)`, med og uden mbstring (`crit.php`: 832 tjek med, 807 uden). Testens case 103 er grøn. Case 87 (installationen) er grøn i trin 4. `git diff` på de eksisterende 78-79-tjek i `tests/product_enrichment_test.php` viser kun tilføjelser, ingen ændrede forventninger. |
| 104 | `hsg_product_parse_text` | BESTÅET | `Blåbær Batch #12, 46%`: ingen `cask_number` i `fields` eller `confidence`. `ÆØÅ ÆØÅ ÆØÅ #12 Batch #34`: `'12'`. Teaninich-teksten: `'707145'`. Kørt i eget script (med og uden mbstring) og i testens case 104. |
| 105 | `hsg_supplier_source_fields` | BESTÅET | `Glen Test – Batch #12` (U+2013): ingen `cask_number` (basen gav `'12'`, se kriterie 110 og tabel T række c). `Blåbær Batch #12`: ingen. `Glen Test – #100`: `'100'`. Kørt i eget script og i testens case 105. |
| 106 | Kode og signaturer | BESTÅET | (1) `ReflectionFunction`: `candidates` har præcis én parameter `string $text` og returtype `array`; `extract` har præcis én `string $text` og `?string`. Mutant B12-B14 (standardværdi på `$legacy`, ekstra parameter, manglende returtype) er opdaget af case 106. (2) `grep -n "mb_\|function_exists" core/product_enrichment.php` giver kun linje 27 og 28, og begge ligger i `if($legacy){`. Den nye gren (`}else{`) har ingen `mb_*` og ingen `function_exists`. (3) `git diff -w -U0 origin/…ciffer...HEAD -- core/product_enrichment.php`: de to legacy-linjer (`$before`, `$tail`) er ikke i diff'en med `-w`, altså flyttet ordret (kun ny indrykning). Hunks ligger på basens linje 12, 17, 28, 38 og 40, alle inden for `:11-44`. `hsg_cask_number_cleanup`, `hsg_product_parse_text`, `hsg_product_text_normalize` og `hsg_cask_number_seed_names` er byte-for-byte identiske med basen (md5 af funktionsteksten). Docblocks svarer til D1. |
| 107 | Oprydningen, produkt J og K (databasetest) | BESTÅET | Case 83/84/107, 85/107 grønne (123 tjek). Kørsel 1: J `NULL`, K `'12'`; `cleared` indeholder A, B, I samt J `{old:STRENGTH,new:null,source:name}` og K `{old:XO,new:'12',source:name}`; `kept` er præcis C, D, E og G. Kørsel 2 og 3: J og K uændrede og ikke i listerne. **Mutanterne fra kriteriet:** B01 (`extract` bruger den gamle udledning) giver J `'12'` og K `NULL` og 7 FEJL; B03 (`candidates` får det rigtige offset) giver K i `kept` (`value: XO`) og 9 FEJL. Begge fejler som krævet, og den samme mutant fejler også i enhedstesten. Uden mbstring: én tydelig FEJL, J og K ikke indsat, resten som før (se ovenfor). |
| 108 | Versioner, migrationer, filliste | BESTÅET | `modules/products/module.php`: `1.5.1`; `modules/supplier_upload/module.php`: `1.1.1`. `modules/products/migrations.php` har kun nøglen `'1.5.1'` (case 88 grøn). Ingen migrationsfil er ændret, og `git diff` indeholder 0 tilføjede `ALTER/CREATE/DROP TABLE`/`ADD COLUMN`-linjer. `git diff origin/…ciffer...HEAD --name-only` giver 7 filer: `.github/workflows/tests.yml`, `README.txt`, `core/product_enrichment.php`, `docs/specs/2026-10-22-fadnummer-batch-byteoffset.md`, `docs/specs/2026-10-22-fadnummer-kraever-ciffer.md`, `tests/cask_number_cleanup_db_test.php`, `tests/product_enrichment_test.php`. Alle står i afsnit 7 (eller er spec'en selv). Filerne under "Ikke ændret" (`core/supplier_import.php`, `modules/products/module.php`, `modules/products/migrations.php`, `products.php`, `migrations.php`, `install.php`, `import.php`, `tests/run.sh`) har 0 linjer i diff'en. |
| 109 | `tests/run.sh` grøn, README-linjen | BESTÅET (lokalt) | `run.sh` med PHPRC er grøn i alle trin (se ovenfor). `README.txt:323` står under "Fadnummer kræver mindst ét ciffer (Produkter 1.5.1)" og er ordret linjen fra spec'ens afsnit 5. **CI er ikke kørt** (GitHub Actions kan ikke køres lokalt); `tests.yml` indeholder nu `mbstring`, og `release.yml` kører hverken enhedstests eller `run.sh`, så den påvirkes ikke. |
| 110 | Manuel test på Kims fil | **DELVIST: 19-10-FILEN FINDES IKKE** | `lager 19-10-2026.xlsx` findes ikke i Downloads, Dokumenter, Documents, Skrivebord/Desktop eller OneDrive - HSG Whisky. Kørt på den ældre `lager 18-10-2026.xlsx` og på `Kopi af lager 18-10-2026 (003).xlsx`. **Forskelle: ingen.** Se afsnittet "Kriterie 110". |

## Tabel T, kørt med og uden mbstring

`tableT.php` (HSG_ROOT = arbejdstræet). "Gammel" er `candidates` på branchen og er identisk med basens funktion i alle rækker. Kolonnen "Før" er basens `extract` med mbstring og stemmer med spec'ens kolonne "Før rettelsen".

| | X | Gammel (med mb) | Gammel (uden mb) | Før (basen, med mb) | Efter, med mb | Efter, uden mb | Spec "Efter" |
|---|---|---|---|---|---|---|---|
| a | `Blåbær Batch #12` | `["12"]` | `[]` | `"12"` | `null` | `null` | `null` |
| b | `Blabaer Batch #12` | `[]` | `[]` | `null` | `null` | `null` | `null` |
| c | `Glen Test – Batch #12` | `["12"]` | `[]` | `"12"` | `null` | `null` | `null` |
| d | `Glen Test - Batch #12` | `[]` | `[]` | `null` | `null` | `null` | `null` |
| e | `Kim’s Batch #12` | `["12"]` | `[]` | `"12"` | `null` | `null` | `null` |
| f | `Ærø Whisky Bottle #25` | `["25"]` | `[]` | `"25"` | `null` | `null` | `null` |
| g | `Ærø Single Malt, flaske #12` | `["12"]` | `[]` | `"12"` | `null` | `null` | `null` |
| h | `ÆØÅ ÆØÅ ÆØÅ #12 Batch #XO` | `["XO"]` | `["12"]` | `null` | `"12"` | `"12"` | `"12"` |
| i | `ÆØÅ ÆØÅ ÆØÅ #12 Batch #34` | `["34"]` | `["12"]` | `"34"` | `"12"` | `"12"` | `"12"` |
| j | `Cask Strength, Blåbær Batch #12` | `["STRENGTH","12"]` | `["STRENGTH"]` | `"12"` | `null` | `null` | `null` |
| k | `Kløver Whisky Batch #12` | `["12"]` | `["12"]` | `"12"` | `null` | `null` | `null` |
| l | `Teaninich, 10 års, #707145, …, 56,8%` | `["707145"]` | `["707145"]` | `"707145"` | `"707145"` | `"707145"` | `"707145"` |

- Kolonnen "Gammel (med mb)" er lig spec'ens kolonne "Gammel" i alle 12 rækker.
- Uden mbstring afhænger den gamle udledning af byte-fallbacken (kolonnen "Gammel (uden mb)"), som D2 beskriver. Række k giver `["12"]` også uden mbstring, som spec'en skriver. Det er netop derfor, at "Gammel" kun testes med mbstring.
- Den nye udledning er ens i de to miljøer. Række k er den vigtige: `substr($before,-18)` skærer ø over og giver `'12'`; den rigtige halbeskæring giver `null`.

## Den gamle udledning = basens kode (differentialtest)

`diff.php`: basens `hsg_cask_number_candidates` er hentet med `git show origin/feature/kims-upload-fadnummer-ciffer:core/product_enrichment.php`, funktionerne er omdøbt til `base_*` og indlæst i samme proces som branchens kode. Kørt to gange: med og uden mbstring.

**Korpus: 288.152 forskellige tekster** (48.005 med kun ASCII før sidste `#`, 240.147 med multibyte-tegn før sidste `#`):

| Kilde | Antal |
|---|---|
| Alle navne i `initial-stock.json`, rå (181 produkter, 175 forskellige navne) | 175 |
| Samme navne normaliseret (`hsg_product_text_normalize`), kun dem der adskiller sig fra det rå navn | 8 |
| Tabel T og tabellen i kriterie 78, rå og normaliseret | 30 |
| Hvert tredje startlagernavn + ni haler (` – Batch #12`, ` - Batch #12`, `, Batch #7`, ` Bottle #25`, ` Flaske #3`, ` Batch #XO`, ` – #100`, `, Kim’s Batch #12`, ` — Bottle #9`) | 549 |
| Hvert tredje startlagernavn + otte præfikser (`Blåbær `, `Ærø `, `ÆØÅ ÆØÅ ÆØÅ `, `Kløver `, `Æ `, `Gløgg – `, `Kim’s `) | 427 |
| Tilfældige tekster (fast seed) af æøåÆØÅéü, –, —, ’, Batch/batch/BATCH/Bottle/Flaske/flaske, `Batch#`, `Batch  #`, tab, NBSP, Cask/Cask No./Fad nr./Barrel/#/Release #, årstal, tal og værdier som `XO`, `R10/123`, `00`, `1a`; 2 % klippet midt i et tegn (ugyldig UTF-8); 1 % med ledende mellemrum og linjeskift | 136.967 |
| Målrettede tekster: 0-25 multibyte-tegn, 0-25 polstringstegn, et batch/bottle/flaske-ord, 0-20 mellemrum (ofte NBSP først), `#`, en værdi. Rammer 18-tegns-vinduets grænse fra begge sider | 149.993 |
| Meget lange tekster (300.000 tegn med Batch #12 til sidst) | 3 |

| Kontrol | Med mbstring | Uden mbstring |
|---|---|---|
| (1) `candidates` (branch) === basens `candidates` | **0 afvigelser** | **0 afvigelser** |
| (2) `extract` (branch) === uafhængig reference (tegn-baseret via `preg_split('//u')`, samme mønstre, rækkefølge, årstal og trim) | **0 afvigelser** | **0 afvigelser** |
| (3) Hvor alle bytes før sidste `#` er ASCII (48.005 tekster): `extract` (branch) === basens `extract` (D1) | **0 afvigelser** | **0 afvigelser** |
| `extract` (branch) mod basens `extract` hvor der er multibyte-tegn før `#` | 69.229 forskelle | 8.948 forskelle |
| ... heraf forskelle på tekster uden batch/bottle/flaske i teksten | **0** | **0** |

- **Ny udledning er ens med og uden mbstring:** de to resultatfiler (288.152 linjer) er byte-identiske (`cmp`, ens md5), kolonnen med `extract`-resultat. Til sammenligning afviger de gamle `candidates` mellem miljøerne i 99.821 tekster, så korpusset rammer netop det område, hvor fejlen sidder.
- **Alle forskelle mellem ny og gammel er forventede:** Teksten indeholder batch, bottle eller flaske, og der står et multibyte-tegn før `#`. Eksempler: `Benriach, 8 yo, Koval Bourbon, 46,0% – Batch #12`: gammel `"12"`, ny `null`. `Girvan, 11 YO, Ximénez-Spínola PX, 46,0% – Batch #12`: gammel `"12"`, ny `null`.
- **Følsomhed:** Differentialtestens kontroller er prøvet mod mutanter (tabellen herunder, kolonnen "diff.php"): de fanger alle mutanter af vindue, offset og filter, så de 0 afvigelser betyder noget.
- **Ydelse og robusthed:** 100.000, 400.000 og 2.000.000 × `Ø` + ` Batch #12` giver `null` uden preg-fejl, både med og uden JIT (`-d pcre.jit=0`). Webhotel-navne er under 1.000 tegn.

## Mutanter

Lavet på kopier af HEAD i scratchpad (`git archive HEAD`), aldrig i arbejdstræet. Hver mutant er kørt mod enhedstesten med og uden mbstring, databasetesten (databasen gendannet fra dump mellem hver) og min egen differentialtest. "Opdaget" betyder exit ≠ 0 med mindst én FEJL ud over mbstring-vagten. Numrene B01-B20 er denne rapports egne (ikke de samme som M1-M22 i den forrige rapport).

| # | Mutant | Enhedstest (mb) | Enhedstest (uden mb) | Databasetest | diff.php (1/2/3) |
|---|---|---|---|---|---|
| B01 | `extract` bruger den gamle udledning (`scan(…,true)`) | **opdaget**, 14 FEJL | 2 FEJL | **opdaget**, 7 FEJL (J `'12'`, K `NULL`) | 0 / 27.828 / 0 |
| B02 | `candidates` bruger den nye udledning | **opdaget**, 9 FEJL | 1 (kun vagten) | **opdaget**, 9 FEJL | 44.283 / 0 / 0 |
| B03 | gammel gren får rigtigt offset (`substr`) | **opdaget**, 9 FEJL | 1 (kun vagten) | **opdaget**, 9 FEJL (K i `kept`) | 44.283 / 0 / 0 |
| B04 | **ny gren: `$tail=substr($before,-18)`** | **opdaget**, 1 FEJL (række k) | 2 FEJL | overlever | 0 / 3.534 / 0 |
| B05 | ny gren: `$before` med `mb_substr` (selve fejlen) | **opdaget**, 14 FEJL | 1 (kun vagten) | **opdaget**, 7 FEJL | 0 / 27.828 / 0 |
| B06 | ny gren: `$tail=mb_substr($before,-18,…)` | overlever | **8 FEJL** (`Call to undefined function mb_substr()`) | overlever | 0 / 0 / 0 |
| B07 | ny gren: `$tail=''` (filter virker aldrig) | **opdaget**, 14 FEJL | 15 FEJL | **opdaget**, 4 FEJL | 0 / 35.196 / 6.396 |
| B08 | ny gren: vindue 17 tegn | overlever | 1 (kun vagten) | overlever | 0 / 1.822 / 26 |
| B09 | ny gren: vindue 19 tegn | overlever | 1 (kun vagten) | overlever | 0 / 1.931 / 37 |
| B10 | ny gren: `substr($text,0,$offset+1)` | **opdaget**, 14 FEJL | 15 FEJL | **opdaget**, 4 FEJL | 0 / 35.196 / 6.396 |
| B11 | `$legacy` ignoreres (altid ny gren) | **opdaget**, 9 FEJL | 1 (kun vagten) | **opdaget**, 9 FEJL | 44.283 / 0 / 0 |
| B12 | `bool $legacy=true` (standardværdi) | **opdaget**, 1 FEJL (case 106) | 2 FEJL | overlever | 0 / 0 / 0 |
| B13 | `extract` får ekstra parameter | **opdaget**, 1 FEJL (case 106) | 2 FEJL | overlever | 0 / 0 / 0 |
| B14 | `candidates` mister returtypen | **opdaget**, 1 FEJL (case 106) | 2 FEJL | overlever | 0 / 0 / 0 |
| B15 | gammel gren: vindue 17 tegn | overlever | 1 (kun vagten) | overlever | 36 / 0 / 0 |
| B16 | ny gren: halens regex uden `u`-flag | **opdaget**, 1 FEJL | 2 FEJL | overlever | 0 / 3.534 / 0 |
| B17 | filteret på begge mønstre (også cask/fad/barrel) | overlever | 1 (kun vagten) | overlever | 0 / 0 / 0 |
| B18 | oprydningens bevis bruger den nye udledning | overlever | 1 (kun vagten) | **opdaget**, 9 FEJL | 0 / 0 / 0 |
| B19 | `mbstring` fjernet fra `tests.yml` | **opdaget**, 1 FEJL (case 102) | 2 FEJL | overlever | 0 / 0 / 0 |
| B20 | batch-filteret fjernet helt | **opdaget**, 20 FEJL | 17 FEJL | **opdaget**, 12 FEJL | 14.321 / 35.196 / 6.396 |

- **Kravet fra kriterie 107 er opfyldt:** B01 og B03 fejler i databasetesten, præcis som spec'en beskriver (J `'12'` og K `NULL`; K i `kept`). De fejler også i enhedstesten.
- **`substr($before,-18)`-mutanten (B04) er opdaget** af række k i case 101 (forventet `null`, fik `'12'`). Mutanten findes kun i den nye gren og ændrer ikke databasetestens J og K, så databasetesten overlever den, hvilket er rimeligt.
- Uden mbstring giver mutanter af den gamle gren (B02, B03, B11) kun mbstring-vagtens ene FEJL, fordi den gamle udledning ikke testes uden mbstring (D5). Mutanter af den nye gren bliver stadig fanget uden mbstring (B01, B04, B07 m.fl.).
- **5 overlevende, ingen af dem produktionsfejl:** B06, B08, B09, B15 og B17 (se "Bemærkninger"). De nye tests er kun tilføjet oven på basens tests, så B08, B09, B15 og B17 er heller ikke dækket af basens egne tests. B06 fanges kun, når enhedstesten køres uden mbstring (8 FEJL, `Call to undefined function mb_substr()`), og det sker hverken i `run.sh` eller i CI.

## Kriterie 110: Kims fil

**`lager 19-10-2026.xlsx` findes ikke på maskinen.** Søgt (`find`) i `Downloads`, `Documents`, `Dokumenter`, `OneDrive` og `OneDrive - HSG Whisky` (inklusive undermapperne `Skrivebord` og `Dokumenter`; en mappe `Desktop` findes ikke) efter `*19-10-2026*`, `*19.10.2026*`, `*2026-10-19*` og `*lager*.xls*`. Den eneste træffer på "19-10-2026" uden for repoet er Excels egen AutoRecover-fil `AppData/Roaming/Microsoft/Excel/lager 19-10-2026 (002)((Unsaved-…)).xlsb` (ændret 19-10 18:22, skrivebeskyttet). Det er en ikke-gemt, binær midlertidig kopi i `.xlsb`-format uden for de angivne mapper, som uploadlæseren ikke understøtter (kun `.xlsx` og `.csv`), og den er ikke brugt. Fundet: `Downloads/lager 18-10-2026.xlsx` (ændret 18-10 15:07, 119.943 byte), `Downloads/Kopi af lager 18-10-2026 (003).xlsx` (ændret 19-10 12:55, 120.169 byte) og ældre lagerfiler (`lager 11-09-2026.xlsx`, `lager liste d. 06-09-2026.xlsx` m.fl.). Filerne er kun læst, hvor de ligger, og er ikke kopieret ind i repoet.

**Metode** (`kim_scan.php` + `kim_cmp.php`): samme kode kørt i to separate PHP-processer, én med basen (`git archive` af `e066862`) og én med HEAD (`e1b8c32`), begge mod samme klonede testdatabase (181 startlagerprodukter). To sammenligninger pr. fil:

- **A)** `hsg_supplier_source_fields(række, kolonnevalg)` på alle 377 rækker efter overskriftsrækken. Kolonnevalg fra afsnittet "Kriterie 96" i den forrige rapport: `sku=E, name=F, age_text=G, distillery=H, brand_name=J, abv=K, stock_quantity=Q, wholesale_price=Z, retail_price=AA`.
- **B)** `hsg_supplier_prepare_preview` med det automatiske kolonnegæt (samme kolonnevalg, som A) på alle 291 varer: hele `source`, `changes`, `match` og alle andre felter pr. vare sammenlignet.

| Fil | A) rækker med forskel | B) varer med forskel |
|---|---|---|
| `lager 18-10-2026.xlsx` | **ingen** (0 af 377) | **ingen** (0 af 291) |
| `Kopi af lager 18-10-2026 (003).xlsx` | **ingen** (0 af 377) | **ingen** (0 af 291) |

- **Rækker, der er forskellige: ingen.** Der er heller ikke forskel i `changes` eller varenummerforslag.
- **Hvorfor ingen:** I filen er der ingen række med et multibyte-tegn før sidste `#` i navnet (0 af 119 rækker med `#`; 21 af 377 navne har æ/ø/å/é/– et andet sted). De tre rækker med "Batch #" (række 216, 217 og 218, "St Bridget's Kirk … batch #1/#5") har kun ASCII før `#`, og giver `null` før og efter.
- **Q-B1:** På de to 18-10-filer er der ingen gemte batchnumre, som rettelsen ville have påvirket, så der er heller ikke noget at rydde. Dette afgør ikke 19-10-filen.
- **Følsomhed:** Samme harness mellem `df886b4` og basen `e066862` finder præcis de 13 rækker (`source.cask_number`) og 2 `changes.cask_number`, som den forrige rapport fandt i kriterie 96. Harnessen kan altså se en forskel, når der er en.
- **Skal gøres manuelt:** Kør samme sammenligning på `lager 19-10-2026.xlsx`, og skriv rækkerne i PR'en, eller "ingen". Filen "Kopi af lager 18-10-2026 (003)" er ændret 19-10, men har 18-10 i navnet, så den må ikke regnes som 19-10-filen.

## Ekstra kontroller

- **End-to-end over HTTP** (`http2.sh`): `php -S` mod en kopi af basen (8811) og en kopi af HEAD (8812), samme database, login som admin, `POST product_enrich.php` med `use_ai=0` (som "Udfyld fra varetekst"). Tekster sendt fra fil for at bevare UTF-8.

  | Tekst | `cask_number`: basen | `cask_number`: HEAD |
  |---|---|---|
  | `Blåbær Batch #12` | `12` | ingen |
  | `Glen Test – Batch #12` | ingen (normaliseres først) | ingen |
  | `Cask Strength, Blåbær Batch #12` | `12` | ingen |
  | `ÆØÅ ÆØÅ ÆØÅ #12 Batch #34` | `34` | `12` |
  | `Kim’s Batch #12` | `12` | ingen |
  | `Ærø Whisky Bottle #25` | `25` | ingen |
  | `Ærø Single Malt, flaske #12` | `12` | ingen |
  | `Kløver Whisky Batch #12` | `12` | ingen |
  | `<script>alert(1)</script> Æ Batch #12` | `12` | ingen |
  | `Teaninich, 10 års, #707145, …` | `707145` | `707145` |
  | `Å'bne Cask No. 231584` | `231584` | `231584` |
  | `Glen Test – #100` | `100` | `100` |
  | `Blabaer Batch #12` | ingen | ingen |

  POST uden CSRF-token: **419** (begge). Uden login: **401** (begge). Endpointet kræver `products.manage` (`product_enrich.php:3`). Der er ingen nye sider, handlinger eller capabilities (spec afsnit 6), og en linkbruger er ikke testet særskilt, fordi ingen adgangsregler er ændret. Svaret er JSON, så `<script>`-teksten kommer ikke ud som HTML.
- **Kanttilfælde direkte på funktionen** (`crit.php`, med og uden mbstring): tom tekst og kun mellemrum/tab/linjeskift giver `null`; ugyldig UTF-8 (`Bl\xE5b\xE6r Batch #12`) giver `null` uden fejl; NBSP mellem `Batch` og `#` filtreres; linjeskift i halen tæller som tegn; `Batch` + 13 mellemrum + `#12` (18 tegn) filtreres, `Batch` + 14 mellemrum + `#12` (19 tegn) filtreres ikke; 12 × Æ + ` Batch #12` filtreres; `Ærø Release #12` giver stadig `12` (kendt begrænsning, uændret); `Ærø Batch Cask 12` giver `12`.
- **Mobilbredde:** Ikke relevant. Ændringen har ingen skærm, og der er ingen layoutændring (spec afsnit 2).
- **Release-tjek:** Trin 2 (negativtesten for release-bygger) er grøn. Ingen nye filer uden for `tests/`, `docs/specs/` og de filer, spec'en nævner, så der kommer ingen test- eller dev-filer med i ZIP'en. Version, `hsg-package.json` og `app_version.php` (`11.1.4`) er uændrede, som D4 siger.

## Bemærkninger (blokerer ikke, ingen af dem er en fejl i koden)

1. **18-tegns-vinduet er ikke testet (B08, B09, B15).** Ingen af testene fejler, hvis vinduet ændres til 17 eller 19 tegn, hverken i den nye eller i den gamle gren. Det samme gælder basens tests (de nye tests er kun tilføjet oven på dem). D1 siger, at vinduet bevares, så en rettelse kunne have fanget det. Forslag til to rækker i tabel T-stil (begge er kørt i `crit.php` og giver det angivne resultat):
   - `'Batch'.str_repeat(' ', 13).'#12'` (18 tegn før `#`): `extract` = `null`. Fanger vindue 17.
   - `'Batch'.str_repeat(' ', 14).'#12'` (19 tegn før `#`): `extract` = `'12'`. Fanger vindue 19.
2. **"Ingen `mb_*` i den nye gren" er kun dækket af review, ikke af en automatisk test (B06).** `run.sh` og CI kører altid med mbstring, så en senere ændring, der bruger `mb_substr` i den nye gren, giver samme resultat og slipper igennem. Den fanges kun, hvis enhedstesten køres uden mbstring (8 FEJL i mit forsøg). Spec'en kræver kun review her (kriterie 106). Forslag, ikke krav: en lille test, der starter `php -n` som underproces og kalder `hsg_extract_cask_number` på tabel T, eller et tjek på kildeteksten af `else`-grenen.
3. **Filteret er kun beregnet til #-mønstret (B17).** Hvis filteret også blev anvendt på cask/fad/barrel-mønstret, ville "Batch Cask 12" miste sit nummer. Ingen test dækker det. Det er gammel adfærd og ikke en del af denne rettelse. Forslag: en række `extract('Ærø Batch Cask 12') === '12'` (kørt, giver `'12'`).
4. **Docblocks.** `hsg_cask_number_scan`'s docblock har en ekstra linje om `$legacy` ud over D1's tekst. `hsg_extract_cask_number` begynder med "Fadnummer (den nye udledning): den første kandidat…" i stedet for "Den første kandidat…". Indholdet er det samme.
5. **mbstring uden fallback andre steder.** Som spec'en skriver (afsnit 9) bruger hurtigsøgningen `mb_strlen` og `mb_strtolower`. Det er uændret og uden for scope.
6. **Lokalt miljø:** `bash tests/run.sh` uden PHPRC fejler også i trin 2 (`ZipArchive` mangler uden php.ini), udover mbstring-vagten. Det er den lokale PHP's manglende standard-ini og ikke et krav fra branchen.

## Hvad er IKKE kørt

| Hvad | Hvorfor | Konsekvens |
|---|---|---|
| Kriterie 110 på **`lager 19-10-2026.xlsx`** | Filen findes ikke på maskinen | Kørt på de to 18-10-filer: ingen forskelle. **Brugeren skal køre sammenligningen på 19-10-filen** og skrive rækkerne i PR'en (eller "ingen"). |
| CI (GitHub Actions, PHP 8.2, MySQL 8.0) | Kan ikke køres lokalt | `tests.yml` indeholder `mbstring`, og kriterie 102 tjekker det, men en rigtig CI-kørsel er ikke set. Lokalt er MariaDB (ikke MySQL 8.0). |
| PHP 8.1 | Kun 8.5.8 installeret | Den nye kode bruger intet nyere end 8.1 (`preg_match`, `substr`), men det er ikke prøvet. |
| Rigtig opgradering via `update.php` (ZIP) og FTP-varianten | Version er ikke hævet (D4), og det ville skrive i `uploads/` | Oprydningen er testet med databasetesten (kørsel 1-3, produkt A-K) og ved frisk installation (case 87). |
| Release-ZIP bygget og pakket ud | Versionen hæves først ved release | Negativtesten (trin 2) er grøn. |
| Rigtig telefon / mobilbredde | Ingen skærmændringer | - |
| Q-B1 (rydning af gemte batchnumre med ciffer) | Uden for scope (spec afsnit 9) | Kriterie 110 på 19-10-filen giver grundlaget, hvis det bliver aktuelt. |

## Oprydning og scripts

- **Ændret i repoet:** kun denne rapport. Ingen produktionskode, ingen tests, ingen spec er rørt. `git status` er ren, og der ligger ingen `config.php` i arbejdstræet (trin 4 slettede sin egen).
- **Databaser:** `hsg_test` står som frisk installation efter `run-all.sh`. `hsg_test_qb` (klon til mutanter og HTTP-test) er droppet.
- **Servere:** `php -S` på 8811 og 8812 er stoppet. To andre `php -S`-processer (8796 og 8798) tilhører en anden session og er ikke rørt.
- **Scripts** ligger i scratchpad under `tester/` (ikke i repoet): `lib.php`, `tableT.php`, `diff.php`, `crit.php`, `big.php`, `mkmut.php`, `runmut.sh`, `kim_scan.php`, `kim_cmp.php`, `http2.sh`. Mutanterne ligger under `tester/mut/`, rå output i `diff_mb.txt`, `diff_nomb.txt`, `mutresult.txt`, `mutlog_*.txt`, `kim_*.json` og `runall_full.txt`. Filen "lager 18-10-2026.xlsx" er ikke kopieret.

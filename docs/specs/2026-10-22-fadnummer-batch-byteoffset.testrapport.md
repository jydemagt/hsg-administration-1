# Testrapport: batch-filterets byte-offset i fadnummer-udledningen (ingen modulversionsændring)

- **Spec:** `docs/specs/2026-10-22-fadnummer-batch-byteoffset.md` (acceptkriterie 101-110, plus afsnit 10 om rettelserne efter review). Brugerens svar: Q-B1 = nej.
- **Branch:** `feature/fadnummer-batch-byteoffset`. Første test på HEAD `e1b8c32` (rapport `008c50a`). **Gentest efter review på HEAD `e59007e`**, som er den kode, der merges. Mellem de to ligger udviklerens `9e41df0`, `6bebdbc`, `c5d1bd9`, `ca992f8` og `e59007e`. Basen er `origin/feature/kims-upload-fadnummer-ciffer` = PR #177, `e066862`.
- **Testet:** 22-10-2026, af uafhængig tester. Udviklerens egne tal er ikke brugt som bevis. Alt nedenfor er kørt af testeren, og tabel T er læst direkte ud af spec'ens markdown-tabel og ikke skrevet af i hånden.
- **Miljø:** PHP 8.5.8 (CLI). Med mbstring: `PHPRC` peger på scratchpad-`php.ini` (mbstring, zip, pdo_mysql, gd m.m.). Uden mbstring: `php` uden PHPRC, og til databasetesten en ini uden mbstring, men med pdo_mysql. MariaDB på 127.0.0.1:3327. Hele pakken er kørt mod `hsg_test`. Mutanter og HTTP-test er kørt mod en klon, `hsg_test_qb`, som er droppet igen.
- **Læsevejledning:** Resten af rapporten gælder `e59007e`. Afsnittet "Gentest efter review" viser, hvad der er ændret siden første test, og hvad der er målt igen. Hvor første test på `e1b8c32` er nævnt, står det udtrykkeligt.

## Samlet konklusion: KLAR (HEAD e59007e)

Ingen fejl fundet, hverken i første test eller i gentesten. Kriterie 101-109 er bestået på `e59007e`. Kriterie 110 er delvist kørt, fordi `lager 19-10-2026.xlsx` ikke findes på maskinen. Samme sammenligning på de to 18-10-filer, der findes, giver **ingen forskelle**.

- `bash tests/run.sh` er grøn i alle 4 trin: **275 / 153 / 56 / 123 / 66** tjek (på `e1b8c32` var det 275 / 143 / 56 / 123 / 66).
- Tabel T (nu 17 rækker, a-q) er kørt med og uden mbstring og er som i spec'en i begge tilfælde.
- `hsg_cask_number_candidates` er **identisk med basens funktion på 444.448 tekster**, med og uden mbstring (0 afvigelser).
- `hsg_extract_cask_number` er **identisk med og uden mbstring** (samme md5 af alle resultater), lig en uafhængig tegn-baseret reference, **lig `e1b8c32`s regex-hale på alle tekster** (også alle kandidater, ikke kun det første resultat), og lig basens funktion på alle 49.385 tekster uden multibyte-tegn før `#`.
- **BØR 1 er lukket:** Den ondsindede Excel-celle (32.767 tegn, `#12 ` gentaget 8.192 gange) tager 14-16 ms på `e59007e` mod 2.200-2.400 ms (JIT) og 10.500-11.100 ms (`pcre.jit=0`) på `e1b8c32`. Over HTTP med `pcre.jit=0`: 11,1 s mod 0,34 s.
- **BØR 2 er lukket:** Mutanterne med vindue på 17 tegn, 19 tegn og 18 bytes, som overlevede før, bliver nu alle fanget af rækkerne m-q i tabel T.
- mbstring-vagten virker uændret: uden PHPRC kommer **præcis én FEJL** med D5-teksten, og exit-koden er 1.
- 24 egne mutanter af `e59007e`: 22 opdages af testpakken. De to overlevende er testhuller uden produktionsfejl (R06 og R17, se "Bemærkninger").

## Gentest efter review (HEAD e59007e)

Diff'en fra `e1b8c32` til `e59007e` rører `core/product_enrichment.php` (halen i den nye gren og et docblock), `tests/product_enrichment_test.php` (rækkerne m-q), `README.txt` (release-linjen) og spec'en. Intet andet i produktionskoden, og `tests/cask_number_cleanup_db_test.php` er uændret.

### 1. Hele pakken: `bash tests/run.sh` via `run-all.sh`

Frisk database `hsg_test`, derefter hele `tests/run.sh` med PHPRC. Exit 0, "ALLE TESTS BESTÅET". Arbejdstræet var bagefter uden `config.php` og uden ændringer.

| Trin | Resultat | Tjek | `e1b8c32` |
|---|---|---|---|
| 1 PHP-syntaks | OK | - | - |
| 2 Release-negativtest | OK | - | - |
| 3 Enhedstest, Kims uploadfil | OK | **275** | 275 |
| 3 Enhedstest, fadnummer-udledning | OK | **153** | 143 |
| 4 Installation og røgtest | OK: alle 19 sider HTTP 200, `products.php` med manipuleret status escapet, uden login 401, POST uden CSRF 419, `reservation_mode` og `reguess` 419 og 401 | - | - |
| 4 Databasetest, Kims uploadfil | OK | **56** | 56 |
| 4 Databasetest, fadnummer-oprydning | OK | **123** | 123 |
| 4 Flowtest, Kims uploadfil | OK | **66** | 66 |

Enhedstesten er gået fra 143 til 153 (+10): fem nye rækker (m-q) i tabel T, hver tjekket i både case 101 og 102.

### 2. Korpus igen på den nye kode

`diff2.php` er `diff.php` fra første test, udvidet med to nye korpusklasser og tre sammenligninger med `e1b8c32` (hentet med `git archive`, funktionerne omdøbt til `prev_*`). Kørt med og uden mbstring.

**Korpus: 444.448 forskellige tekster** (49.385 med kun ASCII før sidste `#`, 395.063 med multibyte-tegn før sidste `#`). De første 288.152 er det samme korpus som i første test (startlagerets 175 + 8 navne rå og normaliseret, tabel T og tabel 78, 976 startlagernavne med batch-haler og æøå-præfikser, 136.967 tilfældige og 149.993 målrettede tekster, tre lange tekster). Nyt i gentesten:

| Ny kilde | Antal |
|---|---|
| Rækkerne m-q i tabel T (tabel T og tabel 78 er nu 36 tekster i alt, rå og normaliseret) | indgår i 36 |
| "wide": 0-30 tegn af blandet bredde (🥃, 😀 og 𝄞 på 4 bytes, `€`, `’`, `–`, æ, é, ø, NBSP, U+2003, U+3000) foran et batch/bottle/flaske-ord, 0-20 blandede mellemrum (mellemrum, NBSP, U+2003, U+3000, tab, linjeskift, CRLF), `#`, en værdi; ofte et ekstra `Batch #` bagefter og et `Cask`-præfiks | 149.920 |
| "window": systematisk vindue. Præfiks (ingen, `a`, `æ`, `€`, 🥃) × ord (Batch, batch, Bottle, Flaske) × mellemrumstype (mellemrum, NBSP, U+2003) × 0-25 mellemrum × 1, 2, 3, 9 og 20 gentagelser af præfikset | 6.370 |

| Kontrol | Med mbstring | Uden mbstring |
|---|---|---|
| (1) `candidates` (HEAD) === basens `candidates` (den gamle udledning er uændret) | **0 afvigelser** | **0 afvigelser** |
| (2) `extract` (HEAD) === uafhængig reference (tegn-baseret via `preg_split('//u')`) | **0 afvigelser** | **0 afvigelser** |
| (3) Hvor alle bytes før sidste `#` er ASCII (49.385 tekster): `extract` (HEAD) === basens `extract` (D1) | **0 afvigelser** | **0 afvigelser** |
| (4) `extract` (HEAD, bytegang) === `extract` (`e1b8c32`, regex-hale) | **0 afvigelser** | **0 afvigelser** |
| (5) `scan(HEAD, false)` === `scan(e1b8c32, false)`: alle kandidater, ikke kun det første | **0 afvigelser** | **0 afvigelser** |
| (6) `scan(e1b8c32, true)` === basens `candidates` (kontrol af min egen kopi af forrige HEAD) | 0 | 0 |
| `extract` (HEAD) mod basens `extract`, multibyte-tegn før `#` | 112.012 forskelle | 33.701 forskelle |
| ... heraf på tekster uden batch/bottle/flaske i teksten | **0** | **0** |

- **Ny udledning er ens med og uden mbstring:** kolonnen med `extract`-resultat i de to resultatfiler (444.448 linjer) er byte-identisk (`cmp`, md5 `40412bb80fe20db70ee69df3545e1ccd` begge steder). De gamle `candidates` afviger mellem de to miljøer i 138.424 tekster, så korpusset rammer det område, hvor den gamle fejl sidder.
- **Bytegangen giver samme hale som regex-halen** på alle 444.448 tekster, inklusive 4-byte-tegn, NBSP og andre Unicode-mellemrum, linjeskift/CRLF og vinduesgrænsen fra begge sider.
- **Egne kontroller på `crit.php`** (kriterie 78/79/103/104/105 og kanttilfælde): 832 tjek med mbstring og 807 uden, alle grønne. Heraf alle 362 startlagernavne (rå og normaliseret) og alle 20 rækker i tabel 78.
- **Robusthed:** 100.000, 400.000 og 2.000.000 × `Ø` + ` Batch #12` giver `null` uden preg-fejl, med og uden JIT.

### 3. Tiden på den ondsindede celle (målt af testeren)

`perf.php` kalder `hsg_extract_cask_number` på HEAD, på `e1b8c32` og på basen. Alle tre giver samme resultat (`"12"`). Millisekunder, ét kald hver, mbstring indlæst.

| Tekst | HEAD `e59007e`, JIT | HEAD `e59007e`, `pcre.jit=0` | `e1b8c32`, JIT | `e1b8c32`, `pcre.jit=0` | basen, JIT |
|---|---|---|---|---|---|
| 32.767 tegn, `#12 ` gentaget (8.191 match) | **14** | **16** | 2.372 | **11.137** | 404 |
| 32.766 bytes, `æ#12 ` gentaget (5.461 match) | 10 | 11 | 1.213 | 4.476 | 258 |
| 32.767 tegn, `Batch #12 ` gentaget (alle filtreres) | 5 | 7 | 864 | 4.346 | 130 |
| 32.767 tegn, `Cask 12 ` gentaget (cask-mønstret) | 6 | 7 | 1.077 | 5.283 | 162 |
| ca. 32.767 tegn, `🥃#12 ` gentaget (43.688 bytes) | 13 | 13 | 1.338 | 4.924 | 587 |
| 1.000.000 tegn, `#12 ` gentaget (250.000 match) | 487 | 484 | - | - | - |
| 1.000.000 tegn, `æ#12 ` gentaget (166.666 match) | 345 | 361 | - | - | - |

- Uden mbstring er tallene for HEAD de samme (14-16 ms på den første celle). Basen er uden mbstring hurtig (9 ms), fordi `substr` ikke gennemløber teksten.
- **Hele kæden** (`hsg_product_parse_text` og `hsg_supplier_source_fields` på samme celle, `pcre.jit=0`): `e59007e` 42 og 37 ms, `e1b8c32` 10.704 og 10.798 ms, basen 386 og 376 ms.
- **Over HTTP** (`POST product_enrich.php` med cellen, `php -S -d pcre.jit=0`, admin): `e1b8c32` **11,1 s**, `e59007e` **0,34 s**. Samme svar (`cask_number: "12"`).
- De to 1.000.000-tegn-rækker er kun målt på HEAD, fordi den gamle regex-hale ikke blev færdig i rimelig tid på dem.
- Reviewerens tal (326 ms / 2,3 s / 11,3 s) er genkendt: min måling af `e1b8c32` er 2,4 s med JIT og 11,1 s uden, og basen er 0,4 s.

### 4. Tidligere overlevende mutanter

24 mutanter af `e59007e` (`mkmut2.php`; kopier af `git archive HEAD` i scratchpad, aldrig i arbejdstræet). Hver er kørt mod enhedstesten med og uden mbstring, databasetesten (database gendannet mellem hver) og min differentialtest. "Opdaget" betyder exit ≠ 0 med mindst én FEJL ud over mbstring-vagten.

| # | Mutant | Enhedstest (mb) | Enhedstest (uden mb) | Databasetest | diff2 (1/2/3) |
|---|---|---|---|---|---|
| R01 | `extract` bruger den gamle udledning | **opdaget**, 16 FEJL | 3 FEJL | **opdaget**, 7 FEJL (J `'12'`, K `NULL`) | 0 / 46.078 / 0 |
| R02 | `candidates` bruger den nye udledning | **opdaget**, 11 FEJL | 1 (kun vagten) | **opdaget**, 9 FEJL | 72.051 / 0 / 0 |
| R03 | gammel gren får rigtigt offset | **opdaget**, 11 FEJL | 1 (kun vagten) | **opdaget**, 9 FEJL (K i `kept`) | 72.051 / 0 / 0 |
| **R04** | **ny gren: vindue på 18 bytes (`substr`, ikke justeret til tegngrænse)** | **opdaget**, 2 FEJL (række k og q) | 3 FEJL | overlever | 0 / 14.241 / 0 |
| **R05** | **ny gren: vindue på 18 bytes, rykket frem til tegngrænse** | **opdaget**, 1 FEJL (række q) | 2 FEJL | overlever | 0 / 6.140 / 0 |
| R06 | ny gren: `$tail=mb_substr(…,-18)` | overlever | **8 FEJL** (`Call to undefined function mb_substr()`) | overlever | 0 / 0 / 0 |
| R07 | ny gren: `$tail=''` | **opdaget**, 17 FEJL | 18 FEJL | **opdaget**, 4 FEJL | 0 / 53.511 / 6.613 |
| **R08** | **ny gren: vindue 17 tegn** | **opdaget**, 2 FEJL (række m og o) | 3 FEJL | overlever | 0 / 4.147 / 71 |
| **R09** | **ny gren: vindue 19 tegn** | **opdaget**, 1 FEJL (række n) | 2 FEJL | overlever | 0 / 4.264 / 82 |
| R10 | halen tager `#` med (`$offset-$i+1`) | **opdaget**, 17 FEJL | 18 FEJL | **opdaget**, 4 FEJL | 0 / 53.511 / 6.613 |
| R11 | `$legacy` ignoreres | **opdaget**, 11 FEJL | 1 (kun vagten) | **opdaget**, 9 FEJL | 72.051 / 0 / 0 |
| R12 | `bool $legacy=true` | **opdaget**, 1 FEJL (case 106) | 2 FEJL | overlever | 0 / 0 / 0 |
| R13 | `extract` får ekstra parameter | **opdaget**, 1 FEJL (case 106) | 2 FEJL | overlever | 0 / 0 / 0 |
| R14 | `candidates` mister returtypen | **opdaget**, 1 FEJL (case 106) | 2 FEJL | overlever | 0 / 0 / 0 |
| **R15** | **gammel gren: vindue 17 tegn** | **opdaget**, 1 FEJL (række m, case 102) | 1 (kun vagten) | overlever | 81 / 0 / 0 |
| R16 | bytegangen tæller fortsættelsesbytes i stedet for første bytes | **opdaget**, 1 FEJL (række n) | 2 FEJL | overlever | 0 / 29.293 / 232 |
| R17 | bytegangen bruger maske `0x80` i stedet for `0xC0` | **overlever** | 1 (kun vagten) | overlever | 0 / 7.724 / 0 |
| R18 | `$i>0`-vagten fjernet | **opdaget**, 8 FEJL (bl.a. `Uninitialized string offset -14`) | 9 FEJL | overlever | 0 / 14.548 / 6.003 |
| **R19** | **filteret på begge mønstre (også cask/fad/barrel)** | **opdaget**, 1 FEJL (række p) | 2 FEJL | overlever | 0 / 1 / 0 |
| R20 | oprydningens bevis bruger den nye udledning | overlever | 1 (kun vagten) | **opdaget**, 9 FEJL | 0 / 0 / 0 |
| R21 | `mbstring` fjernet fra `tests.yml` | **opdaget**, 1 FEJL (case 102) | 2 FEJL | overlever | 0 / 0 / 0 |
| R22 | batch-filteret fjernet helt | **opdaget**, 24 FEJL | 20 FEJL | **opdaget**, 12 FEJL | 15.311 / 53.511 / 6.613 |
| R23 | bytegangen tæller alle bytes som tegn | **opdaget**, 2 FEJL (række k og q) | 3 FEJL | overlever | 0 / 14.241 / 0 |
| R24 | halen løber til tekstens slutning | **opdaget**, 17 FEJL | 18 FEJL | **opdaget**, 4 FEJL | 0 / 53.509 / 6.613 |

- **De mutanter, der overlevede i første test, er nu fanget:** vindue 17 (R08, før B08), vindue 19 (R09, før B09), den gamle grens vindue 17 (R15, før B15) og filteret på begge mønstre (R19, før B17). **18-bytes-mutanten er fanget i begge varianter** (R04, R05, R23), og R05, som flytter vinduet frem til en tegngrænse, falder kun på række q (NBSP), præcis som udvikleren beskriver i commit-beskeden til `e59007e`.
- **Kravene fra kriterie 107 gælder stadig:** R01 og R03 fejler i databasetesten (J `'12'` og K `NULL`; K i `kept`).
- **2 overlevende, ingen produktionsfejl:** R06 og R17. R06 fanges kun, når enhedstesten køres uden mbstring (8 FEJL). R17 er ny: se bemærkning 1. (R20 overlever enhedstesten, men fanges af databasetesten.) Min differentialtest fanger begge, undtagen R06 (som giver samme resultat med mbstring).

### 5. mbstring-vagten uden mbstring

| Kommando | Resultat |
|---|---|
| `php tests/product_enrichment_test.php` uden PHPRC | 136 bestået, **1 fejlede**, exit **1**. Den ene FEJL står under kriterie 102 og har præcis teksten fra D5. Kriterie 101 (alle 17 rækker) er grøn uden mbstring. (Med PHPRC: 153 bestået, 0 fejlede, exit 0.) |
| `php tests/cask_number_cleanup_db_test.php` (ini uden mbstring, men med pdo_mysql) | 113 bestået, **1 fejlede**, exit **1**. FEJL: "[107 J og K] mbstring er ikke indlæst … J og K er ikke indsat." Efter kørslen: 0 `TSTCASK-`-produkter, én audit-post. Med mbstring: 123 bestået, 0 fejlede. |
| `bash tests/run.sh` uden PHPRC og uden `HSG_TEST_DB_NAME` | Trin 3 viser den ene FEJL og "TESTS FEJLEDE", exit 1. (Trin 2 fejler her også, fordi `ZipArchive` mangler uden php.ini. Det er miljøet.) |

### 6. Diff'en mod basen (`git diff origin/feature/kims-upload-fadnummer-ciffer...HEAD`)

- **Filliste:** `.github/workflows/tests.yml`, `README.txt`, `core/product_enrichment.php`, `docs/specs/2026-10-22-fadnummer-batch-byteoffset.md`, `docs/specs/2026-10-22-fadnummer-batch-byteoffset.testrapport.md`, `docs/specs/2026-10-22-fadnummer-kraever-ciffer.md`, `tests/cask_number_cleanup_db_test.php`, `tests/product_enrichment_test.php`. Det er filerne fra afsnit 7 plus spec og testrapport, og intet andet.
- **Uændret:** `core/supplier_import.php`, `modules/`, `products.php`, `migrations.php`, `install.php`, `import.php`, `tests/run.sh`, `app_version.php` og `hsg-package.json` har 0 linjer i diff'en. 0 tilføjede SQL-skemalinjer uden for `docs/` og `tests/`.
- **Gammel gren uændret:** De to legacy-linjer (`$before`, `$tail` med `function_exists('mb_substr')`) er identiske med basens (sammenlignet efter fjernelse af indrykning), og de står uændrede på linje 27 og 28. `git diff -w -U0` viser kun disse fjernede linjer: de gamle docblocks, funktionshovedet (nu omdøbt til `hsg_cask_number_scan`) og `foreach`-linjen i `extract` (nu kald af `scan(…,false)`). Ingen af legacy-linjerne er fjernet eller ændret. `hsg_cask_number_cleanup`, `hsg_product_parse_text`, `hsg_product_text_normalize` og `hsg_cask_number_seed_names` er byte-for-byte identiske med basen (md5 af funktionsteksten). Hunks ligger på basens linje 12, 17, 28, 38 og 40, alle inden for `:11-44`.
- **Ingen `mb_*` i den nye gren:** `grep -n "mb_\|function_exists\|{0,18}" core/product_enrichment.php` giver kun linje 27 og 28 (den gamle gren). Den nye gren (linje 30-35) har ingen `mb_*`, ingen `function_exists` og ikke længere regex'en `.{0,18}\z`, kun `ord`, `substr` og en `while`.
- **Versioner:** `products` `1.5.1`, `supplier_upload` `1.1.1`, `modules/products/migrations.php` har kun nøglen `'1.5.1'`.
- **Spec og kode stemmer:** D1 og afsnit 10 beskriver bytegangen, som den står i koden. Docblocken for `hsg_cask_number_candidates` har den nye tekst ("Uden mbstring er halen 18 bytes og kan skære et tegn over, så filteret svigter (fx 'Kløver Whisky Batch #12')"). `README.txt:323` er ordret linjen fra spec'ens afsnit 5.

### 7. Andet kørt igen på `e59007e`

- Tabel T med alle 17 rækker (se nedenfor), med og uden mbstring.
- Kims fil (kriterie 110) mod `e59007e`: 0 forskelle i begge 18-10-filer (se nedenfor).
- End-to-end over HTTP mod `e59007e` (se nedenfor): samme tabel som før.
- `crit.php` og `big.php`: grønne.

## Testpakken, uden PHPRC og med

Se afsnit 1 og 5 i gentesten ovenfor. Opsummeret: **275 / 153 / 56 / 123 / 66** med mbstring, og uden mbstring præcis én FEJL (kriterie 102 i enhedstesten, kriterie 107 i databasetesten), exit 1.

## Acceptkriterier (HEAD e59007e)

| Nr. | Kriterie (kort) | Resultat | Bevis |
|---|---|---|---|
| 101 | `hsg_extract_cask_number` for tabel T, med og uden mbstring | BESTÅET | `tableT.php` læser tabellen ud af spec'en (**17 rækker a-q**; rækkerne m, n, o og q er bygget af spec'ens beskrivelse: 13 eller 14 mellemrum, tre 🥃, 7 × NBSP) og kalder funktionen. Med og uden mbstring giver alle 17 rækker kolonnen "Efter" (a-g, j, k, m, o og q = `null`; h, i, n og p = `'12'`; l = `'707145'`). Testens case 101 er grøn i begge tilfælde. |
| 102 | `candidates` = kolonnen "Gammel" med mbstring; én FEJL uden; `mbstring` i `extensions` | BESTÅET | Med mbstring er alle 17 rækker som i kolonnen "Gammel" (fx m `[]`, n `['12']`, o `['12']`, q `['12']`). Uden mbstring: præcis én FEJL med D5-teksten, tjekket springes over, exit 1. `.github/workflows/tests.yml:27` indeholder `mbstring`; R21 (fjernet) er opdaget. |
| 103 | ASCII-tekster og startlager uændrede | BESTÅET | Tabel 78 (20 rækker, læst fra Q2-spec'en): ny `extract` = "Efter" og `candidates[0] ?? null` = "I dag", med og uden mbstring. Tabel 79 som før. Alle 362 navne (rå og normaliseret) fra `initial-stock.json`: `extract(N)` = første værdi med ciffer i `candidates(N)`. Kontrol (3) i korpusset: 0 afvigelser mod basen på 49.385 tekster uden multibyte-tegn før `#`. Case 87 er grøn i trin 4. De eksisterende 78-79-tjek i `tests/product_enrichment_test.php` er kun udvidet, aldrig ændret. |
| 104 | `hsg_product_parse_text` | BESTÅET | `Blåbær Batch #12, 46%`: ingen `cask_number` i `fields` eller `confidence`. `ÆØÅ ÆØÅ ÆØÅ #12 Batch #34`: `'12'`. Teaninich-teksten: `'707145'`. Eget script (begge mbstring-tilstande) og testens case 104. |
| 105 | `hsg_supplier_source_fields` | BESTÅET | `Glen Test – Batch #12`: ingen `cask_number` (basen gav `'12'`). `Blåbær Batch #12`: ingen. `Glen Test – #100`: `'100'`. Eget script og testens case 105. |
| 106 | Kode og signaturer | BESTÅET | `ReflectionFunction`: `candidates` og `extract` har præcis én `string $text` og returtyperne `array` og `?string`. R12-R14 (standardværdi, ekstra parameter, manglende returtype) er opdaget af case 106. Ingen `mb_*` eller `function_exists` i den nye gren (kun linje 27-28 i den gamle). De gamle linjer er flyttet ordret. Hunks inden for `:11-44`, de øvrige funktioner byte-identiske. Docblocks svarer til D1 (inklusive den præciserede tekst fra review). |
| 107 | Oprydningen, produkt J og K | BESTÅET | 123 tjek grønne (case 83/84/107, 85/107). R01 giver J `'12'` og K `NULL` (7 FEJL), R03 giver K i `kept` (9 FEJL). Uden mbstring: én tydelig FEJL, J og K ikke indsat, resten som før. Databasetesten er uændret siden `e1b8c32`. |
| 108 | Versioner, migrationer, filliste | BESTÅET | `products` `1.5.1`, `supplier_upload` `1.1.1`, kun nøglen `'1.5.1'`. Ingen skemaændringer. Filliste som i afsnit 6 ovenfor. |
| 109 | `tests/run.sh` grøn, README-linjen | BESTÅET (lokalt) | `run.sh` med PHPRC grøn i alle trin. `README.txt:323` står under "Fadnummer kræver mindst ét ciffer (Produkter 1.5.1)", og er nu "… når der står fx æ, ø, å, ’ eller en tankestreg tidligere i navnet. Et rigtigt fadnummer før "Batch #…" bliver nu fundet.", ordret som spec'ens afsnit 5. **CI er ikke kørt** (GitHub Actions kan ikke køres lokalt). `release.yml` kører hverken enhedstests eller `run.sh`. |
| 110 | Manuel test på Kims fil | **DELVIST: 19-10-FILEN FINDES IKKE** | Se afsnittet "Kriterie 110". Ingen forskelle på de to 18-10-filer, kørt på `e59007e`. |

## Tabel T, kørt med og uden mbstring (17 rækker)

`tableT.php` (HSG_ROOT = arbejdstræet, `e59007e`). "Gammel" er `candidates` på branchen og er identisk med basens funktion i alle rækker. "Før" er basens `extract` med mbstring (spec'ens kolonne "Før rettelsen"). Rækkerne m-q er nye siden første test. Mellemrum i tabellen er skrevet som `␣`, og NBSP som `NBSP`.

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
| m | `Batch` + 13 × `␣` + `#12` | `[]` | `[]` | `null` | `null` | `null` | `null` |
| n | `Batch` + 14 × `␣` + `#12` | `["12"]` | `["12"]` | `"12"` | `"12"` | `"12"` | `"12"` |
| o | `🥃🥃🥃 Batch` + 13 × `␣` + `#12` | `["12"]` | `[]` | `"12"` | `null` | `null` | `null` |
| p | `Ærø Batch Cask 12` | `["12"]` | `["12"]` | `"12"` | `"12"` | `"12"` | `"12"` |
| q | `Batch` + 7 × `NBSP` + `#12` | `["12"]` | `["12"]` | `"12"` | `null` | `null` | `null` |

- Kolonnen "Gammel (med mb)" er lig spec'ens kolonne "Gammel" i alle 17 rækker.
- Uden mbstring afhænger den gamle udledning af byte-fallbacken, som D2 beskriver (række k giver `["12"]` også uden mbstring, som spec'en skriver). Derfor testes "Gammel" kun med mbstring.
- Den nye udledning er ens i de to miljøer. Række k er den vigtige for byte-faldgruben, række q for vinduet i tegn (NBSP er 2 bytes og 1 tegn), række o for 4-byte-tegn, og række m og n for selve vinduets grænse.

## Den gamle udledning = basens kode (differentialtest)

Metoden er som i første test: basens funktioner er hentet med `git show origin/feature/kims-upload-fadnummer-ciffer:core/product_enrichment.php`, omdøbt til `base_*` og indlæst i samme proces. Resultaterne for `e59007e` står i afsnit 2 i gentesten ovenfor (444.448 tekster, 0 afvigelser i kontrol 1-5, med og uden mbstring). På `e1b8c32` var tallene 288.152 tekster og 0 afvigelser.

## Mutanter (første test, `e1b8c32`)

20 mutanter af `e1b8c32`, hvor halen var en regex. Resultaterne er bevaret for sammenligning. De mutanter, der overlevede dengang, er nu fanget (se gentestens afsnit 4). B-numrene hører til første test.

| Mutant (`e1b8c32`) | Resultat dengang | Nu (`e59007e`) |
|---|---|---|
| B01, B02, B03, B05, B07, B10, B11, B12, B13, B14, B16, B19, B20 (udledning, offset, signaturer, yml, filter) | opdaget | tilsvarende mutanter R01-R03, R07, R10-R14, R21, R22 er opdaget |
| B04 `substr($before,-18)` | opdaget (række k) | R04 (18 bytes) opdaget (række k og q) |
| B08 / B09 vindue 17 / 19 tegn | **overlevede** | R08 / R09 opdaget (række m, o / række n) |
| B15 gammel gren, vindue 17 | **overlevede** | R15 opdaget (række m) |
| B17 filter på begge mønstre | **overlevede** | R19 opdaget (række p) |
| B06 ny gren med `mb_substr` | overlevede med mbstring | R06 overlever stadig med mbstring (fanges uden mbstring) |
| B18 oprydningens bevis bruger den nye udledning | opdaget af databasetesten | R20 opdaget af databasetesten |

## Kriterie 110: Kims fil

**`lager 19-10-2026.xlsx` findes ikke på maskinen** (søgt igen i gentesten). Søgt (`find`) i `Downloads`, `Documents`, `Dokumenter`, `OneDrive` og `OneDrive - HSG Whisky` (inklusive undermapperne `Skrivebord` og `Dokumenter`; en mappe `Desktop` findes ikke) efter `*19-10-2026*`, `*19.10.2026*`, `*2026-10-19*` og `*lager*.xls*`. Den eneste træffer på "19-10-2026" uden for repoet er Excels egen AutoRecover-fil `AppData/Roaming/Microsoft/Excel/lager 19-10-2026 (002)((Unsaved-…)).xlsb` (ændret 19-10 18:22, skrivebeskyttet). Det er en ikke-gemt, binær midlertidig kopi i `.xlsb`-format uden for de angivne mapper, som uploadlæseren ikke understøtter (kun `.xlsx` og `.csv`), og den er ikke brugt. Fundet: `Downloads/lager 18-10-2026.xlsx` (ændret 18-10 15:07, 119.943 byte), `Downloads/Kopi af lager 18-10-2026 (003).xlsx` (ændret 19-10 12:55, 120.169 byte) og ældre lagerfiler. Filerne er kun læst, hvor de ligger, og er ikke kopieret ind i repoet.

**Metode** (`kim_scan.php` + `kim_cmp.php`): samme kode kørt i to separate PHP-processer, én med basen (`git archive` af `e066862`) og én med HEAD, begge mod samme klonede testdatabase (181 startlagerprodukter). Kørt på `e1b8c32` i første test og igen på **`e59007e`** i gentesten. To sammenligninger pr. fil:

- **A)** `hsg_supplier_source_fields(række, kolonnevalg)` på alle 377 rækker efter overskriftsrækken. Kolonnevalg fra afsnittet "Kriterie 96" i den forrige rapport: `sku=E, name=F, age_text=G, distillery=H, brand_name=J, abv=K, stock_quantity=Q, wholesale_price=Z, retail_price=AA`.
- **B)** `hsg_supplier_prepare_preview` med det automatiske kolonnegæt (samme kolonnevalg som A) på alle 291 varer: hele `source`, `changes`, `match` og alle andre felter pr. vare sammenlignet.

| Fil | A) rækker med forskel (`e59007e`) | B) varer med forskel (`e59007e`) |
|---|---|---|
| `lager 18-10-2026.xlsx` | **ingen** (0 af 377) | **ingen** (0 af 291) |
| `Kopi af lager 18-10-2026 (003).xlsx` | **ingen** (0 af 377) | **ingen** (0 af 291) |

- **Rækker, der er forskellige: ingen.** Der er heller ikke forskel i `changes` eller varenummerforslag. Det samme gjaldt på `e1b8c32`.
- **Hvorfor ingen:** Ingen række i filerne har et multibyte-tegn før sidste `#` i navnet (0 af 119 rækker med `#`; 21 af 377 navne har æ/ø/å/é/– et andet sted). De tre rækker med "Batch #" (216, 217 og 218, "St Bridget's Kirk … batch #1/#5") har kun ASCII før `#` og giver `null` før og efter.
- **Q-B1:** På de to 18-10-filer er der ingen gemte batchnumre, som rettelsen ville have påvirket. Dette afgør ikke 19-10-filen.
- **Følsomhed:** Samme harness mellem `df886b4` og basen `e066862` finder præcis de 13 rækker (`source.cask_number`) og 2 `changes.cask_number`, som kriterie 96 i den forrige rapport fandt.
- **Skal gøres manuelt:** Kør samme sammenligning på `lager 19-10-2026.xlsx`, og skriv rækkerne i PR'en, eller "ingen". "Kopi af lager 18-10-2026 (003)" er ændret 19-10, men har 18-10 i navnet og må ikke regnes som 19-10-filen.

## Ekstra kontroller

- **End-to-end over HTTP** (`http2.sh`, kørt på `e59007e`): `php -S` mod en kopi af basen (8811) og en kopi af HEAD (8812), samme database, login som admin, `POST product_enrich.php` med `use_ai=0` (som "Udfyld fra varetekst"). Tekster sendt fra fil for at bevare UTF-8. Resultaterne er identiske med første test:

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
- **Kanttilfælde direkte på funktionen** (`crit.php`, med og uden mbstring): tom tekst og kun mellemrum/tab/linjeskift giver `null`; ugyldig UTF-8 (`Bl\xE5b\xE6r Batch #12`) giver `null` uden fejl; NBSP mellem `Batch` og `#` filtreres; linjeskift i halen tæller som tegn; `Batch` + 13 mellemrum + `#12` filtreres, med 14 mellemrum filtreres den ikke; `Ærø Release #12` giver stadig `12` (kendt begrænsning, uændret); `Ærø Batch Cask 12` giver `12`.
- **Mobilbredde:** Ikke relevant. Ændringen har ingen skærm, og der er ingen layoutændring (spec afsnit 2).
- **Release-tjek:** Trin 2 (negativtesten for release-bygger) er grøn. Ingen nye filer uden for `tests/`, `docs/specs/` og de filer, spec'en nævner. Version, `hsg-package.json` og `app_version.php` (`11.1.4`) er uændrede, som D4 siger.

## Bemærkninger (blokerer ikke, ingen af dem er en fejl i koden)

1. **Mutanten R17 overlever (ny).** Hvis bytegangens maske ændres fra `0xC0` til `0x80`, tæller kun ASCII-bytes som tegn, så et multibyte-tegn inde i vinduet ikke tæller med, og vinduet bliver for langt. Ingen række i tabel T har et multibyte-mellemrum *inde i* de 18 tegn, mens "Batch" ligger lige uden for. Forslag til en ekstra række (kørt: HEAD giver `'12'`, mutanten giver `null`, `e1b8c32` giver `'12'`):
   - `'Batch'.str_repeat(' ', 13)."\u{00A0}".'#12'` (19 tegn før `#`, ét af dem NBSP): `extract` = `'12'`, `candidates` med mbstring = `['12']`.
2. **"Ingen `mb_*` i den nye gren" er kun dækket af review, ikke af en automatisk test (R06, tidligere B06).** `run.sh` og CI kører altid med mbstring, så en senere ændring, der bruger `mb_substr` i den nye gren, giver samme resultat og slipper igennem. Den fanges kun, hvis enhedstesten køres uden mbstring (8 FEJL i mit forsøg). Spec'en kræver kun review (kriterie 106). Forslag, ikke krav: en test, der starter `php -n` som underproces og kalder `hsg_extract_cask_number` på tabel T, eller et tjek på kildeteksten af `else`-grenen.
3. **Lukket siden første test:** 18-tegns-vinduet (tidligere bemærkning 1) og at filteret kun gælder #-mønstret (tidligere bemærkning 3) er nu dækket af række m-q i tabel T.
4. **Den gamle gren er stadig O(n) pr. #-match** (basens opførsel, 130-590 ms på en 32.767-tegn celle med mbstring). Det er uændret og bevidst (D1), og oprydningen kalder den kun på produktnavne. Den ondsindede celle går kun gennem den nye gren, som nu er hurtig.
5. **Docblocks.** `hsg_cask_number_scan`'s docblock har en ekstra linje om `$legacy` ud over D1's tekst, og `hsg_extract_cask_number` begynder med "Fadnummer (den nye udledning): den første kandidat…". Indholdet er det samme som i spec'en.
6. **mbstring uden fallback andre steder.** Som spec'en skriver (afsnit 9) bruger hurtigsøgningen `mb_strlen` og `mb_strtolower`. Det er uændret og uden for scope.
7. **Lokalt miljø:** `bash tests/run.sh` uden PHPRC fejler også i trin 2 (`ZipArchive` mangler uden php.ini), udover mbstring-vagten. Det er den lokale PHP's manglende standard-ini.

## Hvad er IKKE kørt

| Hvad | Hvorfor | Konsekvens |
|---|---|---|
| Kriterie 110 på **`lager 19-10-2026.xlsx`** | Filen findes ikke på maskinen | Kørt på de to 18-10-filer: ingen forskelle. **Brugeren skal køre sammenligningen på 19-10-filen** og skrive rækkerne i PR'en (eller "ingen"). |
| CI (GitHub Actions, PHP 8.2, MySQL 8.0) | Kan ikke køres lokalt | `tests.yml` indeholder `mbstring`, og kriterie 102 tjekker det, men en rigtig CI-kørsel er ikke set. Lokalt er MariaDB (ikke MySQL 8.0). |
| PHP 8.1 | Kun 8.5.8 installeret | Den nye kode bruger kun `ord`, `substr`, `preg_match` og `while` (intet nyere end 8.1), men det er ikke prøvet. Ydelsesmålingerne er fra 8.5.8, og en anden PCRE-version på webhotellet kan give andre absolutte tal. |
| Rigtig opgradering via `update.php` (ZIP) og FTP-varianten | Version er ikke hævet (D4), og det ville skrive i `uploads/` | Oprydningen er testet med databasetesten (kørsel 1-3, produkt A-K) og ved frisk installation (case 87). |
| Release-ZIP bygget og pakket ud | Versionen hæves først ved release | Negativtesten (trin 2) er grøn. |
| Rigtig telefon / mobilbredde | Ingen skærmændringer | - |
| Q-B1 (rydning af gemte batchnumre med ciffer) | Uden for scope (spec afsnit 9) | Kriterie 110 på 19-10-filen giver grundlaget, hvis det bliver aktuelt. |

## Oprydning og scripts

- **Ændret i repoet:** kun denne rapport. Ingen produktionskode, ingen tests, ingen spec er rørt. `git status` er ren, og der ligger ingen `config.php` i arbejdstræet (trin 4 slettede sin egen).
- **Databaser:** `hsg_test` står som frisk installation efter `run-all.sh`. `hsg_test_qb` (klon til mutanter og HTTP-test) er droppet.
- **Processer:** Mine `php -S` (8811, 8812, 8813) er stoppet, og en overset ydelsesmåling fra en afbrudt første kørsel er dræbt. To andre `php -S`-processer (8796 og 8798) tilhører en anden session og er ikke rørt.
- **Scripts** ligger i scratchpad under `tester/` (ikke i repoet): `lib.php`, `tableT.php`, `diff.php`, `diff2.php`, `crit.php`, `big.php`, `perf.php`, `perf2.php`, `mkmut.php`, `mkmut2.php`, `runmut.sh`, `runmut2.sh`, `kim_scan.php`, `kim_cmp.php`, `http2.sh`, `http3.sh`, `r17.php`. Rå output: `runall_full.txt` og `runall_full2.txt`, `diff2_mb.txt`, `diff2_nomb.txt`, `mutresult.txt`, `mutresult2.txt`, `mutlog_*.txt`, `perf_out.txt`, `kim_*.json`. Filerne "lager 18-10-2026.xlsx" og "Kopi af lager 18-10-2026 (003).xlsx" er ikke kopieret.

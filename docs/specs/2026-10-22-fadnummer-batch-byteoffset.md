# Opfølgning: batch-filteret i fadnummer-udledningen bruger byte-offset som tegn-offset

- **Dato:** 2026-10-22
- **Status:** Godkendt af brugeren 2026-10-22. Q-B1 besvaret: nej, som anbefalet.
- **Bygger på:** [`2026-10-22-fadnummer-kraever-ciffer.md`](2026-10-22-fadnummer-kraever-ciffer.md) (Q2, PR #177, åben og ikke merget). Spec'en løser punktet "Batch-filteret bruger et byte-offset …" i dens afsnit 9.
- **Branch:** `feature/fadnummer-batch-byteoffset`, lavet ud fra `feature/kims-upload-fadnummer-ciffer`.
- **Modul:** Ingen versionsændring. `products` forbliver `1.5.1` (D4).
- **Kode:** Linjenumrene passer på HEAD `e066862`.

---

## 0. Åbne spørgsmål

1. **Q-B1: Skal gemte batch-numre med ciffer ryddes?** Fejlen kan allerede have gemt et batch-, bottle- eller flaskenummer som fadnummer, fx `12` fra "Blåbær Batch #12". Oprydningen i 1.5.1 springer værdier med ciffer over (`core/product_enrichment.php:97`), så den rører dem ikke.
   **Anbefaling: nej, ikke i denne rettelse.**
   - Det kræver, at der står æ, ø, å, ’ eller en tankestreg før "Batch/Bottle/Flaske #" efterfulgt af mindst to tegn. Startlageret har ingen sådanne navne (kriterie 103).
   - Det ville udvide det godkendte Q1-A ("kun fadnumre uden ciffer").
   - Kriterie 110 viser, om Kims rigtige fil har sådanne rækker. Er der nogle, kan brugeren tage stilling bagefter.

   **Brugerens svar (2026-10-22): nej, som anbefalet.**

   Svarer brugeren ja, skal spec'en udvides før udviklingen. Oprydningen skal så også genudlede værdier med ciffer, hvis den gamle udledning giver præcis den gemte værdi, og den nye giver en anden.

---

## 1. Formål

`hsg_extract_cask_number` skal ignorere batch-, bottle- og flaskenumre, også når navnet har æ, ø, å, ’ eller en tankestreg før `#`. I dag kan "Blåbær Batch #12" blive til fadnummer 12 (med mbstring), og et rigtigt nummer kan blive filtreret fra. Det gælder også genudledningen i oprydningen 1.5.1. Den skal skrive det rigtige nummer og ikke et batch-nummer.

- **Admin:** Produktdata, forslag fra "Udfyld fra varetekst" og Kims uploadfil får ikke batch-numre som fadnummer.
- **Linkbrugere og kunder:** Lagerstatus, kataloget og PDF'en viser ikke "Fadnummer #12" for et batch-nummer, når værdien kommer fra udledningen.
- **Bagudkompatibelt:** Ingen skemaændring og ingen ny migration. Tekster uden multibyte-tegn før `#` giver nøjagtig samme resultat som før.

## 2. Brugerflow og skærme

Der kommer ingen nye skærme, knapper eller tekster. Effekten kan ses disse steder:
- previewet i Kims uploadfil (fx forsvinder "Fad #12" ved "Glen Test – Batch #12"),
- "Udfyld fra varetekst" og "Udfyld manglende data",
- Excel-import uden kolonnen Fadnummer,
- installationen,
- feltet `new` i audit-posten `product.cask_number_cleanup`.

Release-noten fra Q2 beder allerede om et nyt preview i Kims uploadfil efter opdateringen.

---

## 3. Fejlen

`hsg_cask_number_candidates` (`core/product_enrichment.php:17-36`) finder #-match med `PREG_OFFSET_CAPTURE`. Offsettet (`:27`) er en **byte**-position. Med mbstring bruges det som et antal **tegn** i `mb_substr($text,0,$offset,'UTF-8')` (`:28`).

Lad k være antallet af ekstra bytes før `#`. æ, ø, å og é giver +1 hver, og –, — og ’ giver +2 hver. Så tager `$before` k tegn for meget med, og halen (`:29`, de sidste 18 tegn) slutter k tegn **efter** `#`. Filteret (`:30`) ser derfor på en tekstbid, der ligger længere fremme.

**Regneeksempel med "Blåbær Batch #12":** Før `#` står 13 tegn, men de fylder 15 bytes. Offsettet er 15, så `$before` bliver "Blåbær Batch #1" på 15 tegn. Halen slutter på "#1", filteret ser ikke "Batch", og 12 bliver kandidat. Det rigtige `$before` er "Blåbær Batch ", og så bliver 12 filtreret fra.

- **(a) Falsk positiv:** Et batch-, bottle- eller flaskenummer bliver kandidat (eksemplet ovenfor).
- **(b) Falsk negativ:** Slutter det forskudte vindue lige på et senere "Batch", filtreres et rigtigt nummer fra (række h og i i kriterie 101).

**Uden mbstring** er `substr` (`:28`) byte-baseret, så `$before` er rigtig. Halen `substr($before,-18)` (`:29`) består dog af 18 **bytes** og kan skære et tegn over. Så er halen ugyldig UTF-8, `preg_match` med `/u` returnerer `false`, og filteret svigter også (række k). Den gamle udledning afhænger altså af, om mbstring er indlæst.

| Kalder | Tekst | Rammes ved |
|---|---|---|
| `hsg_product_parse_text` (`:147`). Bruges af Excel-import, backfill, installation, Kims uploadfil (`core/supplier_import.php:174`) og produktdata-assistenten | normaliseret: – og — er lavet om til - | æ, ø, å, ’ m.fl. før `#` |
| `core/supplier_import.php:175` | det rå navn | også – og — |
| Oprydningen, bevis (`:103`) | normaliseret | bevidst uændret (D1) |
| Oprydningen, genudledning (`:109`) | normaliseret | æ, ø, å, ’ m.fl. |

---

## 4. Beslutninger

### D1. Kun den nye udledning rettes. Den gamle bevares med fejlen

- **Ny intern funktion** `hsg_cask_number_scan(string $text, bool $legacy): array` i `core/product_enrichment.php`. Den indeholder den fælles løkke: de to mønstre, rækkefølgen, årstalsfilteret, trim og store bogstaver. `$legacy` har ingen standardværdi, så hver kalder skal vælge eksplicit.
  - **`$legacy = true`:** Linje `:27-30` flyttes **ordret**, også `function_exists('mb_substr')`-grenen.
  - **`$legacy = false`:**
    - `$before = substr($text,0,$offset)`. Resultatet er gyldig UTF-8, fordi PCRE med `/u` kun giver offsets på tegngrænser, og matchet starter med `#`, som er ASCII.
    - `$tail` er de sidste **højst 18 tegn** af `$before`. Den beregnes uden `mb_*`, fx med `preg_match('/.{0,18}\z/su',$before,$t)`. `substr($before,-18)` må ikke bruges (række k).
    - Samme filter-regex på `$tail` som i dag (`:30`), og kun for #-mønstret.
- `hsg_cask_number_candidates(string $text): array` returnerer `hsg_cask_number_scan($text, true)`. Signatur og adfærd er uændret.
- `hsg_extract_cask_number(string $text): ?string` returnerer den første værdi med ciffer fra `hsg_cask_number_scan($text, false)`. Signaturen er uændret.
- `hsg_cask_number_cleanup`, `hsg_product_parse_text` og `core/supplier_import.php` ændres **ikke**.

**Begrundelse:**
- Beviset skal svare på spørgsmålet "Ville den gamle kode have gemt præcis denne værdi?". Det kan kun den gamle kode med fejlen svare på.
- En rettet `candidates` ville flytte beviste rækker til "kept". I række h giver den gamle udledning `['XO']`, men en rettet giver `['12']`. Den kunne også bevise værdier, som den gamle kode aldrig har givet.
- Uden multibyte-tegn før et #-match er byte-offset og tegn-offset ens. Den nye udledning er derfor identisk med den gamle for ASCII-tekst. Det gælder også det 18 tegn lange vindue, som bevares af samme grund.
- En intern funktion er bedre end en valgfri parameter på `candidates`. Den offentlige signatur forbliver præcis den samme, og ingen kommer til at bruge den gamle udledning uden at vide det.

**Docblocks** (`:11-16` og `:38`), indhold:
- **`hsg_cask_number_candidates`:** "Den GAMLE udledning. Den bruges kun som bevis i oprydningen (`hsg_cask_number_cleanup`). [Mønstre, rækkefølge, trim og årstal som i dag.] Batch/bottle/flaske-filteret er bevidst det gamle, også med fejlen: Med mbstring bruges byte-offsettet som tegn-offset, så filteret ser for langt frem, når der står æ, ø, å eller – før #. Uden mbstring er det byte-baseret. Ret det ikke, for så passer beviset ikke længere med de værdier, som den gamle kode har gemt. Det første element er præcis det, udledningen gav før cifferreglen. Den nye udledning er `hsg_extract_cask_number`." Sætningen "Samme batch/bottle/flaske- og årstalsfilter som altid" udgår.
- **`hsg_extract_cask_number`:** "Den første kandidat med mindst ét ciffer. Batch/bottle/flaske-filteret ser på de sidste 18 tegn lige før # (rigtigt byte-offset, samme resultat med og uden mbstring). Ord som STRENGTH, FINISH og CRAFT er ikke et fadnummer."
- **`hsg_cask_number_scan`:** "Intern. Brug `hsg_cask_number_candidates` (den gamle udledning) eller `hsg_extract_cask_number` (den nye)."

### D2. Uden mbstring beholder den gamle udledning sin fallback-gren

**Beslutning:**
- Den gamle udledning beholder `function_exists('mb_substr')`-grenen bit for bit.
- Den nye udledning bruger ingen `mb_*`-funktioner og giver samme resultat med og uden mbstring.

**Begrundelse:**
- **Samme server:** Beviset skal gengive det, som den gamle kode gav **på den server**, hvor værdierne blev gemt. Installation, import, Kims uploadfil, backfill og assistent kører på webhotellet, og migrationen kører samme sted. Grenen vælger derfor samme vej som dengang.
- **Ingen efterligning:** Hvis den gamle udledning altid efterlignede mbstring, ville den på en server uden mbstring sammenligne med værdier, som serveren aldrig har gemt. Det kan give falske beviser. Et manuelt "XO" på "ÆØÅ ÆØÅ ÆØÅ #12 Batch #XO" ville fx blive ryddet, selv om serveren selv havde gemt 12.
- **mbstring er ikke et formelt krav:** `tools/build-release.php:209` (`required_extensions`) og `admin.php:19-21` nævner det ikke, og install.php tjekker det ikke. Hurtigsøgningen bruger dog `mb_strlen` og `mb_strtolower` uden fallback (`core/bootstrap.php:15`, `:22`). Produktion har derfor sandsynligvis mbstring. Beslutningen holder i begge tilfælde.
- **Restrisiko:** Har webhotellet skiftet mbstring-status mellem gemning og migration, kan beviset slå fejl for tekster som i kriterie 101. Det accepteres.

### D3. Konsekvens for oprydningen (1.5.1)

- **Hvilke rækker der ryddes eller beholdes, ændres ikke.** Beviset bruger den uændrede `hsg_cask_number_candidates`, og værdier med ciffer springes stadig over (`:97`).
- **Kun `new` (`:109`) ændres.** Det sker kun for tekster, hvor et #-match har multibyte-tegn foran, og hvor batch, bottle eller flaske står i det rigtige eller det forskudte vindue.

| Retning | Navn → gemt værdi | Bevis (gammel udledning) | `new` før rettelsen | `new` efter |
|---|---|---|---|---|
| (a) Et batch-nummer slap igennem som senere kandidat | `Cask Strength, Blåbær Batch #12` → `STRENGTH` | ja, `['STRENGTH','12']` | `'12'` (batch-nummeret) | `null` |
| (a) En batch-værdi uden ciffer var første kandidat | `Blåbær Batch #XO` → `XO` | ja, `['XO']` | `null` | `null` |
| (b) Et rigtigt nummer blev filtreret fra | `ÆØÅ ÆØÅ ÆØÅ #12 Batch #XO` → `XO` | ja, `['XO']` | `null` (feltet tømmes) | `'12'` |

### D4. Ingen versionshævning. Rettelsen skal i samme release som PR #177

- **Ingen versionshævning:** Der er ingen databaseændring og ingen ny migration. CLAUDE.md regel 2 kræver kun versionshævning ved databaseændringer, og modulversionen styrer kun, hvilke migrationer der køres (`core/modules.php:126`). `products` forbliver `1.5.1`, og `supplier_upload` forbliver `1.1.1`.
- **Samme release:** 1.5.1 er ikke udgivet. Rettelsen skal i **samme release som PR #177**, så oprydningen kører med den rettede genudledning første gang. Anbefalingen er en PR mod `feature/kims-upload-fadnummer-ciffer`, så rettelsen bliver en del af PR #177. Alternativt merges den til `main` lige efter #177 og før næste release bygges.
- **Hvis #177 udgives alene:** Så kører 1.5.1 med fejlen og giver de forkerte `new`-værdier fra tabellen i D3. Det kræver en ny vurdering, fx `products 1.5.2`, som ikke er en del af denne spec.
- **App-versionen** hæves ved release som altid.

### D5. Testene skal være deterministiske

- **Den nye udledning** er uafhængig af mbstring, så dens tests giver samme resultat i alle miljøer.
- **Den gamle udledning med multibyte-tegn** testes kun med mbstring, fordi det er produktionens gren.
  - Testen tjekker `function_exists('mb_substr')`, som er samme betingelse som koden.
  - Findes funktionen ikke, registrerer testen **én** tydelig FEJL: "mbstring er ikke indlæst: den gamle udledning med æ, ø, å og – (kriterie 102) kan kun testes med mbstring. Kør med en php.ini, der indlæser mbstring (PHPRC)."
  - Resten af testene kører, og exit-koden er ≠ 0.
  - Databasetesten (kriterie 107) gør det samme.
- **CI:** `mbstring` tilføjes eksplicit i `.github/workflows/tests.yml:27`. Lokalt køres testene med PHPRC som i dag.
- **Uden PHPRC** fejler testen med vilje. Så kan produktionens gren aldrig ende med ikke at blive testet, uden at nogen opdager det. Fallback-grenen er uændret kode og testes ikke separat.

---

## 5. Data

- Ingen nye tabeller, kolonner, indstillinger eller migrationer. Modulversionerne er uændrede (D4).
- Dataeffekten sker kun gennem udledningen: fremtidige udledninger og `new` i oprydningen 1.5.1 (D3).
- **Release-note:** Ny linje i `README.txt` under "Fadnummer kræver mindst ét ciffer (Produkter 1.5.1)" (`:321-322`): "- Numre efter Batch, Bottle og Flaske (fx "Blåbær Batch #12") læses heller ikke som fadnummer, når der står æ, ø, å eller en tankestreg tidligere i navnet."

## 6. Rettigheder

Rettighederne er uændrede. Der kommer ingen nye sider, ingen POST-handlinger og ingen nye capabilities. Direkte brugerlinks er fortsat read-only og ser kun de rettede data.

## 7. Påvirkede filer

| Fil | Ændring |
|---|---|
| `core/product_enrichment.php` | Ny intern `hsg_cask_number_scan`. `hsg_cask_number_candidates` og `hsg_extract_cask_number` (`:11-44`) bruger den som i D1. Docblocks som i D1. |
| `tests/product_enrichment_test.php` | Kriterie 101–106, mbstring-vagt (D5) og filhovedet nævner spec'en og kravet om mbstring. |
| `tests/cask_number_cleanup_db_test.php` | Produkterne J og K. Forventningerne i kørsel 1 og kørsel 3 udvides. mbstring-vagt (kriterie 107). |
| `.github/workflows/tests.yml` | `mbstring` i `extensions` (`:27`). |
| `README.txt` | Release-linjen fra afsnit 5. |
| `docs/specs/2026-10-22-fadnummer-kraever-ciffer.md` | I afsnit 9 får punktet om byte-offset tilføjelsen "Rettet i `2026-10-22-fadnummer-batch-byteoffset.md`". |

**Ikke ændret:** `core/supplier_import.php`, `modules/products/module.php`, `modules/products/migrations.php`, `products.php`, `migrations.php`, `install.php`, `import.php` og `tests/run.sh`.

---

## 8. Acceptkriterier

Nummereringen fortsætter efter Q2-spec'en, som slutter ved 100. Alle tekster er skrevet som UTF-8. – er U+2013, og ’ er U+2019. I PHP kan de evt. skrives som `"\u{2013}"` og `"\u{2019}"`, så de ikke forveksles med `-` og `'`.

Forventningerne er regnet i hånden tegn for tegn og byte for byte. **Udvikleren verificerer dem med PHP med mbstring, før testen skrives.** Ved afvigelser melder udvikleren tilbage i stedet for at tilpasse forventningen.

**Tabel T** (bruges af kriterie 101 og 102). Kolonnen "Før rettelsen" er resultatet på PR #177 med mbstring. Den er kun til orientering og testes ikke.

| | X | Gammel: `candidates(X)` med mbstring | Før rettelsen: `extract(X)` | Efter: `extract(X)` | Bemærkning |
|---|---|---|---|---|---|
| a | `Blåbær Batch #12` | `['12']` | `'12'` | `null` | retning (a), k = 2 |
| b | `Blabaer Batch #12` | `[]` | `null` | `null` | ASCII-kontrol |
| c | `Glen Test – Batch #12` | `['12']` | `'12'` | `null` | rå tankestreg, som `supplier_import.php:175` ser den, k = 2 |
| d | `Glen Test - Batch #12` | `[]` | `null` | `null` | samme tekst normaliseret |
| e | `Kim’s Batch #12` | `['12']` | `'12'` | `null` | ’ normaliseres ikke, k = 2 |
| f | `Ærø Whisky Bottle #25` | `['25']` | `'25'` | `null` | bottle |
| g | `Ærø Single Malt, flaske #12` | `['12']` | `'12'` | `null` | flaske |
| h | `ÆØÅ ÆØÅ ÆØÅ #12 Batch #XO` | `['XO']` | `null` | `'12'` | retning (b), k = 9 |
| i | `ÆØÅ ÆØÅ ÆØÅ #12 Batch #34` | `['34']` | `'34'` | `'12'` | begge retninger |
| j | `Cask Strength, Blåbær Batch #12` | `['STRENGTH','12']` | `'12'` | `null` | som produkt J i kriterie 107 |
| k | `Kløver Whisky Batch #12` | `['12']` | `'12'` | `null` | Halen skal være 18 tegn, ikke 18 bytes. `substr(…,-18)` skærer ø over, og så svigter filteret. Den gamle udledning giver `['12']` også uden mbstring |
| l | `Teaninich, 10 års, #707145, Single Malt (Oloroso Sherry Hogshead), 56,8%` | `['707145']` | `'707145'` | `'707145'` | æ, ø eller å før `#` uden batch: uændret (startlager) |

101. **Givet** `hsg_extract_cask_number(X)` for hver X i tabel T, **når** testen køres med eller uden mbstring, **så** er resultatet som i kolonnen "Efter".
102. **Givet** at `mb_substr` findes, **når** `hsg_cask_number_candidates(X)` kaldes for hver X i tabel T, **så** er resultatet præcis som i kolonnen "Gammel".
     - **Givet** at `mb_substr` ikke findes, **så** registrerer testen præcis én FEJL med beskeden fra D5, springer dette kriteries tjek over, kører resten og slutter med exit-kode ≠ 0.
     - **Givet** `.github/workflows/tests.yml`, **så** indeholder `extensions` `mbstring`.
103. **ASCII-tekster og startlageret er uændrede.**
     - **Givet** tabellerne i kriterie 78 og 79, **så** er de ikke ændret og stadig grønne.
     - **Givet** hvert navn N fra `hsg_cask_number_seed_names()`, både råt og efter `hsg_product_text_normalize`, **så** er `hsg_extract_cask_number(N)` lig den første værdi med ciffer i `hsg_cask_number_candidates(N)`, eller `null`, hvis der ingen er. Det gælder med og uden mbstring. Kriterie 87 (installationen) er derfor uændret.
104. **Givet** `hsg_product_parse_text(T)`, **så** gælder:
     - `Blåbær Batch #12, 46%`: `fields` og `confidence` har ingen nøgle `cask_number`.
     - `ÆØÅ ÆØÅ ÆØÅ #12 Batch #34`: `fields['cask_number'] === '12'`.
     - `Teaninich, 10 års, #707145, Single Malt (Oloroso Sherry Hogshead), 56,8%`: `fields['cask_number'] === '707145'`.
105. **Givet** `hsg_supplier_source_fields(pe_kim_row(['sku'=>…,'name'=>N]), pe_kim_map())`, **så** gælder:
     - N = `Glen Test – Batch #12` (rå tankestreg): Resultatet har ingen nøgle `cask_number`. Før rettelsen gav linje `:175` `'12'`.
     - N = `Blåbær Batch #12`: Resultatet har ingen nøgle `cask_number`.
     - N = `Glen Test – #100`: `cask_number === '100'` (uændret).
106. **Kode og signaturer:**
     - **Givet** `ReflectionFunction`, **så** har `hsg_cask_number_candidates` præcis én parameter `string $text` og returtypen `array`, og `hsg_extract_cask_number` har præcis én parameter `string $text` og returtypen `?string` (enhedstest).
     - **Givet** diff'en, **så** bruger den nye gren ingen `mb_*`-funktioner og ingen `function_exists('mb_…')`. Den gamle grens linjer er flyttet ordret (reviewer).
     - **Givet** `git diff origin/feature/kims-upload-fadnummer-ciffer...HEAD -- core/product_enrichment.php`, **så** er kun docblocks og funktionerne i `:11-44` ændret, og `hsg_cask_number_scan` er ny. `hsg_cask_number_cleanup` og `hsg_product_parse_text` er uændrede. Docblocks er som i D1.
107. **Oprydningen** (databasetest). Testdataene fra kriterie 83 udvides med:

     | | SKU | `name` | `cask_number` før |
     |---|---|---|---|
     | J | TSTCASK-11 | `TSTCASK Cask Strength, Blåbær Batch #12` | `STRENGTH` |
     | K | TSTCASK-12 | `TSTCASK ÆØÅ ÆØÅ ÆØÅ #12 Batch #XO` | `XO` |

     - **Når** kørsel 1 kører (`hsg_cask_number_cleanup($pdo, [])`), **så**
       - er J `NULL`, og K er `'12'`,
       - indeholder `cleared` A, B og I som før, plus J `{old:'STRENGTH', new:null, source:'name'}` og K `{old:'XO', new:'12', source:'name'}`,
       - er `kept` stadig præcis C, D, E og G,
       - opfylder audit-posten kriterie 84.
     - **Når** kørsel 2 og 3 kører, **så** er J og K uændrede og står ikke i `cleared` eller `kept`.
     - **Mutanter:** Bruger `extract` den gamle udledning, bliver J `'12'` og K `NULL`. Bruger `candidates` det rigtige offset, havner K i `kept`. Testen skal fejle i begge tilfælde.
     - **Givet** at `mb_substr` ikke findes, **så** registrerer testen én tydelig FEJL og indsætter ikke J og K. Resten kører som før.
108. **Givet** modulerne, **så**
     - er `products` `1.5.1` og `supplier_upload` `1.1.1`,
     - har `modules/products/migrations.php` kun nøglen `'1.5.1'` (kriterie 88 er uændret grønt),
     - er der ingen skemaændringer.

     **Givet** `git diff origin/feature/kims-upload-fadnummer-ciffer...HEAD --name-only`, **så** står kun filerne fra afsnit 7 på listen ud over spec'en og testrapporten.
109. **Når** `bash tests/run.sh` køres med mbstring, lokalt med PHPRC og i CI, **så** er alle trin grønne, inklusive kriterie 78–88 og 101–107. **Givet** `README.txt`, **så** står linjen fra afsnit 5 i afsnittet "Fadnummer kræver mindst ét ciffer (Produkter 1.5.1)".
110. **Manuel test**, hvis filen "lager 19-10-2026.xlsx" er tilgængelig (den må ikke lægges i repoet).
     - **Når** previewet laves på `feature/kims-upload-fadnummer-ciffer` og på denne branch med samme kolonnevalg og reservationsvalg, **så** er der kun forskelle i `source.cask_number` og det, der afledes af det (`changes` og forslag til varenummer). Forskellene findes kun på rækker, hvor navnet har æ, ø, å, ’ eller en tankestreg før "Batch #", "Bottle #" eller "Flaske #".
     - Testeren skriver rækkerne i PR'en, eller "ingen". Svaret giver grundlaget for Q-B1.
     - Er filen ikke tilgængelig, noteres det i testrapporten.

---

## 9. Uden for scope

- **Rydning af gemte fadnumre med ciffer**, som fejlen har læst ud af "Batch/Bottle/Flaske #NN" (Q-B1).
- **Fejlene i den gamle udledning:** Både mbstring-forskydningen og byte-halen uden mbstring bevares bevidst (D1, D2).
- **mbstring som krav** i `required_extensions` eller i tjekket i `admin.php`. Det er observeret, at hurtigsøgningen (`core/bootstrap.php:15`, `:22`) kræver mbstring uden fallback. Det bør undersøges separat.
- **Øvrige kendte svagheder i udledningen** fra Q2-spec'ens afsnit 9 er uændrede, fx "Release #12", "Barrel 1st Fill" og "Fadnummer 12".
- **Preview-formatet** i Kims uploadfil er uændret (Q3 i Q2-spec'en).

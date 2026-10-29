# Tillæg: Kims uploadfil – opfølgning på review af PR #181

- **Dato:** 2026-10-26
- **Status:** Godkendt af brugeren 2026-10-26 samlet med (a) i Å1 (afsnit 0). Punkt 4 er tilføjet på brugerens ønske: "Ja, læg rettelsen af det tomme preview ind i opfølgnings-sessionen som fjerde punkt."
- **Tillæg til:** [`2026-10-26-kims-upload-kun-lagerantal.md`](2026-10-26-kims-upload-kun-lagerantal.md) (PR #181, ikke merget). Alt, der ikke nævnes her, gælder uændret. Kriterierne fortsætter efter 166.
- **Branch:** `feature/kims-upload-opfoelgning-review`, lavet ud fra `feature/kims-upload-kun-lagerantal` inkl. e9a8f7c. Linjenumrene passer på e9a8f7c og er omtrentlige.
- **Formål (admin):**
  1. En negativ celle i Kims fil må ikke give forvalgt arkivering, uden at admin kan se det.
  2. Previews med kundenavne må ikke blive liggende og komme med i backup.
  3. En produktværdi, der er sendt i POST, bruges altid, som den er.
  4. Et preview må aldrig være tomt uden forklaring. Varenummer og produktnavn gættes igen, og et tomt preview siger hvorfor.
- **Rettigheder:** Uændrede. `supplier_upload.php` kræver `imports.manage`. Direkte brugerlinks har stadig ingen adgang.
- **Data:** Ingen databaseændringer og ingen migrations. Preview-JSON har stadig `format` 2. Nye nøgler:
  - `stock_file.negative` og `stock_file.total_negative` (punkt 1),
  - `col_key_restored` (punkt 4).

  Ældre previews uden nøglerne læses som tomme.

## 0. Åbent spørgsmål

**Å1: Hvad skal der ske med arkiveringen, når en lagercelle for varen er negativ (fx „-1“)?** Varen er ellers 0 i alt og har aktive reservationer.
- **(a) Arkiveringen tilbydes, men er ikke valgt på forhånd.** Reservationerne vises med tomme afkrydsningsfelter, som ved et usikkert match (kriterie 135). Admin kan selv afkrydse dem.
- **(b) Ingen arkivering for varen.** Reservationerne vises ikke, men en bemærkning siger, at de ikke arkiveres. Manuel arkivering fra `reservations.php` findes ikke (Q6). Admin skal derfor rette filen og uploade igen.
- **Anbefaling: (a).** „-1“ betyder sandsynligvis "udsolgt/oversolgt", så forslaget er stadig relevant, men et menneske skal tage stilling. Med (b) er der ingen anden vej til at arkivere.
- **Beslutning: (a)**, godkendt af brugeren 2026-10-26. Alt, der står i parentes om (b), og N3b gælder ikke.

Kriterierne er skrevet til (a). Hvor (b) giver noget andet, står det i parentes.

## 1. Negativ lagercelle (fund 1)

**Problem.** `hsg_supplier_stock_cell()` (`core/supplier_import.php` ca. l. 127-136) laver `-1` om til 0 med `max(0,…)`. Varen tæller så som "entydigt 0" (`hsg_supplier_stock_file_is_zero`, ca. l. 461), og det kan give forvalgt arkivering (ca. l. 704-729). `Fil:`-linjen viser 0, så admin kan ikke se „-1“.

**Ønsket adfærd.**
- **Hvad er negativt:** et gyldigt tal, hvis afrundede værdi er under 0: „-1“, „-0,5“, „-99999“. „-0“ og „-0,4“ er ikke negative. Værdien er stadig 0, og der skrives 0 som i dag.
- **`hsg_supplier_stock_cell`:** talceller får nøglen `'negative'=>bool`. Tomme og ugyldige celler er uændrede.
- **`hsg_supplier_location_cells`:** returnerer også `'negative'=>[stock_loc_x=>tekst]`. Nøglen står sidst og er altid med (`[]`, når ingen celle er negativ).
- **"Lagerantal i alt":** samme regel i begge tilstande.
  - I tilstand T er totalen selve lageret.
  - I tilstand L er totalen kontroltallet, og en negativ total gør heller ikke 0 entydigt.
  - `hsg_supplier_source_fields` lægger teksten i den interne nøgle `stock_file_total_negative`, som `stock_file_total_invalid`.
- **`hsg_supplier_merge_raw_items`:**
  - samler fra alle flettede rækker (første tekst pr. lokation vinder),
  - fjerner den interne nøgle fra `source`,
  - gemmer `stock_file['negative']` (`[stock_loc_x=>tekst]`) og `stock_file['total_negative']` (`?string`).

  Teksten er den trimmede celletekst, afkortet med `hsg_supplier_short_text`.
- **Arkivering:**
  - (a) `$safeMatch` er falsk, når `negative` eller `total_negative` ikke er tom. Lager, tillæg og advarsler beregnes som ved ikke-forvalgt arkivering.
  - (b) `hsg_supplier_stock_file_is_zero` returnerer falsk, så `archive` er `null`.
- **Rækkens forvalg:** `selected` følger den eksisterende regel. Der kommer intet nyt flag.
- **Bemærkninger** (amber, samme stil som "Ugyldig værdi"):
  - **N1:** `Bemærk: Negativ værdi „<tekst>“ for <lokation> behandles som 0.`
  - **N2:** `Bemærk: Negativ værdi „<tekst>“ for lagerantal i alt behandles som 0.`
  - **N3a** (kun ved (a), når `archive` ≠ `null`): `Bemærk: Arkiveringen er ikke valgt på forhånd, fordi Kims fil har en negativ værdi for varen.`
  - **N3b** (kun ved (b), når produktet har aktive reservationer på en lokation med tal fra filen): `Bemærk: Reservationerne arkiveres ikke, fordi Kims fil har en negativ værdi for varen.`

**Berørte filer:**
- `core/supplier_import.php`
- `docs/SUPPLIER-UPLOAD.md`, afsnit 3 og 2.2: "Negative tal bliver 0, får en bemærkning, og arkiveringen er ikke forvalgt".

**Tests:** 167-172 i `tests/supplier_import_test.php`. 173 i `tests/supplier_archive_qa_test.php`.

167. **Lagercellen.**
     - `hsg_supplier_stock_cell('-1')` giver `['state'=>'number','value'=>0,'text'=>'-1','negative'=>true]`.
     - „-0,5“ og „-99999“ giver `negative` sand og `value` 0.
     - „-0“, „-0,4“, „0“ og „41“ giver `negative` falsk.
     - Tomme og ugyldige celler giver præcis de samme arrays som i dag. Test 63, QA1 og QA2 er uændret grønne.
168. **Lokationscellerne.** `hsg_supplier_location_cells(kim_row(['R'=>'-1','S'=>'','T'=>0]), kim_map(false))` giver præcis `['values'=>['stock_loc_1'=>0,'stock_loc_2'=>0,'stock_loc_3'=>0],'blank'=>['stock_loc_2'],'invalid'=>[],'negative'=>['stock_loc_1'=>'-1']]`.
169. **Eksempel A med negativ lokation (`add`).** **Givet** rækken `kim_row(['sku'=>'50-1','name'=>'Spøgelse Whisky','dist'=>'Testdestilleri','R'=>0,'S'=>'','T'=>'-1','Q'=>0])` med `kim_map()`, `[spog()]` og `merge_opts()`, **så** gælder:
     - `archive` er `spog_archive()` med `default` falsk. (Ved (b) er `archive` `null`.)
     - `stock_changes` har ingen `stock_loc_3`, fordi 0 + 2 = 2.
     - `selected === (count(changes) > 0)`. Arkiveringen alene gør ikke rækken valgt.
     - `flags = []` og `stock_file['negative'] = ['stock_loc_3'=>'-1']`.
     - Bemærkningerne indeholder N1 for "Gert" og N3a. (Ved (b) N3b.)
170. **Samme med `none`** (`merge_opts(false)`).
     - Lagerændringerne er præcis `['stock_loc_3'=>['old'=>2,'new'=>0,'file'=>0]]`, uden `archived`.
     - Der er en bemærkning med "Gert får 0 fysisk efter import, men 2 er reserveret".
     - `archive.default` er falsk. (Ved (b) er `archive` `null`.)
171. **Negativ "Lagerantal i alt".**
     - **Tilstand L.** Rækken fra 169 med T=0 og Q='-1' giver:
       - `stock_file.total = 0` og `total_negative = '-1'`,
       - intet `stock_mismatch`-flag,
       - `archive.default` falsk (ved (b) `null`),
       - N2.
     - **Tilstand T.** **Givet** `kim_map(true,false)` og `merge_opts()`. Produktet er `spog()` med `stock_loc_1=2`, `reserved_loc_1=2` og de to reservationer på `location_id 1`, mens `stock_loc_3` og `reserved_loc_3` er 0. Rækken har Q='-1'. **Så** gælder:
       - `stock_file.locations = ['stock_loc_1'=>0]` og `total_negative = '-1'`,
       - `archive.default` falsk (ved (b) `null`),
       - N2.
     - I begge tilstande har hverken `source` eller `changes` nøglen `stock_file_total_negative`.
172. **Fletning og upåvirkede varer.**
     - To rækker med varenummer 50-1 (T='-1' og T=0) giver én vare med `stock_file['negative'] = ['stock_loc_3'=>'-1']` og `archive.default` falsk.
     - `spog_row()` uden negative celler giver præcis kriterie 133 og ingen N1-N3. Kriterie 132-140 er uændret grønne.
     - Kun ved (b): test 132 får to nye tilfælde, der begge giver falsk:
       - `['negative'=>['stock_loc_3'=>'-1']]+$zeros`
       - `['total_negative'=>'-1']+$zeros`
173. **Flow (ny Q10, HTTP, `add`, Kun lagerantal).** **Givet** TSTKIM-9701 med lager 2 på L3 og to reservationer r1 og r2 på L3, og en fil med L3 = „-1“ og 0 i de øvrige lokationer og totalen. **Så** gælder:
     - Previewet viser „Negativ værdi „-1“ for <L3-navn> behandles som 0.“ og N3a. Begge arkiveringsfelter er uden `checked`.
     - Anvend med rækken markeret og uden `archive_res[]`: r1 og r2 er `reserved`, og lageret er `{L3: 2}`.
     - Ny upload og Anvend med `archive_res=[r1]`: r1 er `archived`, r2 er `reserved`, og lageret er `{L3: 1}`.
     - (Ved (b): ingen arkiveringsfelter, N3b vises, og `archive_res=[r1]` arkiverer intet.)

## 2. Gamle previews med kundenavne (fund 2)

**Problem.** Previews i `storage/import-previews/<token>.json` (ca. l. 965) slettes kun ved "Anvend". De indeholder kundenavne og referencer. Et preview, der ikke anvendes, bliver liggende. Det gælder også ved "Annuller preview", som kun er et link. Fuld backup tager filerne med (`hsg_backup_excluded_relpath`, `core/backup.php` ca. l. 62-70).

**Ønsket adfærd.**
- **Ny funktion** `hsg_supplier_preview_cleanup(int $maxAgeSeconds = 86400, ?string $dir = null): int` i `core/supplier_import.php`, efter mønsteret i `hsg_update_cleanup_old_staging`. Den:
  - sletter kun almindelige filer, hvis navn matcher `^[a-f0-9]{40}\.json\z`, og hvis `filemtime` er ældre end grænsen,
  - går ikke ned i undermapper,
  - returnerer antallet af slettede filer,
  - kaster aldrig en undtagelse (fejl skrives med `error_log`).

  `$dir = null` betyder `hsg_supplier_preview_dir()`. Parameteren findes kun for testen.
- **`supplier_upload.php`:** `action=upload` kalder den først, i sin egen `try/catch`. En fejl stopper aldrig uploaden.
- **`core/backup.php`:** `'storage/import-previews/'` kommer på præfikslisten i `hsg_backup_excluded_relpath`.
- **`.gitignore`:** linjen `storage/import-previews/`. Kun kode røres, ikke mapperne.
- **Dokumentation:**
  - `docs/SUPPLIER-UPLOAD.md` ("Preview og opdatering"): "Et preview, der ikke anvendes, slettes automatisk efter 24 timer (ved næste upload) og kommer ikke med i backup."
  - `docs/BACKUP-RESTORE.md` l. 6: "undtagen `config.php`, gamle backups og ikke-anvendte previews fra Kims uploadfil".

174. **Oprydning** (enhedstest i `tests/supplier_import_test.php`, i en mappe under `sys_get_temp_dir()`). **Givet**:
     - `A=<40 hex>.json`, 25 timer gammel,
     - `B=<40 hex>.json`, 23 timer gammel,
     - `notes.json`, `<40 hex>.json.bak` og `<40 HEX med store bogstaver>.json`, alle 25 timer gamle,
     - en undermappe `<40 hex>.json/`.

     **Når** `hsg_supplier_preview_cleanup(86400, $dir)` kaldes, **så** returneres 1, og kun A er slettet. En mappe, der ikke findes, giver 0 uden undtagelse.
175. **Upload rydder op** (ny Q11 i `tests/supplier_archive_qa_test.php`). **Givet** to filer i previewmappen, som testen selv har lagt der: `<40 hex>.json` på 25 timer (`touch`) og `<40 hex>.json` på 1 time. **Når** der uploades, **så** gælder:
     - Den gamle fil er væk, og den nye findes.
     - Uploaden giver et preview som normalt.
     - Testen sletter selv sin nye fil bagefter.
176. **Backup** (enhedstest; `core/backup.php` kan `require`'es uden sideeffekter).
     - `hsg_backup_excluded_relpath` giver sand for `'storage/import-previews/abc.json'` og `'storage\import-previews\abc.json'`.
     - Den giver falsk for `'storage/data/x.key'`, `'uploads/a.jpg'` og `'storage/import-previews.txt'`.
177. **Git.** `.gitignore` indeholder `storage/import-previews/`, og `git check-ignore -q storage/import-previews/x.json` returnerer 0.

## 3. `product_<i>=0` falder tilbage til matchet (fund 3)

**Problem (aktuel kode).** Commit e9a8f7c rettede den tomme værdi: `''` springer nu altid rækken over (l. 185). Linje 186 er stadig `$pid=(int)($postedVal ?: ($item['match']['id']??0));`. `'0'` er falsk i PHP, så en sendt `'0'` (også `' 0 '` efter trim) falder tilbage til previewets match, og lager, produktdata og arkivering følger med. Dropdown'en sender aldrig `'0'`, så hullet kan kun nås med en håndlavet POST. Det skal lukkes alligevel.

**Ønsket adfærd.** Linje 186 ændres til `$pid=$postedRaw===null?(int)($item['match']['id']??0):(int)$postedVal;`.
- En sendt værdi bruges altid, som den er.
- `0` springer rækken over via l. 187 og tæller `rows_skipped` op i Kun lagerantal.
- Mangler feltet helt, bruges matchet som i dag.

**Berørt fil:** `supplier_upload.php`.

178. **Q8, nyt trin (Kun lagerantal).** **Givet** Q8's produkt og reservationer efter det eksisterende trin, **når** der uploades igen og anvendes med `product_<i>='0'` og `archive_res=[r1,r2]`, **så** gælder:
     - Lageret er `{L3: 2}`, og der er ingen lagerbevægelse.
     - r1 og r2 er `reserved`, og der er ingen nye `reservation.archive`.
     - Audit har `rows_skipped 1`, `products_updated 0` og `reservations_archived 0`.
     - Flashen indeholder "1 række uden match er sprunget over."

     **Kontrol:** En ny upload, hvor `product_<i>` udelades, og `archive_res=[r1,r2]`, arkiverer r1 og r2. Matchet bruges altså stadig, når feltet mangler.
179. **Q9, nyt trin (Produktdata og lager).** **Givet** Q9's produkt, **når** der anvendes med `product_<i>='0'`, **så** gælder:
     - Destilleriet er stadig NULL.
     - Lager og reservationer er uændrede.
     - Der er ikke oprettet et nyt produkt.
     - Audit har `products_updated 0` og `products_created 0`.

## 4. Tomt preview, når Varenummer og Produktnavn står til "Ignorer kolonne" (brugerens fjerde punkt)

**Hændelse.** På drift (v11.2.0) gav Kims "lager 19-10-2026.xlsx" et tomt preview, og "Anvend" gav "Vælg mindst én række, der skal opdateres." Det huskede kolonnevalg (`supplier_import_header_map`) havde `varenr`, `variant` og `lagerbeholdning` som "Ignorer kolonne". Uden varenummer, produktnavn og fadnummer springer `hsg_supplier_merge_raw_items` alle rækker over (ca. l. 512). Med "Gæt kolonner forfra" gav filen 291 rækker.

**Problem.**
- Et husket "Ignorer" bruges altid (`hsg_supplier_suggest_columns`, ca. l. 323-329).
- Står sku/name slet ikke i det huskede valg, forhindrer `$blocked` (ca. l. 330-332), at de gættes.
- Previewet siger ikke, hvorfor det er tomt.
- Valget gemmes igen ved Genberegn og Anvend, så fejlen bider sig fast.

**Ønsket adfærd** (både "Produktdata og lager" og "Kun lagerantal"):
- **4a Gæt.** I gennemløb 1 (præcise match) blokeres `sku` og `name` aldrig af `$blocked`. I gennemløb 2 (delvise match) er de blokeret som i dag, så fx „Kundenummer“ ikke bliver varenummer.
- **4b Redning af husket "Ignorer".**
  - **Hvornår:** Når gennemløb 1 og 2 er færdige, og ingen kolonne er valgt som `sku`, `name` eller `cask_number`.
  - **Hvordan:** Kolonnerne gennemgås fra venstre mod højre. En kolonne, der står til "Ignorer" (også et husket valg), får feltet, hvis overskriften er et præcist alias for `sku` eller `name`, og feltet ikke allerede er brugt.
  - **Ellers respekteres "Ignorer":** Er en anden nøglekolonne valgt, bruges det huskede "Ignorer" (test 6 er uændret).
  - **Kun ved upload:** Redningen sker i `hsg_supplier_suggest_columns`. Et manuelt valg i Kolonnemapping (`hsg_supplier_recalculate_preview`) respekteres altid.
- **Visning af redningen.** Ny funktion `hsg_supplier_restored_key_columns(array $headers, array $savedHeaderMap, array $colMap): array`. Den returnerer `[kolonneindeks => 'sku'|'name']` for kolonner, der var husket som `''` og nu er valgt.
  - Resultatet gemmes i previewet som `col_key_restored` efter samme mønster som `col_rejected`:
    - sat ved upload,
    - bevaret ved `reservation_mode` og `import_scope` (`$keep`),
    - nulstillet ved `remap` og `reguess`.
  - Panelet viser T3 ved hver reddet kolonne, i samme stil som beløbsadvarslen (l. 416).
  - Næste "Genberegn preview med tilpasset kolonnemapping" eller "Anvend valgte ændringer" gemmer det rettede valg, så fejlen ikke bider sig fast. (Knapperne "Genberegn preview" ved "Hvad skal opdateres?" og "Reservationer" gemmer ikke kolonnevalget.)
- **4c Ingen stille tomme previews.**
  - Har `mapping` hverken `sku`, `name` eller `cask_number`, viser preview-kortet T1 (amber, som l. 373).
  - Ellers vises T2, hvis `items` er tom.
  - Kolonnemapping-panelet åbner automatisk ved T1, T2 og ved en ikke-tom `col_key_restored`. I dag åbner det kun ved `remap`, 0 genkendte felter eller afviste valg.
  - Ved 0 rækker vises tabellen og "Anvend valgte ændringer" ikke. I stedet vises et kort med "Der er ingen rækker at anvende." og knappen "Annuller preview".
  - **Server:** `action=apply` på et preview med 0 rækker kaster "Previewet har ingen rækker, der kan anvendes." før alt andet. Intet gemmes, heller ikke kolonnevalget.
- **Tekster:**
  - **T1:** `Bemærk: Varenummer og produktnavn er ikke valgt, så ingen rækker kan matches. Tryk "Gæt kolonner forfra", eller vælg kolonnerne under Kolonnemapping.`
  - **T2:** `Bemærk: Filen gav ingen rækker. Ingen rækker har et varenummer, et produktnavn eller et fadnummer i de valgte kolonner. Tjek kolonnerne under Kolonnemapping, eller tryk "Gæt kolonner forfra".`
  - **T3:** `⚠ Husket valg "Ignorer kolonne" er ikke brugt: uden varenummer og produktnavn kan ingen rækker matches.`
- **Dokumentation (`docs/SUPPLIER-UPLOAD.md`):**
  - "Kolonnevalg huskes" (l. 48-49) får undtagelserne 4a og 4b.
  - "Preview og opdatering" får: et tomt preview siger hvorfor, og "Anvend" vises ikke.

**Berørte filer:**
- `core/supplier_import.php`: `hsg_supplier_suggest_columns`, ny `hsg_supplier_restored_key_columns`, `hsg_supplier_prepare_preview` og `hsg_supplier_recalculate_preview`.
- `supplier_upload.php`: visningen, `$keep` og vagten i Anvend.
- `docs/SUPPLIER-UPLOAD.md`.

**Tests:** 180-182 i `tests/supplier_import_test.php` med `kim_headers()`. 183-185 i en ny Q12 i `tests/supplier_archive_qa_test.php`.

180. **Gæt uden blokering (4a).** Med `$saved=['destilleri'=>'distillery','christiansfeld'=>'stock_loc_1']` gælder:
     - `map[4]='sku'`, `map[5]='name'`, `map[7]='distillery'` og `map[17]='stock_loc_1'`.
     - `hsg_supplier_restored_key_columns(kim_headers(),$saved,$map) = []`.
     - `hsg_supplier_suggest_columns(['Kundenummer','Destilleri'],['destilleri'=>'distillery'])` giver `[0=>'',1=>'distillery']`.
181. **Redning (4b, hændelsen).** Med `$saved=['varenr'=>'','variant'=>'','lagerbeholdning'=>'','destilleri'=>'distillery']` gælder:
     - `map[4]='sku'`, `map[5]='name'` og `map[7]='distillery'`.
     - `map[16]=''`. Lagerbeholdning forbliver ignoreret.
     - Restored er `[4=>'sku',5=>'name']`.
182. **Ingen redning, når en anden nøglekolonne er valgt.**
     - Test 6 er uændret grøn (`map[4]=''`).
     - `hsg_supplier_suggest_columns(['Varenr.','Variant:','Fadnr'],['varenr'=>'','variant'=>'','fadnr'=>'cask_number'])` giver `[0=>'',1=>'',2=>'cask_number']`, og restored er `[]`.
     - Test 1 og 8 er uændret grønne.
183. **Flow: hændelsen ("Produktdata og lager").**
     - **Givet**:
       - TSTKIM-9801 med lager 1 på L3,
       - det huskede valg `varenr`, `variant` og `lagerbeholdning` = `''`, `destilleri` = `distillery` og de tre lokationer. Nøglerne er de normaliserede overskrifter.
     - **Når** en fil med TSTKIM-9801 uploades direkte (uden `remap`), **så** gælder:
       - `col_mapping` har `sku` på "Varenr." og `name` på "Variant:".
       - `col_key_restored` har de to kolonner.
       - Previewet har én vare med match på TSTKIM-9801.
       - Siden har `<details class="card" open>`, T3 og "Anvend valgte ændringer" og hverken T1 eller T2.
     - **Efter** `reservation_mode=add` er `col_key_restored` uændret.
     - **Efter** `remap` med samme `col_mapping` gælder:
       - `col_key_restored = []`.
       - Det gemte valg har `varenr → sku` og `variant → name`.
184. **Flow: tomt preview.**
     - **K1.** `remap`, hvor "Varenr." og "Variant:" er `''`, giver:
       - tom `items` og `col_key_restored = []`,
       - T1 og "Der er ingen rækker at anvende." på siden,
       - åbent panel og ingen "Anvend valgte ændringer".

       Det samme gælder efter `import_scope=stock_only`.
     - **K2.** Upload med `remap` til `lMap` af en fil, hvor "Varenr." og "Variant:" er tomme i alle rækker, giver T2 (ikke T1) og ingen "Anvend valgte ændringer".
185. **Vagten i Anvend.** **Givet** K1-previewet, **når** der postes `apply` med `apply_rows=[0]`, **så** gælder:
     - Flashen er "Kunne ikke anvende ændringer: Previewet har ingen rækker, der kan anvendes."
     - Der er ingen ny række i `hsg_supplier_import_runs` og ingen ny `supplier_import.apply`.
     - `supplier_import_header_map` er uændret.

## 5. KAN-punkter (laves, hvis de er trivielle)

186. **KAN-1 `reservations.php` l. 104-105.** `$q` og `$showStatus` læses kun, hvis værdien er en streng: `is_string($_GET['status']??null)?trim($_GET['status']):''`.
     - `reservations.php?status%5B%5D=archived&q%5B%5D=x` svarer 200 uden "Warning:" og viser fanen "Alle reservationer" som aktiv (rettet efter test: fanen "Aktive" findes ikke).
     - Adressen kommer på røgtestens `PAGES` i `tests/run.sh`.
187. **KAN-2 Dobbelte rækker.** `$selected` dedupliceres (`array_values(array_unique(…))`, l. 151).
     - `apply_rows=[i,i]` med `archive_res=[r]` arkiverer r én gang. Audit har `reservations_archived 1`, og der er ingen warning-flash "blev ikke arkiveret".
     - En række uden match i Kun lagerantal giver `rows_skipped 1`.
     - Testen er et trin i `tests/supplier_archive_qa_test.php`.
188. **KAN-3 Hook efter commit.** Hvert `hsg_do_action('reservation.archived',…)` (l. 246-248) får sin egen `try/catch (Throwable)` med `error_log`. En fejlende hook vælter så ikke en gemt import, og success-flashen vises. Ingen modul lytter i dag, så punktet kontrolleres ved review.

## 6. Versioner, ændrede tjek og testpakke

189. **Bevidst ændrede tjek.** Andre tjek ændres ikke.
     - **`tests/supplier_import_test.php`, test 47 og 65:** De forventede arrays fra `hsg_supplier_location_cells` får `'negative'=>[]` sidst. Tilfældet `'-2'` i test 47 får `'negative'=>['stock_loc_1'=>'-2']`.
     - **`tests/product_enrichment_test.php:141` og `tests/supplier_import_db_test.php:403`:** De forventer nu `supplier_upload` `1.2.1`.
     - **Punkt 4:** Forventes ikke at ændre tjek.
       - Flowtest 5/30 i `tests/supplier_upload_flow_test.php` uploader med et husket valg uden `sku`, så "Varenr." gættes nu som varenummer ved upload (4a).
       - Trinnet tjekker ikke rækkerne. Fejler et tjek alligevel, tilpasses kun dét, og testeren skriver det i rapporten.
190. **Versioner.**
     - `supplier_upload` hæves fra `1.2.0` til `1.2.1` i `modules/supplier_upload/module.php`. Det er ét patch-bump for hele opfølgningen, uden `migrations.php`, som i tillæggene 2026-10-20 og 2026-10-22.
     - `reservations` forbliver `1.3.0`. KAN-1 hærder kun input.
     - App-versionen hæves ved release.
191. **Testpakken.** `bash tests/run.sh` er grøn.

## 7. Påvirkede filer

- **Kode:**
  - `core/supplier_import.php`
  - `supplier_upload.php`
  - `core/backup.php`
  - `.gitignore`
  - `modules/supplier_upload/module.php`
  - `reservations.php` (KAN-1)
- **Dokumentation:** `docs/SUPPLIER-UPLOAD.md` og `docs/BACKUP-RESTORE.md`.
- **Tests:**
  - `tests/supplier_import_test.php`
  - `tests/supplier_archive_qa_test.php`
  - `tests/product_enrichment_test.php`
  - `tests/supplier_import_db_test.php`
  - `tests/run.sh` (KAN-1)
  - `tests/supplier_upload_flow_test.php` (kun hvis 5/30 påvirkes, se 189)

## 8. Uden for scope

- **`Fil:`-linjen** viser stadig 0 for en negativ celle. Bemærkningen viser den rå værdi.
- **Intet nyt flag** og intet fravalg af rækken på grund af en negativ værdi.
- **"Annuller preview"** sletter ikke previewet med det samme. 24-timers-oprydningen tager det, og der kommer ingen cron.
- **`tools/build-release.php`** ændres ikke. Previews er ikke git-sporede og kommer ikke i ZIP'en.
- **`product_<i>='12abc'`** læses stadig som 12. Det er eksisterende adfærd.
- **Kun varenummer og produktnavn reddes.** Andre kolonner, der er husket som "Ignorer" (fx "Lagerbeholdning"), og fadnummer reddes ikke.
- **Delvise match** for varenummer og produktnavn følger stadig `$blocked`.
- **Genberegn** gemmer stadig et manuelt valg uden nøglekolonner. Næste upload redder det.
- **Valg af ark** ændres ikke.
- **Ingen databaseændringer.**

## 9. Tilføjet efter review

Tilføjet efter reviewet (fund O4 i testrapporten) og godkendt af brugeren 2026-10-28.

192. **Oversigtslinjen "Arkiveres: …" (O4)** i `supplier_upload.php`.
     - Er mindst én reservation forvalgt, er linjen som før, inkl. tilføjelsen om reservationer på varer, der kræver vurdering.
     - Er ingen forvalgt, men nogle tilbudt (fx ved en negativ værdi eller et usikkert match), står der: `Ingen reservationer er valgt til arkivering. N reservationer (N stk.) på varer, der kræver din vurdering, er ikke valgt. Du kan vælge dem på de enkelte varer.`
     - Sætningen "Arkiveres: 0 reservationer (0 stk.) på 0 varer …" vises aldrig.
     - **Test:** Q10 (negativ værdi) i `tests/supplier_archive_qa_test.php` har den nye sætning og ikke "Arkiveres: 0 reservationer". Q8 (forvalgt) har stadig "Arkiveres: 2 reservationer (2 stk.) på 1 vare …".

193. **Panelteksten i Kolonnemapping** er præciseret efter review (O5 og knapnavnene) og godkendt af brugeren 2026-10-29. Panelet siger nu, at et husket "Ignorer kolonne" kun ikke bruges på varenummer eller produktnavn, når ingen kolonne ellers er valgt som varenummer, produktnavn eller fadnummer (4b). Det siger også, at kun "Genberegn preview med tilpasset kolonnemapping" og "Anvend valgte ændringer" gemmer valget. **Test:** Q23 i `tests/supplier_archive_qa_test.php`.

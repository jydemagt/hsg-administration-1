# Tillæg: Kims uploadfil – "Lagerantal i alt" skal være ét tal (Q1) og strammere lagerceller

- **Dato:** 2026-10-22
- **Status:** Godkendt af brugeren 2026-10-22. Q1: 5 cifre. Q2: forvælg efter de normale regler, intet nyt flag. Q3: ja, `53 stk.`, `-` og `?` er ugyldige.
- **Bygger på:**
  - Hovedspec [`2026-10-19-kims-upload-lagerfejl.md`](2026-10-19-kims-upload-lagerfejl.md)
  - Tillæg [`2026-10-20-kims-upload-review-rettelser.md`](2026-10-20-kims-upload-review-rettelser.md) (R3 og Q1) og testrapporten til det (bemærkning 6)
- **Ændrer kun:**
  - talreglen i tillæggets R3
  - hovedspec 4.2 ("Lagerantal i alt" i tilstand L og T)
  - hovedspec 4.3 (cellerne "Tom" og "Tekst")
  - bemærkningstabellen i 3.4
  - `stock_file` i afsnit 5

  Alt andet gælder uændret.
- **Branch:** `feature/kims-upload-lagertal-q1`. Den bygger på `feature/kims-upload-review-rettelser`, som ikke er merget endnu.
- **Modul:** `supplier_upload` ("Kims uploadfil"), capability `imports.manage`
- **Kode:** `core/supplier_import.php` og `supplier_upload.php`. Linjenumrene passer på `df886b4`.

---

## 0. Åbne spørgsmål

1. **Q1 – Loftet:** Er højst **5 cifre** før decimaltegnet (op til 99.999) i orden? Alternativet er 4 cifre (9.999). **Anbefaling:** 5 cifre (begrundelse i 3.1).
2. **Q2 – Ugyldig total i tilstand L:** Skal rækken forvælges efter de normale regler, uden nyt flag? Spec'en antager ja. Alternativet er et nyt flag, der fravælger rækken, fordi lokationstallene ikke kan kontrolleres.
   **Anbefaling:** ja, som spec'en.
   - Det er sådan, en ugyldig lokationscelle behandles i dag.
   - En række uden kontroltal er også forvalgt, når kolonnen ikke er valgt.
   - Bemærkningen vises i amber.
3. **Q3 – Tekst og enheder i "Lagerantal i alt":** Må `53 stk.`, `-` og `?` blive ugyldige og give en bemærkning? I dag bliver `53 stk.` til 53, og `-`/`?` ignoreres uden bemærkning. **Anbefaling:** ja.
   - Det er samme regel som for lokationscellerne efter R3.
   - Excel gemmer tal uden enhed, så Kims fil rammes kun, hvis kolonne Q indeholder tekst.

---

## 1. Formål

**Admin**, der uploader Kims fil, skal kunne stole på, at et lagertal i previewet er præcis det tal, der stod i cellen. Rettelserne fjerner fem fejl:

| # | Fejl i dag | Sted |
|---|---|---|
| P1 | "Lagerantal i alt" læses med `hsg_supplier_parse_decimal`, som fjerner alt andet end cifre, komma, punktum og minus.<br>• I tilstand T skrives `3 + 2` som **32** på Christiansfeld.<br>• I tilstand L giver det en falsk kontrolafvigelse.<br>Det er Q1 fra tillæg 2026-10-20. | `core/supplier_import.php:160-165` |
| P2 | Ingen loft over antal cifre.<br>• `9223372036854775808` løber over til et negativt tal og slipper uden om reglen "negative tal bliver 0".<br>• En EAN (`5010494560018`) tæller som lagertal.<br>• Tal over 2.147.483.647 rammer `lager_stock.quantity INT`. | `:130-135`, `:163-164` |
| P3 | En celle med kun hårde mellemrum (U+00A0/U+202F) er *ugyldig* i en lokationscelle. Det skyldes, at tomhed tjekkes med `trim()`, før NBSP fjernes. `docs/SUPPLIER-UPLOAD.md:101` lover, at hårde mellemrum ignoreres. | `:126-128` |
| P4 | Den rå tekst kommer med i fuld længde i bemærkningen og to gange i preview-JSON. I testen gav 100.000 tegn 200 KB. | `:134`, `:644` |
| P5 | Værdien afrundes to gange: først til 2 decimaler og så til et helt tal. Derfor bliver `0.4999` til 1. | `:132`, `:164` |

**Lagermedarbejdere og linkbrugere** berøres kun indirekte: forkerte tal når ikke frem til Lagerstatus.

**Bagudkompatibelt:** Kims nuværende fil giver samme resultat som før. Der må kun være forskelle i rækker med en af disse celletyper:
- tekst eller enhed i "Lagerantal i alt"
- en celle med kun hårde mellemrum
- mere end 5 cifre før decimaltegnet
- decimaler lige under ,5
- ugyldig tekst på over 40 tegn (her ændrer kun bemærkningen sig)

## 2. Brugerflow og skærme

Flowet er uændret. Der er ingen nye skærme, knapper eller CSS-klasser, og mobilvisningen er uændret. Brugeren ser kun dette:

- **Ny bemærkning ved ugyldig total.** Den vises i amber, fordi den starter med "Bemærk" (teksten står i 3.6).
- **"Fil:"-linjen viser en ugyldig total**, på samme måde som en ugyldig lokation vises i dag.
- **Lange ugyldige tekster afkortes** til 40 tegn + "…".
- **En lokationscelle med kun hårde mellemrum vises som "(tom)"** og ikke som ugyldig.

---

## 3. Beslutninger

### 3.1 Én regel for en lagercelle (P1, P2, P3, P5)

Der kommer en ny ren funktion: `hsg_supplier_stock_cell(mixed $v): array`. Den returnerer `['state' => 'blank'|'number'|'invalid', 'value' => ?int, 'text' => string]`.

Funktionen bruges af to funktioner:
- `hsg_supplier_location_cells`, til hver valgt lokationscelle
- `hsg_supplier_source_fields`, til "Lagerantal i alt"

Cellen vurderes i fem trin:

1. **Trim.** Mellemrum i begyndelsen og slutningen fjernes med det eksisterende regex `/^[\s\x{00A0}\x{202F}]+|[\s\x{00A0}\x{202F}]+$/u`. Det fjerner også NBSP, smalt NBSP og andre Unicode-mellemrum.
   - Hvis `preg_replace` fejler (ugyldig UTF-8), er cellen **ugyldig**, aldrig tom.
   - `text` er da `trim((string)$v)`, og teksten afkortes ikke.
2. **Tom (P3):** Er der intet tilbage efter trimningen, er cellen tom (`value = null`, `text = ''`).
   - Tjekket sker **efter** trimningen. En celle med kun hårde mellemrum er derfor tom, også når de er blandet med almindelige mellemrum eller tabulatorer.
   - I tilstand L gælder hovedspec 4.3: cellen tæller som 0, hvis en anden lokationscelle i rækken har et tal.
3. **Tal (R3 + P2):** Den trimmede tekst er et tal, når to betingelser er opfyldt:
   - Den passer på `/^-?[0-9]{1,5}(?:[.,]([0-9]+))?$/`. Der bruges `[0-9]` og ikke `\d`, så kun cifrene 0–9 tæller.
   - Der står **ikke præcis tre cifre** efter separatoren (R3).

   Der må højst være **5 cifre før decimaltegnet**. Alle cifre tæller med, også foranstillede nuller. Decimaldelen må være så lang, den vil.
4. **Værdi (P5):** `max(0, (int)round((float)str_replace(',', '.', $t)))`.
   - Værdien afrundes kun én gang med PHP's `round` (,5 rundes væk fra 0), og negative tal bliver 0.
   - Der er højst én separator, så den er altid decimaltegnet.
   - `hsg_supplier_parse_decimal` bruges ikke længere til lagerceller og ændres ikke, fordi den bruges til priser, ABV og flaskestørrelse.
5. **Ellers er cellen ugyldig:** `value = null` og `text = hsg_supplier_short_text($t)` (se 3.4).

**Begrundelse for loftet på 5 cifre (Q1):**
- **Det er rigeligt.** Kims største lokation har under 1.000 flasker, og hele filen har 982 stk. Loftet på 99.999 giver over 100 gange luft, også til andre leverandørfiler.
- **Det afviser de forkerte tal.** EAN-8/13/14 (8–14 cifre), lange varenumre uden bindestreg og overløbstal (19–20 cifre) bliver ugyldige.
- **Det er sikkert.** 99.999 ligger langt under `lager_stock.quantity INT` (2.147.483.647), også efter fletning af mange rækker og reservationstillæg. `(int)` kan aldrig løbe over, og der kommer ingen PHP-advarsel.
- Med 4 cifre ville HSG også klare sig, men 5 cifre åbner ikke for EAN.

### 3.2 "Lagerantal i alt" i hver tilstand (P1 / Q1)

| Total-celle | Tilstand L (kontroltal) | Tilstand T med fallback-lokation | Tilstand T uden fallback (ældre vej) |
|---|---|---|---|
| tom | Intet kontroltal (uændret) | Lageret ændres ikke (uændret) | Intet (uændret) |
| tal N | Kontroltal N (uændret) | `stock_loc_<første>` = N og `stock_file_total` = N (uændret) | `stock_quantity` = N (uændret) |
| **ugyldig** | **Ingen kontrol:**<br>• intet `stock_file_total`<br>• ingen afvigelsesbemærkning og intet `stock_mismatch`<br>• lokationerne skrives som normalt<br>• bemærkning L | **Lageret ændres ikke for rækken:**<br>• ingen `stock_loc_*`<br>• intet `stock_file_total`<br>• bemærkning T | **Lageret ændres ikke:**<br>• intet `stock_quantity`<br>• bemærkning T |

**Teknik:**
- Er "Lagerantal i alt" valgt, og er cellen ugyldig, lægger `hsg_supplier_source_fields` den afkortede tekst i den **interne** nøgle `stock_file_total_invalid`.
- `hsg_supplier_merge_raw_items` fjerner nøglen fra `source` igen og lægger teksten i `stock_file.total_invalid`.
- Nøglen når derfor aldrig `changes`, `hsg_supplier_apply_product` eller `hsg_supplier_create_product`. Funktionerne læser i forvejen kun kendte felter.

**Forvalg og flag:** Der kommer intet nyt flag (se Q2). Rækken forvælges efter de normale regler i hovedspec 4.6, ligesom ved en ugyldig lokationscelle (R3), der også kun giver en bemærkning. Begrundelse:
- I tilstand T skrives der intet lager.
- I tilstand L er lokationstallene selv gyldige.

Konsekvenser:
- **Tilstand T:** Rækken er kun forvalgt, hvis den har andre ændringer, fx en pris.
- **Nyt produkt i tilstand T:** En vare uden match og med ugyldig total har lager 0 og er ikke forvalgt, som ved en tom total i dag.
- **Tilstand L, i dag:** `3 + 2` giver kontroltallet 32, en falsk afvigelse, og rækken er ikke forvalgt.
- **Tilstand L, efter rettelsen:** Der er intet kontroltal, og rækken forvælges efter de normale regler.

### 3.3 Fletning

Har én af de rækker, der flettes, en ugyldig total, er **gruppens total ugyldig**. Det gælder både for bogstav-varenumre (trin 1) og for enheder, der matcher samme HSG-produkt (trin 2).

`hsg_supplier_merge_raw_items` fjerner derfor disse nøgler fra `source`:
- `stock_file_total`
- `stock_quantity`
- i tilstand T: alle `stock_loc_*`, da de i tilstand T kun kommer fra totalen

Der bliver altså hverken skrevet en delsum eller brugt en delsum som kontroltal. Det er samme princip som for ugyldige lokationer i en flettet gruppe (`:621`). I tilstand L beholdes lokationerne, fordi de følger cellereglen for hver række.

`hsg_supplier_merge_source` skal ikke ændres. `stock_file_total_invalid` er ikke et lagerfelt, så den første ikke-tomme værdi bevares (den primære række først), som for andre tekstfelter. Kun funktionens kommentar opdateres.

### 3.4 Afkortning af rå tekst (P4)

Der kommer en ny ren funktion: `hsg_supplier_short_text(string $s, int $max = 40): string`.

- **Metode:** `preg_match('/^.{0,40}/us', $s, $m)`. mbstring findes ikke lokalt og bruges derfor ikke.
- **Resultat:** Har teksten mere end 40 tegn, returneres `$m[0].'…'` (U+2026). Ellers returneres teksten uændret.
- **Tegn tælles, ikke bytes.** Et tegn er et Unicode-tegn, så æ, ø og å klippes ikke over.
- **Ugyldig UTF-8:** Fejler `preg_match`, returneres teksten uændret.
- **Bruges til:** `text` for ugyldige celler. Dermed afkortes også `hsg_supplier_location_cells()['invalid']`, `stock_file.invalid`, `stock_file.total_invalid`, bemærkningerne og "Fil:"-linjen. Andre bemærkninger afkortes ikke.

### 3.5 Eksempeltabel

Pilene viser "i dag → efter rettelsen". "ugyldig" i de to total-kolonner betyder:
- **Tilstand L:** intet kontroltal og bemærkning L.
- **Tilstand T:** ingen lagerændring og bemærkning T.

| Celle | Lokationscelle (L) | Lagerantal i alt, L (kontroltal) | Lagerantal i alt, T (skrives på Christiansfeld) |
|---|---|---|---|
| `41` | 41 → 41 | 41 → 41 | 41 → 41 |
| `3 + 2` | ugyldig → ugyldig | 32, falsk afvigelse → **ugyldig** | **32** → **ugyldig** |
| `53 stk.` | ugyldig → ugyldig | 53 → **ugyldig** | 53 → **ugyldig** |
| `?` / `-` | ugyldig → ugyldig | intet kontroltal, ingen bemærkning → **ugyldig** | ingen ændring, ingen bemærkning → **ugyldig** |
| `1.234` / `1,234` | ugyldig → ugyldig | 1 / 1234 → **ugyldig** | 1 / 1234 → **ugyldig** |
| `99999` | 99999 → 99999 | 99999 → 99999 | 99999 → 99999 |
| `100000` / `000041` | 100000 / 41 → **ugyldig** | 100000 / 41 → **ugyldig** | 100000 / 41 → **ugyldig** |
| `5010494560018` (EAN) | 5010494560018 → **ugyldig** | 5010494560018 → **ugyldig** | 5010494560018 → **ugyldig** |
| `9223372036854775808` | overløb til negativt tal → **ugyldig** | overløb → **ugyldig** | overløb → **ugyldig** |
| `0.4999` | 1 → **0** | 1 → **0** | 1 → **0** |
| `2,4999` | 3 → **2** | 24999 → **2** | 24999 → **2** |
| `0.5` / `2,5` | 1 / 3 → 1 / 3 | 1 / 3 → 1 / 3 | 1 / 3 → 1 / 3 |
| `40.999999999999993` | 41 → 41 | 41 → 41 | 41 → 41 |
| kun NBSP, evt. med mellemrum | ugyldig → **tom** (0, hvis en anden lokation har et tal) | intet kontroltal → uændret | ingen ændring → uændret |
| `5` + NBSP | 5 → 5 | 5 → 5 | 5 → 5 |
| tom | tom → tom | intet kontroltal → uændret | ingen ændring → uændret |
| ugyldig tekst over 40 tegn | ugyldig med fuld tekst → ugyldig, **40 tegn + "…"** | → ugyldig, 40 tegn + "…" | → ugyldig, 40 tegn + "…" |

### 3.6 Tekster (ordret)

`<tekst>` er altid den afkortede tekst (3.4).

| Situation | Tekst |
|---|---|
| Ugyldig lokationscelle (teksten er uændret, men afkortes nu) | `Bemærk: Ugyldig værdi „<tekst>“ for <lokation> – lokationen ændres ikke.` |
| **Ny:** ugyldig total, tilstand L (bemærkning L) | `Bemærk: Ugyldig værdi „<tekst>“ for lagerantal i alt – bruges ikke som kontroltal.` |
| **Ny:** ugyldig total, tilstand T med og uden fallback (bemærkning T) | `Bemærk: Ugyldig værdi „<tekst>“ for lagerantal i alt – lageret ændres ikke.` |
| "Fil:"-linjen i tilstand L (`supplier_upload_file_line`) | `Fil: Christiansfeld 41 · Michael 0 (tom) · Gert 12 · i alt „3 + 2“ (ugyldig)` |
| "Fil:"-linjen i tilstand T (vises nu også, når der ingen lokationer er) | `Fil: lagerantal i alt „3 + 2“ (ugyldig)` |

I tilstand T vises bemærkningen "Lagerantal i alt (N) skrives på …" og advarslen om reservationer ved valget `none` ikke, når totalen er ugyldig, fordi der ikke er nogen lagerændring. I tilstand L vises reservationsadvarslen stadig for lokationerne, da de skrives som normalt.

---

## 4. Data

- **Database:** Ingen ændringer, ingen migration og ingen nye indstillinger i `hsg_settings`.
- **Preview-JSON:**
  - `items[].stock_file` får nøglen `total_invalid` (afkortet tekst eller `null`). Den er altid med i nye previews.
  - `total` er `null`, når totalen er ugyldig.
  - `stock_file.invalid` indeholder den afkortede tekst.
  - `items[].source` indeholder aldrig `stock_file_total_invalid`.
  - `format: 2` er uændret. Et ældre preview uden nøglen læses som `null` (`?? null`).
- **Modulversion:** `modules/supplier_upload/module.php` hæves fra `1.1.1` til `1.1.2`. Det er et patch-bump uden `migrations.php`, som ved de to forrige rettelser. Begrundelsen er, at adfærden for lagertal ændres, og at det skal kunne ses, hvilken regel der kører. App-versionen hæves ved release efter release-tjeklisten i CLAUDE.md.

## 5. Rettigheder

Uændret:
- `require_module_enabled('supplier_upload')` og `require_capability('imports.manage')`.
- CSRF på alle POST-formularer, prepared statements og `h()` på al output. "Fil:"-linjen escapes allerede med `h()` (`supplier_upload.php:342`).
- Direkte brugerlinks har ingen adgang.
- Der er ingen nye administratorhandlinger og derfor ingen nye audit-hændelser.

## 6. Påvirkede filer

| Fil | Ændring |
|---|---|
| `core/supplier_import.php` | • Nye funktioner `hsg_supplier_stock_cell` og `hsg_supplier_short_text`, placeret ved `:112`.<br>• `hsg_supplier_location_cells` (`:121-146`) bruger `hsg_supplier_stock_cell`.<br>• `hsg_supplier_source_fields` (`:160-172`) bruger den til totalen og sætter `stock_file_total_invalid`.<br>• `hsg_supplier_merge_raw_items` (`:615-657`): `total_invalid`, fjernelse ved fletning og bemærkning L/T.<br>• Kommentaren til `hsg_supplier_merge_source` (`:377`).<br>Signaturerne er uændrede. |
| `supplier_upload.php` | `supplier_upload_file_line` (`:197-217`): ugyldig total i tilstand L og T (3.6) |
| `tests/supplier_import_test.php` | Kriterie 60–70 |
| `tests/supplier_upload_flow_test.php` | Kriterie 71 (alternativt manuel test) |
| `docs/SUPPLIER-UPLOAD.md` | • "Hvad er et tal i en lokationscelle?" (`:101`) ændres til "Hvad er et tal i en lagercelle?". Reglen gælder også "Lagerantal i alt".<br>• Konsekvensen i tilstand L og T beskrives.<br>• Loftet på 5 cifre, "kun hårde mellemrum = tom", én afrunding (`0.4999` → 0) og afkortningen til 40 tegn beskrives.<br>• Tabellen får nye rækker fra 3.5. |
| `modules/supplier_upload/module.php` | `version` → `1.1.2` |
| *Ved release* | `app_version.php`, README og `hsg-package.json` (release-tjeklisten) |

---

## 7. Acceptkriterier

Nummereringen fortsætter efter tillæg 2026-10-20 (39–59). Hjælperne `kim_row`, `kim_map`, `hsg_product` og `merge_opts` findes i `tests/supplier_import_test.php`. Testen gør PHP-advarsler til fejl, så "ingen advarsel" testes automatisk.

### Lagercellen (3.1, 3.4)

60. **Givet** `hsg_supplier_stock_cell(X)`, **så** gælder:
    - **Tom:** `state = 'blank'` og `value = null` for X = `''`, `'   '`, `"\u{00A0}"`, `"\u{202F}"` og `" \u{00A0}\t\u{202F} "`.
    - **Tal:** `state = 'number'` og `value` er:

      | X | `value` |
      |---|---|
      | `'41'` | 41 |
      | `'0'` | 0 |
      | `'-0'` | 0 |
      | `'41.0'` | 41 |
      | `'2,4'` | 2 |
      | `'2.6'` | 3 |
      | `'-2'` | 0 |
      | `'-0.5'` | 0 |
      | `'0.5'` | 1 |
      | `'2,5'` | 3 |
      | `'0.4999'` | 0 |
      | `'0,4999'` | 0 |
      | `'2.4999'` | 2 |
      | `'99999'` | 99999 |
      | `"5\u{00A0}"` | 5 |
      | `'40.999999999999993'` | 41 |
      | `'40,999999999999993'` | 41 |
    - **Ugyldig:** `state = 'invalid'`, `value = null` og `text = X` for X =
      - tekst: `'3 + 2'`, `'53 stk.'`, `'?'`, `'-'`, `'1e3'` og `'−2'` (Unicode-minus)
      - tusindtalsformater: `'1.234'`, `'1,234'` og `'1 234'`
      - for mange cifre: `'100000'`, `'-100000'`, `'000041'`, `'5010494560018'`, `'9223372036854775808'` og `'99999999999999999999'`

      Ingen af kaldene giver en PHP-advarsel.
61. **Givet** `hsg_supplier_short_text`, **så** gælder:
    - `str_repeat('a', 40)` og `str_repeat('ø', 40)` returneres uændret.
    - `str_repeat('a', 41)` giver `str_repeat('a', 40).'…'`.
    - `str_repeat('a', 39).'æøå'` giver `str_repeat('a', 39).'æ…'`, og resultatet er gyldig UTF-8 (`preg_match('//u', $r) === 1`).
    - `hsg_supplier_stock_cell(str_repeat('ø', 45))['text']` er `str_repeat('ø', 40).'…'`.

### Lokationsceller (P2, P3, P4, P5)

62. **Givet** `hsg_supplier_location_cells(kim_row([...]), kim_map(false))`, **så** gælder:
    - R=41, S=`"\u{00A0}"` og T=`" \u{202F} "` giver præcis `['values'=>['stock_loc_1'=>41,'stock_loc_2'=>0,'stock_loc_3'=>0],'blank'=>['stock_loc_2','stock_loc_3'],'invalid'=>[]]`. I dag er S og T ugyldige.
    - Kun hårde mellemrum i R, S og T giver `['values'=>[],'blank'=>['stock_loc_1','stock_loc_2','stock_loc_3'],'invalid'=>[]]`. Varen fra `hsg_supplier_merge_raw_items` har bemærkningen "Bemærk: Ingen tal i lokationskolonnerne – lageret ændres ikke." og ingen bemærkning med "Ugyldig værdi".
    - R=`'0.4999'`, S=`'9223372036854775808'` og T=`'5010494560018'` giver `values = ['stock_loc_1'=>0]` og `invalid = ['stock_loc_2'=>'9223372036854775808','stock_loc_3'=>'5010494560018']`.
63. **Givet**
    - et HSG-produkt med samme SKU som filrækken og Christiansfeld 5,
    - filrækken R=`str_repeat('ø', 45)`, S=1 og T='' (`kim_map(false)`),

    **når** rækkerne flettes, **så**
    - er `stock_file.invalid = ['stock_loc_1' => str_repeat('ø', 40).'…']`,
    - har `changes` ingen nøgle `stock_loc_1`,
    - har varen præcis bemærkningen `'Bemærk: Ugyldig værdi „'.str_repeat('ø', 40).'…“ for Christiansfeld – lokationen ændres ikke.'`

### "Lagerantal i alt" i tilstand L (3.2)

64. **Givet** `hsg_supplier_source_fields(kim_row(['sku'=>'64-1','name'=>'X','R'=>41,'S'=>'','T'=>12,'Q'=>'3 + 2']), kim_map())`, **så**
    - er `stock_loc_1=41`, `stock_loc_2=0`, `stock_loc_3=12` og `stock_file_total_invalid='3 + 2'`,
    - er der hverken `stock_file_total` eller `stock_quantity`.

    Med Q=`"\u{00A0}"` er der hverken `stock_file_total` eller `stock_file_total_invalid`. Med Q=`'0.4999'` er `stock_file_total=0`.
65. **Givet**
    - HSG-produktet `hsg_product(['id'=>65,'sku'=>'65-1','name'=>'Kontrol Whisky','distillery'=>'Testdestilleri','stock_loc_1'=>41,'stock_loc_2'=>2,'stock_loc_3'=>12])`,
    - filrækken sku 65-1, name 'Kontrol Whisky' og dist 'Testdestilleri' med R=41, S='', T=12 og Q=`'3 + 2'` (`kim_map()`, `merge_opts()`). Destilleriet er med, fordi navneparseren ellers udleder destilleriet "Kontrol Whisky" af navnet og giver en ekstra ændring (rettet efter testen).

    **når** rækkerne flettes, **så**
    - er `changes` præcis `['stock_loc_2'=>['old'=>2,'new'=>0,'file'=>0]]`,
    - er `stock_file.locations = ['stock_loc_1'=>41,'stock_loc_2'=>0,'stock_loc_3'=>12]`, `stock_file.total = null` og `stock_file.total_invalid = '3 + 2'`,
    - er `flags = []`, og ingen bemærkning indeholder "passer ikke med lagerantal i alt",
    - har varen bemærkningen "Bemærk: Ugyldig værdi „3 + 2“ for lagerantal i alt – bruges ikke som kontroltal.",
    - er `selected` sand, og `source` har hverken `stock_file_total` eller `stock_file_total_invalid`.

    Testen fejler på den nuværende kode, hvor kontroltallet er 32, rækken får `stock_mismatch` og ikke er forvalgt.

### "Lagerantal i alt" i tilstand T (3.2)

66. **Givet** `hsg_supplier_source_fields(kim_row(['sku'=>'66-1','name'=>'X','Q'=>'3 + 2']), kim_map(true,false), 1)`, **så** er `stock_file_total_invalid='3 + 2'`, og der er ingen `stock_loc_*`, `stock_file_total` eller `stock_quantity`. Desuden gælder:
    - **Uden fallback-id:** Det samme gælder, og der er ingen `stock_quantity`.
    - **Q=`"\u{00A0}"`:** Der er ingen lagernøgler og ingen `stock_file_total_invalid`.
    - **Q=`'0.4999'` og fallback 1:** `stock_loc_1=0` og `stock_file_total=0`.

    Test 12 (Q=53) er uændret grøn.
67. **Givet**
    - HSG-produktet `hsg_product(['id'=>67,'sku'=>'67-1','name'=>'Total Whisky','stock_loc_1'=>41,'stock_loc_2'=>2,'stock_loc_3'=>12])`,
    - filrækken sku 67-1, name 'Total Whisky', dist 'Testdestilleri' og Q=`'3 + 2'` (`kim_map(true,false)`, `merge_opts()`),

    **når** rækkerne flettes, **så**
    - har `changes` ingen `stock_loc_*`-nøgle, men indeholder `distillery`,
    - er `stock_file.mode='total'`, `stock_file.locations=[]`, `stock_file.total=null`, `stock_file.total_invalid='3 + 2'` og `stock_file.sum=0`,
    - har varen bemærkningen "Bemærk: Ugyldig værdi „3 + 2“ for lagerantal i alt – lageret ændres ikke." og ingen bemærkning med "skrives på Christiansfeld",
    - er `flags=[]`, og `selected` er sand på grund af destilleriet.

    **Givet** samme række uden match (`$products = []`), **så** er `total_stock=0`, `is_create_new` falsk og `selected` falsk.

    Testen fejler på den nuværende kode, hvor Christiansfeld ændres fra 41 til 32.

### Fletning (3.3)

68. **Givet**
    - filrækkerne 17-1028 ('Jane Street 2015', R=5, S='', T='', Q=`'3 + 2'`) og 17-1028a (samme navn, R='', S=2, T='', Q=2),
    - `kim_map()` og ingen HSG-produkter,

    **når** rækkerne flettes, **så** er der én vare med
    - `stock_file.locations = ['stock_loc_1'=>5,'stock_loc_2'=>2,'stock_loc_3'=>0]`,
    - `stock_file.total = null` og `stock_file.total_invalid = '3 + 2'`,
    - intet `stock_mismatch` og bemærkning L.

    **Givet** Q=5 på 17-1028 og Q=`'?'` på 17-1028a, **så** er `stock_file.total = null` og `total_invalid = '?'`. Delsummen 5 bruges altså ikke, og der er ingen afvigelse.
69. **Givet** filrækkerne 17-1028 (Q=5) og 17-1028a (Q=`'?'`) med samme navn, `kim_map(true,false)` og `merge_opts()`, **når** rækkerne flettes, **så**
    - har varens `source` hverken `stock_loc_*` eller `stock_file_total`,
    - er `stock_file.total_invalid = '?'` og `total_stock = 0`,
    - har varen bemærkning T.

    **Givet** Q=5 og Q=2, **så** er `source.stock_loc_1 = 7` og `stock_file.total = 7`, som i dag.
70. **Givet** `hsg_supplier_merge_source` og `hsg_supplier_parse_decimal`, **så** gælder:
    - `hsg_supplier_merge_source(['stock_file_total'=>3,'name'=>'A'], ['stock_file_total_invalid'=>'?','name'=>'B'])` er præcis `['stock_file_total'=>3,'name'=>'A','stock_file_total_invalid'=>'?']`.
    - `hsg_supplier_merge_source(['stock_file_total_invalid'=>'a'], ['stock_file_total_invalid'=>'b'])['stock_file_total_invalid']` er `'a'`.
    - `hsg_supplier_parse_decimal` er uændret: `'1.234,50'` giver 1234.5, `'53 stk.'` giver 53.0, og `'0.4999'` giver 0.5.

### Preview og generelt

71. **Givet** et preview med lokationskolonner og en vare med R=41, S tom, T=12 og Q=`'3 + 2'`, **så** viser varen linjen `Fil: <Christiansfeld> 41 · <Michael> 0 (tom) · <Gert> 12 · i alt „3 + 2“ (ugyldig)`. Lokationsnavnene er dem, testen bruger.

    **Givet** et preview med kun "Lagerantal i alt" og Q=`'3 + 2'`, **så** vises `Fil: lagerantal i alt „3 + 2“ (ugyldig)`.

    Det testes i flowtesten eller manuelt. Et preview uden `total_invalid` vises som før.
72. **Når** `bash tests/run.sh` køres, **så** er alle trin grønne, og de eksisterende kriterier 1–59 er uændret grønne (enhedstest, databasetest og flowtest).
73. **Givet** den rigtige fil "lager 19-10-2026.xlsx" (manuel test; filen må ikke lægges i repoet), **når** previewet laves på `feature/kims-upload-review-rettelser` og på denne branch med samme kolonnevalg og reservationsvalg, **så** er varer, match, ændringer og forvalg identiske. Eventuelle forskelle skal kunne forklares med cellerne i 3.5, og testeren skriver dem i PR'en.
74. **Givet** modulmanifestet, **så** er `version` lig `1.1.2`, og `docs/SUPPLIER-UPLOAD.md` er opdateret som angivet i afsnit 6.

---

## 8. Uden for scope

- `hsg_supplier_parse_decimal`, som bruges til priser, ABV og flaskestørrelse.
- Videnskabelig notation (`1e3`, `1.7E-15`), `+5`, Unicode-minus og tusindtalsformater. De er ugyldige som efter R3.
- Et nyt flag eller fravalg af rækker med en ugyldig total (se Q2).
- At opsummeringen "Lager i filen" viser antallet af ugyldige totaler. Den tæller kun gyldige totaler. I tilstand L kan badget "Afviger – se bemærkningerne på de enkelte varer" derfor vises, og varen har så bemærkning L.
- Afkortning af andre bemærkninger, fx navne i "navnene er forskellige („A“ / „B“)".
- Konvertering af tegnsæt i CSV-filer. Ugyldig UTF-8 giver "ugyldig", aldrig "tom".
- Q2 fra tillæg 2026-10-20 (fadnumre uden ciffer).

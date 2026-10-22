# Opfølgning Q2: Fadnummer kræver et ciffer – udledning og oprydning

- **Dato:** 2026-10-22
- **Status:** Godkendt af brugeren 2026-10-22. Q1 = A (ryd kun med bevis, resten på listen), Q2 = ja (skriv det rigtige fadnummer), Q3 = nej (release-note i stedet for nyt preview-format). KAN-punkterne laves, hvis de er billige.
- **Bygger på:** [`docs/specs/2026-10-20-kims-upload-review-rettelser.md`](2026-10-20-kims-upload-review-rettelser.md). Det er opfølgningen **Q2** fra afsnit 0 punkt 2 og afsnit 10 punkt 2 i den spec. Spec'en ophæver første punkt i dens afsnit 9 ("`hsg_extract_cask_number` … udleder stadig fadnumre uden ciffer, og eksisterende værdier i HSG ryddes ikke op"). R2 gælder uændret (se F4).
- **Branch:** `feature/kims-upload-fadnummer-ciffer`. Den bygger på `feature/kims-upload-review-rettelser`, hvor R2 er implementeret. Ingen af dem er merget til `main`.
- **Modul:** `products` ("Produkter", kernemodul, capability `products.manage`) hæves fra `1.5.0` til `1.5.1` og får sin første `migrations.php`. `supplier_upload` er uændret (`1.1.1`).
- **Kode:** `core/product_enrichment.php`, `modules/products/` og `products.php`. Linjenumrene passer på HEAD af branchen (`df886b4`).
- **Rettet efter review:** 2026-10-22. XSS i Produkter, mobiloverløb, flere tests, præcis hjælpetekst og omnummerering af kriterierne fra 60–80 til 78–98 (afsnit 11).

---

## 0. Åbne spørgsmål

Spørgsmålene er samlet med anbefalinger i afsnit 10. Kort:

1. **Q1 – Hvor meget ryddes automatisk?** Det kan ikke afgøres sikkert for alle produkter, om et fadnummer uden ciffer er udledt automatisk (afsnit 3.3). **Anbefaling:** Migrationen rydder kun værdier, hvor der er bevis for, at de er udledt automatisk. Resten vises på en liste i Produkter, så admin selv kan rette dem.
2. **Q2 – Rigtigt fadnummer i stedet?** Skal migrationen skrive det rigtige fadnummer, når den tekst, der gav det forkerte ord, også indeholder et rigtigt nummer (fx 12-1016 Knockdhu: FINISH → 43)? **Anbefaling:** ja.
3. **Q3 – Gamle previews i Kims uploadfil:** Et preview, der er lavet før opdateringen, kan stadig anvendes bagefter og kan lægge fx CRAFT i et tomt felt igen. Skal preview-formatet hæves, så gamle previews afvises? **Anbefaling:** nej. Skriv i release-noten, at man skal lave et nyt preview efter opdateringen.

---

## 1. Formål

`hsg_extract_cask_number` (`core/product_enrichment.php:10-28`) læser ord som fadnummer: "Cask Strength" bliver til STRENGTH, "Sherry Cask Finish" til FINISH, "Cask Craft" til CRAFT og "barrel matured" til MATURED. Ordene ender i `lager_products.cask_number` via Excel-import, opgraderingens backfill, installationens startlager, Kims uploadfil og produktdata-assistenten. Derfra vises de som "Fadnummer #STRENGTH" i kataloget og PDF'en, i Lagerstatus og i billedtjekket.

Funktionen returnerer desuden det **første** match. Derfor taber den i dag et rigtigt fadnummer, der står senere i teksten. Et eksempel fra startlageret er 12-1016 "Knockdhu, 10yo, 2012 - #43 - Virgin Oak Barrel Finish", hvor fadnummeret i dag bliver FINISH i stedet for 43.

Opfølgningen løser problemet for **admin**:
- Nye fadnumre skal indeholde mindst ét ciffer (F1).
- Eksisterende værdier, som beviseligt er udledt automatisk, ryddes én gang og rettes til det rigtige nummer, hvis teksten har et (F2).
- Admin får en liste over de resterende fadnumre uden ciffer, så de kan rettes manuelt (F3).

**Linkbrugere og kunder** bliver berørt indirekte: Lagerstatus, kataloget og PDF'en viser ikke længere ord som fadnummer.

**Bagudkompatibelt:**
- Ingen skemaændringer.
- Fadnumre med ciffer røres ikke.
- R2 og resten af Kims uploadfil er uændret i koden.
- Kun værdier uden ciffer, der beviseligt er udledt automatisk, ændres (Q1).

---

## 2. Brugerflow og skærme

### 2.1 Flow

1. Admin opgraderer (update.php eller upload via FTP). update.php tager som altid en FULL-backup først.
2. Ved første almindelige forespørgsel efter opgraderingen kører migrationen `products 1.5.1` (F2). Den har ingen brugerflade.
3. I **System → Auditlog** (filter "Produkter") står én post `product.cask_number_cleanup` med aktøren "System (opdatering)". Den viser, hvad der er ryddet (gammel og ny værdi), og hvad der er ladt stå.
4. I **Produkter** står knappen **"Fadnr. uden ciffer (N)"**, hvis der stadig er sådanne produkter (F3). Admin åbner listen, trykker **Rediger** på hvert produkt, retter eller tømmer feltet **Fadnummer** og trykker **Gem produkt**. Produktet forsvinder derefter fra listen.
5. I **Datakvalitet** står produkter med et tomt fadnummer under "Mangler data", fordi Fadnummer som standard er et obligatorisk felt (`core/quality.php:24`). Admin indtaster det rigtige nummer eller trykker **Ikke nødvendig**. Det sker allerede i dag for produkter uden fadnummer.
6. I **Kims uploadfil** viser previewet ikke længere "Fad #STRENGTH". Admin laver et nyt preview efter opdateringen (Q3).

### 2.2 Skærm: Produkter (`products.php`) – kun ændringer

Der kommer ingen ny side, ingen ny POST-handling og ingen ny CSS-klasse. Filteret genbruger mønsteret fra "Mangler fadnr." (`products.php:116`, `:125`, `:158`).

```
Produktoversigt                                         [＋ Opret nyt produkt]
[Aktive] [Inaktive] [Udgåede] [Alle m. status] |
[Mangler fadnr. (41)] [Fadnr. uden ciffer (4)] [Negativt lager (0)]
Fadnumre uden ciffer (fx STRENGTH, FINISH eller N/A) er ikke rigtige fadnumre. De
bruges ikke ved produktmatch i Kims uploadfil, men vises stadig i kataloget og
bruges i billedtjekket, indtil de er rettet. De fleste er læst forkert ud fra
produktnavnet. Åbn produktet, ret eller tøm feltet Fadnummer, og gem. Listen
ændrer ikke noget selv.

Mobil (kort pr. produkt):           Desktop (tabel, uændret):
┌─────────────────────────────┐     Glen Test – 12 år – 55 %
│ [billede] Glen Test – 12 år │     Glen Test · 12 år · 55% · Fad #STRENGTH
│ 13-2001 · Uncharted Whisky  │                                   [Rediger]
│ Disponibelt          6 stk. │
│ Fysisk / Reserveret   6 / 0 │
│ Pris (Engros/Udsalg)   …    │
│ Fadnr.          #STRENGTH   │  ← ny linje, kun i dette filter
│ [Rediger]                   │
└─────────────────────────────┘
```

| Element | Regel |
|---|---|
| Knap | Teksten er `Fadnr. uden ciffer (N)`. Den står mellem "Mangler fadnr." og "Negativt lager" og vises **kun, når N > 0**. Linket er `products.php?status=all&filter=cask_no_digit`. Knappen har klassen `button`, når filteret er valgt, ellers `button secondary`, som "Mangler fadnr.". |
| N | Antal produkter i **alle** statusser, hvor `cask_number` ikke er tom efter `TRIM` og ikke indeholder et ciffer. Linket bruger `status=all`, så listen passer med tallet. |
| Filterbetingelse | `p.cask_number IS NOT NULL AND TRIM(p.cask_number)<>'' AND p.cask_number NOT REGEXP '[0-9]'`. Forespørgslen indeholder ingen brugerinput, og `filter` sammenlignes med en fast streng som de andre filtre. |
| Hjælpetekst | Teksten i skitsen står som `<p class="muted">` under knaprækken, kun når filteret er valgt. |
| Mobil | I kortet (`products.php:200-228`) kommer en ekstra `mobile-stock-line` efter prislinjen (`:214`) med `Fadnr.` og `#<værdi>`, kun når filteret er valgt. Værdien escapes med `h()`. Værdi-spanen har `overflow-wrap:anywhere;min-width:0;text-align:right` (inline), så en lang værdi uden mellemrum ombrydes i stedet for at gøre siden bredere end skærmen (rettelse efter review). |
| Desktop | Ingen ændring. Linjen med produktdata viser allerede "Fad #STRENGTH" (`:246`). |
| "Nulstil filtre" | Uændret (`:134-136`). |
| `status` fra URL'en | Rettelse efter review (XSS): Værdien whitelistes til `active`, `inactive`, `discontinued` eller `all`. Alt andet, også en tom værdi, giver `active`, som når parameteren mangler. Den skrives med `h()` alle steder på siden. |

---

## 3. Regler

### 3.1 Hvem bruger udledningen? (undersøgt i hele repoet)

| Kalder | Hvor | Bruges til | Efter F1 |
|---|---|---|---|
| `hsg_product_parse_text` | `core/product_enrichment.php:54` | Fælles regelbaseret aflæsning af varetekst | Giver ikke længere `cask_number` uden ciffer. Finder et senere rigtigt nummer i samme tekst. |
| ↳ Excel-import | `import.php:44`, `:55` | Fadnummer, når filen ikke har kolonnen "Fadnummer", eller cellen er tom | Skriver tomt i stedet for STRENGTH. `product_id()` (`:14`) skriver altid `cask_number` på eksisterende produkter. Det er uændret (afsnit 9). |
| ↳ Opgraderingens backfill | `migrations.php:109-124` | Udfylder kun tomme felter (`:114`) | Kan ikke længere skrive ord uden ciffer. Den kører kun, når app-versionen er hævet (`:27`), og ikke ved hver forespørgsel. Koden ændres ikke. |
| ↳ Installation med startlager | `install.php:46` | Fadnummer på startlagerets produkter | 12-1016, 12-1087, 12-1098 og 12-1104 får 43, 143, 71324 og 300223 i stedet for FINISH. 11-2010 og 18-1015 får intet i stedet for CRAFT og MATURED. |
| ↳ Kims uploadfil | `core/supplier_import.php:174` (`hsg_supplier_source_fields`) | `source.cask_number` i previewet. Skrives i tomme HSG-felter og ved "Opret som nyt produkt" | Previewet viser ikke "Fad #CRAFT". Tomme HSG-felter får ikke ord. En række uden varenummer (fx række 21 i Kims fil med CRAFT) får SKU `KIM-<dato>-<tilfældig>` i stedet for `KIM-CRAFT` (`:968-969`). |
| ↳ Produktdata-assistent | `hsg_enrich_product_text` (`core/product_enrichment.php:189-190`) → `product_enrich.php:25`, `:28`. Bruges af "Udfyld fra varetekst" (`product_edit.php:209-211`) og "Udfyld manglende data på alle" (`products.php:315`, `apply=1`) | Foreslår eller skriver værdier i tomme felter | Foreslår og skriver ikke ord uden ciffer. AI-delen udfylder aldrig fadnummer (`:176`), så Groq kan ikke lægge dem ind igen. |
| `hsg_extract_cask_number` direkte | `core/supplier_import.php:175` | Ekstra forsøg på det rå navn | Samme funktion og dermed samme regel. Linjen ændres ikke. |

**Ingen andre kaldere:** `core/groq.php`, `core/quality.php`, `quality.php`, `image_tools.php` og `product_edit.php` kalder hverken funktionen direkte eller via `hsg_product_parse_text`. De **læser** kun den gemte værdi (tabellen herunder).

**Hvor den gemte værdi vises eller bruges** (her mærkes oprydningen):

| Sted | Hvor | Effekt af oprydningen |
|---|---|---|
| Katalog web/PDF | `core/catalog_layout.php:241` (bruges af `catalog.php` og `catalog_pdf.php`, ingen PDF-cache) | Rækken "Fadnummer #STRENGTH" forsvinder eller viser det rigtige nummer. |
| Lagerstatus (også direkte links) | `status.php:160` | Faktaboksen viser ikke ordet. |
| Produkter | `products.php:116`, `:125`, `:158`, `:246` | "Fad #STRENGTH" bliver til "Fadnr. mangler", og tælleren "Mangler fadnr." stiger. |
| Datakvalitet | `core/quality.php:24`, `:49-59` | Ryddede produkter står under "Mangler data", indtil de har et nummer eller er markeret "Ikke nødvendig". |
| Excel-eksport | `export.php` (kolonnen Fadnummer) | Kolonnen er tom for ryddede produkter. |
| Billedtjek | `image_tools.php:74`, `:143`, `:153`. `core/groq.php:138`, `:283` | Ingen falsk "fadnummer matcher" (+22) og ingen falsk "andet fadnummer på siden" (−35) på grund af ord som STRENGTH. |
| Kims uploadfil | `supplier_upload.php:192`, `:320`, `:336`. Matching via R2 | Tælleren over produkter uden fadnummer stiger. Ordene var allerede uskadelige ved matching (R2). |
| Hurtigsøgning | `core/bootstrap.php:63` | Underteksten viser ikke "Cask: STRENGTH". |

### 3.2 F1 – Udledningen kræver et ciffer, og filteret ligger i løkken

**Ny ren funktion** `hsg_cask_number_candidates(string $text): array` i `core/product_enrichment.php`. Den returnerer **alle** værdier, som den nuværende kode ville kunne returnere, i den samme rækkefølge og med de samme regler:
- de samme to mønstre (`:13-14`),
- først alle match fra mønster 1 og derefter alle match fra mønster 2, i den rækkefølge de står i teksten,
- det samme batch/bottle/flaske-filter foran `#` (`:22`) og det samme årstalsfilter for 19xx/20xx (`:23`),
- hver værdi trimmes og skrives med store bogstaver (`:19`, `:24`).

Tom tekst giver `[]`. Det første element er altså præcis det, den nuværende `hsg_extract_cask_number` returnerer. F2 bruger det som "den gamle udledning".

**`hsg_extract_cask_number(string $text): ?string`** har samme signatur. Den returnerer den **første kandidat, der indeholder mindst ét ciffer** (0–9, `preg_match('/\d/', …)`, som i R2), ellers `null`.

**Beslutning: filteret ligger i løkken.** Funktionen returnerer altså ikke bare `null` efter første match uden ciffer. Begrundelse:
- Startlageret har fire produkter med mønstret "#NNN – … Cask Finish" (12-1016, 12-1087, 12-1098 og 12-1104). Med `null` efter første match ville de miste deres rigtige nummer. Med filteret i løkken får de 43, 143, 71324 og 300223.
- "Cask Strength, Cask No. 231584" giver 231584 i stedet for intet.
- Ændringen er lokal: Tekster uden ord-match giver samme resultat som i dag.

**Konsekvens, som accepteres:** En tekst med både et ord-match og et senere `#NN`, der ikke er et fadnummer, fx "Cask Finish – Release #12", giver nu 12 (i dag FINISH). Det samme navn uden "Cask Finish" giver allerede 12 i dag. Det er en kendt svaghed i #-mønstret (afsnit 9).

`hsg_product_parse_text` ændres ikke i koden. Effekten kommer via `hsg_extract_cask_number` (`:54-55`). Begrundelsen "fadnummer aflæst direkte efter #/Cask No." kommer derfor kun med, når der er fundet et nummer med ciffer.

### 3.3 F2 – Engangsoprydning (migration `products 1.5.1`)

#### Kan automatiske værdier skelnes fra manuelle?

Undersøgt spor:

| Spor | Hvad det viser | Hvorfor det ikke er nok alene |
|---|---|---|
| `data_enrichment_source`, `_note` og `_enriched_at` | Sættes af backfillen (`migrations.php:120-121`) og af assistenten med `apply` (`core/product_enrichment.php:233-236`). Noten nævner fadnummeret, hvis reglerne fandt et. | Gælder hele rækken, ikke feltet. Overskrives ved næste berigelse. Sættes ikke af Excel-import, Kims uploadfil, installationen eller Produkt-redigering. En senere manuel rettelse lader noten stå uændret. |
| Den gamle udledning af produktets **nuværende navn** | Præcist bevis, når den gemte værdi er nøjagtig det, den gamle kode giver. | Navnene skrives om til katalogtitlen "Destilleri (årgang) – alder – %" ved hver opgradering (`migrations.php:265-275`) og ved hver gemning i Produkt-redigering (`product_edit.php:46`). Teksten med "Cask Strength" er derfor som regel væk. |
| Den gamle udledning af navnet i **`initial-stock.json`** for samme SKU | Præcist bevis for produkter, der er oprettet fra startlageret (`install.php:46`). | Dækker kun startlageret: de seks kendte tilfælde 11-2010, 12-1016, 12-1087, 12-1098, 12-1104 og 18-1015. |
| Audit-loggen | `product.enrich` logger feltnavne (`product_enrich.php:29`), og `supplier_import.apply` logger feltnavne pr. produkt (`supplier_upload.php:162`). | Ingen af dem logger værdier. `product.save` logger kun SKU, navn og status (`product_edit.php:70`), så en senere manuel ændring kan ikke udelukkes. `import.php` logger intet pr. produkt (`:67`). Backfill og installation logger intet. |
| Historik | – | Der findes ingen feltshistorik for `lager_products`. |
| Værdiens form | Den gamle kode gemmer altid STORE bogstaver uden `#` og mellemrum: 2–31 tegn af A–Z, `.`, `-` og `/` (`:13-14`, `:24`). | Formen er nødvendig, men ikke tilstrækkelig. Et manuelt indtastet "N/A" har samme form. Den kan kun vise, at fx "Ukendt" eller "strength" er indtastet manuelt, ikke det modsatte. |

**Konklusion:** Det kan ikke skelnes sikkert for alle produkter. **Anbefaling (Q1): ryd det beviste, og vis resten.**
- Migrationen ændrer kun værdier med et præcist bevis fra et af de to navnespor.
- Alt andet uden ciffer får admin på en liste først (F3) og retter selv.
- Alt, der ryddes, står i audit-posten med den gamle værdi.

#### Regel

`hsg_cask_number_cleanup(PDO $pdo, ?array $seedNames = null): array` er en ny funktion i `core/product_enrichment.php`. `$seedNames` har formen `[sku => [navn, …]]`. Med `null` læses den fra `initial-stock.json` i roden. Testen sender sin egen.

For hver række i `lager_products` (alle statusser), hvor `cask_number` efter `trim` ikke er tom og ikke indeholder et ciffer:

1. **Kildetekster** i denne rækkefølge:
   - (a) produktets nuværende `name`,
   - (b) hvert navn i `initial-stock.json` (`products[].name`), hvor `products[].sku` efter `trim` er lig produktets `sku`. Et SKU kan stå flere gange.
2. Hver kildetekst normaliseres som i `hsg_product_parse_text` (`:31`): `–` og `—` bliver til `-`, og mellemrum samles og trimmes. Derefter beregnes den gamle udledning: `hsg_cask_number_candidates(tekst)[0] ?? null`.
3. **Bevis:** den første kildetekst, hvor den gamle udledning er **præcis** lig `trim(cask_number)`. Der skelnes mellem store og små bogstaver, og sammenligningen sker i PHP, ikke i SQL. Små bogstaver og `#` kan ikke komme fra den gamle kode.
4. **Med bevis:**
   - `ny = hsg_extract_cask_number(beviseteksten)` (genudledning med den nye regel, Q2). Resultatet kan være `null`.
   - `UPDATE lager_products SET cask_number=? WHERE id=? AND cask_number=?` sætter `ny`, eller `NULL` hvis `ny` er `null`. Den sidste betingelse er den gamle værdi.
   - Rækken kommer med i `cleared` som `{id, sku, old, new, source}`, hvor `source` er `name` eller `initial_stock`.
5. **Uden bevis:** ingen ændring. Rækken kommer med i `kept` som `{id, sku, value}`.

**Robusthed:** Migrationen kører inde i `db.php`. En undtagelse giver den generelle databasefejl (`db.php:14`) på **alle** sider ved hver forespørgsel, indtil fejlen er rettet. Derfor:
- En manglende eller ugyldig `initial-stock.json` behandles som `[]`.
- Findes kolonnen `cask_number` ikke (`db_column_exists`), returnerer funktionen tomme lister uden at gøre noget.
- Funktionen åbner **ikke** selv en transaktion, fordi modulmekanismen allerede har åbnet en (`core/modules.php:128-137`). Alle ændringer og audit-posten gemmes derfor samlet eller slet ikke.

Returværdien er `['cleared' => [...], 'kept' => [...]]`.

#### Audit

Hvert kald skriver **præcis én** række i `hsg_audit_log` (`schema.sql:214-228`). Det gælder også, når intet er ryddet, så det kan ses, at tjekket er kørt.

`audit_log()` (`core/audit.php:4`) bruges ikke af to grunde:
- `core/audit.php` indlæses først efter `db.php` i `auth.php` (`:5` og `:9`), så funktionen findes ikke, når migrationen kører.
- Den finder aktøren i sessionen. Posten ville derfor stå på den admin eller linkbruger, hvis forespørgsel tilfældigvis udløste migrationen.

Oprydningen indsætter derfor selv rækken med en prepared statement, men kun hvis `hsg_audit_log` findes (`db_table_exists`):

| Kolonne | Værdi |
|---|---|
| `actor_type` / `actor_id` / `actor_name` | `system` / `NULL` / `System (opdatering)` |
| `action` | `product.cask_number_cleanup` |
| `entity_type` / `entity_id` | `product` / `NULL` (vises under filteret "Produkter" i `system.php:123`) |
| `ip_hash` | `NULL` |
| `details_json` | `{"rule":"Fadnummer uden ciffer, udledt automatisk af navnet","cleared_count":N,"cleared":[{"id":…,"sku":"…","old":"FINISH","new":"43","source":"initial_stock"},…],"kept_count":M,"kept":[{"id":…,"sku":"…","value":"STRENGTH"},…]}` |

#### Kun én gang, idempotent

- `modules/products/migrations.php` er ny. Den returnerer kun `['1.5.1' => callable]`. Callable'en kalder `hsg_cask_number_cleanup($pdo)` efter `require_once` af `core/product_enrichment.php`. Filen må ikke gøre andet end at returnere arrayet, fordi den indlæses ved hver forespørgsel (`core/modules.php:120`).
- Migrationen kører, når products-versionen i `hsg_module_versions` er lavere end `1.5.1` (`core/modules.php:126`), og versionen sættes bagefter (`:131`). Eksisterende installationer har `1.5.0` og kører den én gang. En ny installation har `0.0.0` og kører den også én gang: Der er intet at rydde, og audit-posten viser 0 og 0.
- **Idempotent:** Ryddede rækker er bagefter tomme eller har et ciffer og vælges ikke igen. Rækker i `kept` ændres aldrig. Et nyt kald ændrer derfor ingen data og skriver kun en ny audit-post med `cleared_count` 0.

#### Rækkefølge i forhold til backfillen

- `db.php:11` kører `ensure_schema_updates` (med backfill `:109-124` og navnestandardisering `:265-275`) **før** `db.php:13` `hsg_run_module_migrations`. Oprydningen kører altså efter backfillen i samme forespørgsel.
- Backfillen udfylder kun tomme felter. Den rører derfor ikke de ord, som oprydningen tager sig af, og efter F1 kan den ikke skrive nye. Rækkefølgen ændrer altså ikke resultatet.
- Oprydningen venter ikke på næste backfill, fordi den selv genudleder (punkt 4). Felter, den tømmer, kan blive udfyldt af en senere backfill, men kun med et nummer med ciffer og kun, hvis navnet indeholder et.
- **Opgradering via update.php:** Selve opgraderingsforespørgslen kører `ensure_schema_updates` med den **gamle** kode, som allerede er indlæst (`core/updater.php:323`). `migrations.php` og `core/product_enrichment.php` er indlæst med `require_once` ved forespørgslens start. Den gamle backfill kan derfor skrive ord i tomme felter én sidste gang, og den sætter `schema_version` til den nye version. Modulmigrationen kører først ved næste forespørgsel med den nye kode:
  - Ordene ryddes, hvis det nuværende navn stadig giver dem.
  - Ellers kommer de på listen. Det kan ske, fordi navnet standardiseres i samme kørsel som backfillen.
- **Derfor ikke et engangsflag i `migrations.php`** (`meta_get`/`meta_set`, som i `:107`): Efter en opgradering via update.php ville den nye kode i `ensure_schema_updates` først køre ved den **næste** versionsændring, fordi funktionen returnerer tidligt (`:27`). En modulmigration kører ved første forespørgsel efter enhver opgradering og hæver modulversionen, som CLAUDE.md regel 2 kræver.

#### Forventet effekt på kendte data

Tabellen forudsætter, at produktet er oprettet med den gamle kode og ikke er rettet manuelt siden. Den faktiske liste står i audit-posten.

| SKU | Kendt kilde | Værdi i dag | Efter migrationen |
|---|---|---|---|
| 11-2010 | startlager "MMD - Auchroisk, Cask Craft, …" | CRAFT | tom (`initial_stock`) |
| 12-1016 | startlager "Knockdhu, … #43 … Barrel Finish" | FINISH | **43** |
| 12-1087 | startlager "Royal Brackla, … #143 … Cask Finish" | FINISH | **143** |
| 12-1098 | startlager "Teaninich, … #71324 … Qtr Cask Finish" | FINISH | **71324** |
| 12-1104 | startlager "Deanston, … #300223, PX Quarter Cask Finish" | FINISH | **300223** |
| 18-1015 | startlager "Invergordon, 50YO, Double ex-bourbon barrel matured" | MATURED | tom |
| 11-2007, 11-2011, 12-1124, 12-1128 | Kims fil (CRAFT/FINISH ifølge testrapporten til den forrige spec) | evt. CRAFT/FINISH | ryddes kun, hvis det nuværende navn stadig giver værdien. Ellers kommer de på listen (F3). |

### 3.4 F3 – Kontrolliste i Produkter (read-only)

Se afsnit 2.2. Listen er "liste først"-delen af Q1:
- Den viser alle produkter med et fadnummer uden ciffer, uanset hvor værdien kommer fra: rester efter migrationen, Kims Fadnummer-kolonne, Excel-import eller manuel indtastning.
- Den ændrer intet selv. Admin retter via den eksisterende Produkt-redigering.

### 3.5 F4 – R2 bliver (beslutning)

`hsg_supplier_variant_compatible` (`$caskOf`, `core/supplier_import.php:397-400`) og `hsg_supplier_match_score` (`:188-190`) ændres **ikke**. Projektlederens anbefaling følges, og R2 bliver som ekstra værn. Begrundelse:

1. Migrationen rydder kun beviste værdier. Resten, fx et manuelt "N/A", står, indtil admin retter det, og ville uden R2 igen give falske 98-match og falske splits.
2. Kims fil kan have en Fadnummer-kolonne med tekst uden ciffer. Den værdi kommer fra filen og ikke fra udledningen, og den skrives stadig i tomme felter (`:157`, `:177`). Det samme gælder Excel-importens Fadnummer-kolonne og Produkt-redigering.
3. Det er to linjer, der allerede er testet (kriterie 44–46). Fjernes de, skal der testes igen, og der er intet at vinde.

`hsg_supplier_source_fields` (`:155-179`) ændres heller ikke. Linje `:175` er overflødig, fordi `:174` allerede bruger samme udledning, men den er harmløs og bevares, så `core/supplier_import.php` er helt uændret.

---

## 4. Valgfrie KAN-punkter (laves, hvis de er billige)

- **KAN-1 – Hjælpetekst i Produkt-redigering** (`product_edit.php:137`): `<span class="muted">` får teksten "Nummeret efter # / Cask No. bruges som stærkt match ved leverandørupload og billedtjek. Det skal indeholde mindst ét ciffer – ord som "Strength" eller "Finish" er ikke et fadnummer." Der kommer ingen validering (afsnit 9).
- **KAN-2 – Note i `docs/ARCHITECTURE.md`**, afsnittet "Migrations": En engangsrettelse af data, som skal køre med den nye kode, lægges som modulmigration. Begrundelsen er, at `ensure_schema_updates` kører med den gamle kode i opgraderingsforespørgslen (`core/updater.php:323`) og returnerer tidligt bagefter (`migrations.php:27`).

---

## 5. Data

- **Ingen skemaændringer.** Der kommer ingen nye tabeller, kolonner eller indstillinger i `hsg_settings`.
- **Dataændring:** `lager_products.cask_number` sættes til `NULL` eller til det genudledte nummer på rækker med bevis (F2).
- **Ny audit-handling:** `product.cask_number_cleanup` (aktør System).
- **Modul:** `modules/products/module.php` hæves fra `1.5.0` til `1.5.1`. Det er en patch-bump, fordi det er en fejlrettelse med dataoprydning. `modules/products/migrations.php` er ny med nøglen `1.5.1`.
- **Ikke hævet:** `supplier_upload` (`1.1.1`) og `import_export`. Deres kode er uændret, og de ændrer kun adfærd gennem kernefunktionen.
- **Preview-formatet** i Kims uploadfil er uændret (`format: 2`, Q3).
- **App-versionen** hæves ved release efter release-tjeklisten i CLAUDE.md.
- **Release-note (README.txt):** "Fadnummer kræver nu mindst ét ciffer. Ord som STRENGTH, FINISH og CRAFT, der før blev læst ud af navne som "Cask Strength", gemmes ikke længere som fadnummer. Opdateringen rydder sådanne værdier, når det kan ses, at de er læst automatisk, og retter dem til det rigtige fadnummer, hvis navnet har et. Resten vises under Produkter → "Fadnr. uden ciffer". Lav et nyt preview i Kims uploadfil efter opdateringen."

## 6. Rettigheder

- **Migrationen** har ingen bruger. Den kører som System ved første forespørgsel efter opgraderingen og logges i audit-loggen (F2).
- **Listen i Produkter** bruger den eksisterende `require_capability('products.manage')` (`products.php:3`). Den er kun GET, og der kommer ingen ny POST-handling. "Rediger" vises som i dag kun for admin (`:219-223`).
- **Direkte brugerlinks** har ingen adgang til Produkter (uændret). I Lagerstatus ser de kun de rensede data. Der er ingen nye rettigheder.
- CSRF, prepared statements og `h()` gælder som altid. Filterets SQL har ingen brugerinput, og migrationens SQL bruger prepared statements.
- Efter review: `status` fra URL'en whitelistes og escapes i Produkter (XSS, afsnit 11).

## 7. Påvirkede filer

| Fil | Ændring |
|---|---|
| `core/product_enrichment.php` | Ny `hsg_cask_number_candidates`. `hsg_extract_cask_number` (`:10-28`) bruger cifferreglen i løkken. Ny `hsg_cask_number_cleanup` (F2). Eventuelt en lille hjælper til tekstnormaliseringen fra `:31`, så den ikke kopieres. |
| `modules/products/migrations.php` | **Ny.** `'1.5.1'` kalder `hsg_cask_number_cleanup($pdo)`. |
| `modules/products/module.php` | `version` → `1.5.1` |
| `products.php` | Filteret `cask_no_digit`, tælleren, knappen (kun når N > 0), hjælpeteksten og mobillinjen (afsnit 2.2). Efter review: whitelist og `h()` for `status` (XSS) og ombrydning af mobillinjen |
| `product_edit.php` | KAN-1 (`:137`), valgfri |
| `tests/product_enrichment_test.php` | **Ny** enhedstest uden database (kriterie 78–82 og 88) |
| `tests/cask_number_cleanup_db_test.php` | **Ny** databasetest med samme sikkerhedsvagt som `tests/supplier_import_db_test.php:21-26`. Varenumrene har præfikset `TSTCASK-` og ryddes op bagefter (kriterie 83–87) |
| `tests/run.sh` | Trin 3 kører også `tests/product_enrichment_test.php`. Trin 4 kører `tests/cask_number_cleanup_db_test.php` efter databasetesten af Kims uploadfil. Røgtesten får `products.php?status=all&filter=cask_no_digit` med på sidelisten (`:72-74`). Efter review: regressionstjek for XSS via `status` |
| `docs/SUPPLIER-UPLOAD.md` | `:24`: fadnummer udledes kun, hvis det har et ciffer. "Produktmatch" (`:79`): ordene udledes ikke længere, og R2 er et ekstra værn mod manuelle værdier og værdier fra filen |
| `docs/PRODUCT-DATA-ASSISTANT.md` | Punkt 1: fadnummer efter `#`, Cask No. og Fad nr. aflæses kun med mindst ét ciffer. Ny sektion "Fadnumre uden ciffer" om engangsoprydningen og listen i Produkter |
| `README.txt` | `:254` får tilføjet "et fadnummer skal indeholde mindst ét ciffer" samt release-noten (afsnit 5) |
| `docs/ARCHITECTURE.md` | KAN-2, valgfri |
| *Ved release* | `app_version.php`, README og `hsg-package.json` (release-tjeklisten) |

**Ikke ændret:** `core/supplier_import.php`, `supplier_upload.php`, `migrations.php`, `import.php`, `install.php`, `core/catalog_layout.php`, `core/quality.php` og `quality.php`.

---

## 8. Acceptkriterier

Nummereringen fortsætter efter Q1-spec'en [`2026-10-22-kims-upload-lagertal-q1.md`](2026-10-22-kims-upload-lagertal-q1.md) (63–77), som selv fortsætter efter tillæg 2026-10-20 (39–62). Kriterierne hed 60–80, da spec'en blev godkendt, og er flyttet 18 op, fordi tillæg 2026-10-20 fik kriterie 60–62 efter sin retest, og Q1-spec'en bruger 63–77. Henvisninger til de andre specs' kriterier (fx 44–46 for R2) er uændrede. "I dag" betyder HEAD `df886b4`.

### F1 – udledningen (enhedstest, `tests/product_enrichment_test.php`)

78. **Givet** `hsg_extract_cask_number(X)` for hver tekst X i tabellen, brugt præcis som den står og uden ekstra kontekst, **så** er resultatet som i kolonnen "Efter".

    | | X | I dag | Efter | Bemærkning |
    |---|---|---|---|---|
    | a | `Cask Strength` | `STRENGTH` | `null` | ord uden ciffer |
    | b | `Cask Finish` | `FINISH` | `null` | |
    | c | `Cask Craft` | `CRAFT` | `null` | |
    | d | `Cask No. 231584` | `231584` | `231584` | uændret |
    | e | `#200` | `200` | `200` | uændret |
    | f | `R10/123` | `null` | `null` | Bar tekst matcher ingen af mønstrene, som kræver cask/fad/barrel eller `#` foran |
    | g | `Cask R10/123` | `R10/123` | `R10/123` | uændret |
    | h | `#R10/123` | `R10/123` | `R10/123` | uændret |
    | i | `Batch #3` | `null` | `null` | Kun ét tegn efter `#`, og mønstret kræver mindst to |
    | j | `Batch #12` | `null` | `null` | batch-filteret (`:22`) |
    | k | `1st fill` | `null` | `null` | intet cask/fad/barrel eller `#` foran |
    | l | `Cask Strength, Cask No. 231584` | `STRENGTH` | `231584` | filter i løkken |
    | m | `Knockdhu, 10yo, 2012 - #43 - Virgin Oak Barrel Finish, 58,2%` | `FINISH` | `43` | startlagerets 12-1016 |
    | n | `Fadstyrke` | `STYRKE` | `null` | dansk ord |
    | o | `Barrel 1st Fill` | `1ST` | `1ST` | kendt begrænsning (afsnit 9) |
    | p | `''` | `null` | `null` | |
    | q | `Cask 2012` | `null` | `null` | årstalsfilteret (tilføjet efter review) |
    | r | `#2015 Cask Strength` | `STRENGTH` | `null` | årstal og ord uden ciffer (tilføjet efter review) |
    | s | `Cask 2012, #43` | `43` | `43` | årstallet springes over (tilføjet efter review) |
    | t | `#00` | `00` | `00` | `0` er et ciffer (tilføjet efter review) |
79. **Givet** `hsg_cask_number_candidates(X)` for hver X i tabellen i 78, **så**
    - er `[0] ?? null` lig kolonnen "I dag", så funktionen gengiver den gamle udledning præcist,
    - er alle elementer skrevet med store bogstaver,
    - er resultatet `['FINISH','43']` for m, `['STRENGTH','231584']` for l og `[]` for j og p.
80. **Givet** `hsg_product_parse_text(T)`, **så** gælder:
    - `Glenfarclas 15 Cask Strength, 60%`: `fields` og `confidence` har ingen nøgle `cask_number`, og `reason` indeholder ikke "fadnummer".
    - `MMD - Auchroisk, Cask Craft, Koval/Bourbon, 44,5%`: ingen `cask_number`.
    - `Clynelish 2013, Cask No. 231584, 56,1%`: `fields['cask_number'] === '231584'`, `confidence['cask_number'] === 99`, og `reason` indeholder "fadnummer aflæst direkte efter #/Cask No.".
    - `Knockdhu, 10yo, 2012 - #43 - Virgin Oak Barrel Finish, 58,2%`: `fields['cask_number'] === '43'`.

    Kriteriet dækker også backfillen (`migrations.php:109-124`), Excel-importen og installationen, som alle bruger `hsg_product_parse_text` og ikke ændres. Backfillen køres ikke i databasetesten, fordi `ensure_schema_updates` også omdøber alle produkter og importerer katalogbilleder til `uploads/`.
81. **Givet** `hsg_supplier_source_fields(kim_row(['sku'=>'20-300','name'=>'Glenfarclas 15 Cask Strength']), kim_map(false))`, **så** har resultatet ingen nøgle `cask_number`. **Givet** navnet `Glen Test Sherry Cask #100`, **så** er `cask_number === '100'` (uændret).
82. **Givet** de eksisterende tests i `tests/supplier_import_test.php` (bl.a. 19, 20, 26 og 44–46), **så** er de uændret grønne.

### F2 – oprydningen (databasetest, `tests/cask_number_cleanup_db_test.php`)

**Testdata.** Testen opretter disse produkter med `INSERT`. Navnene er valgt, så det nuværende navn enten giver eller ikke giver beviset:

| | SKU | `name` | `cask_number` før |
|---|---|---|---|
| A | TSTCASK-1 | `TSTCASK Glenfarclas 15 Cask Strength, 60%` | `STRENGTH` |
| B | TSTCASK-2 | `TSTCASK Knockdhu, 10yo, 2012 - #43 - Virgin Oak Barrel Finish, 58,2%` | `FINISH` |
| C | TSTCASK-3 | `TSTCASK Royal Brackla (2013) – 11 år – 54,6 %` | `FINISH` |
| D | TSTCASK-4 | `TSTCASK Glen Test – 12 år – 55 %` | `STRENGTH` |
| E | TSTCASK-5 | `TSTCASK Ardbeg Cask Strength` | `N/A` |
| F | TSTCASK-6 | `TSTCASK Clynelish 2013, Cask No. 231584` | `231584` |
| G | TSTCASK-7 | `TSTCASK Benriach Cask Strength` | `strength` |
| H | TSTCASK-8 / TSTCASK-9 | `TSTCASK Cask Strength Tom` | `NULL` / `''` |
| I | TSTCASK-10 | `TSTCASK Highland Park Cask Strength` | `' STRENGTH '` (mellemrum omkring; tilføjet efter review) |

Seed-kortet til kørsel 2 og 3 er `['TSTCASK-3' => ['Royal Brackla, 11yo, 2013 - #143 - Peated Whisky Cask Finish, 54,6%']]`.

83. **Givet** testdataene, **når** `hsg_cask_number_cleanup($pdo, [])` kaldes (kørsel 1, intet startlager), **så**
    - er A og I `NULL`, og B er `'43'`,
    - er C, D, E, F, G og H uændrede,
    - indeholder returværdien og audit-posten (kriterie 84) `cleared` for A `{old:'STRENGTH', new:null, source:'name'}` og for B `{old:'FINISH', new:'43', source:'name'}` og for I `{old:' STRENGTH ', new:null, source:'name'}` (den gemte værdi uden trim),
    - indeholder `kept` C `'FINISH'`, D `'STRENGTH'`, E `'N/A'` og G `'strength'`.

    **Når** funktionen derefter kaldes med seed-kortet (kørsel 2), **så**
    - er C `'143'`, og `cleared` indeholder præcis én TSTCASK-række: C `{old:'FINISH', new:'143', source:'initial_stock'}`,
    - indeholder `kept` D, E og G,
    - er A, B og I uændrede siden kørsel 1.
84. **Givet** hver kørsel i 83, **så** skrives præcis én ny række i `hsg_audit_log`. Testen ser kun på rækker med id over det højeste id ved teststart. Rækken har:
    - `action='product.cask_number_cleanup'`, `actor_type='system'`, `actor_id IS NULL`, `actor_name='System (opdatering)'`, `entity_type='product'`, `entity_id IS NULL` og `ip_hash IS NULL`,
    - `details_json` med samme `cleared` og `kept` som returværdien og `cleared_count`/`kept_count` lig længden af listerne. TSTCASK-rækkerne sammenlignes på `id`, `sku`, `old`, `new`, `source` og `value`.
    - F, TSTCASK-8 og TSTCASK-9 står ikke i nogen af listerne.
85. **Givet** tilstanden efter kørsel 2, **når** funktionen kaldes igen med seed-kortet (kørsel 3), **så**
    - er alle TSTCASK-værdier uændrede,
    - indeholder `cleared` ingen TSTCASK-række,
    - indeholder `kept` stadig D, E og G,
    - er der skrevet én ny audit-post (idempotens).
86. **Givet** startlagerets produkt **12-1016** i testdatabasen, som midlertidigt har `cask_number='FINISH'` og navnet `Knockdhu (2012) – 10 år – 58,2 %`, **når** `hsg_cask_number_cleanup($pdo)` kaldes **uden** seed-parameter, så den rigtige `initial-stock.json` læses, **så**
    - er `cask_number` `'43'`,
    - indeholder audit-posten `{sku:'12-1016', old:'FINISH', new:'43', source:'initial_stock'}`.

    Testen sætter produktets oprindelige navn og fadnummer tilbage i `finally`. Testen forudsætter startlageret (`run.sh` installerer med `seed_stock=1`). Findes 12-1016 ikke, fejler testen med en tydelig besked.
87. **Givet** den tomme testdatabase, som `run.sh` har installeret og røgtestet, før testen laver sine egne kald, **så**
    - er `hsg_module_versions.version` for `products` `'1.5.1'`,
    - findes **præcis én** audit-post `product.cask_number_cleanup` med `cleared_count` 0 og `kept_count` 0, fra første forespørgsel efter installationen. Røgtestens mange sidevisninger har altså ikke kørt den igen.
    - har ingen af startlagerets produkter et ikke-tomt `cask_number` uden ciffer,
    - har 12-1016, 12-1087, 12-1098 og 12-1104 `'43'`, `'143'`, `'71324'` og `'300223'`, mens 11-2010 og 18-1015 har et tomt `cask_number`.
88. **Givet** `modules/products/migrations.php` (enhedstest), **så**
    - returnerer filen et array med præcis nøglen `'1.5.1'`, og værdien er callable,
    - giver det ingen output og ingen nye funktioner at indlæse filen to gange,
    - er `version` i `modules/products/module.php` `'1.5.1'`.

### F3 – listen i Produkter (testerens HTTP- eller manuelle test)

89. **Givet** tre aktive produkter med fadnumrene `STRENGTH`, `N/A` og `strength`, ét inaktivt produkt med `FINISH` og ét produkt med `231584`, og ingen andre produkter uden ciffer, **når** admin åbner `products.php`, **så**
    - står knappen "Fadnr. uden ciffer (4)" mellem "Mangler fadnr." og "Negativt lager" og linker til `products.php?status=all&filter=cask_no_digit`,
    - viser listen bag linket præcis de fire produkter, men ikke `231584`, med hjælpeteksten fra afsnit 2.2,
    - viser hvert mobilkort linjen "Fadnr." med `#<værdi>`. Uden filteret har kortene ikke den linje.
90. **Givet** at intet produkt har et fadnummer uden ciffer, **så** vises knappen ikke, og `products.php?status=all&filter=cask_no_digit` svarer HTTP 200 med en tom liste.
91. **Givet** `products.php`, **så** er der ingen ny POST-handling. **Givet** en linkbruger uden `products.manage`, **så** afvises `products.php?filter=cask_no_digit` som i dag.
92. **Givet** `tests/run.sh`, **så** indeholder røgtestens sideliste `products.php?status=all&filter=cask_no_digit`, og siden svarer uden HTTP 500 og uden PHP-fejl.

### Generelt

93. **Når** `bash tests/run.sh` køres, **så** er alle trin grønne, inklusive de nye tests og de eksisterende enheds-, database- og flowtests af Kims uploadfil.
94. **Givet** modulmanifesterne, **så** er `products` `1.5.1`, og `supplier_upload` er uændret `1.1.1`. **Givet** dokumentationen, **så** beskriver `docs/SUPPLIER-UPLOAD.md`, `docs/PRODUCT-DATA-ASSISTANT.md` og `README.txt` reglen og oprydningen som angivet i afsnit 7.
95. **Givet** `git diff origin/feature/kims-upload-review-rettelser...HEAD -- core/supplier_import.php supplier_upload.php migrations.php import.php install.php` (altså kun denne branchs egne ændringer i forhold til basen), **så** er der ingen ændringer (R2 og backfillen er uændrede, F4). Branchen har merget basen frem til `3cb8b6f`, så `git diff df886b4` viser basens egen rettelse i `core/supplier_import.php` (`7b68a6f`, preview-token); den tæller ikke.
96. **Givet** den rigtige fil "lager 19-10-2026.xlsx" (manuel test; filen må ikke lægges i repoet), **når** previewet laves på `feature/kims-upload-review-rettelser` og på denne branch med samme kolonnevalg og reservationsvalg, **så**
    - er kolonnevalg, match og forvalg identiske,
    - har rækkerne 21, 22, 27, 28, 115, 159, 185 og 205 (fra testrapporten) ikke længere CRAFT eller FINISH i `source.cask_number`. De har intet eller et rigtigt nummer,
    - findes eventuelle forskelle i `changes` kun for `cask_number` på de rækker.

    Testeren skriver forskellene i PR'en.

### Valgfrie KAN-punkter (kun hvis de laves)

97. **KAN-1:** **Givet** Produkt-redigering, **så** står teksten fra afsnit 4 under feltet Fadnummer.
98. **KAN-2:** **Givet** `docs/ARCHITECTURE.md`, **så** forklarer afsnittet "Migrations", hvorfor engangsrettelser af data lægges som modulmigration.

### Rettelser efter review

99. **XSS:** **Givet** en admin, der åbner `products.php?status=%22%3E%3Cscript%3Ealert(1)%3C/script%3E`, **så** svarer siden HTTP 200 uden PHP-fejl, svaret indeholder ikke `"><script>alert(1)`, og listen viser aktive produkter som ved `status=active`. **Givet** `status` `active`, `inactive`, `discontinued` eller `all` eller ingen `status`, **så** er listen og knapperne som før. Alle steder, hvor `status` skrives i HTML på siden, bruger `h()`. Røgtesten i `tests/run.sh` har regressionstjekket.
100. **Mobiloverløb:** **Givet** et produkt med et fadnummer uden ciffer på 80 tegn uden mellemrum (fx 80 × `X`), **når** `products.php?status=all&filter=cask_no_digit` vises i 390 px bredde, **så** ombrydes værdien i linjen Fadnr., og siden kan ikke scrolles sidelæns (`scrollWidth` = `clientWidth`). Værdi-spanen har `overflow-wrap:anywhere;min-width:0;text-align:right`. Testerens manuelle test.

---

## 9. Uden for scope

- **Visningsfilter:** Kataloget/PDF'en, Lagerstatus, billedtjekket og Kims uploadfil viser og bruger stadig fadnumre uden ciffer, som ikke er ryddet (fx manuelt "N/A"), indtil admin retter dem via listen. Der kommer ikke et R2-lignende værn i billedtjekket (`image_tools.php:143`).
- **Validering i Produkt-redigering:** Et fadnummer uden ciffer afvises ikke. KAN-1 giver kun en hjælpetekst.
- **Datakvalitet:** Ryddede produkter får ikke automatisk "Ikke nødvendig", og `quality_approved_at` røres ikke.
- **Automatisk rydning af `kept`-værdier.** Det kræver et nyt svar på Q1.
- **Kendte svagheder i udledningen, som ikke ændres:**
  - "Barrel 1st Fill" giver `1ST`, fordi et ordenstal med ciffer efter cask/fad/barrel læses som fadnummer.
  - "Fadnummer 12" giver `null` (i dag NUMMER), fordi "nummer" ikke er med i mønstret. "Fad nr. 12" virker.
  - #-mønstret skelner ikke mellem et fadnummer og fx "Release #12" eller "Chapter #2". Kun batch, bottle og flaske filtreres fra (se også F1, Konsekvens).
  - Batch-filteret bruger et byte-offset fra `PREG_OFFSET_CAPTURE` som tegn-offset i `mb_substr` (`core/product_enrichment.php:19-21`). Står der æ, ø, å eller "–" før `#`, kan "Batch #12" derfor blive læst som fadnummer 12. **Anbefales som separat opfølgning.**
- **Excel-import** skriver altid `cask_number` på eksisterende produkter, også tomt, når filen ikke har kolonnen Fadnummer (`import.php:14`, `:55`). Det er uændret.
- **Navnestandardiseringen** (`migrations.php:265-275`) bruger navnet som destilleri, når destilleri er tomt. Det ser ud til at kunne forlænge sådanne navne ved hver opgradering. Det er observeret under undersøgelsen, men ikke verificeret, og bør undersøges separat.
- **Preview-formatet** i Kims uploadfil (Q3).

## 10. Åbne spørgsmål til brugeren (opsummering)

1. **Q1 – Omfanget af den automatiske oprydning.**
   - **A (anbefalet):** Ryd kun værdier med bevis (det nuværende navn eller startlagerets navn giver præcis værdien). Resten vises under Produkter → "Fadnr. uden ciffer", og admin retter selv. Det er sikkert, fordi manuelle værdier aldrig røres. Forventet er en håndfuld produkter på listen (fx 11-2007, 11-2011, 12-1124, 12-1128 og "Cask Strength"-produkter fra Kims fil). Det præcise tal kan ikke ses uden produktionsdatabasen.
   - **B:** Ryd også alle værdier i den gamle kodes form (kun store A–Z, `.`, `-` og `/`, 2–31 tegn). Det rammer flere, men også manuelt "N/A".
   - **C:** Ryd alt uden ciffer.

   B og C logger også alt i audit-posten, og update.php tager en FULL-backup først.
2. **Q2 – Genudledning.** Skal migrationen skrive det rigtige fadnummer, når samme tekst har et (12-1016 → 43, 12-1087 → 143, 12-1098 → 71324, 12-1104 → 300223)? **Anbefaling: ja.** Det er præcis det, den rettede udledning ville have gemt. Alternativet er kun at tømme feltet, så produkterne står under "Mangler fadnr.".
3. **Q3 – Gamle previews i Kims uploadfil.** Skal preview-formatet hæves fra 2 til 3, så et preview fra før opdateringen afvises? **Anbefaling: nej.** Previews er kortlivede, og det ville kræve ændringer og en ny version af `supplier_upload`. Release-noten beder om et nyt preview efter opdateringen, og værdier, der alligevel kommer tilbage, vises på listen.

---

## 11. Rettelser efter review (2026-10-22)

Testeren meldte KLAR, og revieweren godkendte med tre BØR-punkter. Brugeren bad om, at alle BØR-punkter blev rettet i denne branch, også XSS. Ændringer:

1. **XSS i Produkter (BØR 1, fandtes også på `main`):** `status` fra URL'en blev skrevet uescapet i href'en på "Mangler fadnr." og "Negativt lager". `$showStatus` whitelistes nu til `active`, `inactive`, `discontinued` eller `all` (alt andet giver `active`, som når parameteren mangler), og `h()` bruges alle steder, hvor værdien skrives. Gyldige værdier opfører sig som før. Kun ukendte eller tomme værdier viser nu aktive produkter i stedet for alle. Røgtesten har et regressionstjek. Kriterie 99.
2. **Mobiloverløb (BØR 2):** Værdi-spanen i linjen Fadnr. har `overflow-wrap:anywhere;min-width:0;text-align:right` (inline, ingen ny CSS-klasse). Kriterie 100.
3. **Testhuller (BØR 3 og testrapportens mutanter M4, M13 og M22):** Tabellen i kriterie 78 har fået rækkerne q–t (årstalsfilteret og `#00`). Testdataene i kriterie 83 har fået produkt I med `' STRENGTH '` (mellemrum omkring), som skal ryddes med den gemte værdi i `old`.
4. **Hjælpetekst:** Teksten under filteret sagde, at fadnumre uden ciffer "bruges ikke ved match", men billedtjekket bruger dem stadig. Nu står der, at de ikke bruges ved produktmatch i Kims uploadfil, men stadig vises i kataloget og bruges i billedtjekket, indtil de er rettet (afsnit 2.2).
5. **Omnummerering:** Kriterierne 60–80 hedder nu 78–98 (+18), fordi tillæg 2026-10-20 fik 60–62, og Q1-spec'en om lagertal bruger 63–77. Henvisninger til de andre specs' kriterier er uændrede. Testrapporten og tjeknavnene i testene er omnummereret tilsvarende.

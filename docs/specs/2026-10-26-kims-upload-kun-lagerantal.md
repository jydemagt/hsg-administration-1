# Kims uploadfil – "Kun lagerantal" og arkivering af reservationer, der ikke længere har lager

- **Dato:** 2026-10-26
- **Status:** Godkendt af brugeren 2026-10-26 med designerens anbefalinger på alle syv spørgsmål i afsnit 0. Arkivering altid, pr. vare i alt, kun ved 0, ny status `archived`, forvalgt på sikre rækker, "Kun lagerantal" huskes (standard "Produktdata og lager"), ingen genåbning og ingen særregel for nye reservationer.
- **Brugerens ønske (ordret):** "kan du lave en funktion hvor man vælger kun at lave upload af lagerantal fra kims fil? hvis der er nogen reservationer på vare som i uploadfilen er 0 skal disse reservationer arkiveres."
- **Brugerens præcisering (ordret):** "jeg mener arkiver reservationer hvor der ikke længere er lager". Reservationer arkiveres altså, når der ifølge Kims fil ikke længere er lager til dem. Spec'en oversætter det til: Kims lager i alt for varen er 0 (Q2 og Q2b).
- **Grundlag:** `main` = v11.2.0 (8c822fe). Modulerne `supplier_upload` 1.1.2 og `reservations` 1.2.0.
- **Bygger på:**
  - Hovedspec [`2026-10-19-kims-upload-lagerfejl.md`](2026-10-19-kims-upload-lagerfejl.md)
  - Tillæg [`2026-10-20-kims-upload-review-rettelser.md`](2026-10-20-kims-upload-review-rettelser.md) og [`2026-10-22-kims-upload-lagertal-q1.md`](2026-10-22-kims-upload-lagertal-q1.md)

  Alt, hvad der ikke ændres her, gælder uændret: kolonnegæt, lagercellen, tomme lokationsceller, kontroltal, tilstand L/T, bogstav-fletning og reservationsvalget `add`/`none`.
- **Ændrer bevidst:** Previewet i hovedspec'ens kriterie 27 og 28 (Octomore med Kims tal 0 og 2 reserverede). Reservationerne foreslås nu arkiveret (se 2.6 og kriterie 162).
- **Moduler:** `supplier_upload` ("Kims uploadfil", capability `imports.manage`) og `reservations` (ny status).
- **Branch:** `feature/kims-upload-kun-lagerantal`
- **Kode:** `core/supplier_import.php`, `supplier_upload.php`, `reservations.php` og `functions.php`. Linjenumrene passer på 8c822fe og er omtrentlige.

---

## 0. Åbne spørgsmål (besvares ved godkendelsen)

1. **Q1 – Gælder arkiveringen altid eller kun i "Kun lagerantal"?**
   **Anbefaling: altid**, dvs. i begge tilstande. Begrundelse:
   - Begge tilstande skriver lager. Samme fil skal give samme lagertal, uanset om produktdata også opdateres. Ellers giver "Produktdata og lager" spøgelsesflasker (2.5), mens "Kun lagerantal" ikke gør.
   - Arkiveringen vises altid i previewet og kan fravælges pr. reservation.

   *Hvis "kun i Kun lagerantal":* Arkiveringslisten beregnes og vises kun i den tilstand. Reglerne er ellers de samme, og de eksisterende tjek i kriterie 162 skal ikke ændres.
2. **Q2 – Vurderes "ikke længere lager" pr. vare i alt eller pr. lokation?**
   Din præcisering er, at reservationer arkiveres, når der ifølge Kims fil ikke længere er lager til dem. **Anbefaling: pr. vare i alt.** En reservation har ikke længere lager bag sig, når varen er 0 i alt hos Kim.

   | | Fil | HSG før | Pr. vare i alt (anbefalet) | Pr. lokation |
   |---|---|---|---|---|
   | A | Christiansfeld 0, Michael tom, Gert 0, i alt 0 | Gert fysisk 2, to reservationer på 1 stk. hos Gert | Begge arkiveres. Gert 2 → 0 | Samme |
   | B | Christiansfeld 5, Michael tom, Gert 0, i alt 5 | Christiansfeld 5, Gert 1, én reservation på 1 stk. hos Gert | Intet arkiveres. Gert forbliver 1 (0 + 1 med `add`) | Reservationen arkiveres. Gert 1 → 0 |

   Begrundelse:
   - **Der er stadig lager til reservationen i eksempel B.** Varen har 5 flasker i Christiansfeld, og en af dem kan flyttes til Gert med "Flyt" under Lager. Reservationen kan altså opfyldes, og så er der ikke tale om "ikke længere lager".
   - **Kims fordeling på lokationer er usikker.** Kims formel er R = Q − T − S, så Christiansfeld er "resten". Q1b fra 10-19 er stadig ubesvaret: Kim kan have trukket Gerts reserverede flaske fra Christiansfeld, så "Gert 0" beviser ikke, at reservationen hos Gert er uden flaske.
   - **Totalen 0 er entydig.** Varen er udsolgt hos Kim, og ingen af reservationerne har flasker bag sig.

   *Hvis "pr. lokation":* Betingelsen i 2.2 vurderes for hver lokation, og kun reservationer på lokationer med entydigt 0 bliver kandidater. Eksempel B arkiveres så.
3. **Q2b – Hvad når Kims tal er lavere end det reserverede, men ikke 0 (delvist lager)?**
   Eksempel C: Kims tal i alt er 1, og der er tre reservationer på 1 stk. hos Christiansfeld (oprettet 1/10, 5/10 og 20/10). HSG har fysisk 4.

   **Anbefaling: arkivér kun, når Kims tal er 0.** Begrundelse:
   - **Med `add`** (standard, og du har bekræftet, at Kim regner reserverede flasker som solgt) er Kims 1 det, der er tilbage *efter* reservationerne. Alle tre reservationer har stadig flasker bag sig: Fysisk = 1 + 3 = 4, og Disponibelt = 1. At arkivere to af dem ville fjerne reservationer på flasker, der findes.
   - **Kun med `none`** (Kims tal er det fysiske lager) mangler der flasker til to af reservationerne. Det advarer previewet allerede om i dag: "Christiansfeld får 1 fysisk efter import, men 3 er reserveret …".
   - **Ved 0 er der ingen tvivl**, og det er nok til at fjerne spøgelsesflaskerne (2.5).
   - **Et delvist valg kræver to ekstra regler:** hvilke reservationer der skal ud, og hvad der skal ske med en reservation på fx 2 stk., når overskuddet kun er 1. Den kan ikke deles uden at ændre dens antal.

   Med anbefalingen sker der i eksempel C intet nyt:
   - `add`: Fysisk forbliver 4.
   - `none`: Fysisk bliver 1, og advarslen vises som i dag.

   *Hvis "ja, også ved delvist lager":* Anbefalet regel:
   - Overskuddet (reserveret i alt − Kims tal i alt) arkiveres som **hele reservationer, nyeste først**. De ældste reservationer har førsteret til de flasker, der er tilbage. I eksempel C foreslås reservationerne fra 20/10 og 5/10.
   - En reservation deles aldrig. Er den større end det resterende overskud, stopper udvælgelsen, og varen får en bemærkning.
   - Kandidaterne er **aldrig forvalgt**, fordi de kræver vurdering.
   - Reglen foreslås kun ved `none`. Ved `add` er Kims tal efter reservationerne, så der er intet overskud.

   Den variant kræver et tillæg med egne kriterier og er ikke beskrevet i detaljer her.
4. **Q3 – Hvad betyder "arkiveret"?**
   **Anbefaling: en ny status `archived` ("Arkiveret")**. Den kræver en migration, fordi kolonnen er en ENUM (afsnit 5). Alternativerne er:
   - `completed` ("Solgt/afsluttet"): ingen migration, men arkiverede reservationer kan så ikke skelnes fra salg, der er registreret i HSG med en salgsbevægelse.
   - `cancelled` ("Annulleret"): forkert betydning, for kunden har ikke annulleret.

   Alle forespørgsler i systemet tæller kun `status='reserved'` som reserveret, så den nye status er sikker alle steder (afsnit 5.2).
5. **Q4 – Skal arkiveringen være valgt på forhånd?**
   **Anbefaling: ja, på rækker, der selv er valgt på forhånd** (sikkert match ≥ 90 % uden flag). På rækker, der kræver vurdering, er den ikke valgt.

   Bemærk: Den 19-10 (kriterie 27) ville du kunne markere Octomore-reservationerne som solgt efter uploaden. Med denne funktion foreslås de i stedet arkiveret, når Kims tal er 0.

   *Hvis "nej, aldrig forvalgt":* Previewets lagertal beregnes uden arkivering som i dag. De eksisterende tjek i kriterie 162 skal så ikke ændres.
6. **Q5 – Skal valget "Kun lagerantal" huskes til næste upload?**
   **Anbefaling: ja**, som kolonnevalg og reservationsvalg. Standarden er "Produktdata og lager", som er dagens adfærd. Valget står tydeligt på upload-kortet og i previewet.
7. **Q6 – Skal en arkiveret reservation kunne genåbnes?**
   **Anbefaling: nej, ikke nu.** `reservations.php` får en fane "Arkiveret", hvor de arkiverede reservationer kan ses. Er en reservation arkiveret ved en fejl, rettes lageret, og der oprettes en ny reservation.
8. **Q7 – Hvad med reservationer, der er oprettet efter Kims ark** (Q1c fra 10-19)?
   **Anbefaling: ingen særregel.** Oprettelsesdatoen står ud for hver reservation i previewet, så den kan fravælges.

---

## 1. Formål

**For admin, der uploader Kims fil:**
- Admin kan vælge **"Kun lagerantal"**. Så opdaterer filen kun lager pr. lokation. Den ændrer ingen produktdata og opretter ingen nye produkter. Det er den hyppige opgave: Kims lagertal skal ind, men produktkortene i HSG er allerede rettet til og må ikke røres.
- Har en vare **ikke længere lager** i Kims fil (0 i alt), foreslås varens aktive reservationer **arkiveret**, fordi der ikke er flasker bag dem. Så tæller de ikke længere som reserveret, og der står ingen spøgelsesflasker i HSG.

**For lagermedarbejdere og linkbrugere:**
- Reservationslisten ryddes for reservationer på udsolgte varer.
- Disponibelt og Lagerstatus passer med Kims tal.

**Spøgelsesflasker i dag:** Med reservationsvalget `add` (standard) bliver en vare med Kims tal 0 og 2 reserverede til Fysisk 2, Reserveret 2 og Disponibelt 0. Er varen udsolgt hos Kim, står de 2 flasker i HSG, selvom de ikke findes. Reservationerne bliver aldrig afsluttet.

---

## 2. Regler

### 2.1 "Kun lagerantal" (`import_scope`)

| Værdi | Tekst | Hvad sker der |
|---|---|---|
| `full` (standard) | **Produktdata og lager** | Som i dag |
| `stock_only` | **Kun lagerantal** | Kun lager pr. lokation (se nedenfor) |

**I tilstanden `stock_only` gælder:**
- **Matchning, fletning og bemærkninger er uændrede.** Varenummer, navn, destilleri, fadnummer osv. bruges stadig til at finde varen i HSG og til bogstav-fletning. De bliver bare ikke skrevet.
- **Ændringer:** Kun `stock_loc_*`, plus `stock_quantity` på den ældre vej uden lokationer. Ingen andre felter ændres i `lager_products`: navn, priser, fadnummer, fadtype, ABV, destilleri, alder, årgang, kategori, land, flaskestørrelse, antal flasker og brand.
- **Lageret skrives efter de eksisterende regler:** tilstand L/T, lagercellen, tomme celler, kontroltal, bogstav-fletning og reservationsvalget.
- **Ingen nye produkter.**
  - En vare uden match (`match.id = 0`) får `skipped = true`, `is_create_new = false` og `selected = false` og bemærkningen fra 4.4.
  - Dropdown'en tilbyder ikke "Opret som nyt produkt".
  - Ved "Anvend" springes en markeret række uden produkt over, også hvis POST'en indeholder `create_new`.
- **Admin kan stadig vælge et HSG-produkt manuelt** i dropdown'en. Så skrives kun lageret på det produkt.
- **Uændret:**
  - Varenummer-koblingen (`hsg_supplier_remember_alias`) gemmes som i dag. Den ligger i alias-tabellen og er ikke produktdata.
  - `hsg_sync_product_stock_status` kører som i dag. Status aktiv/inaktiv følger disponibelt lager.
- **Forvalg:** Rækken er valgt på forhånd, når den har et sikkert match (2.6) og enten mindst én lagerændring eller mindst én forvalgt reservation til arkivering.
- **Tilstand og anvendelse:** Tilstanden står i previewet (`preview.import_scope`). "Anvend" bruger previewets tilstand, ikke en værdi fra POST'en.

**Valget huskes** i `hsg_settings` under nøglen `supplier_import_scope`. Det gemmes tre steder:
- ved upload (valget på upload-kortet)
- når det ændres i previewet (`action=import_scope`)
- ved "Anvend"

Upload-kortet forvælger den gemte værdi. Er nøglen tom eller ukendt, er værdien `full`.

### 2.2 Hvornår er der "ikke længere lager"? (arkiveringsbetingelsen)

Brugerens præcisering ("arkiver reservationer hvor der ikke længere er lager") omsættes sådan:
- Der er ikke længere lager, når **Kims lager i alt for varen entydigt er 0** (Q2).
- **Delvist lager giver ingen arkivering** (Q2b). Det gælder, når Kims tal er over 0, men lavere end det reserverede. Her gælder reglerne fra i dag:
  - `add` lægger reservationerne oven i.
  - `none` skriver Kims tal og advarer, når det er lavere end det reserverede.

Ny ren funktion: `hsg_supplier_stock_file_is_zero(array $stockFile): bool`. Den bruger `items[].stock_file` og er kun sand, når Kims tal for varen **entydigt** er 0:

| `stock_file.mode` | Sand, når |
|---|---|
| `locations` (tilstand L) | `locations` er ikke tom, **og** `invalid` er tom, **og** `total_invalid` er `null`, **og** `sum` = 0, **og** (`total` er `null` eller `total` = 0) |
| `total` (tilstand T) | `locations` er ikke tom (første lokation), **og** `total` = 0, **og** `total_invalid` er `null` |
| `none`, manglende eller ukendt | Aldrig |

**Konsekvenser:**
- **Varer, der ikke står i filen,** har ingen `stock_file` og får aldrig arkiveret reservationer. Det gælder også varer uden for brand-filteret og varer, hvis række ikke kunne matches.
- **Ukendte tal** giver aldrig arkivering:
  - alle lokationsceller er tomme,
  - en ugyldig lokationscelle,
  - en ugyldig total,
  - en kontrolafvigelse.
- **Tomme celler:** En tom lokationscelle tæller som 0 efter den godkendte regel, når rækken har et tal i en anden lokationskolonne. Den kan derfor indgå i "entydigt 0".
- **Flettede varer** (17-1028 + 17-1028a) bruger summen af rækkerne, som i dag.

### 2.3 Hvilke reservationer foreslås arkiveret?

For en vare med et matchet HSG-produkt, hvor 2.2 er sand:
- **Kandidater:** alle reservationer med `status='reserved'` på produktet, **som ligger på en lokation, der får et tal fra filen**. Det er nøglerne i `stock_file.locations`:
  - i tilstand L: de valgte lokationskolonner
  - i tilstand T: første lokation
- **Reservationer på andre lokationer** røres ikke. Varen får en bemærkning (4.4). Begrundelsen er, at importen heller ikke ændrer lageret på de lokationer, så filen siger intet om dem.
- **Rækkefølge:** Kandidaterne vises sorteret efter `created_at` og derefter `id`.

### 2.4 Status "Arkiveret"

- Ny ENUM-værdi `archived` i `lager_reservations.status`. Labelen er **"Arkiveret"**.
- Kun importens "Anvend" sætter `archived`, og kun fra `reserved`.
- **Arkiveringen ændrer kun status.** `updated_at` opdateres automatisk. Der skrives intet i `lager_stock` og ingen række i `lager_stock_movements`. Fysisk lager nedskrives altså **ikke** af arkiveringen. Kims 0 er allerede efter salget.
- **Vagt:** `hsg_supplier_archive_reservations` tjekker én gang pr. kald i `information_schema.COLUMNS`, at `COLUMN_TYPE` for `lager_reservations.status` indeholder `'archived'`. Hvis ikke, kastes `RuntimeException` med teksten "Databasen kan ikke gemme status „Arkiveret“ endnu. Genindlæs siden, så opdateringen kan køre, og prøv igen." Hele "Anvend" rulles så tilbage.

  Begrundelse: Uden strict-mode gemmer MySQL en ukendt ENUM-værdi som `''`.

### 2.5 Lagerberegning sammen med `add` og `none`

For hver lokation L, der får et tal fra filen:

| Reservationsvalg | Fysisk(L) efter "Anvend" |
|---|---|
| `add` | Kims tal(L) + aktive reservationer på L, **som ikke arkiveres i samme "Anvend"** |
| `none` | Kims tal(L), uændret fra i dag |

Med `add` bortfalder tillægget for de arkiverede reservationer. Ellers stod de som spøgelsesflasker.

**Eksempler** (Gert, Kims tal 0, to reservationer på 1 stk. hos Gert):

| HSG før | Valg | Uden arkivering (i dag) | Begge arkiveres | Kun den ene arkiveres |
|---|---|---|---|---|
| Fysisk 2 | `add` | 2 → 2, ingen bevægelse, disponibelt 0 | 2 → 0, bevægelse −2, disponibelt 0 | 2 → 1, bevægelse −1, disponibelt 0 |
| Fysisk 2 | `none` | 2 → 0, disponibelt −2 og advarsel | 2 → 0, disponibelt 0 | 2 → 0, disponibelt −1 og advarsel |
| Fysisk 0 (fx efter `none`) | `add` | 0 → 2 | 0 → 0, ingen bevægelse | 0 → 1 |

Lagerbevægelsen er importens egen (`movement_type='import'`), som i dag. Der skrives aldrig en `sale`-bevægelse.

**Reference på lagerbevægelsen** (N = reservationstillæg > 0, M = arkiverede stk. på lokationen > 0):

| | Reference |
|---|---|
| N og M | `Kims masterfil opdatering (+N reserveret, M arkiveret)` |
| Kun N | `Kims masterfil opdatering (+N reserveret)` (uændret) |
| Kun M | `Kims masterfil opdatering (M arkiveret)` |
| Ingen af dem | `Kims masterfil opdatering` (uændret) |

### 2.6 Forvalg og previewets tal

- **Sikkert match:** `match.id > 0`, `match.score ≥ 90` og ingen flag. Det er samme betingelse som for forvalg af rækker i dag.
- **Forvalgt arkivering:** `archive.default = sikkert match`. Alle kandidater på en vare er enten forvalgt eller ikke forvalgt. Hver reservation har sit eget afkrydsningsfelt.
- **Rækkens forvalg ("Opdatér"):** `!flags && ((sikkert match && (ændringer > 0 || archive.default)) || is_create_new)`. I `stock_only` er `is_create_new` altid falsk.
- **Previewets tal bygger på forvalget:**
  - `changes` beregnes, som om de forvalgte reservationer er arkiveret. `reserved_loc_<id>` sænkes med de forvalgte stk. på lokationen, dog aldrig under 0.
  - En lagerændring på en lokation med forvalgt arkivering får nøglen `archived` (stk.). Nøglernes rækkefølge er `old`, `new`, `file`, `reserved` (kun > 0) og `archived` (kun > 0).
  - Advarslen ved `none` ("… får 0 fysisk efter import, men 2 er reserveret …") og bemærkningen i tilstand T ("… så lageret i alt bliver X") bruger også de reserverede tal efter forvalget.
- **Fravalg i browseren genberegner ikke previewet.** Der bruges intet JavaScript. Teksten under listen (4.4) forklarer, hvad et fravalg betyder. "Anvend" beregner altid ud fra de faktisk afkrydsede reservationer.
- **`items[].reserved`** ("Reserveret i HSG: …") viser stadig alle aktive reservationer, også dem, der foreslås arkiveret.

### 2.7 Rækkefølge ved "Anvend" (én transaktion)

I den eksisterende transaktion (`supplier_upload.php` ca. :131) gøres følgende for hver markeret række i rækkefølge:

1. **Produktet** findes som i dag (`product_<idx>` eller previewets match). I `stock_only` springes rækken over (tælles i `rows_skipped`), hvis den ikke har et produkt eller er sendt som `create_new`.
   - *Tillæg efter review (B1):* "– Intet match –" (feltet `product_<idx>` er sendt tomt) springer altid rækken over i begge tilstande, også når previewet har et match – intet skrives, og intet arkiveres. Kun når feltet mangler helt i POST'en, bruges previewets match. Undtagelsen er uændret: i `full` opretter en tom værdi på en række uden match med `is_create_new` et nyt produkt.
2. **Reservationer til arkivering:** Hvis det valgte produkt er lig `item.match.id`, og `item.archive` ikke er `null`:
   - `ids` = de postede `archive_res[]` ∩ id'erne i `item.archive.reservations`.

   Hvis admin har valgt et andet produkt end previewets match, arkiveres intet for rækken, og id'erne tælles som "ikke arkiveret".

   Postede id'er, der ikke er kandidater på en markeret række, ignoreres uden besked. Det gælder fx afkrydsede reservationer på en række, der ikke er markeret, og id'er, som previewet aldrig har foreslået.
3. **`hsg_supplier_archive_reservations($pdo, $pid, $ids, $locationIds)`**, hvor `$locationIds` er lokations-id'erne fra `item.stock_file.locations`:
   - Låser rækkerne (`SELECT … FOR UPDATE`).
   - Arkiverer kun dem, der stadig har `status='reserved'`, `product_id = $pid` og en lokation i `$locationIds`.
   - Skriver med `UPDATE … SET status='archived' WHERE id=? AND status='reserved'`.
   - Returnerer de arkiverede rækker.
   - Kandidater, der ikke længere er aktive, tælles som "ikke arkiveret". Det sker fx, hvis en reservation er markeret solgt eller annulleret efter previewet.
4. **`hsg_supplier_apply_product`** kaldes som i dag med optionerne `add_reserved`, `stock_only` og `archived_by_location` ([lokations-id ⇒ stk.]). Funktionen læser de aktive reservationer igen, og de arkiverede tæller ikke længere med. Derefter skrives lager og bevægelse efter 2.5.
5. **Status:** Er der arkiveret mindst én reservation, kaldes `hsg_sync_product_stock_status($pdo, $pid)`. Det sker også, selv om `hsg_supplier_apply_product` ikke fandt nogen ændringer.
6. **Alias** gemmes som i dag.

Efter løkken, stadig i transaktionen:
- kolonnevalg, reservationsvalg og `supplier_import_scope` gemmes
- importkørslen indsættes
- `supplier_import.apply` logges
- én `reservation.archive` logges pr. arkiveret reservation med kørslens id

Derefter følger commit. **Efter commit** kaldes `hsg_do_action('reservation.archived', …)` pr. reservation, og flash-beskederne vises (4.5).

En fejl undervejs ruller alt tilbage, også arkiveringen.

**Idempotent:** Uploades samme fil igen, er de arkiverede reservationer ikke længere aktive. Varen har så ingen kandidater, Fysisk = Kims tal + 0 = det, der allerede står, og der kommer ingen ændring, ingen bevægelse og ingen ny arkivering.

---

## 3. Brugerflow

1. Admin åbner **Kims uploadfil**. Under "Hvad skal opdateres?" er det huskede valg forvalgt. Admin vælger fx **"Kun lagerantal"** og en fil og trykker **"Analysér fil og vis preview"**.
2. Previewet viser:
   - tilstanden og et nyt valg til at skifte den,
   - reservationsvalget (som i dag),
   - en opsummering: hvor mange varer der matcher, hvor mange der springes over, og hvor mange reservationer der foreslås arkiveret.
3. Admin kan skifte tilstand og trykke **"Genberegn preview"**. Valget gemmes og logges.
4. Admin gennemgår rækkerne. Hver vare viser:
   - i "Kun lagerantal" kun lagerlinjer,
   - "Fil: …" og "Reserveret i HSG: …" som i dag,
   - for varer uden lager (Kims lager i alt 0): listen **"Arkivér reservationer – Kims lager i alt er 0:"** med et afkrydsningsfelt pr. reservation.

   Varer uden match er markeret **"Springes over"** i "Kun lagerantal".
5. Admin fravælger eventuelt reservationer, der ikke skal arkiveres (fx en kunde, der ikke har hentet flasken endnu). Admin kan også fravælge hele rækker.
6. Admin trykker **"Anvend valgte ændringer"**. Lager, arkivering, audit og hooks udføres som i 2.7.
7. Flash-beskeden fortæller antal opdaterede produkter, tillagte reserverede flasker, arkiverede reservationer og rækker, der er sprunget over.
8. De arkiverede reservationer kan ses under **Reservationer → Arkiveret**.

---

## 4. Skærme

Alle tekster er på dansk. Der indføres ingen nye CSS-klasser. Der genbruges:
- `.card`, `.muted`, `.badge`, `.badge.amber`, `.inline`, `.check`, `.actions`, `.button.secondary` og `.table-wrap`,
- reglen om, at bemærkninger, der starter med "Bemærk", vises med `color:var(--amber);font-weight:600`.

Afkrydsningsfelter i en `label.check` får samme inline-stil som i `newsletter_recipients.php:167`: `style="width:auto;min-height:0;margin:0"`.

### 4.1 Upload-kortet (`supplier_upload.php` ca. :235-245)

```
┌ Upload Kims masterfil ──────────────────────────────────────────────┐
│ Upload masterfil (Excel eller CSV). … (ny brødtekst, se nedenfor)   │
│ Excel/CSV [Vælg fil]        Begræns til brand/leverandør [▾]        │
│ Hvad skal opdateres?                                                │
│ [Kun lagerantal                                        ▾]           │
│ Kun lagerantal ændrer ingen produktdata og opretter ingen nye       │
│ produkter. Varer uden match springes over.                          │
│ [Analysér fil og vis preview]                                       │
└─────────────────────────────────────────────────────────────────────┘
```

- Ny `<label>Hvad skal opdateres?<select name="import_scope">` i upload-formularen med `full` = "Produktdata og lager" og `stock_only` = "Kun lagerantal". Den gemte værdi er `selected`.
- `<small class="muted">` under feltet: "Kun lagerantal ændrer ingen produktdata og opretter ingen nye produkter. Varer uden match springes over."
- **Ny brødtekst:**
  > Upload masterfil (Excel eller CSV). Filen opdaterer produktdata og lager i HSG Administration – eller kun lageret, hvis du vælger "Kun lagerantal". Varenumre med et bogstav til sidst (fx 17-1028a) flettes ind i varenummeret uden bogstav (17-1028), og lagerantallet lægges sammen – men kun når navn og destilleri passer. Er det en anden whisky, står varen alene med en bemærkning. Har en vare ikke længere lager i filen (0 i alt), foreslås dens aktive reservationer arkiveret. Kolonnevalg, reservationsvalg og valget af, hvad der opdateres, huskes fra seneste upload.

### 4.2 Preview-kortet (ca. :262-273)

```
┌ Preview: lager 26-10-2026.xlsx ──────────────────────────────────────┐
│ Fundet i Ark 1, overskriftsrække 1. Genkendte felter: …              │
│ Lager i filen: 982 stk. i alt · Sum af lokationskolonner: 982 stk.   │
│ Aktive reservationer i HSG på matchede varer: 14 stk.                │
│ Kun lagerantal: 88 varer matcher et HSG-produkt · 4 varer springes   │
│ over (findes ikke i HSG).                                            │
│ Arkiveres: 3 reservationer (4 stk.) på 2 varer, fordi Kims lager i   │
│ alt er 0. Du kan fravælge dem på de enkelte varer. 1 reservation     │
│ (1 stk.) på varer, der kræver din vurdering, er ikke valgt.          │
│                                                                      │
│ Hvad skal opdateres?                                                 │
│ [Kun lagerantal                     ▾] [Genberegn preview]           │
│ Reservationer                                                        │
│ [Kims tal er efter reservationer – læg dem oven i ▾] [Genberegn …]   │
│ (eksisterende hjælpetekst og 90–100 %-linje)                         │
└──────────────────────────────────────────────────────────────────────┘
```

- **Linjen "Kun lagerantal: …"** vises kun i `stock_only`:
  - "Kun lagerantal: N varer matcher et HSG-produkt · K varer springes over (findes ikke i HSG)."
  - N = antal varer med `match.id > 0`, og K = antal varer med `skipped`.
  - Ental hedder "1 vare".
- **Linjen "Arkiveres: …"** vises kun, når mindst én vare har `archive` ≠ `null`:
  - "Arkiveres: X reservationer (Y stk.) på Z varer, fordi Kims lager i alt er 0. Du kan fravælge dem på de enkelte varer."
  - Tal for forvalgte kandidater.
  - Er der kandidater, der ikke er forvalgt, tilføjes: " U reservationer (V stk.) på varer, der kræver din vurdering, er ikke valgt."
  - Ental hedder "1 reservation" og "1 vare".
- **Nyt valg:** en lille formular med `.inline`, placeret før reservationsvalget: `action=import_scope`, `preview_token`, `csrf_field()`, `<label>Hvad skal opdateres?<select name="import_scope">` og `<button class="button secondary">Genberegn preview</button>`.
  - `<small class="muted">` under: "Kun lagerantal ændrer ingen produktdata og opretter ingen nye produkter. Varer uden match springes over."
- **Ingen lagerkolonner:** Er `stock_only` valgt, og er der ingen lagerkolonner (`stock_file.mode = 'none'`), vises dette afsnit med amber-stil: "Bemærk: Der er ikke valgt nogen lagerkolonner, så Kun lagerantal ændrer intet. Vælg lagerkolonnerne under Kolonnemapping."

  Afsnittet bruger `<p … style="color:var(--amber);font-weight:600">` og **ikke** `.flash`. Flowtestens `flashes()` læser `<div class="flash …">`.

### 4.3 Kolonnemapping-panelet

Kun i `stock_only` tilføjes en `<p class="muted">` efter teksten om lokationskolonner: "I Kun lagerantal bruges de øvrige kolonner kun til at finde varen i HSG."

### 4.4 Preview-tabellen (ca. :317-351)

Kolonnerne er uændrede: Opdatér | Filrække | Leverandørens vare | Match i HSG | Foreslåede ændringer.

**Vare med arkiveringsliste** (tilstand L, `add`):

```
☑ │ #57 │ Spøgelse Whisky │ [100 %] SKU er identisk   │ • Lagerantal: Gert: 2 → 0 (fil 0, 2 reserveret arkiveres)
  │     │ SKU 50-1        │ [50-1 · Spøgelse Whisky ▾] │ Fil: Christiansfeld 0 · Michael 0 (tom) · Gert 0 · i alt 0
  │     │                 │                           │ Reserveret i HSG: Gert 2
  │     │                 │                           │ Arkivér reservationer – Kims lager i alt er 0:
  │     │                 │                           │ ☑ #9001 · Gert · 1 stk. · Hansen · Ordre 1047 · oprettet 2026-10-01
  │     │                 │                           │ ☑ #9002 · Gert · 1 stk. · Jensen · oprettet 2026-10-03
  │     │                 │                           │ Arkiveres kun, når rækken er markeret under "Opdatér". Lageret
  │     │                 │                           │ nedskrives ikke ved arkiveringen. En fravalgt reservation forbliver
  │     │                 │                           │ aktiv og lægges oven i Kims tal.
```

- **Ændringslinjen for lager**, hvor `archived > 0`:
  - Uden tillæg: `(fil F, M reserveret arkiveres)`
  - Med tillæg: `(fil F + N reserveret, M arkiveres)`
  - Ellers er linjen uændret: `(fil F + N reserveret)`.
- **Arkiveringslisten** vises under "Reserveret i HSG", når `item.archive` ≠ `null`:
  - Overskrift: `<br><small><strong>Arkivér reservationer – Kims lager i alt er 0:</strong></small>`
  - En linje pr. reservation: `<label class="check"><input type="checkbox" name="archive_res[]" value="<id>" [checked]> #<id> · <lokation> · <antal> stk. · <kunde> · <reference> · oprettet <ÅÅÅÅ-MM-DD></label>`
    - Kunde og reference udelades, når de er tomme.
    - Datoen er de første 10 tegn af `created_at`.
    - `checked`, når `archive.default` er sand.
    - Alle værdier escapes med `h()`.
  - Hjælpetekst med `<small class="muted">`:
    - `add`: "Arkiveres kun, når rækken er markeret under "Opdatér". Lageret nedskrives ikke ved arkiveringen. En fravalgt reservation forbliver aktiv og lægges oven i Kims tal."
    - `none`: "Arkiveres kun, når rækken er markeret under "Opdatér". Lageret nedskrives ikke ved arkiveringen. En fravalgt reservation forbliver aktiv, og disponibelt bliver negativt."
- **"Kun lagerantal", vare uden match:**
  ```
  ☐ │ #91 │ Helt Ny Whisky │ [Springes over] For usikkert: svagt match              │ Ingen ændringer fundet
    │     │ SKU 50-9       │ Bemærk: Springes over – varen findes ikke i HSG, og …  │ Fil: Christiansfeld 4 · …
    │     │                │ [– Intet match (springes over) – ▾]                    │
  ```
  - Badge: `<span class="badge amber">Springes over</span>` i stedet for "Nyt produkt oprettes (Lager: N)" eller score-badget. Matchets `reason` vises som i dag.
  - I `stock_only` har dropdown'en på **alle** rækker ingen `create_new`-mulighed. Første mulighed er "– Intet match (springes over) –" (værdi `''`).
- **Nye bemærkninger** (`items[].notes`):

  | Situation | Tekst |
  |---|---|
  | `stock_only` og intet match | `Bemærk: Springes over – varen findes ikke i HSG, og Kun lagerantal opretter ikke nye produkter. Vælg et HSG-produkt manuelt, hvis varen findes under et andet varenummer.` |
  | Kims lager i alt er 0, og der er aktive reservationer på en lokation, der ikke får et tal fra filen (én bemærkning pr. lokation) | `Bemærk: 1 reservation på Gert arkiveres ikke, fordi lokationen ikke får et tal fra filen.` (flertal: "2 reservationer på …") |

### 4.5 Bundtekst og flash-beskeder

**Bundteksten** (ca. :350):
- Den eksisterende tekst beholdes, og der tilføjes: " Markerede reservationer arkiveres kun på markerede rækker."
- I `stock_only` står denne sætning før: "Kun lagerantal: Kun lager pr. lokation opdateres. Produktdata ændres ikke, og der oprettes ingen nye produkter."

**Flash-beskeder:**

| Hændelse | Type | Tekst |
|---|---|---|
| `import_scope` ændret | success | "Valget er gemt, og preview er genberegnet." |
| `import_scope` ugyldig | error | "Kunne ikke gemme valget: Vælg, om filen skal opdatere produktdata og lager eller kun lagerantal." |
| Anvend | success | Den eksisterende tekst og det eksisterende tillæg om reserverede flasker. Derefter tilføjes:<br>• hvis X > 0: " X reservationer (Y stk.) er arkiveret, fordi Kims lager var 0." (ental: "1 reservation (1 stk.) er arkiveret …")<br>• hvis S > 0: " S rækker uden match er sprunget over." (ental: "1 række uden match er sprunget over.") |
| Anvend, kandidater ikke arkiveret | warning | "U valgte reservationer blev ikke arkiveret, fordi de ikke længere er aktive, eller fordi du valgte et andet produkt end previewets match." (ental: "1 valgt reservation blev ikke arkiveret, …") |
| Arkivering ikke mulig (vagten i 2.4) | error | "Kunne ikke anvende ændringer: Databasen kan ikke gemme status „Arkiveret“ endnu. Genindlæs siden, så opdateringen kan køre, og prøv igen." |

### 4.6 Reservationer (`reservations.php`)

```
[Alle reservationer] [Reserverede] [Solgt / Afsluttet] [Annulleret] [Arkiveret]
Arkiverede reservationer er afsluttet ved upload af Kims fil, fordi varen ikke
længere havde lager i filen. Lageret blev ikke nedskrevet ved arkiveringen.
Detaljer står i audit-loggen.

Dato │ Produkt │ Lokation │ Antal │ Kunde / reference │ Oprettet af │ Status      │ Handling
…    │ …       │ Gert     │ 1     │ Hansen            │ Admin       │ [Arkiveret] │
```

- **Ny fane** efter "Annulleret": `<a class="button …" href="reservations.php?status=archived…">Arkiveret</a>`. Den har samme mønster som de andre faner, også `q`.
- **Filter:** Whitelisten (ca. :125) udvides med `'archived'`.
- **Forklaring:** Når `status=archived`, vises en `<p class="muted">` under fanerne: "Arkiverede reservationer er afsluttet ved upload af Kims fil, fordi varen ikke længere havde lager i filen. Lageret blev ikke nedskrevet ved arkiveringen. Detaljer står i audit-loggen."
- **Status-badge:** Ingen farveklasse, altså grå som "Annulleret". Labelen kommer fra `reservation_status_label`.
- **Handlinger:** Der er ingen knapper. De vises i dag kun for `reserved`, og det er uændret.
- **"Alle reservationer":** Arkiverede vises efter de aktive. Sorteringen `r.status='reserved' DESC` er uændret.

### 4.7 Mobilvisning

- **`supplier_upload.php`** er en admin-side:
  - Tabellen ligger fortsat i `.table-wrap` med vandret scroll.
  - Formularerne bruger `.inline`, så felt og knap står under hinanden på smalle skærme.
  - Arkiveringslisten bruger `label.check`, som brydes over flere linjer.
- **`reservations.php`:** Den nye fane ligger i `.actions`, som har `flex-wrap`, så den brydes pænt ved 375 px.

---

## 5. Data

### 5.1 Database

- **`lager_reservations.status`:** ENUM udvides til `('reserved','completed','cancelled','archived')`. Den nye værdi står **til sidst**, så de eksisterende værdier beholder deres indeks.
  - **Migration:** Ny fil `modules/reservations/migrations.php` med nøglen `'1.3.0'`. Filen må kun returnere arrayet, uden funktionsdefinitioner (jf. ARCHITECTURE.md).
  - Callable'en læser `COLUMN_TYPE` fra `information_schema.COLUMNS` (`TABLE_SCHEMA=DATABASE()`, `lager_reservations`, `status`) og sammenligner med små bogstaver:
    - Indeholder typen allerede `'archived'`: Intet sker. Det er tilfældet på friske installationer, som får typen fra `schema.sql`.
    - Er typen præcis `enum('reserved','completed','cancelled')`: `ALTER TABLE lager_reservations MODIFY status ENUM('reserved','completed','cancelled','archived') NOT NULL DEFAULT 'reserved'`.
    - Er typen noget tredje: Intet sker, så der ikke mistes værdier. Vagten i 2.4 forhindrer så arkivering.
  - `modules/reservations/module.php`: `version` hæves fra `1.2.0` til `1.3.0`.
  - Hvorfor en modulmigration og ikke `ensure_schema_updates`? `ensure_schema_updates` kører med den gamle kode ved opgradering via update.php (ARCHITECTURE.md, "Migrations"). Modulmigrationen kører ved første forespørgsel med den nye kode.
  - ALTER giver en implicit commit. `hsg_run_module_migrations` tåler det allerede, fordi den kun committer, hvis `inTransaction()` er sand. Nyhedsbrevets CREATE TABLE-migration bruger samme vej.
- **`schema.sql`:** Linje 169 får samme ENUM, så friske installationer har værdien fra start.
- **Ellers ingen skemaændringer.** `lager_stock`, `lager_stock_movements` og `hsg_supplier_import_runs` er uændrede. `reference` bliver højst ca. 60 tegn (grænsen er 180).

### 5.2 Hvor læses status? (tjekket)

| Sted | Filter | Konsekvens af `archived` |
|---|---|---|
| `functions.php` `available_for`, `total_available_for` og `create_reservation`; `status.php`, `stock.php`, `products.php:125`, `index.php:8-9`, `export.php`, `catalog_pdf.php`, `image_check.php`, `core/quality.php`, `core/supplier_import.php`, `reservations.php:107` | `status='reserved'` | Tæller ikke som reserveret. Det er ønsket. |
| `products.php:96` og `quality.php:28` (sletning af produkt) | `status<>'reserved'` | Arkiverede slettes sammen med produktet, som afsluttede og annullerede gør i dag. |
| `products.php:54` (fletning af produkter) | alle | Flyttes med, som i dag. |
| `core/bootstrap.php:84-100` (hurtigsøgning) | alle | Vises og linker til `reservations.php?q=…`, hvor status vises korrekt. |
| `reservations.php:125`, `:150-153`, `:253` og `functions.php:50` | whitelist og label | **Ændres** (4.6). |
| `reports.php` | bruger ikke `lager_reservations` | Ingen |

### 5.3 Indstillinger og preview-JSON

- **`hsg_settings`:** Ny nøgle `supplier_import_scope` med værdien `full` eller `stock_only`. Den læses og skrives med `setting_get`/`setting_set`.
- **Preview-JSON** (`storage/import-previews/<token>.json`, runtime-fil, slettes ved "Anvend"). `format` forbliver `2`. Nye nøgler:
  - `import_scope` (`full`/`stock_only`)
  - `items[].skipped` (bool, kun sand i `stock_only` uden match)
  - `items[].archive`, enten `null` eller:
    ```
    {
      default:      bool,              // forvalgt (sikkert match, 2.6)
      quantity:     int,               // sum af kandidaternes antal
      reservations: [ { id, location: 'stock_loc_<id>', quantity, customer_name, reference, created_at } ]
    }
    ```
  - `items[].changes['stock_loc_<id>'].archived` (int, kun > 0)

  **Ældre previews** i format 2 uden de nye nøgler behandles som `import_scope = 'full'` og `archive = null`. Der arkiveres aldrig noget fra dem.
- **Produktdata i prepare, recalculate og merge:** `hsg_supplier_products_with_stock` giver også hvert produkt nøglen `active_reservations`:
  - en liste med `{id, location_id, quantity, customer_name, reference, created_at}`
  - sorteret efter `created_at, id`
  - hentet med én forespørgsel: `SELECT id, product_id, location_id, quantity, customer_name, reference, created_at FROM lager_reservations WHERE status='reserved' ORDER BY created_at, id`

  Produkter uden nøglen giver ingen kandidater. Det gælder fx ældre testfixtures.
- **Versioner:**
  - `supplier_upload` hæves fra `1.1.2` til **`1.2.0`** (ny funktion, ingen migration).
  - `reservations` hæves fra `1.2.0` til **`1.3.0`** (migration).
  - App-versionen hæves ved release efter release-tjeklisten. Forslag: 11.3.0.

---

## 6. Rettigheder, audit og hooks

### 6.1 Rettigheder

- **`supplier_upload.php`:** Uændret `require_module_enabled('supplier_upload')` og `require_capability('imports.manage')`.
  - Den nye POST-handling `import_scope` har `csrf_field()`. CSRF verificeres centralt i `auth.php`.
  - `archive_res[]` behandles kun i `apply`, som allerede er CSRF-beskyttet.
- **Arkivering** sker kun via "Anvend" af en admin med `imports.manage`. `imports.manage` gives kun til admins (`core/permissions.php`), og admins må allerede håndtere alle reservationer i `reservations.php` (`is_admin()`). Der kræves derfor ingen ekstra capability.
- **Direkte brugerlinks:**
  - Ingen adgang til `supplier_upload.php` (modulet har ikke `link_access`, uændret).
  - I `reservations.php` ser de arkiverede reservationer efter de eksisterende regler: egne reservationer, eller alle med `reservations.manage_all`.
  - De ser dem read-only, uden knapper, og kan ikke arkivere.
- **SQL:** Alle forespørgsler med id'er eller brugerinput bruger prepared statements. `IN (…)` bygges med `?`-pladsholdere ud fra antallet af heltal, eller der bruges én sætning pr. id.
- **Output:** Alt output escapes med `h()`, også kundenavn, reference, lokationsnavn og ændringslinjer.

### 6.2 Audit

- **`supplier_import.preview`:** `import_scope` tilføjes i details.
- **`supplier_import.settings`:**
  - Ved `action=import_scope` logges præcis `{import_scope: 'full'|'stock_only'}`.
  - Ved `action=reservation_mode` er details uændret `{reservation_mode}`.
- **`supplier_import.apply`:**
  - **Nye nøgler på topniveau:**
    - `import_scope`
    - `reservations_archived` (antal)
    - `reservations_archived_qty` (stk.)
    - `rows_skipped` (antal markerede rækker, der er sprunget over i `stock_only`)
  - **Pr. produkt i `details[]`:**
    - `archived_reservations: [{reservation_id, location_id, quantity, customer_name, reference, status_before: 'reserved', status_after: 'archived'}]`, kun når der er arkiveret.
    - Hver indgang i `stock[]` får `archived` (stk.), når den er > 0.
    - Et produkt, der kun har fået arkiveret reservationer og ingen ændringer, får også en indgang: `fields: []` og `archived_reservations`. Det tæller ikke i `products_updated`.
  - Eksisterende nøgler bevares.
- **Ny hændelse `reservation.archive`** (entity `reservation`, entity_id = reservations-id), én pr. arkiveret reservation:
  - details: `{product_id, location_id, quantity, customer_name, reference, status_before: 'reserved', status_after: 'archived', source: 'supplier_import', import_run_id}`
  - Navnet følger de eksisterende `reservation.cancel` og `reservation.complete`, så reservationen kan findes i audit-loggen på sit id.

### 6.3 Hooks

Efter commit kaldes `hsg_do_action('reservation.archived', ['reservation_id' => …, 'product_id' => …, 'location_id' => …, 'quantity' => …, 'source' => 'supplier_import'])` pr. arkiveret reservation. Det er samme mønster som `reservation.completed` og `reservation.cancelled`. Der er ingen lyttere i dag, men fremtidige moduler (fx WooCommerce) kan reagere.

---

## 7. Påvirkede filer

| Fil | Ændring |
|---|---|
| `core/supplier_import.php` | Nye og ændrede funktioner (tabellen nedenfor) |
| `supplier_upload.php` | • Valget på upload-kortet og ny brødtekst<br>• Ny handling `import_scope`<br>• Upload sender og gemmer valget<br>• `remap`, `reguess` og `reservation_mode` bevarer previewets `import_scope` via `recalculate`<br>• Opsummeringslinjer og afsnittet om manglende lagerkolonner<br>• "Springes over"-badge og dropdown uden `create_new` i `stock_only`<br>• Arkiveringslisten og ændringslinjens `archived`-tekst<br>• Anvend efter 2.7<br>• Flash-beskeder (4.5) og audit (6.2) |
| `functions.php` | `reservation_status_label`: `'archived' => 'Arkiveret'` |
| `reservations.php` | Whitelist, fanen "Arkiveret" og forklaringen (4.6) |
| `schema.sql` | ENUM på `lager_reservations.status` |
| `modules/reservations/migrations.php` | **Ny.** `'1.3.0'` (5.1) |
| `modules/reservations/module.php` | `version` → `1.3.0` |
| `modules/supplier_upload/module.php` | `version` → `1.2.0`. `description` → "Masterfil upload med produktopdatering eller kun lagerantal, kolonnemapping, automatisk fletning af lagerantal og arkivering af reservationer, der ikke længere har lager." |
| `tests/supplier_import_test.php` | Kriterie 128-140 |
| `tests/supplier_import_db_test.php` | Kriterie 141-147 og 161, plus ændringen af 27p (kriterie 162) |
| `tests/supplier_upload_flow_test.php` | Kriterie 148-158, plus ændringen af 28/30 (kriterie 162). `supplier_import_scope` gemmes og sættes tilbage i `finally` som de andre indstillinger |
| `tests/product_enrichment_test.php` | Linje 139-140: forventet `supplier_upload`-version `1.2.0` med opdateret kommentar (kriterie 162) |
| `tests/run.sh` | • `import_scope` tilføjes løkken `for act in reservation_mode reguess` (CSRF 419 / login 401)<br>• `reservations.php?status=archived` tilføjes `PAGES` |
| `docs/SUPPLIER-UPLOAD.md` | Nye afsnit "Kun lagerantal" og "Arkivering af reservationer, der ikke længere har lager" (regler fra 2.1-2.7 med eksempel A, B og C). "Reservationer" får henvisning |
| `assets/style.css` | Ingen ændring |
| *Ved release* | `app_version.php`, README og `hsg-package.json` (release-tjeklisten i CLAUDE.md) |

**Funktioner i `core/supplier_import.php`.** Signaturerne er bindende, fordi testene bruger dem. Nye parametre er valgfrie, så eksisterende kald virker uændret.

| Funktion | Ændring |
|---|---|
| `hsg_supplier_import_scope(PDO $pdo): string` | **Ny.** Læser `supplier_import_scope`. Returnerer `'stock_only'` eller ellers `'full'` |
| `hsg_supplier_stock_file_is_zero(array $stockFile): bool` | **Ny, ren.** 2.2 |
| `hsg_supplier_products_with_stock(PDO $pdo, array $locationIds): array` | Tilføjer `active_reservations` pr. produkt (5.3) |
| `hsg_supplier_merge_options(array $locations, string $reservationMode, string $importScope = 'full'): array` | Tilføjer `'stock_only' => $importScope === 'stock_only'` |
| `hsg_supplier_merge_raw_items(…, array $options = [])` | • `$options['stock_only']` (bool)<br>• `items[].skipped` og `items[].archive` (2.3)<br>• `changes` efter forvalgt arkivering med `archived` (2.6)<br>• Forvalg (2.6)<br>• Bemærkningerne fra 4.4<br>• Advarslen ved `none` og T-bemærkningen bruger de reserverede tal efter forvalget |
| `hsg_supplier_changes_for_product(array $src, array $product, array $options = []): array` | `$options['stock_only']`: kun `stock_loc_*` og `stock_quantity` |
| `hsg_supplier_apply_product(PDO $pdo, int $productId, array $src, array $options = []): array` | • `$options['stock_only']` sendes videre, så der aldrig er en `UPDATE lager_products SET …` med produktfelter<br>• `$options['archived_by_location']` ([lokations-id ⇒ stk.]) bruges kun til `reference` (2.5) |
| `hsg_supplier_archive_reservations(PDO $pdo, int $productId, array $reservationIds, array $locationIds): array` | **Ny.**<br>• Vagt (2.4), låsning og `UPDATE` (2.7 trin 3)<br>• Returnerer de arkiverede rækker som `[{id, product_id, location_id, quantity, customer_name, reference}]`<br>• Ingen lagerændring, ingen bevægelse og ingen audit (siden logger)<br>• Ugyldige, inaktive og fremmede id'er springes over uden fejl |
| `hsg_supplier_prepare_preview(PDO $pdo, array $sheets, string $filename, ?int $brandId = null, ?string $importScope = null): array` | `null` ⇒ den gemte værdi. Udfylder `import_scope` |
| `hsg_supplier_recalculate_preview(PDO $pdo, array $preview, array $customColMap, ?string $reservationMode = null, ?string $importScope = null): array` | `null` ⇒ previewets `import_scope`, ellers den gemte værdi |

---

## 8. Acceptkriterier

Nummereringen fortsætter efter mbstring-spec'ens 111-127.

**Testdata:**
- Hjælperne `kim_row`, `kim_map`, `hsg_product`, `merge_opts`, `item_by_sku` og `t_has_note` findes i `tests/supplier_import_test.php`.
- Lokations-id i enhedstestene: 1 = Christiansfeld, 2 = Michael og 3 = Gert.
- I database- og flowtestene er L1/L2/L3 TSTKIM-lokationerne, og alle varenumre har præfikset `TSTKIM-`.
- `merge_opts()` er `add`. `merge_opts(false)` er `none`. `merge_opts() + ['stock_only' => true]` er Kun lagerantal.

**Fixture "Spøgelse" (eksempel A)** til enhedstestene:
```
SPOG = hsg_product(['id'=>501,'sku'=>'50-1','name'=>'Spøgelse Whisky','distillery'=>'Testdestilleri',
  'stock_loc_3'=>2,'reserved_loc_3'=>2,'active_reservations'=>[
   ['id'=>9001,'location_id'=>3,'quantity'=>1,'customer_name'=>'Hansen','reference'=>'Ordre 1047','created_at'=>'2026-10-01 12:00:00'],
   ['id'=>9002,'location_id'=>3,'quantity'=>1,'customer_name'=>'Jensen','reference'=>'','created_at'=>'2026-10-03 09:00:00']]])
SPOG_ROW = kim_row(['sku'=>'50-1','name'=>'Spøgelse Whisky','dist'=>'Testdestilleri','R'=>0,'S'=>'','T'=>0,'Q'=>0])
```
"Lagerændringerne" betyder nøglerne `stock_*` i `changes`. Navneparseren kan give afledte felter, og dem ser lagertjekkene bort fra.

### A. Kun lagerantal – rene funktioner (enhedstest)

128. **Standard er uændret.**
     - **Givet** `hsg_supplier_merge_options([1=>'Christiansfeld'], 'add')` uden tredje parameter, **så** er `stock_only` falsk.
     - **Givet** `'stock_only'` som tredje parameter, **så** er `stock_only` sand.
     - **Givet** at alle eksisterende kald af `hsg_supplier_merge_raw_items` er uden `stock_only`, **så** er de eksisterende tests 1-77 og QA1-QA7 uændret grønne (kriterie 162).
129. **Kun lager skrives.**
     - **Givet**
       - `P = hsg_product(['id'=>503,'sku'=>'50-3','name'=>'Pris Whisky','distillery'=>'','stock_loc_1'=>1])`,
       - rækken `kim_row(['sku'=>'50-3','name'=>'Pris Whisky','dist'=>'Testdestilleri','R'=>3,'S'=>'','T'=>''])` og `kim_map()`,

       **når** rækkerne flettes med `merge_opts()`, **så** indeholder `changes` både `distillery` og `stock_loc_1`.
     - **Når** de flettes med `merge_opts() + ['stock_only'=>true]`, **så**
       - er `changes` præcis `['stock_loc_1'=>['old'=>1,'new'=>3,'file'=>3]]`,
       - er `selected` sand, `skipped` falsk og `archive` `null`.
130. **`changes_for_product` med `stock_only`.**
     - **Givet** `hsg_supplier_changes_for_product(['distillery'=>'X','retail_price'=>99.0,'cask_number'=>'123','stock_loc_1'=>3], hsg_product(['stock_loc_1'=>1]), ['stock_only'=>true])`, **så** er resultatet præcis `['stock_loc_1'=>['old'=>1,'new'=>3,'file'=>3]]`.
     - **Givet** `(['stock_quantity'=>5,'distillery'=>'X'], hsg_product(['stock_loc_1'=>1]), ['stock_only'=>true])`, **så** har resultatet kun nøglen `stock_quantity`.
     - **Uden** optionen er resultatet som i dag.
131. **Ny vare i Kun lagerantal springes over.**
     - **Givet** rækken `kim_row(['sku'=>'50-9','name'=>'Ny Whisky','R'=>4,'S'=>'','T'=>''])` og produkterne `[P]` fra kriterie 129.
     - **Når** rækkerne flettes med `merge_opts()`, **så** er `is_create_new` og `selected` sande, som i dag.
     - **Når** de flettes med `stock_only`, **så**
       - er `is_create_new` falsk, `skipped` sand og `selected` falsk,
       - er `changes = []` og `total_stock = 4`,
       - har varen præcis bemærkningen fra 4.4 ("Bemærk: Springes over – varen findes ikke i HSG, og Kun lagerantal opretter ikke nye produkter. …").

### B. Arkivering – betingelse og preview (enhedstest)

132. **Givet** `hsg_supplier_stock_file_is_zero`, **så** gælder:

     | `stock_file` | Resultat |
     |---|---|
     | `mode 'locations'`, `locations [1=>0,2=>0,3=>0]`, `blank ['stock_loc_2']`, `invalid []`, `total 0`, `total_invalid null`, `sum 0` (nøglerne er `stock_loc_*`) | sand |
     | samme, men `total null` | sand |
     | `mode 'locations'`, `locations []`, alle tre i `blank` | falsk |
     | `locations [1=>0,3=>0]`, `invalid ['stock_loc_2'=>'?']` | falsk |
     | nuller, men `total_invalid '3 + 2'` | falsk |
     | nuller, men `total 2` (afvigelse) | falsk |
     | `locations [1=>5,2=>0,3=>0]`, `sum 5` | falsk |
     | `locations [1=>1,2=>0,3=>0]`, `sum 1` (delvist lager, Q2b) | falsk |
     | `mode 'total'`, `locations ['stock_loc_1'=>0]`, `total 0`, `total_invalid null` | sand |
     | `mode 'total'`, `locations []`, `total null`, `total_invalid '?'` | falsk |
     | `mode 'total'`, `locations []`, `total 0` (uden fallback-lokation) | falsk |
     | `mode 'none'` | falsk |
     | `[]` (ældre preview) | falsk |
133. **Eksempel A med `add`.** **Givet** `[SPOG_ROW]`, `kim_map()`, `[SPOG]` og `merge_opts()`, **når** rækkerne flettes, **så**
     - er lagerændringerne præcis `['stock_loc_3'=>['old'=>2,'new'=>0,'file'=>0,'archived'=>2]]`,
     - er `archive` præcis `['default'=>true,'quantity'=>2,'reservations'=>[['id'=>9001,'location'=>'stock_loc_3','quantity'=>1,'customer_name'=>'Hansen','reference'=>'Ordre 1047','created_at'=>'2026-10-01 12:00:00'],['id'=>9002,'location'=>'stock_loc_3','quantity'=>1,'customer_name'=>'Jensen','reference'=>'','created_at'=>'2026-10-03 09:00:00']]]`,
     - er `reserved = ['stock_loc_3'=>2]`, `flags = []` og `selected` sand.
134. **Eksempel A med `none`.** **Givet** samme data med `merge_opts(false)`, **så**
     - er lagerændringerne præcis `['stock_loc_3'=>['old'=>2,'new'=>0,'file'=>0,'archived'=>2]]`,
     - er `archive.default` sand,
     - har ingen bemærkning teksten "er reserveret. Disponibelt bliver".
135. **Ikke sikkert match: arkiveringen er ikke forvalgt.**
     - **Givet**
       - `SPOG` med navnet `'Spøgelse Testaa'` og uden varenummer-match,
       - en række uden varenummer og uden destilleri med navnet `'Spøgelse Testab'` og R=0, S='', T=0, Q=0.

       Testen tjekker først som forudsætning, at `match.id = 501`, og at `70 ≤ match.score ≤ 89`. Med disse navne giver `hsg_supplier_match_score` 85.
     - **Når** rækkerne flettes med `merge_opts()`, **så**
       - er `archive.default` falsk, og `archive.reservations` indeholder 9001 og 9002,
       - har lagerændringerne ingen `stock_loc_3`, fordi 0 + 2 = 2 (som i dag),
       - er `selected` falsk.
136. **Eksempel B og C: der er stadig lager.**
     - **Eksempel B (lager på en anden lokation, Q2):**
       - **Givet**
         - `hsg_product(['id'=>502,'sku'=>'50-2','name'=>'Halv Whisky','distillery'=>'Testdestilleri','stock_loc_1'=>5,'stock_loc_3'=>1,'reserved_loc_3'=>1,'active_reservations'=>[['id'=>9003,'location_id'=>3,'quantity'=>1,'customer_name'=>'Gert-kunde','reference'=>'','created_at'=>'2026-10-02 10:00:00']]])`,
         - rækken R=5, S='', T=0, Q=5,
       - **når** rækkerne flettes med `merge_opts()`, **så** er `archive` `null`, og der er ingen lagerændringer.
       - **Med** `merge_opts(false)` er lagerændringerne præcis `['stock_loc_3'=>['old'=>1,'new'=>0,'file'=>0]]`, og bemærkningen "Gert får 0 fysisk efter import, men 1 er reserveret" vises (som i dag).
     - **Eksempel C (delvist lager, Q2b):**
       - **Givet**
         - `hsg_product(['id'=>505,'sku'=>'50-5','name'=>'Delvis Whisky','distillery'=>'Testdestilleri','stock_loc_1'=>4,'reserved_loc_1'=>3,'active_reservations'=>[…]])` med tre reservationer på 1 stk. hos Christiansfeld (id 9006, 9007 og 9008, oprettet 2026-10-01, 2026-10-05 og 2026-10-20),
         - rækken R=1, S='', T='', Q=1,
       - **så** er `archive` `null` med både `merge_opts()` og `merge_opts(false)`.
       - Med `add` er der ingen lagerændring hos Christiansfeld (1 + 3 = 4).
       - Med `none` er ændringen præcis `['stock_loc_1'=>['old'=>4,'new'=>1,'file'=>1]]`, og bemærkningen "Christiansfeld får 1 fysisk efter import, men 3 er reserveret" vises (som i dag).
137. **Ukendt 0 giver ingen arkivering.** **Givet** `[SPOG]` og hver af disse rækker for 50-1, **så** er `archive` `null`:
     - R='', S='', T='', Q='' (alle tomme)
     - R=0, S='?', T=0
     - R=0, S='', T=0, Q='3 + 2'
     - R=0, S='', T=0, Q=2 (kontrolafvigelse). Rækken har som i dag `stock_mismatch`.

     **Givet** `$products = []` (varen findes ikke i HSG), **så** er `archive` `null`.
138. **Kun lokationer med et tal fra filen.**
     - **Tilstand T:**
       - **Givet**
         - `hsg_product(['id'=>504,'sku'=>'50-4','name'=>'Total Spøgelse','distillery'=>'Testdestilleri','stock_loc_1'=>1,'stock_loc_3'=>1,'reserved_loc_1'=>1,'reserved_loc_3'=>1,'active_reservations'=>[['id'=>9004,'location_id'=>1,'quantity'=>1,'customer_name'=>'A','reference'=>'','created_at'=>'2026-10-01 08:00:00'],['id'=>9005,'location_id'=>3,'quantity'=>1,'customer_name'=>'B','reference'=>'','created_at'=>'2026-10-01 09:00:00']]])`,
         - rækken Q=0 og `kim_map(true,false)`,
       - **så** gælder:
         - `archive.reservations` har kun id 9004.
         - Lagerændringerne er præcis `['stock_loc_1'=>['old'=>1,'new'=>0,'file'=>0,'archived'=>1]]`.
         - Varen har bemærkningen "Bemærk: 1 reservation på Gert arkiveres ikke, fordi lokationen ikke får et tal fra filen."
     - **Tilstand L uden kolonne for Michael:**
       - **Givet** map `['sku'=>4,'name'=>5,'distillery'=>7,'stock_quantity'=>16,'stock_loc_1'=>17,'stock_loc_3'=>19]` og et produkt med en aktiv reservation hos Michael (lokation 2),
       - **så** er den reservation ikke kandidat, og bemærkningen nævner Michael.
139. **Arkivering i Kun lagerantal (Q1 = altid).** **Givet** eksempel A med `merge_opts() + ['stock_only'=>true]`, **så** er `archive` og lagerændringerne som i kriterie 133, og `changes` har ingen andre nøgler end `stock_*`.
140. **Ældre fixtures.** **Givet** et produkt med `reserved_loc_*` men uden nøglen `active_reservations` (fx `octomore()`), **så** er `archive` `null`, og de eksisterende tests 27, 28 og QA7 er uændret grønne.

### C. Arkivering – Anvend (databasetest, `tests/supplier_import_db_test.php`)

Fælles data:
- Produktet `TSTKIM-80xx` med lager `[L1=>0, L2=>0, L3=>2]`.
- To reservationer hos L3 på hver 1 stk.: r1 ("TSTKIM Hansen", "Ordre 1047") og r2 ("TSTKIM Jensen").
- Kilden er `hsg_supplier_source_fields` af rækken R=0, S='', T=0, Q=0 med testens map og fallback L1.

141. **Begge arkiveres (`add`).**
     - **Givet** TSTKIM-8001, **når**
       - `hsg_supplier_archive_reservations($pdo, $pid, [r1, r2], [L1, L2, L3])` kaldes,
       - og derefter `hsg_supplier_apply_product($pdo, $pid, $src, ['add_reserved'=>true,'archived_by_location'=>[L3=>2]])`,
     - **så** gælder:
       - Funktionen returnerer to rækker med nøglerne `id`, `product_id`, `location_id`, `quantity`, `customer_name` og `reference`.
       - Status for r1 og r2 er `archived`.
       - `db_stock` er `[L1=>0, L2=>0, L3=>0]`.
       - `db_movements` er præcis `[[L3, −2, 0, 'import', 'Kims masterfil opdatering (2 arkiveret)']]`, og der er ingen bevægelse med `sale`.
       - `available_for(L3) = 0`, og `total_available_for = 0`.
       - Produktets status er `inactive`.
142. **Kun den ene arkiveres.** **Givet** TSTKIM-8002, **når** kun r1 arkiveres, og apply kaldes med `archived_by_location [L3=>1]`, **så**
     - er L3 = 1,
     - er bevægelsen præcis `[L3, −1, 1, 'import', 'Kims masterfil opdatering (+1 reserveret, 1 arkiveret)']`,
     - er r2 stadig `reserved`, og `available_for(L3) = 0`.
143. **Ingen arkiveres** (fravalgt, som i dag). **Givet** TSTKIM-8003, **når** apply kaldes med `add` uden arkivering, **så** er der ingen lagerændring og ingen bevægelse (0 + 2 = 2), og r1 og r2 er `reserved`.
144. **`none` med arkivering.** **Givet** TSTKIM-8004, **når** begge arkiveres, og apply kaldes med `['add_reserved'=>false,'archived_by_location'=>[L3=>2]]`, **så**
     - er L3 = 0,
     - er referencen `Kims masterfil opdatering (2 arkiveret)`,
     - er `available_for(L3) = 0`.
145. **Fremmede og inaktive id'er.** **Givet** følgende, **når** `hsg_supplier_archive_reservations($pdo, id(TSTKIM-8001-lignende produkt), [ids…], [L1, L3])` kaldes, **så** returneres `[]`, ingen status ændres, og der kastes ingen undtagelse:
     - en aktiv reservation på et andet produkt,
     - en `cancelled` reservation på produktet,
     - en aktiv reservation hos L2, der ikke er med i lokationslisten,
     - id `999999999`.
146. **Vagt.** **Givet** en database, hvor `lager_reservations.status` ikke kender `'archived'`, **når** `hsg_supplier_archive_reservations` kaldes med et gyldigt id, **så**
     - kastes en `RuntimeException` med teksten fra 2.4,
     - er reservationen uændret `reserved`.

     Databasetesten må gøre det med en `try/finally`, der først rydder testens arkiverede rækker og bagefter genskaber kolonnetypen. Ellers testes det manuelt, og testeren skriver det i rapporten.
147. **Migration.**
     - **Givet** den friske installation fra `tests/run.sh`, **så** gælder:
       - `COLUMN_TYPE` for `lager_reservations.status` er `enum('reserved','completed','cancelled','archived')`.
       - `hsg_module_versions` har `reservations = 1.3.0` og `supplier_upload = 1.2.0`.
     - **Givet** `require 'modules/reservations/migrations.php'` to gange, **så** gælder:
       - Der kommer intet output, og der defineres ingen nye funktioner.
       - Resultatet er et array med præcis nøglen `'1.3.0'`.
       - Når callable'en kaldes igen, kastes der ingen fejl, og `COLUMN_TYPE` er uændret.
     - **Opgradering (manuel test):**
       - **Givet** en installation på v11.2.0 med reservationer i alle tre statusser,
       - **når** koden opdateres, og en side åbnes,
       - **så** har ENUM'en `archived`, alle eksisterende reservationer har samme status som før, og Reservationer har version 1.3.0.

### D. Flow via HTTP (`tests/supplier_upload_flow_test.php`)

Nye trin kører **efter** de eksisterende og bruger **egne uploads** med egne varer (som trin 74), så de eksisterende trin er uændrede:
- Kolonnevalget sættes eksplicit med `remap`: R/S/T → L1/L2/L3, og Q → Lagerantal i alt.
- Reservationsvalget er `add`.
- Overskrifterne er de samme som i flowtestens `$headers`.

**HSG-data:**
- **8101 "TSTKIM Spøgelse Flow":** destilleri "TSTKIM Dist", lager `[L1 0, L2 0, L3 2]`, reservationerne rA (L3, 1, kunde `<b>TSTKIM Hansen</b>`, ref "Ordre 1047") og rB (L3, 1, "TSTKIM Jensen").
- **8102 "TSTKIM Halv Flow":** lager `[L1 5, L3 1]`, rC (L3, 1, "TSTKIM Gert-kunde").
- **8103 "TSTKIM Ikke i fil":** lager `[L3 2]`, rD og rE (L3, 1 hver).
- **8104 "TSTKIM Pris Flow":** udsalgspris 100.00, engrospris, destilleri og fadnummer `NULL`, lager `[L1 1]`.
- **8105 "TSTKIM Manuel Flow":** lager `[L3 1]`, rF (L3, 1).

**Filer:**
- **F1:** 8101 (Q 0, R 0, S tom, T 0) og 8102 (Q 5, R 5, S tom, T 0)
- **F2:** 8104 (destilleri "TSTKIM Ny Dist", Q 3, R 3, Z 253, AA 499) og 8199 "TSTKIM Helt Ny Kun Lager" (Q 4, R 4), der ikke findes i HSG
- **F3:** 8105 (Q 0, R 0, S tom, T 0)

148. **Preview med arkiveringsliste.** **Givet** upload af F1 i `full`, **så** gælder:
     - **8101:**
       - `archive.default` er sand, `archive.quantity = 2`, og id'erne er [rA, rB].
       - Lagerændringerne er præcis `['stock_loc_L3'=>['old'=>2,'new'=>0,'file'=>0,'archived'=>2]]`.
     - **8102:** `archive = null` og ingen lagerændringer.
     - **8103** står ikke i previewet.
     - **Siden** viser:
       - to afkrydsede felter `name="archive_res[]"` med værdierne rA og rB,
       - "Arkivér reservationer – Kims lager i alt er 0:",
       - "Lagerantal: TSTKIM Gert: 2 → 0 (fil 0, 2 reserveret arkiveres)",
       - "Arkiveres: 2 reservationer (2 stk.) på 1 vare, fordi Kims lager i alt er 0.",
       - `&lt;b&gt;TSTKIM Hansen&lt;/b&gt;`, og HTML'en indeholder ikke `<b>TSTKIM Hansen</b>`.
149. **Andet produkt valgt manuelt.** **Givet** upload af F3, **når** der postes `apply` med rækken for 8105, `product_<i>` = id(8103) og `archive_res[] = [rF]`, **så** gælder:
     - rF, rD og rE er stadig `reserved`.
     - Lageret for 8103 er uændret `[L3 2]`.
     - Der vises warning-flashen "1 valgt reservation blev ikke arkiveret, fordi de ikke længere er aktive, eller fordi du valgte et andet produkt end previewets match."
     - Der er ingen nye `reservation.archive` i audit-loggen.
150. **Anvend som browseren.**
     - **Givet** previewet fra 148, **når** der postes `apply` med:
       - de forvalgte rækker og deres produkter (som i trin 13/16/24/27/32/33),
       - `archive_res[] = [rA, rB, rC, rD]`. rC og rD er ikke kandidater, men er lagt i POST'en.
     - **så** gælder:
       - **Reservationer:** rA og rB er `archived`. rC, rD og rE er `reserved`.
       - **Lager:**
         - 8101 er `[L1 0, L2 0, L3 0]`.
         - 8101 har præcis én ny bevægelse: L3, −2, `import`, `Kims masterfil opdatering (2 arkiveret)`.
         - 8102 og 8103 har uændret lager.
         - 8101 har status `inactive`.
       - **Flash:**
         - Success-flashen indeholder "2 reservationer (2 stk.) er arkiveret, fordi Kims lager var 0."
         - Der er ingen warning-flash, fordi rC og rD ignoreres uden besked.
       - **`supplier_import.apply`:**
         - `import_scope = 'full'`, `reservations_archived = 2` og `reservations_archived_qty = 2`.
         - `details` for 8101 har `archived_reservations` med to indgange. Den første er `{reservation_id: rA, location_id: L3, quantity: 1, customer_name: '<b>TSTKIM Hansen</b>', reference: 'Ordre 1047', status_before: 'reserved', status_after: 'archived'}`.
         - `stock` er `[{location_id: L3, old: 2, new: 0, file: 0, archived: 2}]`.
       - **`reservation.archive`:** Der er præcis to nye hændelser med `entity_type = 'reservation'` og `entity_id` rA og rB. Deres details har `source: 'supplier_import'` og `import_run_id` = entity_id for `supplier_import.apply`.
151. **Idempotent.**
     - **Givet** at F1 uploades igen efter 150, **så** har 8101 `archive = null` og ingen lagerændringer.
     - **Når** rækken for 8101 alligevel markeres og anvendes uden `archive_res`, **så**
       - er der ingen nye bevægelser for 8101 og ingen nye `reservation.archive`,
       - er lageret uændret `[L1 0, L2 0, L3 0]`.
152. **Kun lagerantal: preview og husket valg.** **Givet** upload af F2 med `import_scope=stock_only` i formularen, **så** gælder:
     - **Preview og indstilling:**
       - `preview.import_scope = 'stock_only'`.
       - `supplier_import_scope = 'stock_only'` i `hsg_settings`.
       - Den nyeste `supplier_import.preview` har `import_scope: 'stock_only'`.
     - **Varer:**
       - 8104 har præcis lagerændringerne `['stock_loc_L1'=>['old'=>1,'new'=>3,'file'=>3]]` og ingen andre `changes`.
       - 8199 har `skipped` sand, `is_create_new` falsk og `selected` falsk.
     - **Siden:**
       - viser "Springes over" og "Kun lagerantal: 1 vare matcher et HSG-produkt · 1 vare springes over (findes ikke i HSG).",
       - indeholder ikke `value="create_new"`.
     - **Et nyt `GET supplier_upload.php`** viser `<option value="stock_only" selected>` i upload-kortets felt "Hvad skal opdateres?".
153. **Kun lagerantal: Anvend.** **Når** der postes `apply` med rækkerne 8104 (`product_<i>` = id) og 8199 (`product_<i>` = `create_new`), **så** gælder:
     - **8104:** Udsalgspris er 100.00. Engrospris, destilleri og fadnummer er stadig `NULL`. Lageret er L1 = 3.
     - Der findes intet produkt med varenummeret `TSTKIM-8199`.
     - Success-flashen indeholder "1 række uden match er sprunget over."
     - **Audit:** `import_scope = 'stock_only'`, `rows_skipped = 1` og `products_created = 0`.
154. **Skift i previewet.**
     - **Givet** en ny upload af F2 i `stock_only`, **når** `action=import_scope` postes med `import_scope=full`, **så**
       - er flashen "Valget er gemt, og preview er genberegnet.",
       - er `preview.import_scope = 'full'`,
       - har 8199 `is_create_new` sand, og 8104's `changes` indeholder `distillery`,
       - er `supplier_import_scope = 'full'`,
       - er den nyeste `supplier_import.settings` præcis `{"import_scope":"full"}`.
     - **Givet** `import_scope=x' OR 1=1`, **så** vises fejl-flashen fra 4.5, og indstillingen er uændret.
     - **Givet** at `remap`, `reguess` eller `reservation_mode` postes derefter, **så** er `preview.import_scope` uændret.
155. **CSRF og login.** **Givet** `tests/run.sh`, **så** afvises `supplier_upload.php` med `action=import_scope` uden CSRF med HTTP 419 og uden login med HTTP 401. Det er samme løkke som `reservation_mode` og `reguess`.
156. **Ældre preview.**
     - **Givet** et format 2-preview, hvor nøglerne `import_scope`, `items[].skipped` og `items[].archive` er fjernet. Det kan fx være en kopi af F3's preview, gemt under et nyt token som i trin 34.
     - **Når** det anvendes med rækken for 8105 og `archive_res[] = [rF]`, **så**
       - er rF stadig `reserved`,
       - har audit `import_scope = 'full'`,
       - er der ingen `reservation.archive`.
157. **Reservationer-siden.** **Givet** status efter 150 og admin-login, **så** gælder:
     - **`GET reservations.php?status=archived`** svarer 200 og:
       - viser rækkerne for rA og rB med badget "Arkiveret",
       - viser forklaringen fra 4.6 og fanen "Arkiveret" som aktiv knap,
       - indeholder ingen `value="complete"` og ingen `value="cancel"`.
     - **`?status=reserved`** indeholder "TSTKIM Gert-kunde" (rC), men ikke "TSTKIM Jensen" (rB).
     - **`reservations.php`** (Alle) indeholder begge, og rC står før rB.
158. **Escaping på reservationssiden.** **Givet** `GET reservations.php?status=archived`, **så** står `&lt;b&gt;TSTKIM Hansen&lt;/b&gt;` i HTML'en, og `<b>TSTKIM Hansen</b>` står der ikke.

### E. Reservationer og øvrige sider

159. **Arkiverede tæller ikke.** **Givet** 8101 efter 150, **så** gælder:
     - `SELECT COUNT(*) FROM lager_reservations WHERE product_id = 8101 AND status = 'reserved'` = 0.
     - `available_for` og `total_available_for` svarer til det fysiske lager.

     **Givet** forespørgslerne i tabellen i 5.2, **så** er de uændrede (kodegennemgang).
160. **Direkte brugerlink (manuel test).**
     - **Givet** et linkbrugerlink med visning af reservationer og en reservation, som linkbrugeren har oprettet, og som derefter er arkiveret,
     - **når** linket åbner `reservations.php?status=archived` i 375 px bredde, **så**
       - vises reservationen med "Arkiveret" og uden knapper,
       - brydes fanerne uden vandret scroll af siden.
     - `supplier_upload.php` afvises for linkbrugeren som i dag.
161. **Label.** **Givet** `functions.php`, **så** gælder:
     - `reservation_status_label('archived') === 'Arkiveret'`.
     - De tre eksisterende labels er uændrede.

     Testes i databasetesten, som allerede indlæser `functions.php`.

### F. Generelt

162. **Ændrede eksisterende tjek** (bevidst, jf. Q1 og Q4). Andre eksisterende tjek ændres ikke. Disse fire ændres:
     - **`tests/supplier_import_db_test.php`, trin 27p** (Octomore: Kims tal 0, 2 reserveret, fysisk 0):
       - Med `add` er `changes['stock_loc_L1']` nu fraværende (0 → 0 med forvalgt arkivering) i stedet for 0 → 2. `archive.quantity = 2`, og `archive.default` er sand.
       - Med `none` er der ingen lagerændring og **ingen** advarsel.
       - For stadig at teste tillægget og advarslen tilføjes produktet TSTKIM-3008 med fysisk 0, 2 reserveret og rækken R=1, S='', T='', Q=1:
         - `add` giver `['old'=>0,'new'=>3,'file'=>1,'reserved'=>2]`.
         - `none` giver `['old'=>0,'new'=>1,'file'=>1]` og bemærkningen "får 1 fysisk efter import, men 2 er reserveret".
     - **`tests/supplier_upload_flow_test.php`, trin 28/30:**
       - **`none`:** Tjekket "advarsel om negativt disponibelt" for Octomore erstattes af tre tjek:
         - Octomores `archive.quantity = 2`.
         - Octomores `archive.default` er sand.
         - Siden indeholder ikke "får 0 fysisk efter import, men 2 er reserveret".
       - **`add`:** Tjekket "Octomore 0 → 2" erstattes af `changes['stock_loc_L1'] = null`. Tillægget tjekkes i stedet på Epsilon: `['old'=>0,'new'=>5,'file'=>3,'reserved'=>2]`.
     - **`tests/supplier_upload_flow_test.php`, trin 13/16/24/27/32/33:** Trinnet er **uændret**. Det poster ingen `archive_res[]`, så der arkiveres intet. Octomore får 0 → 2, flashen nævner 4 reserverede flasker, og audit er som før. Det dokumenterer, at fravalgt arkivering giver dagens adfærd.
     - **`tests/product_enrichment_test.php:139-140`:** Forventet `supplier_upload`-version er `1.2.0`.
163. **Testpakken.**
     - **Når** `bash tests/run.sh` køres, **så** er alle trin grønne: enhedstest, databasetest, flowtest, røgtest og release-negativtest.
     - **Givet** røgtestens `PAGES`, **så** svarer `reservations.php?status=archived` uden PHP-fejl.
164. **Versioner og dokumentation.** **Givet** manifesterne, **så** gælder:
     - `supplier_upload` har version `1.2.0`, og `reservations` har `1.3.0`.
     - `docs/SUPPLIER-UPLOAD.md` beskriver:
       - "Kun lagerantal" (2.1),
       - betingelsen for arkivering med eksempel A, B og C (2.2-2.3),
       - status "Arkiveret" (2.4),
       - lagerberegningen med `add`/`none` og referencerne (2.5),
       - forvalget (2.6).
165. **Manuel test med den rigtige fil**, hvis "lager 19-10-2026.xlsx" eller en nyere fil fra Kim er tilgængelig. Filen må ikke lægges i repoet.
     - **Givet** samme kolonnevalg og reservationsvalg, **når** previewet laves i `full` på main og på branchen, **så** er varer, match og ikke-lagerændringer identiske. Lagerændringer og forvalg må kun afvige på varer med Kims lager i alt 0 og aktive reservationer. Testeren skriver de afvigende varer i rapporten.
     - **Givet** `stock_only`, **så** har ingen vare andre ændringer end `Lagerantal: …`.
166. **Tekster.** **Givet** alle nye tekster på `supplier_upload.php` og `reservations.php`, **så** er de på dansk og stemmer ordret med afsnit 4.

---

## 9. Uden for scope

- **Genåbning** af en arkiveret reservation (knap i `reservations.php`) og manuel arkivering fra `reservations.php` (Q6).
- **Arkivering pr. lokation** (Q2) og **ved delvist lager**, hvor Kims tal er over 0 men lavere end det reserverede (Q2b). Svarer brugeren ja til Q2b, laves det i et tillæg med reglen "nyeste først, hele reservationer, ikke forvalgt, kun ved `none`".
- **Særregel for reservationer**, der er oprettet efter Kims ark (Q7).
- **Arkivering ved manuelt valgt produkt:** Har en vare med Kims lager 0 intet match, og vælger admin produktet manuelt i dropdown'en, foreslås der ingen arkivering, fordi previewet ikke kendte produktet. Admin kan uploade igen, når koblingen er gemt som alias.
- **Live genberegning** af previewet med JavaScript, når en reservation fravælges.
- **Ændringer i reservationens `note`** eller nye kolonner (fx hvem der arkiverede). Det står i audit-loggen.
- **"Kun lagerantal" i den almindelige Excel-import** (`import.php`).
- **Ændringer i reservationsvalget** `add`/`none`, i kolonnegættet og i lagercellens talregel.
- **Afstemning med Kims personkolonner** (AI–AU).

---

## 10. Åbne spørgsmål til brugeren (opsummering)

1. **Q1:** Skal reservationer arkiveres i begge tilstande eller kun i "Kun lagerantal"? Anbefaling: **i begge**, så samme fil giver samme lagertal.
2. **Q2:** Vurderes "ikke længere lager" **pr. vare i alt** eller pr. lokation? Anbefaling: **pr. vare i alt**. Har varen stadig flasker på en anden lokation, kan reservationen opfyldes ved at flytte en flaske (eksempel A arkiveres, eksempel B ikke).
3. **Q2b:** Skal der også arkiveres, når Kims tal er **lavere end det reserverede, men ikke 0** (fx 1 tilbage og 3 reserveret)? Anbefaling: **nej, kun ved 0**. Med `add` er Kims tal efter reservationerne, så alle reservationer har stadig flasker bag sig. Hvis ja: hele reservationer, nyeste først, aldrig forvalgt og kun ved `none`. Det kræver et tillæg.
4. **Q3:** Er en ny status **"Arkiveret"** i orden (kræver en lille databasemigration), eller skal de markeres som "Solgt/afsluttet"? Anbefaling: **ny status "Arkiveret"**.
5. **Q4:** Skal arkiveringen være **valgt på forhånd** på sikre rækker, så du kun fravælger undtagelser? Anbefaling: **ja**. Det betyder, at Octomore-reservationerne fra 19-10 nu foreslås arkiveret i stedet for at blive lagt oven i.
6. **Q5:** Skal valget "Kun lagerantal" **huskes** til næste upload? Anbefaling: **ja**, med standarden "Produktdata og lager".
7. **Q6:** Skal en arkiveret reservation kunne **genåbnes**? Anbefaling: **nej, ikke nu**. Fanen "Arkiveret" viser dem.
8. **Q7:** Skal reservationer, der er oprettet **efter** Kims ark, have en særregel? Anbefaling: **nej**. Oprettelsesdatoen vises, så de kan fravælges.

# Kims uploadfil – rettelse af lagerfejl, kolonnegæt, bogstav-fletning og reservationer

- **Dato:** 2026-10-19
- **Status:** Godkendt af brugeren 2026-10-19 (se "Beslutninger" nedenfor)
- **Modul:** `supplier_upload` ("Kims uploadfil"), capability `imports.manage`
- **Grundlag:** main v11.1.4, som allerede har "husk kolonnevalg" og "flet bogstav-varenumre"
- **Berørt kode:** `supplier_upload.php` (side) og `core/supplier_import.php` (logik). Linjenumrene i spec'en er omtrentlige.
- **Testfil:** "lager 19-10-2026.xlsx" fra brugeren. Den må **ikke** lægges i repo eller release-ZIP. Enhedstests bruger kun syntetiske data.

---

## Beslutninger ved godkendelsen (2026-10-19)

- **Q1 = Ja:** Kim regner altid reserverede flasker som solgt. Standard for reservationsvalget er derfor `add` ("Læg aktive reservationer oven i Kims tal").
- **Q2 = Godkendt:** Tomme lokationsceller tæller som 0, når rækken har mindst ét lokationstal. Er alle tomme, ændres lageret ikke.
- **Q3 = Godkendt:** Fletningsreglen for bogstav-varenumre i afsnit 4.4 gælder.
- **Q1b, Q1c, Q4, Q5 og Q6 er ikke besvaret.** Spec'ens antagelser gælder: reservationen lægges oven i på den lokation, hvor den er reserveret i HSG, og prisregnen i afsnit 4.1 er uændret. Knappen "Gæt kolonner forfra" dækker Q5.

---

## 0. Åbne spørgsmål (besvares ved godkendelsen, før udvikling)

**Q1 – Reservationer (afgør standardvalget i afsnit 4.5)**
Regner Kim altid flasker, der er reserveret i HSG, som solgt i sit ark, dvs. at de allerede indgår i "Solgt/ stk"?
- **Ja** (spec'en antager dette): Standard = "Læg aktive reservationer oven i Kims tal" (`add`).
- **Nej**: Standard = "Brug Kims tal uændret" (`none`). Kun standardværdien ændres.
- **Det varierer**: Der er ingen standard. Valget starter på "– Vælg –", og "Anvend" afvises, indtil der er valgt. Valget huskes ikke mellem uploads.

**Q1b – Hvilken lokation?** Når en reserveret flaske står hos Gert eller Michael, trækker Kim den så fra "hos Gert"/"hos Michael"? Eller forsvinder den fra Christiansfeld, fordi formlen er R = Q − T − S? Spec'en antager, at reservationen lægges oven i på **den lokation, hvor den er reserveret i HSG**. Totalen bliver rigtig i begge tilfælde. Fordelingen mellem lokationerne kan dog blive skæv med én flaske pr. reservation.

**Q1c – Rækkefølge i tid:** Opretter I reservationer i HSG, efter Kims ark er lavet, og før det uploades? I så fald har Kim ikke trukket dem fra endnu, og `add` tæller dem med én gang for meget.

**Q2 – Tomme lokationsceller (afsnit 4.3)** Godkender du denne regel: *"Har en række et tal i mindst én lokationskolonne, tæller tomme lokationsceller i samme række som 0. Er alle lokationsceller tomme, ændres lageret ikke."*? Alternativerne er: (b) en tom celle ændrer aldrig noget (som i dag), eller (c) en tom celle er altid 0.

**Q3 – Bogstav-varenumre (afsnit 4.4)** Er reglen i orden? Den siger: flet kun, når navnet er det samme eller næsten det samme, og fadnummer, destilleri, årgang og alder ikke modsiger hinanden. Eller skal "a"-varer altid være selvstændige produkter i HSG?

**Q4 – Personkolonnerne AI–AU** ("Henrik stockflet", "Keld" …): Er det de samme flasker som reservationerne i HSG? Kolonnerne importeres ikke i denne rettelse, men svaret hjælper med Q1.

**Q5 – Husket kolonnevalg fra 19-10:** Rettede du kolonnevalget manuelt, før du trykkede "Anvend" den 19-10? Hvis ikke, er det forkerte valg husket: Lagerværdi → Lagerantal, Engros salgspris → Udsalgspris og "sponsor til whisky.dk" → Produktnavn. Så skal du trykke "Gæt kolonner forfra" én gang (se afsnit 10).

**Q6 – Priser:** Er HSG's "Engrospris" det samme som Kims "Engros salgspris (ex moms)" (Z), og "Udsalgspris" det samme som "vejl. salgspris (inkl moms)" (AA)? Kostpris (L og Y) importeres ikke, fordi HSG ikke har et kostprisfelt.

---

## 1. Formål

Efter uploaden af Kims masterfil "lager 19-10-2026.xlsx" passede lagertallene i HSG ikke. Det skyldes fire fejl i "Kims uploadfil" og manglende håndtering af reservationer:

| # | Fejl | Eksempel fra filen |
|---|------|--------------------|
| 1 | Auto-kolonnemapping tager det første delvise match og overser præcise match længere til højre | O "Lagerværdi" bliver lagerantal, så 13-1021 får 9856 i stedet for 53. Z "Engros salgspris" bliver udsalgspris. AS "sponsor til whisky.dk" bliver produktnavn. |
| 2 | Lagerantal i alt skrives ind i første lokation, selvom lokationskolonnerne er valgt | Christiansfeld 41 + Gert 12 + Michael 2 bliver til 53 + 12 + 2 = 67 |
| 3 | Tomme lokationsceller springes over, så HSG beholder gamle flasker | "hos Michael" er tom i 69 af 92 varer med lager |
| 4 | Bogstav-varenumre flettes alene ud fra varenummeret | 12-1123a (Clynelish, 9 stk.) + 12-1123 (St. Bridget Kirk, 15 stk.) bliver til 24 stk. på St. Bridget |
| R | Kims tal er (formentlig) efter reservationer, og HSG trækker reservationerne fra igen | Disponibelt ≤ 0, varen forsvinder fra Lagerstatus, og "Markér solgt" fejler |

**For hvem:**
- **Admin** (uploader Kims fil) skal kunne stole på, at previewet viser præcis det, der bliver udført.
- **Lagermedarbejdere og linkbrugere** (Lagerstatus og reservationer) skal se det rigtige disponible lager. Varer må ikke forsvinde, fordi en reservation er trukket fra to gange.

**Mål:**
1. Kims fil kan uploades uden at rette kolonnevalget manuelt. Undtagelsen er lokationskolonnerne, som vælges én gang og derefter huskes.
2. Fysisk lager pr. lokation i HSG = Kims tal (+ aktive reservationer, hvis det vælges). Summen = Kims "Lagerbeholdning".
3. Forskellige whiskyer flettes aldrig sammen.
4. Previewet viser kun ændringer, der faktisk bliver udført, med de tal, der bliver skrevet.

---

## 2. Brugerflow

1. Admin åbner **Kims uploadfil**, vælger filen og trykker **"Analysér fil og vis preview"** (som i dag).
2. Systemet foreslår et kolonnevalg i denne rækkefølge: husket valg, så præcise match og til sidst delvise match (afsnit 4.1). Et husket valg, der ikke længere er tilladt (en beløbskolonne som lager), bruges ikke. Kolonnemapping-panelet åbner så automatisk med en bemærkning.
3. **Preview-kortet** viser en opsummering af lageret i filen, aktive reservationer på de matchede varer og **reservationsvalget**. Det huskede valg er forvalgt (standard: se Q1).
4. Admin kan
   - ændre reservationsvalget og trykke **"Genberegn preview"**, hvorefter valget gemmes,
   - rette kolonner i panelet og trykke **"Genberegn preview med tilpasset kolonnemapping"** (som i dag),
   - trykke **"Gæt kolonner forfra"**. Så ses der bort fra det huskede valg, undtagen lokationskolonnerne. Intet gemmes, før admin genberegner eller anvender.
5. Admin gennemgår rækkerne. Hver vare viser foreslåede ændringer pr. lokation med Kims tal og reservationstillæg, linjen "Fil: …", "Reserveret i HSG: …" og eventuelle bemærkninger (ikke flettet variant, kontrolafvigelse, tom celle tolket som 0 og så videre).
6. Admin trykker **"Anvend valgte ændringer"**. I transaktionen beregnes ændringerne igen med de **aktuelle** reservationer. Lager og lagerbevægelser skrives, valgene huskes, og der logges i audit-loggen.
7. Flash-beskeden fortæller, hvor mange produkter der er opdateret og oprettet, og hvor mange reserverede flasker der er lagt oven i.
8. Produkter, der blev sat inaktive af den forkerte upload, bliver automatisk aktive igen, når Disponibelt > 0. Det sker via den eksisterende `hsg_sync_product_stock_status`.

---

## 3. Skærme

Alle tekster er på dansk. Der indføres ingen nye CSS-klasser. Der genbruges `.card`, `.muted`, `.badge`, `.badge.amber`, `.inline`, `.button.secondary`, `.flash.warning` og den eksisterende regel om, at bemærkninger, der starter med "Bemærk", vises med farven `var(--amber)`.

### 3.1 Upload-kortet (kun tekstændring)

Brødteksten erstattes af:

> Upload masterfil (Excel eller CSV). Filen opdaterer produktdata og lager i HSG Administration. Varenumre med et bogstav til sidst (fx 17-1028a) flettes ind i varenummeret uden bogstav (17-1028), og lagerantallet lægges sammen – men kun når navn og destilleri passer. Er det en anden whisky, står varen alene med en bemærkning. Kolonnevalg og reservationsvalg huskes fra seneste upload.

### 3.2 Preview-kortet (opsummering og reservationsvalg)

```
┌ Preview: lager 19-10-2026.xlsx ───────────────────────────────────────────┐
│ Fundet i Ark 1, overskriftsrække 1. Genkendte felter: SKU / Varenummer,    │
│ Produktnavn, Alder, Destilleri, Brand / Mærke, ABV, Lagerantal i alt, …    │
│                                                                            │
│ Lager i filen: 982 stk. i alt · Sum af lokationskolonner: 982 stk.         │
│ Aktive reservationer i HSG på matchede varer: 14 stk.                      │
│                                                                            │
│ Reservationer                                                              │
│ [Kims tal er efter reservationer – læg dem oven i            ▾] [Genberegn preview] │
│ Hvis Kim tæller reserverede flasker som solgt, skal reservationerne lægges │
│ oven i. Ellers trækkes de fra to gange, og varen kan forsvinde fra         │
│ Lagerstatus. Valget huskes til næste upload.                               │
│                                                                            │
│ [90–100 %] vælges som udgangspunkt automatisk. [70–89 %] kræver … (som i dag) │
└────────────────────────────────────────────────────────────────────────────┘
```

- "Lager i filen" er summen af Kims "Lagerantal i alt" (kontroltal) over alle varer. "Sum af lokationskolonner" er summen af Kims lokationstal efter reglen for tomme celler. Linjen vises kun for de kolonner, der er valgt. Er de to tal forskellige, vises linjen med `.badge.amber` og teksten "Afviger – se bemærkningerne på de enkelte varer".
- Reservationsvalget er en `<select name="reservation_mode">` i sin egen lille formular (`action=reservation_mode`, `preview_token`, `csrf_field()`) med `.inline`-layout, så den bryder pænt på mobil. Valgmuligheder:
  - `add`: **"Kims tal er efter reservationer – læg dem oven i"**
  - `none`: **"Kims tal er det fysiske lager – brug dem uændret"**
  - (kun hvis Q1 = "det varierer") `''`: **"– Vælg –"**
- Ekstra hjælpetekst under feltet: "Tallene beregnes igen med de aktuelle reservationer, når du trykker 'Anvend'."

### 3.3 Kolonnemapping-panelet

```
▸ ⚙️ Kolonnemapping (Tilknytning af felter)
  Vælg hvilken kolonne … (eksisterende tekst) …
  Vælger du kolonner til lokationerne, skrives lageret kun på dem, og
  "Lagerantal i alt" bruges kun som kontroltal. Uden lokationskolonner skrives
  "Lagerantal i alt" på første lokation (Christiansfeld).

  Fil-kolonne   Overskrift i dokument      Tilknyttet HSG-felt
  Kolonne #15   Lagerværdi                 [– Ignorer kolonne – ▾]
                                           ⚠ Husket valg "Lagerantal i alt" er ikke brugt:
                                             overskriften ligner et beløb.
  Kolonne #17   Lagerbeholdning            [Lagerantal i alt (kontroltal / første lokation) ▾]
  Kolonne #18   Christiansfeld             [Lagerantal for Christiansfeld ▾] Husket fra seneste upload
  …
  [Genberegn preview med tilpasset kolonnemapping]   [Gæt kolonner forfra]
  "Gæt kolonner forfra" ser bort fra det huskede valg (lokationskolonnerne beholdes).
  Intet gemmes, før du genberegner eller anvender.
```

- Panelet (`<details>`) er åbent, hvis `?remap=1`, hvis der ikke er genkendt nogen felter (som i dag), **eller** hvis mindst ét husket valg blev afvist (`col_rejected` er ikke tomt).
- Bemærkningen om et afvist husket valg vises som `<small>` med farven `var(--amber)`.
- "Gæt kolonner forfra" er en separat lille POST-formular (`action=reguess`, `preview_token`, `csrf_field()`) med knappen `class="button secondary"`.
- Etiketter:
  - I dropdown: `stock_quantity` = **"Lagerantal i alt (kontroltal / første lokation)"**
  - I ændringslisten: `stock_quantity` = **"Lagerantal i alt"**
  - Lokationer er uændrede ("Lagerantal for X" / "Lagerantal: X").

### 3.4 Preview-tabellen

Kolonnerne er uændrede: Opdatér | Filrække | Leverandørens vare | Match i HSG | Foreslåede ændringer.

**Lagerlinjer i "Foreslåede ændringer":**
- En ændring på en lokation:
  - Med reservationstillæg: `Lagerantal: Christiansfeld: 0 → 2 (fil 0 + 2 reserveret)`
  - Uden tillæg: `Lagerantal: Michael: 2 → 0`
- Under listen, med `<small class="muted">`. Vises altid, når der er valgt lokationskolonner, også når der ikke er nogen ændringer:
  - `Fil: Christiansfeld 41 · Michael 0 (tom) · Gert 12 · i alt 53`. "(tom)" betyder, at cellen var tom og tæller som 0. "i alt" er Kims kontroltal, hvis kolonnen er valgt.
  - `Reserveret i HSG: Christiansfeld 2 · Gert 1`. Vises kun, når det matchede produkt har aktive reservationer, uanset reservationsvalget.
- I totaltilstand (kun "Lagerantal i alt" er valgt): `Fil: lagerantal i alt 53 → skrives på Christiansfeld`.

**Eksempelrækker:**

```
☑ │ #45, #46 (Flettet) │ Jane Street …            │ [100 %] SKU er identisk              │ • Lagerantal: Christiansfeld: 3 → 5 (fil 3 + 2 reserveret)
  │                    │ SKU 17-1028 + 17-1028a   │ Lagerantal fra 17-1028a er lagt      │ • Lagerantal: Michael: 2 → 0
  │                    │                          │ sammen med 17-1028 (samme navn).     │ Fil: Christiansfeld 3 · Michael 0 (tom) · Gert 0 (tom) · i alt 3
  │                    │                          │ [17-1028 · Jane Street … ▾]          │ Reserveret i HSG: Christiansfeld 2

☐ │ #318               │ Clynelish, 2013, #231584 │ [Nyt produkt oprettes (Lager: 9)]    │ Fil: Christiansfeld 9 · Michael 0 (tom) · Gert 0 (tom) · i alt 9
  │                    │ - Hogshead               │ Bemærk: 12-1123a er ikke flettet med │
  │                    │ SKU 12-1123a · Fad #231584│ 12-1123: navnene er forskellige     │
  │                    │                          │ („Clynelish, 2013, #231584 - Hogshead“ / „St. Bridget Kirk, John Barleycorn Edition“).
  │                    │                          │ Varen behandles som sit eget varenummer og er ikke valgt på forhånd.
```

**Nye bemærkninger** (strenge i `items[].notes`, som renderes som i dag):

| Situation | Tekst |
|---|---|
| Variant flettet (eksisterende, udvidet med begrundelse) | `Lagerantal fra 17-1028a er lagt sammen med 17-1028 (samme navn).` |
| Variant ikke flettet | `Bemærk: 12-1123a er ikke flettet med 12-1123: <begrundelse>. Varen behandles som sit eget varenummer og er ikke valgt på forhånd.` |
| To enheder matcher samme HSG-produkt, men er forskellige varer | `Bemærk: Matcher samme HSG-produkt som 12-1123, men er en anden vare: <begrundelse>. Vælg produkt manuelt eller opret nyt.` |
| Kontrolafvigelse | `Bemærk: Summen af lokationskolonnerne (53) passer ikke med lagerantal i alt (60). Tjek kolonnevalget og rækken i Kims fil. Rækken er ikke valgt på forhånd.` |
| Alle lokationsceller tomme | `Bemærk: Ingen tal i lokationskolonnerne – lageret ændres ikke.` (ændret i tillæg 2026-10-20, KAN-1) |
| Tekst i en lokationscelle | `Bemærk: Ugyldig værdi „?“ for Gert – lokationen ændres ikke.` |
| Totaltilstand med lager på andre lokationer | `Bemærk: Lagerantal i alt (53) skrives på Christiansfeld. Produktet har også 14 stk. på andre lokationer, så lageret i alt bliver 67.` |
| Reservationsvalg `none` og Kims tal < reserveret | `Bemærk: Christiansfeld får 0 fysisk efter import, men 2 er reserveret. Disponibelt bliver −2, varen kan forsvinde fra Lagerstatus, og reservationen kan ikke markeres som solgt.` |

`<begrundelse>` er en af: `navnene er forskellige („A“ / „B“)`, `forskelligt fadnummer (#A / #B)`, `forskelligt destilleri (A / B)`, `forskellig årgang (A / B)`, `forskellig alder (A / B)`, `for få oplysninger til at afgøre, om det er samme vare`.

### 3.5 Bundtekst og flash-beskeder

Bundteksten under tabellen erstattes af:

> Kun de markerede rækker opdateres. Tomme felter i Kims fil overskriver aldrig produktdata. Undtagelse: lager pr. lokation – har en række et tal i mindst én lokationskolonne, tæller tomme lokationsceller i samme række som 0. Lager og reservationer beregnes igen, når du trykker "Anvend".

Flash-beskeder:
- **Reservationsvalg ændret** (success): "Reservationsvalget er gemt, og preview er genberegnet."
- **Gæt forfra** (success): "Kolonnerne er gættet forfra. Tjek valget, og tryk 'Genberegn' eller 'Anvend' for at gemme det."
- **Manuel beløbskolonne som lager** (warning): "Kolonnen „Lagerværdi“ kan ikke bruges som lagerantal, fordi overskriften ligner et beløb. Den er sat til „Ignorer kolonne“."
- **Gammelt preview** (error): "Previewet er lavet med en ældre version af systemet. Upload filen igen."
- **Anvend** (success): Den eksisterende tekst. Hvis tillægget er > 0, tilføjes " N reserverede flasker er lagt oven i Kims tal."
- **Q1 = "det varierer" og intet valg** (error): "Vælg, om Kims tal er før eller efter reservationer, før du anvender ændringerne."

### 3.6 Mobilvisning

Siden er en admin-side. Tabellen ligger fortsat i `.table-wrap` med vandret scroll. Reservationsvalget bruger `.inline`, så felt og knap står under hinanden på smalle skærme. Der er ingen nye layoutkomponenter.

---

## 4. Forretningsregler

### 4.1 Kolonnegæt (Fejl 1) – `hsg_supplier_suggest_columns`

**K1 – Rækkefølge:**
1. **Husket valg** (`supplier_import_header_map`) bruges først, som i dag, også "Ignorer". Undtagelse: K4. Reglen om, at felter uden kolonne sidst ikke gættes igen, er også uændret.
2. **Gennemløb 1:** præcise alias-match for alle øvrige kolonner fra venstre mod højre. Den første kolonne pr. felt vinder.
3. **Gennemløb 2:** delvise match for de kolonner, der stadig er tomme, fra venstre mod højre og kun til felter, der ikke allerede er brugt.

Et præcist match vinder dermed altid over et delvist match, uanset kolonnernes placering.

**K2 – Strammere delvist match.** Grundreglen er som i dag: den normaliserede overskrift indeholder et alias på mindst 4 tegn, eller aliaset indeholder overskriften. Derudover gælder:
- a. **Produktnavn** (`name`): Delvist match kun med aliaser på mindst 8 tegn (produktnavn, productname, itemname, varetekst, beskrivelse, description). Aliaserne "whisky", "navn", "produkt", "product", "item" og "title" kræver præcist match. Derfor bliver "sponsor til whisky.dk" ikke produktnavn.
- b. **Udsalgspris** (`retail_price`): Aldrig, hvis den normaliserede overskrift indeholder `engros`, `wholesale`, `netto`, `dealer`, `trade`, `indkob`, `kost` eller `cost`.
- c. **Engrospris** (`wholesale_price`): Nyt alias `engros`. Aldrig, hvis overskriften indeholder `udsalg`, `vejl`, `retail`, `rrp` eller `inklmoms`.

**K3 – Nyt præcist alias:** `variant` → Produktnavn (Kims kolonne F "Variant:").

**K4 – Beløbskolonner er aldrig lager.** En overskrift er en beløbsoverskrift, hvis den normaliserede tekst indeholder `vaerdi`, `value`, `pris`, `price`, `kost`, `cost`, `belob`, `amount` eller `moms`. Det samme gælder, hvis den rå overskrift indeholder ordet `kr`, `kr.`, `dkk`, `eur` eller `usd` (ordgrænse, uden forskel på store og små bogstaver). En beløbsoverskrift må aldrig tilknyttes `stock_quantity` eller `stock_loc_*`. Det gælder for gæt, for husket valg og for manuelt valg:
- Et husket valg, der afvises, sættes til `''` og registreres i `col_rejected`, som vises i panelet (3.3).
- Et manuelt valg (`remap`), der afvises, sættes til `''`, og der vises en warning-flash (3.5).

**K5 – Lokationskolonner gættes aldrig automatisk** (som i dag). Begrundelse: "michael" (AO) er et præcist navn, mens "hos Michael" (S) kun er et delvist match. Det samme gælder "optalt d. xxxx Christiansfeld" (U) over for "Christiansfeld" (R).

**K6 – "Gæt kolonner forfra":** Kolonnevalget beregnes som K1–K5 uden husket valg. Bagefter lægges de huskede `stock_loc_*`-valg ind igen på de kolonner, der efter gættet står tomme. Resultatet gemmes først ved "Genberegn" eller "Anvend".

**Forventet resultat for Kims fil uden husket valg:**

| Kolonne | Overskrift | Forslag |
|---|---|---|
| E | Varenr. | SKU / Varenummer |
| F | Variant: | Produktnavn |
| G | Alder: | Alder |
| H | Destilleri: | Destilleri |
| J | Aftapper: | Brand / Mærke |
| K | Alc.: | ABV |
| Q | Lagerbeholdning | Lagerantal i alt |
| Z | Engros salgspris (ex moms) | Engrospris |
| AA | vejl. salgspris (inkl moms) | Udsalgspris |
| C, L, M, O, R, S, T, U, Y, AI–AU | Stk., Kostpris, Solgt/ stk, Lagerværdi, Christiansfeld, hos Michael, hos Gert, optalt …, kostpris ex. Moms:, personkolonner | Ignorer kolonne |

R, S og T vælges én gang manuelt som "Lagerantal for Christiansfeld/Michael/Gert" og huskes derefter.

### 4.2 Lagerantal i alt over for lokationer (Fejl 2)

Tilstanden afgøres af **kolonnevalget**, ikke af værdierne i den enkelte række:

| Tilstand | Betingelse | Hvad skrives |
|---|---|---|
| **L – lokationer** | Mindst én kolonne er valgt som "Lagerantal for <lokation>" | Kun de valgte lokationer. "Lagerantal i alt" (hvis valgt) er **kun et kontroltal** og skrives aldrig. Lokationer uden kolonne røres ikke. |
| **T – total** | Ingen lokationskolonner, men "Lagerantal i alt" er valgt | Som i dag: tallet skrives på **første aktive lokation** (laveste `sort_order`, derefter `id`). I previewet vises det som en ændring af den lokation, fx "Lagerantal: Christiansfeld: 41 → 53", og ikke som "Lagerantal i alt". Har produktet lager på andre lokationer, vises bemærkningen fra 3.4. |
| **Ingen** | Ingen lagerkolonner | Intet lager ændres |

- **Kontrol (kun L):** Er "Lagerantal i alt" valgt, og har varen (efter fletning) mindst ét lokationstal, sammenlignes summen af Kims lokationstal med Kims total. Tallene sammenlignes **før** reservationstillæg. Er de forskellige, vises en kontrolbemærkning, og rækken er ikke forvalgt (flag `stock_mismatch`).
- **Nye produkter:** Samme regler. I tilstand L skrives totalen aldrig på første lokation. Badget "Nyt produkt oprettes (Lager: N)" viser summen af lokationstallene. I dag tælles totalen og lokationerne sammen, så tallet bliver dobbelt.
- **Anvend og opret (forsvar i dybden):** Fallbacken til første lokation i `hsg_supplier_apply_product` og `hsg_supplier_create_product` må kun køre, når kilden **ikke har nogen** `stock_loc_*`-nøgler. Den nuværende betingelse "ingen stock_loc_* blandt ændringerne" eller "intet lokationslager > 0" fjernes.
- Previewet viser aldrig en "Lagerantal"-ændring, som ikke bliver udført. Tallet `new` i en lagerændring er altid præcis det tal, der skrives i `lager_stock.quantity`.

### 4.3 Tomme lokationsceller (Fejl 3) – kun i tilstand L

For hver filrække og hver valgt lokationskolonne gælder:

| Celle | Tolkning |
|---|---|
| Tal (også 0). Decimaler afrundes, og negative tal bliver 0 | Tallet |
| Tom (`''` efter trim), og mindst én anden valgt lokationscelle i rækken har et tal | **0** |
| Tom, og ingen valgt lokationscelle i rækken har et tal | Ingen ændring (rækken bidrager ikke med lager) |
| Tekst, der ikke er et tal (fx "?", "se note") | Ukendt. Lokationen ændres ikke for varen, heller ikke hvis andre flettede rækker har tal, og der vises en bemærkning |

- **Fletning:** Reglen bruges først på hver række for sig. Derefter summeres rækkerne (`hsg_supplier_merge_source`, som i dag). En række med lutter tomme lokationsceller bidrager ikke. En lokation vises som "(tom)", hvis ingen af varens rækker havde et tal for den.
- **Begrundelse:** Kims egen formel R = Q − T − S regner tomme celler som 0. Med reglen bliver summen i HSG lig Kims "Lagerbeholdning". Eksempel 13-1021: R=41, S tom og T=12 giver 41 + 0 + 12 = 53 = Q.
- "Tomme felter overskriver aldrig produktdata" gælder fortsat for alle andre felter end lager pr. lokation.
- Reglen er ikke relevant i tilstand T.

### 4.4 Bogstav-varenumre og fletning (Fejl 4)

Hvad der er en variant, er uændret: `hsg_supplier_base_sku` ("17-1028a" → "17-1028").

**Er det samme vare?** Ny ren funktion `hsg_supplier_variant_compatible(array $a, array $b): array{ok: bool, reason: string}`. Her er `$a` varianten, og `$b` er enten basens række i filen eller HSG-produktet, hvis basen kun findes i HSG. Begge arrays bruger nøglerne `name`, `distillery`, `cask_number`, `vintage_year` og `age_text`.

*Stopregler (afviser altid):*
- S1 – Fadnummer udfyldt i begge og forskelligt. Der sammenlignes med store bogstaver og uden `#` og mellemrum.
- S2 – Destilleri *kendt* i begge og forskelligt efter `hsg_supplier_norm`. *Ukendt* betyder, at den normaliserede værdi er `''`, `na`, `ukendt`, `unknown` eller `diverse`. Det dækker "N/A", "-", "?" og tom.
- S3 – Årgang udfyldt i begge og forskellig.
- S4 – Alder: det første heltal i begge findes og er forskelligt.

*Navneregler (mindst én skal være opfyldt, ellers afvist):*
- N1 – `hsg_supplier_name_key(a.name) === hsg_supplier_name_key(b.name)` (ikke tom). Begrundelse: "samme navn".
- N2 – `similar_text` på navnenøglerne giver mindst 90 %. Begrundelse: "næsten samme navn".
- N3 – `similar_text` giver mindst 75 %, og destilleriet er det samme og kendt. Begrundelse: "samme destilleri og lignende navn".
- N4 – Navnet mangler på den ene side, og destilleriet er det samme og kendt.

Samme destilleri er ikke nok i sig selv. Kim kan have flere forskellige aftapninger fra samme destilleri.

**Forventet resultat i Kims fil:**

| Par | Resultat |
|---|---|
| 17-1028/17-1028a, 17-1029/17-1029a, 17-1030/17-1030a (Jane Street) | Flettes (samme navn) |
| 17-1038/17-1038a (Ben Nevis) | Flettes (samme navn) |
| 12-1097/12-1097a (Craigellachie) | Flettes (samme navn) |
| 12-1123 (St. Bridget Kirk, N/A, 15 stk.) / 12-1123a (Clynelish, 2013, #231584 - Hogshead, 9 stk.) | **Flettes ikke**: navnene er forskellige. Destilleriet "N/A" er ukendt og afviser derfor ikke i sig selv. |

**Konsekvenser i `hsg_supplier_merge_raw_items`:**
- **F1 – Basen står i filen:** Varianten flettes kun, hvis den passer med den første række, der har præcis basens varenummer.
- **F2 – Basen findes kun i HSG:** Varianten flettes kun, hvis den passer med HSG-produktet.
- **F3 – Varianten flettes ikke:**
  - Den bliver sin egen enhed med sit eget varenummer og får bemærkningen "ikke flettet" (3.4) og flaget `variant_split`.
  - Den er **aldrig forvalgt**.
  - Dens automatiske match må **aldrig** pege på HSG-produktet, hvis SKU er basens varenummer. Produktet fjernes fra kandidatlisten, før `hsg_supplier_find_match` kaldes. Det dækker match via alias-tabellen, fadnummer og navn. Hvis HSG-produktet 12-1123 fx har fået fadnummer 231584 ved den forkerte fletning, matcher 12-1123a det stadig ikke.
  - Findes der intet andet match, tilbydes "Opret som nyt produkt" (ikke forvalgt).
- **F4 – Trin 2 (enheder, der matcher samme HSG-produkt):**
  - Enhederne flettes kun, hvis `hsg_supplier_variant_compatible(enhedA.source, enhedB.source)` er ok.
  - Ellers beholder den enhed matchet, der har den højeste score. Er scoren lige, vinder den enhed, hvis SKU er lig produktets SKU. Er der stadig lighed, vinder den første i filen.
  - De andre enheder får match nulstillet til `['id'=>0,'score'=>0,'reason'=>'…']`, bemærkningen "Matcher samme HSG-produkt som …" og flaget `match_conflict`, og de er ikke forvalgt.
- **F5 – Alias-tabellen** (`hsg_supplier_sku_aliases`) er uændret:
  - En gammel, forkert kobling (12-1123a → produkt 12-1123) er uskadelig på grund af F3. Den bliver ikke slettet automatisk ved upload.
  - Når en række anvendes som **"Opret som nyt produkt"**, gemmes rækkens eget varenummer nu også som alias for det nye produkt via den eksisterende `hsg_supplier_remember_alias`. Den sletter samtidig ældre koblinger fra samme varenummer til andre produkter, så den forkerte kobling ryddes op.
  - Ved opdatering virker det som i dag: varenummeret gemmes, og gamle koblinger slettes.
- **F6 – Uændret adfærd:**
  - Når en variant flettes, summeres lagerfelterne, og de øvrige felter udfylder kun tomme felter.
  - Advarslen "findes også som selvstændigt produkt" vises stadig.
  - Flettede varianter gemmes stadig som alias ved "Anvend".
  - Dublet-fallbacken (match via en bogstav-variant, der findes i HSG) er uændret.

### 4.5 Reservationer

**Model (uændret):** Fysisk = `lager_stock.quantity` pr. lokation. Disponibelt = Fysisk − aktive reservationer (`lager_reservations.status='reserved'`) pr. produkt og lokation.

**Valg:** Gemmes i indstillingen `supplier_import_reservation_mode` i `hsg_settings`.
- `add` – Kims tal er efter reservationer. For hver lokation, hvor varen får et tal fra filen: **Fysisk = Kims tal + aktive reservationer for produktet på den lokation**. Disponibelt bliver dermed lig med Kims tal.
- `none` – Kims tal er det fysiske lager: **Fysisk = Kims tal**.

**Standard, når indstillingen ikke findes: `add`** (forudsat Q1 = "ja"). Begrundelse:
1. Filen peger på det. Personkolonnerne indgår i "Solgt/ stk" (Octomore 15.1: C=12, M=12, Q=0, personkolonner i alt 9).
2. Med `none` og et ark, der allerede har trukket reservationerne fra, bliver Disponibelt ≤ 0. Så sætter `hsg_sync_product_stock_status` varen inaktiv, den forsvinder fra Lagerstatus, og "Markér solgt" i `reservations.php` fejler med "Det fysiske lager er lavere end reservationen". Det er præcis den rapporterede fejl.
3. Med `add` bliver Disponibelt aldrig lavere end Kims eget tal.

Risikoen ved `add`: Hvis Kim **ikke** har trukket reservationerne fra, bliver Fysisk og Disponibelt for højt med antallet af reserverede flasker. Derfor viser previewet altid reservationerne, og valget kan ændres pr. upload.

Hvis Q1 = "nej", er standarden `none`. Hvis Q1 = "det varierer", er der ingen standard (se Q1).

**Huskes:** Valget gemmes, når det ændres i previewet (`action=reservation_mode`), og ved "Anvend". Ved upload forvælges den gemte værdi, og den kopieres ind i previewet som `reservation_mode`.

**Gælder kun:**
- eksisterende produkter (nye produkter har ingen reservationer),
- lokationer, der får et tal fra filen. I tilstand T er det første lokation, og der lægges reservationerne på den lokation oven i.
- Lokationer med "ingen ændring" (alle celler tomme eller ugyldig tekst) røres ikke.

**Previewet** viser reservationerne pr. lokation for det matchede produkt (`items[].reserved`), viser tillægget i ændringslinjen og advarer i tilstand `none`, når Kims tal er mindre end reserveret (3.4).

**Anvend:**
- Reservationerne læses igen inde i transaktionen. Det tal, der skrives, bygger altså på de aktuelle reservationer. Er der kommet en reservation til efter previewet, bruges den nye.
- Lagerbevægelsens `reference` bliver "Kims masterfil opdatering (+N reserveret)", når N > 0. Ellers er den uændret "Kims masterfil opdatering".

### 4.6 Forvalg af rækker (afkrydsningsfeltet "Opdatér")

Rækker forvælges som i dag (`pid > 0`, score ≥ 90 og mindst én ændring, eller "nyt produkt" med lager), **undtagen** når varen har et af flagene `variant_split`, `match_conflict` eller `stock_mismatch`.

### 4.7 Gamle previews

Preview-JSON får `format: 2`. Handlingerne "Anvend", "Genberegn", "Gæt forfra" og "Reservationsvalg" på et preview uden `format: 2` afvises med flash-beskeden fra 3.5, og intet ændres. Previews er kortlivede, så det er nok at uploade igen.

---

## 5. Data

- **Ingen skemaændring og ingen migration.**
- **`hsg_settings`:**
  - Ny nøgle `supplier_import_reservation_mode` med værdien `add` eller `none`. Den læses og skrives med `setting_get`/`setting_set`.
  - Den eksisterende nøgle `supplier_import_header_map` har uændret format. En afvist beløbskolonne gemmes som `''` (Ignorer) næste gang kolonnevalget gemmes, så fejlen retter sig selv.
- **`hsg_supplier_sku_aliases`:** Uændret struktur. Nyt: Der skrives et alias ved "Opret som nyt produkt" (F5).
- **`lager_stock` og `lager_stock_movements`:** Skrives som i dag (`movement_type='import'`). `reference` udvides med "(+N reserveret)", hvilket er under 180 tegn.
- **`lager_reservations`:** Læses kun og ændres aldrig af importen.
- **Preview-JSON** (`storage/import-previews/<token>.json`, runtime-fil) får nye nøgler:
  - `format` (2), `reservation_mode` (`add`/`none`/`''`) og `col_rejected` (`{kolonneindeks: afvist felt}`)
  - `items[].stock_file`:
    ```
    {
      mode:      'locations'|'total'|'none',
      locations: {stock_loc_<id>: int},   // Kims tal efter 4.3, summeret ved fletning
      blank:     [stock_loc_<id>],        // lokationer uden tal i nogen række
      invalid:   {stock_loc_<id>: rå tekst},
      total:     int|null,                // Kims "Lagerantal i alt"
      sum:       int
    }
    ```
  - `items[].reserved` (`{stock_loc_<id>: int}`, kun værdier > 0 for det matchede produkt)
  - `items[].flags` (liste med `variant_split`, `match_conflict` og `stock_mismatch`)
  - Hver lagerændring i `items[].changes['stock_loc_<id>']` får ud over `old`/`new` også `file` (Kims tal) og `reserved` (tillægget, kun når det er > 0)
- **Kildedata (`items[].source`):**
  - I tilstand L indeholder kilden **ikke** `stock_quantity`. Kims total gemmes i stedet som `stock_file_total`, som aldrig er en ændring og aldrig skrives.
  - I tilstand T omsættes totalen til `stock_loc_<første lokation>` og gemmes også som `stock_file_total`.
- **Produktdata i prepare, recalculate og apply:** Ud over de eksisterende `stock_loc_<id>` får produkterne `reserved_loc_<id>` for hver aktiv lokation. Tallene hentes med én grupperet forespørgsel på `lager_reservations WHERE status='reserved' GROUP BY product_id, location_id`.
- **Modulversion:** `supplier_upload` hæves fra 1.0.0 til 1.1.0 for at markere adfærdsændringen. Der er ingen migration. App-versionen hæves ved release efter release-tjeklisten.

---

## 6. Rettigheder og audit

- **Rettigheder er uændrede:**
  - Siden kræver `require_module_enabled('supplier_upload')` og `require_capability('imports.manage')`.
  - De nye POST-handlinger `reservation_mode` og `reguess` bruger `csrf_field()`. CSRF verificeres centralt i `auth.php`.
  - SQL med brugerinput bruger prepared statements, og alt output bruger `h()`.
- **Direkte brugerlinks** har ingen adgang. Modulet har ikke `link_access`, og importen er en administratorhandling.
- **Audit:**
  - `supplier_import.preview`: `reservation_mode` tilføjes i details.
  - `supplier_import.apply`:
    - På topniveau tilføjes `reservation_mode` og `reserved_added_total`.
    - Pr. opdateret produkt i `details[]` tilføjes `stock: [{location_id, old, new, file, reserved_added}]` for hver lagerændring. Feltet `reserved_added` tilføjes kun, når det er > 0.
    - Eksisterende nøgler bevares.
  - Ny hændelse `supplier_import.settings` (entity `import`) logges, når reservationsvalget ændres i previewet: `{reservation_mode: 'add'|'none'}`.
  - "Gæt forfra" og "Genberegn" logges ikke. De ændrer ingen data ud over det huskede kolonnevalg, som i dag heller ikke logges.

---

## 7. Påvirkede filer

| Fil | Ændring |
|---|---|
| `core/supplier_import.php` | Logikken i afsnit 4 (se funktionslisten nedenfor) |
| `supplier_upload.php` | Nye handlinger `reservation_mode` og `reguess`, format-tjek, flash ved afvist beløbskolonne, valg sendes videre til apply, audit-udvidelse, nye tekster og etiketter, visning af "Fil:", "Reserveret i HSG:" og opsummering |
| `tests/supplier_import_test.php` | **Ny.** Enhedstests af rene funktioner, uden database og netværk |
| `tests/run.sh` | Nyt trin "Enhedstest: Kims uploadfil" kører `php tests/supplier_import_test.php`, som giver exit-kode ≠ 0 ved fejl. Trinnumrene i `info`-linjerne opdateres (1/4 … 4/4) |
| `docs/SUPPLIER-UPLOAD.md` | Opdateres. Afsnittet "Lager" er forældet. Beskriv tilstand L/T, reglen for tomme celler, reservationsvalget, kontroltallet, reglen for bogstav-fletning og "Gæt forfra" |
| `modules/supplier_upload/module.php` | `version` → `1.1.0` |
| `assets/style.css` | Ingen ændring forventet |
| *Ved release* | `app_version.php`, README og `hsg-package.json` (release-tjekliste i CLAUDE.md) |

**Funktioner i `core/supplier_import.php`.** Signaturerne er bindende, fordi testene bruger dem. Alle nye parametre er valgfrie, så eksisterende kald virker uændret.

| Funktion | Ændring |
|---|---|
| `hsg_supplier_aliases()` | Tilføj `variant` (name) og `engros` (wholesale_price) |
| `hsg_supplier_header_is_money(string $header): bool` | **Ny.** K4 |
| `hsg_supplier_suggest_columns(array $headers, array $savedHeaderMap = [], ?array $allowedFields = null): array` | Uændret signatur. K1–K5 |
| `hsg_supplier_reguess_columns(array $headers, array $savedHeaderMap, ?array $allowedFields = null): array` | **Ny.** K6 |
| `hsg_supplier_location_cells(array $row, array $map): array` | **Ny.** Returnerer `['values'=>[stock_loc_x=>int], 'blank'=>[…], 'invalid'=>[stock_loc_x=>rå]]` efter 4.3 for én række |
| `hsg_supplier_source_fields(array $row, array $map, ?int $fallbackLocationId = null): array` | Tilstand L: `stock_loc_*` efter 4.3 og `stock_file_total`, aldrig `stock_quantity`. Tilstand T med `$fallbackLocationId`: `stock_loc_<fallback>` og `stock_file_total`. Tilstand T uden fallback-id: `stock_quantity` som i dag |
| `hsg_supplier_merge_source(array $primary, array $extra): array` | Summerer også `stock_file_total` |
| `hsg_supplier_variant_compatible(array $a, array $b): array` | **Ny.** 4.4 |
| `hsg_supplier_merge_raw_items(array $rawRows, array $fieldMap, array $products, ?int $brandId = null, int $start = 1, ?PDO $pdo = null, array $options = []): array` | `$options`: `fallback_location_id` (?int), `location_names` ([id=>navn], bruges til bemærkninger) og `add_reserved` (bool). F1–F4, 4.2-kontrol, `stock_file`, `reserved`, `flags`, forvalg (4.6) og `total_stock` = summen af lokationstal |
| `hsg_supplier_changes_for_product(array $src, array $product, array $options = []): array` | `$options['add_reserved']`. `stock_loc_*`: `new = file + (add_reserved ? reserved_loc : 0)`. Indgangen indeholder `file` og eventuelt `reserved`. `stock_file_total` er aldrig en ændring |
| `hsg_supplier_apply_product(PDO $pdo, int $productId, array $src, array $options = []): array` | Læser `reserved_loc_*`, sender `add_reserved` videre, bruger fallback kun uden `stock_loc_*` i `$src`, og udvider `reference` |
| `hsg_supplier_create_product(PDO $pdo, array $src): int` | Fallback kun, når `$src` ikke har nogen `stock_loc_*`-nøgle |
| `hsg_supplier_prepare_preview(...)` / `hsg_supplier_recalculate_preview(PDO $pdo, array $preview, array $customColMap, ?string $reservationMode = null)` | Lokationer sorteres efter `sort_order, id`. Første lokation bruges som fallback. `reserved_loc_*` og lokationsnavne hentes. Udfylder `format`, `reservation_mode` og `col_rejected`. Recalculate bruger K4 på manuelt valg |

---

## 8. Acceptkriterier

**Testdata til enhedstest af Kims overskrifter:** et array med indeks 0–46 (A–AU), hvor ukendte kolonner er `''`. Kendte kolonner:

| Indeks | Kolonne | Overskrift |
|---|---|---|
| 2 | C | Stk. |
| 4 | E | Varenr. |
| 5 | F | Variant: |
| 6 | G | Alder: |
| 7 | H | Destilleri: |
| 9 | J | Aftapper: |
| 10 | K | Alc.: |
| 11 | L | Kostpris |
| 12 | M | Solgt/ stk |
| 14 | O | Lagerværdi |
| 16 | Q | Lagerbeholdning |
| 17 | R | Christiansfeld |
| 18 | S | hos Michael |
| 19 | T | hos Gert |
| 20 | U | optalt d. xxxx Christiansfeld |
| 24 | Y | kostpris ex. Moms: |
| 25 | Z | Engros salgspris (ex moms) |
| 26 | AA | vejl. salgspris (inkl moms) |
| 34–42 | AI–AQ | Henrik stockflet, Keld, Brian, Mike Knudsen, jack, jørn, michael, kim, delle |
| 44 | AS | sponsor til whisky.dk |
| 45 | AT | per (hr.klingenberg@gmail.com) |
| 46 | AU | KWL (send til Klaus) |

Lokations-id i eksemplerne: 1 = Christiansfeld (første lokation), 2 = Michael, 3 = Gert.

### A. Kolonnegæt (Fejl 1)

1. **Givet** Kims overskrifter og intet husket kolonnevalg, **når** `hsg_supplier_suggest_columns` kaldes, **så** er E=`sku`, F=`name`, G=`age_text`, H=`distillery`, J=`brand_name`, K=`abv`, Q=`stock_quantity`, Z=`wholesale_price` og AA=`retail_price`. Alle andre kolonner, herunder O, AS og AI–AU, er `''`.
2. **Givet** overskrifterne `['Lagerværdi', 'Lagerbeholdning']` i den rækkefølge, **når** der gættes, **så** er Lagerbeholdning=`stock_quantity` og Lagerværdi=`''`.
3. **Givet** `hsg_supplier_header_is_money`, **så** giver den `true` for "Lagerværdi", "Lager kr.", "Lagerbeløb", "Kostpris" og "Værdi DKK" og `false` for "Lagerbeholdning", "Lagerantal", "Antal", "Christiansfeld", "hos Michael" og "Stk.".
4. **Givet** overskriften `['sponsor til whisky.dk']`, **når** der gættes, **så** er den `''`. **Givet** `['Whisky']`, **så** er den `name` (præcist alias).
5. **Givet** et husket valg `{"lagervaerdi":"stock_quantity","variant":"name"}`, **når** Kims overskrifter gættes, **så** er O=`''` (afvist), F=`name` (husket), og previewet har `col_rejected[14]='stock_quantity'`. Panelet åbner automatisk og viser "Husket valg … er ikke brugt: overskriften ligner et beløb."
6. **Givet** et husket valg, hvor en kolonne står til "Ignorer", **når** der gættes, **så** står den stadig til `''`, selvom den har et præcist alias. Det er den eksisterende adfærd, og den bevares.
7. **Givet** et preview, **når** admin manuelt sætter "Lagerværdi" til "Lagerantal for Christiansfeld" og genberegner, **så** står kolonnen til "Ignorer", der vises warning-flashen fra 3.5, og det huskede valg gemmes med `''` for `lagervaerdi`.
8. **Givet** et husket valg `{"christiansfeld":"stock_loc_1","engrossalgsprisexmoms":"retail_price","sponsortilwhiskydk":"name"}`, **når** "Gæt kolonner forfra" bruges, **så** er R=`stock_loc_1`, Z=`wholesale_price`, AA=`retail_price`, AS=`''` og F=`name`. `supplier_import_header_map` er uændret i databasen, indtil admin genberegner eller anvender.

### B. Lager pr. lokation og total (Fejl 2 og 3)

9. **Givet** map R→`stock_loc_1`, S→`stock_loc_2`, T→`stock_loc_3` og Q→`stock_quantity` og rækken R=41, S='', T=12, Q=53, **når** `hsg_supplier_source_fields` kaldes, **så** indeholder resultatet `stock_loc_1=41`, `stock_loc_2=0`, `stock_loc_3=12` og `stock_file_total=53` og **ingen** nøgle `stock_quantity`.
10. **Givet** samme map og rækken R='', S='', T='', Q=5, **så** har resultatet ingen `stock_loc_*`-nøgler, og `stock_file_total=5`. I previewet står "Bemærk: Ingen tal i lokationskolonnerne – lageret ændres ikke." (med "Bemærk:" efter tillæg 2026-10-20, KAN-1).
11. **Givet** rækken R=10, S='?', T='', **så** er `stock_loc_1=10` og `stock_loc_3=0`, og `stock_loc_2` findes ikke. Previewet viser "Bemærk: Ugyldig værdi „?“ for Michael – lokationen ændres ikke."
12. **Givet** et map uden lokationskolonner (kun Q→`stock_quantity`), Q=53 og `$fallbackLocationId=1`, **så** er `stock_loc_1=53` og `stock_file_total=53`, og der er ingen `stock_quantity`.
13. **Givet** HSG-produktet 13-1021 med Christiansfeld 41, Michael 2 og Gert 12 og ingen reservationer, og filrækken R=41, S tom, T=12, Q=53, **når** previewet laves, **så** er den eneste lagerændring "Lagerantal: Michael: 2 → 0". Der er ingen ændring af "Lagerantal i alt" og ingen ændring af Christiansfeld, og linjen "Fil: Christiansfeld 41 · Michael 0 (tom) · Gert 12 · i alt 53" vises. **Når** ændringen anvendes, **så** er lageret 41/0/12 = 53. Der findes præcis én ny lagerbevægelse (Michael, −2, `import`), og Christiansfeld er ikke ændret.
14. **Givet** den rigtige fil "lager 19-10-2026.xlsx" med kolonnevalget fra kriterie 1 plus R, S og T som lokationer, **når** previewet laves, **så** viser opsummeringen "Lager i filen: 982 stk. i alt". Ingen vare har en lagerændring med et tal fra kolonne O (fx 9856 for 13-1021). Afviger "Sum af lokationskolonner" fra 982, har hver afvigende vare en kontrolbemærkning og er ikke forvalgt.
15. **Givet** filrækken R=41, S='', T=12 og Q=60, **så** har varen bemærkningen "Summen af lokationskolonnerne (53) passer ikke med lagerantal i alt (60) …", flaget `stock_mismatch`, og den er ikke forvalgt.
16. **Givet** en ny vare (intet match) med R=5, S='', T=3 og Q=8, **når** previewet laves, **så** viser badget "Nyt produkt oprettes (Lager: 8)" (ikke 16). **Når** varen anvendes som nyt produkt, **så** har det Christiansfeld 5 og Gert 3 og ingen lagerrække på Michael. Totalen skrives ikke på første lokation.
17. **Givet** et map uden lokationskolonner og et HSG-produkt med Christiansfeld 41, Michael 2 og Gert 12 og Q=53, **når** previewet laves, **så** står der "Lagerantal: Christiansfeld: 41 → 53" og bemærkningen "Lagerantal i alt (53) skrives på Christiansfeld. Produktet har også 14 stk. på andre lokationer, så lageret i alt bliver 67." Der står **ikke** "Lagerantal i alt: 55 → 53".
18. **Givet** 17-1028 med R=5, S='', T='' og 17-1028a med R='', S=2, T='' og samme navn, **når** rækkerne flettes, **så** er varens lokationer Christiansfeld 5, Michael 2 og Gert 0, og kun Gert vises som "(tom)".

### C. Bogstav-varenumre (Fejl 4)

19. **Givet** `hsg_supplier_variant_compatible` med A = {name "Clynelish, 2013, #231584 - Hogshead", distillery "Clynelish", cask_number "231584", vintage_year 2013} og B = {name "St. Bridget Kirk, John Barleycorn Edition", distillery "N/A"}, **så** er `ok=false`, og `reason` indeholder "navnene er forskellige".
20. **Givet** to arrays med samme navn og samme destilleri, **så** er `ok=true` med reason "samme navn". **Givet** samme navn men fadnumrene "100" og "200", **så** er `ok=false` med "forskelligt fadnummer".
21. **Givet** filrækkerne 12-1123 (St. Bridget Kirk, N/A, 15 stk. på Christiansfeld) og 12-1123a (Clynelish …, 9 stk. på Christiansfeld) og et HSG-produkt med SKU 12-1123, **når** `hsg_supplier_merge_raw_items` kaldes, **så** returneres to varer:
    - 12-1123 matcher produktet med 15 stk.
    - 12-1123a har `match.id ≠ id(12-1123)`, flaget `variant_split`, bemærkningen "12-1123a er ikke flettet med 12-1123 …", total_stock 9, og den er ikke forvalgt.
22. **Givet** samme situation, men HSG-produktet 12-1123 har fadnummer "231584" og årgang 2013 (forurenet af den tidligere fletning), **så** matcher 12-1123a stadig **ikke** produktet 12-1123.
23. **Givet** at kun 12-1123a står i filen, og at HSG har produktet 12-1123 (St. Bridget Kirk), **så** flettes varianten ikke ind i 12-1123, matcher ikke 12-1123 og er ikke forvalgt.
24. **Givet** en database med den gamle alias-række `12-1123a → produkt 12-1123`, **når** filen uploades, **så** matcher 12-1123a ikke produktet 12-1123. **Når** 12-1123a anvendes som "Opret som nyt produkt", **så** peger aliaset `12-1123a` på det nye produkt, og koblingen til 12-1123 er slettet.
25. **Givet** filrækkerne 17-1028 og 17-1028a med samme navn og destilleri, **så** bliver de én vare med summeret lager og bemærkningen "Lagerantal fra 17-1028a er lagt sammen med 17-1028 (samme navn)." Det samme gælder 17-1029/a, 17-1030/a, 17-1038/a og 12-1097/a i den rigtige fil.
26. **Givet** to filrækker med forskellige varenumre X (lig HSG-produktets SKU) og Y (matcher samme produkt via navn ≥ 90 %, men har et andet fadnummer), **så** flettes de ikke. X beholder matchet, og Y har `match.id=0`, flaget `match_conflict` og bemærkningen "Matcher samme HSG-produkt som X …", og Y er ikke forvalgt.

### D. Reservationer

27. **Givet** Octomore 15.1 (filrække 292: Q=0, R=0, S og T tomme) matchet til et HSG-produkt med Christiansfeld fysisk 0 og to aktive reservationer på i alt 2 stk. på Christiansfeld, og reservationsvalget `add`, **når** previewet laves, **så** viser varen "Lagerantal: Christiansfeld: 0 → 2 (fil 0 + 2 reserveret)" og "Reserveret i HSG: Christiansfeld 2". **Når** ændringen anvendes, **så** er Fysisk 2, Reserveret 2 og Disponibelt 0. Lagerbevægelsen har reference "Kims masterfil opdatering (+2 reserveret)". En af reservationerne kan derefter markeres "Solgt" uden fejl.
28. **Givet** samme situation, men med valget `none`, **så** er der ingen lagerændring for Christiansfeld (0 → 0), og varen viser bemærkningen "Christiansfeld får 0 fysisk efter import, men 2 er reserveret …".
29. **Givet** et produkt uden aktive reservationer, **så** giver `add` og `none` præcis de samme ændringer.
30. **Givet** at indstillingen `supplier_import_reservation_mode` ikke findes, **når** en fil uploades, **så** er "Kims tal er efter reservationer – læg dem oven i" forvalgt (forudsat Q1 = ja). **Når** admin vælger "Kims tal er det fysiske lager" og trykker "Genberegn preview", **så** genberegnes previewet, indstillingen gemmes som `none`, der logges `supplier_import.settings`, og næste upload har `none` forvalgt.
31. **Givet** et HSG-produkt med Christiansfeld fysisk 5 og 2 stk. reserveret på Christiansfeld, filrækken R=3 (S og T tomme) og valget `add`, **når** previewet laves, **så** er der ingen lagerændring for Christiansfeld (3 + 2 = 5). **Når** der derefter oprettes en ny reservation på 1 stk. på Christiansfeld, rækken markeres manuelt, og der trykkes "Anvend", **så** skrives Christiansfeld = 6 (3 + 3 aktuelle reservationer), og audit-detaljerne viser `old: 5, new: 6, file: 3, reserved_added: 3` for lokationen.
32. **Givet** en gennemført "Anvend", **så** indeholder audit-hændelsen `supplier_import.apply` `reservation_mode`, `reserved_added_total` og pr. produkt `stock: [{location_id, old, new, file, reserved_added}]`, og flash-beskeden nævner antallet af reserverede flasker, der er lagt oven i.
33. **Givet** et produkt, som den forkerte upload satte inaktivt (Disponibelt ≤ 0), **når** den rettede upload anvendes med `add`, og Kims tal er > 0, **så** er produktet aktivt igen og vises på Lagerstatus.

### E. Generelt

34. **Givet** et preview-token fra før opdateringen (uden `format: 2`), **når** der trykkes "Anvend", **så** vises "Previewet er lavet med en ældre version af systemet. Upload filen igen.", og ingen data ændres.
35. **Givet** en bruger uden `imports.manage` eller et direkte brugerlink, **når** `supplier_upload.php` åbnes, **så** afvises adgangen som i dag. **Givet** en POST til de nye handlinger `reservation_mode` eller `reguess` uden gyldigt CSRF-token, **så** afvises den med HTTP 419.
36. **Givet** et husket kolonnevalg fra før opdateringen, der ikke indeholder beløbskolonner som lager, **når** samme filtype uploades, **så** bruges det huskede valg uændret (bagudkompatibilitet).
37. **Når** `bash tests/run.sh` køres, **så** er alle trin grønne, inklusive det nye trin "Enhedstest: Kims uploadfil". Testfilen dækker mindst kriterie 1–4, 8–12, 15, 16 (badge-tallet `total_stock`), 18–23 og 25–29 på funktionsniveau. For 27–29 dækkes den rene del i `changes_for_product`. Funktionerne er `suggest_columns`, `reguess_columns`, `header_is_money`, `source_fields`, `location_cells`, `merge_source`, `variant_compatible`, `merge_raw_items` med `$pdo = null` og `changes_for_product`. Release-negativtesten bekræfter, at `tests/` ikke kommer med i ZIP'en.
38. Alle nye tekster på siden er på dansk og stemmer med afsnit 3.

---

## 9. Uden for scope

- Automatisk gæt af lokationskolonner ud fra lokationsnavne (se K5).
- Import af Kims personkolonner (AI–AU) eller oprettelse og afstemning af reservationer ud fra dem.
- Ny betydning af tilstand T. Totalen skrives fortsat på første lokation og vises nu bare ærligt.
- Automatisk reparation af data fra uploaden den 19-10 (lager, felter på 12-1123, priser og navne på produkter, der blev oprettet den dag). Det klares ved at uploade igen og rette manuelt (afsnit 10).
- En brugerflade til at se og redigere alias-tabellen. Gamle koblinger slettes heller ikke ved upload, kun når en række anvendes (F5).
- Et kostprisfelt i HSG og import af Kostpris (L/Y).
- Ændringer i den almindelige Excel-lagerimport (`import.php`).
- Flere kolonneprofiler (én pr. leverandør eller filtype).
- Ændringer i `status.php`, `reservations.php` eller beregningen af disponibelt lager.

---

## 10. Engangsoprydning efter uploaden den 19-10 (drift, ingen kode)

Afsnittet er til brugeren og testeren og bygges ikke.

1. Opdatér til den version, der har rettelsen, og upload "lager 19-10-2026.xlsx" igen.
2. Åbn kolonnemapping-panelet. Har du ikke rettet kolonnevalget den 19-10 (Q5), så tryk "Gæt kolonner forfra". Tjek, at valget er: E SKU, F Produktnavn, Q Lagerantal i alt, R Christiansfeld, S Michael, T Gert, Z Engrospris og AA Udsalgspris, og at O, AS og AI–AU er sat til Ignorer. Tryk "Genberegn".
3. Vælg reservationsvalget ud fra dit svar på Q1. Gennemgå bemærkningerne, især kontrolafvigelser og 12-1123a. Anvend.
4. Tjek HSG-produktet **12-1123 (St. Bridget Kirk)** manuelt. Fletningen kan have udfyldt tomme felter med Clynelish-data (fadnummer 231584, årgang 2013, fadtype Hogshead). Opret eller tjek **12-1123a (Clynelish)**.
5. Den forkerte upload kan have udfyldt **tomme** udsalgspriser med Kims engrospris (Z), fordi felter kun udfyldes, hvis de er tomme. Den kan også have oprettet nye produkter med forkert navn, fordi AS blev brugt som navn. Find dem i audit-loggen `supplier_import.apply` fra den 19-10: `details[].fields` indeholder `retail_price`, og `action: created` viser de oprettede produkter. Ret dem manuelt.

---

## 11. Åbne spørgsmål til brugeren (opsummering)

1. **Q1:** Regner Kim reserverede flasker som solgt i sit ark: altid, aldrig eller det varierer? Svaret afgør standarden: `add`, `none` eller intet forvalg.
   - **Q1b:** Lægges en reservation oven i på den lokation, hvor den er reserveret i HSG, eller tager Kims formel den altid fra Christiansfeld?
   - **Q1c:** Oprettes der reservationer i HSG, efter Kims ark er lavet, men før det uploades?
2. **Q2:** Godkender du reglen for tomme lokationsceller: tom = 0, når rækken har mindst ét lokationstal, og ingen ændring, når alle er tomme? Alternativerne er "aldrig ændring" eller "altid 0".
3. **Q3:** Er reglen for bogstav-fletning (samme eller næsten samme navn og ingen modstridende fadnummer, destilleri, årgang eller alder) i orden? Eller skal "a"-varer altid være selvstændige produkter?
4. **Q4:** Er personkolonnerne AI–AU de samme flasker som reservationerne i HSG?
5. **Q5:** Rettede du kolonnevalget manuelt ved uploaden den 19-10, før du trykkede "Anvend"?
6. **Q6:** Er Z (Engros salgspris ex moms) lig HSG's Engrospris og AA (vejl. salgspris inkl moms) lig HSG's Udsalgspris?

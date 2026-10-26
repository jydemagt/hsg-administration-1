# Leverandør-upload

Modulet **Leverandør-upload** ("Kims uploadfil") kan analysere forskellige XLSX- og CSV-filer uden at kræve et fast HSG-format.

## Genkendte data
HSG forsøger automatisk at finde kolonnenavne og varedata for bl.a.:

- SKU / varenummer
- Produktnavn / varetekst (også Kims "Variant:")
- Fadnummer
- Fadtype
- Engrospris (også "Engros salgspris")
- Udsalgspris (også "vejl. salgspris")
- ABV
- Destilleri
- Alder
- Årgang
- Brand/leverandør
- Kategori og land
- Flaskestørrelse
- Outturn / antal flasker
- Lagerantal i alt og lagerantal pr. lokation

Hvis oplysninger som ABV, alder, årgang eller fadnummer står direkte i vareteksten, kan de udledes uden AI. Et fadnummer udledes kun, hvis det indeholder mindst ét ciffer (fx "#43" eller "Cask No. 231584"). Ord som "Cask Strength", "Cask Finish" og "Cask Craft" giver ikke et fadnummer, og et rigtigt nummer senere i teksten bruges i stedet.

## Kolonnegæt
Kolonnevalget foreslås i denne rækkefølge:

1. **Husket valg** fra seneste upload (se nedenfor) – også "Ignorer kolonne".
2. **Præcise match** på overskriften, fra venstre mod højre. Den første kolonne pr. felt vinder.
3. **Delvise match** for de kolonner, der stadig er tomme, og kun til felter, der ikke allerede er brugt. Det sker i to trin, begge fra venstre mod højre:
   1. Overskriften **indeholder** et alias (fx "Retail Price (inc VAT)" → Udsalgspris).
   2. Derefter, for de kolonner der stadig er tomme: et alias **indeholder** overskriften (fx "Price" → første ledige prisfelt).

Et præcist match vinder derfor altid over et delvist match, uanset kolonnernes placering ("Lagerbeholdning" vinder over "Lagerværdi"). En mere præcis overskrift vinder over en bar overskrift: I `SKU, Product, Stock, Price, Trade Price, Retail Price (inc VAT)` bliver "Trade Price" engrospris og "Retail Price (inc VAT)" udsalgspris, mens "Price" ignoreres, fordi begge prisfelter er taget. Ellers får "Price" det første ledige prisfelt i rækkefølgen engrospris, udsalgspris: engrospris i `SKU, Price` og `SKU, Price, Retail Price (inc VAT)`, men udsalgspris i `SKU, Trade Price, Price`.

Ekstra regler:

- **Produktnavn** gættes kun delvist ud fra lange aliaser (fx "produktnavn", "varetekst"). "whisky", "navn", "produkt", "item" og "title" kræver præcist match, så fx "sponsor til whisky.dk" ikke bliver produktnavn.
- **Udsalgspris** gættes aldrig på en overskrift med engros, netto, kost, cost m.fl. **Engrospris** gættes aldrig på en overskrift med udsalg, vejl, retail, rrp eller inkl. moms.
- **Beløbskolonner er aldrig lager.** En overskrift med fx værdi, pris, kost, beløb, moms, kr., DKK, EUR eller USD kan ikke knyttes til "Lagerantal i alt" eller en lokation – hverken ved gæt, husket valg eller manuelt valg. Et afvist husket valg vises i kolonnemapping-panelet (som åbner automatisk), og et afvist manuelt valg giver en advarsel.
- **Lokationskolonner gættes aldrig automatisk.** De vælges én gang manuelt ("Lagerantal for Christiansfeld" osv.) og huskes derefter.

### Kolonnevalg huskes
Kolonnemappingen gemmes server-side i `hsg_settings` (nøgle `supplier_import_header_map`), når preview genberegnes, eller ændringerne anvendes. Den gælder derfor på tværs af enheder og browsere.

- Kolonner matches på overskriftsnavn (normaliseret), ikke på placering, så rækkefølgen må gerne ændre sig.
- En kolonne, der sidst stod til "Ignorer kolonne", forbliver ignoreret – undtagen i redningen nedenfor.
- Kun nye overskrifter gættes automatisk, og et HSG-felt, der sidst ikke var knyttet til nogen kolonne, gættes ikke igen – undtagen varenummer og produktnavn ved et præcist match (fx "Varenr." og "Variant:"). Delvise match er stadig blokeret, så fx "Kundenummer" ikke bliver varenummer.
- **Redning ved upload:** Står der derefter hverken varenummer, produktnavn eller fadnummer i kolonnevalget, kan ingen rækker matches. Så bruges et husket "Ignorer kolonne" ikke på en kolonne, hvis overskrift præcist er et alias for varenummer eller produktnavn (første kolonne fra venstre pr. felt). Panelet åbner af sig selv og viser "⚠ Husket valg "Ignorer kolonne" er ikke brugt: uden varenummer og produktnavn kan ingen rækker matches." ved kolonnen. Næste "Genberegn" eller "Anvend" gemmer det rettede valg. Er en anden nøglekolonne valgt (fx fadnummer), respekteres "Ignorer". Andre kolonner (fx "Lagerbeholdning") reddes ikke, og et manuelt valg i kolonnemapping-panelet respekteres altid.

### Gæt kolonner forfra
Knappen **"Gæt kolonner forfra"** i kolonnemapping-panelet ser bort fra det huskede valg og gætter alle kolonner igen. De huskede lokationskolonner lægges ind igen bagefter. Intet gemmes, før du trykker "Genberegn" eller "Anvend".

## Varenumre med bogstav til sidst
Et varenummer med ét bogstav direkte efter et tal (fx `17-1028a`) er en variant af varenummeret uden bogstav (`17-1028`).

- Står begge i filen, eller findes `17-1028` allerede i HSG, flettes rækkerne – **men kun når det er samme vare**:
  - Fadnummer, kendt destilleri, årgang eller alder må ikke være forskellige ("N/A", "ukendt" og tom tæller som ukendt destilleri).
  - Navnet skal være det samme eller næsten det samme. Et lignende navn er nok, hvis destilleriet er det samme og kendt. Samme destilleri alene er ikke nok.
  - Står basen i filen, sammenlignes med den første række med basens varenummer. Findes basen kun i HSG, sammenlignes med HSG-produktet.
- Ved fletning lægges lagerantallet (også pr. lokation) sammen og skrives på `17-1028`. Øvrige oplysninger tages fra rækken uden bogstav. Previewet viser begrundelsen, fx "(samme navn)".
- Er det **ikke** samme vare, står varianten alene med sit eget varenummer, en bemærkning med begrundelsen og er ikke valgt på forhånd. Dens automatiske match peger aldrig på HSG-produktet med basens varenummer – heller ikke via en gammel alias-kobling, fadnummer eller navn.
- **Basens ejer** i HSG er produktet, som en gemt alias-kobling for `17-1028` peger på, ellers produktet med SKU `17-1028`. Ejeren findes **uden** brand-filteret og bruges kun til at udelukke: Er varianten ikke samme vare som ejeren, kan dens match hverken pege på ejeren eller på produktet med basens SKU – også når ejeren hører til et andet brand end det valgte, eller kun er koblet via alias (fx et HSG-produkt med SKU `HSG-55`).
- **Brand-filteret respekteres ved fletning.** Er uploaden begrænset til ét brand, flettes varianten kun ind i basen, når basen findes med brand-filteret (eller via en gemt alias-kobling for basen). Ligger basen uden for brandet, og er det samme vare, flettes varianten ikke og står som en almindelig række. Er det en anden vare, får varianten bemærkningen "ikke flettet" og er ikke valgt på forhånd – også når basen ligger uden for brandet.
- Varianten gemmes som alias (`hsg_supplier_sku_aliases`) for produktet, når ændringerne anvendes. Oprettes en række som nyt produkt, gemmes rækkens eget varenummer også som alias for det nye produkt, og ældre koblinger fra samme varenummer slettes.
- Findes kun `17-1028a`, og findes `17-1028` heller ikke i HSG, behandles `17-1028a` som sit eget varenummer, ligesom før. Der oprettes ikke noget `17-1028`.
- Findes `17-1028a` allerede som et selvstændigt produkt i HSG, ændres det ikke. Previewet viser en advarsel, så det kan sættes som udgået, og lageret ikke tælles dobbelt.

Matcher to forskellige varer i filen det samme HSG-produkt, flettes de kun, hvis de er samme vare efter samme regler. Ellers beholder den bedste (højeste score, derefter varen med produktets varenummer, derefter den første i filen) matchet, og de andre skal vælges manuelt.

## Produktmatch
Match prioriteres i denne rækkefølge:

1. Eksakt SKU
2. Eksakt fadnummer
3. Stærkt navnematch
4. Supplerende kontrol af destilleri, årgang, alder og ABV

Et fadnummer skal indeholde mindst ét ciffer for at tælle. Ord som "Finish", "Strength" og "Craft" udledes ikke længere af navnet ("Cask Finish", "Cask Strength"). Reglen ved matching er et ekstra værn mod fadnumre uden ciffer, som er indtastet manuelt, kommer fra filens Fadnummer-kolonne eller endnu ikke er rettet (se Produkter → "Fadnr. uden ciffer"): De giver hverken match på fadnummer eller "forskelligt fadnummer", når varianter sammenlignes.

Match på 90 % eller mere er valgt som udgangspunkt i previewet. Usikre matches opdateres aldrig automatisk. Rækker med en ikke-flettet variant, en matchkonflikt eller en kontrolafvigelse på lageret er aldrig valgt på forhånd.

## Preview og opdatering
Uploaden ændrer ikke data med det samme. Først vises et preview med gammel og ny værdi. Administratoren vælger derefter de rækker, der skal anvendes. Previewet viser kun ændringer, der faktisk bliver udført, med de tal, der bliver skrevet.

Tomme felter fra leverandørfilen overskriver aldrig eksisterende HSG-data. Undtagelsen er lager pr. lokation (se nedenfor).

Previews fra en ældre version af systemet kan ikke anvendes. Upload filen igen.

**Et tomt preview siger hvorfor.** Er hverken varenummer, produktnavn eller fadnummer valgt i kolonnemappingen, står der "Bemærk: Varenummer og produktnavn er ikke valgt, så ingen rækker kan matches. …". Er de valgt, men gav filen ingen rækker (kolonnerne er tomme), står der "Bemærk: Filen gav ingen rækker. …". Kolonnemapping-panelet åbner af sig selv, og i stedet for tabellen og "Anvend valgte ændringer" vises "Der er ingen rækker at anvende." med knappen "Annuller preview". Et tomt preview kan ikke anvendes: Intet gemmes, heller ikke kolonnevalget.

**Previews ryddes op.** Et preview gemmes som en fil i `storage/import-previews/` og indeholder bl.a. kundenavne og referencer fra reservationerne. Det slettes, når ændringerne anvendes. Et preview, der ikke anvendes (også efter "Annuller preview"), slettes automatisk efter 24 timer (ved næste upload) og kommer ikke med i backup.

## Lager
Hvordan lageret skrives, afgøres af kolonnevalget:

| Tilstand | Når | Hvad skrives |
|---|---|---|
| **Lokationer** | Mindst én kolonne er valgt som "Lagerantal for <lokation>" | Kun de valgte lokationer. "Lagerantal i alt" er kun et kontroltal og skrives aldrig. Lokationer uden kolonne røres ikke. |
| **Total** | Kun "Lagerantal i alt" er valgt | Tallet skrives på første aktive lokation (laveste sortering). Previewet viser det som en ændring af den lokation og advarer, hvis produktet også har lager andre steder. |
| **Intet** | Ingen lagerkolonner | Lageret ændres ikke. |

**Tomme lokationsceller:** Har en række et tal i mindst én lokationskolonne, tæller tomme lokationsceller i samme række som 0 (som i Kims egen formel). En celle med kun mellemrum, også hårde mellemrum, er tom. Er alle lokationsceller tomme, ændres lageret ikke for rækken. Tekst, der ikke er ét tal (fx "?"), ændrer aldrig lokationen og giver en bemærkning.

**Hvad er et tal i en lagercelle?** Reglen gælder både lokationscellerne og "Lagerantal i alt". Cellen skal være præcis ét tal: valgfrit minus (`-`), cifre og højst ét decimaltegn (`.` eller `,`) med cifre efter. Et komma er altid decimaltegnet.

- Mellemrum (også hårde mellemrum) før og efter tallet ignoreres. En celle med kun mellemrum er tom.
- Der må højst være **5 cifre før decimaltegnet** (så højst 100.000 efter afrunding), og foranstillede nuller tæller med. Så bliver EAN-numre, lange varenumre og meget store tal ugyldige. Decimaldelen må være så lang, den vil.
- Decimaler afrundes **én gang** til nærmeste hele tal (,5 rundes væk fra 0), så `0.4999` bliver 0.
- **Negative tal bliver 0, får en bemærkning, og arkiveringen er ikke forvalgt.** Et tal er negativt, når den afrundede værdi er under 0 (`-1`, `-0,5`, `-99999`, men ikke `-0` eller `-0,4`). Varen får bemærkningen "Negativ værdi „-1“ for Gert behandles som 0." (eller "… for lagerantal i alt behandles som 0."). "Fil:"-linjen viser 0. Se også [Forvalg](#forvalg).
- Ugyldig tekst vises i bemærkningen og på "Fil:"-linjen, afkortet til 40 tegn + "…".

| Celle | Tolkes som |
|---|---|
| `41`, `0`, `41.0` | 41, 0, 41 |
| `2,4`, `2.6`, `-2` | 2, 3, 0 (negativ: bemærkning) |
| `0.4999`, `2,4999` | 0, 2 |
| `0.5`, `0,5`, `2,5`, `2,5000` | 1, 1, 3, 3 |
| `99999` | 99999 |
| `40.999999999999993` (formelresultat) | 41 |
| `5` efterfulgt af et hårdt mellemrum | 5 |
| Kun hårde mellemrum (evt. blandet med almindelige mellemrum) | Tom |
| `3 + 2`, `53 stk.`, `2 (1 i bil)`, `1/2`, `ca. 5`, `se note 2`, `3 2`, `?`, `-`, `−2` (typografisk minus) | Ugyldig |
| `1.234`, `1,234`, `1 234`, `1.875` | Ugyldig |
| `100000`, `000041`, `5010494560018` (EAN), `9223372036854775808` | Ugyldig (mere end 5 cifre) |
| Ugyldig tekst på over 40 tegn | Ugyldig, vises med de første 40 tegn + "…" |

**Tusindtalsformater er ugyldige:** præcis tre cifre efter `.` eller `,`, mellemrum inde i tallet eller både `.` og `,`. Læsningen ville ellers være tvetydig ("1.234" som 1 eller 1234). Ingen lokation har så mange flasker. Reglen rammer tekstceller og CSV, men også en Excel-talcelle med præcis tre decimaler (fx 1,875, typisk et formelresultat): Den er ugyldig.

**En ugyldig celle skrives aldrig som lager:**

- **Lokationscelle:** Lokationen ændres ikke, og varen får en bemærkning.
- **"Lagerantal i alt" i tilstand Lokationer:** Totalen bruges ikke som kontroltal, så der er ingen kontrol og ingen afvigelse. Lokationerne skrives som normalt. Bemærkningen er "Ugyldig værdi „…“ for lagerantal i alt – bruges ikke som kontroltal."
- **"Lagerantal i alt" i tilstand Total:** Lageret ændres ikke for rækken. Bemærkningen er "Ugyldig værdi „…“ for lagerantal i alt – lageret ændres ikke."
- Rækken er valgt på forhånd efter de normale regler. Flettes flere rækker, og har én af dem en ugyldig total, er hele varens total ugyldig. Der bruges aldrig en delsum.

**Kontroltal:** Er både lokationer og "Lagerantal i alt" valgt, sammenlignes summen af lokationstallene med totalen (før reservationer). Afviger de, får varen en bemærkning og er ikke valgt på forhånd. Previewet viser desuden "Lager i filen" og "Sum af lokationskolonner" for hele filen. "Lager i filen" tæller kun gyldige totaler.

**Nye produkter** får kun lager på de lokationer, der har et tal over 0. Badget "Nyt produkt oprettes (Lager: N)" viser summen af lokationstallene.

### Reservationer
Fysisk lager er `lager_stock.quantity`. Disponibelt er fysisk minus aktive reservationer. Reservationsvalget i previewet afgør, hvordan Kims tal tolkes:

- **"Kims tal er efter reservationer – læg dem oven i"** (standard): Fysisk = Kims tal + aktive reservationer på samme lokation. Disponibelt bliver dermed lig Kims tal.
- **"Kims tal er det fysiske lager – brug dem uændret"**: Fysisk = Kims tal. Er Kims tal lavere end det reserverede, advarer previewet, fordi disponibelt bliver negativt, varen kan forsvinde fra Lagerstatus, og reservationen ikke kan markeres som solgt.

Valget gemmes i `hsg_settings` (nøgle `supplier_import_reservation_mode`), når det ændres i previewet, og når ændringerne anvendes. Det gælder kun eksisterende produkter og kun lokationer, der får et tal fra filen. Previewet viser reservationerne pr. vare ("Reserveret i HSG: …").

Når der trykkes "Anvend", beregnes lageret igen med de **aktuelle** reservationer. Lagerbevægelsen får referencen "Kims masterfil opdatering (+N reserveret)", når der er lagt reservationer oven i. Audit-loggen (`supplier_import.apply`) indeholder reservationsvalget, antallet af reserverede flasker, der er lagt oven i, og lagerændringerne pr. lokation. En ændring af reservationsvalget logges som `supplier_import.settings`.

Har en vare ikke længere lager i Kims fil (0 i alt), foreslås dens aktive reservationer arkiveret, så de ikke lægges oven i Kims tal. Se [Arkivering af reservationer, der ikke længere har lager](#arkivering-af-reservationer-der-ikke-længere-har-lager).

## Kun lagerantal
Under "Hvad skal opdateres?" på upload-kortet og i previewet vælges, hvad filen opdaterer:

| Valg | Hvad sker der |
|---|---|
| **Produktdata og lager** (standard) | Som beskrevet ovenfor: produktdata, lager og nye produkter. |
| **Kun lagerantal** | Kun lager pr. lokation. Ingen produktdata ændres, og der oprettes ingen nye produkter. |

I **Kun lagerantal** gælder:

- **Matchning, fletning og bemærkninger er uændrede.** Varenummer, navn, destilleri, fadnummer osv. bruges stadig til at finde varen i HSG og til bogstav-fletning. De bliver bare ikke skrevet.
- **Kun lageret ændres:** lager pr. lokation (og "Lagerantal i alt" på den ældre vej uden lokationer). Navn, priser, fadnummer, fadtype, ABV, destilleri, alder, årgang, kategori, land, flaskestørrelse, antal flasker og brand røres ikke.
- **Lageret skrives efter de samme regler** som ellers: tilstand Lokationer/Total, lagercellen, tomme celler, kontroltal, bogstav-fletning og reservationsvalget.
- **Ingen nye produkter.** En vare uden match er markeret "Springes over" og er ikke valgt. Dropdown'en tilbyder ikke "Opret som nyt produkt". En markeret række uden produkt springes over ved "Anvend" (også hvis "Opret som nyt" er sendt med), og flash-beskeden fortæller, hvor mange rækker der er sprunget over.
- **Et HSG-produkt kan stadig vælges manuelt** i dropdown'en. Så skrives kun lageret på det produkt.
- Varenummer-koblingen (alias) gemmes som ellers, og produktets status aktiv/inaktiv følger disponibelt lager.
- Er der ikke valgt nogen lagerkolonner, ændrer Kun lagerantal intet, og previewet siger det.

Valget gemmes i `hsg_settings` (nøgle `supplier_import_scope`, værdien `full` eller `stock_only`) ved upload, når det ændres i previewet ("Genberegn preview"), og når ændringerne anvendes. Upload-kortet forvælger det gemte valg. "Anvend" bruger altid previewets valg. Et preview fra en ældre version uden valget behandles som "Produktdata og lager". Et nyt valg i previewet logges som `supplier_import.settings`.

## Arkivering af reservationer, der ikke længere har lager
Er Kims lager i alt for en vare **entydigt 0**, er der ingen flasker bag varens reservationer. Så foreslås de aktive reservationer **arkiveret**. Det gælder i begge valg ("Produktdata og lager" og "Kun lagerantal"), så samme fil altid giver samme lagertal.

### Hvornår er lageret entydigt 0?
Vurderingen sker **pr. vare i alt**, ikke pr. lokation:

| Tilstand | Entydigt 0, når |
|---|---|
| **Lokationer** | Mindst én lokationscelle har et tal, ingen lokationscelle er ugyldig, "Lagerantal i alt" er ikke ugyldig, summen af lokationerne er 0, og "Lagerantal i alt" er tom eller 0. |
| **Total** | "Lagerantal i alt" er 0 (og gyldig) og skrives på første lokation. |
| **Intet** | Aldrig. |

Derfor giver følgende **aldrig** arkivering: varer, der ikke står i filen (eller ligger uden for brand-filteret), varer uden match, rækker hvor alle lokationsceller er tomme, ugyldige celler, en ugyldig total og en kontrolafvigelse. En tom lokationscelle tæller som 0 efter den sædvanlige regel, når rækken har et tal i en anden lokationskolonne. Flettede varer (17-1028 + 17-1028a) bruger summen af rækkerne.

Et negativt tal (fx "-1") tæller som 0, så varen kan være entydigt 0. Arkiveringen tilbydes da, men er aldrig valgt på forhånd (se [Forvalg](#forvalg)).

**Eksempler:**

| | Fil | HSG før | Resultat |
|---|---|---|---|
| **A** | Christiansfeld 0, Michael tom, Gert 0, i alt 0 | Gert fysisk 2, to reservationer på 1 stk. hos Gert | Begge reservationer foreslås arkiveret. Gert 2 → 0. |
| **B** | Christiansfeld 5, Michael tom, Gert 0, i alt 5 | Christiansfeld 5, Gert 1, én reservation på 1 stk. hos Gert | Intet arkiveres: Der er stadig lager, og en flaske kan flyttes til Gert. Med "læg dem oven i" forbliver Gert 1. |
| **C** | I alt 1 (delvist lager) | Christiansfeld fysisk 4, tre reservationer på 1 stk. | Intet arkiveres. Med "læg dem oven i" forbliver fysisk 4. Med "brug dem uændret" bliver fysisk 1, og previewet advarer som altid. |

### Hvilke reservationer foreslås?
Alle aktive reservationer på det matchede produkt, **som ligger på en lokation, der får et tal fra filen** (de valgte lokationskolonner, eller første lokation i tilstand Total). Reservationer på andre lokationer røres ikke, og varen får en bemærkning, fx "Bemærk: 1 reservation på Gert arkiveres ikke, fordi lokationen ikke får et tal fra filen." Previewet viser listen "Arkivér reservationer – Kims lager i alt er 0:" med et afkrydsningsfelt pr. reservation (nummer, lokation, antal, kunde, reference og oprettelsesdato), sorteret efter oprettelse.

### Forvalg
- På en række med et **sikkert match** (90 % eller mere uden flag) er alle varens reservationer forvalgt til arkivering, og rækken er valgt, også selv om lageret ikke ændres.
- På rækker, der kræver vurdering, er arkiveringen ikke forvalgt.
- Har Kims fil en negativ værdi for varen (en lokation eller "Lagerantal i alt"), er arkiveringen heller ikke forvalgt, selv ved et sikkert match. Varen får bemærkningen "Bemærk: Arkiveringen er ikke valgt på forhånd, fordi Kims fil har en negativ værdi for varen." Admin kan selv afkrydse reservationerne. Rækkens forvalg følger de normale regler (arkiveringen alene gør ikke rækken valgt).
- Previewets tal bygger på forvalget: Lagerændringen beregnes, som om de forvalgte reservationer er arkiveret, og viser fx "Gert: 2 → 0 (fil 0, 2 reserveret arkiveres)". Advarslen ved "brug dem uændret" og bemærkningen i tilstand Total bruger også tallene efter forvalget.
- Fravælges en reservation i browseren, beregnes previewet ikke igen. "Anvend" beregner altid ud fra de reservationer, der faktisk er afkrydset. En fravalgt reservation forbliver aktiv og lægges oven i Kims tal (eller giver negativt disponibelt med "brug dem uændret").
- "Reserveret i HSG: …" viser stadig alle aktive reservationer.

### Status "Arkiveret"
Arkiverede reservationer får status **Arkiveret** (`archived`) og tæller ikke længere som reserveret nogen steder. Arkiveringen ændrer kun status: Lageret nedskrives ikke, og der skrives ingen lagerbevægelse og intet salg, for Kims 0 er allerede efter salget. De arkiverede reservationer kan ses under **Reservationer → Arkiveret** (kun visning). En arkiveret reservation kan ikke genåbnes. Er den arkiveret ved en fejl, rettes lageret, og der oprettes en ny reservation.

Statusværdien kommer med modulet Reservationer 1.3.0, som udvider kolonnen ved første sidevisning efter opdateringen. Kan databasen endnu ikke gemme status "Arkiveret", afvises "Anvend" helt med en fejlbesked, og intet ændres.

### Lagerberegning ved "Anvend"
"Anvend" sker i én transaktion. For hver markeret række arkiveres de afkrydsede reservationer **først** – men kun når rækkens produkt er previewets match, og kun reservationer, der stadig er aktive. Derefter beregnes lageret med de reservationer, der stadig er aktive:

| Reservationsvalg | Fysisk på en lokation med et tal fra filen |
|---|---|
| "læg dem oven i" | Kims tal + aktive reservationer, som **ikke** arkiveres i samme "Anvend" |
| "brug dem uændret" | Kims tal (uændret) |

Eksempel A (Gert, Kims tal 0, to reservationer på 1 stk.):

| HSG før | Valg | Uden arkivering | Begge arkiveres | Kun den ene arkiveres |
|---|---|---|---|---|
| Fysisk 2 | læg oven i | 2 → 2, ingen bevægelse | 2 → 0, bevægelse −2 | 2 → 1, bevægelse −1 |
| Fysisk 2 | brug uændret | 2 → 0, disponibelt −2 og advarsel | 2 → 0, disponibelt 0 | 2 → 0, disponibelt −1 |
| Fysisk 0 | læg oven i | 0 → 2 | 0 → 0, ingen bevægelse | 0 → 1 |

Lagerbevægelsen er importens egen (`import`) og får referencen:

| | Reference |
|---|---|
| Tillæg N og M arkiverede stk. | `Kims masterfil opdatering (+N reserveret, M arkiveret)` |
| Kun tillæg | `Kims masterfil opdatering (+N reserveret)` |
| Kun arkiverede | `Kims masterfil opdatering (M arkiveret)` |
| Ingen af dem | `Kims masterfil opdatering` |

Afkrydsede reservationer, der ikke er kandidater på en markeret række, ignoreres. Kunne en afkrydset reservation ikke arkiveres (den er ikke længere aktiv, eller der er valgt et andet produkt end previewets match), giver "Anvend" en advarsel. Uploades samme fil igen, er der intet at arkivere, og lageret ændres ikke.

### Audit og hooks
- `supplier_import.apply` får `import_scope`, `reservations_archived`, `reservations_archived_qty` og `rows_skipped`. Pr. produkt står de arkiverede reservationer i `archived_reservations`, og lagerændringerne har `archived`.
- Hver arkiveret reservation logges som `reservation.archive` med kørslens id (`import_run_id`), så den kan findes i audit-loggen på sit id.
- Efter commit kaldes hooken `reservation.archived` pr. reservation (`reservation_id`, `product_id`, `location_id`, `quantity`, `source`). En hook, der fejler, skrives i fejlloggen og vælter ikke den gemte import.

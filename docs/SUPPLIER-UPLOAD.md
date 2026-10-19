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

Hvis oplysninger som ABV, alder, årgang eller fadnummer står direkte i vareteksten, kan de udledes uden AI.

## Kolonnegæt
Kolonnevalget foreslås i denne rækkefølge:

1. **Husket valg** fra seneste upload (se nedenfor) – også "Ignorer kolonne".
2. **Præcise match** på overskriften, fra venstre mod højre. Den første kolonne pr. felt vinder.
3. **Delvise match** for de kolonner, der stadig er tomme, og kun til felter, der ikke allerede er brugt.

Et præcist match vinder derfor altid over et delvist match, uanset kolonnernes placering ("Lagerbeholdning" vinder over "Lagerværdi").

Ekstra regler:

- **Produktnavn** gættes kun delvist ud fra lange aliaser (fx "produktnavn", "varetekst"). "whisky", "navn", "produkt", "item" og "title" kræver præcist match, så fx "sponsor til whisky.dk" ikke bliver produktnavn.
- **Udsalgspris** gættes aldrig på en overskrift med engros, netto, kost, cost m.fl. **Engrospris** gættes aldrig på en overskrift med udsalg, vejl, retail, rrp eller inkl. moms.
- **Beløbskolonner er aldrig lager.** En overskrift med fx værdi, pris, kost, beløb, moms, kr., DKK, EUR eller USD kan ikke knyttes til "Lagerantal i alt" eller en lokation – hverken ved gæt, husket valg eller manuelt valg. Et afvist husket valg vises i kolonnemapping-panelet (som åbner automatisk), og et afvist manuelt valg giver en advarsel.
- **Lokationskolonner gættes aldrig automatisk.** De vælges én gang manuelt ("Lagerantal for Christiansfeld" osv.) og huskes derefter.

### Kolonnevalg huskes
Kolonnemappingen gemmes server-side i `hsg_settings` (nøgle `supplier_import_header_map`), når preview genberegnes, eller ændringerne anvendes. Den gælder derfor på tværs af enheder og browsere.

- Kolonner matches på overskriftsnavn (normaliseret), ikke på placering, så rækkefølgen må gerne ændre sig.
- En kolonne, der sidst stod til "Ignorer kolonne", forbliver ignoreret.
- Kun nye overskrifter gættes automatisk, og et HSG-felt, der sidst ikke var knyttet til nogen kolonne, gættes ikke igen.

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

Match på 90 % eller mere er valgt som udgangspunkt i previewet. Usikre matches opdateres aldrig automatisk. Rækker med en ikke-flettet variant, en matchkonflikt eller en kontrolafvigelse på lageret er aldrig valgt på forhånd.

## Preview og opdatering
Uploaden ændrer ikke data med det samme. Først vises et preview med gammel og ny værdi. Administratoren vælger derefter de rækker, der skal anvendes. Previewet viser kun ændringer, der faktisk bliver udført, med de tal, der bliver skrevet.

Tomme felter fra leverandørfilen overskriver aldrig eksisterende HSG-data. Undtagelsen er lager pr. lokation (se nedenfor).

Previews fra en ældre version af systemet kan ikke anvendes. Upload filen igen.

## Lager
Hvordan lageret skrives, afgøres af kolonnevalget:

| Tilstand | Når | Hvad skrives |
|---|---|---|
| **Lokationer** | Mindst én kolonne er valgt som "Lagerantal for <lokation>" | Kun de valgte lokationer. "Lagerantal i alt" er kun et kontroltal og skrives aldrig. Lokationer uden kolonne røres ikke. |
| **Total** | Kun "Lagerantal i alt" er valgt | Tallet skrives på første aktive lokation (laveste sortering). Previewet viser det som en ændring af den lokation og advarer, hvis produktet også har lager andre steder. |
| **Intet** | Ingen lagerkolonner | Lageret ændres ikke. |

**Tomme lokationsceller:** Har en række et tal i mindst én lokationskolonne, tæller tomme lokationsceller i samme række som 0 (som i Kims egen formel). Er alle lokationsceller tomme, ændres lageret ikke for rækken. Tekst, der ikke er et tal (fx "?"), ændrer aldrig lokationen og giver en bemærkning.

**Kontroltal:** Er både lokationer og "Lagerantal i alt" valgt, sammenlignes summen af lokationstallene med totalen (før reservationer). Afviger de, får varen en bemærkning og er ikke valgt på forhånd. Previewet viser desuden "Lager i filen" og "Sum af lokationskolonner" for hele filen.

**Nye produkter** får kun lager på de lokationer, der har et tal over 0. Badget "Nyt produkt oprettes (Lager: N)" viser summen af lokationstallene.

### Reservationer
Fysisk lager er `lager_stock.quantity`. Disponibelt er fysisk minus aktive reservationer. Reservationsvalget i previewet afgør, hvordan Kims tal tolkes:

- **"Kims tal er efter reservationer – læg dem oven i"** (standard): Fysisk = Kims tal + aktive reservationer på samme lokation. Disponibelt bliver dermed lig Kims tal.
- **"Kims tal er det fysiske lager – brug dem uændret"**: Fysisk = Kims tal. Er Kims tal lavere end det reserverede, advarer previewet, fordi disponibelt bliver negativt, varen kan forsvinde fra Lagerstatus, og reservationen ikke kan markeres som solgt.

Valget gemmes i `hsg_settings` (nøgle `supplier_import_reservation_mode`), når det ændres i previewet, og når ændringerne anvendes. Det gælder kun eksisterende produkter og kun lokationer, der får et tal fra filen. Previewet viser reservationerne pr. vare ("Reserveret i HSG: …").

Når der trykkes "Anvend", beregnes lageret igen med de **aktuelle** reservationer. Lagerbevægelsen får referencen "Kims masterfil opdatering (+N reserveret)", når der er lagt reservationer oven i. Audit-loggen (`supplier_import.apply`) indeholder reservationsvalget, antallet af reserverede flasker, der er lagt oven i, og lagerændringerne pr. lokation. En ændring af reservationsvalget logges som `supplier_import.settings`.

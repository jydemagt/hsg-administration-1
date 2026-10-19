# Leverandør-upload

Modulet **Leverandør-upload** kan analysere forskellige XLSX- og CSV-filer uden at kræve et fast HSG-format.

## Genkendte data
HSG forsøger automatisk at finde kolonnenavne og varedata for bl.a.:

- SKU / varenummer
- Produktnavn / varetekst
- Fadnummer
- Fadtype
- Engrospris
- Udsalgspris
- ABV
- Destilleri
- Alder
- Årgang
- Brand/leverandør
- Kategori og land
- Flaskestørrelse
- Outturn / antal flasker

Hvis oplysninger som ABV, alder, årgang eller fadnummer står direkte i vareteksten, kan de udledes uden AI.

## Kolonnevalg huskes
Kolonnemappingen gemmes server-side i `hsg_settings` (nøgle `supplier_import_header_map`), når preview genberegnes, eller ændringerne anvendes. Den gælder derfor på tværs af enheder og browsere.

- Kolonner matches på overskriftsnavn (normaliseret), ikke på placering, så rækkefølgen må gerne ændre sig.
- En kolonne, der sidst stod til "Ignorer kolonne", forbliver ignoreret.
- Kun nye overskrifter gættes automatisk, og et HSG-felt, der sidst ikke var knyttet til nogen kolonne, gættes ikke igen.

## Varenumre med bogstav til sidst
Et varenummer med ét bogstav direkte efter et tal (fx `17-1028a`) er en variant af varenummeret uden bogstav (`17-1028`).

- Står begge i filen, eller findes `17-1028` allerede i HSG, flettes rækkerne: lagerantallet (også pr. lokation) lægges sammen og skrives på `17-1028`. Øvrige oplysninger tages fra rækken uden bogstav.
- Varianten gemmes som alias (`hsg_supplier_sku_aliases`) for produktet, når ændringerne anvendes.
- Findes kun `17-1028a`, og findes `17-1028` heller ikke i HSG, behandles `17-1028a` som sit eget varenummer, ligesom før. Der oprettes ikke noget `17-1028`.
- Findes `17-1028a` allerede som et selvstændigt produkt i HSG, ændres det ikke. Previewet viser en advarsel, så det kan sættes som udgået, og lageret ikke tælles dobbelt.

## Produktmatch
Match prioriteres i denne rækkefølge:

1. Eksakt SKU
2. Eksakt fadnummer
3. Stærkt navnematch
4. Supplerende kontrol af destilleri, årgang, alder og ABV

Match på 90 % eller mere er valgt som udgangspunkt i previewet. Usikre matches opdateres aldrig automatisk.

## Preview og opdatering
Uploaden ændrer ikke data med det samme. Først vises et preview med gammel og ny værdi. Administratoren vælger derefter de rækker, der skal anvendes.

Tomme felter fra leverandørfilen overskriver aldrig eksisterende HSG-data.

## Lager
Leverandør-upload er adskilt fra den almindelige lagerimport. En pris- eller outturn-fil ændrer derfor ikke fysisk lager. Brug **Excel**-modulet til lagerantal.

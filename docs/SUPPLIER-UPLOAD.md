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

# Produktdata-assistent

Fra HSG Administration 1.3.0 kan produktdata udfyldes fra produktnavn/varetekst.

## Sådan virker det
1. HSG aflæser først sikre mønstre lokalt uden API-kald: ABV, alder, årgang og flaskestørrelse samt eksplicit kategori/fadtype.
2. Destilleri foreslås kun, når navnestrukturen gør det rimeligt sikkert, fx `Craigellachie (2013) – 11 år – 60,6%` eller `Motherlover (Macallan) (2015) – 9 år – 57,7%`.
3. Hvis Groq-nøgle er gemt, kan Groq bruges til manglende/usikre felter. AI må kun bruge den leverede varetekst/kontekst og må ikke opfinde produktdata.
4. Eksisterende udfyldte felter overskrives ikke automatisk af knappen på produktsiden.

## Produktsiden
Brug **Udfyld fra varetekst** for ét produkt eller **Udfyld manglende data på alle** for en sekventiel gennemgang. Massefunktionen respekterer Groq rate limits.

## Excel
Ved import udfyldes sikre manglende felter automatisk fra kolonnen Navn. Excel-arbejdsfilen indeholder Land, Årgang og Flaskestørrelse ud over de eksisterende produktfelter.

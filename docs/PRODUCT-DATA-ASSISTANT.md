# Produktdata-assistent

Fra HSG Administration 1.3.0 kan produktdata udfyldes fra produktnavn/varetekst.

## Sådan virker det
1. HSG aflæser først sikre mønstre lokalt uden API-kald: ABV, alder, årgang og flaskestørrelse samt eksplicit kategori/fadtype. Fadnummer efter `#`, Cask No. og Fad nr. aflæses kun, når det indeholder mindst ét ciffer. "Cask Strength" giver altså ikke fadnummeret STRENGTH, og i "Knockdhu, 10yo, 2012 - #43 - Virgin Oak Barrel Finish" er fadnummeret 43.
2. Destilleri foreslås kun, når navnestrukturen gør det rimeligt sikkert, fx `Craigellachie (2013) – 11 år – 60,6%` eller `Motherlover (Macallan) (2015) – 9 år – 57,7%`.
3. Hvis Groq-nøgle er gemt, kan Groq bruges til manglende/usikre felter. AI må kun bruge den leverede varetekst/kontekst og må ikke opfinde produktdata.
4. Eksisterende udfyldte felter overskrives ikke automatisk af knappen på produktsiden.

## Produktsiden
Brug **Udfyld fra varetekst** for ét produkt eller **Udfyld manglende data på alle** for en sekventiel gennemgang. Massefunktionen respekterer Groq rate limits.

## Excel
Ved import udfyldes sikre manglende felter automatisk fra kolonnen Navn. Excel-arbejdsfilen indeholder Land, Årgang og Flaskestørrelse ud over de eksisterende produktfelter.

## Fadnumre uden ciffer
Tidligere versioner kunne læse ord som fadnummer, fx STRENGTH ("Cask Strength"), FINISH ("Cask Finish"), CRAFT ("Cask Craft") og MATURED ("barrel matured").

- **Engangsoprydning:** Ved første forespørgsel efter opdateringen (modulet Produkter, version 1.5.1) ryddes de værdier, hvor det kan ses, at de er læst automatisk: Den gamle aflæsning af produktets nuværende navn eller af startlagerets navn for samme varenummer giver præcis den gemte værdi. Har navnet et rigtigt fadnummer, skrives det i stedet (fx FINISH → 43). Ellers tømmes feltet. Manuelle værdier som "N/A" røres ikke.
- **Audit-log:** Oprydningen står som én post `product.cask_number_cleanup` med aktøren "System (opdatering)" under System → Auditlog (filter "Produkter"). Posten viser, hvad der er ryddet (gammel og ny værdi), og hvad der er ladt stå.
- **Liste i Produkter:** Knappen **Fadnr. uden ciffer (N)** viser alle produkter, der stadig har et fadnummer uden ciffer, uanset hvor værdien kommer fra. Listen ændrer ikke noget selv: Åbn produktet, ret eller tøm feltet Fadnummer, og gem. Knappen vises kun, når der er sådanne produkter.
- Ryddede produkter står under "Mangler data" i Datakvalitet, indtil de har et nummer eller er markeret "Ikke nødvendig".

# HSG Administration – produktbilleder

Fra version 1.4.1 er automatisk billedsøgning fjernet.

## Kilder til billeder

1. Produktbilleder kan leveres sammen med en HSG-katalogimport.
2. Et billede kan tilføjes manuelt via Billedtjek.
3. Der foretages ingen automatisk web-, sitemap-, søgemaskine- eller AI-søgning efter billeder.

## HSG-katalog maj 2026

Version 1.4.1 indeholder produktbilleder udtrukket direkte fra `Whisky Katalog maj-26- VEJL priser.docx`. Billederne gemmes lokalt i `uploads/products` ved installation/opgradering, og databasen gemmer produktets billedsti og godkendelsesstatus. Allerede manuelt godkendte billeder overskrives ikke.

## Validering og godkendelse

Groq bruges kun til at validere det billede, der allerede er gemt på produktet.

- Valideringsscore >= 80 %: billedet kan sendes til manuel HSG-godkendelse.
- Valideringsscore < 80 %: billedet flagges.
- Alle billeder kræver manuel godkendelse før katalogbrug.
- Varer med disponibelt lager <= 0 springes over i Billedtjek/AI-validering.

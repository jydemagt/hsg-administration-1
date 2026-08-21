HSG ADMINISTRATION v1.3.5

Version 1.4.1 – katalogbilleder uden automatisk søgning
--------------------------------------------------------
- Automatisk internet-/AI-billedsøgning er fjernet fra Billedtjek og endpointet.
- 70 produktbilleder fra HSG Whisky Katalog maj 2026 følger med pakken og matches til kendte HSG-SKU'er.
- Ved installation/opgradering kopieres katalogbillederne lokalt til uploads/products og registreres på produkterne.
- Eksisterende manuelt godkendte produktbilleder overskrives ikke.
- Nye katalogbilleder sættes til Afventer godkendelse og skal AI-valideres (mindst 80 %) før manuel godkendelse.
- Groq bruges kun til billedvalidering, ikke til at finde billeder.
- Manuelle internetbilleder kan fortsat tilføjes med dokumentation fra leverandørens officielle produktside.

========================

Ren PHP/MySQL-platform til almindeligt webhotel. Ingen Docker eller Composer er nødvendig.

HOVEDFUNKTIONER
---------------
- Sikkert admin-login med brugernavn/password.
- Personlige direkte brugerlinks uden login.
- Modulrettigheder pr. linkbruger. Direkte links er fortsat ikke administrative.
- Mobilvenlig Lagerstatus med direkte reservation.
- Hovedlager, Gert Lager og ubegrænset antal nye lokationer.
- Reservationer, lagerflytninger, historik og disponibelt lager.
- Produkter, brands, engros-/udsalgspriser og Nyhed-flueben.
- Web/PDF-katalog med brandintroduktion og produktbilleder.
- Excel import/eksport med dynamiske lokationskolonner.
- Billedtjek med robust flertrins-søgning, leverandør/sitemap/structured-data, Groq/OpenAI fallback, kandidatvisning og manuel URL.
- Modulær platform med versionsstyrede database-migrationer.
- Audit-log.
- Sikkert opgraderingsmodul med ZIP-validering, pre-update FULL-backup og opgraderingshistorik.
- Natlig DATA-backup + ugentlig FULL disaster-recovery backup.
- Valgfri upload til Microsoft OneDrive via Microsoft Graph.
- FULL backup indeholder restore.php + README-RESTORE.txt + database.sql + hele sitet.

INSTALLATION
------------
1. Opret en MySQL/MariaDB-database på webhotellet.
2. Upload alle filer til fx /hsg/.
3. Åbn https://ditdomæne.dk/hsg/install.php.
4. Indtast databaseoplysninger og opret admin-login.
5. Gem det personlige lagerlink, der vises efter installation.
6. Slet/omdøb evt. install.php efter installation.

KRAV / ANBEFALET PHP
--------------------
- PHP 8.1 eller nyere.
- PDO MySQL.
- ZipArchive (XLSX + ZIP-backup).
- SimpleXML (XLSX-import).
- GD anbefales til billedbehandling.
- cURL kræves til automatisk internetbilledhentning og OneDrive.
- HTTPS anbefales stærkt.

MODULRETTIGHEDER
----------------
Admin > Adgangslinks > Rettigheder vælger hvilke brugerrettede moduler et direkte link må se.
Standard: Overblik, Lagerstatus, Reservationer og Katalog. Kun Reservationer har arbejdsrettighed via link (opret + annuller egne aktive reservationer). Kritiske ændringer kræver altid admin-login.

AUTOMATISK BACKUP
-----------------
Under Backup & disaster recovery kan du konfigurere:
- Natlig DATA-backup: database + mutable uploads.
- Ugentlig FULL-backup: applikation + database + uploads + restore-værktøj.
- Retention af lokale backups.
- OneDrive-kopi.

Webhotellets cron skal starte scriptet. Anbefalet én gang hver nat, fx kl. 02:00:
  php /absolut/sti/til/hsg/cron/backup.php

Hvis webhotellet kun kan kalde en URL, viser Backup-siden en beskyttet cron-URL med nøgle.

ONEDRIVE
--------
OneDrive-delen bruger Microsoft Graph server-to-server (client credentials) til en Microsoft 365/OneDrive for Business-bruger.
Du indtaster Tenant ID, Client ID, Client Secret og OneDrive-brugerens UPN under Backup.
Microsoft Entra-applikationen skal have relevante application file permissions og admin consent.
Client Secret udelades bevidst fra disaster-recovery-backuppen og skal genindtastes efter restore.

RESTORE VED TOTALT NEDBRUD
--------------------------
Lav/download en FULL backup. Inde i den findes README-RESTORE.txt med den komplette vejledning.
Kort fortalt: opret tom database -> upload/udpak backup på nyt webhotel -> åbn restore.php -> indtast nye DB-oplysninger -> systemet kopierer sitefiler og importerer databasen.

OPGRADERING FRA v1.1.0
----------------------
1. Tag en backup af eksisterende installation.
2. Behold config.php og uploads/.
3. Upload v1.2.0-filerne oven på installationen.
4. Åbn systemet som admin. Database-migrationerne køres automatisk.
5. Gå til Adgangslinks og kontroller modulrettigheder.
6. Gå til Backup og konfigurer cron/OneDrive.

FREMTIDIGE MODULER
------------------
Platformen er lavet til senere moduler som Indkøb & Leverandører, Kunder & Salg, Fade & Anparter, Events, Økonomi, Rapporter og WooCommerce-integration uden at omskrive lagerkernen.

VERSION 1.2.1
- Varer med disponibelt lager på 0 eller derunder skjules automatisk fra Lagerstatus, reservationsvalg og produktkatalog/PDF.
- Produktet slettes ikke fra databasen og kommer automatisk frem igen, når disponibelt lager bliver positivt.


VERSION 1.2.2
- Leverandør-URL kan gemmes pr. brand til automatisk billedsøgning.
- Billedmotoren søger først leverandørens eget sitemap og interne søgesider.
- Fallback-søgning accepterer kun resultater fra det angivne leverandørdomæne.
- Billedtjek kan åbnes direkte for et bestemt brand og hente alle manglende billeder.
- Produktets direkte leverandør-/produktside kan fortsat overstyre brandets standard-URL.


OPGRADERING FRA ADMIN-SIDEN
---------------------------
Fra version 1.2.3 findes modulet "Opgradering" i admin-menuen.
1. Log ind som administrator.
2. Gå til Opgradering.
3. Upload en nyere HSG Administration ZIP-pakke.
4. Systemet validerer pakken, versionsnummer, filstier, PHP-krav og filhashes.
5. Gennemgå oplysningerne og vælg "Installér opgradering".
6. HSG opretter automatisk en FULL-backup før filer eller database ændres.
7. config.php, uploads, backupfiler og mutable data overskrives ikke.
8. Databaseændringer køres automatisk, og opgraderingen registreres i historikken.

Upload kun HSG-pakker fra en kilde, du har tillid til. Integritetskontrollen opdager beskadigede filer, men erstatter ikke tillid til pakkens afsender.


VERSION 1.2.3
- Nyt kernemodul "Opgradering" i admin-menuen.
- Upload og kontrollér nyere HSG ZIP-pakker direkte i browseren.
- Automatisk FULL-backup før installation.
- Beskyttelse af config.php, uploads, backups og mutable data.
- Vedligeholdelsestilstand under filskift.
- SHA-256-integritetskontrol og beskyttelse mod usikre ZIP-filstier.
- Filniveau-rollback ved installationsfejl samt historik i databasen.


VERSION 1.2.4
- Under Billedtjek kan produktbilleder klikkes og vises i stort format i en responsiv lightbox.
- Storvisningen kan lukkes med X, klik på baggrunden eller Escape og fungerer også på mobil.
- Billeder, der netop er hentet automatisk eller via URL, kan åbnes i storvisning med det samme.


VERSION 1.2.6
- AI-fallback kan aktiveres under Billedtjek og bruges kun som domænebegrænset hjælp på leverandørens officielle hjemmeside.
- AI kan hjælpe med at finde sandsynlige produktsider, men HSG accepterer kun sider på det registrerede leverandørdomæne; AI genererer ikke flaskebilleder.
- HSG downloader, beskærer og cacher fortsat det originale kildebillede lokalt.
- AI-sikkerhedsgrænse kan indstilles (standard 80%). Under grænsen gemmes billedet ikke automatisk.
- Billedtjek viser om et billede er fundet via leverandørside, domænebegrænset AI-fallback eller manuel URL.
- OpenAI API-nøglen behandles som en hemmelighed og udelades fra disaster-recovery-backupper.
- En særskilt “Søg med AI”-knap kan bruges til manuel test på et produkt.

AI BILLEDSØGNING
-----------------
Fra v1.2.6 kan Groq Free Plan bruges som gratis AI-fallback. Opsætning findes under Billedtjek og i docs/AI-IMAGE-SEARCH.md. OpenAI er fortsat valgfrit.


VERSION 1.2.7
- Rettet Groq Compound Mini-fejl: citation_options sendes ikke længere i API-kaldet.
- Groq web search-kilder håndteres fortsat automatisk fra svaret.
- Når et brand har leverandørdomæne, bruges Groqs search_settings/include_domains til at begrænse AI-fallback til leverandørens eget domæne.


VERSION 1.2.8
- Nyt AI-valideringstjek af alle lokalt gemte produktbilleder i Billedtjek.
- Hvert billede får en separat valideringsscore på 0-100%, uafhængigt af søgningens confidence-score.
- 95-100% godkendes; alle billeder under 95% flagges automatisk til manuel kontrol.
- Valideringen sammenholder synlig etiket med produktnavn, brand, destilleri, alder og ABV.
- Knappen “Valider alle billeder” gennemgår hele billedbiblioteket sekventielt.
- Nye billeder valideres automatisk efter hentning, når Groq Vision er konfigureret.
- Valideringsfejl flagges og overskriver ikke selve det gemte billede.
- Groq Vision bruger qwen/qwen3.6-27b og kontrollerer først, at modellen er tilgængelig for den aktuelle Groq-konto.



v1.2.10
- Groq Vision-validering bruger nu robust nøgle=værdi-output i stedet for tvungen JSON-mode, som kunne fejle med 'Failed to validate JSON'.
- Groq 429/rate limits returnerer retry-tid, og Billedtjek pauser og fortsætter automatisk ved massevalidering.
- Midlertidig rate limiting markeres ikke længere som en permanent valideringsfejl på produktet.


Version 1.3.0 – produktdata-assistent og lagerbevidst Billedtjek
----------------------------------------------------------------
- Produktsiden har knappen “Udfyld fra varetekst”.
- Sikker mønstergenkendelse udfylder bl.a. ABV, alder, årgang og flaskestørrelse direkte fra produktnavnet.
- Groq kan valgfrit hjælpe med usikre/manglende felter som destilleri, kategori og fadtype uden at overskrive allerede udfyldte værdier.
- “Udfyld manglende produktdata” kan gennemgå flere produkter sekventielt og respekterer Groq rate limits.
- Excel-import udfylder sikre manglende produktfelter automatisk fra Navn/varetekst; Excel-eksport indeholder også Land, Årgang og Flaskestørrelse.
- Billedtjek omfatter kun produkter med disponibelt lager > 0. Varer på 0 eller negativt lager søges eller valideres ikke og bruger ingen AI-kald.


Version 1.3.1 – kandidatvisning og produktinfo i stor billedvisning
- Billedtjek gemmer og viser alle AI-kandidater med mindst 50 % søgescore.
- Kandidater på 50–94 % vises med rød FLAGGET-status og kræver manuel kontrol.
- Kun kandidater på 95 % eller mere må anvendes automatisk.
- Klik på et billede åbner en stor overlay over siden med produktnavn, SKU, brand, søgescore, valideringsscore og kilde.
- AI-kandidater kan vælges direkte fra overlayet med "Brug dette billede".


Version 1.3.2 – Groq rate-limit robusthed
- Korte Groq TPM-rate-limits genprøves automatisk én gang.
- Billedsøgning normaliseres til groq/compound-mini.
- Produktdata-assistenten foretrækker llama-3.3-70b-versatile og bruger kortere svar.
- Længere rate-limit-vinduer sendes fortsat til browserens eksisterende pause/retry-flow.


Version 1.3.3 – robust billedsøgning
-----------------------------------
- Automatisk billedsøgning samler nu flere kandidater i stedet for at bruge første fund.
- Flere søgevarianter kombinerer produktnavn, brand, destilleri, årgang, alder og ABV.
- Leverandørens sitemap, image-sitemap, WordPress/WooCommerce/Shopify-data og interne søgning bruges parallelt. Eksterne websites bruges ikke som kilder.
- Fundne produktsider scores mod de faktiske produktdata, før et billede kan få høj sikkerhed.
- Produktbilleder udtrækkes fra schema.org/JSON-LD Product, Open Graph, Twitter cards, WooCommerce/Shopify-gallerier, lazy-load og srcset/zoom-billeder.
- Logoer, bannere, ikoner og andre typiske ikke-produktbilleder nedprioriteres.
- Normale søgekandidater og AI-kandidater vises samlet i Billedtjek fra 50 %. Kun 95 %+ må anvendes automatisk.
- Groq-fund bliver genkontrolleret af HSG mod den faktiske produktside i stedet for at stole på AI-confidence alene.
- Manuel produkt-URL bruger også den nye billedudtrækker og vælger det mest sandsynlige produktbillede fra siden.

Version 1.3.4 – manuel billedgodkendelse og afvisning
----------------------------------------------------
- Alle eksisterende og nye produktbilleder får status "Afventer manuel godkendelse".
- AI-score og manuel godkendelse er adskilt: en AI-score på 100 % godkender ikke billedet.
- Billedtjek kan filtreres på Alle, Ikke godkendt, Godkendt og Mangler billede.
- Administrator kan godkende eller afvise det aktuelle billede.
- Forkerte kandidater kan afvises. HSG gemmer den afviste billed-/kilde-URL pr. produkt og foreslår den ikke igen.
- Ikke-godkendte billeder bruges ikke i web- eller PDF-kataloget og vises ikke til almindelige lagerbrugere.
- Klik på billeder/kandidater åbner fortsat stor overlay med produktnavn, SKU, brand, scorer, manuel status og handlinger.
- Numeriske søgetokens type-castes til tekst for at undgå PHP 8-fejlen "str_contains(): Argument #2 ($needle) must be of type string, int given".



Version 1.3.5 – gratis og mere robust billedsøgning
- Ingen nye betalte billed-API'er er nødvendige.
- Udnytter image-sitemaps direkte, inklusive flere billeder pr. produkt.
- Leverandørindekset crawler flere katalogsider og cache genopbygges automatisk ved nul resultater.
- WooCommerce/Shopify/WordPress offentlige endpoints prioriteres fortsat.
- Groq Free kan bruges som valgfri AI-fallback/validering.
- Manuel fallback foregår via en konkret leverandørside. Eksterne billed-URLer kræver dokumentation fra leverandørens officielle produktside.
- Alle fundne billeder kræver fortsat manuel godkendelse.


Version 1.3.6: AI-valideringsgrænsen i Billedtjek er ændret fra 95 % til 80 %. Kun billeder med valideringsscore på mindst 80 % vises som klar til manuel godkendelse. Billeder under 80 % flagges og kan ikke godkendes manuelt, før et bedre billede er fundet/valideret. Søgekandidaternes egen søgescore er uændret.


Leverandør-kildekrav (v1.3.7)
- Automatisk billedsøgning sker kun på den leverandør-URL, der er knyttet til produktets brand/leverandør.
- Eksterne webshops og generelle billedsøgemaskiner bruges ikke som billedkilder.
- Et billede må godt ligge på et eksternt CDN/domæne, men kun hvis en produktside på leverandørens officielle domæne refererer til netop billedet.
- Ved manuel ekstern billed-URL skal leverandørens produktside angives som dokumentation; HSG verificerer relationen før billedet gemmes.
- Den verificerede leverandørside gemmes som billedets kildebevis og vises i Billedtjek.


NYT I V1.4.0 – FADNUMMER OG LEVERANDØR-UPLOAD
------------------------------------------------
- Produkter har nu et selvstændigt felt til Fadnummer samt det eksisterende felt Fadtype.
- Fadnummer aflæses sikkert fra varetekst efter #, Cask No. eller Fad nr.; batchnumre ignoreres.
- Produktsiden viser hvor mange aktive produkter der mangler fadnummer og kan filtrere dem frem.
- Fadnummer bruges som stærkt signal i billedsøgning og produktmatch.
- Nyt modul: Leverandør-upload. Upload forskellige XLSX/CSV pris-, outturn- og produktfiler.
- Modulet finder selv tabellen/overskriftsrækken og genkender produktnavn, SKU, fadnummer, fadtype, priser, ABV, destilleri, alder, årgang m.m.
- Match prioriteres efter SKU, fadnummer og stærkt navnematch.
- Alle ændringer vises som preview (gammel → ny) og kræver administratorens godkendelse før de skrives til databasen.
- Leverandør-upload ændrer ikke fysisk lager; lagerimport er fortsat et separat modul.


Version 1.4.2
- Lagerstatus: Reservér åbner nu en mobilvenlig popup med lokation, antal og frit felt 'Hvem / hvad'.
- Antal begrænses efter disponibelt lager på valgt lokation.
- Produktnavn/billede på lagerstatus er klikbart og åbner en produktdetalje-popup med produktdata, priser og lager pr. lokation.


Version 1.4.3: Mobil lagerstatus hotfix. Produktkort er hvide/klikbare, Reservér er en separat knap, popup-events er gjort mere robuste, og style.css cache-bustes med versionsnummer så Safari/iPhone altid henter korrekt CSS efter opgradering.

VERSION 1.5.0 – DATAKVALITET
-----------------------------
- Overblik viser klikbare tællere for produkter der mangler data eller godkendt billede.
- Nyt Datakvalitet-modul med filtre: Kræver handling, Mangler data, Mangler billede, Klar og Godkendt.
- Hvert manglende felt kan markeres "Ikke nødvendig" for netop den vare.
- Et fravalgt felt kan senere gøres relevant igen.
- Når alle obligatoriske punkter er udfyldt eller fravalgt, kan varen godkendes manuelt.
- Godkendelsen gemmer tidspunkt og administrator og registreres i audit-loggen.
- Redigering af produkt eller billede nulstiller produktets kvalitetsgodkendelse.
- Billedkravet gælder kun varer med disponibelt lager over 0.
- Admin kan selv vælge hvilke produktfelter der globalt skal være obligatoriske.


VERSION 1.5.1 - KATALOGLAYOUT
------------------------------
- PDF-kataloget er redesignet efter HSG Whisky Katalog maj 2026.
- Forside med HSG-logo, titel og opdateringsdato.
- Automatisk indholdsfortegnelse med produkt- og sidenumre.
- Hovedbrands får introduktionsside med brandlogo og brandbeskrivelse.
- Produktsider viser to flasker pr. A4-side med skiftevis billede og produkttabel.
- Produkttabellen viser destilleri, ABV, alder, evt. fadnummer, fadtype, antal flasker og valgt pris.
- Retail bruger teksten 'Vejl. pris (inkl. moms)' som i maj-2026-kataloget.
- Engroskataloget bruger samme layout med engrospris ekskl. moms.
- NYHED-produkter bruger katalogets visuelle NYHED-maerke.
- Webkataloget har en responsiv forhåndsvisning i samme stil.


Version 1.5.4 – negativt lager
- Produkter med negativt fysisk lager kan filtreres på Produkter > Negativt lager og slettes individuelt.
- Sletning kræver, at der ikke findes aktive reservationer på produktet.
- Ved permanent sletning fjernes produktets historiske lagerbevægelser og afsluttede/annullerede reservationer; audit-loggen gemmer et snapshot af sletningen.
- Lagerimport opretter ikke nye produkter med negative lagerceller og springer rækker over, som ville give negativt fysisk lager.
- Ny installation springer også eventuelle negative startlagerrækker over.


V1.6.0 – FORENKLET ADMINISTRATION
- Admin-hovedmenuen er reduceret til Overblik, Lager, Produkter, Katalog, Import / Upload og Administration.
- Administration samler reservationer, lagerændringer, lokationer, brands, billedtjek, datakvalitet, adgang, backup, opgradering og system.
- Mobil adminmenu: Lager, Reservér, Katalog og Mere.
- Brugere & adgang viser aktive/deaktiverede links og kan vise/kopiere den fulde linkadresse for administrator. Nye/regenererede linktokens opbevares krypteret med en nøgle under storage/data, som følger DATA/FULL-backup men ikke opgraderingspakker.
- Ældre links kan ikke rekonstrueres fra hash og skal regenereres én gang for at blive synlige som fuld URL.


Version 1.6.1
- Katalogtabeller tegnes med en sammenhængende ydre ramme på alle fire sider, inkl. nederste kant.
- Katalogbilleder beskæres automatisk i en separat cache, så flasken fylder højden bedre og store hvide baggrundsmargener fjernes uden at ændre originalbilledet.
- Datakvalitet viser fysisk lager og giver en direkte Slet produkt-knap ved fysisk lager 0 eller lavere. Aktive reservationer blokerer sletning.

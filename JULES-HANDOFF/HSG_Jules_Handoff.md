# HSG Administration – komplet projekt-handoff til Jules

Du skal overtage videreudviklingen af **HSG Administration**, et PHP/MySQL-baseret administrations- og lagerstyringssystem for **HSG Whisky ApS**.

GitHub repository:

`https://github.com/jydemagt/hsg-administration-1`

## VIGTIGT – arbejdsmåde

Start med at gennemgå hele repositoryet. Rebuild ikke systemet fra bunden. Bevar eksisterende database, funktionalitet, installationsflow og updater.

Systemet skal kunne køre på et almindeligt dansk webhotel med PHP/MySQL. Ingen Docker, Node-runtime eller serverprocesser må være nødvendige i produktion.

Alle ændringer skal være bagudkompatible, medmindre andet specifikt er aftalt.

Ved hver ny version skal:

- PHP-filer syntakskontrolleres.
- `hsg-package.json` regenereres efter alle filændringer.
- Alle filer i update-ZIP’en bortset fra selve manifestet skal stå i manifestet.
- SHA-256 hashes skal stemme.
- Ingen testfiler må ved en fejl komme med.
- `config.php`, `.env`, `config.local.php`, uploads, backups eller andre brugerdata må aldrig overskrives.
- Databaseændringer skal ske gennem migrations/opdateringsmekanismen.
- Versionen skal opdateres konsekvent.

En tidligere update fejlede fordi filen:

`test-catalog-v151.pdf`

lå i ZIP-filen uden at være med i integritetsmanifestet. Undgå dette permanent.

---

## 1. Teknisk grundlag

Systemet er:

- PHP
- MySQL
- installerbart direkte på webhotel
- browserbaseret
- mobile-first på lagerdelen

Installeren skal selv oprette nødvendige MySQL-tabeller.

Systemet har et modulært fundament med:

- moduler
- migrations
- hooks
- updater
- backup/restore
- brugerrettigheder

Det skal kunne vokse til et bredere HSG administrationssystem.

---

## 2. Login og sikkerhed

Der findes almindeligt admin-login med brugernavn/password.

Der findes også personlige direkte links til brugere.

Direkte brugerlinks skal være read-only, bortset fra de funktioner administratoren eksplicit giver dem adgang til, fx reservation.

Admin skal kunne se:

- alle aktive adgangslinks
- bruger/navn
- rolle/rettigheder
- status
- oprettelsesdato
- senest brugt
- fuld linkadresse
- kopiér link
- deaktivér
- generér nyt link

Kun admin må kunne se den komplette linkadresse.

Nyere links skal kunne gemmes krypteret, så URL’en kan vises igen. Ældre links, der kun blev gemt som hash, kan ikke rekonstrueres og må genereres igen.

---

## 3. Lager

Der findes mindst:

- Hovedlager
- Gert Lager

Administrator skal kunne:

- oprette lokationer
- omdøbe dem
- deaktivere dem

Lagerberegning:

**Disponibelt lager = fysisk lager − reserveret**

Ved solgt reservation reduceres fysisk lager.

Ved annullering frigives reservationen.

Produkter med disponibelt lager `<= 0` skal normalt være skjult fra almindelig lager-/reservations-/katalogvisning, men ikke automatisk slettes.

### Negativt lager

Ny regel:

Produkter med negativt **fysisk lager** må ikke oprettes ved import.

Hvis en importlinje ville oprette et produkt med negativ fysisk beholdning, springes den over.

Eksisterende produkter med negativt fysisk lager skal kunne slettes af admin.

Sletning må ikke kunne foretages, hvis varen har aktive reservationer.

Sletning skal audit-logges.

---

## 4. Produkter

Produkter kan bl.a. indeholde:

- navn
- brand
- destilleri
- alkoholprocent
- alder
- vintage
- fadtype
- fadnummer
- antal flasker
- detailpris
- engrospris
- billede
- øvrige eksisterende felter

Fadnummer hedder teknisk typisk:

`cask_number`

Fadtype:

`cask_type`

Ved udtræk fra produkttekster kan et fadnummer efter `#` bruges, men vær forsigtig:

`Solera Batch #1`

må fx ikke fejlagtigt fortolkes som fadnummer.

Manglende fadnummer må aldrig AI-gættes.

---

## 5. Import

Systemet skal kunne importere Excel/CSV.

Det oprindelige lagergrundlag kommer fra:

`Lager HSG.xlsx`

Tidligere analyse viste cirka 181 produkter og 974 flasker, men brug altid kildefilen som autoritativ frem for gamle tal.

Der findes også et supplier upload/import-modul, som skal kunne håndtere forskellige XLSX/CSV-formater.

Matching skal helst ske i denne prioritet:

1. SKU
2. fadnummer
3. stærkt navnematch
4. støttedata som destilleri, vintage, alder og ABV

Importen skal være konservativ og vise preview før større ændringer.

---

## 6. Datakvalitet

Der findes en side:

`quality.php`

med bl.a. filter:

`quality.php?filter=missing_data`

Datakvalitetscenteret skal vise varer som mangler:

- data
- billede
- obligatoriske felter

Hvert felt skal kunne markeres:

**Ikke nødvendig**

så produktet ikke længere står som fejl pga. det felt.

Et produkt kan kvalitetsgodkendes, når alle krav enten:

- er udfyldt
- eller markeret ikke nødvendige

Godkendelsen skal gemme:

- administrator
- tidspunkt

Hvis relevante data senere slettes/ændres, skal produktet kunne komme tilbage på fejl-listen.

Der findes globale obligatoriske felter.

### Direkte sletning fra Datakvalitet

På Datakvalitet → Mangler data skal admin kunne slette et produkt direkte, hvis fysisk lager er `0` eller lavere.

Slet-knappen skal stå direkte ved produktet.

Har produktet aktiv reservation, skal sletning være blokeret og forklares.

---

## 7. Produktbilleder

MEGET VIGTIG BESLUTNING:

Der må **ikke længere laves automatisk billedsøgning på internettet**.

Gamle eksperimenter med Google/Bing/andre søgekilder skal ikke genintroduceres.

Billeder skal komme fra:

- HSG’s eget uploadede katalog
- manuel upload
- andre eksplicit godkendte lokale kilder

Groq må eventuelt bruges til at **validere et allerede eksisterende billede**, men ikke til at søge efter billeder.

Billeder skal manuelt kunne godkendes.

Produktbilleder gemmes som lokale filer med database-reference — ikke som store DB-BLOBs.

Dermed kan FULL backup inkludere billederne.

---

## 8. HSG-katalogets kilde

Den vigtigste katalogkilde er:

`Whisky Katalog maj-26- VEJL priser.docx`

Titel:

**Whisky Katalog Fra HSG Whisky Aps**

Opdateret:

**19. maj 2026**

Dokumentet indeholder bl.a.:

- Woodrow’s
- Fragrant Drops
- Edinburgh Whisky
- Lady of the Glen
- Samhain
- Dalgety
- St Bridget’s Kirk
- Uncharted
- flere øvrige brands/produkter

Der blev tidligere udtrukket omkring 70 produktbilleder fra dokumentet.

Disse billeder skal bruges lokalt og ikke erstattes af automatisk web-søgning.

Der findes også et ældre katalog:

`Whisky Katalog Dec-24- Engros priser.docx`

---

## 9. Katalogets design

Brugeren ønsker, at det genererede produktkatalog visuelt skal ligne det uploadede HSG Word-katalog.

Ønsket struktur:

- forside med HSG-logo
- indholdsfortegnelse
- brandsektioner
- brandintro/logo/beskrivelse
- cirka to produkter per A4-side
- produktinformation i tabel
- stort flaskebillede
- skiftende layout hvor relevant

Produktdata i tabeller kan fx være:

- DESTILLERI
- ALC. %
- ALDER
- FADTYPE
- FADNUMMER
- ANTAL FLASKER
- VEJL. PRIS (INKL. MOMS)

Der skal også kunne laves engrosvariant med fx:

**Engros pris (ekskl. moms)**

---

## 10. Katalogets aktuelle visuelle fejl

Dette er et meget vigtigt punkt.

Brugeren har flere gange vist screenshots, hvor produktets tabel ikke ser rigtig ud.

Tabellen skal være en **rigtig lukket tabel hele vejen rundt**.

Den skal have:

- venstre yderkant
- højre yderkant
- topkant
- bundkant
- alle indre vandrette linjer
- korrekt lodret skillelinje mellem label og værdi

Den nederste række må IKKE være åben.

Problemet har især været, at nederste/højre border mangler eller bliver klippet.

Implementeringen skal ikke kun prøve at give hver celle tilfældige borders. Sørg for en robust samlet yderramme omkring hele tabellen i både HTML/web og PDF-rendering.

Brugeren har specifikt sagt:

> tabellerne skal laves rigtig hele vejen rundt

Dette skal visuelt verificeres.

---

## 11. Flaskebilleder i katalog

Brugeren ønsker, at flasken fylder billedområdet meget mere.

Produktbillederne må beskæres til katalogbrug, så:

- hvid/neutral baggrund omkring flasken reduceres kraftigt
- flasken fylder mest muligt i højden
- flasken stadig vises komplet
- billedets proportioner bevares
- originalfilen ikke destruktivt ændres

Lav gerne en katalog-cache med beskårne billeder.

Der må ikke opstå store hvide felter rundt om små flasker.

---

## 12. Konkret katalogdata-eksempel

Fra May-2026 kataloget:

**Aberlour (2011) – 12 år – 55,3%**

Data omfatter:

- destilleri: Aberlour
- ABV: 55,3 %
- alder: 12 år
- fad: Refill Bourbon Barrel
- antal flasker: 219
- vejledende pris inkl. moms: 1.099 kr.

Sådanne data fra HSG’s eget katalog kan bruges som fallback, hvis databasefeltet mangler, men database og HSG-kildedata skal håndteres kontrolleret.

---

## 13. Mobil Lagerstatus

Mobilvisningen skal være enkel.

Produktkortene skal være **hvide**, ikke store blå knapper.

Hele produktkortet/billedet/navnet skal kunne åbne produktdetaljer.

Der skal være en separat blå:

**Reservér**

-knap.

Reservér må ikke bare navigere væk. Den skal åbne en mobil popup/modal.

Popup skal have:

- lokation
- antal
- plus/minus-knapper
- fritekstfelt

Fritekstlabel:

**Hvem / hvad er reservationen til?**

CSS-cache har tidligere givet problemer i Safari. Brug versions-cache-busting på stylesheets.

---

## 14. Simplificeret navigation

Brugeren ønsker en langt enklere struktur.

Admin-hovedmenuen bør være:

**Overblik · Lager · Produkter · Katalog · Import / Upload · Administration**

Tekniske funktioner flyttes under Administration.

Under Administration kan bl.a. ligge:

- Reservationer / lagerændringer
- Brands
- Lokationer
- Billeder
- Datakvalitet
- Brugere & adgang
- Backup
- Opgradering
- Systemindstillinger

Mobil-navigation bør være:

**Lager · Reservér · Katalog · Mere**

Overblik skal fokusere på det vigtigste:

- produkter
- lager
- aktive reservationer
- varer der kræver handling
- aktive adgangslinks
- backupstatus

Undgå unødvendige badges og tekniske knapper i standardvisningen.

---

## 15. Backup / disaster recovery

Systemet har backup-funktioner.

Der skal kunne laves FULL backup.

Full backup skal inkludere nødvendige uploaded produktbilleder/data.

Der er også arbejdet med OneDrive-support.

Backup/restore må ikke kompromittere hemmelige konfigurationsdata.

---

## 16. Updater

Updateren er central.

Eksisterende kode har `core/updater.php`.

Updatepakker bruger:

`hsg-package.json`

Updateren verificerer alle filer og SHA-hashes.

Den må blokere fremmede/uventede filer.

Beskyttede områder omfatter bl.a.:

- `config.php`
- `.env`
- `config.local.php`
- `uploads/`
- `storage/backups/`
- `storage/data/`

Vær opmærksom på `.htaccess`; filnavnet må ikke fejl-normaliseres.

---

## 17. GitHub-opdateringer – næste store opgave

Repository:

`jydemagt/hsg-administration-1`

Brugeren ønsker, at GitHub bliver den centrale kode-/opdateringskanal.

Målet er:

**Ny kode → GitHub → HSG opdager ny version → admin installerer opdateringen**

HSG Administration skal på sigt kunne:

- kontrollere GitHub for ny version
- vise version og release notes
- hente update ZIP
- verificere integritetsmanifest
- tage FULL backup
- installere update
- køre migrations
- vise resultat

Brugeren vil helst slippe for manuelt at hente ZIP-filer.

Den nuværende ChatGPT GitHub-forbindelse kan læse repoet, men direkte writes har givet:

`403 Resource not accessible by integration`

Derfor er automatisk GitHub-push fra denne ChatGPT-session IKKE etableret endnu.

Forsøg ikke at antage, at det allerede virker.

---

## 18. GitHub sikkerhed

Repoet blev på et tidspunkt oprettet som public.

Undgå at committe:

- passwords
- API keys
- GitHub tokens
- Groq keys
- DB credentials
- `config.php`
- backups
- uploaded brugerdata
- aktive access-link secrets

Lav `.gitignore`/beskyttelse hvis det ikke allerede findes.

---

## 19. Mulig GitHub updater-arkitektur

En robust løsning kunne være GitHub Releases.

Fx:

`v1.7.0`

Release asset:

`hsg-administration-v1.7.0.zip`

Samt metadata/manifest.

HSG updateren kan bruge GitHub API til at finde seneste release.

Hvis repoet forbliver public, kan release-filer downloades uden private tokens.

Hvis repoet senere gøres privat, skal GitHub-token håndteres sikkert.

Systemet bør ikke installere en release, hvis:

- manifestet ikke matcher
- versionen er ugyldig
- nødvendige PHP extensions mangler
- backup fejler
- pakken forsøger at overskrive brugerdata

---

## 20. Versionshistorik

Tidligere udvikling har omtrent været:

v1.0.0 – grundsystem PHP/MySQL  
v1.0.1 – admin security  
v1.1.0 – modulært fundament  
v1.2.0 – backup, module permissions, OneDrive/restore  
v1.2.1 – skjul lager <= 0  
v1.2.2–1.3.x – forskellige billed-/AI-eksperimenter  
v1.4.0 – supplier upload + fadnummer/fadtype  
v1.4.1 – automatisk billedsøgning fjernet, katalogbilleder importeret  
v1.4.2 – mobil reservation popup og produktdetaljer  
v1.4.3 – mobile cards/CSS cache hotfix  
v1.5.0 – datakvalitet og produktgodkendelse  
v1.5.1 – kataloglayout inspireret af May-2026 kataloget  
v1.5.2 – katalog overflow/billeder/fallback rettelser  
v1.5.3 – updater/integritetsfix pga. test-PDF  
v1.5.4 – negativt lager / sletning  
v1.6.0 – simplere navigation + aktive adgangslinks  
v1.6.1 – Datakvalitet-sletning + katalogborder/billedbeskæring

**v1.6.1 er den seneste klart bekræftede leverede version i dialogen.**

Kontrollér repositoryet før du vælger næste versionsnummer.

---

## 21. Den vigtigste umiddelbare QA

Inden nye funktioner bør følgende verificeres visuelt og funktionelt:

1. Generér et rigtigt PDF-katalog.
2. Kontrollér at hver produkt-tabel er helt lukket med border på alle fire sider.
3. Kontrollér især nederste række.
4. Kontrollér at flaskebilleder er beskåret tæt omkring flasken.
5. Kontrollér at flasken fylder størstedelen af billedhøjden.
6. Kontrollér at ingen auto-web-billedsøgning findes.
7. Kontrollér Datakvalitet → Mangler data og direkte Slet-knap.
8. Kontrollér at sletning kun tillades ved fysisk lager <= 0 og uden aktiv reservation.
9. Kontrollér mobil Lagerstatus i Safari/iPhone-layout.
10. Kontrollér updater-manifest før release.

---

## 22. Arbejdsprincip for Jules

Når brugeren beder om en ændring:

- inspectér eksisterende implementation først
- lav minimal sikker ændring
- bevar eksisterende funktionalitet
- test PHP syntax
- test migration/updater
- test relevante UI flows
- bump version
- regenerér manifest
- commit ændringer til en branch
- opret PR med kort changelog

Før merge bør du opsummere præcist:

- hvad blev ændret
- hvilke filer
- evt. databaseændringer
- hvordan det blev testet
- hvilket versionsnummer det bliver

---

## 23. Brugersprog og stil

Brugeren kommunikerer primært på dansk.

Svar kort og konkret.

Undgå at ændre design eller arbejdsgange unødigt uden at brugeren har bedt om det.

Når brugeren viser et screenshot af en fejl, behandl screenshotet som den konkrete visuelle reference og ret den faktiske UI-fejl frem for kun at ændre abstrakt CSS.

---

# Første opgave til Jules

1. Åbn og gennemgå `jydemagt/hsg-administration-1`.
2. Fortæl hvilken version repositoryet aktuelt indeholder.
3. Find katalogrendererens HTML/PDF/CSS-kode.
4. Find Datakvalitet-sletningen.
5. Find updater/manifest-koden.
6. Kontrollér om v1.6.1-funktionerne faktisk findes i repoet.
7. Lav IKKE større ændringer endnu.
8. Rapportér eventuelle forskelle mellem repositoryet og ovenstående handoff.
9. Herefter skal vi etablere GitHub som den officielle HSG update-kanal.

# Nyhedsbrev – vejledning (trin 1: modtagere, import og afmelding)

Modulet *Nyhedsbrev* (`newsletter`, version 1.0.0) holder styr på modtagerne af HSG's månedlige nyhedsbrev.
I trin 1 bygges modtagerlisten op fra Kims Excel-fil, og afmeldinger huskes. **Der sendes ingen mails i trin 1.**
Udsendelse via Microsoft 365, indstillinger og katalog kommer i trin 2 og 3.

Spec: `docs/specs/2026-10-19-nyhedsbrev.md`.

## Adgang

- Superadministratorer har altid adgang. Menupunktet hedder **Nyhedsbrev** under *Salg* (på mobil: *Mere → Nyhedsbrev*).
- Andre login-brugere har **ingen adgang**, før en superadministrator sætter flueben ved *Nyhedsbrev* under
  *Brugere → Login-brugere*. Det gælder også brugere, der oprettes, mens modulet er slået fra.
- Personlige adgangslinks (linkbrugere) kan aldrig få adgang til modulet.
- Modulet kan slås fra under *System → Moduler* (nødstop). Så svarer administrationssiderne 404,
  men afmeldingslinket virker stadig – det skal altid være muligt at afmelde sig.

## Månedlig import af kundelisten

1. Gå til *Nyhedsbrev → Import*, og vælg Excel-filen (.xlsx) eller en CSV-fil (højst 5 MB og 5.000 rækker).
2. Vælg **Hvem er i filen?**
   - *Kunder* – alle i filen er kunder.
   - *Forhandlere* – alle i filen er forhandlere.
   - *Brug kolonne* – en kolonne i filen angiver typen. Værdier, der starter med "forhandler"
     (uanset store og små bogstaver), bliver *Forhandler*. Alt andet bliver *Kunde*.
3. Tryk **Analysér fil**. Forhåndsvisningen viser:
   - hvilken kolonne der bruges til mail, navn, firma og type
   - antal nye, der opdateres, uændrede, afmeldte/stoppede/slettede (springes over) og ugyldige
   - dubletter i filen, konflikter, typeskift og hvor mange aktive modtagere der ikke står i filen
4. Ret evt. kolonnevalget (eller arket, hvis filen har flere ark), og tryk **Genberegn**.
5. Tryk **Importér N nye og opdatér M**. Importen logges i audit-loggen (kun antal, aldrig mailadresser).

**Kolonner:** Mailkolonnen findes ud fra overskriften (*E-mail*, *Mail*, *Emailadresse*, *Mailadresse*, *E-post*).
Har filen ingen overskrifter, bruges **kolonne D**. Navn (*Navn*, *Name*, *Kontakt*, *Kontaktperson*) og
firma (*Firma*, *Virksomhed*, *Company*) er valgfrie. Ændrer du kolonnevalget, huskes det ud fra
overskrifterne til næste import. Andre kolonner (telefon, adresse osv.) gemmes aldrig i databasen.
Kun kolonne A–AD (de første 30 kolonner) læses.

**Grænser:** Filen må højst fylde 5 MB og have 5.000 rækker med indhold (inklusive overskriftsrækken).
En Excel-fil må højst fylde 30 MB, når den pakkes ud. Er den større (fx fordi den har flere store ark),
så gem kun arket med modtagerne i en ny fil, eller gem det som CSV. Teksten i kolonne A–AD må højst fylde
10 MB i alt. Tomme rækker tæller ikke med.

**Regler:**
- Mailadresser renses (mellemrum, `mailto:` og `<>` fjernes) og skrives med små bogstaver.
  En celle med flere adresser adskilt af `;`, `,` eller mellemrum giver flere modtagere.
- Ugyldige adresser vises med rækkenummer og springes over. Adresser med æ, ø eller å i domænet er ugyldige.
- Samme adresse flere gange i filen bliver til én modtager ("Dubletter i filen").
- Står en adresse både som forhandler og kunde, importeres den som **Kunde** (konflikt).
- Eksisterende aktive modtagere får opdateret navn og firma (hvis filen har en værdi) og type.
  Typeskift vises tydeligt i forhåndsvisningen, før du importerer.
- **Afmeldte, stoppede og slettede modtagere ændres aldrig af en import** og tilmeldes aldrig igen.
- Aktive modtagere, der ikke står i filen, ændres ikke. Forhåndsvisningen viser kun antallet.
- Forhåndsvisningen gemmes kun midlertidigt i din session og aldrig i databasen. Den slettes, når importen er
  gennemført eller annulleret. Efter 2 timer er den udløbet og slettes, næste gang du åbner *Nyhedsbrev*.
- En celle på mere end 320 tegn afkortes og slutter med "…". Klippes en mailadresse midt over, står den
  afklippede del med "…" under *Ugyldige* og importeres aldrig.

## Modtagere

*Nyhedsbrev → Modtagere* viser tal for aktive, forhandlere, kunder og afmeldte og en liste, der kan
filtreres (Aktive, Forhandlere, Kunder, Afmeldte, Stoppede). Søgefeltet søger kun i listen i din browser.

- **Tilføj modtager:** Mail, navn, firma og type. Findes adressen allerede, får du besked.
  Er adressen tidligere afmeldt eller slettet, kræves fluebenet
  *"Modtageren har selv bedt om at blive tilmeldt igen"*.
- Tryk på mailadressen eller **Rediger** for at rette mail, navn, firma og type. Mailadressen kan kun ændres for aktive modtagere,
  så en afmeldt eller stoppet adresse aldrig mister sin spærring.
- **Afmeld:** Modtageren afmeldes (fx efter en svarmail med "afmeld").
- **Stop:** Bruges fx ved afviste mails. **Genaktivér** gør en stoppet modtager aktiv igen.
  En afmeldt modtager kan kun genaktiveres med fluebenet "selv bedt om".
- **Personligt afmeldingslink:** Vises på modtagerens side til manuel hjælp og test.

## Afmelding (modtageren)

Hver modtager har et personligt, hemmeligt afmeldingslink (`nyhedsbrev-afmeld.php?t=…`). Linket indeholder
aldrig mailadressen.

- Åbnes linket, vises en bekræftelse med en maskeret adresse (fx `k***@firma.dk`). **Der afmeldes ikke ved
  blot at åbne linket**, så linkscannere i mailprogrammer (fx Outlook Safe Links) ikke afmelder nogen.
- Trykker modtageren **Ja, afmeld mig**, er afmeldingen gældende med det samme.
- Mailprogrammers "Afmeld"-knap (ét klik, RFC 8058) afmelder direkte. Det bruges fra trin 2, når der sendes mails.
- Et ugyldigt eller slettet link giver en neutral side (HTTP 404), der ikke afslører, om en adresse findes.

## GDPR-henvendelser

1. Find modtageren med søgefeltet, og åbn den.
2. **Eksportér data (CSV)** giver en CSV-fil (UTF-8 med semikolon) med modtagerens oplysninger, som kan sendes
   til den registrerede (ret til indsigt).
3. **Slet modtager** sletter modtagerens mail, navn, firma og afmeldingslink (ret til sletning).
   Mailadressen gemmes kun som en hash (SHA-256), så personen ikke kommer med igen ved næste import.
   Hashen er ikke anonym: den, der kender mailadressen, kan beregne den samme hash og genkende den.
   Den bruges kun som spærring og vises ingen steder.

Afmeldte modtagere bevares som spærreliste uden automatisk anonymisering. Audit-loggen indeholder aldrig
mailadresser – kun modtager-id og antal.

## Teknisk

- Tabel: `hsg_newsletter_recipients` (migration 1.0.0 i `modules/newsletter/migrations.php`). Den kommer med i DATA- og FULL-backup.
- Afmeldingstokens gemmes som SHA-256 (opslag) og krypteret med samme nøgle som de personlige adgangslinks
  (`storage/data/access-link.key`), så linket kan vises igen og virker efter en gendannelse.
- Indstillingen `newsletter_import_columns` husker kolonnevalget ved import.
- Importen har sine egne læsere i `core/newsletter.php` (`core/supplier_import.php` er uændret). En xlsx-fil
  læses med PHP's XMLReader ét element ad gangen, og den udpakkede størrelse kontrolleres, før noget pakkes ud.
  Kræver PHP-udvidelserne zip og xmlreader. CSV kræver ingen udvidelser.
- Afmeldingssiden sender `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow` og `Cache-Control: no-store`.

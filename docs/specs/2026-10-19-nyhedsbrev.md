# Spec: Nyhedsbrev fra HSG Administration

- **Dato:** 2026-10-19
- **Status:** Udkast 3 – Michaels svar indarbejdet (også om afsender og kataloglink), afventer svar på spørgsmål 2, 6, 11 og 12
- **Ønske fra:** Kim, HSG Whisky ApS (mail "VS: brochure", 21-09-2026)
- **Nyt modul:** `newsletter` (version 1.0.0 → 1.1.0 → 1.2.0, ét versionsløft pr. trin)
- **Prioritet:** Fremtidsønske, ikke hastende. Bygges i tre trin, og hvert trin kan frigives for sig.

---

## Åbne spørgsmål

Spørgsmålene herunder er endnu ikke besvaret. Indtil der kommer svar, bygger udvikleren på antagelserne. Numrene er de samme som i første udkast, så svarene kan findes igen.

- **(2) Forhandlere og kunder i Excel-filen (Kim – påvirker trin 1).** Hvordan er de to grupper markeret: to filer eller én kolonne, og i så fald med hvilke værdier? Hvilke kolonner findes ud over mailadressen i kolonne D (navn, firma)?
  *Antagelse:* Administratoren vælger enten "Alle i filen er kunder", "Alle i filen er forhandlere" eller en kolonne. Vælges en kolonne, bliver værdier der starter med "forhandler" (uanset store/små bogstaver) til *Forhandler*, og alt andet til *Kunde*.
- **(6) Hvilket katalog og hvilke priser (Kim – påvirker trin 3).** Skal der sendes hele kataloget eller nyhedskataloget hver måned? Skal forhandlere kun have engrospriser, eller også et link med vejledende udsalgspriser?
  *Antagelse:* Katalogtypen vælges pr. udsendelse (hele kataloget / kun nyheder / intet katalog). Forhandlere får engrospriser ekskl. moms, og kunder får vejledende udsalgspriser inkl. moms.
- **(11) Overgang fra Outlook (Kim).** Stopper Kim med at sende via Bcc fra Outlook, når systemet sender? Ellers kan modtagerne få nyhedsbrevet to gange. Hvornår ønskes de enkelte trin i drift?
  *Antagelse:* Kim stopper med Bcc-udsendelsen, når trin 2 er i drift.
- **(12) Modtagere der forsvinder fra Excel-filen (Kim).** Skal en modtager automatisk sættes til "Stoppet", hvis vedkommende ikke længere står i månedens fil?
  *Antagelse:* Nej, ikke i første version. Forhåndsvisningen af importen viser kun antallet.

---

## Afklarede spørgsmål (19-10-2026)

Michael besvarede spørgsmålene den 19-10-2026. For hvert svar står konsekvensen for spec'en.

| Nr. | Spørgsmål | Svar | Konsekvens for spec'en |
|---|---|---|---|
| 1 | Retsgrundlag for udsendelsen | "Ikke relevant." | Systemet spørger ikke om retsgrundlag og registrerer det ikke. Kolonnen `basis`, valget af grundlag og bekræftelses-fluebenet ved import og manuel tilføjelse er fjernet, og det samme gælder grundlag i audit-details, CSV-eksport, skærme og acceptkriterier. Afmeldingslink i hver mail, `List-Unsubscribe` og spærrelisten er uændrede. |
| 3 | Mailudbyder og SMTP | Office 365. info@ ligger i Microsoft 365. | Beslutning 2 og 7 er omskrevet. Transporten er SMTP med OAuth2 (XOAUTH2) til `smtp.office365.com:587` via en egen Entra-app med `SMTP.SendAsApp`. Dedikerede udbydere (Brevo, Mailjet, SES) er flyttet til "Fravalgt" som reserve. |
| 4 | DNS og afsenderadresse | Michael styrer selv DNS. | `docs/NEWSLETTER.md` skrives til Michael med de præcise SPF-, DKIM- og DMARC-poster for Microsoft 365 (se Brugerflow A). "Tjek afsenderdomæne" kontrollerer alle tre, også DKIM via `selector1`/`selector2`. |
| 5 | Link i stedet for vedhæftning | "Kataloget skal der bare linkes til, så der skal være et direkte link i nyhedsbrevet." | Beslutning 5 er bekræftet. PDF'en vedhæftes ikke. Mailen har et direkte link, der åbner katalog-PDF'en med det samme, uden login og uden mellemside (S8). Den nuværende PDF-størrelse er ikke længere relevant. |
| 7 | Opbevaring af data | OK som foreslået. | Udsendelseslog 24 måneder, kataloglinks 90 dage, afmeldte gemmes som spærreliste uden automatisk anonymisering, og slettede gemmes som hash. Perioderne kan ændres under Indstillinger. |
| 8 | Ét-klik-afmelding uden CSRF | Godkendt. | Undtagelsen fra CSRF-reglen er **godkendt af Michael den 19-10-2026**. Den gælder kun `nyhedsbrev-afmeld.php`, kun med et gyldigt token og kun til afmelding (beslutning 3). CLAUDE.md ændres ikke. |
| 9 | Bunden af mailen | Samme adresse, CVR og privatlivspolitik som på hsg-whisky.dk. | Felterne "Bund i mailen" og "Privatlivspolitik" i indstillingerne bevares, og Michael udfylder dem ved opsætningen med oplysningerne fra hsg-whisky.dk. Der hardkodes intet. |
| 10 | Hvem må sende | De, der har adgang til modulet. | Som designet: `newsletter.send` følger moduladgangen, og superadministratorer har altid adgang. Andre login-brugere har ingen adgang, før en superadministrator giver den i `users.php`. Det sikres med `admin_default_access => false` (beslutning 6). |
| 13 | Afsenderpostkasse | Fra info@. | Som antaget: `newsletter_from_email` er `info@hsg-whisky.dk`, og Exchange-rettigheden gives til info@ (Brugerflow A). Der oprettes ingen ekstra postkasse. Risikoen for, at Microsoft begrænser info@, hvis mange markerer mailen som spam, står under beslutning 2, Risici. |

---

## Formål

**Problem:** I dag sender Kim nyhedsbrevet manuelt. Han kopierer mailadresserne fra kolonne D i en Excel-kundeliste ind i Bcc, sender fra info-adressen med et fast emne og en fast tekst og vedhæfter månedens PDF-katalog. Det giver flere problemer:
- Afmeldinger huskes ikke på tværs af måneder. En afmeldt modtager kommer med igen, når listen kopieres på ny.
- Der er intet personligt afmeldingslink og ingen `List-Unsubscribe`-header, som både lovgivning og spamfiltre forventer.
- Bcc-masseudsendelser og store vedhæftninger rammer ofte spamfiltre og mailudbyderens grænser.
- Forhandlere og kunder får det samme katalog. Kunderne må ikke se engrospriserne.

**Løsning:** Et nyt modul *Nyhedsbrev* i HSG Administration:
- en modtagerliste, der indlæses fra Kims Excel-fil og husker afmeldinger
- personlige afmeldingslinks
- én mail pr. modtager fra info@ via Microsoft 365 (SMTP med OAuth2), sendt fra en kø, der kan genoptages efter en afbrydelse
- forhåndsvisning og obligatorisk testudsendelse
- et personligt link til et fastfrosset katalog med den rigtige pris for modtagerens gruppe

**Brugere:**
- **Administrator (Kim, Michael):** importerer modtagere, skriver og tester nyhedsbrevet, sender det og håndterer afmeldinger og GDPR-henvendelser. Michael står for den engangsopsætning, der kræves i Microsoft 365 og DNS.
- **Modtager (forhandler eller kunde):** møder kun mailen, den offentlige afmeldingsside og kataloglinket. Modtageren har ingen login.
- **Linkbrugere og lagermedarbejdere:** har ingen adgang og ser ingen ændringer.

---

## Overblik og trinopdeling

| Trin | Modulversion | Indhold | Værdi alene |
|---|---|---|---|
| **1** | `newsletter` 1.0.0 | Modul og menupunkt, modtagertabel, Excel/CSV-import med forhåndsvisning, manuel tilføj/ret/afmeld/stop/slet/eksport, offentlig afmeldingsside med ét-klik-afmelding. **Der sendes ingen mails.** | Kims liste bliver renset (dubletter, ugyldige adresser), opdelt i forhandlere og kunder og klar med afmeldingstokens. |
| **2** | `newsletter` 1.1.0 | Indstillinger (afsender, Microsoft 365, hastighed, opbevaring, cron), egen SMTP-klient med OAuth2 (XOAUTH2) til Microsoft 365 og en lokal test-SMTP, udsendelser (kladde, forhåndsvisning, test, start), kø med batches via browser og cron, pause/genoptag/annullér, udsendelseslog og automatisk oprydning. Endnu uden katalog. | Nyhedsbrevet kan sendes sikkert fra info@ via systemet. Kim kan midlertidigt skrive et link i teksten. |
| **3** | `newsletter` 1.2.0 | Katalog: kataloggenereringen flyttes ud i en funktion, kataloget fryses pr. udsendelse, og der kommer en offentlig download pr. modtager med pris efter gruppe og udløbsdato. | Kataloget følger med, uden vedhæftning og uden at engrospriser bliver offentlige. |

Udvikleren bygger og frigiver ét trin ad gangen. Hvert trin har sine egne acceptkriterier (se nedenfor).

---

## Beslutninger og alternativer

### 1. Kundelisten: import ind i en ny tabel

**Anbefaling:** Kims Excel- eller CSV-fil importeres ind i en ny tabel `hsg_newsletter_recipients`, som oprettes via migration. Importen genbruger den eksisterende fillæsning i `core/supplier_import.php` (`hsg_supplier_read_xlsx()` og `hsg_supplier_read_csv()`). Den skal ikke kopieres eller ændres.

**Begrundelse:**
- Afmeldinger, personlige tokens og udsendelseslog kræver en varig tilstand pr. modtager.
- Ved import hver måned skal en afmeldt modtager genkendes og *aldrig* genaktiveres. Det kan kun lade sig gøre, hvis systemet husker modtageren.
- Kims Excel-fil forbliver hans masterliste til andre formål. Systemet gemmer kun mail, navn, firma og type. Andre kolonner (telefon, adresse osv.) læses aldrig ind i databasen.

**Regler for importen:**
- **Normalisering:** Mellemrum, `mailto:` og `<>` fjernes, og hele adressen skrives med små bogstaver. En celle med flere adresser adskilt af `;`, `,` eller mellemrum giver flere modtagere.
- **Gyldighed:** PHP's `FILTER_VALIDATE_EMAIL`, højst 190 tegn, og domænet skal indeholde et punktum. Ugyldige adresser vises med rækkenummer og springes over. Tomme celler ignoreres. Adresser med æøå i domænet (IDN) betragtes som ugyldige i denne version.
- **Dubletter:** Nøglen er SHA-256 af den normaliserede adresse (`email_hash`, UNIQUE). Dubletter i samme fil bliver til én modtager og tælles som "Dubletter i filen".
- **Konflikter:** Står samme adresse i filen både som forhandler og kunde, importeres den som **Kunde**. Det er den sikre side, fordi en kunde, der får engrospriser, er værre end en forhandler, der får vejledende udsalgspriser. Konflikten vises i forhåndsvisningen.
- **Genimport:** Eksisterende aktive modtagere opdateres (navn, firma og type, hvis filen har en værdi). Modtagere med status *Afmeldt*, *Stoppet* eller *Slettet* springes over og ændres aldrig af en import.
- **Typeskift:** Hvis en eksisterende modtager skifter type, fx fra Kunde til Forhandler, vises det tydeligt i forhåndsvisningen, før importen gennemføres.
- **Kolonnevalg:** Mailkolonnen findes automatisk ud fra en overskrift (`email`, `e-mail`, `mail`, `emailadresse`, `mailadresse`, `epost`, normaliseret som i `hsg_supplier_norm()`). Ellers bruges **kolonne D**. Navn (`navn`, `name`, `kontakt`, `kontaktperson`) og firma (`firma`, `virksomhed`, `company`) er valgfrie. Kolonnevalget huskes i `hsg_settings` (`newsletter_import_columns`) ud fra overskriftsnavn, ligesom i Kims uploadfil.

**Fravalgt:**
- *Upload af Excel-filen ved hver udsendelse uden tabel.* Systemet ville ikke kunne huske afmeldinger, give tokens eller føre log.
- *En fuld CRM-kundetabel.* For stort et scope.
- *Webshopkunder fra `hsg_woocommerce_orders.customer_email`.* Det er en anden kundeliste med et andet formål. Kims liste er masterlisten for nyhedsbrevet.

### 2. Afsendelse via Microsoft 365 (info@)

**Anbefaling: SMTP med OAuth2 (XOAUTH2) til `smtp.office365.com:587` med STARTTLS.**

- **SMTP-klient:** En lille SMTP-klient skrevet i projektet (`core/newsletter_mailer.php`) med `stream_socket_client` og certifikatkontrol slået til. Der bruges ingen Composer og ingen tredjepartskode.
- **Login:**
  - Klienten logger ind med `AUTH XOAUTH2` som den postkasse, der står i `newsletter_from_email` (info@).
  - Login-strengen er base64 af `user=<postkasse>`, tegnet 0x01, `auth=Bearer <token>` og to gange tegnet 0x01.
  - Strengen bygges af den rene funktion `hsg_newsletter_xoauth2_string(string $user, string $token): string`, så den kan testes uden netværk.
- **Token:**
  - Hentes med client credentials fra `https://login.microsoftonline.com/<tenant>/oauth2/v2.0/token` med scope `https://outlook.office365.com/.default`.
  - Hentes via den eksisterende `hsg_http_request()` (cURL) i `core/onedrive.php`, som indlæses med `require_once` og ikke ændres.
  - Hentes én gang pr. kald (det er gyldigt i ca. en time) og findes kun i hukommelsen. Det gemmes aldrig i databasen, i loggen eller i fejltekster.
- **Entra-app:**
  - Nyhedsbrevet får en **egen Entra-app** med programtilladelsen **`SMTP.SendAsApp`** (API'et "Office 365 Exchange Online").
  - Appen registreres som service principal i Exchange Online (`New-ServicePrincipal`) og får rettigheden **FullAccess** til info@ (`Add-MailboxPermission`), som Microsofts vejledning beskriver.
  - SMTP AUTH skal være slået til for info@ (`Set-CASMailbox … -SmtpClientAuthenticationDisabled $false`). Det gælder også med OAuth.
  - Appen kan kun sende som de postkasser, den udtrykkeligt har fået rettighed til.
  - Opsætningen står trin for trin i Brugerflow A.
- **Lokal test-SMTP:**
  - Forsendelsesmetoden "Lokal test-SMTP" bruger ren SMTP uden TLS og uden login, og kun til `127.0.0.1`, `localhost` eller `::1`.
  - Den er beregnet til testerens SMTP-opsamler og bruger præcis samme kø, MIME-opbygning og fejlhåndtering som Microsoft 365. Kun login-trinnet er sprunget over.
- **Én mail pr. modtager:** Feltet `To:` indeholder kun modtageren. Der bruges aldrig Bcc eller Cc. Det er nødvendigt for det personlige afmeldingslink, for `List-Unsubscribe` og for, at én afvist adresse ikke stopper alle de andre.
- **Kø og batches:** Tabellen `hsg_newsletter_deliveries` er både kø og log. Den afvikles i små batches:
  - **Fra browseren:** Siden for udsendelsen kalder `newsletter_send.php` via AJAX (samme mønster som Billedtjek med `fetch` og `csrf` i FormData), igen og igen, indtil køen er tom.
  - **Fra webhotellets cron:** `cron/newsletter.php` følger mønstret fra `cron/backup.php` (CLI eller `?key=` med `hash_equals` mod `setting_get(...,'newsletter_cron_key')`). Udsendelsen fortsætter derfor, selv om Kim lukker browseren.
- **Tidsgrænse:** Hvert kald fra browseren arbejder højst ~15 sekunder (målt i realtid) og højst `newsletter_batch_size` mails. Cron-kald arbejder højst 240 sekunder fra CLI og 25 sekunder via URL. Det ligger langt under typiske grænser for `max_execution_time` og proxy-timeout. Endpointet sætter `ignore_user_abort(true)`, så en lukket fane ikke afbryder midt i en SMTP-transaktion.
- **Hastighed tilpasset Exchange Online:**
  - Exchange Online tillader 30 beskeder pr. minut og 10.000 modtagere pr. døgn (rullende 24 timer) pr. postkasse.
  - Den planlagte grænse på 2.000 eksterne modtagere pr. døgn pr. postkasse er annulleret (Microsofts opdatering af 06-01-2026).
  - Organisationen har desuden en samlet grænse for eksterne modtagere pr. døgn, som skalerer med antallet af licenser.
  - **Standardværdier:** højst **20 pr. minut** (så der er plads til Kims egne mails fra info@ samtidig), 600 pr. time og 2.000 pr. døgn. Grænsen pr. minut kan højst sættes til 30.
  - Inden for ét kald holdes der mindst `60 / newsletter_max_per_minute` sekunder mellem to mails (3 sekunder ved 20 pr. minut).
  - Før hver mail tælles de sendte mails i log-tabellen for de seneste 60 sekunder, 60 minutter og 24 timer. Er en grænse nået, stopper kaldet og svarer med `wait_until`.
  - Kims få hundrede modtagere tager derfor 15–20 minutter (312 modtagere ≈ 16 minutter) og rammer aldrig Microsofts grænser.
- **Ingen dobbelt-mails ved genoptagelse (højst én mail pr. modtager):**
  1. `UNIQUE (campaign_id, recipient_id)` i køen, så hver modtager kun kan stå i køen én gang pr. udsendelse.
  2. Én afsender ad gangen via MySQL `GET_LOCK(CONCAT(DATABASE(),':hsg_newsletter'),0)`. Låsen frigives automatisk, hvis PHP dør. Optager browser og cron samtidig, svarer den ene "optaget".
  3. Hver række reserveres med en betinget `UPDATE … SET status='sending' WHERE id=? AND status='queued'`, *lige før* den sendes. Rækken bliver først `sent`, når SMTP-serveren har svaret 250 på DATA.
  4. Når en batch starter (med låsen), er alle rækker i `sending` efterladt af en afbrudt proces. De sættes til **`unknown` og sendes ikke automatisk igen**. Administratoren ser antallet og kan vælge "Send igen til disse N" med en advarsel om, at de måske får mailen to gange.
  5. Fejl *før* den afsluttende `.` i DATA betyder, at mailen sikkert ikke er sendt.
     - En midlertidig fejl (4xx) sender rækken tilbage i køen. Der gives op til 3 forsøg med 15 minutters mellemrum.
     - En permanent fejl (5xx) for netop denne modtager gør rækken `failed`.
     - Mistes forbindelsen *efter* den afsluttende `.`, sættes rækken til `unknown`.
  6. **Fejl, der gælder afsenderen, sætter udsendelsen på pause** med en dansk forklaring. Den reserverede række går tilbage i køen og bliver ikke `failed`. Det gælder, når:
     - tokenet ikke kan hentes (fx forkert client secret, `AADSTS…`)
     - forbindelsen eller STARTTLS fejler
     - AUTH afvises (fx `535 5.7.3` eller `535 5.7.139`)
     - `MAIL FROM` afvises (fx `530 5.7.57` eller `5.7.60`)
     - et svar nævner afsenderblokering eller kvote (`5.1.8`, `5.1.90`, `SubmissionQuotaExceeded`)

     Fejl ved `RCPT TO` gælder kun den modtager: `failed` ved 5xx, nyt forsøg ved 4xx.
  7. Modtagerens status kontrolleres lige før hver mail. Er modtageren afmeldt efter starten, bliver rækken `skipped`.
- **SPF, DKIM og DMARC for Microsoft 365:**
  - Michael styrer selv DNS (afklaret spørgsmål 4).
  - SPF skal indeholde `include:spf.protection.outlook.com`.
  - DKIM slås til for `hsg-whisky.dk` med de to CNAME-poster `selector1._domainkey` og `selector2._domainkey`. Uden DKIM for eget domæne signerer Microsoft med `…onmicrosoft.com`, og så kan DKIM ikke bruges til at bestå DMARC.
  - Der skal være en DMARC-post på `_dmarc.hsg-whisky.dk`.
  - Da info@ allerede sender fra Microsoft 365 i dag, er SPF og DKIM sandsynligvis sat op. "Tjek afsenderdomæne" bekræfter det (se S3).
  - De præcise poster står i Brugerflow A og i `docs/NEWSLETTER.md`.
- **Sendt post og headere:** Efter vores viden lægger Exchange ingen kopi i info@'s Sendt post, når mails indleveres via SMTP, og egne headere som `List-Unsubscribe` og `List-Unsubscribe-Post` bevares. Det kontrolleres i driftstjekket (Brugerflow A, trin 8). Oversigten over, hvad der er sendt, er HSG's udsendelseslog.
- **Risici:**
  - **Masseudsendelse:** Microsofts servicebeskrivelse anbefaler en specialiseret udbyder til kommercielle masseudsendelser. Én månedlig udsendelse til få hundrede kendte modtagere i lavt tempo ligger inden for grænserne. Markerer mange modtagere mailen som spam, kan Microsoft dog begrænse den postkasse, der sender. Det er info@ (afklaret spørgsmål 13), så Kim kan i så fald ikke sende almindelig mail fra info@, før postkassen er frigivet i Microsoft Defender. Det lave tempo og ét-klik-afmeldingen holder risikoen nede.
  - **Port 587:** Nogle webhoteller blokerer udgående forbindelser på port 587. "Test forbindelse" afslører det med det samme. Sker det, er reserven Graph `sendMail` over HTTPS (se Fravalgt).
  - **Client secret:** Den udløber efter højst 24 måneder. Indstillingen `newsletter_m365_secret_expires` giver en advarsel 30 dage før.

**Fravalgt:**
- *SMTP AUTH med brugernavn og adgangskode (Basic auth) mod Office 365:* Microsoft slår det fra som standard for eksisterende organisationer ved udgangen af december 2026. Administratorer kan slå det til igen, og den endelige fjernelse annonceres i 2027. Vi bygger ikke på adgangskoder, der er ved at blive udfaset.
- *Microsoft Graph `sendMail`* (reserve, hvis webhotellet blokerer port 587):
  - JSON-formatet tillader kun egne headere, der starter med `x-`, så `List-Unsubscribe` kan ikke sættes.
  - MIME-formatet (base64) er den dokumenterede vej til egne headere, men erfaringerne er modstridende om, hvorvidt `List-Unsubscribe-Post` overlever.
  - Graph lægger som standard en kopi i Sendt post.
  - Det kræver en ekstra kodevej, som testerens SMTP-opsamler ikke kan afprøve.
  - Tilladelsen `Mail.Send` giver adgang til at sende som alle postkasser, medmindre den afgrænses særskilt.
- *High Volume Email (HVE) og Azure Communication Services:*
  - HVE er beregnet til automatiske mails inden for organisationen fra dedikerede konti.
  - ACS kræver et Azure-abonnement og en separat opsætning af domænet.
  - Begge er for store til få hundrede mails om måneden.
- *Dedikeret nyhedsbrevsudbyder (Brevo, Mailjet, Amazon SES):* Ikke længere anbefalingen, da info@ ligger i Microsoft 365. Den kan bruges som reserve, hvis Microsoft begrænser postkassen. Det kræver en databehandleraftale, nye DNS-poster og en ny forsendelsesmetode "SMTP med adgangskode", som er uden for scope. Køen og MIME-opbygningen skal ikke ændres.
- *Webhotellets egen SMTP:* Fx forbyder Simply.com nyhedsbreve via `smtp.simply.com`.
- *PHP `mail()`:* giver intet svar pr. modtager, har intet login mod Microsoft 365 (og dermed ingen DKIM-justering) og giver en større risiko for header-injection.
- *PHPMailer:* kræver Composer eller at tredjepartskode lægges ind i projektet. Det er ikke tilladt.
- *Bcc som i dag:* se begrundelsen under "Én mail pr. modtager".

### 3. Afmelding og GDPR

**Anbefaling:**
- **Token:**
  - Hver modtager får ved oprettelsen et tilfældigt token på 32 bytes (64 hex-tegn).
  - Det gemmes præcis som de personlige adgangslinks i `lager_users`: `token_hash` (SHA-256, UNIQUE, bruges til opslag) og `token_cipher` (AES-256-GCM via de eksisterende `hsg_access_link_encrypt()` og `hsg_access_link_decrypt()` i `core/access_links.php`, uændrede). Krypteringen gør det muligt at lave linket igen ved hver udsendelse.
  - Nøglen ligger allerede i `storage/data/` og følger både DATA- og FULL-backup. Derfor virker afmeldingslinks også efter en gendannelse.
  - Der oprettes ingen nye filer i `storage/data/`.
  - Kan et token ikke dekrypteres (fx hvis nøglen mangler), udstedes et nyt token, før mailen sendes.
- **Offentlig side `nyhedsbrev-afmeld.php?t=<token>`** (uden login):
  - **GET ændrer aldrig noget.** Linkscannere som Outlook Safe Links følger GET-links. Siden viser "Vil du afmelde k\*\*\*@firma.dk fra nyhedsbrevet fra HSG Whisky ApS?" med en knap.
  - **POST fra formularen** kræver `csrf_field()` og verificering (session fra `session.php`) og afmelder (`via='link'`).
  - **POST med body `List-Unsubscribe=One-Click`** (RFC 8058) afmelder uden CSRF (`via='one_click'`) og svarer 200 med teksten `Afmeldt`.
    - Undtagelsen fra CSRF-reglen er **godkendt af Michael den 19-10-2026** (afklaret spørgsmål 8). Den gælder kun denne side, kun med et gyldigt token og kun til afmelding. CLAUDE.md ændres ikke.
    - Det hemmelige token på 256 bit fungerer som autorisation, og den eneste mulige handling er afmelding, som kan gentages uden virkning.
  - Siden inkluderer **ikke** `auth.php`, fordi den ville afvise uden login og kræve CSRF. Den inkluderer `session.php`, `functions.php`, `db.php`, `core/settings.php`, `core/audit.php`, `core/access_links.php` og `core/newsletter.php`, som `cron/backup.php` gør. Den respekterer `.hsg-maintenance` (503).
  - **Siden virker også, når modulet er deaktiveret.** Det skal altid være muligt at afmelde sig.
  - Headere: `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store`.
  - Et ugyldigt token giver HTTP 404 og en neutral tekst. Den afslører intet om, hvorvidt en adresse findes.
- **Mailen:** Headeren `List-Unsubscribe: <https://…/nyhedsbrev-afmeld.php?t=TOKEN>, <mailto:svar-til?subject=Afmeld>` og `List-Unsubscribe-Post: List-Unsubscribe=One-Click`. Synligt afmeldingslink og teksten "Du kan også svare på denne mail og skrive 'afmeld'" nederst. Administratoren afmelder manuelt på modtagerens side (`via='admin'`).
- **Mailadresser i URL'er:** Mailadresser må aldrig stå i URL'er. Offentlige links indeholder kun token og udsendelses-id. Administrationssider bruger `?id=`. Søgning i modtagerlisten filtrerer i browseren (JavaScript), så der aldrig sendes en GET-forespørgsel med mailadressen. Eksport og sletning sker via POST.
- **Mailadresser i audit-loggen:** Audit-loggen indeholder aldrig mailadresser, kun modtager-id og antal.
- **Afmeldte bevares** med status *Afmeldt* som spærreliste uden automatisk anonymisering (afklaret spørgsmål 7), så en genimport ikke tilmelder dem igen. Genaktivering kræver, at administratoren sætter flueben ved "Modtageren har selv bedt om at blive tilmeldt igen". Det audit-logges.
- **Sletning (ret til sletning):** Rækken anonymiseres. `email`, `name`, `company`, `token_hash` og `token_cipher` sættes til NULL, og status sættes til `deleted`. Kun `email_hash` bevares, så personen ikke kommer med igen ved næste import. Rækker i køen og loggen peger kun på modtager-id og indeholder ingen mailadresse.
- **Eksport (ret til indsigt):** CSV med UTF-8 BOM og semikolon. Den indeholder modtagerens felter (mail, navn, firma, type, status, kilde, oprettet, afmeldt) og (fra trin 2) udsendelseshistorikken. Celler, der starter med `=`, `+`, `-` eller `@`, får et foranstillet `'`, så de ikke kan køre som formler i Excel.
- **Opbevaring (afklaret spørgsmål 7):**
  - Udsendelsesrækker slettes, når udsendelsen blev afsluttet for mere end `newsletter_log_retention_months` (standard 24) måneder siden. Selve udsendelsen (emne og tekst) bevares, da den ikke indeholder personoplysninger.
  - Katalogfiler slettes efter udløb (trin 3).
  - Oprydningen kører i cron og, når en administrator åbner Nyhedsbrev.
- **Grundlag for udsendelsen:** Grundlaget for at sende nyhedsbrevet til modtagerne er HSG's ansvar uden for systemet. Systemet spørger ikke om det og registrerer det ikke.

**Fravalgt:**
- *Mailadresse i afmeldingslinket (`?email=`):* lækker til logfiler og browserhistorik og lader alle afmelde alle.
- *Kun manuel afmelding via svarmail:* langsomt og strider mod forventningen om let og gratis afmelding. Svarmail beholdes som et ekstra supplement.
- *Afmelding ved GET:* linkscannere ville afmelde folk utilsigtet.

### 4. Forhåndsvisning og testudsendelse

**Anbefaling:**
- **Forhåndsvisning:** Mailens HTML-version vises i browseren i en sandboxet iframe (`newsletter_preview.php`, kun GET, kun læsning, CSP `default-src 'none'; style-src 'unsafe-inline'`). Man kan skifte mellem varianterne *Forhandler* og *Kunde*. Tekstversionen vises i en `<details>`.
- **"Send test":** Testen sendes til 1–5 testadresser. Feltet er udfyldt med `newsletter_test_email`, og den senest brugte adresse gemmes. Der sendes én mail pr. variant, som udsendelsen rammer. Emnet får præfikset `[TEST] `, og øverst i mailen står "TESTUDSENDELSE – variant: Forhandler (engrospriser)". Links i testmails bruger et test-token for udsendelsen: afmeldingssiden svarer "Dette er et testlink – ingen er afmeldt", og kataloglinket virker for begge varianter (trin 3).
- **Indholdshash:** Systemet beregner en SHA-256 over alt, der påvirker mailen: emne, tekst, målgruppe, katalogtype, katalogfilernes SHA-256, afsendernavn, afsenderadresse, svar-til, footer, link til privatlivspolitik og offentlig adresse. Hashen gemmes som `tested_hash`, når en test er sendt.
- **"Start udsendelse"** er kun mulig, når *den aktuelle* hash er lig `tested_hash`. Ændres noget efter testen, vises "Ændret siden testen – send en ny test", og start afvises på serveren.
- **Bekræftelse:** Panelet viser "312 modtagere (48 forhandlere, 264 kunder)". Brugeren skal sætte flueben ved "Jeg har modtaget og kontrolleret testmailen". Knappen hedder "Start udsendelse til 312 modtagere" og viser en JavaScript-bekræftelse. Serveren kontrollerer, at det indsendte `expected_count` stadig passer. Er modtagerlisten ændret, vises "Modtagerlisten er ændret – kontroller antallet igen."

**Fravalgt:**
- *Kun JavaScript-`confirm()`:* kan omgås og sikrer ikke, at den sendte version er testet.
- *E-mail på login-brugeren:* `lager_admins` har ingen mailkolonne, og det ville kræve en ændring af en kernetabel. I stedet bruges et testadressefelt.

### 5. Kataloget: link til et fastfrosset øjebliksbillede

**Anbefaling (trin 3):**
- **Frys katalog:** Under udsendelsen trykker administratoren på "Frys katalog nu". Det genererer PDF'en på serveren for hver nødvendig prisvariant: engros til forhandlere og vejledende udsalgspris til kunder, enten som hele kataloget eller kun nyheder.
- **Lagring:** PDF'erne gemmes i **`storage/tmp/newsletter/<udsendelses-id>/<32 tilfældige hex-tegn>.pdf`** med metadata i `hsg_newsletter_catalogs` (SHA-256, størrelse, udløb, antal downloads).
- **Hvorfor `storage/tmp/newsletter/`:**
  - Mappen er allerede afvist af `storage/.htaccess` (`Require all denied`) og ignoreret i `.gitignore`.
  - Den står allerede som forbudt i `tools/build-release.php` (`PROHIBITED_PATHS`), så en genereret PDF aldrig kommer med i en release-ZIP (jf. fejlen med `test-catalog-v151.pdf`).
  - Den er allerede beskyttet i opdateringsmodulet (`hsg_update_protected_relpath`).
  - Filerne er midlertidige (udløber efter 90 dage) og kræver derfor ikke backup. Både DATA- og FULL-backup udelader i dag `storage/tmp/`.
  - Konklusion: Der skal **ikke ændres** i opdateringsmodulet, release-byggeren, `.gitignore` eller backupmodulet for at gemme filerne.
- **Generering:** Kræver en udtrækning af logikken i `catalog_pdf.php` til en funktion i `core/catalog_pdf_builder.php`, plus en metode i `SimplePdf` (`pdf_simple.php`), der skriver til en fil i stedet for at streame og afslutte. `catalog_pdf.php` skal give præcis samme resultat som før.
- **Offentlig download `nyhedsbrev-katalog.php?t=<modtager-token>&c=<udsendelses-id>`:**
  - Kræver, at tokenet hører til en modtager, der har en udsendelsesrække med status `sent` eller `unknown` for netop den udsendelse.
  - **Varianten afgøres på serveren** ud fra udsendelsesrækkens `segment`. Der findes ingen prisparameter i URL'en, så en kunde kan ikke hente engrosvarianten.
  - Efter `expires_at` (start + `newsletter_catalog_link_days`, standard 90) svarer siden HTTP 410: "Linket er udløbet".
  - Der tælles kun downloads samlet pr. fil. Der logges intet pr. modtager.
  - Samme headere som afmeldingssiden. `Content-Disposition: inline`.
  - Når modulet er deaktiveret, svarer siden 404 (nødstop).
- **Mailens tekst:**
  - Forhandlere: "Kataloget viser engrospriser ekskl. moms."
  - Kunder: "Kataloget viser vejledende udsalgspriser inkl. moms."
  - Begge: "Linket virker til og med <dato>."

**Fravalgt:**
- *Vedhæftning:* Katalog-PDF'en med indlejrede flaskebilleder kan nemt fylde flere MB. Det giver hundredvis af kopier gennem SMTP, rammer udbyderens størrelsesgrænser, øger spam-scoren og kan ikke trækkes tilbage. Desuden skal der være forskellig PDF pr. gruppe.
- *Link direkte til `catalog_pdf.php`:* kræver login. At åbne siden offentligt ville vise hele kataloget og engrospriserne for alle. Siden genereres desuden live, så priserne ville ændre sig efter udsendelsen, og hvert klik ville belaste serveren.
- *PDF som BLOB i databasen:* store backups (hex-dump) og risiko for at ramme `max_allowed_packet`.
- *`uploads/`:* ligger direkte på webben uden tokenkontrol og må ikke bruges ifølge CLAUDE.md.
- *Ny mappe `storage/newsletter/`:* ville kræve ændringer i opdateringsmodulet, release-byggeren og `.gitignore`, men giver ingen ekstra værdi for midlertidige filer.

### 6. Rettigheder, CSRF og audit-log

**Anbefaling:**
- **Modul og capability:** Nyt modul `modules/newsletter/` med `capability => 'newsletter.send'` og `link_access => false`.
  - `hsg_capabilities()` giver automatisk `newsletter.send`, `newsletter.view` og `newsletter.operate` til login-brugere med moduladgang. Superadministratorer har `*`.
  - Linkbrugere får aldrig capabilities ud over de fem, der er kodet fast. Modulet vises heller ikke i `user_permissions.php`, fordi `link_access` er false.
- **Standard er ingen adgang for almindelige login-brugere:** `hsg_admin_can_view_module()` returnerer i dag `true`, hvis der ikke findes en række i `hsg_admin_module_access`. Det er ikke nok at indsætte rækker med `can_view=0` i migrationen: `users.php` sletter alle en brugers rækker ved gem og skriver kun rækker for moduler, der er *aktive* lige nu (`$assignableModules`). Er Nyhedsbrev slået fra (fx som nødstop), mens en superadministrator gemmer en login-bruger, forsvinder rækken, og brugeren får adgang til at sende, når modulet slås til igen. Det samme gælder login-brugere, der oprettes, mens modulet er slået fra.
  - **Løsning:** Manifestet får den nye nøgle `'admin_default_access' => false`. `hsg_admin_can_view_module()` i `core/permissions.php` ændres, så en *manglende* række (og en manglende `hsg_admin_module_access`-tabel) giver manifestets `admin_default_access` (standard `true`) i stedet for altid `true`. Alle eksisterende moduler opfører sig præcis som før, fordi de ikke har nøglen.
  - Migration 1.0.0 indsætter desuden `(admin_id, 'newsletter', can_view=0)` med `INSERT IGNORE` for alle eksisterende ikke-superadministratorer, så fluebenet i `users.php` står korrekt fra start. Senere styres adgangen med det eksisterende flueben i `users.php`.
- **Sidebeskyttelse:** Alle administrationssider bruger `require __DIR__.'/auth.php'`, `require_module_enabled('newsletter')` og `require_capability('newsletter.send')`.
- **CSRF:**
  - Alle POST-formularer har `csrf_field()`. `auth.php` kalder `verify_csrf()` globalt.
  - AJAX-endpointet sender `csrf` i FormData.
  - Den eneste undtagelse er ét-klik-POST på `nyhedsbrev-afmeld.php` (godkendt af Michael 19-10-2026, se punkt 3).
- **Audit:** `audit_log($pdo, $action, $entityType, $entityId, $details)`. `details` må aldrig indeholde mailadresser, client secrets eller tokens.

| Handling | `action` | `entity_type` / `entity_id` | `details` (eksempel) |
|---|---|---|---|
| Import gennemført | `newsletter.import` | `newsletter` / – | filnavn, ark, segmentvalg, antal nye/opdaterede/uændrede/sprunget over/ugyldige/dubletter/konflikter |
| Modtager tilføjet manuelt | `newsletter.recipient.create` | `newsletter_recipient` / id | segment |
| Modtager rettet | `newsletter.recipient.update` | `newsletter_recipient` / id | navnene på de ændrede felter (ingen værdier) |
| Afmelding | `newsletter.recipient.unsubscribe` | `newsletter_recipient` / id | `via`: link / one_click / admin |
| Stoppet / genaktiveret | `newsletter.recipient.stop` / `.reactivate` | `newsletter_recipient` / id | `self_requested: true` ved genaktivering af en afmeldt |
| Slettet (anonymiseret) | `newsletter.recipient.delete` | `newsletter_recipient` / id | – |
| Dataeksport | `newsletter.recipient.export` | `newsletter_recipient` / id | – |
| Indstillinger gemt | `newsletter.settings` | `system` / – | nøglerne der er ændret; `m365_secret_changed: true` |
| Forbindelsestest | `newsletter.smtp_test` | `system` / – | forsendelsesmetode, ok/fejl, SMTP-kode eller Microsoft-fejlkode (`AADSTS…`) |
| Ny cron-nøgle | `newsletter.cron_key.rotate` | `system` / – | – |
| Udsendelse oprettet / rettet / kopieret / slettet | `newsletter.campaign.create` / `.update` / `.copy` / `.delete` | `newsletter_campaign` / id | – |
| Katalog fastfrosset | `newsletter.campaign.catalog_snapshot` | `newsletter_campaign` / id | varianter, størrelser, de første 12 tegn af SHA-256 |
| Test sendt | `newsletter.campaign.test` | `newsletter_campaign` / id | antal testadresser, varianter, de første 12 tegn af hashen |
| Udsendelse startet | `newsletter.campaign.start` | `newsletter_campaign` / id | antal pr. segment, de første 12 tegn af hashen |
| Pause / genoptag / annullér resten | `newsletter.campaign.pause` / `.resume` / `.cancel` | `newsletter_campaign` / id | antal i kø |
| Automatisk pause (afsenderfejl) | `newsletter.campaign.auto_pause` | `newsletter_campaign` / id | årsag og kode (uden secret og token) |
| Gensend ukendte | `newsletter.campaign.retry_unknown` | `newsletter_campaign` / id | antal |
| Udsendelse færdig | `newsletter.campaign.complete` | `newsletter_campaign` / id | sendt/fejlet/ukendt/sprunget over |
| Oprydning | `newsletter.cleanup` | `system` / – | antal slettede logrækker og katalogfiler (logges kun, hvis antallet er større end 0) |

Der logges **ikke** pr. afsendt mail (loggen findes i `hsg_newsletter_deliveries`) og ikke pr. katalogdownload.

### 7. Indstillinger

**Anbefaling:**
- Indstillingerne gemmes i `hsg_settings` via `setting_get()` og `setting_set()` (nøgler: se *Data*), på samme måde som `openai_api_key` og `onedrive_client_secret`. Siden er `newsletter_settings.php`.
- **Forsendelsesmetode (`newsletter_transport`):**
  - `m365` – "Microsoft 365 (OAuth2)". Standard. Vært, port og kryptering ligger fast i koden: `smtp.office365.com`, 587, STARTTLS.
  - `local` – "Lokal test-SMTP uden login". Vært og port kan indstilles, men værten skal være `127.0.0.1`, `localhost` eller `::1`.
- **Egen Entra-app med egne indstillinger:** `newsletter_m365_tenant_id`, `newsletter_m365_client_id`, `newsletter_m365_client_secret` og `newsletter_m365_secret_expires` (valgfri dato, som giver en advarsel 30 dage før udløb).
  - Er feltet Tenant ID tomt, foreslås værdien af `onedrive_tenant_id`. Det er kun et forslag i feltet, og OneDrive-indstillingerne bruges ikke til andet.
  - **Begrundelse:**
    - OneDrive-backuppens adfærd forbliver helt uændret, fordi hverken `hsg_onedrive_token()` (fast scope og statisk cache) eller OneDrive-indstillingerne røres.
    - Nyhedsbrevets secret kan fornyes eller tilbagekaldes uden at påvirke backuppen.
    - Appen får kun `SMTP.SendAsApp` og kun til de postkasser, Exchange giver den rettighed til.
    - OneDrive-backuppen er valgfri og findes måske slet ikke.
- **Hemmelige nøgler:** `newsletter_m365_client_secret` og `newsletter_cron_key` føjes til `hsg_backup_sensitive_setting_keys()` i `core/backup.php`. De kommer derfor ikke med i backup, ligesom de eksisterende hemmeligheder. Teksterne om, hvilke hemmeligheder der ikke tages med, opdateres i restore-README'en og på `backup.php` ("client secret til nyhedsbrevets Microsoft 365-app og nyhedsbrevets cron-nøgle").
- **Client secret-feltet** kan kun skrives i: `type="password"` med tom værdi og teksten "Lad feltet være tomt for at beholde den eksisterende client secret". Det er samme mønster som client secret i `backup.php`. Secret og tokens vises, logges og returneres aldrig, heller ikke i fejltekster fra Microsoft.
- **Offentlig adresse (`newsletter_public_base_url`) er obligatorisk.** `base_url()` giver `http://localhost` i CLI-cron og `…/cron` ved kald af cron via URL. Links i mails skal derfor bygges ud fra en fast adresse. Feltet foreslår `base_url()`, når det vises første gang. Det skal starte med `https://`, eller `http://localhost` / `http://127.0.0.1` til test, og må ikke have en afsluttende `/`.
- **Afsenderoplysninger:** Michael udfylder felterne "Bund i mailen" (adresse og CVR) og "Privatlivspolitik" ved opsætningen med de samme oplysninger som på hsg-whisky.dk (afklaret spørgsmål 9). Der hardkodes intet.
- **cURL:** Mangler PHP cURL, viser siden "PHP cURL mangler – Microsoft 365 kan ikke bruges", og Test og Start er deaktiveret for `m365`.

**Fravalgt:**
- *Genbrug af OneDrive-appen med en ekstra tilladelse:*
  - Én hemmelighed ville give adgang til både filer (`Files.ReadWrite.All`) og afsendelse af mail. Det øger skaden, hvis den lækker.
  - Fornyelse af secret ville påvirke backup og nyhedsbrev på samme tid.
  - `hsg_onedrive_token()` skulle ændres for at kunne bruge et andet scope, og det strider mod kravet om uændret OneDrive-adfærd.
- *`config.php`:* må ikke røres, og administratoren kan ikke redigere den.
- *Kryptering af client secret med nøglen til adgangslinks:* nøglen ligger i samme backup som databasen og giver derfor ingen reel beskyttelse. Det ville afvige fra det eksisterende mønster.
- *Fri SMTP-vært med adgangskode:* uden for scope (se beslutning 2, Fravalgt).

---

## Brugerflow

### A. Første opsætning (Michael, trin 1 og 2)
1. Opgrader HSG. Modulet *Nyhedsbrev* bliver oprettet automatisk, og kun superadministratorer har adgang.
2. **Microsoft 365 – engangsopsætning (ca. 30 minutter).** Samme trin står i `docs/NEWSLETTER.md`.
   1. *Entra admin center → App-registreringer → Ny registrering:* navn "HSG Administration – Nyhedsbrev", kun konti i denne organisation, ingen omdirigerings-URI. Notér **Application (client) ID** og **Directory (tenant) ID**.
   2. *Certifikater og hemmeligheder → Ny client secret* (fx 24 måneder). Kopiér **Value** med det samme, og notér udløbsdatoen.
   3. *API-tilladelser → Tilføj en tilladelse → "APIs my organization uses" → Office 365 Exchange Online → Programtilladelser → `SMTP.SendAsApp`* → **Giv administratorsamtykke**.
   4. *Enterprise-programmer → appen → Oversigt:* kopiér **Object ID**. Det skal være enterprise-appens (service principalens) ID, ikke app-registreringens.
   5. *Exchange Online PowerShell* (`Connect-ExchangeOnline`):
      - `New-ServicePrincipal -AppId <client-id> -ObjectId <enterprise-object-id> -DisplayName "HSG Nyhedsbrev"`
      - `Add-MailboxPermission -Identity info@hsg-whisky.dk -User <enterprise-object-id> -AccessRights FullAccess`
      - `Set-CASMailbox -Identity info@hsg-whisky.dk -SmtpClientAuthenticationDisabled $false`. Det slår SMTP AUTH til for netop info@ og kræves også med OAuth. Det samme kan gøres i Microsoft 365 Admin Center under brugeren → Mail → Administrer mailapps → "Authenticated SMTP".
   6. **DNS for `hsg-whisky.dk`** (Michael styrer DNS). Sandsynligvis er SPF og DKIM allerede sat op, fordi info@ sender fra Microsoft 365 i dag:
      - **SPF** (TXT på `hsg-whisky.dk`): `v=spf1 include:spf.protection.outlook.com -all`. Sender andre tjenester (fx webshop eller webhotel) også som @hsg-whisky.dk, skal deres `include:` med i *samme* post. Der må kun være én SPF-post.
      - **DKIM**: Gå til *Microsoft Defender → E-mail og samarbejde → Politikker og regler → Trusselspolitikker → Indstillinger for mailgodkendelse → DKIM → hsg-whisky.dk*. Opret de to CNAME-poster `selector1._domainkey` og `selector2._domainkey` med præcis de mål, portalen viser (fx `selector1-hsg-whisky-dk._domainkey.<tenant>.onmicrosoft.com`; nyere domæner kan få et mål under `…dkim.mail.microsoft`). Slå signering til, når posterne er slået igennem.
      - **DMARC** (TXT på `_dmarc.hsg-whisky.dk`): start med `v=DMARC1; p=none; rua=mailto:<adresse til rapporter>`. Efter 2–4 uger uden fejl i rapporterne skiftes til `p=quarantine`.
3. *Nyhedsbrev → Indstillinger:* udfyld afsendernavn (forudfyldt "HSG Whisky ApS"), afsenderadresse (`info@hsg-whisky.dk`), svar-til, offentlig adresse, "Bund i mailen" og "Privatlivspolitik" med oplysningerne fra hsg-whisky.dk samt testadresse.
4. Forsendelse: vælg **Microsoft 365 (OAuth2)**, og udfyld tenant ID, client ID, client secret og udløbsdato → **Test forbindelse** → "Forbindelsen virker (smtp.office365.com:587, STARTTLS, OAuth2 som info@hsg-whisky.dk)".
5. **Tjek afsenderdomæne** → SPF, DKIM og DMARC skal stå som "OK".
6. Opret en cron på webhotellet hvert 5. minut (eller hvert minut, hvis webhotellet tillader det) med kommandoen eller URL'en, der vises på siden.
7. Giv evt. andre login-brugere adgang i *Brugere → Login-brugere* (afklaret spørgsmål 10).
8. **Driftstjek, før den første rigtige udsendelse:**
   - Send en test til en Gmail- og en Outlook.com-adresse.
   - Under "Vis original" skal der stå SPF = PASS, DKIM = PASS med `d=hsg-whisky.dk` og DMARC = PASS.
   - Headerne `List-Unsubscribe` og `List-Unsubscribe-Post` skal være med, og Gmail skal vise "Afmeld".
   - Se efter, om der ligger kopier i info@'s Sendt post.
   - Mangler `List-Unsubscribe-Post`, eller lægger Exchange kopier i Sendt post, meldes det til udvikleren, før systemet tages i brug.

### B. Månedlig import af kundelisten (Kim, trin 1)
1. *Nyhedsbrev → Import* → vælg Excel/CSV-fil.
2. Vælg "Hvem er i filen?" (Kunder / Forhandlere / Brug kolonne) → **Analysér fil**.
3. Forhåndsvisningen viser den valgte mailkolonne (fx "Kolonne D – E-mail") og tal for: nye, opdateres, uændrede, afmeldte/stoppede/slettede (springes over), ugyldige, dubletter, konflikter, typeskift og "N aktive modtagere står ikke i filen". Kim kan ændre kolonnevalget og genberegne.
4. **Importér 25 nye og opdatér 3** → besked om resultatet, og importen audit-logges.

### C. Månedlig udsendelse (Kim, trin 2 og 3)
1. *Nyhedsbrev → Udsendelser* → **Ny udsendelse**, eller **Kopiér til ny kladde** på sidste måneds udsendelse.
2. Emnet er forudfyldt med "Nyhedsbrev fra HSG Whisky ApS (Oktober 2026)" (aktuel måned på dansk). Skriv teksten, vælg målgruppe (Alle / Forhandlere / Kunder) og katalog (Hele kataloget / Kun nyheder / Intet) → **Gem kladde**.
3. (Trin 3) **Frys katalog nu** → "Frosset 19.10.2026 kl. 10.42 · Engros 8,4 MB · Vejl. 8,3 MB".
4. Se forhåndsvisningen for *Forhandler* og *Kunde*.
5. **Send test** → åbn testmailen i Outlook eller på mobilen, og klik på katalog- og afmeldingslinket.
6. Sæt flueben ved "Jeg har modtaget og kontrolleret testmailen" → **Start udsendelse til 312 modtagere** → bekræft.
7. Siden viser fremdriften (ca. 16 minutter for 312 modtagere ved 20 pr. minut). Kim kan lade siden stå åben eller lukke den, hvis cron er sat op ("Sidste cron-kørsel kl. 10.55").
8. Når udsendelsen er færdig, vises en opsummering med en liste over fejlede og ukendte modtagere.

### D. Modtager afmelder sig
1. Modtageren klikker på "Afmeld nyhedsbrevet" i mailen eller på mailprogrammets "Afmeld"-knap (ét klik).
2. Fra mailen: siden viser en bekræftelse → **Ja, afmeld mig** → "Du er nu afmeldt …". Med ét klik sker det uden bekræftelse.
3. Afmeldingen gælder med det samme, også for en udsendelse, der er i gang.

### E. GDPR-henvendelse (administrator)
1. Find modtageren med søgefeltet (filtrerer i browseren).
2. **Eksportér data (CSV)** sender kopien til den registrerede. **Slet modtager** anonymiserer, og personen kommer ikke med igen ved import.

### F. Afbrudt udsendelse
1. Browseren lukkes, eller PHP dør → køen står uændret. Cron eller det næste besøg på siden fortsætter med rækkerne `queued`.
2. Var en mail undervejs, sættes den til *Ukendt*. Siden viser "1 modtager har ukendt status (afbrudt under afsendelse). Sendes ikke igen automatisk." og knappen **Send igen til disse 1** med en advarsel.

### G. Client secret udløber (Michael)
1. 30 dage før `newsletter_m365_secret_expires` viser alle Nyhedsbrev-sider en advarsel: "Client secret til Microsoft 365 udløber 19.10.2028 – opret en ny i Entra, og indsæt den under Indstillinger."
2. Michael opretter en ny secret på appen, indsætter den og den nye udløbsdato → **Test forbindelse**.

---

## Skærme

Alle sider bruger `page_header()` og `page_footer()` og de eksisterende komponenter: `.card`, `.grid` med `.card.metric`, `.split`, `.three`, `.simple-tabs`, `.table-wrap`, `.badge` (`green` / `amber` / `red` / `blue`), `.actions`, `.check`, `.muted`, `.flash` (inkl. `.flash.warning` til advarslen om secret-udløb), og til forhåndsvisningen `.catalog-pdf-wrapper` og `.catalog-pdf-iframe`. De offentlige sider bruger samme opbygning som skærmen for manglende login i `auth.php` (`<main class="public"><div class="card narrow">`). Den eneste nye CSS er `.newsletter-progress{width:100%;height:14px}` til det indbyggede `<progress>`-element. På skærme under 900 px falder `.split` og `.three` automatisk sammen til én kolonne, og tabeller scroller vandret inde i `.table-wrap`.

**Menu:**
- I `page_header()` (`functions.php`) tilføjes under sektionen **SALG**: `'newsletter' => ['newsletter.php','✉','Nyhedsbrev']`. Menupunktet vises kun, når modulet er aktivt og brugeren har modulet (den eksisterende kontrol `hsg_admin_can_view_module`).
- På `admin.php` ("Mere" på mobil) tilføjes et kort i gruppen "Daglig drift": **Nyhedsbrev** – "Modtagere, import og månedlige udsendelser." Kortet vises kun, hvis modulet er aktivt og `can('newsletter.send')`.

**Faner øverst på alle administrationssider** (`.simple-tabs`, den aktive fane har klassen `active`):
`Udsendelser` (trin 2) · `Modtagere` · `Import` · `Indstillinger` (trin 2).
I trin 1 viderestiller `newsletter.php` til `newsletter_recipients.php`.

### S1. Modtagere – `newsletter_recipients.php` (trin 1)

```
Nyhedsbrev
[Udsendelser] [Modtagere] [Import] [Indstillinger]

+----------+ +-------------+ +----------+ +-----------+
| 312      | | 48          | | 264      | | 7         |
| Aktive   | | Forhandlere | | Kunder   | | Afmeldte  |
+----------+ +-------------+ +----------+ +-----------+

Vis: [Aktive] [Forhandlere] [Kunder] [Afmeldte] [Stoppede]      (?filter=…)
Søg i listen [______________]  (filtrerer i browseren)  [+ Tilføj modtager]

+--------------------------------------------------------------------+
| Mail            | Navn / firma        | Type       | Status | ...  |
| kim@firma.dk    | Kim Hansen          | Forhandler | Aktiv  |Rediger|
|                 | Firma ApS           |            |        |      |
+--------------------------------------------------------------------+
```

- Typen vises som badge: Forhandler = `blue`, Kunde = standard. Status: Aktiv = `green`, Afmeldt = `amber`, Stoppet = `red`. Slettede vises ikke i listen.
- **Tilføj modtager** (foldes ud med `<details>`):
  - Felter: Mail*, Navn, Firma, Type (Kunde/Forhandler).
  - Knappen hedder **Tilføj**.
  - Var adressen tidligere afmeldt eller slettet, vises fejlen "Adressen er tidligere afmeldt/slettet. Sæt flueben, hvis modtageren selv har bedt om at blive tilmeldt igen." sammen med fluebenet.
- **Rediger** (`?id=N`):
  - Felter: Mail, Navn, Firma og Type, plus (kun læsning) Kilde, Oprettet, Sidst set i import, Afmeldt (tidspunkt og hvordan).
  - Feltet "Personligt afmeldingslink" er kun til læsning med `onclick="this.select()"`. Det er beregnet til manuel hjælp og test. I trin 1 bygges linket med `base_url()` (den aktuelle browserforespørgsel), fra trin 2 med `newsletter_public_base_url`, når den er udfyldt.
  - Knapper: **Gem** · **Afmeld** (hvis aktiv) · **Stop** (fx afviste mails) / **Genaktivér** (genaktivering fra Afmeldt kræver fluebenet "selv bedt om") · **Eksportér data (CSV)** · **Slet modtager** (`button.danger` + `confirm('Slet modtageren permanent? Mailadressen gemmes kun som en uigenkendelig spærring, så den ikke importeres igen.')`).
  - Fra trin 2 vises også tabellen *Udsendelseshistorik* (Udsendelse, Emne, Status, Sendt).
- Søgefeltet er et `<input type="search">` uden for enhver formular, og JavaScript skjuler de rækker, der ikke matcher.

### S2. Import – `newsletter_import.php` (trin 1)

```
Import af modtagere
+------------------------------------------------------------------+
| Excel/CSV-fil  [Vælg fil]   (.xlsx eller .csv, højst 5.000 rækker)|
| Hvem er i filen?   (o) Kunder  ( ) Forhandlere  ( ) Brug kolonne   |
| [Analysér fil]                                                    |
+------------------------------------------------------------------+

Forhåndsvisning: kundeliste-oktober.xlsx (Ark 1, overskrift i række 1)
Mailkolonne [D – "E-mail" v]  Navn [B – "Navn" v]  Firma [– ingen – v]
Type-kolonne [– v] (kun ved "Brug kolonne")  [Genberegn]

+------+ +----------+ +----------+ +-----------------+ +----------+
| 25   | | 3        | | 280      | | 7               | | 4        |
| Nye  | | Opdateres| | Uændrede | | Afmeldt/stoppet | | Ugyldige |
+------+ +----------+ +----------+ | – springes over | +----------+
                                   +-----------------+
Dubletter i filen: 6 · Konflikter (importeres som Kunde): 1
Skifter type: 2 · Aktive modtagere, der ikke står i filen: 5

> Ugyldige (4)            række 17: "kim(at)firma.dk" ...
> Springes over (7)       række 40: a***@x.dk – Afmeldt ...
> Skifter type (2)        række 12: Kunde -> Forhandler (får engrospriser)
> Konflikter (1)          ...

[Importér 25 nye og opdatér 3]   [Annullér]
```

- Forhåndsvisningen gemmes i `$_SESSION` under et tilfældigt nøgle-token, der sendes som skjult felt. Den gemmes ikke i `storage/`, fordi Kims fil kan indeholde andre persondata. Når importen er gennemført eller annulleret, slettes den.
- Hvis xlsx-filen har mere end ét ark, vises valget "Ark [v]".
- Listerne vises i `<details>` med højst 200 linjer og teksten "… og N flere".
- Afmeldte adresser vises maskeret (`a***@x.dk`).
- Fejltekster (vises med `flash('error', …)`):
  - "Vælg en Excel-fil (.xlsx) eller CSV-fil."
  - "Filen indeholder ingen gyldige mailadresser."
  - "Filen har mere end 5.000 rækker."
  - "Forhåndsvisningen er udløbet – upload filen igen."

### S3. Indstillinger – `newsletter_settings.php` (trin 2)

```
Afsender
  Afsendernavn      [HSG Whisky ApS        ]
  Afsenderadresse*  [info@hsg-whisky.dk    ]  (postkassen i Microsoft 365)
  Svar-til          [                      ]  (tom = afsenderadresse)
  Offentlig adresse*[https://lager.hsg-whisky.dk]  (bruges i links i mails)
  Bund i mailen     [adresse og CVR som på hsg-whisky.dk]  (textarea)
  Privatlivspolitik [https://hsg-whisky.dk/...]
  Testadresse       [michael@...           ]
Forsendelse
  Metode  (o) Microsoft 365 (OAuth2) – smtp.office365.com:587, STARTTLS
          ( ) Lokal test-SMTP uden login (kun 127.0.0.1 / localhost)
  Microsoft 365
    Tenant ID*      [........-....-....]   Client ID* [........-....-....]
    Client secret*  [••••] (Lad feltet være tomt for at beholde den eksisterende client secret)
    Secret udløber  [2028-10-19] (valgfri – advarsel 30 dage før)
  Lokal test-SMTP
    Vært [127.0.0.1]  Port [2525]
Hastighed og opbevaring
  Mails pr. kald [10]  Maks. pr. minut [20]  pr. time [600]  pr. døgn [2000]
  Gem udsendelseslog i [24] måneder   Kataloglink gyldigt i [90] dage (trin 3)
[Gem indstillinger]  [Test forbindelse]  [Tjek afsenderdomæne]

Afsenderdomæne hsg-whisky.dk                (vises efter "Tjek afsenderdomæne")
  SPF    OK      v=spf1 include:spf.protection.outlook.com -all
  DKIM   OK      selector1 og selector2 peger på Microsoft
  DMARC  OK      v=DMARC1; p=none; rua=...

Webhotel-cron
  Anbefalet CLI-kommando [php /sti/cron/newsletter.php]   (hvert 5. minut)
  Beskyttet cron-URL     [https://…/cron/newsletter.php?key=…]
  Sidste cron-kørsel: 19.10.2026 10.55        [Generér ny cron-nøgle]
```

- **Gyldige værdier:**
  - mails pr. kald 1–30; pr. minut 1–30 (Exchange Onlines grænse er 30); pr. time 1–1.800; pr. døgn 1–9.000 (under Exchange Onlines 10.000)
  - log 6–60 måneder; kataloglink 7–365 dage
  - port for lokal test-SMTP 1–65535; udløbsdato tom eller en gyldig dato
  - værten for lokal test-SMTP skal være `127.0.0.1`, `localhost` eller `::1`

  Ugyldige værdier afvises med en dansk fejltekst, og intet gemmes.
- **"Test forbindelse" med Microsoft 365** henter et token, forbinder, starter TLS, logger ind med XOAUTH2 og afslutter med QUIT, uden at sende en mail. Resultatet vises som flash:
  - OK: "Forbindelsen virker (smtp.office365.com:587, STARTTLS, OAuth2 som info@hsg-whisky.dk)".
  - Token kunne ikke hentes: "Microsoft-login fejlede: <Microsofts fejlkode og tekst, fx AADSTS7000215>" (aldrig secret eller token).
  - `535 5.7.3`: "Exchange afviste login (535 5.7.3). Kontrollér service principal og FullAccess til postkassen – se vejledningen."
  - `535 5.7.139`: "SMTP AUTH er slået fra for postkassen (535 5.7.139). Slå 'Authenticated SMTP' til for postkassen."
  - Forbindelsen fejler: "Kunne ikke forbinde til smtp.office365.com:587. Webhotellet blokerer måske udgående port 587."
- **"Test forbindelse" med lokal test-SMTP:** forbinder, EHLO, QUIT.
- **"Tjek afsenderdomæne"** bruger `dns_get_record()` på domænet i afsenderadressen og er kun til læsning:
  - **SPF:** OK, hvis der er præcis én `v=spf1`-post, og den indeholder `include:spf.protection.outlook.com`. Ellers "Mangler" eller "Flere SPF-poster".
  - **DKIM:** OK, hvis `selector1._domainkey` og `selector2._domainkey` er CNAME-poster, der ender på `.onmicrosoft.com` eller indeholder `dkim.mail.microsoft`.
  - **DMARC:** OK, hvis `_dmarc.<domæne>` har en TXT-post, der starter med `v=DMARC1`. Politikken (`p=`) vises.

  Resultatet vises med badges (`green` OK, `amber` mangler) og de fundne poster. Der kommer ingen PHP-fejl, hvis DNS-opslaget fejler.
- Kun cron-URL'en og CLI-kommandoen vises i klartekst (som i `backup.php`). Client secret vises aldrig.
- Mangler PHP cURL, vises "PHP cURL mangler – Microsoft 365 kan ikke bruges".

### S4. Udsendelser – `newsletter.php` (trin 2)

```
[+ Ny udsendelse]
+--------------------------------------------------------------------------+
| Oprettet   | Emne                                   | Status   | Sendt   |
| 19.10.2026 | Nyhedsbrev fra HSG Whisky ApS (Okt...) | Sender   | 140/312 |
| 18.09.2026 | Nyhedsbrev fra HSG Whisky ApS (Sep...) | Færdig   | 305/307 |
+--------------------------------------------------------------------------+
```

- Kolonner: Oprettet, Emne, Status (badge), Modtagere, Sendt, Fejlet/Ukendt, Startet af, link **Åbn**.
- Statusbadges: Kladde, Sender (`blue`), På pause (`amber`), Færdig (`green`), Annulleret.

### S5. Udsendelse – `newsletter_campaign.php?id=N` (trin 2, katalogdelen i trin 3)

Desktop, mens udsendelsen er kladde:
```
Udsendelse #12                                              [Kladde]
+-- 1. Indhold ---------------------------------------------------+
| Emne*  [Nyhedsbrev fra HSG Whisky ApS (Oktober 2026)        ]   |
| Tekst* [textarea, ca. 12 linjer                              ]  |
| Modtagere (o) Alle (312)  ( ) Forhandlere (48)  ( ) Kunder (264)|
| Katalog   (o) Hele kataloget ( ) Kun nyheder ( ) Intet katalog  |  trin 3
|   Frosset 19.10.2026 kl. 10.42 · Engros 8,4 MB · Vejl. 8,3 MB    |
|   [Frys katalog nu] / [Frys igen]                                |
| [Gem kladde]  [Slet kladde]                                      |
+-----------------------------------------------------------------+
+-- 2. Forhåndsvisning -------------------------------------------+
| [Forhandler] [Kunde]                                             |
| +-------------------------------------------------------------+ |
| | <iframe sandbox src="newsletter_preview.php?id=12&v=dealer"> | |
| +-------------------------------------------------------------+ |
| > Tekstversion                                                  |
+-----------------------------------------------------------------+
+-- 3. Test ------------------------------------------------------+
| Testadresse(r) [michael@...                ]  [Send test]       |
| Testet 19.10.2026 kl. 10.50 – denne version  (badge green)      |
|  ELLER: Ændret siden testen – send en ny test (badge amber)     |
+-----------------------------------------------------------------+
+-- 4. Send ------------------------------------------------------+
| 312 modtagere (48 forhandlere, 264 kunder)                      |
| [ ] Jeg har modtaget og kontrolleret testmailen                 |
| [Start udsendelse til 312 modtagere]   (deaktiveret før test)   |
| Mangler: Afsenderadresse, Microsoft 365-oplysninger             |
|          (vises kun, hvis indstillinger mangler)                 |
+-----------------------------------------------------------------+
```

Mobil, mens udsendelsen kører (sektionerne står under hinanden, og knapperne fylder hele bredden):
```
Udsendelse #12          [Sender]
<progress 140 / 312>
Sendt 140 · I kø 170 · Fejlet 2
Ukendt 0 · Sprunget over 0
Afsendelsen kører. Hold siden åben
– eller lad cron fortsætte
(sidste cron-kørsel kl. 10.55).
[ Pause                ]
[ Annullér resten      ]
```

- **Tilstande og knapper:**
  - *Kladde:* som skitsen.
  - *Sender:* **Pause**, **Annullér resten**.
  - *På pause:* **Genoptag**, **Annullér resten**, plus årsagen (fx "Automatisk pause: Exchange afviste login (535 5.7.3). Ret indstillingerne og tryk Genoptag." eller "Automatisk pause: Microsoft 365's sendegrænse er nået. Tryk Genoptag senere.").
  - *Færdig/Annulleret:* opsummering, tabellen *Fejlede* (Mail, Fejl), antal *Ukendte* med **Send igen til disse N** (`confirm` med advarsel om mulig dobbelt-mail), og **Kopiér til ny kladde** i alle tilstande.
- Når udsendelsen er startet, er emne, tekst, målgruppe og katalog låst og vises kun til læsning.
- Kun én udsendelse kan være *Sender* eller *På pause* ad gangen. Start af en anden afvises med "En anden udsendelse er i gang (#11)."
- **Fremdrift via JavaScript:**
  - Mens status er *Sender*, kaldes `newsletter_send.php` (POST med `csrf`, `campaign_id`).
  - Svaret er JSON: `{ok, status, counts:{total,queued,sending,sent,failed,unknown,skipped}, sent_now, busy, wait_until, message}`.
  - Næste kald sker efter 0,5 s. Ved `busy` ventes 5 s.
  - Ved `wait_until` vises fx "Sendegrænse nået (20 pr. minut). Fortsætter kl. 11.42.", og næste kald sker på det tidspunkt (højst 60 s ad gangen).
  - Kaldene stopper ved `paused`, `done` eller `cancelled`, og siden genindlæses.
- Uden JavaScript sker afsendelsen kun via cron. Siden viser tallene ved genindlæsning.

### S6. Forhåndsvisning – `newsletter_preview.php?id=N&v=dealer|customer` (trin 2)

Siden returnerer kun mailens HTML (ingen `page_header`). Links til afmelding og katalog vises som `#` med `title="Indsættes ved afsendelse"`. Siden har headeren `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'`, og iframen har attributten `sandbox`.

### S7. Offentlig afmeldingsside – `nyhedsbrev-afmeld.php?t=<token>` (trin 1)

```
+---------------------------------------------+
| HSG Whisky ApS                              |
| Afmeld nyhedsbrevet                         |
|                                             |
| Vil du afmelde k***@firma.dk fra            |
| nyhedsbrevet fra HSG Whisky ApS?            |
|                                             |
| [ Ja, afmeld mig ]                          |
+---------------------------------------------+
```

- **Efter POST:** "Du er nu afmeldt. Du modtager ikke flere nyhedsbreve fra HSG Whisky ApS. Afmeldingen gælder med det samme." Fortryder modtageren, står der: "Har du afmeldt ved en fejl, så skriv til <svar-til/afsenderadresse>." Mailadressen vises kun, når den er sat i indstillingerne.
- **Allerede afmeldt:** "Du er allerede afmeldt nyhedsbrevet."
- **Testtoken (trin 2):** "Dette er et testlink fra en testudsendelse. Ingen er blevet afmeldt."
- **Ugyldigt token (404):** "Linket er ugyldigt eller udløbet. Kontakt HSG Whisky ApS, hvis du vil afmeldes."
- **Mobil:** `.narrow` og `.public` fungerer allerede på små skærme, og knappen fylder hele bredden.

### S8. Offentligt kataloglink – `nyhedsbrev-katalog.php?t=<token>&c=<id>` (trin 3)

- Ved gyldigt token returneres PDF'en (`application/pdf`, `inline`, filnavn fx `HSG-Whisky-Katalog-engrospriser-2026-10-19.pdf`).
- Fejlsider bruger samme opbygning som S7:
  - 404 "Linket er ugyldigt."
  - 410 "Linket er udløbet. Kontakt HSG Whisky ApS for at få det nyeste katalog."
  - 404 "Kataloget er ikke længere tilgængeligt." (filen mangler)

---

## Mailens indhold og format (trin 2, katalogafsnittet i trin 3)

- **Headere:**
  - `Date`, `From: "<afsendernavn>" <afsenderadresse>` (navnet kodet efter RFC 2047, UTF-8), `To: <modtager>`, `Reply-To` (hvis udfyldt), `Subject` (RFC 2047), `Message-ID: <32hex@afsenderdomæne>`, `MIME-Version: 1.0`, `List-Unsubscribe`, `List-Unsubscribe-Post`.
  - `From` og `MAIL FROM` er altid afsenderadressen, dvs. den postkasse, appen har rettighed til.
  - Linjeskift og CR i alle headerværdier fjernes.
  - Der bruges ingen `Cc` eller `Bcc` og ingen sporing.
- **Krop:** `multipart/alternative` med en `text/plain`- og en `text/html`-del, begge UTF-8 og quoted-printable. Linjer i SMTP er højst 998 tegn, linjeskift er CRLF, og der bruges dot-stuffing.
- **HTML:**
  - Simpel, med inline-styles, maks. 600 px bred, Arial og ingen eksterne ressourcer eller billeder.
  - Brødteksten er Kims ren-tekst. Den escapes med `h()`, deles i afsnit ved tomme linjer, linjeskift bliver til `<br>`, og `https://…` gøres til links.
  - Der er ingen WYSIWYG-editor eller HTML-input.
- **Tekstversion (HTML-versionen har samme indhold):**

```
{brødtekst}

------------------------------------------------------------
MÅNEDENS KATALOG (PDF)                         (kun trin 3 og hvis valgt)
Hent kataloget her: {kataloglink}
Kataloget viser {engrospriser ekskl. moms | vejledende udsalgspriser inkl. moms}.
Linket virker til og med {dd.mm.åååå}.
------------------------------------------------------------
Du modtager denne mail, fordi du er registreret som {forhandler | kunde} hos {afsendernavn}.
Afmeld nyhedsbrevet: {afmeldingslink}
Du kan også svare på denne mail og skrive "afmeld".
{bund-tekst}
{Privatlivspolitik: url}   (kun hvis udfyldt)
```

- **Begrænsninger:** Emnet er 1–200 tegn. Teksten er 1–20.000 tegn.
- **Standardemne:** "Nyhedsbrev fra HSG Whisky ApS (<Måned> <år>)" med dansk månedsnavn med stort begyndelsesbogstav (Januar … December).
- **Tidszone:** Tidspunkter vises i `setting_get($pdo,'timezone','Europe/Copenhagen')`, som `backup.php` gør. Sammenligninger af tid i køen sker med databasens `NOW()`.

---

## Data

Alle tabeller bruger `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, ligesom de eksisterende `hsg_`-tabeller. Migrationerne skal kunne køres flere gange uden fejl (`db_table_exists`/`db_column_exists` før `CREATE`/`ALTER`). De ligger i `modules/newsletter/migrations.php`, og `version` i `module.php` hæves for hvert trin. Alle `hsg_`-tabeller kommer automatisk med i DATA- og FULL-backup.

### Migration `1.0.0` (trin 1)

**Ny tabel `hsg_newsletter_recipients`**

| Kolonne | Type | Bemærkning |
|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT PK | |
| `email` | VARCHAR(190) NULL | Normaliseret (trimmet, små bogstaver). NULL når slettet. |
| `email_hash` | CHAR(64) NOT NULL, **UNIQUE** | SHA-256 af den normaliserede mail. Nøgle til dubletter og spærring, og bevares ved sletning. |
| `name` | VARCHAR(180) NULL | |
| `company` | VARCHAR(180) NULL | |
| `segment` | ENUM('dealer','customer') NOT NULL DEFAULT 'customer' | Forhandler / Kunde |
| `status` | ENUM('active','unsubscribed','stopped','deleted') NOT NULL DEFAULT 'active' | Aktiv / Afmeldt / Stoppet / Slettet |
| `source` | ENUM('import','manual') NOT NULL | |
| `token_hash` | CHAR(64) NULL, **UNIQUE** | SHA-256 af afmeldingstokenet. NULL når slettet. |
| `token_cipher` | TEXT NULL | `hsg_access_link_encrypt(token)`. NULL når slettet. |
| `unsubscribed_at` | DATETIME NULL | |
| `unsubscribe_via` | ENUM('link','one_click','admin') NULL | |
| `last_import_at` | DATETIME NULL | Sidst fundet i en import |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | |

Indeks: `idx_nl_rec_status_segment (status, segment)`.

**Adgang:** Hvis `hsg_admin_module_access` findes: `INSERT IGNORE` af `(id, 'newsletter', 0)` for alle `lager_admins` med `is_superadmin=0`. Den egentlige sikring mod adgang som standard er `admin_default_access => false` i manifestet (se beslutning 6), ikke disse rækker.

**Indstilling:** `newsletter_import_columns` (JSON, oprettes ved første import).

### Migration `1.1.0` (trin 2)

**Ny tabel `hsg_newsletter_campaigns`**

| Kolonne | Type | Bemærkning |
|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT PK | |
| `subject` | VARCHAR(200) NOT NULL | |
| `body_text` | MEDIUMTEXT NOT NULL | Ren tekst |
| `audience` | ENUM('all','dealer','customer') NOT NULL DEFAULT 'all' | |
| `status` | ENUM('draft','sending','paused','done','cancelled') NOT NULL DEFAULT 'draft' | Kladde / Sender / På pause / Færdig / Annulleret |
| `tested_hash` | CHAR(64) NULL | Indholdshashen ved den seneste test |
| `tested_at` | DATETIME NULL | |
| `tested_by_admin` | INT UNSIGNED NULL | |
| `preview_token_hash` | CHAR(64) NULL | SHA-256 af test-tokenet (nyt ved hver test) |
| `pause_reason` | VARCHAR(255) NULL | |
| `recipients_total` | INT UNSIGNED NOT NULL DEFAULT 0 | Sættes ved start |
| `created_by_admin` | INT UNSIGNED NULL | |
| `started_by_admin` | INT UNSIGNED NULL | |
| `started_at` | DATETIME NULL | |
| `completed_at` | DATETIME NULL | |
| `created_at` / `updated_at` | DATETIME | Som ovenfor |

Indeks: `idx_nl_camp_status (status)`.

**Ny tabel `hsg_newsletter_deliveries`** (kø og udsendelseslog)

| Kolonne | Type | Bemærkning |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `campaign_id` | INT UNSIGNED NOT NULL | |
| `recipient_id` | INT UNSIGNED NOT NULL | Ingen kopi af mailadressen |
| `segment` | ENUM('dealer','customer') NOT NULL | Fastfrosset ved start. Bestemmer katalogvariant og tekst. |
| `status` | ENUM('queued','sending','sent','failed','unknown','skipped') NOT NULL DEFAULT 'queued' | I kø / Sendes / Sendt / Fejlet / Ukendt (afbrudt) / Sprunget over |
| `attempts` | TINYINT UNSIGNED NOT NULL DEFAULT 0 | |
| `next_attempt_at` | DATETIME NULL | Ved midlertidig fejl |
| `claimed_at` | DATETIME NULL | |
| `sent_at` | DATETIME NULL | Bruges også til hastighedsgrænserne pr. minut, time og døgn |
| `message_id` | VARCHAR(190) NULL | |
| `last_error` | VARCHAR(500) NULL | SMTP-kode og tekst, aldrig secret eller token |

Indeks: **UNIQUE `uniq_nl_deliv (campaign_id, recipient_id)`**, `idx_nl_deliv_status (campaign_id, status)`, `idx_nl_deliv_sent (status, sent_at)`, `idx_nl_deliv_recipient (recipient_id)`.

**Standardværdier i `hsg_settings`** (`INSERT IGNORE`, som `modules/system/migrations.php`):

| Nøgle | Standard | Bemærkning |
|---|---|---|
| `newsletter_from_name` | værdien af `company_name` eller "HSG Whisky ApS" | |
| `newsletter_from_email` | '' | Obligatorisk før test/start. Postkassen i Microsoft 365, som appen har FullAccess til (antaget `info@hsg-whisky.dk`). |
| `newsletter_reply_to` | '' | Tom = afsenderadresse |
| `newsletter_public_base_url` | '' | Obligatorisk; https |
| `newsletter_footer_text` | '' | Udfyldes af Michael med adresse og CVR som på hsg-whisky.dk |
| `newsletter_privacy_url` | '' | Udfyldes af Michael med privatlivspolitikken på hsg-whisky.dk |
| `newsletter_test_email` | '' | Senest brugte testadresse(r) |
| `newsletter_transport` | 'm365' | 'm365' (Microsoft 365, OAuth2) / 'local' (lokal test-SMTP uden login) |
| `newsletter_m365_tenant_id` | '' | Directory (tenant) ID |
| `newsletter_m365_client_id` | '' | Application (client) ID for nyhedsbrevets egen Entra-app |
| `newsletter_m365_client_secret` | '' | **Hemmelig** – føjes til `hsg_backup_sensitive_setting_keys()` |
| `newsletter_m365_secret_expires` | '' | Dato (ÅÅÅÅ-MM-DD), valgfri; advarsel 30 dage før |
| `newsletter_local_smtp_host` | '127.0.0.1' | Kun for 'local'; skal være `127.0.0.1`, `localhost` eller `::1` |
| `newsletter_local_smtp_port` | '2525' | Kun for 'local' |
| `newsletter_batch_size` | '10' | Mails pr. kald |
| `newsletter_max_per_minute` | '20' | Højst 30 (Exchange Onlines grænse) |
| `newsletter_max_per_hour` | '600' | |
| `newsletter_max_per_day` | '2000' | Under Exchange Onlines 10.000 modtagere pr. døgn |
| `newsletter_log_retention_months` | '24' | Afklaret spørgsmål 7 |
| `newsletter_cron_key` | `bin2hex(random_bytes(24))` | **Hemmelig** – føjes til `hsg_backup_sensitive_setting_keys()` |
| `newsletter_cron_last_run` | '' | Tidspunkt i ISO-format |

`onedrive_*`-indstillingerne ændres ikke af modulet.

### Migration `1.2.0` (trin 3)

**Ændret tabel `hsg_newsletter_campaigns`:** ny kolonne `catalog_type ENUM('none','full','news') NOT NULL DEFAULT 'none'` (kun hvis kolonnen ikke findes).

**Ny tabel `hsg_newsletter_catalogs`**

| Kolonne | Type | Bemærkning |
|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT PK | |
| `campaign_id` | INT UNSIGNED NOT NULL | |
| `price` | ENUM('retail','wholesale') NOT NULL | Vejl. udsalgspris / Engros |
| `news_only` | TINYINT(1) NOT NULL DEFAULT 0 | |
| `file_name` | VARCHAR(100) NOT NULL | `<32 hex>.pdf` i `storage/tmp/newsletter/<campaign_id>/` |
| `file_size` | INT UNSIGNED NOT NULL | |
| `sha256` | CHAR(64) NOT NULL | Indgår i indholdshashen |
| `download_count` | INT UNSIGNED NOT NULL DEFAULT 0 | Kun samlet tal |
| `expires_at` | DATETIME NULL | Sættes ved start = `started_at` + `newsletter_catalog_link_days` |
| `deleted_at` | DATETIME NULL | Sættes, når filen er fjernet |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |

Indeks: **UNIQUE `uniq_nl_cat (campaign_id, price)`**.

**Indstilling:** `newsletter_catalog_link_days` = '90' (`INSERT IGNORE`).

### Statusovergange

- **Modtager:**
  - `active` → `unsubscribed` (via link, ét klik eller administrator), `active` → `stopped` (administrator), `stopped` → `active` (administrator).
  - `unsubscribed` → `active` kun med fluebenet "selv bedt om".
  - Alle statusser → `deleted`. En slettet adresse kan kun komme tilbage ved manuel tilføjelse med fluebenet "selv bedt om". Rækken genbruges, og der udstedes et nyt token.
  - En import ændrer aldrig `status`, undtagen når den opretter nye rækker som `active`.
- **Udsendelse:**
  - `draft` → `sending` (start) → `done`, når der ikke er flere `queued` eller `sending`.
  - `sending` ⇄ `paused` (manuelt eller automatisk ved afsenderfejl). `sending`/`paused` → `cancelled` (resten sættes til `skipped`).
  - `done` → `sending` igen kun via "Send igen til ukendte".
- **Udsendelsesrække:**
  - `queued` → `sending` (reserveret) → `sent` | `failed` | `queued` (midlertidig fejl, `attempts`<3, eller afsenderfejl) | `unknown`.
  - `queued` → `skipped` (modtageren er ikke længere aktiv, eller resten er annulleret).
  - `unknown` → `queued` kun via "Send igen til disse".

---

## Rettigheder

| Hvem | Adgang |
|---|---|
| Superadministrator | Alt (`*`) |
| Login-bruger (ikke superadmin) | Kun hvis modulet *Nyhedsbrev* er markeret i `users.php` (afklaret spørgsmål 10). Standard efter migrationen: **ingen adgang**. Med adgang gælder `newsletter.send` til alle sider og handlinger, inkl. indstillinger. |
| Direkte link (linkbruger) | **Ingen adgang** (403). `link_access => false`, ingen menupunkter, og modulet vises ikke i `user_permissions.php`. |
| Ikke logget ind | Administrationssider: 401 (som i dag via `auth.php`). Kun de offentlige sider med token: `nyhedsbrev-afmeld.php` (læs og afmeld) og `nyhedsbrev-katalog.php` (kun læsning af egen PDF). |
| Cron | `cron/newsletter.php`: CLI uden nøgle eller URL med `?key=` (`hash_equals` mod `newsletter_cron_key`). Forkert eller manglende nøgle giver 403. Når modulet er deaktiveret, gør scriptet intet og svarer "Modulet er deaktiveret." |
| Entra-appen | Kun `SMTP.SendAsApp` og kun til de postkasser, Michael har givet den FullAccess til i Exchange (info@). Ingen Graph-tilladelser. |

- **Kapabilitet:** `newsletter.send` (manifestets `capability`).
- **Moduldeaktivering** (System → Moduler) fungerer som nødstop. Administrationssider, AJAX-endpointet og kataloglinks svarer 404, og cron sender intet. **Afmeldingssiden virker fortsat.**
- **Offentlige sider:**
  - Accepterer kun `t` (64 hex-tegn, valideres med regex før databaseopslag) og `c` (heltal).
  - Bruger prepared statements og `h()` på al output, og viser aldrig fulde mailadresser (kun maskeret).
  - Afmeldingssiden kan kun ændre én modtagers `status`, `unsubscribed_at` og `unsubscribe_via`.

---

## Påvirkede filer

### Trin 1 (modul 1.0.0)
| Fil | Ny/ændret | Indhold |
|---|---|---|
| `modules/newsletter/module.php` | Ny | `id` 'newsletter', `name` 'Nyhedsbrev', `version` '1.0.0', `nav` true, `link_access` false, `admin_default_access` false, `href` 'newsletter.php', `icon` '✉', `capability` 'newsletter.send', `description` 'Månedligt nyhedsbrev til forhandlere og kunder med afmelding og katalog.', `core` false, `sort` 35 |
| `core/permissions.php` | Ændret | `hsg_admin_can_view_module()`: manglende række eller tabel giver manifestets `admin_default_access` (standard `true`). Ingen ændring for eksisterende moduler. |
| `modules/newsletter/migrations.php` | Ny | '1.0.0' |
| `core/newsletter.php` | Ny | Normalisering og validering af mail, maskering, token (via `core/access_links.php`), analyse og gennemførelse af import (bruger `core/supplier_import.php`), afmelding, anonymisering, CSV-eksport, faner, danske etiketter |
| `newsletter.php` | Ny | Trin 1: viderestiller til `newsletter_recipients.php` |
| `newsletter_recipients.php` | Ny | S1 (liste, tilføj, rediger, afmeld/stop/genaktivér, eksport, slet) |
| `newsletter_import.php` | Ny | S2 |
| `nyhedsbrev-afmeld.php` | Ny | S7 (offentlig) |
| `functions.php` | Ændret | Menupunkt under SALG i `page_header()` |
| `admin.php` | Ændret | Kort "Nyhedsbrev" i "Daglig drift" |
| `docs/NEWSLETTER.md` | Ny | Vejledning: import, afmelding, GDPR |
| `tests/run.sh` | Ændret | Nye sider i røgtesten, offentlig side med ugyldigt token |
| `core/supplier_import.php` | **Uændret** | Genbruges kun via `require_once` |

### Trin 2 (modul 1.1.0)
| Fil | Ny/ændret | Indhold |
|---|---|---|
| `modules/newsletter/module.php`, `migrations.php` | Ændret | Version '1.1.0', migration '1.1.0' |
| `core/newsletter_mailer.php` | Ny | SMTP-klient (forbind, STARTTLS, `AUTH XOAUTH2`, send, RSET, QUIT; fejlens fase og om den gælder afsender eller modtager), token til Microsoft 365 via `hsg_http_request()`, og de rene funktioner `hsg_newsletter_xoauth2_string()` og MIME-byggeren (testbare uden netværk) |
| `core/onedrive.php` | **Uændret** | `hsg_http_request()` genbruges via `require_once`. `hsg_onedrive_token()` og OneDrive-backuppen røres ikke. |
| `core/newsletter.php` | Ændret | Udsendelser, indholdshash, start/kø, batch (GET_LOCK, reservering, grænser pr. minut/time/døgn, pause ved afsenderfejl), oprydning, skabeloner til tekst og HTML, advarsel om secret-udløb, DNS-tjek |
| `newsletter.php` | Ændret | S4 |
| `newsletter_campaign.php` | Ny | S5 |
| `newsletter_send.php` | Ny | AJAX-batch (JSON) |
| `newsletter_preview.php` | Ny | S6 |
| `newsletter_settings.php` | Ny | S3 |
| `cron/newsletter.php` | Ny | Som `cron/backup.php`: oprydning og batches i højst 240 s (CLI) / 25 s (URL), opdaterer `newsletter_cron_last_run` |
| `newsletter_recipients.php` | Ændret | Udsendelseshistorik pr. modtager, historik i CSV-eksporten |
| `nyhedsbrev-afmeld.php` | Ændret | Testtoken |
| `core/backup.php` | Ændret | `newsletter_m365_client_secret` og `newsletter_cron_key` i `hsg_backup_sensitive_setting_keys()`, tekst i `hsg_restore_readme()` |
| `backup.php` | Ændret | Teksten om hemmeligheder, der ikke kommer med i backup |
| `assets/style.css` | Ændret | `.newsletter-progress` |
| `docs/NEWSLETTER.md` | Ændret | Vejledning til Michael: Entra-app og Exchange PowerShell trin for trin, SPF-, DKIM- og DMARC-poster for Microsoft 365, cron, driftstjek og fornyelse af client secret (indhold som Brugerflow A og G) |
| `tests/run.sh` | Ændret | Nye sider i røgtesten |

### Trin 3 (modul 1.2.0)
| Fil | Ny/ændret | Indhold |
|---|---|---|
| `modules/newsletter/module.php`, `migrations.php` | Ændret | Version '1.2.0', migration '1.2.0' |
| `core/catalog_pdf_builder.php` | Ny | Logikken fra `catalog_pdf.php` flyttet til en funktion (pris, kun nyheder) → `SimplePdf`, inkl. de hjælpefunktioner der i dag ligger i `catalog_pdf.php` |
| `catalog_pdf.php` | Ændret | Bruger den nye funktion. Samme adfærd, rettigheder, grænser og filnavne som før. |
| `pdf_simple.php` | Ændret | Ny metode der skriver PDF'en til en fil (samme indhold som `output()`) uden at sende headere og uden `exit` |
| `newsletter_campaign.php` | Ændret | Valg af katalog, Frys katalog / Frys igen |
| `nyhedsbrev-katalog.php` | Ny | S8 (offentlig) |
| `core/newsletter.php` | Ændret | Fastfrysning, udløb, kataloglink i skabeloner, oprydning af filer |
| `cron/newsletter.php` | Ændret | Oprydning af udløbne katalogfiler |
| `docs/NEWSLETTER.md` | Ændret | Katalogafsnit |

**Ved hver release** (CLAUDE.md): `app_version.php`, README og manifest opdateres konsekvent, `hsg-package.json` laves igen med `php tools/build-release.php <version>`, og `bash tests/run.sh` skal være grøn.

**Rør ikke:** `config.php`, `config.local.php`, `.env`, `uploads/`, `storage/backups/`, `storage/data/`, `core/updater.php`, `tools/build-release.php`, `.gitignore`, `core/onedrive.php` og `CLAUDE.md`. Updater, release-byggeren og `.gitignore` behøver ingen ændringer, fordi `storage/tmp/` allerede er beskyttet.

---

## Acceptkriterier

Testeren bruger forsendelsesmetoden **Lokal test-SMTP** og en lokal SMTP-opsamler, fx et lille PHP-script i `tests/`, der lytter på `127.0.0.1:2525` med `stream_socket_server` og gemmer hver modtaget besked som en fil. Opsamleren skal kunne svare med en bestemt kode på et bestemt trin (fx `MAIL FROM`, `RCPT TO` eller efter DATA). Hvor der står "mail", menes en besked modtaget af opsamleren.

Kriterier mærket **(M365)** kræver internetadgang. Kan testeren ikke nå Microsoft, dækkes de af Michaels driftstjek (Brugerflow A, trin 8).

### Trin 1 – modtagere, import og afmelding

1. **Givet** en eksisterende installation uden modulet, **når** en vilkårlig side indlæses efter opgraderingen, **så** findes `hsg_newsletter_recipients`, `hsg_module_versions` har `newsletter = 1.0.0`, og endnu en sidevisning giver ingen fejl.
2. **Givet** en login-bruger med `is_superadmin=0` fra før opgraderingen, **når** migrationen er kørt, **så** findes `hsg_admin_module_access(admin_id,'newsletter',can_view=0)`, brugeren får 403 på `newsletter_recipients.php`, og menupunktet "Nyhedsbrev" vises ikke. En superadministrator ser menupunktet og siden. **Og givet** at modulet slås fra under System, at en superadministrator gemmer samme login-bruger i `users.php` (så brugerens `newsletter`-række forsvinder), og at modulet slås til igen, **så** får brugeren stadig 403. En login-bruger, der oprettes, mens modulet er slået fra, har heller ikke adgang, når det slås til. Login-brugere uden rækker for de *eksisterende* moduler har fortsat adgang til dem som i dag.
3. **Givet** en session fra et direkte link (`?k=…`), **når** `newsletter.php`, `newsletter_recipients.php` eller `newsletter_import.php` åbnes, **så** svarer de 403, menuen indeholder ikke "Nyhedsbrev", og `user_permissions.php` viser ikke modulet.
4. **Givet** en bruger uden login, **når** en af administrationssiderne åbnes, **så** svarer den 401.
5. **Givet** en indlogget administrator, **når** der sendes POST til `newsletter_recipients.php` eller `newsletter_import.php` uden `csrf`, **så** svarer siden 419, og intet ændres.
6. **Givet** en xlsx-fil uden overskrifter med mailadresser i kolonne D, **når** den analyseres, **så** vælges "Kolonne D" som mailkolonne, og tallene for nye og ugyldige passer med filen.
7. **Givet** en fil med `" Kim@Firma.DK "` og `kim@firma.dk`, **når** den importeres, **så** oprettes præcis én modtager med `email = kim@firma.dk`, og forhåndsvisningen viser "Dubletter i filen: 1".
8. **Givet** en celle med `a@x.dk; b@y.dk`, **når** filen importeres, **så** oprettes to modtagere.
9. **Givet** cellerne `kim(at)firma.dk`, `kim@` og `kim@firma`, **når** filen analyseres, **så** står alle tre under "Ugyldige" med rækkenummer, og ingen af dem importeres. Tomme celler tælles ikke som ugyldige.
10. **Givet** samme fil importeret to gange, **når** den anden import er analyseret, **så** viser den 0 nye, og antallet af rækker i tabellen er uændret.
11. **Givet** en afmeldt modtager, **når** en fil med samme adresse (anden skrivemåde med store bogstaver) importeres, **så** står adressen under "Springes over" maskeret, og status er fortsat `unsubscribed`. Det samme gælder `stopped` og `deleted` (slettede genkendes på `email_hash`).
12. **Givet** valget "Alle i filen er forhandlere" og en eksisterende kunde i filen, **når** filen analyseres, **så** vises "Skifter type: Kunde -> Forhandler". Efter importen har modtageren `segment = dealer`.
13. **Givet** valget "Brug kolonne" og samme adresse to gange med værdierne "Forhandler" og "Kunde", **når** filen importeres, **så** bliver modtageren `customer`, og forhåndsvisningen viser én konflikt.
14. **Givet** importformularen og formularen "Tilføj modtager", **når** HTML'en hentes, **så** indeholder de ingen felter eller flueben om retsgrundlag, og tabellen `hsg_newsletter_recipients` har ingen kolonne `basis`. Nye modtagere fra en gennemført import har `source = import` og `last_import_at` sat, og manuelt tilføjede har `source = manual`.
15. **Givet** at mailkolonnen er ændret manuelt til en kolonne med overskriften "Mail", **når** en ny fil med samme overskrifter importeres, **så** er "Mail" valgt på forhånd.
16. **Givet** en gennemført import, **når** `hsg_audit_log` læses, **så** findes `newsletter.import` med antal, og `details_json` indeholder ikke tegnet `@`.
17. **Givet** manuel tilføjelse, **når** en ugyldig adresse indtastes, **så** vises en fejl. Ved en eksisterende aktiv adresse vises "Adressen findes allerede". Ved en afmeldt eller slettet adresse kræves fluebenet "selv bedt om". Med fluebenet genaktiveres modtageren, og `newsletter.recipient.reactivate` logges med `self_requested: true`.
18. **Givet** en aktiv modtager og det personlige afmeldingslink fra redigeringssiden, **når** linket åbnes med GET (også flere gange), **så** vises bekræftelsessiden med maskeret adresse, og status er uændret `active`.
19. **Givet** bekræftelsessiden, **når** der trykkes "Ja, afmeld mig", **så** bliver `status = unsubscribed`, `unsubscribe_via = link` og `unsubscribed_at` sat, siden viser "Du er nu afmeldt", og `newsletter.recipient.unsubscribe` logges. Gentages handlingen, vises "Du er allerede afmeldt nyhedsbrevet" uden fejl.
20. **Givet** et gyldigt token, **når** `curl -X POST -d 'List-Unsubscribe=One-Click' '<base>/nyhedsbrev-afmeld.php?t=<token>'` køres uden cookies og uden CSRF, **så** svarer siden 200, og modtageren er afmeldt med `via = one_click`.
21. **Givet** et token, der ikke findes, et token der er for kort eller langt, eller tegn uden for hex, **når** siden kaldes med GET eller POST, **så** svarer den 404 med en neutral tekst, ingen PHP-fejl og ingen ændringer.
22. **Givet** en POST fra formularen uden `csrf` og uden One-Click-body, **når** den sendes til afmeldingssiden, **så** svarer siden 419, og intet ændres.
23. **Givet** at modulet er deaktiveret under System, **når** afmeldingslinket bruges, **så** virker afmeldingen stadig. Administrationssiderne svarer 404.
24. **Givet** afmeldingssiden, **når** dens headere hentes, **så** indeholder de `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex` og `Cache-Control: no-store`.
25. **Givet** en aktiv modtager, **når** "Slet modtager" bekræftes, **så** er `email`, `name`, `company`, `token_hash` og `token_cipher` NULL, status er `deleted`, modtageren vises ikke i listen, det gamle afmeldingslink giver 404, og `newsletter.recipient.delete` logges.
26. **Givet** en modtager, **når** "Eksportér data (CSV)" bruges, **så** downloades en CSV med UTF-8 BOM, `;` og modtagerens felter (mail, navn, firma, type, status, kilde, oprettet, afmeldt). Et navn som `=CMD()` står som `'=CMD()`, og `newsletter.recipient.export` logges.
27. **Givet** modtagerlisten, importsiden og redigeringssiden, **når** HTML'en hentes, **så** indeholder ingen `href`, `src` eller `action` en mailadresse (bortset fra `mailto:`), og søgefeltet ligger ikke i en GET-formular.
28. **Givet** et navn med `<script>alert(1)</script>` i Excel-filen, **når** forhåndsvisningen og listen vises, **så** står teksten escaped, og intet script kører.
29. **Givet** en skærmbredde på 375 px, **når** S1, S2 og S7 vises, **så** er der ingen vandret scroll på selve siden (kun inde i `.table-wrap`), og knapper og felter kan bruges.

### Trin 2 – indstillinger, Microsoft 365, test og udsendelse

30. **Givet** at migration 1.1.0 er kørt, **så** findes `hsg_newsletter_campaigns` og `hsg_newsletter_deliveries`, `newsletter_cron_key` er en tilfældig værdi, `newsletter_transport = m365`, standardværdierne for hastighed er 10 / 20 / 600 / 2000, og modulversionen er 1.1.0.
31. **Givet** en gemt client secret, **når** indstillingerne gemmes med et tomt secret-felt, **så** bevares den. Siden indeholder aldrig secret i HTML, og `newsletter.settings` logger kun `m365_secret_changed`.
32. **Givet** en ny DATA-backup, **når** `database.sql` gennemsøges, **så** findes hverken `newsletter_m365_client_secret` eller `newsletter_cron_key`.
33. **Givet** den offentlige adresse `http://lager.example.dk`, forsendelsesmetoden *Lokal test-SMTP* med værten `smtp.example.dk`, eller *Maks. pr. minut* = 31, **når** der gemmes, **så** afvises det med en dansk fejltekst, og intet gemmes.
34. **Givet** forsendelsesmetoden *Lokal test-SMTP* og en kørende SMTP-opsamler, **når** der trykkes "Test forbindelse", **så** vises "Forbindelsen virker", og der modtages ingen mail. Mod en lukket port vises en dansk fejltekst uden PHP-advarsler.
35. **Givet** funktionen `hsg_newsletter_xoauth2_string()`, **når** den kaldes med `info@hsg-whisky.dk` og `abc`, **så** returnerer den base64 af `user=info@hsg-whisky.dk`, tegnet 0x01, `auth=Bearer abc` og to gange tegnet 0x01.
36. **(M365)** **Givet** forsendelsesmetoden *Microsoft 365* med et ugyldigt client secret, **når** der trykkes "Test forbindelse", **så** vises "Microsoft-login fejlede:" efterfulgt af Microsofts fejlkode (`AADSTS…`), uden PHP-advarsler, og hverken secret eller token står på siden eller i audit-loggen. Bagefter er alle `onedrive_*`-indstillinger uændrede, og "Test OneDrive" på `backup.php` opfører sig som før.
37. **Givet** `newsletter_m365_secret_expires` sat til i dag + 20 dage, **når** en Nyhedsbrev-side åbnes, **så** vises advarslen "Client secret til Microsoft 365 udløber …". Ved i dag + 40 dage vises den ikke.
38. **Givet** "Ny udsendelse" i oktober 2026, **så** er emnet forudfyldt med "Nyhedsbrev fra HSG Whisky ApS (Oktober 2026)".
39. **Givet** en tekst med `<b>fed</b>` og `https://hsg-whisky.dk`, **når** forhåndsvisningen vises for begge varianter, **så** står `<b>` som tekst, URL'en er et link, og afmeldingslinket er `#`. Svaret har CSP-headeren, og iframen har `sandbox`.
40. **Givet** en kladde uden test, **når** der sendes POST med "start" (også selv om knappen er deaktiveret), **så** afvises det, der oprettes ingen udsendelsesrækker, og status er fortsat `draft`.
41. **Givet** en kladde, **når** "Send test" sendes til én testadresse med målgruppen *Alle*, **så** modtager opsamleren 2 mails med emnet `[TEST] …` (én pr. variant), `tested_hash` gemmes, og `newsletter.campaign.test` logges.
42. **Givet** en testet kladde, **når** emnet eller afsendernavnet ændres, **så** viser siden "Ændret siden testen – send en ny test", og start afvises, indtil der er sendt en ny test.
43. **Givet** en testet kladde med 10 aktive modtagere, **når** der startes med `expected_count = 9`, **så** vises "Modtagerlisten er ændret – kontroller antallet igen.", og intet startes. Med `expected_count = 10` og flueben oprettes 10 udsendelsesrækker, status bliver `sending`, og `newsletter.campaign.start` logges.
44. **Givet** en igangværende udsendelse, **når** batch-endpointet kaldes, indtil det svarer `done`, **så** har opsamleren præcis én mail pr. modtager. Hver mail har:
    - kun modtageren i `To` og ingen `Cc`/`Bcc`
    - headerne `List-Unsubscribe` (https-URL med `t=` og `mailto:`), `List-Unsubscribe-Post: List-Unsubscribe=One-Click` og `Message-ID`
    - `From` og `MAIL FROM` lig afsenderadressen, og svar-til fra indstillingerne
    - afmeldingslinket i teksten

    Ingen URL i mailen indeholder en mailadresse.
45. **Givet** `newsletter_batch_size = 3`, **når** batch-endpointet kaldes én gang, **så** sendes højst 3 mails, og JSON-svaret indeholder `counts` og `status`.
46. **Givet** en igangværende udsendelse, **når** to batch-kald (og eventuelt cron) køres samtidig, **så** svarer det ene `busy: true`, og efter færdiggørelse har hver modtager præcis én mail.
47. **Givet** en udsendelsesrække, der manuelt er sat til `sending`, **når** den næste batch kører, **så** sættes rækken til `unknown`, og der sendes ingen mail til den modtager. Siden viser "1 modtager har ukendt status". "Send igen til disse 1" sætter rækken i `queued` og logger `newsletter.campaign.retry_unknown`.
48. **Givet** en modtager, der afmelder sig efter start, **når** hans række behandles, **så** bliver den `skipped`, og der sendes ingen mail.
49. **Givet** `newsletter_max_per_minute = 2`, `newsletter_max_per_hour = 4` og 10 modtagere, **når** batch-endpointet kaldes gentagne gange, **så** gælder:
    - Ingen periode på 60 sekunder har mere end 2 sendte mails.
    - Der sendes højst 4 i alt, og svaret indeholder `wait_until`.
    - Der sendes ikke flere, før de ældste `sent_at` er mere end en time gamle. Testeren kan flytte `sent_at` tilbage.
50. **Givet** at opsamleren svarer `530 5.7.57` på `MAIL FROM`, **når** en batch kører, **så** bliver udsendelsen `paused` med `pause_reason`, den reserverede række står igen i `queued`, der er ingen `unknown`- eller `failed`-rækker, og `newsletter.campaign.auto_pause` logges. Det samme gælder, når opsamleren svarer `554 5.2.0 STOREDRV.Submission.Exception:SubmissionQuotaExceededException` efter DATA.
51. **Givet** at opsamleren svarer 550 på `RCPT TO` for én adresse, **når** batchen kører, **så** bliver den række `failed` med fejlteksten, og de øvrige sendes.
52. **Givet** en igangværende udsendelse, **når** der trykkes Pause, **så** sender batch-kald intet. Efter Genoptag fortsætter udsendelsen. Annullér resten sætter rækker i `queued` til `skipped` og status til `cancelled`. Alle tre handlinger logges.
53. **Givet** en igangværende udsendelse, **når** der startes en anden, **så** afvises den med "En anden udsendelse er i gang".
54. **Givet** `cron/newsletter.php` kaldt via URL uden nøgle eller med forkert nøgle, **så** svarer det 403. Med korrekt nøgle eller fra CLI behandles køen, og `newsletter_cron_last_run` opdateres. Links i mails sendt fra cron begynder med `newsletter_public_base_url`, ikke `localhost` eller `/cron`.
55. **Givet** en udsendelse afsluttet for 25 måneder siden og `newsletter_log_retention_months = 24`, **når** cron eller `newsletter.php` kører oprydningen, **så** er dens udsendelsesrækker slettet, mens selve udsendelsen stadig findes.
56. **Givet** en færdig udsendelse, **når** der trykkes "Kopiér til ny kladde", **så** findes en ny kladde med samme emne og tekst, og uden `tested_hash`.
57. **Givet** en testmail, **når** dens afmeldingslink åbnes og bekræftes, **så** vises "Dette er et testlink …", og ingen modtager ændres.
58. **Givet** en linkbruger eller en login-bruger uden moduladgang, **når** `newsletter.php`, `newsletter_campaign.php`, `newsletter_settings.php`, `newsletter_preview.php` eller `newsletter_send.php` kaldes, **så** svarer de 403. AJAX-endpointet uden `csrf` svarer 419.

### Trin 3 – katalog

59. **Givet** `catalog_pdf.php` før og efter ændringen, **når** den kaldes med `price=retail` og `price=wholesale` samt `news=0` og `news=1`, **så** er filnavn, `Content-Type`, `Content-Disposition` og antal sider uændrede, og filen begynder med `%PDF-` og slutter med `%%EOF`. Linkbrugere med `catalog.view` har samme adgang som før.
60. **Givet** en kladde med målgruppen *Alle* og "Hele kataloget", **når** der trykkes "Frys katalog nu", **så** oprettes 2 filer i `storage/tmp/newsletter/<id>/` med tilfældige navne og 2 rækker i `hsg_newsletter_catalogs` med korrekt `sha256` og `file_size`. En tidligere test bliver ugyldig ("Ændret siden testen"). Med målgruppen *Kunder* oprettes kun `retail`.
61. **Givet** valget af et katalog, der ikke er frosset, **når** der trykkes "Send test", **så** afvises det med "Frys kataloget først".
62. **Givet** en afsluttet udsendelse, **når** en forhandlers kataloglink åbnes, **så** returneres engros-PDF'en (samme SHA-256 som i tabellen), og en kundes link returnerer udsalgsprisvarianten. Det er ikke muligt at få den anden variant ved at ændre URL'en.
63. **Givet** et gyldigt modtagertoken for en modtager uden udsendelsesrække i udsendelse `c`, **når** `nyhedsbrev-katalog.php?t=…&c=…` åbnes, **så** svarer siden 404.
64. **Givet** at `expires_at` er passeret, **når** linket åbnes, **så** svarer siden 410. Efter oprydning er filen slettet, og `deleted_at` er sat.
65. **Givet** en webserver, der respekterer `.htaccess`, **når** `storage/tmp/newsletter/<id>/<fil>.pdf` hentes direkte, **så** svarer den 403.
66. **Givet** to downloads af samme fil, **så** er `download_count = 2`, og der findes ingen log over downloads pr. modtager.
67. **Givet** et release-build (`php tools/build-release.php`), **så** indeholder ZIP-filen ingen filer fra `storage/tmp/`, og negativtesten er grøn.

### Fælles

68. **Givet** hvert trin, **når** `bash tests/run.sh` køres, **så** er resultatet grønt. Røgtesten omfatter de nye administrationssider, og de offentlige sider med et ugyldigt token giver 404 uden PHP-fejl.
69. **Givet** alle nye sider, mails og fejltekster, **så** er al tekst, brugeren ser, på dansk.

---

## Uden for scope

- Vedhæftning af PDF i mailen (se beslutning 5).
- WYSIWYG-editor, HTML-indtastning, billeder og logo i mailen, flere skabeloner.
- Flettefelter og personlig hilsen ("Kære {navn}").
- Sporing af åbninger og klik (pixels, omdirigerede links). Det er bevidst fravalgt af hensyn til databeskyttelse.
- Registrering af retsgrundlag pr. modtager (afklaret spørgsmål 1).
- Automatisk håndtering af afviste mails (IMAP eller webhooks). Afviste mails lander i info@'s indbakke, og administratoren sætter modtageren til *Stoppet*.
- Tilmeldingsformular og double opt-in på hjemmesiden.
- Planlagt afsendelse ("send kl. 08.00").
- Andre forsendelsesmetoder end Microsoft 365 (OAuth2) og lokal test-SMTP: SMTP med brugernavn/adgangskode, dedikerede udbydere (Brevo, Mailjet, SES), Microsoft Graph `sendMail`, HVE og Azure Communication Services. De kan tilføjes senere som en ny forsendelsesmetode uden at ændre køen.
- Certifikatbaseret login til Entra-appen i stedet for client secret. DKIM-signering i PHP.
- Mailadresser med æøå i domænet (IDN). De vises som ugyldige.
- Automatisk stop af modtagere, der ikke længere står i Kims fil (se åbent spørgsmål 12). Forhåndsvisningen viser kun antallet.
- Eksport af hele modtagerlisten til Excel.
- Flere udsendelser samtidig.
- WooCommerce-kunder som kilde til modtagere.
- Spørgsmål om afmeldingsårsag og flere sprog.
- Mailfelt på login-brugere (`lager_admins`).

---

## Kilder (tekniske forudsætninger)

**Microsoft 365 / Exchange Online**
- Basic auth for SMTP AUTH (MC786329, opdateret 27-01-2026): [mc.merill.net](https://mc.merill.net/message/MC786329) og [Tech Community](https://techcommunity.microsoft.com/blog/exchange/exchange-online-to-retire-basic-auth-for-client-submission-smtp-auth/4114750)
- SMTP med OAuth og `SMTP.SendAsApp`, service principal og postkasserettighed: [Microsoft Learn – Authenticate an IMAP, POP or SMTP connection using OAuth](https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth) og [Microsoft Q&A – 535 5.7.3 med client credentials](https://learn.microsoft.com/en-ie/answers/questions/5872803/smtp-auth-with-oauth-2-0-client-credentials-flow-r)
- Graph `sendMail` og MIME: [user: sendMail](https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0), [Send MIME](https://learn.microsoft.com/en-us/graph/outlook-send-mime-message) og [Q&A om List-Unsubscribe via Graph](https://learn.microsoft.com/en-us/answers/questions/2128545/how-to-set-both-list-unsubscribe-post-and-list-uns)
- Sendegrænser: [Microsoft Defender – outbound sending limits](https://learn.microsoft.com/en-us/defender-office-365/outbound-spam-sending-limits-troubleshoot), [External Recipient Rate Limit (annulleret)](https://techcommunity.microsoft.com/blog/exchange/exchange-online-to-introduce-external-recipient-rate-limit/4114733), [BleepingComputer](https://www.bleepingcomputer.com/news/microsoft/microsoft-cancels-plans-to-rate-limit-exchange-online-bulk-emails/) og [Tenant outbound email limits](https://techcommunity.microsoft.com/blog/exchange/introducing-exchange-online-tenant-outbound-email-limits/4372797)
- Masseudsendelse og servicebeskrivelsen (gengivet af en udbyder): [Retarus](https://www.retarus.com/blog/en/sending-out-newsletters-rather-not-with-exchange-online)
- DKIM i Microsoft 365: [MailReach-guide](https://www.mailreach.co/blog/set-up-dkim-office-365)

**Øvrige**
- Dansk Erhverv: [Forbrugerombudsmanden: det er for svært at framelde sig jeres nyhedsbrev](https://www.danskerhverv.dk/presse-og-nyheder/nyheder/2024/februar/forbrugerombudsmanden-til-virksomhed-det-er-for-svart-at-framelde-sig-jeres-nyhedsbrev/)
- Simply.com: [Mail restrictions and policies](https://www.simply.com/en/support/faq/mail/639/)
- RFC 8058 – One-Click Unsubscribe: [rfc-editor.org](https://www.rfc-editor.org/rfc/rfc8058)

Microsoft ændrer jævnligt grænser og datoer. Michael bør kontrollere Message Center og Microsoft Learn, før systemet tages i drift. Det gælder især datoen for den endelige fjernelse af Basic auth og grænsen for eksterne modtagere i organisationen.

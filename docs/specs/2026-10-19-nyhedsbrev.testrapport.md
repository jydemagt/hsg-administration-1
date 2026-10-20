# Testrapport: Nyhedsbrev trin 1 (modul `newsletter` 1.0.0)

- **Spec:** `docs/specs/2026-10-19-nyhedsbrev.md` (acceptkriterierne under "Trin 1 – modtagere, import og afmelding", nr. 1–29, og de fælles kriterier 88 og 89)
- **Branch:** `feature/nyhedsbrev-trin1` (testet på commit `f574dae`)
- **Testet:** 20-10-2026, af uafhængig tester (udviklerens egne testscripts er ikke brugt som bevis)
- **Åbne spørgsmål 2, 6, 11 og 12** er ikke besvaret. Der er testet mod spec'ens antagelser.

## Konklusion

**KLAR** (trin 1).

- **31 af 31 kriterier er bestået** (nr. 1–29, 88 og 89). **0 er fejlet.**
- Udviklerens fem afvigelser fra spec'en er efterprøvet. Ingen af dem bryder et kriterie (se afsnittet "Afvigelserne 1–5").
- Der er fundet **ingen fejl i trin 1**. Der er fundet nogle svagheder og bemærkninger, som ikke blokerer (se "Bemærkninger").
- Der er fundet en **svaghed i kernen uden for trin 1** (`verify_csrf()` i `functions.php`). Den er bekræftet, men ikke rettet (se "Fund uden for trin 1").
- Før en release mangler de sædvanlige release-punkter fra CLAUDE.md (se "Før release").

## Miljø og metode

- PHP 8.5.8 (`php -S`), MariaDB 11.4.13 (`127.0.0.1:3306`, tom database `hsg_test`, nulstillet før hver installation).
- Opgraderingsscenariet (kriterie 1–3) er kørt ved at installere `main` (11.1.4, uden modulet), oprette brugere, lægge branchens filer ovenpå og så indlæse en side.
- Importfiler er lavet med en generator, der ligner Excel (sharedStrings, `workbook.xml`, udeladte rækker, tomme rækker og formaterede tomme celler). Der er også testet CSV i Windows-1252 med semikolon.
- Kriterie 29: headless Chrome. Chrome i headless-tilstand kan ikke gå under 500 px bredde, så siderne er vist i en iframe på præcis 375 px (de rigtige sider med login, ikke øjebliksbilleder). Bredden er målt med JavaScript, og skærmbillederne er set.
- De fleste kriterier (2, 3, 6–14, 16–28 samt afvigelse 1 og 2) er derudover lagt ind som **automatisk test** (se "Nye automatiske tests"). Kriterie 5 og 88 dækkes af den eksisterende røgtest, og kriterie 1, 15, 29 og 89 er kun afprøvet manuelt med scripts.
- Testscripts og rå output ligger uden for repoet (scratchpad, undermappen `tester/`).

### Samlede kørsler

| Kørsel | Resultat |
|---|---|
| `bash tests/run.sh` (før mine tilføjelser) | grøn: syntaks, 86 enhedstest, release-negativtest, installation, røgtest af alle sider |
| `bash tests/run.sh` (efter mine tilføjelser, 55 nye tjek) | **ALLE TESTS BESTÅET** |
| Egne scripts: opgradering og adgang (fase A) | 85 bestået. 2 "fejl" er mine egne testfejl, ikke fejl i koden (se nedenfor). |
| Egne scripts: import, kriterie 6–16 (fase B1) | 59 bestået, 0 fejlet |
| Egne scripts: manuel tilføjelse, afmelding, slet, eksport (fase B2) | 116 bestået, 0 fejlet |
| Egne scripts: modul fra, headere, URL'er, XSS, afvigelse 4 og 5 (fase B3) | 55 bestået, 0 fejlet |
| Egne scripts: `verify_csrf()` (fase C) | 3 bestået, 0 fejlet (resten er målinger) |
| Egne scripts: fejlfiler, løb, store filer (fase D) | 27 bestået, 0 fejlet |

De to "fejl" i fase A: (1) `grep` efter "Nyhedsbrev" på `user_permissions.php` ramte menuen, fordi superadministratoren ser menupunktet. Når kun modultabellen kontrolleres, står Nyhedsbrev ikke i den (bestået, se kriterie 3). (2) Serverloggen indeholder en advarsel fra `stock.php` linje 75 ("Undefined array key status"). Den fil er ikke ændret på branchen, og advarslen findes også på `main`.

## Acceptkriterier

| Nr. | Kriterie (kort) | Resultat | Bevis |
|---|---|---|---|
| 1 | Migration ved opgradering | BESTÅET | Installerede `main`, lagde branchen ovenpå og indlæste `index.php`. `SHOW TABLES` viser `hsg_newsletter_recipients`, `hsg_module_versions.newsletter = 1.0.0`. Yderligere to sidevisninger giver 200 uden PHP-fejl og stadig én versionsrække. |
| 2 | Ingen adgang for login-brugere som standard | BESTÅET | Tre login-brugere fra før opgraderingen (to med eksplicitte valg, én uden rækker) har efter migrationen `(admin_id,'newsletter',0)`. Alle får 403 på de tre sider, ser ikke menupunktet og intet kort på Mere-siden. POST med gyldigt csrf giver 403, og intet oprettes. Superadministrator får 200 og ser menupunktet. **Modul fra → superadmin gemmer brugeren i `users.php` (newsletter-rækken forsvinder) → modul til:** brugeren får stadig 403. En ny bruger oprettet, mens modulet var slået fra, får også 403. Brugere uden rækker har fortsat adgang til de eksisterende moduler (se afvigelse 1). Med flueben i `users.php` får brugeren adgang. |
| 3 | Direkte link | BESTÅET | `?k=…` giver 403 på `newsletter.php`, `newsletter_recipients.php`, `newsletter_import.php` (også POST med csrf). Menuen har ikke Nyhedsbrev. `user_permissions.php?id=<link>` viser ikke modulet i modultabellen. Linkbrugeren kan stadig se `reservations.php`. |
| 4 | Uden login = 401 | BESTÅET | Alle tre sider giver 401. POST uden login giver 401, og intet oprettes. |
| 5 | POST uden CSRF = 419 | BESTÅET | `newsletter_recipients.php` og `newsletter_import.php`: POST uden `csrf`, med forkert `csrf` og med `csrf[]=x` giver 419, og intet oprettes. |
| 6 | Kolonne D uden overskrifter | BESTÅET | xlsx uden overskrifter: `<option value="3" selected>Kolonne D</option>`, "ingen overskriftsrække fundet". Nye = 3, Ugyldige = 3. Rækkenumre følger Excel, også med udeladt række og tom række. |
| 7 | `" Kim@Firma.DK "` + `kim@firma.dk` | BESTÅET | Præcis én modtager med `email = kim@firma.dk`. Forhåndsvisningen viser "Dubletter i filen: 1". `email_hash` er SHA-256 af den normaliserede adresse. |
| 8 | `a@x.dk; b@y.dk` i én celle | BESTÅET | To modtagere oprettet. |
| 9 | `kim(at)firma.dk`, `kim@`, `kim@firma` | BESTÅET | Alle tre står under "Ugyldige" med rækkenummer (3, 5 og 7 i en fil med udeladt række 4 og en tom celle i række 6). Ingen importeres. Tomme celler nævnes ikke. |
| 10 | Samme fil to gange | BESTÅET | Anden analyse: 0 nye, 3 uændrede. Antal rækker uændret. |
| 11 | Afmeldt/stoppet/slettet springes over | BESTÅET | `UNSUB@X.DK`, ` Stopped@X.dk `, `DELETED@x.dk` står under "Springes over (3)" som `u***@x.dk – Afmeldt` osv. De fulde adresser står ikke i siden. Efter import er statusserne uændrede, og navnet ændres ikke. Den slettede række (kun `email_hash`) genkendes. |
| 12 | "Alle er forhandlere" + eksisterende kunde | BESTÅET | "Skifter type: 1", "Kunde -> Forhandler (får engrospriser)". Før import er modtageren `customer`, efter import `dealer`. |
| 13 | "Brug kolonne", Forhandler + Kunde | BESTÅET | Modtageren bliver `customer`. "Konflikter (importeres som Kunde): 1". `FORHANDLER A/S` bliver `dealer`. Uden valgt type-kolonne vises en fejl, og Importér er deaktiveret. |
| 14 | Ingen retsgrundlag, `source`, `last_import_at` | BESTÅET | Import- og tilføj-formularerne indeholder ikke "grundlag", "samtykke" eller "basis". Tabellen har ingen kolonne `basis` (`SHOW COLUMNS`). Importerede: `source=import`, `last_import_at` sat. Manuelt tilføjede: `source=manual`, `last_import_at` NULL. |
| 15 | Kolonnevalg huskes | BESTÅET | Mailkolonnen ændret manuelt til "Mail". Setting `newsletter_import_columns` = `{"navn":"name","kontakt":"","mail":"email","email":""}`. Ny fil med samme overskrifter i anden rækkefølge: "Mail" (kolonne B) og "Navn" (kolonne C) er valgt på forhånd. |
| 16 | Audit af import | BESTÅET | `newsletter.import` med antal (`new`, `updated`, `unchanged`, `skipped`, `invalid`, `duplicates`, `conflicts`, `type_changes`). Ingen `@` i nogen `details_json` for `newsletter.%`. Et filnavn med `@` skrives som `(at)`. |
| 17 | Manuel tilføjelse | BESTÅET | Ugyldig og tom adresse giver fejl. Eksisterende aktiv: "Adressen findes allerede". Afmeldt og slettet uden flueben afvises, og fluebenet vises. Med flueben genaktiveres modtageren (slettet: samme `id`, nyt token), og `newsletter.recipient.reactivate` har `self_requested: true`. Stoppet: "Adressen findes allerede, men modtageren er stoppet". |
| 18 | GET ændrer intet | BESTÅET | Tre GET uden cookies og HEAD: 200, maskeret `a***@x.dk`, knappen "Ja, afmeld mig", den fulde adresse står ikke i siden. Status uændret `active`, ingen audit-række. |
| 19 | POST fra formularen | BESTÅET | `status=unsubscribed`, `unsubscribe_via=link`, `unsubscribed_at` sat. Siden viser "Du er nu afmeldt". Audit `newsletter.recipient.unsubscribe` (`via: link`, ingen `@`, `actor_type = system`). Gentaget POST og GET: "Du er allerede afmeldt nyhedsbrevet", og der kommer ikke en ny audit-række. |
| 20 | Ét klik (RFC 8058) | BESTÅET | `curl -X POST -d 'List-Unsubscribe=One-Click' <link>` uden cookies: `Afmeldt|200|text/plain`, `via = one_click`. Gentaget kald er idempotent og logger ikke igen. Virker også med `Content-Type: text/plain`. `List-Unsubscribe=Other` afmelder ikke (419). |
| 21 | Ugyldigt token | BESTÅET | 64 nuller, `abc`, 65 og 63 tegn, 64 × `g`, store hex-bogstaver, tomt, `../../etc/passwd`, `' OR 1=1`, nulbyte, manglende `t` og `t[]=…`: GET og POST giver 404 med teksten "Linket er ugyldigt eller udløbet…", ingen PHP-fejl, ingen `@` i svaret, ingen nye audit-rækker, og ingen modtager ændres. |
| 22 | POST uden CSRF og uden One-Click | BESTÅET | 419 for: tom POST uden cookies, `x=1`, `csrf=` (tom), forkert `csrf` med session, ingen `csrf` med session, `csrf[]=x`, og et gyldigt `csrf` fra en anden session. Intet ændres. Teksten er "Formularen er udløbet…". |
| 23 | Modul deaktiveret | BESTÅET | Administrationssiderne svarer 404 (GET og POST, også for superadmin), og menupunktet forsvinder. Afmeldingslinket virker stadig: GET 200, POST fra formular og ét klik afmelder. Ugyldigt token giver stadig 404. Vedligeholdelsesfilen `.hsg-maintenance` giver 503 med `Retry-After` og 200 igen, når filen er væk. |
| 24 | Headere | BESTÅET | `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store` på alle svar: GET 200, GET 404, POST 419 og POST ét-klik 200. |
| 25 | Slet modtager | BESTÅET | `email`, `name`, `company`, `token_hash`, `token_cipher` = NULL, `status = deleted`, `email_hash` bevaret. Vises ikke i nogen filtreret liste. `?id=` giver 404. Det gamle link giver 404 (GET og ét klik). `newsletter.recipient.delete` logget uden `@`. Gentaget sletning og eksport af en slettet giver en fejl, ikke 500. |
| 26 | Eksportér data (CSV) | BESTÅET | POST giver `text/csv`, `Content-Disposition: attachment`, UTF-8 BOM (`efbbbf`), `;` og kolonnerne Mail, Navn, Firma, Type, Status, Kilde, Oprettet, Sidst set i import, Afmeldt, Afmeldt hvordan. Navnet `=CMD()` står som `'=CMD()`, firmaet `+SUM(1)` som `'+SUM(1)`. `newsletter.recipient.export` logget. Eksport via GET giver ikke en CSV. |
| 27 | Ingen mailadresse i URL'er | BESTÅET | På listen (alle filtre), redigér-siden, importsiden og forhåndsvisningen: ingen `href`, `src` eller `action` med `@` (bortset fra `mailto:`). Søgefeltet er et `<input type="search">` uden for enhver `<form>`. `Location`-headere efter POST indeholder ingen adresse, og serverens request-log har ingen URL med `@`. |
| 28 | `<script>` i filen | BESTÅET | Navn `<script>alert(1)</script>`, firma `"><img src=x onerror=alert(2)>`, en ugyldig mailcelle med `<script>`, og et filnavn med `<img … onerror>`: alt står som `&lt;…&gt;` i forhåndsvisningen, listen, redigér-siden og audit-visningen. Intet rå tag i HTML'en. `'; DROP TABLE x;--` gemmes som almindelig tekst. |
| 29 | Mobil, 375 px | BESTÅET | Målt i Chrome på 375 px: S1 (liste, filtre, "Tilføj modtager" åben, redigér), S2 (upload og forhåndsvisning med langt filnavn og lang adresse) og S7 (afmelding og 404): `scrollWidth = clientWidth` på alle sider, så der er ingen vandret scroll på siden. Tabellen på listen scroller inde i `.table-wrap` (indhold 2412 px i 334 px). Felter og knapper på S2 og redigér-siden er mindst 44 px høje (ingen mål under 40 px), S7 er ikke målt for højde, men bruger standardknappen (`min-height: var(--touch-min)` = 44 px), og "Rediger"-knapperne i tabellen er 36 px (`.button.small`, som i resten af systemet). Se bemærkning 4. |
| 88 | `bash tests/run.sh` er grøn | BESTÅET | Se "Samlede kørsler". Røgtesten omfatter de tre administrationssider og afmeldingssiden (404 uden PHP-fejl for fem ugyldige tokens, GET og POST). |
| 89 | Al tekst på dansk | BESTÅET | Tekst på alle trin 1-sider (import, forhåndsvisning, liste, redigér, afmelding, 404), alle flash-beskeder, fejltekster, knapper, `title`, `placeholder` og `aria-label` er gennemlæst. Alt er på dansk. `docs/NEWSLETTER.md` er også på dansk. |

## Afvigelserne 1–5

### 1. `users.php` følger `admin_default_access` (commit `0da3b13`)

**Vurdering: korrekt, og der er ingen bivirkninger på eksisterende moduler.**

- Testet med en login-bruger **uden rækker** (som i en ældre installation). Brugeren har adgang til catalog, products, stock, status, reservations, locations, brands og quality (200) både før og efter, at jeg gemmer brugeren i `users.php` med de viste flueben. En bruger med eksplicit valg (kun catalog og inventory) får stadig 403 på `products.php`.
- I `users.php` er Nyhedsbrev **ikke afkrydset** hverken i "Opret ny login-bruger" eller i redigeringsformularen for en bruger uden rækker. Alle andre moduler er afkrydset som før. Listen "Moduladgang" viser ikke Nyhedsbrev for brugeren.
- Gem uden at sætte flueben giver ikke adgang (403 før og efter), og newsletter-rækken står som `can_view = 0`.
- Ændringen er nødvendig for kriterie 2, fordi `users.php` ellers ville vise Nyhedsbrev som afkrydset for brugere uden rækker, og et ubevidst gem ville give adgang. Ændringen står ikke på spec'ens filliste, men er lille og i overensstemmelse med beslutning 6.
- Bemærkning: "Vælg alle moduler"-knappen afkrydser også Nyhedsbrev. Det er en bevidst handling fra superadministratoren og derfor i orden.

### 2. En stoppet modtager kan afmelde sig

**Vurdering: korrekt og ønskeligt.** En stoppet modtager er i praksis stadig en person, der kan have et gammelt link.

- GET viser bekræftelsessiden og ændrer intet. POST fra formularen giver `unsubscribed` med `via = link`. Ét klik giver `unsubscribed` med `via = one_click`.
- Administratoren kan stadig kun afmelde *aktive* (`Stop af afmeldt afvises`, `Afmeld` vises kun for aktive). En afmeldt kan ikke stoppes.
- Spec'en nævner ikke stoppede i statusovergangene. Overgangen `stopped → unsubscribed` ved modtagerens eget ønske bør føjes til spec'en.

### 3. Ekstra CSRF-kontrol i `nyhedsbrev-afmeld.php`

**Vurdering: kontrollen er nødvendig og virker.** Svagheden i kernen er bekræftet (se "Fund uden for trin 1").

- Afmeldingssidens egen kontrol kræver både et token i formularen og et token i sessionen. Seks varianter af POST uden gyldigt token giver 419 (se kriterie 22), også uden cookies. Det er præcis det tilfælde, `verify_csrf()` ikke dækker.

### 4. Rækkenumre fra arket

**Vurdering: korrekt.**

- Testet med en xlsx med udeladte rækker (`<row>` mangler), en række uden celler (`<row r="2"/>`), en række med kun en formateret tom celle (`<c s="1"/>`), titelrækker over overskriften og flere ark. Rækkenumrene i forhåndsvisningen er Excel-numrene: overskrift i række 4, ugyldige i række 5, 7 og 8, og på ark 2 en ugyldig i række 5 trods udeladte rækker.
- Forhåndsvisningen viser "overskrift i række N" med det rigtige Excel-nummer. CSV bruger linjenumre.
- Funktionen spejler læserens regel (en række tælles med, hvis den har mindst ét `<c>`-element). Hvis antallet ikke passer, falder den tilbage til 1, 2, 3 …, så en uventet filstruktur giver forkerte tal, men ingen fejl. Det er ikke afprøvet med en fil, hvor de to afviger.

### 5. Overskriftsrækken findes kun ud fra kendte overskrifter

**Vurdering: en svaghed, ikke et brud på et kriterie.**

| Fil | Resultat |
|---|---|
| Navn, Firma, Tlf, **Kontakt e-mail** (kolonne D) | Overskriftsrækken findes via Navn og Firma, og kolonne D er standard. 2 nye, 0 ugyldige. |
| Kun **Kontakt e-mail** i kolonne D, ingen andre overskrifter | "ingen overskriftsrække fundet". Overskriften behandles som data og står som to ugyldige i række 1 (`Kontakt` og `e-mail`, fordi cellen deles ved mellemrum). Importen kan gennemføres, og overskriften bliver ikke en modtager. |
| **Kontakt e-mail** i kolonne A og Firma i kolonne B | Overskriftsrækken findes. Kolonne D er tom, så der vises "Filen indeholder ingen gyldige mailadresser", og Importér er deaktiveret. Efter valg af kolonne A i listen "Mailkolonne" og Genberegn: 1 ny, 0 ugyldige. |

- Spec'en siger kun, at mailkolonnen findes ud fra de seks navne, "ellers bruges kolonne D". Det er opfyldt. Kriterie 6 handler om filer *uden* overskrifter. Ingen ukendt overskrift kan blive importeret som modtager, fordi en overskrift aldrig er en gyldig adresse.
- Det er kun besværligt, hvis Kims fil har en ukendt mailoverskrift. Det kan afgøres, når Kim har svaret på spørgsmål 2 om kolonnerne. Et enkelt tiltag er at tilføje flere navne (fx "kontakt e-mail") eller at genkende en overskriftsrække, når den sidste række over dataene har en tekst i den valgte mailkolonne.

## Fund uden for trin 1

### `verify_csrf()` i `functions.php` godtager POST uden token, når sessionen heller ikke har et

**Bekræftet.** Funktionen (linje 9) er:

```php
function verify_csrf(): void { if($_SERVER['REQUEST_METHOD']==='POST'){ $t=$_POST['csrf']??'';if(!is_string($t)||!hash_equals($_SESSION['csrf']??'',$t)){ http_response_code(419); exit(...); }} }
```

Mangler både `$_POST['csrf']` og `$_SESSION['csrf']`, bliver det `hash_equals('', '')`, som er `true`. Funktionen er ikke ændret.

**Bevis (fase C):**
- CLI: `verify_csrf()` med tom session og tom POST returnerer uden at afvise. Med `$_SESSION['csrf']='abc'` afvises samme POST (419).
- Login uden forudgående GET af login-siden og uden `csrf`: `admin-login.php` svarer `302 Location: index.php`, og sessionen er logget ind uden token.
- I den session giver `POST newsletter_recipients.php` med `action=create` og uden `csrf` **302, og modtageren blev oprettet**. Efter at en side med en formular er vist (så sessionen har fået et token), giver samme POST 419.
- Linkbruger (`?k=…`): POST til `reservations.php` uden `csrf` (`action=cancel`) giver 302 i en frisk session og 419, efter at `reservations.php` er vist.
- En ordinær admin-session (login-siden vist først, som i en browser) har altid et token og afviser (419).

**Hvilke sider er i praksis udsatte:**
- **Alle sider, der kræver `auth.php`**, når den indloggede session endnu ikke har et token. Det gælder administrationssiderne, herunder de tre nyhedsbrevssider og `users.php`, og linkbrugernes sider med POST (reservationer). Det er sessioner, der er oprettet uden at en formular har været vist: et direkte link (`?k=`) før første formular, og et script- eller formularlogin uden forudgående GET af login-siden. I en normal browsersession lukkes vinduet, så snart en side med `csrf_field()` vises.
- **`admin-login.php`**: en POST uden token accepteres (login-CSRF). Den har ingen anden beskyttelse end begrænsningen af loginforsøg.
- **`install.php` og `admin-setup.php`** er kun tilgængelige, før systemet er installeret, eller for det gamle første-admin-link.
- **`nyhedsbrev-afmeld.php`** er ikke udsat, fordi den har sin egen kontrol (kriterie 22).

**Afbødning i dag:** `session.php` sætter cookien med `SameSite=Lax`, så en POST fra et andet websted ikke sender sessionscookien i moderne browsere. Risikoen er derfor lav og kræver en angriber på samme websted eller en ældre browser. Det er stadig en svaghed i kernen.

**Anbefaling (ikke rettet):** afvis, hvis `$_SESSION['csrf']` er tom, fx `$s=(string)($_SESSION['csrf']??''); if($s===''||!is_string($t)||!hash_equals($s,$t)) {…}`. Rettelsen bør laves som en selvstændig opgave med egen test, fordi den også ændrer login-flowet (login-siden skal altid oprette et token, før POST).

## Bemærkninger (ikke fejl)

1. **Svagheden ved ukendte overskrifter** (afvigelse 5) bør tages med, når Kim har svaret på spørgsmål 2.
2. **Modtagerlisten har ingen sidedeling.** Med 5.029 modtagere er HTML'en 1,5 MB. Kims få hundrede modtagere giver ingen problemer, og importgrænsen er 5.000 rækker. Import af 4.999 rækker tog 5 sekunder, analysen 1 sekund. Grænsen er "højst 5.000 rækker" *inklusive* overskriftsrækken (en fil med 5.002 rækker afvises korrekt med "Filen har mere end 5.000 rækker.").
3. **Samme fejltekst vises to gange** ("Filen indeholder ingen gyldige mailadresser.") når Importér trykkes på en analyse uden adresser. Importér er deaktiveret i browseren, så det kræver en manuel POST. Kun kosmetisk.
4. **Mobil, S1:** "Rediger"-knappen ligger i den sidste kolonne og er uden for skærmen på 375 px, indtil tabellen scrolles vandret (som spec'en tillader). Det vil være bedre, hvis selve mailadressen er et link til redigering. Mindre punkt.
5. **Forhåndsvisningen viser fulde adresser** under "Nye", "Skifter type" og "Konflikter". Kun de sprungne (afmeldt/stoppet/slettet) er maskeret, som spec'en siger. Adresserne ligger i en sidevisning for administratoren og ikke i URL'er.
6. **Forhåndsvisningen i sessionen** indeholder hele filen (alle kolonner), som spec'en tillader. Den ligger kun i sessionsfilen og slettes ved import eller annullering. Et forkert eller fremmed `preview_token` afvises ("Forhåndsvisningen er udløbet"), og et andet apply af samme token ændrer intet.
7. **Løb mellem forhåndsvisning og import:** Afmeldes en modtager (ét klik) efter forhåndsvisningen og før "Importér", genaktiveres hun ikke, og navnet ændres ikke. Importen laver analysen igen mod databasen.
8. **Release endnu ikke klar:** `app_version.php` (11.1.4), README og `hsg-package.json` er uændrede. Release-ZIP'en er ikke bygget i denne test, fordi bygget kræver et git-checkout og skriver `hsg-package.json`. Release-negativtesten i `tests/run.sh` er grøn, og `tools/build-release.php` fjerner `tests` og `docs/specs`, så testfilerne og denne rapport ikke kommer med.
9. **Udviklerens testscripts:** `mkxlsx.php` skriver `inlineStr` og ingen sharedStrings. Rigtige Excel-filer bruger sharedStrings. Mine filer bruger sharedStrings, og resultatet er det samme. Udviklerens `mobile.sh` udelukker `.simple-tabs`, `.sidebar` og `.mobile-nav` fra målingen. Min måling udelukker kun `.table-wrap`.
10. **Miljø:** Windows begrænser stier til 260 tegn, og `ZipArchive::close()` fejler ved lange stier. Det gælder kun testværktøjerne her (scratchpad-stien er lang). `tests/make_xlsx.php` skriver derfor en tydelig fejl.

## Før release

- Hæv `app_version.php`, README og manifest konsekvent, og lav `hsg-package.json` igen med `php tools/build-release.php <version>`.
- Kør `bash tests/run.sh` igen, og kontrollér, at ZIP'en ikke indeholder `tests/`, `docs/specs/` eller `config.php`.
- Opdatér spec'ens statusovergange med `stopped → unsubscribed` (afvigelse 2), og tilføj `users.php` til trin 1's filliste (afvigelse 1).

## Nye automatiske tests (kun i `tests/`)

| Fil | Indhold |
|---|---|
| `tests/newsletter_funktionel.sh` | 55 tjek, der køres som en del af `bash tests/run.sh`: import (kriterie 6–14, 16, 28), manuel tilføjelse (17), afmelding (18–22, 24), stoppet modtager (afvigelse 2), modul slået fra (23), slet og eksport (25, 26), ingen adresser i URL'er (27), login-bruger og direkte link (2, 3, afvigelse 1). |
| `tests/newsletter_sql.php` | Lille hjælper, der kører ét SQL-udtryk mod testdatabasen. |
| `tests/make_xlsx.php` | Laver en xlsx, der ligner en Excel-fil (udeladte rækker, tomme rækker, formaterede tomme celler). |
| `tests/run.sh` | Ændret: læser `newsletter_funktionel.sh` ind i trin 4. |

De nye tests er kontrolleret mod fejl: i en kopi med tre bevidste fejl (afmeldingssidens CSRF-kontrol fjernet, stoppede kan ikke afmelde sig, sletning rydder ikke tokenet) fejler de relevante tjek (K21/K22, afvigelse 2 og K25).

## Genskab testen

```bash
# nulstil databasen
mariadb -h 127.0.0.1 -P 3306 -u root -e "DROP DATABASE IF EXISTS hsg_test; CREATE DATABASE hsg_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# fuld testpakke (kræver php med mysqli/pdo_mysql, zip, curl)
HSG_TEST_DB_HOST=127.0.0.1 HSG_TEST_DB_NAME=hsg_test HSG_TEST_DB_USER=root HSG_TEST_DB_PASS= bash tests/run.sh
```

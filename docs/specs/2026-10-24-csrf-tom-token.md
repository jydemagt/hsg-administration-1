# Spec: CSRF – et tomt session-token må aldrig godtages

- **Dato:** 2026-10-24
- **Status:** Udkast 1, venter på Michaels godkendelse
- **Type:** Sikkerhedsrettelse i kernen (`functions.php`). Ingen nye skærme, ingen databaseændring, intet modul og ingen migration.
- **Baggrund:** Fundet i testrapporten for nyhedsbrevet (`docs/specs/2026-10-19-nyhedsbrev.testrapport.md`, "Fund uden for trin 1") og bekræftet igen under PR #179, hvor en POST helt uden session gav HTTP 200 i stedet for 419.
- **Grundlag:** main `16cd3d0` (app-version 11.1.4)

---

## Åbne spørgsmål

1. **Udgivelse.** Skal rettelsen ud som en selvstændig patch-release med det samme, eller følge med i næste release sammen med PR #179?
   *Antagelse:* Den følger med i næste release. Denne PR løfter ikke versionen (se "Versionsbehov").
2. **Fund uden for scope.** `tools/restore-standalone.php` har hverken session eller CSRF, `logout.php` logger ud via GET, og `core/bootstrap.php` er en død fil med et søge-endpoint, der kan kaldes direkte (se tabellen). Skal de have hver deres opgave?
   *Antagelse:* De rettes ikke her. Mit forslag er én lille oprydningsopgave til `restore-standalone.php` og `core/bootstrap.php`. `logout.php` kan blive, som den er.

---

## Formål

`verify_csrf()` (`functions.php` linje 9) sammenligner formularens `csrf` med sessionens `csrf`. Hvis sessionen ikke har noget token, og formularen heller ikke sender et (eller sender et tomt), sammenlignes to tomme strenge. De er ens, så POST'en godtages. Det bryder CLAUDE.md regel 3.

**Hvem rammes i dag:** Kun sessioner, hvor der endnu ikke er vist en side med `csrf_field()`:
- **Administrator-login uden forudgående GET** (login-CSRF): `admin-login.php` godtager en POST uden token, og sessionen er bagefter logget ind uden token. I den session godtages POST'er uden `csrf` på alle administrationssider (testrapporten viste, at der blev oprettet en nyhedsbrevsmodtager).
- **Direkte link lige efter `?k=`:** linkbrugerens session er logget ind, men har intet token, før den første side med en formular er vist. Testrapporten viste, at en POST til `reservations.php` uden `csrf` blev godtaget.
- **`install.php` og `admin-setup.php`**, hvis der postes uden at formularen er hentet først.

**Risiko:** Lav. Session-cookien er `SameSite=Lax`, så en POST fra et andet websted sender ikke cookien i moderne browsere, og en almindelig browser henter altid formularen (og dermed et token), før den poster. Det er en svaghed i kernen, og den skal lukkes.

**Hvem mærker rettelsen:** Ingen almindelige brugere (admin, linkbruger eller lagermedarbejder) i en browser. Kun scripts og angribere, der poster uden token, får nu 419.

---

## Rettelsen (regel for `verify_csrf()`)

En POST godtages **kun**, når alle fire punkter er opfyldt:

1. Sessionen har nøglen `csrf`, og værdien er en **tekststreng** (ikke en liste, et tal, `null` eller andet).
2. Sessionens streng er **ikke tom**.
3. Formularfeltet `csrf` findes og er en **tekststreng** (ikke en liste som `csrf[]`).
4. Formularens streng er **ikke tom** og er lig med sessionens. Sammenligningen sker i konstant tid med `hash_equals` som i dag, og sessionens værdi er første argument.

Ellers afvises POST'en med **præcis det samme svar som i dag**: HTTP-status 419 og brødteksten `Ugyldig formularsession. Genindlæs siden og prøv igen.` (ren tekst uden HTML, uden ekstra headere og uden ændret ordlyd).

**Krav til implementeringen:**
- **Ingen typekonvertering af sessionens værdi.** Brug en typekontrol, ikke en `(string)`-cast. En cast gør en liste til teksten `Array` med en PHP-advarsel, og så godtages formularværdien `Array`. Forslaget i testrapporten fra 19-10 (`$s=(string)(...)`) har netop den svaghed og må ikke bruges ordret.
- **Ingen PHP-advarsler, notices eller TypeError** for nogen kombination af værdier. I dag giver en ikke-streng i sessionen en TypeError (HTTP 500), fordi `functions.php` har `strict_types=1`. Efter rettelsen giver den 419.
- `verify_csrf()` **skriver ikke i sessionen**. Den må fx ikke kalde `csrf_token()` og dermed oprette et token.
- Funktionsnavn, signatur (`verify_csrf(): void`), placering i `functions.php`, metodetjekket (kun `REQUEST_METHOD === 'POST'`) og 419-svaret er uændrede. Alle 10 eksisterende kald (`auth.php`, `admin-login.php`, `admin-setup.php`, `install.php`, `nyhedsbrev-afmeld.php` og 5 i `reports.php`) virker uden ændringer.
- **`csrf_token()` og `csrf_field()` ændres ikke** (se "Overvejet og fravalgt").
- **`auth.php` ændres ikke.** Rækkefølgen *vedligeholdelse → hooks → admin-udløb → `?k=` → login-tjek (401) → `verify_csrf()`* bevares. En POST uden login giver derfor fortsat 401, ikke 419.

### Sandhedstabel

| # | `$_SESSION['csrf']` | `$_POST['csrf']` | I dag | Efter rettelsen |
|---|---|---|---|---|
| 1 | mangler | mangler | **godtaget** | 419 |
| 2 | mangler | `''` (tom) | **godtaget** | 419 |
| 3 | `''` | `''` | **godtaget** | 419 |
| 4 | `''` | `'abc'` | 419 | 419 |
| 5 | mangler | `'abc'` | 419 | 419 |
| 6 | gyldigt token (48 hex-tegn) | samme token | godtaget | godtaget |
| 7 | gyldigt token | en anden streng | 419 | 419 |
| 8 | gyldigt token | mangler | 419 | 419 |
| 9 | gyldigt token | `''` | 419 | 419 |
| 10 | gyldigt token | liste (`csrf[]=…`) | 419 | 419 |
| 11 | liste, fx `['x']` | `'Array'` | **HTTP 500 (TypeError)** | 419 |
| 12 | tal, fx `123` | `'123'` | **HTTP 500 (TypeError)** | 419 |
| 13 | token oprettet af `csrf_token()` i en tom session | samme token | godtaget | godtaget |
| 14 | hvad som helst | hvad som helst, men GET-anmodning | ingen kontrol | ingen kontrol |

---

## Brugerflow

Der kommer ingen nye flows. De eksisterende er uændrede:

1. **Normal formular (alle roller):** Brugeren åbner en side (GET), og `csrf_field()` lægger et token i sessionen og i formularen. Brugeren sender formularen (POST), og den godtages som i dag.
2. **Formular uden token i en session uden token (nyt udfald):** POST'en afvises med 419 og teksten "Ugyldig formularsession. Genindlæs siden og prøv igen." Genindlæser brugeren siden, får sessionen et token, og næste POST virker. I en browser sker det kun, hvis noget har fjernet feltet fra formularen.
3. **Session udløbet mellem GET og POST:** uændret. Formularen sender sit gamle token, sessionen er ny og uden token, og POST'en afvises med 419 som i dag.
4. **Opgradering:** Eksisterende sessioner har allerede et token (fra den første viste formular) og virker videre uden nyt login.
5. **Ét-klik-afmelding (RFC 8058):** uændret. Mailprogrammet poster `List-Unsubscribe=One-Click` uden cookies og uden `csrf` og får 200 `Afmeldt`. Grenen ligger før enhver CSRF-kontrol og kalder ikke `verify_csrf()`.

---

## Skærme

Ingen nye eller ændrede skærme. 419-svaret er uændret ren tekst:

```
HTTP/1.1 419
Ugyldig formularsession. Genindlæs siden og prøv igen.
```

Afmeldingssiden (`nyhedsbrev-afmeld.php`) beholder sin egen pæne 419-side ("Formularen er udløbet. Åbn siden igen …"), fordi dens egen kontrol kører før `verify_csrf()`. Mobilvisning er ikke berørt.

---

## Data

Ingen nye eller ændrede tabeller eller kolonner. Ingen migration og intet løft af modulversioner.

---

## Rettigheder

- Ingen ny capability.
- Direkte links (`?k=`) har de samme rettigheder som før. En linksession uden token kan ikke længere poste (fx en reservation), før en side med en formular er vist. I browseren er det altid tilfældet, fordi reservationen sendes fra en formular med `csrf_field()`.
- Undtagelsen for ét-klik-afmelding (godkendt af Michael 19-10-2026) er uændret: kun `nyhedsbrev-afmeld.php`, kun med et gyldigt token og kun til afmelding.

---

## Gennemgang af alle POST-modtagere

Grundlag: grep efter `verify_csrf`, `$_POST`, `REQUEST_METHOD`, `php://input`, `session.php` og `session_start` i alle PHP-filer. Optællingen viser, at alle 24 filer i systemet med en `<form method="post">` har mindst lige så mange `csrf_field()`-kald som formularer. Kun `tools/restore-standalone.php` har en formular uden token (se tabellen). AJAX-kaldene (`image_check.php` → `image_action.php`, `products.php`/`product_edit.php` → `product_enrich.php` og `products.php` → `products.php`) sender `csrf` fra siden i FormData.

| Fil / flow | Kalder `verify_csrf()`? | Session? | Token vist før POST? | Ændrer rettelsen adfærden? | Er dagens adfærd tilsigtet? |
|---|---|---|---|---|---|
| **Alle sider bag `auth.php`** med POST-håndtering (21 filer: `admin-account`, `backup`, `brands`, `image_action` (AJAX), `image_check`, `import`, `locations`, `newsletter_import`, `newsletter_recipients`, `product_edit`, `product_enrich` (AJAX), `products`, `quality`, `reports`, `reservations`, `stock`, `supplier_upload`, `system`, `update`, `user_permissions`, `users`) | Ja, globalt i `auth.php` linje 75: **efter** login-tjekket (401) og **før** sidens `require_capability`/`require_admin` | Ja | Ja | Kun i en **indlogget session uden token** (direkte link lige efter `?k=`, eller login uden GET af login-siden): POST uden eller med tom `csrf` gav 200/302 og blev udført, nu 419. Uden session: 401 som før, fordi `verify_csrf()` aldrig nås. | Nej, en fejl |
| Sider bag `auth.php` **uden** POST-håndtering (fx `status.php`, `catalog.php`, `index.php`, `admin.php`, `newsletter.php`, `import_center.php`, `export.php`, `catalog_pdf.php`) | Ja, via `auth.php` | Ja | – | Som ovenfor: en POST uden token i en session uden token gav 200 (siden blev vist), nu 419. Ingen data ændres i nogen af tilfældene. | Nej |
| `reports.php` | Ja, også ekstra kald pr. handling efter `require_capability('reports.manage')` (overflødige) | Ja | Ja | Ikke ud over rækken ovenfor | – |
| `update.php` (updater) og `backup.php` | Ja, via `auth.php` | Ja | Ja | Ikke ud over rækken ovenfor. En opgradering midt i en session beholder sessionens token. | – |
| **`admin-login.php`** | Ja, første sætning i POST-grenen. Siden inkluderer ikke `auth.php`. Før den står kun `is_admin()` → redirect. | Ja (`session.php`) | Ja | **Ja:** POST uden forudgående GET (session uden token) og uden eller med tom `csrf` gav 200 (forkert login) eller 302 (logget ind), nu 419. Browserflowet er uændret. `tests/run.sh` henter token først. | Nej, login-CSRF |
| `admin-setup.php` | Ja, i POST-grenen. Før den: 302 til login, hvis der findes administratorer, og 403 uden gammelt admin-link. | Ja | Ja | Kun samme tilfælde som login. I praksis uændret, og siden kan ikke nås i røgtesten (der findes administratorer efter installationen). | Nej |
| **`install.php`** | Ja, første sætning i POST-grenen. Før den: findes `config.php`, vises "allerede installeret" (200) og `exit`. | **Ja**, `session.php` inkluderes i linje 3, før der er en database | Ja (`csrf_field()` i formularen) | **Ja**, før installationen: en POST uden session og token gik videre til installationslogikken, nu 419. Browserflowet (GET → formular → POST) er uændret. Efter installationen er der ingen ændring. | Nej |
| **`nyhedsbrev-afmeld.php`, ét klik** (`List-Unsubscribe=One-Click`, RFC 8058) | **Nej.** Grenen afslutter (200 `Afmeldt`), før der er nogen CSRF-kontrol. Beskyttet af et gyldigt 64-hex-token i `?t=` og kun til afmelding. | Session startes, men bruges ikke | Nej (mailprogrammet har ingen session) | **Nej** | **Ja**, godkendt undtagelse 19-10-2026 |
| `nyhedsbrev-afmeld.php`, formularen | Egen streng kontrol (kræver token i både formular og session, ellers egen 419-side). Derefter `verify_csrf()` | Ja | Ja | Nej, den egne kontrol afviser allerede. Kun kommentaren i linje 74-75 ("verify_csrf() godtager en tom formular …") bliver forkert. | Ja |
| `logout.php` | Nej (logud via GET-link) | Ja | – | Nej | Uden for scope |
| `cron/backup.php` | Nej. Beskyttet af `?key=` med `hash_equals`, og en tom nøgle afvises (403). Inkluderer ikke `session.php`. | Nej | – | Nej | Ja |
| `core/bootstrap.php` (`?action=quick_search`) | Nej. Kun GET, og ingen fil inkluderer den. | Ja | – | Nej | Død kode. Uden login er resultatet tomt (`can()` er falsk). Uden for scope. |
| `tools/restore-standalone.php` (lægges i backup-pakken som `restore.php`) | Nej, ingen session og ingen CSRF | Nej | Nej | Nej | Uden for scope (selvstændigt værktøj, der kører før systemet findes) |
| Direkte links `?k=` og reservationer (`status.php`, `reservations.php`) | Ja, via `auth.php` | Ja | Ja (`status.php` og `reservations.php` har `csrf_field()`) | Kun linksessionen uden token, se første række | Nej |
| `tests/newsletter_probe.php` | Indlæser `auth.php` med `REQUEST_METHOD=GET`, så `verify_csrf()` gør intet | – | – | Nej | – |

**Konklusion:** Ingen side eller flow afhænger bevidst af, at et tomt token godtages. Den eneste bevidste undtagelse fra CSRF (ét klik) kalder ikke `verify_csrf()` og er derfor upåvirket.

---

## Regressionstjek i `tests/run.sh`

### Hvorfor de eksisterende tjek ikke fanger fejlen
- Linje 89-90 (`locations.php`) og 97-100 (`newsletter_recipients.php`, `newsletter_import.php`) poster **med** admin-sessionen i `$JAR`. Den har et token, fordi login-siden er hentet først. De giver 419 både før og efter rettelsen.
- En POST **uden cookie** til en side bag `auth.php` giver 401 i `auth.php` linje 69-73, før `verify_csrf()` i linje 75 nås (det er linje 167 i `newsletter_post_input.sh`). Den kan heller ikke vise fejlen.

### Nye tjek (ingen eksisterende linjer ændres, og overskrifterne "1/4" til "4/4" omnummereres ikke)

**Blok A – `install.php` før installationen.** Indsættes mellem linje 54 (`token()`) og linje 56 (`T=$(token "$BASE/install.php")`).
- POST til `install.php` **uden cookie-fil** og uden `csrf`, kun med et harmløst felt (fx `db_name=csrf-test`) og **uden** `db_user`. Så kan anmodningen aldrig gennemføre en installation, heller ikke hvis rettelsen mangler. Forventet: 419, og `config.php` findes ikke bagefter.
- Uden rettelsen giver den 200 med fejlen "Database, databasebruger … skal udfyldes".

**Blok B – sessioner uden token.** Indsættes efter linje 90 og før `# Nyhedsbrev: administrationssider …` (linje 92). Brug nye midlertidige cookie-filer (fx `mktemp`), og slet dem i slutningen af blokken.
1. **Reference:** POST `x=1` til `locations.php` med `$JAR` (som linje 89), og gem brødteksten. Den er dagens 419-svar.
2. **`admin-login.php` uden cookie og uden `csrf`** (`-d username=csrf-test -d password=forkert`): 419, brødteksten er byte-identisk med referencen (`cmp -s`), og `php tests/newsletter_sql.php "SELECT COUNT(*) FROM lager_login_attempts WHERE username='csrf-test'"` giver `0`. Det viser, at login-logikken ikke blev nået. Uden rettelsen: 200 og én række.
   *Hvorfor `admin-login.php`:* Den inkluderer ikke `auth.php`, så der kommer ingen 401 først. `is_admin()`-redirect sker ikke uden cookie, og `verify_csrf()` er første sætning i POST-grenen. Den rammer altså fejlen direkte.
3. **`admin-login.php` uden cookie med tomt `csrf=`** (`-d csrf= -d username=csrf-test -d password=forkert`): samme forventning som 2.
4. **`admin-login.php` uden cookie og helt uden body** (`-X POST`): 419.
5. **Linksession uden token:** Tag linket fra installationssiden (`grep -o '?k=[a-f0-9]\{64\}' /tmp/hsg-install.html | head -1`). Hent `$BASE/<link>` med en ny, tom cookie-fil **uden `-L`**, så viderestillingen ikke følges, og sessionen derfor ikke får et token. Forventet: 302. Post derefter `x=1` til `status.php` med den cookie-fil. Forventet: 419 og byte-identisk brødtekst.
   *Hvorfor `status.php`:* Linket fra installationen har `inventory.view`. Siden har ingen POST-håndtering, så den gav 200 uden rettelsen og ændrer intet i nogen af tilfældene. `verify_csrf()` i `auth.php` kører efter login-tjekket, som linksessionen består.
6. **Linksession uden token med tomt `csrf=`:** som 5, men med en **ny** cookie-fil og et nyt `?k=`-kald (sessionen fra 5 kan have fået et token, hvis rettelsen mangler). Forventet: 419.
7. **Kontrol (positiv):** Brug en ny linksession, hent `status.php` (GET, så sessionen får et token), læs `csrf` fra siden, og post `x=1` med det til `status.php`. Forventet: 200. Linkbrugere med token virker altså som før.

**Blok C – enhedstest uden database.** Indsættes i trin 2 lige efter linje 31 som en ekstra `if`-blok i samme stil: `php tests/csrf_test.php`, og ved succes `ok "$out"`, ellers `bad "Enhedstest af verify_csrf fejlede"`. Trinnet kører også i CI uden database.
- Ny fil `tests/csrf_test.php` (kun i `tests/`, kommer ikke med i release). Den afvises med 404, hvis den ikke køres fra CLI, som `tests/newsletter_test.php`.
- Den dækker alle 14 rækker i sandhedstabellen (række 14 med `REQUEST_METHOD=GET`).
- `verify_csrf()` afslutter med `exit`, så hvert tilfælde køres i **sin egen PHP-proces** (fx kalder filen sig selv med `PHP_BINARY` og et tilfældenummer). Miljøet arves, så `PHPRC` følger med. Underprocessen kører med `error_reporting=E_ALL` og `display_errors=1` (til stdout), sætter `$_SERVER['REQUEST_METHOD']`, `$_SESSION` og `$_POST`, indlæser `functions.php`, kalder `verify_csrf()` og skriver en fast markør, hvis kaldet vender tilbage.
- **Afvist** betyder, at outputtet er præcis 419-teksten og intet andet. **Godtaget** betyder, at outputtet er præcis markøren. Alt andet (advarsel, TypeError, Fatal) er en fejl.
- Udskrift som nyhedsbrevstesten: `CSRF: <n> bestået, <m> fejlet`, exit 0 ved 0 fejl.

### Konflikter med PR #179
PR #179 (`feature/mbstring-krav`) kan også ændre `tests/run.sh` og versionsnummeret. For at undgå konflikter:
- Denne PR ændrer ingen eksisterende linjer i `tests/run.sh`. Den tilføjer kun de tre blokke ovenfor og omnummererer ikke trinnene.
- Denne PR løfter ikke versionen og regenererer ikke `hsg-package.json`.
- Den PR, der merges sidst, rebases på main og kører `bash tests/run.sh` igen. En eventuel konflikt i `run.sh` er så kun to indsættelser tæt på hinanden og løses ved at beholde begge.

---

## Versionsbehov

- **Hvor versionen styres:** `app_version.php` (i dag `'11.1.4'`) og `hsg-package.json` (`"version": "11.1.4"`, bygget 19-10-2026). `README.txt` vedligeholdes ikke pr. version (overskriften siger stadig v1.3.5). Modulversionerne i `modules/*/module.php` styrer kun migrationer og berøres ikke.
- **Hvordan der udgives:** `.github/workflows/release.yml` bygger ved et tag `v*.*.*` og kræver, at `app_version.php` passer med tagget. Nyhedsbrevets PR'er (#17x) er merget uden versionsløft, og versionen har været 11.1.4 siden 19-10. Release-tjeklisten i CLAUDE.md gælder altså, når der udgives en ny version, ikke for hver PR.
- **Vurdering:** Rettelsen er en **patch** (11.1.x): en fejlrettelse uden ny funktion og uden databaseændring, og den er bagudkompatibel.
- **Forslag:** Ingen versionsløft i denne PR. Næste release, enten sammen med #179 eller som selvstændig hotfix (åbent spørgsmål 1), løfter versionen én gang, regenererer `hsg-package.json` med `php tools/build-release.php <version>` og følger tjeklisten.

---

## Påvirkede filer

| Fil | Ændring |
|---|---|
| `functions.php` | Kun `verify_csrf()` (linje 9) efter reglen ovenfor. `csrf_token()` og `csrf_field()` er uændrede. |
| `tests/csrf_test.php` | Ny: enhedstest af sandhedstabellen (blok C). |
| `tests/run.sh` | Tre nye blokke (A, B, C). Ingen eksisterende linjer ændres. |
| `nyhedsbrev-afmeld.php` | **Kun kommentaren** i linje 74-75. Den skal ikke længere påstå, at `verify_csrf()` godtager en tom formular, men forklare, at siden har sin egen kontrol for at kunne vise sin egen 419-side med et link. Ingen logik ændres. |
| `docs/ARCHITECTURE.md` | Én sætning under "Sikkerhed", fx: "En POST afvises (419), hvis sessionen ikke har et token, også når formularen heller ikke sender et." |

**Ændres ikke:** `auth.php`, `session.php`, `admin-login.php`, `install.php`, `admin-setup.php`, `app_version.php`, `hsg-package.json`, `README.txt`, modulmanifester og migrationer.

---

## Acceptkriterier

1. **Givet** `tests/csrf_test.php`, **når** `php tests/csrf_test.php` køres, **så** består alle 14 tilfælde i sandhedstabellen, udskriften er `CSRF: <n> bestået, 0 fejlet`, exit-koden er 0, og ingen underproces skriver `Warning`, `Notice`, `Deprecated`, `TypeError`, `Fatal` eller `Uncaught`.
2. **Givet** en session med en liste i `$_SESSION['csrf']`, **når** der postes `csrf=Array`, **så** afvises POST'en med 419 uden PHP-advarsel (tilfælde 11). Det beviser, at sessionens værdi ikke konverteres til tekst.
3. **Givet** at der ikke er sendt nogen cookie, **når** der postes til `admin-login.php` uden et `csrf`-felt (med `username=csrf-test` og `password=forkert`), **så** svarer siden 419, brødteksten er byte-identisk med dagens 419-svar (referencen fra `locations.php` med admin-sessionen), og der er ingen række i `lager_login_attempts` med `username='csrf-test'`.
4. **Givet** at der ikke er sendt nogen cookie, **når** der postes til `admin-login.php` med `csrf=` (tomt) og de samme felter, **så** gælder det samme som i kriterie 3.
5. **Givet** at der ikke er sendt nogen cookie, **når** der sendes en tom POST (`-X POST`) til `admin-login.php`, **så** svarer siden 419.
6. **Givet** en ny linksession fra installationens direkte link, hentet uden at følge viderestillingen (302, intet token), **når** der postes `x=1` til `status.php` uden `csrf`, **så** svarer siden 419 med den byte-identiske brødtekst.
7. **Givet** en anden ny linksession uden token, **når** der postes til `status.php` med `csrf=` (tomt), **så** svarer siden 419.
8. **Givet** en ny linksession, hvor `status.php` er hentet med GET, **når** der postes til `status.php` med sidens `csrf`, **så** svarer siden 200. Linkbrugere med token virker som før.
9. **Givet** at systemet ikke er installeret (ingen `config.php`), **når** der postes til `install.php` uden cookie og uden `csrf` (kun `db_name=csrf-test`), **så** svarer siden 419, og `config.php` er ikke oprettet. Den efterfølgende normale installation i `run.sh` giver "Installation gennemført".
10. **Givet** de normale browserflows i `run.sh` (installation og admin-login, hvor token hentes med GET først), **når** testpakken køres, **så** giver de fortsat "Installation gennemført" og "Admin-login lykkedes".
11. **Givet** at der ikke er sendt nogen cookie, **når** der postes til en side bag `auth.php` (fx `newsletter_recipients.php`), **så** svarer den fortsat **401**, ikke 419. Det eksisterende tjek "uden login giver listefelter 401" i `newsletter_post_input.sh` og tjekkene i `run.sh` linje 85-96 er grønne.
12. **Givet** et gyldigt afmeldingstoken, **når** mailprogrammet poster `List-Unsubscribe=One-Click` uden cookies og uden `csrf`, **så** svarer siden 200 `Afmeldt`, og modtageren er afmeldt med `via=one_click`. Det svarer til de eksisterende tjek K20, "Afv2 stoppet modtager" og "K23 modul slået fra", og de er grønne.
13. **Givet** afmeldingsformularen, **når** den sendes med et gyldigt token fra siden, **så** afmeldes modtageren (K19). **Når** den sendes uden, med tomt eller med forkert token, **så** svarer siden 419 med sin egen side (K22). Begge er grønne og uændrede.
14. **Givet** CI (`.github/workflows/tests.yml`, MySQL 8.0), **når** `bash tests/run.sh` køres, **så** er alle eksisterende og nye tjek grønne, og serverloggen har ingen `Fatal`/`Uncaught` (run.sh linje 127).
15. **Givet** lokal kørsel med MariaDB 10.4 fra XAMPP, **når** `bash tests/run.sh` køres, **så** er det eneste røde tjek "KAN afmeldingssiden ved databasefejl" (`RENAME COLUMN` findes ikke i MariaDB 10.4). Det fejler på samme måde på main `16cd3d0`.
16. **Givet** en kopi af grenen, hvor kun `functions.php` er sat tilbage til main `16cd3d0`, **når** testpakken køres, **så** fejler de nye tjek i kriterie 3-7 og 9 (200 i stedet for 419), og enhedstestens tilfælde 1, 2, 3, 11 og 12 fejler. De nye tests fanger altså fejlen. Testeren skriver resultatet i testrapporten.
17. **Givet** diff'en for PR'en, **når** den gennemgås, **så** er `verify_csrf()` den eneste ændrede funktion i `functions.php`, `csrf_token()` og `csrf_field()` er byte-identiske, `nyhedsbrev-afmeld.php` er kun ændret i kommentarlinjer, `auth.php` er uændret, og ingen eksisterende linje i `tests/run.sh` er ændret.
18. **Givet** release-negativtesten (`php tools/build-release.php --test-negative`), **når** den køres, **så** er den grøn, og `tests/csrf_test.php` ligger under `tests/`, som bygget fjerner.
19. **Givet** en session, der allerede har et token (fx logget ind før opdateringen), **når** der postes med det token efter opdateringen, **så** godtages POST'en. Der kræves ikke nyt login.

---

## Lokale testforhold (til udvikler og tester)

- Testene kræver en `php.ini` via `PHPRC` med `mbstring`, `zip`, `pdo_mysql`, `openssl`, `gd`, `fileinfo` og `curl`.
- MariaDB fra XAMPP kan bruges som testdatabase (`HSG_TEST_DB_*`). Brug en tom database, aldrig produktionsdatabasen.
- Tjekket "KAN afmeldingssiden ved databasefejl" i `tests/newsletter_funktionel.sh` (linje 224-227) bruger `ALTER TABLE … RENAME COLUMN`, som MariaDB 10.4 ikke har. Det fejler derfor lokalt uafhængigt af denne rettelse. I CI (MySQL 8.0) er det grønt.
- Røgtesten skal køres i en ren kopi uden `config.php` (git worktree), som `run.sh` selv kræver.

---

## Overvejet og fravalgt

- **At gøre `csrf_token()` robust over for en ikke-streng værdi** (fx gendanne tokenet, hvis sessionen indeholder en liste). Det er fravalgt, fordi `csrf_token()` er den eneste, der skriver `$_SESSION['csrf']` (grep), og den skriver altid 48 hex-tegn. Tilfældet kan ikke opstå i praksis. Skulle det alligevel ske, får brugeren 419 indtil næste login i stedet for dagens HTTP 500.
- **At give 419 før 401 i `auth.php`.** Fravalgt. 401 uden login er det rigtige svar og er dækket af eksisterende tests.
- **At fjerne afmeldingssidens egen CSRF-kontrol, nu hvor `verify_csrf()` er lige så streng.** Fravalgt. Den egne kontrol viser en pæn side med et link til at prøve igen, og den kræver ingen ændring.
- **En pænere HTML-side for 419 på login-siden.** Fravalgt. Svaret skal være præcis som i dag.

---

## Uden for scope

- `tools/restore-standalone.php` har hverken session eller CSRF (selvstændigt restore-værktøj i backup-pakken). Se åbent spørgsmål 2.
- `logout.php` logger ud via GET (logout-CSRF).
- `core/bootstrap.php`: en død fil med et JSON-søge-endpoint (GET), som ingen fil inkluderer, men som kan kaldes direkte. Uden login er resultatet tomt.
- De overflødige ekstra `verify_csrf()`-kald i `reports.php`.
- Rotation af token pr. anmodning eller efter login, `SameSite=Strict` og kontrol af `Origin`/`Referer`.
- Opdatering af `README.txt`, som ikke følger app-versionen.
- Versionsløft og regenerering af `hsg-package.json` (hører til release-PR'en).

# Testrapport: Nyhedsbrev, felter sendt som lister og oprydning af forhåndsvisningen

- **Branch:** `feature/nyhedsbrev-post-input`, testet på commit `e1c0726` (én commit oven på `2ec1d90`, PR #173). Lokal `main` er forældet (`be131df`), så alle sammenligninger er mod `2ec1d90`. Ingen versionsløft og ingen release.
- **Testet:** 22-10-2026, af uafhængig tester. Udviklerens tal er gentaget, ikke brugt som bevis.
- **Miljø:** PHP 8.5.8 (`php -S`, `display_errors=On`, `E_ALL`), MariaDB 11.4 (tom database pr. kørsel), Windows/Git Bash, kørt i en kopi uden for OneDrive.
- **Ikke testet:** PHP 8.1 og rigtige browsere (ingen HTML er ændret, se kriterie 3).

## Konklusion

**KLAR.**

- **6 af 6 kriterier er bestået.**
- **Ingen SKAL-fund.** **2 BØR** (begge om testpakken og en eksisterende fejl uden for ændringen) og 4 KAN (se "Fund").
- Jeg har fundet et testhul, som er lukket: udviklerens test af listefelter fanger ikke fejlen i dette miljø (BØR 1). Mine nye tests fanger den.

## Kørsler

| Kørsel | Resultat |
|---|---|
| `bash tests/run.sh` på `e1c0726` (uden mine tilføjelser) | grøn: 141 enhedstest, 126 OK-linjer, 0 FEJL (som udvikleren angiver) |
| `bash tests/run.sh` med mine tilføjelser | **grøn:** 141 enhedstest, **149 OK-linjer** (126 + 23 nye), 0 FEJL |
| Mutation 1: `newsletter_recipients.php` og `newsletter_import.php` tilbage til `2ec1d90` | 11 tests fejler: 9 af mine (id[], skæve id'er, listefelter, kontrol, linkbruger) og 2 af udviklerens 3 nye tests; testen "felter sendt som lister giver hverken HTTP 500 eller PHP-advarsler" er **fortsat OK** (BØR 1). |
| Mutation 2: `modules/newsletter/hooks.php` slettet | 10 tests fejler: udviklerens `products.php`-test, to ældre tests og 7 af mine. |
| Gammel kode (`2ec1d90`) kørt i hånden | `stop` med `id[]=5` stoppede modtager 1. `GET ?preview[]=abc` gav "Array to string conversion". Fejlene var altså rigtige. |

## Acceptkriterier

| Nr. | Kriterie | Resultat | Bevis |
|---|---|---|---|
| 1 | Listefelter giver ingen advarsler eller HTTP 500 | BESTÅET | Alle felter fra kriteriet sendt som `f[]=x`, `f[a][b]=x` og `f[]=` med gyldigt CSRF-token: recipients (7 felter × create og update = 42 POST) og import (8 felter × remap, `apply` med `action[]`/`preview_token[]`, `analyse` med `mode[]`/`action[]`, `file[]` med flere filer). GET `preview[]`, `preview[a][b]`, `filter[]`, `id[]` og `id[]=&filter[]=`. `csrf[]` giver 419. Alle svar HTTP 200 eller 419, ingen advarsel i svaret, ingen nye linjer i serverloggen. Rettelsen virker: `mode[]` giver standardværdien, og `preview_token[]` giver "Forhåndsvisningen er udløbet". Også gjort i hånden med `curl -g`. |
| 2 | `id[]=X` rammer aldrig en modtager | BESTÅET | Modtager 1 og 4 aktive, 2 stoppet, 3 afmeldt. 6 handlinger (`delete`, `stop`, `unsubscribe`, `reactivate`, `export`, `update`) × 9 id-former (`id[]=4`, `id[]=1`, `id[0]=4`, `id[a]=4`, `id[]=1&id[]=4`, `id=4x`, `id=-1`, `id=1.0`, `id=99999999999999999999`) = 54 POST. Hver gang: den rigtige fejltekst ("Kun aktive modtagere kan stoppes." osv.), og et øjebliksbillede af alle modtagerrækker og antallet af audit-linjer er uændret. Kontrol: `stop id=4` ændrer modtageren, så testen kan se en ændring. `id=0`, tomt og `id=%204` rammer heller ikke noget. En direkte linkbruger får 403 på `delete` med `id[]=1` (også med gyldigt CSRF). Uden login: 401. |
| 3 | Normale flows virker som før, `run.sh` grøn | BESTÅET | Se "Kørsler". 12 sider (liste, 4 filtre, redigér #1–4 og #999, import, `newsletter.php`) sammenlignet byte for byte med `2ec1d90` på samme database og session (CSRF og afmeldingslink normaliseret): **11 identiske** (redigér #4 afviger kun, fordi min kopi af `2ec1d90` ikke har krypteringsnøglen). Dermed er layout og mobilvisning uændret. Normal import i hånden: upload, remap og `apply` gav 3 nye modtagere. Specialtegn (`<script>`, `'`, `"><img onerror>`, æøå) oprettes og vises escapet. 5.000 `Æ` i navn og firma afkortes til 180. Mail på 300 tegn og tomme felter giver "ugyldig". POST uden CSRF: 419. |
| 4 | Udløbet forhåndsvisning slettes på alle sider bag `auth.php` | BESTÅET | Forhåndsvisning lagt i sessionen med `tests/newsletter_session_put.php`. **Admin, 7.300 s:** slettet på `products`, `admin`, `stock`, `status`, `reservations`, `catalog`, `reports`, `users`, `system`, `locations`, `brands`, `quality`, `import_center`, `supplier_upload`, `import`, `image_check` og `admin-account` (HTTP som uden forhåndsvisning). **7.100 s:** bevaret på 7 sider. **Linkbruger** (`?k=`): slettet på `status`, `catalog`, `reservations`, `stock` (403) og `products` (403), og også ved et ugyldigt link (403). Bevaret ved 7.100 s. Grænse i hånden: 7.200 s bevares, 7.201 s slettes. En POST uden CSRF (419) sletter også, fordi hooks indlæses før `verify_csrf()`. 11 skæve værdier i `$_SESSION['newsletter_import']` (`"abc"`, `0`, `false`, `[]`, `created` som tekst, liste, negativ, 1.5e300 …): alle slettes uden advarsel. **Ikke indlæst uden forhåndsvisning:** `tests/newsletter_probe.php` kører `auth.php` med den rigtige session: uden forhåndsvisning `nl=nej`; med frisk `nl=ja, preview=ja`; med udløbet `nl=ja, preview=nej`. Samme resultat som `2ec1d90` for de øvrige filer (`product_enrichment.php` og `groq.php` er allerede indlæst uden forhåndsvisning). |
| 5 | `hooks.php` kan ikke give fejl på andre sider | BESTÅET | **Navnekonflikter:** alle 374 funktioner og 10 konstanter på øverste niveau i filer uden for `tests/`, sammenlignet uden hensyn til store/små bogstaver: **0 dubletter** (kun JavaScript-funktionen `enrichRequest` står to steder). Ingen funktion er pakket ind i `function_exists`. De fire filer indlæses overalt med `require_once`, og stierne peger på samme fil. Filerne har kun funktioner og konstanter på øverste niveau, så indlæsning inde fra `hsg_load_module_hooks()` giver ingen variabelproblemer. Ingen sessionsnøgle kolliderer. **32 sider** (alle sider i roden med GET) med ingen, frisk og udløbet forhåndsvisning: samme HTTP-kode og ingen PHP-fejl i alle 96 kald. Det gælder `import.php`, `supplier_upload.php`, `users.php` og `product_enrich.php`, som selv indlæser filerne. POST til `supplier_upload.php` (upload og preview, med `Deprecated` skjult, se "Til info") og `import.php` med forhåndsvisning i sessionen virker. **Modulet slået fra** (via `system.php`): `nl=nej`, forhåndsvisningen bliver liggende (hooks ikke indlæst), `products`, `supplier_upload` og 14 andre sider svarer 200 uden fejl, nyhedsbrevssiderne og afmeldingssiden 404. Slået til igen: rydder op igen. |
| 6 | Dokumentation og dansk tekst | BESTÅET | `docs/NEWSLETTER.md` linje 55 og specen linje 624 og 1282 stemmer med koden (`modules/newsletter/hooks.php`, indlæst af `auth.php`, når modulet er slået til). Ingen anden sætning i dokumenterne siger, at kun *Import*/*Modtagere* rydder op. Kommentarer, fejltekster og testtekster er på dansk. Se KAN 3 om formuleringen "en side i systemet". |

## Fund

Ingen SKAL.

**BØR 1. Udviklerens listefelt-test er blind i dette miljø.** `tests/newsletter_funktionel.sh` ("Opfølgning: felter sendt som lister giver hverken HTTP 500 eller PHP-advarsler") og røgtestens `grep 'Warning:'` leder efter `Warning:`. `php -S` skriver her `<b>Warning</b>:  Array to string …` (`html_errors=1`), og serverloggen er tom, fordi fejlene vises i svaret. Resultat: testen er OK mod den gamle kode (mutation 1). Med `-d html_errors=0` fejler den, som den skal. Rettelsen er at sætte `-d html_errors=0` på `php -S` i `tests/run.sh`, men **først når BØR 2 er rettet**, ellers bliver pakken rød. Jeg har ikke ændret flaget. Mine tests bruger et regulært udtryk, der matcher begge formater, og fanger fejlen uanset.

**BØR 2. Eksisterende fejl, ikke fra denne gren: `stock.php` linje 75 giver `Warning: Undefined array key "status"`** uden `?status=` (samme på `2ec1d90`, fra v10.4.0). Følgen er, at standardfilteret "aktive" ikke bruges: `$statusFilter` bliver `null`, og siden viser alle undtagen udgåede. Advarslen vises kun med `display_errors=On`. Skal rettes for sig selv (fx `$_GET['status']??'active'` i den sidste gren).

**KAN 1.** `update` med en liste i `name[]`, `company[]` eller `segment[]` behandler listen som tom/standard, gemmer og siger "Ændringerne er gemt.": navn og firma blev slettet, og en Forhandler blev til Kunde (afprøvet på modtager 4). Kun admin med gyldigt CSRF, og før gemte siden teksten "Array". Bedre at afvise en liste med en fejltekst i `update`.

**KAN 2.** `self_requested[]=1` tæller som bekræftelse (`!empty`), som enhver anden værdi i feltet. Ingen ny rettighed.

**KAN 3.** Oprydningen kører kun på sider bag `auth.php` og kun, når modulet er slået til. `admin-login.php`, `admin-setup.php`, `install.php` og `nyhedsbrev-afmeld.php` rydder ikke op, og er modulet slået fra, bliver en udløbet forhåndsvisning liggende, til sessionen udløber. Logout ødelægger sessionen. Dokumentationens "en side i systemet" er lidt bredt; "en side efter login" er mere præcist.

**KAN 4.** Mens en frisk forhåndsvisning (højst 2 timer) ligger i sessionen, indlæser hver side `core/newsletter.php`, `core/supplier_import.php` og `core/access_links.php`. Ingen fejl i mine kørsler. `hooks.php` kunne tjekke alderen først og kun indlæse filen, når forhåndsvisningen er udløbet. Kriterie 4 kræver det ikke.

*Til info (ikke fra grenen):* `core/supplier_import.php` linje 51 giver `Deprecated: fgetcsv()` på PHP 8.4 og nyere. Med `display_errors=On` ødelægger det omdirigeringen efter en upload i `supplier_upload.php`. Samme på `2ec1d90`, og produktion (PHP 8.1) er uberørt.

## Tilføjede tests (kun i `tests/`)

- `tests/newsletter_post_input.sh` (23 OK-linjer): læses ind fra `tests/run.sh` efter `newsletter_funktionel.sh`.
  - Listefelter i alle felter på begge sider (42 + 24 + 6 + 4 kald, GET, `csrf[]`, `file[]`).
  - 54 id-angreb med øjebliksbillede af modtagere og audit-antal.
  - Oprydning på 17 sider (admin), 7 sider med 7.100 s, linkbruger, ugyldigt link og POST uden CSRF.
  - Direkte linkbruger og uden login.
  - Sondering af `core/newsletter.php`: ingen, frisk og udløbet forhåndsvisning, modulet slået fra og til.
  - Sammenligner hver side med en basislinje uden forhåndsvisning, så `stock.php`-advarslen (BØR 2) ikke bliver tilskrevet `hooks.php`.
  - Værn: kører kun mod databasen i `HSG_TEST_DB_NAME`.
- `tests/newsletter_session_put.php`: lægger en forhåndsvisning i (eller fjerner den fra) en sessionsfil ud fra en cookiefil, så en linkbrugers session kan prøves.
- `tests/newsletter_probe.php`: kører `auth.php` med en given session og skriver, om `core/newsletter.php` blev indlæst, og om forhåndsvisningen findes.
- `tests/run.sh`: én ny `source`-linje.
- Mine tests fejler under begge mutationer (se "Kørsler") og er grønne på branchen.

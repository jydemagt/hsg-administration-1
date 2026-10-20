# Testrapport: Nyhedsbrev, review-rettelser efter trin 1

- **Branch:** `feature/nyhedsbrev-review-rettelser`, testet på commit `902561d` (PR #173). Ingen versionsløft og ingen release.
- **Testet:** 20-10-2026, af uafhængig tester. Udviklerens egne målinger og mutationstjek er ikke brugt som bevis; de er gentaget (se nedenfor).
- **Miljø:** PHP 8.5.8 (`php -S`, `memory_limit=128M`), MariaDB 11.4 (tom database pr. kørsel). PR'ens CI (PHP 8.2.34, MySQL 8.0) er også grøn: 118 enhedstest og "ALLE TESTS BESTÅET".
- **Ikke testet:** PHP 8.1 (kun 8.5 lokalt og 8.2 i CI) og rigtige Excel-filer fra Excel eller LibreOffice (repoet har ingen; filerne er lavet med generatorer, der ligner Excel). Michael bør åbne sin egen kundeliste én gang, før næste import i drift.

## Konklusion

**KLAR.**

- **12 af 12 kriterier er bestået.** Ingen eksisterende trin 1-kriterier er brudt (K6, K9, K11, K25 og K27 er grønne i `run.sh`, og K6, K9 og K27 er desuden afprøvet i hånden).
- **Ingen SKAL-fund.** **1 BØR** og 5 KAN (se "Fund").
- BØR-fundet er et hul i selve afkortningen til 320 tegn (kriterie 5), ikke en fejl i rettelsen. Det anbefales rettet i næste omgang, men blokerer ikke.
- Jeg har fundet et testhul og lukket det: ingen test fangede, at siderne faktisk kalder `hsg_newsletter_preview_expire()`. Se "Tilføjede tests".

## Kørsler

| Kørsel | Resultat |
|---|---|
| `bash tests/run.sh` (branchen som den er, fuld røgtest mod tom database) | grøn: 118 enhedstest, 108 OK-linjer, 0 FEJL |
| `bash tests/run.sh` (med mine tilføjelser) | **grøn:** 120 enhedstest, 119 OK-linjer, 0 FEJL |
| Mutation: begge kald til `hsg_newsletter_preview_expire()` fjernet fra siderne | kun mine 2 nye tests fejler. Udviklerens suite var grøn (hullet). |
| 12 mutationer i `core/newsletter.php` (række, kolonne, celle, udpakket, tekst, delte strenge, escape-tegn, upload, `rPh`, tomme CSV-rækker, `statName`-summen, `preview_expire` som no-op) | mindst én enhedstest fejler hver gang |
| `$escape` sat til standard | fangedes kun af en `Deprecated`-linje på PHP 8.5. Fanges nu af min nye test på alle versioner. |

## Acceptkriterier

| Nr. | Kriterie | Resultat | Bevis |
|---|---|---|---|
| 1 | Upload over 5 MB: flash, ikke 500 | BESTÅET | CSV på 5 MB + 1 byte og xlsx på 5 MB + 1: HTTP 200 og "Filen er for stor (højst 5 MB)." Præcis 5 MB afvises ikke på størrelsen. 17 MB (over `upload_max_filesize`): den gamle tekst "…for stor til at blive uploadet." |
| 2 | xlsx over 30 MB udpakket afvises før udpakning, aldrig 500 | BESTÅET | Ark på 40 MB, delte strenge på 40 MB, 20 + 12 MB, 200 MB og 50 ark à 1,5 MB: alle giver "Excel-filen fylder for meget, når den pakkes ud. …" på **0,00 s og 2 MB RAM**, HTTP 200. Zip-kataloget forfalsket (påstår 2.000 byte, arket er 40 MB): "Excel-filen kunne ikke læses", 2 MB RAM (PHP læser højst den påståede størrelse). DOCTYPE med eksterne entiteter (`file:///…`): intet læses ind. Billion laughs: afvist på 0,01 s. |
| 3 | CSV med egen `fgetcsv`-løkke, stopper ved række 5.001. `core/supplier_import.php` uændret | BESTÅET | `git diff main...HEAD -- core/supplier_import.php` er tom (0 byte). 5.000 rækker godtages, 5.001 afvises ("Filen har mere end 5.000 rækker."), også med 500 tomme rækker til sidst (godtaget) og tomme rækker midt i (godtaget). 700.000 rækker: afvist på 0,01 s. |
| 4 | xlsx læses én gang, `hsg_newsletter_xlsx_row_numbers` væk, rækkenumre er Excels | BESTÅET | Funktionen findes ikke (`grep`). Ét `statName`-gennemløb, én `getFromName` pr. ark. Fil med titelrække, udeladt række, tom række og række med kun formaterede tomme celler: rækkenumre 1, 4, 5, 8, 9, "overskrift i række 4", ugyldige i række 5 og 8. Celler uden `r`, række uden `r`, præfikset navnerum (`x:worksheet`, også `sharedStrings`), rich text og fonetiske afsnit: korrekt. K6 og K9 grønne. |
| 5 | Højst 30 kolonner og 320 tegn pr. celle, vist og i sessionen | BESTÅET | Fil med 40 kolonner og celler i `XFD`: række på 30 celler, sidste `AD`, `AE` og `XFD` væk. Celle på 1.000 `æ`: 320 tegn, gyldig UTF-8. Sessionsfilen aflæst: `maxkol=30`, `maxcelle=320`. Fil på 5.000 × 30 celler à 60 tegn (16 MB XML): analyse 2,3 s, forhåndsvisning 0,4 s, import af 4.999 modtagere, alt under `memory_limit=128M`. |
| 6 | Udløbet forhåndsvisning (over 7.200 s) slettes uanset token | BESTÅET | Forhåndsvisningens alder sat med `tests/newsletter_session.php` og sessionen aflæst. 7.300 s: slettet ved `GET newsletter_import.php` uden token og ved `GET newsletter_recipients.php`. 7.199–7.200 s: bevaret. 7.201 s: slettet. Gyldig forhåndsvisning med et andet token (GET `?preview=forkert` og POST `cancel`): **ikke** slettet. Udløbet forhåndsvisning med rigtigt token i POST `apply`: ingenting importeres, "Forhåndsvisningen er udløbet". |
| 7 | "gemmes aldrig i databasen", ikke "læses aldrig ind" | BESTÅET | `newsletter_import.php` linje 131 og `docs/NEWSLETTER.md` linje 36. Se KAN 3 om specen. |
| 8 | Ordlyd om hash | BESTÅET | `grep -riE "uigenkend|anonymiser"`: kun "uden automatisk anonymisering" (om afmeldte, ikke om hashen) og "…er ikke anonym". Flash, `confirm()` og hjælpetekst siger "Mailadressen gemmes kun som en hash (SHA-256), så den ikke importeres igen." Spærringen virker (K11 og K25 grønne). |
| 9 | KAN-punkterne | BESTÅET | **Link:** `<td><a href="newsletter_recipients.php?id=N">`, ingen `@` i nogen `href`/`src`/`action` (K27 grøn). På 375 px (Chrome i iframe): siden `scrollWidth = clientWidth`, tabellen scroller i `.table-wrap` (1324 px i 334 px), også med en adresse på 132 tegn. **Afmelding:** kolonne omdøbt og InnoDB-låsetimeout: HTTP 500 på GET, POST og ét klik, teksten "Afmeldingen kunne ikke gennemføres lige nu på grund af en teknisk fejl. …", ingen SQL, sti eller PHP-fejl. Status uændret (`active`). `Referrer-Policy`, `X-Robots-Tag`, `Cache-Control: no-store` og `X-Content-Type-Options` sendes. Se KAN 1 for et hjørne. **Migration:** `CREATE TABLE IF NOT EXISTS`, kørt to gange uden fejl. **Værn:** `newsletter_sql.php` afviser (exit 3) uden og med forkert `HSG_TEST_DB_NAME`, og `newsletter_funktionel.sh` kører intet og rører ikke databasen. **Spec:** `users.php` og `stopped → unsubscribed` er med. |
| 10 | Udviklerens tilføjelser | BESTÅET | **XMLReader:** gentaget. Et ark på 30 MB med mange små elementer: gammel SimpleXML-vej **dør ved 128 MB**, og kræver 348 MB og 38 s ved 2 GB. Ny vej: 32 MB og 2,7 s. Begrundelsen holder. **500.000 delte strenge:** 500.000 godtages (54 MB), 500.001 afvises. **10 MB tekst:** én delt streng genbrugt i 5.000 × 30 celler afvises ("…for meget tekst…"). **Ødelagt xlsx:** "Excel-filen kunne ikke læses. …". **Tomme rækker** springes over, rækkenumrene bevares (CSV: 1, 2, 5, 8 …). **`$escape = ''`:** `"Pia \"` giver feltet `Pia \` (RFC 4180). |
| 11 | `bash tests/run.sh` grøn, ingen trin 1-kriterier brudt | BESTÅET | Se "Kørsler". |
| 12 | Al tekst på dansk | BESTÅET | Alle nye tekster (fejltekster, afmeldingens 500-side, sidetekster, `docs/NEWSLETTER.md`, specen) gennemlæst. |

## Fund

### BØR 1. En celle over 320 tegn med flere adresser kan give en forkert, men gyldig adresse

Kriterie 5 afkorter hver celle til 320 tegn. Er cellen en mailkolonne med flere adresser (kriterie 8 i trin 1: `a@x.dk; b@y.dk`), klippes den sidste adresse midt over, og den afklippede adresse kan være gyldig.

- **Trin:** Upload en CSV med `E-mail` som overskrift og en celle på 321 tegn, hvor sidste adresse starter på tegn 309: `a@b.dk; a@b.dk; … ; kim@firma.com`.
- **Forventet:** Den afklippede adresse importeres ikke (fx at alt efter sidste skilletegn droppes, eller at cellen står som ugyldig/for lang).
- **Faktisk:** Forhåndsvisningen viser `kim@firma.co` som "Ny", og efter import findes `kim@firma.co` i `hsg_newsletter_recipients`. Nyhedsbrevet ville gå til en fremmed adresse. På `main` blev hele cellen importeret korrekt.
- **Anbefaling:** Fritag mailkolonnen fra afkortningen, eller drop et afklippet sidste stykke. Dokumentér grænsen i `docs/NEWSLETTER.md`, som i dag kun siger, at "hver celle afkortes til 320 tegn". Det er sjældent (mindst 16 adresser i én celle), men konsekvensen er en mail til en forkert person.

### KAN

1. **Auditfejl efter opdateringen i `nyhedsbrev-afmeld.php`.** Fejler `audit_log()` efter `UPDATE` (jeg omdøbte `hsg_audit_log.details_json`), er modtageren afmeldt (`unsubscribed`, `one_click`), men siden svarer 500 "kunne ikke gennemføres", og der er ingen auditlinje. Det fejler på den sikre side (næste besøg: "Du er allerede afmeldt"), og kriteriet gælder opslag og opdatering, men teksten er misvisende. Kan løses ved at fange fejlen om auditlinjen i `hsg_newsletter_unsubscribe()` eller køre begge dele i én transaktion.
2. **README og krav.** `README.txt` (KRAV) nævner "SimpleXML (XLSX-import)", men ikke `xmlreader`, som nyhedsbrevets xlsx-import nu kræver (står i `docs/NEWSLETTER.md`). Mangler udvidelsen, får brugeren en dansk fejltekst, så der sker ikke noget galt. Tilføj ved næste release (README, evt. `tests.yml`).
3. **Specen.** Linje 89 siger stadig "læses aldrig ind i databasen" (kriteriet gælder kun siden og vejledningen), og skitsen S1 nævner ikke, at mailadressen er et link.
4. **Trykflade på mobil.** Mailadresse-linket er 19 px højt på 375 px (knappen "Rediger" i rækken er 36 px). Layoutet er ikke brudt.
5. **Småting uden 500.** `file[]` som upload giver teksten "…for stor til at blive uploadet." (arrayet bliver til tallet 1). En celle med 30 bogstaver som reference giver en `TypeError` fra `hsg_supplier_col_index()`, som siden fanger som "Filen kunne ikke læses …". `mode[]=x` giver en PHP-advarsel på `newsletter_import.php` linje 19 (ikke i denne diff).

### Bemærkninger uden for denne branch (ikke fejl i rettelsen)

- En CSV med BOM og anførselstegn om første felt (`BOM"Navn"`) beholder anførselstegnene. Det gør den gamle læser også.
- En upload over `post_max_size` giver "Ugyldig formularsession" (419). Det gjorde den også før.
- 5 MB tomme linjer (5,2 mio. linjer) tager ca. 6 s i CSV-læseren. Kun en konstrueret fil, men på et langsomt webhotel nærmer det sig `max_execution_time`.

## Tilføjede tests (kun i `tests/`)

- `tests/newsletter_session.php` (ny): CLI-hjælper, der aflæser eller aldrer `$_SESSION['newsletter_import']` ud fra cookie-filen. Bruges af de funktionelle tests.
- `tests/newsletter_funktionel.sh` (+11 tjek): tom `.xlsx`, CSV omdøbt til `.xlsx`, zip med forfalsket størrelse (påstår 2.000 byte, er 8 MB), CSV med præcis 5.000 rækker og tomme rækker til sidst, CSV i Windows-1252 med æøå, og seks tjek af forhåndsvisningens levetid (ny ligger i sessionen, slettes på begge sider ved udløb, 7.100 s er ikke udløbet, et andet token sletter ikke, en udløbet forhåndsvisning kan ikke gennemføres).
- `tests/newsletter_test.php` (+2 tjek): omvendt skråstreg er ikke et escape-tegn, og dobbelte anførselstegn i et felt.
- Bevis for værdien: uden kaldene til `hsg_newsletter_preview_expire()` på siderne er udviklerens suite grøn, mens mine to tests fejler.

#!/usr/bin/env bash
# Nyhedsbrev, opfølgning – testerens supplement (kun udvikling/CI, kommer ikke med i release).
#
# Læses ind af tests/run.sh efter tests/newsletter_funktionel.sh. Forudsætter, at run.sh har defineret:
#   ok, bad, info, BASE, JAR (admin-cookies), ROOT, token()  – og at config.php findes.
# Kan ikke køres alene.
#
# Dækker:
#   1. Felter sendt som lister (f.eks. mode[]=x, id[]=5) på newsletter_import.php og newsletter_recipients.php:
#      hverken HTTP 500, PHP-advarsler eller serverlog-fejl.
#   2. id[]=X (og andre skæve id'er) rammer aldrig en modtager – hverken X eller id 1: delete, stop, unsubscribe,
#      reactivate, update og export giver en fejltekst og ændrer intet.
#   3. En udløbet forhåndsvisning slettes på alle sider bag auth.php (admin og linkbruger), en forhåndsvisning
#      under 7.200 sekunder bevares, core/newsletter.php indlæses kun, når der ligger en forhåndsvisning,
#      og hooks indlæses ikke, når modulet er slået fra.

info "Nyhedsbrev: felter sendt som lister, id[]-angreb og oprydning af forhåndsvisningen (tester)"
PI=$(mktemp -d)
# PHP-fejl i et svar: begge formater (html_errors=0: "Warning: ...", html_errors=1: "<b>Warning</b>: ...") samt tekst fra listefelter
PIERR='<b>(Warning|Notice|Deprecated|Fatal error|Parse error)</b>|(Warning|Notice|Deprecated|Fatal error|Parse error): |Uncaught|Cannot redeclare|Array to string'
pisql() { php "$ROOT/tests/newsletter_sql.php" "$1"; }
pichk() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }

# Værn: testen sletter modtagere, så den må kun køre mod testdatabasen i HSG_TEST_DB_NAME.
PIDB=$(php -r '$c = require $argv[1]; echo (string)($c["db_name"] ?? "");' "$ROOT/config.php" 2>/dev/null)
if [ -z "${HSG_TEST_DB_NAME:-}" ] || [ "$PIDB" != "$HSG_TEST_DB_NAME" ]; then
  bad "Nyhedsbrev (tester): config.php peger ikke på testdatabasen HSG_TEST_DB_NAME – testen er ikke kørt"
  rm -rf "$PI"
  return 0
fi

picsrf() { token "$BASE/${1:-newsletter_recipients.php}"; }
pipage() { curl -g -s -L -b "$JAR" -c "$JAR" -o "$PI/page.html" -w '%{http_code}' "$@"; }
# En POST med gyldigt CSRF-token til en side; resten af argumenterne er curl-felter (-d eller -F).
pipost() { local p="$1" T; shift; T=$(picsrf "$p"); pipage "$BASE/$p" -d "csrf=$T" "$@"; }
pibadhtml() { grep -qiE "$PIERR" "$PI/page.html"; }
pilogwarn() { grep -cE "Array to string|must be of type|Uncaught|Fatal error" "${HSG_PHP_LOG:-/tmp/hsg-php-server.log}" 2>/dev/null || true; }
# Ét SQL-kald pr. øjebliksbillede (modtagerne og antallet af audit-linjer), så testen er hurtig
pisnap() { pisql "SELECT MD5(CONCAT_WS('|', (SELECT IFNULL(GROUP_CONCAT(CONCAT_WS(',', id, IFNULL(email,'-'), IFNULL(name,'-'), IFNULL(company,'-'), segment, status, updated_at) ORDER BY id SEPARATOR ';'),'') FROM hsg_newsletter_recipients), (SELECT COUNT(*) FROM hsg_audit_log)))"; }
pisess() { php "$ROOT/tests/newsletter_session.php" "$@"; }
piput() { php "$ROOT/tests/newsletter_session_put.php" "$@" >/dev/null; }
piprobe() { php "$ROOT/tests/newsletter_probe.php" "$1" 2>/dev/null | grep '^PROBE' | tail -1; }
pitoggle() { local T; T=$(token "$BASE/system.php?tab=modules"); curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/system.php?tab=modules" --data-urlencode "csrf=$T" --data-urlencode action=toggle_module --data-urlencode module_id=newsletter --data-urlencode enabled="$1"; }

# ---------- 1. Faste modtagere: id 1 og 4 aktive, 2 stoppet, 3 afmeldt (id 1 er netop det, (int)id[] blev til før) ----------
pisql "DELETE FROM hsg_newsletter_recipients" >/dev/null
pisql "INSERT INTO hsg_newsletter_recipients(id,email,email_hash,name,company,segment,status,source) VALUES
  (1,'pi1@x.dk',SHA2('pi1@x.dk',256),'Pi Et','Firma Et','dealer','active','manual'),
  (2,'pi2@x.dk',SHA2('pi2@x.dk',256),'Pi To','Firma To','dealer','stopped','manual'),
  (3,'pi3@x.dk',SHA2('pi3@x.dk',256),'Pi Tre','Firma Tre','customer','unsubscribed','manual'),
  (4,'pi4@x.dk',SHA2('pi4@x.dk',256),'Pi Fire','Firma Fire','customer','active','manual')" >/dev/null
declare -A PIMSG=(
  [delete]="Modtageren findes ikke eller er allerede slettet."
  [stop]="Kun aktive modtagere kan stoppes."
  [unsubscribe]="Kun aktive modtagere kan afmeldes."
  [reactivate]="Modtageren kan ikke genaktiveres."
  [export]="Modtageren findes ikke."
  [update]="Modtageren findes ikke."
)
for act in delete stop unsubscribe reactivate export update; do
  fails=""
  for spec in "id[]=4" "id[]=1" "id[0]=4" "id[a]=4" "id[]=1&id[]=4" "id=4x" "id=-1" "id=1.0" "id=99999999999999999999"; do
    before=$(pisnap)
    IFS='&' read -r -a parts <<< "$spec"; args=(); for pt in "${parts[@]}"; do args+=(-d "$pt"); done
    exp="${PIMSG[$act]}"; case "$spec" in *"["*) exp="Formularen er ugyldig. Genindlæs siden, og prøv igen.";; esac  # lister afvises før handlingen
    code=$(pipost newsletter_recipients.php -d "action=$act" "${args[@]}" -d self_requested=1 -d email=pi9@x.dk -d name=Z -d company=Z -d segment=dealer)
    # Et uoverskueligt id (id=99999999999999999999) bliver til PHP_INT_MAX; siden, der vises bagefter, svarer 404 (modtageren findes ikke).
    okcode=0; [ "$code" = "200" ] && okcode=1; [ "$code" = "404" ] && [ "$spec" = "id=99999999999999999999" ] && okcode=1
    if [ "$okcode" != "1" ] || [ "$(pisnap)" != "$before" ] || ! grep -qF "$exp" "$PI/page.html" || pibadhtml; then fails="$fails [$spec: HTTP $code]"; fi
  done
  pichk "Tester: $act med id[]/skæve id'er giver fejltekst og ændrer intet (hverken modtager 1 eller 4)${fails:+ – FEJL:$fails}" '[ -z "$fails" ]'
done
# Kontrol: testen kan opdage en ændring (et almindeligt id virker)
before=$(pisnap); pipost newsletter_recipients.php -d action=stop -d id=4 >/dev/null
pichk "Tester: kontrol – stop med almindeligt id=4 ændrer modtageren (så ovenstående tests kan opdage en ændring)" '[ "$(pisnap)" != "$before" ] && [ "$(pisql "SELECT status FROM hsg_newsletter_recipients WHERE id=4")" = "stopped" ]'
pipost newsletter_recipients.php -d action=reactivate -d id=4 >/dev/null

# ---------- 2. Alle felter som liste: ingen HTTP 500, ingen PHP-advarsler, ingen serverlog-fejl ----------
W0=$(pilogwarn); codes=""; listfail=""
pilist() { # navn side felter...  (felterne sendes som curl -d/-F-argumenter)
  local name="$1" p="$2"; shift 2
  local c; c=$(pipost "$p" "$@"); codes="$codes $c"
  if [ "$c" -ge 500 ] || pibadhtml; then listfail="$listfail [$name HTTP $c]"; fi
}
for f in action id email name company segment self_requested; do
  for v in "[]=x" "[a][b]=x" "[]="; do
    pilist "recipients $f$v" newsletter_recipients.php -d "action=create" -d "email=pil@x.dk" -d "name=N" -d "company=C" -d "segment=dealer" -d "$f$v"
    pilist "recipients $f$v (update)" newsletter_recipients.php -d "action=update" -d "id=4" -d "email=pi4@x.dk" -d "name=Pi Fire" -d "company=Firma Fire" -d "segment=customer" -d "$f$v"
  done
done
pisql "DELETE FROM hsg_newsletter_recipients WHERE email='pil@x.dk'" >/dev/null
printf 'E-mail\npil1@x.dk\n' > "$PI/pil.csv"
PT=$(cd "$PI" && curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -F "csrf=$(picsrf newsletter_import.php)" -F action=analyse -F mode=customer -F "file=@pil.csv" "$BASE/newsletter_import.php"); PT=${PT##*preview=}
for f in action mode sheet col_email col_name col_company col_segment preview_token; do
  for v in "[]=x" "[a][b]=x" "[]="; do
    pilist "import $f$v (remap)" newsletter_import.php -d "action=remap" -d "preview_token=$PT" -d "sheet=Ark1" -d "mode=customer" -d "col_email=0" -d "$f$v"
    case "$f" in action|preview_token) pilist "import $f$v (apply)" newsletter_import.php -d "action=apply" -d "preview_token=$PT" -d "$f$v";; esac
  done
done
for f in mode action; do
  for v in "[]=x" "[a][b]=x"; do
    c=$(cd "$PI" && curl -g -s -L -b "$JAR" -c "$JAR" -o "$PI/page.html" -w "%{http_code}" -F "csrf=$(picsrf newsletter_import.php)" -F action=analyse -F mode=customer -F "file=@pil.csv" -F "$f$v" "$BASE/newsletter_import.php"); codes="$codes $c"
    if [ "$c" -ge 500 ] || pibadhtml; then listfail="$listfail [import analyse $f$v HTTP $c]"; fi
  done
done
for u in "newsletter_import.php?preview[]=$PT" "newsletter_import.php?preview[a][b]=x" "newsletter_recipients.php?filter[]=x" "newsletter_recipients.php?filter[a][b]=x" "newsletter_recipients.php?id[]=1" "newsletter_recipients.php?id[a][b]=1" "newsletter_recipients.php?id[]=&filter[]="; do
  c=$(pipage "$BASE/$u"); codes="$codes $c"; if [ "$c" -ge 500 ] || pibadhtml; then listfail="$listfail [GET $u HTTP $c]"; fi
done
c=$(pipage "$BASE/newsletter_recipients.php" -d "csrf[]=x" -d action=delete -d id=4); codes="$codes $c"; [ "$c" = "419" ] || listfail="$listfail [csrf[] gav HTTP $c, ikke 419]"
c=$(pipage "$BASE/newsletter_import.php" -F "csrf=$(picsrf newsletter_import.php)" -F action=analyse -F mode=customer -F "file[]=@$PI/pil.csv" -F "file[]=@$PI/pil.csv"); codes="$codes $c"; if [ "$c" -ge 500 ] || pibadhtml; then listfail="$listfail [file[] HTTP $c]"; fi
pichk "Tester: alle felter som liste ([]=x, [a][b]=x, []=) giver hverken HTTP 500 eller PHP-advarsler${listfail:+ – FEJL:$listfail}" '[ -z "$listfail" ] && ! echo "$codes" | grep -qE "(^| )5[0-9][0-9]( |$)"'
pichk "Tester: serverloggen har ingen nye 'Array to string'/Fatal-linjer efter listefelterne" '[ "$(pilogwarn)" = "$W0" ]'
pichk "Tester: modtager 1 er uændret efter listefelterne (aktiv, samme navn, firma og type)" '[ "$(pisql "SELECT status,name,company,segment FROM hsg_newsletter_recipients WHERE id=1")" = "$(printf "active\tPi Et\tFirma Et\tdealer")" ]'
pichk "Opfølgning (testerens KAN 1): update med name[], company[] eller segment[] sletter ikke navn og firma på modtager 4" '[ "$(pisql "SELECT name,company,segment FROM hsg_newsletter_recipients WHERE id=4")" = "$(printf "Pi Fire\tFirma Fire\tcustomer")" ]'

# ---------- 3. Oprydning af udløbet forhåndsvisning på alle sider bag auth.php ----------
pisess_n() { pisess show "$1" | cut -d" " -f1; }
# Siden uden forhåndsvisning er basislinjen: kun nye PHP-fejl (flere end uden forhåndsvisning) tæller, så en fejl, der
# allerede findes på en side (fx "Undefined array key" på stock.php fra v10.4.0), ikke bliver tilskrevet hooks.php.
pierrs() { grep -ciE "$PIERR" "$PI/p.html" || true; }
fails=""
for pg in products.php admin.php stock.php status.php reservations.php catalog.php reports.php users.php system.php locations.php brands.php quality.php import_center.php supplier_upload.php import.php image_check.php admin-account.php; do
  piput drop "$JAR"; c0=$(curl -s -b "$JAR" -c "$JAR" -o "$PI/p.html" -w '%{http_code}' "$BASE/$pg"); e0=$(pierrs)
  piput put "$JAR" 7300
  c=$(curl -s -b "$JAR" -c "$JAR" -o "$PI/p.html" -w '%{http_code}' "$BASE/$pg"); e1=$(pierrs)
  [ "$(pisess_n "$JAR")" = "nej" ] && [ "$c" -lt 500 ] && [ "$c" = "$c0" ] && [ "$e1" -le "$e0" ] || fails="$fails [$pg: HTTP $c (uden: $c0), fejl $e1 (uden: $e0), session=$(pisess_n "$JAR")]"
done
pichk "Tester: en udløbet forhåndsvisning slettes på alle sider uden for nyhedsbrevet (admin)${fails:+ – FEJL:$fails}" '[ -z "$fails" ]'
fails=""
for pg in products.php admin.php stock.php status.php supplier_upload.php import.php users.php; do
  piput drop "$JAR"; c0=$(curl -s -b "$JAR" -c "$JAR" -o "$PI/p.html" -w '%{http_code}' "$BASE/$pg"); e0=$(pierrs)
  piput put "$JAR" 7100
  c=$(curl -s -b "$JAR" -c "$JAR" -o "$PI/p.html" -w '%{http_code}' "$BASE/$pg"); e1=$(pierrs)
  [ "$(pisess_n "$JAR")" = "ja" ] && [ "$c" -lt 500 ] && [ "$c" = "$c0" ] && [ "$e1" -le "$e0" ] || fails="$fails [$pg: HTTP $c (uden: $c0), fejl $e1 (uden: $e0), session=$(pisess_n "$JAR")]"
done
pichk "Tester: en forhåndsvisning på 7.100 sekunder bevares på alle disse sider, og siderne virker som før${fails:+ – FEJL:$fails}" '[ -z "$fails" ]'
piput put "$JAR" 7300; c=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -d x=1 "$BASE/locations.php")
pichk "Tester: en POST uden CSRF giver 419, og den udløbne forhåndsvisning er alligevel slettet (hooks indlæses før verify_csrf)" '[ "$c" = "419" ] && [ "$(pisess_n "$JAR")" = "nej" ]'

# Linkbruger: en session i link-tilstand rydder også op (linket kan ikke selv lave en forhåndsvisning, så den lægges i sessionen)
T=$(token "$BASE/users.php?tab=links")
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/users.php?tab=links" --data-urlencode "csrf=$T" -d action=create --data-urlencode "name=QA Linkbruger 2"
curl -s -b "$JAR" -c "$JAR" -o "$PI/links.html" "$BASE/users.php?tab=links"; LK=$(grep -o 'http://[^"<]*/?k=[a-f0-9]*' "$PI/links.html" | head -1)
PL="$PI/link.jar"; : > "$PL"; curl -s -L -b "$PL" -c "$PL" -o /dev/null "$LK"
fails=""
for pg in status.php catalog.php reservations.php stock.php products.php; do
  piput put "$PL" 7300
  c=$(curl -s -b "$PL" -c "$PL" -o "$PI/p.html" -w '%{http_code}' "$BASE/$pg")
  [ "$(pisess_n "$PL")" = "nej" ] || fails="$fails [$pg: HTTP $c, session=$(pisess_n "$PL")]"
done
pichk "Tester: en udløbet forhåndsvisning slettes også i en linkbrugers session${fails:+ – FEJL:$fails}" '[ -n "$LK" ] && [ -z "$fails" ]'
piput put "$PL" 7100; curl -s -b "$PL" -c "$PL" -o /dev/null "$BASE/status.php"
pichk "Tester: en forhåndsvisning på 7.100 sekunder bevares også i en linkbrugers session" '[ "$(pisess_n "$PL")" = "ja" ]'
piput put "$PL" 7300; curl -s -b "$PL" -c "$PL" -o /dev/null "$BASE/status.php?k=forkert-og-for-kort"
pichk "Tester: et ugyldigt link (403) rydder også op i en udløbet forhåndsvisning" '[ "$(pisess_n "$PL")" = "nej" ]'
piput drop "$PL"
# Direkte link: nyhedsbrevssiderne er stadig forbudt, også med listefelter
fails=""
for u in "newsletter_recipients.php?id[]=1" "newsletter_import.php?preview[]=x" "newsletter_recipients.php?filter[]=x"; do
  c=$(curl -g -s -b "$PL" -c "$PL" -o /dev/null -w '%{http_code}' "$BASE/$u"); [ "$c" = "403" ] || fails="$fails [GET $u: $c]"
done
LT=$(curl -s -b "$PL" -c "$PL" "$BASE/reservations.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
if [ -n "$LT" ]; then
  c=$(curl -g -s -b "$PL" -c "$PL" -o /dev/null -w '%{http_code}' "$BASE/newsletter_recipients.php" -d "csrf=$LT" -d action=delete -d "id[]=1"); [ "$c" = "403" ] || fails="$fails [POST delete id[]=1: $c]"
fi
pichk "Tester: en direkte linkbruger får 403 på nyhedsbrevssiderne med listefelter og kan ikke slette (modtager 1 er uændret)${fails:+ – FEJL:$fails}" '[ -z "$fails" ] && [ "$(pisql "SELECT status FROM hsg_newsletter_recipients WHERE id=1")" = "active" ]'
pichk "Tester: uden login giver listefelter 401" '[ "$(curl -g -s -o /dev/null -w "%{http_code}" "$BASE/newsletter_recipients.php?id[]=1")" = "401" ] && [ "$(curl -g -s -o /dev/null -w "%{http_code}" "$BASE/newsletter_recipients.php" -d action=delete -d "id[]=1")" = "401" ]'

# ---------- 4. core/newsletter.php indlæses kun, når der ligger en forhåndsvisning; hooks følger modulet ----------
piput drop "$JAR"; r1=$(piprobe "$JAR")
piput put "$JAR" 10; r2=$(piprobe "$JAR")
piput put "$JAR" 7300; r3=$(piprobe "$JAR")
pichk "Tester: uden forhåndsvisning indlæses core/newsletter.php ikke ($r1)" '[ "$r1" = "PROBE nl=nej preview=nej" ]'
pichk "Tester: med en frisk forhåndsvisning indlæses den, og forhåndsvisningen bevares ($r2)" '[ "$r2" = "PROBE nl=ja preview=ja" ]'
pichk "Tester: med en udløbet forhåndsvisning slettes den ($r3)" '[ "$r3" = "PROBE nl=ja preview=nej" ]'
pitoggle 0
piput put "$JAR" 7300; r4=$(piprobe "$JAR")
c1=$(curl -s -b "$JAR" -c "$JAR" -o "$PI/p.html" -w '%{http_code}' "$BASE/products.php"); bp=0; pibadhtml_p() { grep -qiE "$PIERR" "$PI/p.html"; }; pibadhtml_p && bp=1
c2=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' "$BASE/newsletter_recipients.php")
c3=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' "$BASE/supplier_upload.php")
pichk "Tester: med modulet slået fra indlæses hooks ikke (preview bevares, nl=nej: $r4), andre sider virker (products $c1, supplier_upload $c3), og nyhedsbrevssiden giver 404 ($c2)" '[ "$r4" = "PROBE nl=nej preview=ja" ] && [ "$c1" = "200" ] && [ "$bp" = "0" ] && [ "$c2" = "404" ] && [ "$c3" = "200" ]'
pitoggle 1
piput put "$JAR" 7300; curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/products.php"
pichk "Tester: modulet slået til igen rydder op igen" '[ "$(pisess_n "$JAR")" = "nej" ]'

rm -rf "$PI"

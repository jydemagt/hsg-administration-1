#!/usr/bin/env bash
# Nyhedsbrev trin 1 – funktionel test af acceptkriterierne (kun udvikling/CI, kommer ikke med i release).
#
# Læses ind af tests/run.sh (trin 4), efter installation og admin-login. Forudsætter, at run.sh har defineret:
#   ok, bad, BASE, JAR (admin-cookies), ROOT, token()  – og at config.php findes.
# Kan ikke køres alene.
#
# Dækker (spec 2026-10-19-nyhedsbrev, trin 1): kriterie 2, 3, 6–13, 16–28 samt standardadgangen i users.php.

NF=$(mktemp -d)
nfsql() { php "$ROOT/tests/newsletter_sql.php" "$1"; }
nfchk() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }
nfpage() { curl -s -b "$JAR" -c "$JAR" -o "$NF/page.html" -w '%{http_code}' "$1"; }
nfpost() { local T; T=$(token "$BASE/newsletter_recipients.php"); curl -s -L -b "$JAR" -c "$JAR" -o "$NF/page.html" -w '%{http_code}' "$1" -d "csrf=$T" "${@:2}"; }
nfflash() { php -r '$h=file_get_contents($argv[1]); if(preg_match_all("/<div class=\"flash[^\"]*\"[^>]*>(.*?)<\/div>/s",$h,$m)) echo html_entity_decode(implode(" | ",$m[1]),ENT_QUOTES,"UTF-8");' "$NF/page.html"; }
nfxlsx() { php "$ROOT/tests/make_xlsx.php" "$NF/$1" "$2" >/dev/null || bad "kunne ikke lave $1"; }
nfanalyse() { # fil mode -> preview-token
  local T u; T=$(token "$BASE/newsletter_import.php")
  u=$( cd "$NF" && curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -F "csrf=$T" -F action=analyse -F "mode=$2" -F "file=@$1" "$BASE/newsletter_import.php" )
  echo "${u##*preview=}"
}
nfpreview() { nfpage "$BASE/newsletter_import.php?preview=$1" >/dev/null; }
nfapply() { local T; T=$(token "$BASE/newsletter_import.php"); curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/newsletter_import.php" -d "csrf=$T" -d action=apply -d "preview_token=$1"; }
nfmetric() { grep -o "<strong>[0-9]*</strong><span>$1</span>" "$NF/page.html" | sed 's/<strong>\([0-9]*\).*/\1/'; }
nfid() { nfsql "SELECT id FROM hsg_newsletter_recipients WHERE email_hash=SHA2('$1',256)"; }
nfstatus() { nfsql "SELECT status FROM hsg_newsletter_recipients WHERE email_hash=SHA2('$1',256)"; }
nflink() { nfpage "$BASE/newsletter_recipients.php?id=$1" >/dev/null; grep -o 'value="http[^"]*nyhedsbrev-afmeld.php?t=[a-f0-9]*"' "$NF/page.html" | head -1 | sed 's/^value="//;s/"$//'; }
nfcode() { curl -s -o "$NF/pub.html" -w '%{http_code}' "$@"; }

info "Nyhedsbrev: funktionel test af trin 1"
nfsql "DELETE FROM hsg_newsletter_recipients" >/dev/null

# ---------- Kriterie 6, 9: kolonne D, ugyldige med Excel-rækkenummer (række 4 er udeladt i filen) ----------
nfxlsx k6.xlsx '[["Kim","Firma","x","a1@x.dk"],["","","","b1@y.dk"],["","","","kim(at)firma.dk"],null,["","","","kim@"],["","","",false],["","","","kim@firma"],["","","","c1@z.dk"]]'
P=$(nfanalyse k6.xlsx customer); nfpreview "$P"
nfchk "K6 uden overskrifter: Kolonne D er valgt som mailkolonne" 'grep -q "<option value=\"3\" selected>Kolonne D</option>" "$NF/page.html"'
nfchk "K6 nye = 3, ugyldige = 3" '[ "$(nfmetric Nye)" = "3" ] && [ "$(nfmetric Ugyldige)" = "3" ]'
nfchk "K9 ugyldige står med Excel-rækkenummer 3, 5 og 7 (tomme celler tælles ikke)" 'grep -q "række 3: \"kim(at)firma.dk\"" "$NF/page.html" && grep -q "række 5: \"kim@\"" "$NF/page.html" && grep -q "række 7: \"kim@firma\"" "$NF/page.html" && ! grep -q "række 6:" "$NF/page.html"'
nfapply "$P"
nfchk "K6/K9 3 modtagere importeret, ingen af de ugyldige" '[ "$(nfsql "SELECT COUNT(*) FROM hsg_newsletter_recipients")" = "3" ]'
P=$(nfanalyse k6.xlsx customer); nfpreview "$P"
nfchk "K10 samme fil igen: 0 nye, 3 uændrede" '[ "$(nfmetric Nye)" = "0" ] && [ "$(nfmetric Uændrede)" = "3" ]'
nfapply "$P"
nfchk "K10 antal rækker uændret" '[ "$(nfsql "SELECT COUNT(*) FROM hsg_newsletter_recipients")" = "3" ]'

# ---------- Kriterie 7, 8, 14, 16: dubletter, flere adresser, kilde, audit ----------
nfxlsx k7.xlsx '[["Navn","Firma","Telefon","E-mail"],["Kim Hansen","Firma ApS","123"," Kim@Firma.DK "],["Kim H.","","","kim@firma.dk"],["To","Duo","","a2@x.dk; b2@y.dk"]]'
P=$(nfanalyse k7.xlsx customer); nfpreview "$P"
nfchk "K7 'Dubletter i filen: 1' og overskrift i række 1" 'grep -q "Dubletter i filen: <strong>1</strong>" "$NF/page.html" && grep -q "overskrift i række 1" "$NF/page.html"'
nfapply "$P"
nfchk "K7 præcis én kim@firma.dk med små bogstaver" '[ "$(nfsql "SELECT COUNT(*) FROM hsg_newsletter_recipients WHERE email='"'"'kim@firma.dk'"'"'")" = "1" ]'
nfchk "K8 a2@x.dk og b2@y.dk er begge oprettet" '[ "$(nfsql "SELECT COUNT(*) FROM hsg_newsletter_recipients WHERE email IN ('"'"'a2@x.dk'"'"','"'"'b2@y.dk'"'"')")" = "2" ]'
nfchk "K14 import: source=import og last_import_at sat; ingen kolonne 'basis'" '[ "$(nfsql "SELECT COUNT(*) FROM hsg_newsletter_recipients WHERE source<>'"'"'import'"'"' OR last_import_at IS NULL")" = "0" ] && [ "$(nfsql "SHOW COLUMNS FROM hsg_newsletter_recipients LIKE '"'"'%basis%'"'"'")" = "" ]'
nfpage "$BASE/newsletter_import.php" >/dev/null
nfchk "K14 importformularen nævner hverken grundlag eller samtykke" '! grep -qiE "grundlag|samtykke|legitim|basis" "$NF/page.html"'
nfchk "K16 audit newsletter.import findes og indeholder ikke @" '[ "$(nfsql "SELECT COUNT(*) FROM hsg_audit_log WHERE action='"'"'newsletter.import'"'"'")" -ge 2 ] && [ "$(nfsql "SELECT COUNT(*) FROM hsg_audit_log WHERE action LIKE '"'"'newsletter.%'"'"' AND details_json LIKE '"'"'%@%'"'"'")" = "0" ]'

# ---------- Kriterie 11: afmeldt, stoppet og slettet springes over (anden skrivemåde) ----------
nfsql "INSERT INTO hsg_newsletter_recipients(email,email_hash,segment,status,source,token_hash) VALUES('unsub@x.dk',SHA2('unsub@x.dk',256),'customer','unsubscribed','import',SHA2('t1',256)),('stopped@x.dk',SHA2('stopped@x.dk',256),'customer','stopped','import',SHA2('t2',256))" >/dev/null
nfsql "INSERT INTO hsg_newsletter_recipients(email,email_hash,segment,status,source) VALUES(NULL,SHA2('deleted@x.dk',256),'customer','deleted','import')" >/dev/null
nfxlsx k11.xlsx '[["E-mail","Navn"],["UNSUB@X.DK","Skal ikke genaktiveres"],[" Stopped@X.dk ","S"],["DELETED@x.dk","D"],["ny11@x.dk","Ny"]]'
P=$(nfanalyse k11.xlsx customer); nfpreview "$P"
nfchk "K11 tre sprunget over, maskeret (u***@x.dk), ingen klartekst" '[ "$(nfmetric "Afmeldt/stoppet – springes over")" = "3" ] && grep -q "u\*\*\*@x.dk – Afmeldt" "$NF/page.html" && ! grep -qi "unsub@x.dk\|stopped@x.dk\|deleted@x.dk" "$NF/page.html"'
nfapply "$P"
nfchk "K11 statusser uændrede efter import (unsubscribed, stopped, deleted)" '[ "$(nfstatus unsub@x.dk)" = "unsubscribed" ] && [ "$(nfstatus stopped@x.dk)" = "stopped" ] && [ "$(nfstatus deleted@x.dk)" = "deleted" ]'

# ---------- Kriterie 12, 13: type ----------
nfxlsx k12.xlsx '[["E-mail","Navn"],["kim@firma.dk","Kim"]]'
P=$(nfanalyse k12.xlsx dealer); nfpreview "$P"
nfchk "K12 'Skifter type: 1' og 'Kunde -> Forhandler'" 'grep -q "Skifter type: <strong>1</strong>" "$NF/page.html" && grep -q "Kunde -> Forhandler" "$NF/page.html"'
nfapply "$P"
nfchk "K12 efter import er kim@firma.dk dealer" '[ "$(nfsql "SELECT segment FROM hsg_newsletter_recipients WHERE email='"'"'kim@firma.dk'"'"'")" = "dealer" ]'
nfxlsx k13.xlsx '[["E-mail","Type"],["konf@x.dk","Forhandler"],["konf@x.dk","Kunde"],["rent@x.dk","FORHANDLER A/S"]]'
P=$(nfanalyse k13.xlsx column); T=$(token "$BASE/newsletter_import.php")
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/newsletter_import.php" -d "csrf=$T" -d action=remap -d "preview_token=$P" -d col_email=0 -d col_name= -d col_company= -d col_segment=1 -d mode=column
nfpreview "$P"
nfchk "K13 én konflikt vises" 'grep -q "Konflikter (importeres som Kunde): <strong>1</strong>" "$NF/page.html"'
nfapply "$P"
nfchk "K13 konflikt bliver Kunde, 'FORHANDLER A/S' bliver Forhandler" '[ "$(nfsql "SELECT segment FROM hsg_newsletter_recipients WHERE email='"'"'konf@x.dk'"'"'")" = "customer" ] && [ "$(nfsql "SELECT segment FROM hsg_newsletter_recipients WHERE email='"'"'rent@x.dk'"'"'")" = "dealer" ]'

# ---------- Kriterie 28: <script> i filen ----------
nfxlsx k28.xlsx '[["E-mail","Navn","Firma"],["xss1@x.dk","<script>alert(1)</script>","\"><img src=x onerror=alert(2)>"],["<script>alert(3)</script>","U",""]]'
P=$(nfanalyse k28.xlsx customer); nfpreview "$P"
nfchk "K28 forhåndsvisning: escaped, intet rå script/img" '! grep -q "<script>alert" "$NF/page.html" && ! grep -q "<img src=x" "$NF/page.html" && grep -q "&lt;script&gt;alert(1)&lt;/script&gt;" "$NF/page.html"'
nfapply "$P"; XID=$(nfid xss1@x.dk)
nfpage "$BASE/newsletter_recipients.php" >/dev/null
nfchk "K28 modtagerlisten: escaped" '! grep -q "<script>alert" "$NF/page.html" && grep -q "&lt;script&gt;alert(1)&lt;/script&gt;" "$NF/page.html"'
nfpage "$BASE/newsletter_recipients.php?id=$XID" >/dev/null
nfchk "K28 redigér-siden: escaped" '! grep -q "<img src=x" "$NF/page.html" && grep -q "&lt;img src=x onerror=alert(2)&gt;" "$NF/page.html"'

# ---------- Kriterie 17: manuel tilføjelse ----------
nfpost "$BASE/newsletter_recipients.php" -d action=create -d email=ikke-en-mail -d segment=customer >/dev/null
nfchk "K17 ugyldig adresse giver fejl" 'nfflash | grep -q "ugyldig"'
nfpost "$BASE/newsletter_recipients.php" -d action=create -d email=kim@firma.dk -d segment=customer >/dev/null
nfchk "K17 eksisterende aktiv: 'Adressen findes allerede'" 'nfflash | grep -q "Adressen findes allerede"'
nfpost "$BASE/newsletter_recipients.php" -d action=create -d email=UNSUB@x.dk -d segment=customer >/dev/null
nfchk "K17 afmeldt uden flueben afvises, og fluebenet vises" 'nfflash | grep -q "Sæt flueben" && grep -q "name=\"self_requested\"" "$NF/page.html" && [ "$(nfstatus unsub@x.dk)" = "unsubscribed" ]'
nfpost "$BASE/newsletter_recipients.php" -d action=create -d email=UNSUB@x.dk -d segment=customer -d self_requested=1 >/dev/null
nfchk "K17 med flueben genaktiveres, og audit har self_requested true" '[ "$(nfstatus unsub@x.dk)" = "active" ] && [ "$(nfsql "SELECT COUNT(*) FROM hsg_audit_log WHERE action='"'"'newsletter.recipient.reactivate'"'"' AND details_json LIKE '"'"'%\"self_requested\":true%'"'"'")" = "1" ]'
nfpost "$BASE/newsletter_recipients.php" -d action=create -d email=Manuel@Test.DK --data-urlencode "name=Åse Ørsted-O'Neil" -d segment=dealer >/dev/null
nfchk "K14 manuelt tilføjet: source=manual og last_import_at NULL" '[ "$(nfsql "SELECT CONCAT(source,COALESCE(last_import_at,'"'"'NULL'"'"')) FROM hsg_newsletter_recipients WHERE email='"'"'manuel@test.dk'"'"'")" = "manualNULL" ]'

# ---------- Kriterie 18–22: afmeldingssiden ----------
ID1=$(nfid a1@x.dk); URL1=$(nflink "$ID1")
nfchk "K18 linket indeholder kun token (ingen mailadresse)" 'echo "$URL1" | grep -qE "nyhedsbrev-afmeld.php\?t=[a-f0-9]{64}$"'
for i in 1 2; do c=$(nfcode "$URL1"); done
nfchk "K18 GET (flere gange) viser maskeret adresse og ændrer intet" '[ "$c" = "200" ] && grep -q "a\*\*\*@x.dk" "$NF/pub.html" && ! grep -q "a1@x.dk" "$NF/pub.html" && [ "$(nfstatus a1@x.dk)" = "active" ]'
V="$NF/visitor.jar"; : > "$V"; curl -s -b "$V" -c "$V" -o "$NF/pub.html" "$URL1"
VT=$(grep -o 'name="csrf" value="[^"]*"' "$NF/pub.html" | head -1 | sed 's/.*value="//;s/"$//')
c=$(curl -s -b "$V" -c "$V" -o "$NF/pub.html" -w '%{http_code}' "$URL1" -d "csrf=$VT")
nfchk "K19 POST fra formularen afmelder (via=link) og siger 'Du er nu afmeldt'" '[ "$c" = "200" ] && grep -q "Du er nu afmeldt" "$NF/pub.html" && [ "$(nfsql "SELECT CONCAT(status,unsubscribe_via,IF(unsubscribed_at IS NULL,'"'"'N'"'"','"'"'T'"'"')) FROM hsg_newsletter_recipients WHERE id=$ID1")" = "unsubscribedlinkT" ]'
c=$(curl -s -b "$V" -c "$V" -o "$NF/pub.html" -w '%{http_code}' "$URL1" -d "csrf=$VT")
nfchk "K19 gentaget: 'Du er allerede afmeldt', og kun én audit-linje uden @" '[ "$c" = "200" ] && grep -q "Du er allerede afmeldt nyhedsbrevet" "$NF/pub.html" && [ "$(nfsql "SELECT COUNT(*) FROM hsg_audit_log WHERE action='"'"'newsletter.recipient.unsubscribe'"'"' AND entity_id='"'"'$ID1'"'"' AND details_json NOT LIKE '"'"'%@%'"'"'")" = "1" ]'
ID2=$(nfid b1@y.dk); URL2=$(nflink "$ID2")
out=$(curl -s -X POST -d 'List-Unsubscribe=One-Click' -w '|%{http_code}' "$URL2")
nfchk "K20 ét-klik uden cookies og CSRF: 200 'Afmeldt', via=one_click" 'echo "$out" | grep -q "^Afmeldt|200" && [ "$(nfsql "SELECT CONCAT(status,unsubscribe_via) FROM hsg_newsletter_recipients WHERE id=$ID2")" = "unsubscribedone_click" ]'
ID3=$(nfid c1@z.dk); URL3=$(nflink "$ID3")
nfchk "K22 POST uden csrf og uden One-Click afvises med 419 (uden og med cookies, forkert token, tom token, anden værdi)" '
  [ "$(nfcode -X POST "$URL3")" = "419" ] && [ "$(nfcode -d x=1 "$URL3")" = "419" ] && [ "$(nfcode -d csrf= "$URL3")" = "419" ] \
  && [ "$(nfcode -d List-Unsubscribe=Other "$URL3")" = "419" ] && [ "$(nfcode -b "$V" -c "$V" -d csrf=forkert "$URL3")" = "419" ] \
  && [ "$(nfcode -b "$V" -c "$V" -d "csrf[]=x" "$URL3")" = "419" ] && [ "$(nfstatus c1@z.dk)" = "active" ]'
ZERO=$(printf '0%.0s' $(seq 1 64)); VALID=${URL3##*t=}; AUD0=$(nfsql "SELECT COUNT(*) FROM hsg_audit_log")
bads=""
for t in "$ZERO" abc "${VALID}0" "${VALID:0:63}" "$(printf 'g%.0s' $(seq 1 64))" "$(echo "$VALID" | tr a-f A-F)" "" "../../etc/passwd"; do
  for m in GET POST; do
    if [ $m = POST ]; then c=$(nfcode -d 'List-Unsubscribe=One-Click' "$BASE/nyhedsbrev-afmeld.php?t=$t"); else c=$(nfcode "$BASE/nyhedsbrev-afmeld.php?t=$t"); fi
    if [ "$c" != "404" ] || grep -qiE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught' "$NF/pub.html" || grep -q "@" "$NF/pub.html"; then bads="$bads $m:${t:0:8}=$c"; fi
  done
done
c=$(nfcode "$BASE/nyhedsbrev-afmeld.php?t[]=$VALID")
nfchk "K21 ugyldige tokens (GET og POST) giver 404 med neutral tekst, uden PHP-fejl og uden ændringer${bads:+ – FEJL:$bads}" '[ -z "$bads" ] && [ "$c" = "404" ] && [ "$(nfsql "SELECT COUNT(*) FROM hsg_audit_log")" = "$AUD0" ] && [ "$(nfstatus c1@z.dk)" = "active" ]'
hdr=$(curl -s -D - -o /dev/null -X POST "$URL3")
nfchk "K24 headerne sendes også på 419-svar" 'echo "$hdr" | grep -qi "^Referrer-Policy: no-referrer" && echo "$hdr" | grep -qi "^X-Robots-Tag: noindex" && echo "$hdr" | grep -qi "^Cache-Control: no-store"'

# ---------- Afvigelse 2: en stoppet modtager kan afmelde sig ----------
nfpost "$BASE/newsletter_recipients.php" -d action=create -d email=stop3@x.dk -d segment=customer >/dev/null; SID3=$(nfid stop3@x.dk)
nfpost "$BASE/newsletter_recipients.php" -d action=stop -d id="$SID3" >/dev/null; SURL=$(nflink "$SID3")
nfchk "Afv2 stoppet modtager: GET ændrer intet, ét klik afmelder (one_click)" '[ "$(nfstatus stop3@x.dk)" = "stopped" ] && c=$(nfcode "$SURL") && [ "$(nfstatus stop3@x.dk)" = "stopped" ] && out=$(curl -s -X POST -d "List-Unsubscribe=One-Click" -w "|%{http_code}" "$SURL") && echo "$out" | grep -q "^Afmeldt|200" && [ "$(nfstatus stop3@x.dk)" = "unsubscribed" ]'

# ---------- Kriterie 23: modulet slået fra ----------
nfpost "$BASE/newsletter_recipients.php" -d action=create -d email=k23@x.dk -d segment=customer >/dev/null; K23=$(nfid k23@x.dk); URL23=$(nflink "$K23")
nftoggle() { local T; T=$(token "$BASE/system.php?tab=modules"); curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/system.php?tab=modules" --data-urlencode "csrf=$T" --data-urlencode action=toggle_module --data-urlencode module_id=newsletter --data-urlencode enabled="$1"; }
nftoggle 0
nfchk "K23 modul slået fra: administrationssiderne svarer 404" '[ "$(nfpage "$BASE/newsletter_recipients.php")" = "404" ] && [ "$(nfpage "$BASE/newsletter_import.php")" = "404" ] && [ "$(nfpage "$BASE/newsletter.php")" = "404" ]'
nfchk "K23 modul slået fra: afmeldingslinket virker stadig (GET 200, ét klik afmelder)" '[ "$(nfcode "$URL23")" = "200" ] && out=$(curl -s -X POST -d "List-Unsubscribe=One-Click" -w "|%{http_code}" "$URL23") && echo "$out" | grep -q "^Afmeldt|200" && [ "$(nfstatus k23@x.dk)" = "unsubscribed" ]'
nftoggle 1
nfchk "K23 modul slået til igen: siden virker" '[ "$(nfpage "$BASE/newsletter_recipients.php")" = "200" ]'

# ---------- Kriterie 25, 26: slet og eksportér ----------
nfpost "$BASE/newsletter_recipients.php" -d action=create -d email=slet@x.dk --data-urlencode "name=Slet Mig" -d segment=customer >/dev/null; SID=$(nfid slet@x.dk); SURL=$(nflink "$SID")
nfpost "$BASE/newsletter_recipients.php" -d action=delete -d id="$SID" >/dev/null
nfchk "K25 email, navn, firma, token_hash og token_cipher er NULL, status deleted, email_hash bevaret" '[ "$(nfsql "SELECT CONCAT(IFNULL(email,'"'"'N'"'"'),IFNULL(name,'"'"'N'"'"'),IFNULL(company,'"'"'N'"'"'),IFNULL(token_hash,'"'"'N'"'"'),IFNULL(token_cipher,'"'"'N'"'"'),status) FROM hsg_newsletter_recipients WHERE id=$SID")" = "NNNNNdeleted" ] && [ "$(nfsql "SELECT email_hash FROM hsg_newsletter_recipients WHERE id=$SID")" = "$(printf slet@x.dk | sha256sum | cut -d" " -f1)" ]'
nfchk "K25 vises ikke i listen, redigér = 404, gammelt link = 404, audit logget" '[ "$(nfpage "$BASE/newsletter_recipients.php?id=$SID")" = "404" ] && [ "$(nfcode "$SURL")" = "404" ] && [ "$(nfsql "SELECT COUNT(*) FROM hsg_audit_log WHERE action='"'"'newsletter.recipient.delete'"'"' AND entity_id='"'"'$SID'"'"'")" = "1" ]'
nfpost "$BASE/newsletter_recipients.php" -d action=create -d email=csv@x.dk --data-urlencode "name==CMD()" -d segment=dealer >/dev/null; CID=$(nfid csv@x.dk)
T=$(token "$BASE/newsletter_recipients.php")
curl -s -b "$JAR" -c "$JAR" -D "$NF/exp_h.txt" -o "$NF/exp.csv" "$BASE/newsletter_recipients.php" -d "csrf=$T" -d action=export -d id="$CID"
nfchk "K26 CSV: BOM, semikolon, '=CMD() som tekst, Content-Disposition attachment, audit logget" '[ "$(head -c3 "$NF/exp.csv" | od -An -tx1 | tr -d " \n")" = "efbbbf" ] && grep -q "Mail;Navn;Firma;Type;Status;Kilde;Oprettet" "$NF/exp.csv" && grep -q "csv@x.dk;'"'"'=CMD()" "$NF/exp.csv" && grep -qi "content-disposition: attachment" "$NF/exp_h.txt" && [ "$(nfsql "SELECT COUNT(*) FROM hsg_audit_log WHERE action='"'"'newsletter.recipient.export'"'"' AND entity_id='"'"'$CID'"'"'")" = "1" ]'

# ---------- Kriterie 27: ingen mailadresser i href/src/action, søgefelt uden for formular ----------
for pg in "newsletter_recipients.php" "newsletter_recipients.php?filter=unsubscribed" "newsletter_recipients.php?id=$CID" "newsletter_import.php"; do
  nfpage "$BASE/$pg" >/dev/null
  r=$(php -r '$h=file_get_contents($argv[1]); $bad=0; preg_match_all("/\b(href|src|action)=\"([^\"]*)\"/i",$h,$m,PREG_SET_ORDER);
    foreach($m as $x){ $v=html_entity_decode($x[2]); if(strpos($v,"@")!==false && stripos($v,"mailto:")!==0) $bad++; }
    $inForm=false; if(preg_match_all("/<form\b.*?<\/form>/is",$h,$f)){ foreach($f[0] as $x) if(stripos($x,"type=\"search\"")!==false) $inForm=true; }
    echo $bad, $inForm?"F":"-";' "$NF/page.html")
  nfchk "K27 $pg: ingen mailadresse i href/src/action, søgefeltet ikke i en formular" '[ "$r" = "0-" ]'
done

# ---------- Kriterie 2, 3 og afvigelse 1: login-bruger uden rækker, direkte link ----------
T=$(token "$BASE/users.php?tab=admins")
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/users.php?tab=admins" --data-urlencode "csrf=$T" -d action=create_admin -d username=qa-nyhedsbrev -d display_name=QA -d password='Qa-Pass-12345!' -d is_superadmin=0 -d 'modules[]=catalog' -d 'modules[]=products'
QID=$(nfsql "SELECT id FROM lager_admins WHERE username='qa-nyhedsbrev'")
nfsql "DELETE FROM hsg_admin_module_access WHERE admin_id=$QID" >/dev/null   # som en ældre bruger uden rækker
nfsql "INSERT IGNORE INTO hsg_admin_module_access(admin_id,module_id,can_view) VALUES($QID,'newsletter',0)" >/dev/null   # som migration 1.0.0 gør
Q="$NF/qa.jar"; : > "$Q"
QT=$(curl -s -b "$Q" -c "$Q" "$BASE/admin-login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -b "$Q" -c "$Q" -o /dev/null "$BASE/admin-login.php" --data-urlencode "csrf=$QT" -d username=qa-nyhedsbrev --data-urlencode 'password=Qa-Pass-12345!'
qc() { curl -s -b "$Q" -c "$Q" -o "$NF/q.html" -w '%{http_code}' "$1"; }
nfchk "K2 login-bruger uden adgang: 403 på alle tre administrationssider" '[ "$(qc "$BASE/newsletter.php")" = "403" ] && [ "$(qc "$BASE/newsletter_recipients.php")" = "403" ] && [ "$(qc "$BASE/newsletter_import.php")" = "403" ]'
qnone() { qc "$1" >/dev/null; ! grep -q "Nyhedsbrev" "$NF/q.html"; }
nfchk "K2 login-bruger uden adgang: ingen menupunkt og intet kort på Mere-siden" 'qnone "$BASE/index.php" && qnone "$BASE/admin.php"'
nfchk "K2 login-bruger uden adgang: POST med gyldigt csrf giver 403 og opretter intet" '
  QT=$(curl -s -b "$Q" -c "$Q" "$BASE/admin-account.php" | grep -o "name=\"csrf\" value=\"[^\"]*\"" | head -1 | sed "s/.*value=\"//;s/\"$//"); n0=$(nfsql "SELECT COUNT(*) FROM hsg_newsletter_recipients");
  [ "$(curl -s -b "$Q" -c "$Q" -o /dev/null -w "%{http_code}" "$BASE/newsletter_recipients.php" -d "csrf=$QT" -d action=create -d email=hack@x.dk)" = "403" ] && [ "$(nfsql "SELECT COUNT(*) FROM hsg_newsletter_recipients")" = "$n0" ]'
nfchk "Afv1 login-bruger uden rækker for eksisterende moduler har fortsat adgang til dem (products, catalog, stock)" '[ "$(qc "$BASE/products.php")" = "200" ] && [ "$(qc "$BASE/catalog.php")" = "200" ] && [ "$(qc "$BASE/stock.php")" = "200" ]'
nfpage "$BASE/users.php?tab=admins" >/dev/null
chk=$(php -r '$h=file_get_contents($argv[1]); $p=strpos($h,"value=\"create_admin\""); $e=strpos($h,"Opret login-bruger</button>",$p); $f=substr($h,$p,$e-$p);
  preg_match_all("/<input type=\"checkbox\" name=\"modules\[\]\" value=\"([a-z_]+)\"([^>]*)>/",$f,$m,PREG_SET_ORDER); $o=[]; foreach($m as $x) $o[$x[1]]=str_contains($x[2],"checked");
  echo (!empty($o["products"])&&!empty($o["catalog"])&&isset($o["newsletter"])&&$o["newsletter"]===false)?"ok":"fejl";' "$NF/page.html")
nfchk "Afv1 users.php: Nyhedsbrev er ikke afkrydset som standard, øvrige moduler er" '[ "$chk" = "ok" ]'
# modulet slås fra, superadmin gemmer brugeren (så newsletter-rækken forsvinder), modulet slås til: stadig 403
nftoggle 0
T=$(token "$BASE/users.php?tab=admins")
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/users.php?tab=admins" --data-urlencode "csrf=$T" -d action=update_admin_user -d admin_id="$QID" -d username=qa-nyhedsbrev -d display_name=QA -d is_superadmin=0 -d 'modules[]=catalog' -d 'modules[]=products'
nfchk "K2 efter gem i users.php mens modulet er slået fra, er newsletter-rækken væk" '[ "$(nfsql "SELECT COUNT(*) FROM hsg_admin_module_access WHERE admin_id=$QID AND module_id='"'"'newsletter'"'"'")" = "0" ]'
nftoggle 1
nfchk "K2 …og brugeren har stadig 403, når modulet er slået til igen" '[ "$(qc "$BASE/newsletter_recipients.php")" = "403" ] && [ "$(qc "$BASE/products.php")" = "200" ]'
# direkte link
T=$(token "$BASE/users.php?tab=links")
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/users.php?tab=links" --data-urlencode "csrf=$T" -d action=create --data-urlencode "name=QA Linkbruger"
nfpage "$BASE/users.php?tab=links" >/dev/null; LK=$(grep -o 'http://[^"<]*/?k=[a-f0-9]*' "$NF/page.html" | head -1)
L="$NF/link.jar"; : > "$L"; curl -s -L -b "$L" -c "$L" -o /dev/null "$LK"
lc() { curl -s -b "$L" -c "$L" -o "$NF/l.html" -w '%{http_code}' "$1"; }
nfchk "K3 direkte link: 403 på de tre administrationssider, og menuen har ikke Nyhedsbrev" '[ -n "$LK" ] && [ "$(lc "$BASE/newsletter.php")" = "403" ] && [ "$(lc "$BASE/newsletter_recipients.php")" = "403" ] && [ "$(lc "$BASE/newsletter_import.php")" = "403" ] && lc "$BASE/reservations.php" >/dev/null && ! grep -q "Nyhedsbrev" "$NF/l.html"'
nfpage "$BASE/user_permissions.php?id=$(nfsql "SELECT id FROM lager_users WHERE name='QA Linkbruger'")" >/dev/null
nfchk "K3 user_permissions.php viser ikke modulet Nyhedsbrev" '[ "$(nfpage "$BASE/user_permissions.php?id=$(nfsql "SELECT id FROM lager_users WHERE name='"'"'QA Linkbruger'"'"'")")" = "200" ] && ! php -r "\$h=file_get_contents(\$argv[1]); \$i=strpos(\$h,\"<table\"); \$j=strpos(\$h,\"</table>\"); exit(stripos(substr(\$h,\$i,\$j-\$i),\"nyhedsbrev\")!==false?0:1);" "$NF/page.html"'

# ---------- Kriterie 89 (stikprøve): afmeldingssidens tekster er på dansk ----------
nfchk "K89 afmeldingssiden: danske tekster (bekræftelse og 404)" 'curl -s "$URL23" | grep -q "allerede afmeldt" && curl -s "$BASE/nyhedsbrev-afmeld.php?t=abc" | grep -q "Linket er ugyldigt eller udløbet"'

rm -rf "$NF"

#!/usr/bin/env bash
# CSRF – testerens supplement (kun udvikling/CI, kommer ikke med i release).
#
# Læses ind af tests/run.sh i trin 4, efter tests/php_krav_tester.sh. Forudsætter, at run.sh har defineret:
#   ok, bad, info, BASE, JAR (admin-cookies), ROOT  – og at config.php findes. Kan ikke køres alene.
#
# Spec: docs/specs/2026-10-24-csrf-tom-token.md. Udviklerens blok A-C dækker manglende og tomt csrf. Her prøves også,
# gennem rigtige HTTP-anmodninger, at PHP's tolkning af felterne ikke åbner en bagdør:
#   - csrf som liste (csrf[]=x, csrf[a]=b), "Array", et forkert token og et 100 kB langt token
#   - multipart/form-data (som FormData i AJAX-kaldene) med og uden token
#   - mod admin-login.php (med GYLDIGE loginoplysninger), status.php fra en linksession uden token og to sider bag
#     auth.php (locations.php og products.php) med admin-sessionen
#   - at en afvist POST intet udfører (ingen lokation, ingen loginforsøg, sessionen er ikke logget ind)
#   - at en POST med sessionens eget token stadig virker (urlencoded og multipart)
# install.php før installationen kan ikke prøves her (den er allerede installeret). Den dækkes af blok A i run.sh.

info "CSRF (tester): liste, forkert token og multipart mod admin-login.php, status.php, locations.php og products.php"
CT=$(mktemp -d)
printf '%s' 'Ugyldig formularsession. Genindlæs siden og prøv igen.' > "$CT/forventet.txt"
ctsql() { php "$ROOT/tests/newsletter_sql.php" "$1"; }
ct_afvist() { # <beskrivelse> <http-kode>: 419 og præcis den kendte tekst (svaret ligger i $CT/svar.html)
  if [ "$2" = "419" ] && cmp -s "$CT/forventet.txt" "$CT/svar.html"; then ok "CSRF: $1 afvist (HTTP 419, samme tekst)"
  else bad "CSRF: $1 gav HTTP $2, ikke 419 med samme tekst ($(head -c 100 "$CT/svar.html" | tr '\n' ' '))"; fi
}
CT_VARIANTER=('csrf[]=x' 'csrf[a]=b' 'csrf[0]=x&csrf[1]=y' 'csrf=forkert' 'csrf=Array' 'csrf=0')

# 1. admin-login.php uden cookie, men med GYLDIGE loginoplysninger: login-CSRF må ikke lykkes
CT_ATT0=$(ctsql "SELECT COUNT(*) FROM lager_login_attempts")
for v in "${CT_VARIANTER[@]}"; do
  : > "$CT/jar"
  code=$(curl -s -b "$CT/jar" -c "$CT/jar" -o "$CT/svar.html" -w '%{http_code}' -d "$v" -d 'username=testadmin' --data-urlencode 'password=Test-Pass-12345!' "$BASE/admin-login.php")
  ct_afvist "admin-login.php med gyldige loginoplysninger og $v" "$code"
  code=$(curl -s -b "$CT/jar" -o /dev/null -w '%{http_code}' "$BASE/users.php")
  [ "$code" = "401" ] && ok "CSRF: sessionen er ikke logget ind efter afvist login med $v (users.php giver HTTP 401)" || bad "CSRF: users.php gav HTTP $code efter afvist login med $v (sessionen blev logget ind?)"
done
[ "$(ctsql "SELECT COUNT(*) FROM lager_login_attempts")" = "$CT_ATT0" ] && ok "CSRF: login-logikken blev aldrig nået (antal loginforsøg uændret)" || bad "CSRF: login-logikken blev nået af en POST uden gyldigt token"

# 2. status.php fra en linksession uden token (direkte link hentet uden at følge viderestillingen)
CT_LINK=$(grep -o '?k=[a-f0-9]\{64\}' /tmp/hsg-install.html | head -1)
if [ -z "$CT_LINK" ]; then bad "CSRF: fandt intet direkte link (?k=) i installationssiden"
else
  for v in "${CT_VARIANTER[@]}"; do
    : > "$CT/jar"
    curl -s -b "$CT/jar" -c "$CT/jar" -o /dev/null "$BASE/$CT_LINK"
    code=$(curl -s -b "$CT/jar" -c "$CT/jar" -o "$CT/svar.html" -w '%{http_code}' -d "$v" -d 'x=1' "$BASE/status.php")
    ct_afvist "status.php fra linksession uden token med $v" "$code"
  done
fi

# 3. Sider bag auth.php med admin-sessionen: ingen variant må udføre noget
CT_N0=$(ctsql "SELECT COUNT(*) FROM lager_locations")
for p in locations.php products.php; do
  for v in "${CT_VARIANTER[@]}" 'csrf=' 'x=1'; do
    code=$(curl -s -b "$JAR" -o "$CT/svar.html" -w '%{http_code}' -d "$v" -d 'action=save' -d 'name=CSRF-test-afvist' "$BASE/$p")
    ct_afvist "$p som admin med $v" "$code"
  done
done
# et 100 kB langt token og et token med specialtegn
php -r 'file_put_contents($argv[1], "csrf=".str_repeat("a", 100000)."&action=save&name=CSRF-test-afvist");' "$CT/langt.body"
code=$(curl -s -b "$JAR" -o "$CT/svar.html" -w '%{http_code}' -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$CT/langt.body" "$BASE/locations.php")
ct_afvist "locations.php som admin med et 100 kB langt token" "$code"
code=$(curl -s -b "$JAR" -o "$CT/svar.html" -w '%{http_code}' -d 'csrf=%C3%A6%C3%B8%C3%A5%3Cscript%3E%27' -d 'action=save' -d 'name=CSRF-test-afvist' "$BASE/locations.php")
ct_afvist "locations.php som admin med et token med æøå, script-tag og apostrof" "$code"
# uden login giver siderne fortsat 401 (ikke 419), uanset tokenets form
for p in locations.php products.php status.php; do
  for v in 'csrf[]=x' 'csrf='; do
    code=$(curl -s -o /dev/null -w '%{http_code}' -d "$v" "$BASE/$p")
    [ "$code" = "401" ] && ok "CSRF: $p uden login med $v giver HTTP 401" || bad "CSRF: $p uden login med $v gav HTTP $code, ikke 401"
  done
done

# 4. multipart/form-data (FormData)
for fld in 'csrf[]=x' 'csrf[a]=b' 'csrf=forkert' 'csrf='; do
  code=$(curl -s -b "$JAR" -o "$CT/svar.html" -w '%{http_code}' -F "$fld" -F 'action=save' -F 'name=CSRF-test-afvist' "$BASE/locations.php")
  ct_afvist "locations.php som admin, multipart med $fld" "$code"
done
code=$(curl -s -b "$JAR" -o "$CT/svar.html" -w '%{http_code}' -F 'action=save' -F 'name=CSRF-test-afvist' "$BASE/locations.php")
ct_afvist "locations.php som admin, multipart uden csrf" "$code"
[ "$(ctsql "SELECT COUNT(*) FROM lager_locations")" = "$CT_N0" ] && ok "CSRF: ingen afvist POST oprettede en lokation" || bad "CSRF: en afvist POST oprettede en lokation"

# 5. Kontrol: sessionens eget token virker stadig (urlencoded og multipart), og siden skriver i audit-loggen
CT_T=$(token "$BASE/locations.php")
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$CT_T" -d 'action=save' -d 'name=CSRF-test-urlencoded' "$BASE/locations.php")
{ [ -n "$CT_T" ] && [ "$code" = "302" ] && [ "$(ctsql "SELECT COUNT(*) FROM lager_locations WHERE name='CSRF-test-urlencoded'")" = "1" ]; } && ok "CSRF: locations.php med sessionens token (urlencoded) opretter lokationen (HTTP 302)" || bad "CSRF: locations.php med sessionens token (urlencoded) gav HTTP $code (token fundet: $([ -n "$CT_T" ] && echo ja || echo nej))"
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -F "csrf=$CT_T" -F 'action=save' -F 'name=CSRF-test-multipart' "$BASE/locations.php")
{ [ "$code" = "302" ] && [ "$(ctsql "SELECT COUNT(*) FROM lager_locations WHERE name='CSRF-test-multipart'")" = "1" ]; } && ok "CSRF: locations.php med sessionens token (multipart) opretter lokationen (HTTP 302)" || bad "CSRF: locations.php med sessionens token (multipart) gav HTTP $code"
ctsql "DELETE FROM lager_locations WHERE name LIKE 'CSRF-test-%'" >/dev/null
rm -rf "$CT"

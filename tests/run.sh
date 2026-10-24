#!/usr/bin/env bash
# HSG Administration – samlet testpakke (kun udvikling/CI, kommer ikke med i release).
#
#   bash tests/run.sh
#
# Røgtesten med database kører kun hvis disse er sat (ellers springes den over):
#   HSG_TEST_DB_HOST, HSG_TEST_DB_NAME, HSG_TEST_DB_USER, HSG_TEST_DB_PASS
# OBS: Brug en TOM testdatabase – aldrig produktionsdatabasen.
set -u
cd "$(dirname "$0")/.."
ROOT=$(pwd)
FAIL=0
ok()   { echo -e "\033[32m[OK]\033[0m $*"; }
bad()  { echo -e "\033[31m[FEJL]\033[0m $*"; FAIL=1; }
info() { echo -e "\033[34m[INFO]\033[0m $*"; }

# 1. PHP-syntaks
info "1/4 PHP-syntakstjek"
LINT_ERR=0
while IFS= read -r -d '' f; do
  out=$(php -l "$f" 2>&1) || { echo "$out"; LINT_ERR=1; }
done < <(find . -name '*.php' -not -path './storage/*' -not -path './vendor/*' -not -path './.git/*' -print0)
[ $LINT_ERR -eq 0 ] && ok "Alle PHP-filer er syntaktisk korrekte" || bad "Syntaksfejl fundet"

# 2. Enhedstest af rene funktioner (ingen database)
info "2/4 Enhedstest af nyhedsbrevets rene funktioner"
if out=$(php tests/newsletter_test.php 2>&1); then
  ok "$out"
else
  echo "$out"; bad "Enhedstest af nyhedsbrevet fejlede"
fi
info "Enhedstest: Kims uploadfil og fadnummer-udledning (ingen ZipArchive)"
if php tests/supplier_import_test.php; then
  ok "Enhedstest: Kims uploadfil bestået"
else
  bad "Enhedstest: Kims uploadfil fejlede"
fi
if php tests/product_enrichment_test.php; then
  ok "Enhedstest: fadnummer-udledning bestået"
else
  bad "Enhedstest: fadnummer-udledning fejlede"
fi

# PHP-krav: fællesfunktionerne i core/php_requirements.php og updaterens tjek (spec 2026-10-23-mbstring-krav)
if out=$(php tests/php_requirements_test.php 2>&1); then ok "$out"; else echo "$out"; bad "Enhedstest af PHP-kravene fejlede"; fi
# Samme enhedstest uden mbstring, hvis der er angivet en ini-fil uden mbstring (spec afsnit 7.1)
if [ -n "${HSG_TEST_PHP_INI_UDEN_MBSTRING:-}" ]; then
  if out=$(PHP_INI_SCAN_DIR= php -c "$HSG_TEST_PHP_INI_UDEN_MBSTRING" tests/php_requirements_test.php --uden-mbstring 2>&1); then ok "Uden mbstring: $out"; else echo "$out"; bad "Enhedstest af PHP-kravene uden mbstring fejlede"; fi
else info "Test uden mbstring sprunget over (HSG_TEST_PHP_INI_UDEN_MBSTRING ikke sat)"; fi

# 3. Release-bygger: negativtest (forbudte filer må aldrig komme med)
info "3/4 Release-bygger negativtest"
if php tools/build-release.php --test-negative >/tmp/hsg-build-neg.log 2>&1; then
  ok "Release-negativtest bestået"
else
  cat /tmp/hsg-build-neg.log; bad "Release-negativtest fejlede"
fi
# PHP-krav (tester): bygget ZIP har de krævede udvidelser, og negativtest B og C afvises af den tilsigtede grund (spec 2026-10-23-mbstring-krav, kriterie 122-123)
if out=$(php tests/php_krav_release_test.php 2>&1); then ok "$out"; else echo "$out"; bad "Release-test af PHP-kravene fejlede"; fi

# 4. Installation + røgtest af sider (kræver testdatabase)
if [ -z "${HSG_TEST_DB_NAME:-}" ]; then
  info "4/4 Røgtest sprunget over (HSG_TEST_DB_* ikke sat)"
else
  info "4/4 Installation og røgtest mod ${HSG_TEST_DB_NAME}"
  if [ -f config.php ]; then
    bad "config.php findes allerede – kør røgtest i en ren kopi (fx git worktree), ikke i en installation"
  else
    PORT=${HSG_TEST_PORT:-8787}; BASE="http://127.0.0.1:$PORT"; JAR=$(mktemp)
    # Uploadgrænserne ligger over nyhedsbrevsimportens egen grænse på 5 MB, så testen rammer den.
    php -d upload_max_filesize=16M -d post_max_size=24M -S 127.0.0.1:$PORT -t "$ROOT" >/tmp/hsg-php-server.log 2>&1 & SRV=$!
    trap 'kill $SRV 2>/dev/null; rm -f "$ROOT/config.php" "$JAR"' EXIT
    sleep 1
    token() { curl -s -b "$JAR" -c "$JAR" "$1" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }

    T=$(token "$BASE/install.php")
    curl -s -b "$JAR" -c "$JAR" -o /tmp/hsg-install.html -w '%{http_code}' "$BASE/install.php" \
      --data-urlencode "csrf=$T" --data-urlencode "db_host=${HSG_TEST_DB_HOST:-127.0.0.1}" \
      --data-urlencode "db_name=$HSG_TEST_DB_NAME" --data-urlencode "db_user=${HSG_TEST_DB_USER:-root}" \
      --data-urlencode "db_pass=${HSG_TEST_DB_PASS:-}" --data-urlencode "admin_name=Test Admin" \
      --data-urlencode "admin_username=testadmin" --data-urlencode "admin_password=Test-Pass-12345!" \
      --data-urlencode "admin_password_confirm=Test-Pass-12345!" --data-urlencode "seed_stock=1" >/dev/null
    [ -f config.php ] && ok "Installation gennemført" || { bad "Installation fejlede (se /tmp/hsg-install.html)"; exit 1; }

    T=$(token "$BASE/admin-login.php")
    curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/admin-login.php" \
      --data-urlencode "csrf=$T" --data-urlencode "username=testadmin" --data-urlencode "password=Test-Pass-12345!"
    if curl -s -b "$JAR" "$BASE/users.php" | grep -q 'name="password"'; then
      bad "Admin-login lykkedes ikke – røgtesten ville kun se login-siden"; exit 1
    else ok "Admin-login lykkedes"; fi

    # Hver side skal svare uden 500 og uden PHP-fejl i output
    PAGES="admin.php status.php products.php brands.php stock.php reservations.php locations.php
           catalog.php reports.php quality.php users.php system.php backup.php import_center.php
           supplier_upload.php image_check.php update.php admin-account.php
           newsletter.php newsletter_recipients.php newsletter_import.php
           products.php?status=all&filter=cask_no_digit"
    for p in $PAGES; do
      [ -f "${p%%\?*}" ] || continue
      code=$(curl -s -b "$JAR" -o /tmp/hsg-page.html -w '%{http_code}' "$BASE/$p")
      if [ "$code" -ge 500 ] || grep -qiE 'Fatal error|Parse error|Uncaught|Warning:' /tmp/hsg-page.html; then
        bad "$p → HTTP $code / PHP-fejl i output"
      else ok "$p → HTTP $code"; fi
    done

    # Sikkerhed: admin-side uden login skal afvises
    code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/users.php")
    [ "$code" != "200" ] && ok "users.php uden login afvist (HTTP $code)" || bad "users.php er tilgængelig uden login!"
    # Sikkerhed: POST uden CSRF skal afvises
    code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -d 'x=1' "$BASE/locations.php")
    [ "$code" = "419" ] || [ "$code" = "403" ] && ok "POST uden CSRF afvist (HTTP $code)" || bad "POST uden CSRF gav HTTP $code"
    # Sikkerhed: status fra URL'en må ikke kunne bryde ud af href i Produkter (XSS-regression)
    code=$(curl -s -g -b "$JAR" -o /tmp/hsg-page.html -w '%{http_code}' "$BASE/products.php?status=%22%3E%3Cscript%3Ealert(1)%3C/script%3E")
    if [ "$code" != "200" ] || grep -qiE 'Fatal error|Parse error|Uncaught|Warning:' /tmp/hsg-page.html; then
      bad "products.php med manipuleret status → HTTP $code / PHP-fejl i output"
    elif grep -qF '"><script>alert(1)' /tmp/hsg-page.html; then
      bad "products.php med manipuleret status skriver status uescapet (XSS)"
    else ok "products.php med manipuleret status er escapet (HTTP $code)"; fi

    # Kims uploadfil: de nye POST-handlinger skal afvises uden CSRF (419) og uden login (401)
    for act in reservation_mode reguess; do
      code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -d "action=$act&preview_token=x" "$BASE/supplier_upload.php")
      [ "$code" = "419" ] && ok "supplier_upload.php $act uden CSRF afvist (HTTP 419)" || bad "supplier_upload.php $act uden CSRF gav HTTP $code"
      code=$(curl -s -o /dev/null -w '%{http_code}' -d "action=$act&preview_token=x" "$BASE/supplier_upload.php")
      [ "$code" = "401" ] && ok "supplier_upload.php $act uden login afvist (HTTP 401)" || bad "supplier_upload.php $act uden login gav HTTP $code"
    done

    # Kims uploadfil: preview, apply og create mod den installerede testdatabase (rydder selv op)
    if php tests/supplier_import_db_test.php; then ok "Databasetest: Kims uploadfil bestået"; else bad "Databasetest: Kims uploadfil fejlede"; fi

    # Fadnummer uden ciffer: modulmigrationen products 1.5.1 på den friske installation og oprydningen (rydder selv op)
    if php tests/cask_number_cleanup_db_test.php; then ok "Databasetest: fadnummer-oprydning bestået"; else bad "Databasetest: fadnummer-oprydning fejlede"; fi

    # Kims uploadfil: hele flowet via HTTP (upload, kolonnegæt, reservationsvalg, anvend, audit-log). Rydder selv op.
    if php tests/supplier_upload_flow_test.php "$BASE" testadmin 'Test-Pass-12345!'; then ok "Flowtest: Kims uploadfil bestået"; else bad "Flowtest: Kims uploadfil fejlede"; fi

    # Nyhedsbrev: administrationssider kræver login og CSRF
    for p in newsletter.php newsletter_recipients.php newsletter_import.php; do
      code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$p")
      [ "$code" = "401" ] && ok "$p uden login afvist (HTTP 401)" || bad "$p uden login gav HTTP $code"
    done
    for p in newsletter_recipients.php newsletter_import.php; do
      code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -d 'action=create&email=x%40example.dk' "$BASE/$p")
      [ "$code" = "419" ] && ok "$p: POST uden CSRF afvist (HTTP 419)" || bad "$p: POST uden CSRF gav HTTP $code"
    done

    # Nyhedsbrev: offentlig afmeldingsside med ugyldigt token giver 404 uden PHP-fejl
    ZERO=$(printf '0%.0s' $(seq 1 64))
    for q in "t=" "t=abc" "t=$ZERO" "t=${ZERO}0" "t=$(printf 'g%.0s' $(seq 1 64))"; do
      for m in GET POST; do
        if [ "$m" = "POST" ]; then
          code=$(curl -s -o /tmp/hsg-page.html -w '%{http_code}' -d 'List-Unsubscribe=One-Click' "$BASE/nyhedsbrev-afmeld.php?$q")
        else
          code=$(curl -s -o /tmp/hsg-page.html -w '%{http_code}' "$BASE/nyhedsbrev-afmeld.php?$q")
        fi
        if [ "$code" = "404" ] && ! grep -qiE 'Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:' /tmp/hsg-page.html; then
          ok "nyhedsbrev-afmeld.php $m ?${q:0:12}… → HTTP 404"
        else bad "nyhedsbrev-afmeld.php $m ?${q:0:12}… → HTTP $code / PHP-fejl i output"; fi
      done
    done
    hdr=$(curl -s -D - -o /dev/null "$BASE/nyhedsbrev-afmeld.php?t=$ZERO")
    if echo "$hdr" | grep -qi '^Referrer-Policy: no-referrer' && echo "$hdr" | grep -qi '^X-Robots-Tag: noindex' && echo "$hdr" | grep -qi '^Cache-Control: no-store'; then
      ok "nyhedsbrev-afmeld.php sender Referrer-Policy, X-Robots-Tag og Cache-Control"
    else bad "nyhedsbrev-afmeld.php mangler sikkerhedsheadere"; fi

    # Nyhedsbrev trin 1: funktionel test af acceptkriterierne (import, afmelding, GDPR, adgang)
    source "$ROOT/tests/newsletter_funktionel.sh"

    # Nyhedsbrev, opfølgning (testerens supplement): felter sendt som lister, id[]-angreb og oprydning af forhåndsvisningen
    source "$ROOT/tests/newsletter_post_input.sh"

    # PHP-krav: hurtigsøgningen i core/bootstrap.php svarer med JSON (spec 2026-10-23-mbstring-krav, kriterie 115)
    source "$ROOT/tests/php_krav_smoke.sh"

    # PHP-krav, testerens supplement: Systemstatus og install.php med og uden mbstring (kriterie 116-120)
    source "$ROOT/tests/php_krav_tester.sh"

    grep -iE 'Fatal|Uncaught' /tmp/hsg-php-server.log && bad "PHP-fejl i serverlog"
  fi
fi

echo
[ $FAIL -eq 0 ] && { ok "ALLE TESTS BESTÅET"; exit 0; } || { bad "TESTS FEJLEDE"; exit 1; }

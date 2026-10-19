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

# 2. Release-bygger: negativtest (forbudte filer må aldrig komme med)
info "2/4 Release-bygger negativtest"
if php tools/build-release.php --test-negative >/tmp/hsg-build-neg.log 2>&1; then
  ok "Release-negativtest bestået"
else
  cat /tmp/hsg-build-neg.log; bad "Release-negativtest fejlede"
fi

# 3. Enhedstest af rene funktioner (ingen database, ingen ZipArchive)
info "3/4 Enhedstest: Kims uploadfil"
if php tests/supplier_import_test.php; then
  ok "Enhedstest: Kims uploadfil bestået"
else
  bad "Enhedstest: Kims uploadfil fejlede"
fi

# 4. Installation + røgtest af sider (kræver testdatabase)
if [ -z "${HSG_TEST_DB_NAME:-}" ]; then
  info "4/4 Røgtest sprunget over (HSG_TEST_DB_* ikke sat)"
else
  info "4/4 Installation og røgtest mod ${HSG_TEST_DB_NAME}"
  if [ -f config.php ]; then
    bad "config.php findes allerede – kør røgtest i en ren kopi (fx git worktree), ikke i en installation"
  else
    PORT=${HSG_TEST_PORT:-8787}; BASE="http://127.0.0.1:$PORT"; JAR=$(mktemp)
    php -S 127.0.0.1:$PORT -t "$ROOT" >/tmp/hsg-php-server.log 2>&1 & SRV=$!
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
           supplier_upload.php image_check.php update.php admin-account.php"
    for p in $PAGES; do
      [ -f "$p" ] || continue
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

    grep -iE 'Fatal|Uncaught' /tmp/hsg-php-server.log && bad "PHP-fejl i serverlog"
  fi
fi

echo
[ $FAIL -eq 0 ] && { ok "ALLE TESTS BESTÅET"; exit 0; } || { bad "TESTS FEJLEDE"; exit 1; }

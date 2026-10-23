#!/usr/bin/env bash
# PHP-krav – testerens supplement (kun udvikling/CI, kommer ikke med i release).
#
# Læses ind af tests/run.sh i trin 4, efter tests/php_krav_smoke.sh. Forudsætter, at run.sh har defineret:
#   ok, bad, info, BASE, JAR (admin-cookies), ROOT  – og at config.php findes.
# Kan ikke køres alene.
#
# Dækker (spec 2026-10-23-mbstring-krav):
#   Altid (alle udvidelser):
#     120  admin.php viser "✓ Alle aktiveret" og hele listen, uden advarsel.
#     119  (sidste afsnit) install.php i en ren kopi viser formularen, og en POST uden/med forkert csrf giver 419
#          og ændrer intet.
#   Kun hvis HSG_TEST_PHP_INI_UDEN_MBSTRING peger på en ini-fil uden mbstring (spec afsnit 7.1):
#     116  hurtigsøgningen giver HTTP 200 og gyldig JSON uden Fatal/Uncaught.
#     117  låsen "Systemet er allerede installeret" kommer før tjekket af udvidelser.
#     118  install.php i en ren kopi (uden config.php) viser manglesiden uden formular.
#     119  en POST med alle installationsfelter (med/uden csrf, med forfalskede felter) giver manglesiden,
#          skriver ikke config.php og opretter ikke administratoren i databasen.
#     120  admin.php viser "🔴 Mangler: mbstring" og den danske besked.
#
# Alle servere her startes med opcache slået fra. To php -S med forskellige udvidelser på samme filer
# deler ellers opcache-hukommelse på Windows og kan finde på at kalde den forkerte funktion (fx curl_init -> imagecolorset).

info "PHP-krav (tester): systemstatus og installation med alle udvidelser"
TK=$(mktemp -d)
TKPORT=$(( ${HSG_TEST_PORT:-8787} + 20 ))
TKPIDS=()
TKHELP="Slå udvidelsen til i webhotellets kontrolpanel under PHP-indstillinger"
tk_stop() { local p; for p in "${TKPIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done; TKPIDS=(); }
# tk_start <ini eller tom> <port> <rod> <log>
tk_start() {
  local ini="$1" port="$2" root="$3" log="$4" args i
  args=(-d opcache.enable=0 -d display_errors=1 -d error_reporting=-1 -d upload_max_filesize=16M -d post_max_size=24M)
  [ -n "$ini" ] && args=(-c "$ini" "${args[@]}")
  php "${args[@]}" -S 127.0.0.1:$port -t "$root" >"$log" 2>&1 & TKPIDS+=($!)
  for i in $(seq 1 40); do curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$port/assets/style.css" && return 0; sleep 0.25; done
  return 1
}
tkhas()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3: '$2' findes ikke i svaret"; fi; }
tkhasnt(){ if grep -qF -- "$2" "$1"; then bad "$3: '$2' findes i svaret"; else ok "$3"; fi; }
tksql()  { php "$ROOT/tests/newsletter_sql.php" "$1"; }
# En ren kopi af de sporede filer (ingen config.php, uploads eller storage-data)
tk_copy() {
  mkdir -p "$1"
  (cd "$ROOT" && git ls-files -z | tar --null -T - -cf - 2>/dev/null) | tar -xf - -C "$1"
  [ -f "$1/install.php" ] && [ ! -e "$1/config.php" ]
}
TKADM="pkkrav-tester-adm"
TKFIELDS=(--data-urlencode "db_host=${HSG_TEST_DB_HOST:-127.0.0.1}" --data-urlencode "db_name=${HSG_TEST_DB_NAME:-}" --data-urlencode "db_user=${HSG_TEST_DB_USER:-root}"
          --data-urlencode "db_pass=${HSG_TEST_DB_PASS:-}" --data-urlencode "admin_name=Tester Admin" --data-urlencode "admin_username=$TKADM"
          --data-urlencode "admin_password=Test-Pass-12345!" --data-urlencode "admin_password_confirm=Test-Pass-12345!" --data-urlencode "seed_stock=1")

# --- 120 (alle udvidelser): Systemstatus
curl -s -b "$JAR" -o "$TK/admin.html" "$BASE/admin.php"
tkhas "$TK/admin.html" '✓ Alle aktiveret' "120: admin.php viser '✓ Alle aktiveret' med alle udvidelser"
tkhas "$TK/admin.html" 'pdo, pdo_mysql, zip, json, gd, curl, mbstring, openssl' "120: admin.php viser hele listen over krævede udvidelser"
tkhasnt "$TK/admin.html" 'Webhotellet mangler' "120: admin.php har ingen besked om manglende udvidelser med alle udvidelser"
tkhasnt "$TK/admin.html" 'Mangler:' "120: admin.php har ingen rød 'Mangler:'-badge med alle udvidelser"

# --- En ren kopi med alle udvidelser: formularen og CSRF som før
if tk_copy "$TK/rent-fuld" && tk_start "" $((TKPORT + 1)) "$TK/rent-fuld" "$TK/rent-fuld.log"; then
  TKB="http://127.0.0.1:$((TKPORT + 1))"; TKJ="$TK/jar-fuld"
  c=$(curl -s -c "$TKJ" -b "$TKJ" -o "$TK/fuld-get.html" -w '%{http_code}' "$TKB/install.php")
  [ "$c" = "200" ] && ok "119: install.php i ren kopi med alle udvidelser: HTTP 200" || bad "119: install.php i ren kopi med alle udvidelser gav HTTP $c"
  tkhas "$TK/fuld-get.html" 'name="db_host"' "119: installationsformularen vises med alle udvidelser"
  tkhasnt "$TK/fuld-get.html" 'Webhotellet mangler' "119: ingen manglebesked med alle udvidelser"
  c=$(curl -s -c "$TKJ" -b "$TKJ" -o "$TK/fuld-p1.html" -w '%{http_code}' "$TKB/install.php" "${TKFIELDS[@]}")
  [ "$c" = "419" ] && ok "119: POST uden csrf (session med token) giver HTTP 419" || bad "119: POST uden csrf gav HTTP $c, ikke 419"
  c=$(curl -s -c "$TKJ" -b "$TKJ" -o "$TK/fuld-p2.html" -w '%{http_code}' "$TKB/install.php" --data-urlencode "csrf=forkert" "${TKFIELDS[@]}")
  [ "$c" = "419" ] && ok "119: POST med forkert csrf giver HTTP 419" || bad "119: POST med forkert csrf gav HTTP $c, ikke 419"
  [ ! -e "$TK/rent-fuld/config.php" ] && ok "119: ingen config.php efter afviste POST med alle udvidelser" || bad "119: config.php blev skrevet af en POST uden gyldigt csrf"
  [ "$(tksql "SELECT COUNT(*) FROM lager_admins WHERE username='$TKADM'")" = "0" ] && ok "119: afviste POST oprettede ingen administrator" || bad "119: administrator '$TKADM' blev oprettet af en POST uden gyldigt csrf"
else
  bad "PHP-krav (tester): kunne ikke lave en ren kopi/starte server med alle udvidelser"
fi
tk_stop

if [ -z "${HSG_TEST_PHP_INI_UDEN_MBSTRING:-}" ]; then
  info "PHP-krav (tester): test af server uden mbstring (116-120) sprunget over (HSG_TEST_PHP_INI_UDEN_MBSTRING ikke sat)"
else
  info "PHP-krav (tester): server uden mbstring (kriterie 116-120)"
  TKINI="$HSG_TEST_PHP_INI_UDEN_MBSTRING"
  tkenv=$(php -c "$TKINI" -r 'foreach(["mbstring","pdo_mysql","zip","gd","curl","openssl"] as $e) echo $e,"=",extension_loaded($e)?1:0," ";' 2>/dev/null)
  if [ "$tkenv" != "mbstring=0 pdo_mysql=1 zip=1 gd=1 curl=1 openssl=1 " ]; then
    bad "PHP-krav (tester): ini-filen uden mbstring er ugyldig (fik '$tkenv')"
  else
    ok "PHP-krav (tester): miljøet uden mbstring er rigtigt (mbstring=0, de øvrige 1)"

    # --- Den installerede kopi (ROOT, med config.php) på en server uden mbstring
    : > "$TK/rod.log"; TKL0=0
    if tk_start "$TKINI" $((TKPORT + 2)) "$ROOT" "$TK/rod.log"; then
      TKN="http://127.0.0.1:$((TKPORT + 2))"
      # 117: låsen kommer først
      c=$(curl -s -o "$TK/lock-get.html" -w '%{http_code}' "$TKN/install.php")
      [ "$c" = "200" ] && grep -qF 'Systemet er allerede installeret' "$TK/lock-get.html" && ok "117: uden mbstring og med config.php viser GET 'Systemet er allerede installeret'" || bad "117: GET uden mbstring med config.php gav HTTP $c uden låsesiden"
      tkhasnt "$TK/lock-get.html" 'Webhotellet mangler' "117: låsen kommer før tjekket af udvidelser (GET)"
      c=$(curl -s -o "$TK/lock-post.html" -w '%{http_code}' "$TKN/install.php" "${TKFIELDS[@]}")
      grep -qF 'Systemet er allerede installeret' "$TK/lock-post.html" && ok "117: POST uden mbstring med config.php giver låsesiden (HTTP $c)" || bad "117: POST uden mbstring med config.php gav ikke låsesiden (HTTP $c)"
      tkhasnt "$TK/lock-post.html" 'Webhotellet mangler' "117: låsen kommer før tjekket af udvidelser (POST)"

      # 116: hurtigsøgningen
      TKQ="$TKN/core/bootstrap.php?action=quick_search"
      tkq() { # navn q [curl-argumenter]
        local n="$1" q="$2"; shift 2
        curl -s "$@" -o "$TK/q-$n.json" -w '%{http_code}|%{content_type}' "$TKQ&q=$q"
      }
      for spec in "aabn:%C3%85BN" "aa:%C3%85" "anon:a"; do
        n=${spec%%:*}; q=${spec#*:}
        if [ "$n" = "anon" ]; then r=$(tkq "$n" "$q"); else r=$(tkq "$n" "$q" -b "$JAR"); fi
        if [ "$r" = "200|application/json; charset=utf-8" ] && php -r '$j=json_decode((string)file_get_contents($argv[1]),true); exit(is_array($j) && array_key_exists("results",$j) && is_array($j["results"]) ? 0 : 1);' "$TK/q-$n.json" \
           && ! grep -qiE 'Fatal|Uncaught|Warning' "$TK/q-$n.json"; then ok "116: uden mbstring, q=$q ($n): HTTP 200, JSON med results, ingen PHP-fejl"
        else bad "116: uden mbstring, q=$q ($n): $r $(head -c 200 "$TK/q-$n.json")"; fi
      done
      [ "$(cat "$TK/q-anon.json")" = '{"results":[]}' ] && ok "116: uden login giver {\"results\":[]} også uden mbstring" || bad "116: uden login gav data uden mbstring"
      # 120 (uden mbstring)
      curl -s -b "$JAR" -o "$TK/admin-nomb.html" "$TKN/admin.php"
      tkhas "$TK/admin-nomb.html" '🔴 Mangler: mbstring' "120: uden mbstring viser admin.php '🔴 Mangler: mbstring'"
      tkhas "$TK/admin-nomb.html" 'Webhotellet mangler PHP-udvidelsen mbstring. '"$TKHELP"', eller bed webhotellets support om det. Næste opgradering kan ikke installeres, før det er gjort.' "120: uden mbstring viser admin.php beskeden og 'Næste opgradering kan ikke installeres'"
      tkhas "$TK/admin-nomb.html" 'class="flash error" role="alert"' "120: uden mbstring ligger beskeden i en flash error"
      tkhasnt "$TK/admin-nomb.html" '✓ Alle aktiveret' "120: uden mbstring står der ikke '✓ Alle aktiveret'"
      tkhasnt "$TK/admin-nomb.html" 'Fatal error' "120: admin.php uden mbstring uden PHP-fejl"
      if tail -n +"$((TKL0 + 1))" "$TK/rod.log" 2>/dev/null | grep -qiE 'Fatal|Uncaught|Warning'; then bad "116/120: Fatal/Warning/Uncaught i serverloggen uden mbstring"; else ok "116/120: ingen Fatal/Warning/Uncaught i serverloggen uden mbstring"; fi
    else bad "PHP-krav (tester): kunne ikke starte serveren uden mbstring på den installerede kopi"; fi
    tk_stop

    # --- En ren kopi uden config.php på en server uden mbstring
    if tk_copy "$TK/rent-nomb" && tk_start "$TKINI" $((TKPORT + 3)) "$TK/rent-nomb" "$TK/rent-nomb.log"; then
      TKB="http://127.0.0.1:$((TKPORT + 3))"; TKJ="$TK/jar-nomb"
      c=$(curl -s -c "$TKJ" -b "$TKJ" -o "$TK/nomb-get.html" -w '%{http_code}' "$TKB/install.php")
      [ "$c" = "200" ] && ok "118: GET install.php uden mbstring: HTTP 200" || bad "118: GET install.php uden mbstring gav HTTP $c"
      tkhas "$TK/nomb-get.html" 'Webhotellet mangler PHP-udvidelsen mbstring.' "118: siden siger 'Webhotellet mangler PHP-udvidelsen mbstring.'"
      tkhas "$TK/nomb-get.html" "$TKHELP" "118: siden forklarer, hvad man gør"
      tkhas "$TK/nomb-get.html" '<span class="badge red">Mangler</span> mbstring' "118: mbstring står som Mangler"
      for e in pdo pdo_mysql zip json gd curl openssl; do
        tkhas "$TK/nomb-get.html" "<span class=\"badge green\">OK</span> $e<" "118: $e står som OK"
      done
      tkhas "$TK/nomb-get.html" '<a class="button" href="install.php">Tjek igen</a>' "118: der er et 'Tjek igen'-link til install.php"
      tkhasnt "$TK/nomb-get.html" 'name="db_host"' "118: der er intet db_host-felt"
      tkhasnt "$TK/nomb-get.html" '<form' "118: der er ingen <form"
      if grep -qiE 'Fatal|Warning|Notice|Deprecated|Uncaught|Stack trace| on line |[A-Za-z]:\\|/tmp/|/home/' "$TK/nomb-get.html"; then bad "118: manglesiden afslører stier eller PHP-fejl"; else ok "118: manglesiden afslører hverken stier eller PHP-fejl"; fi
      # 119: POST med alle felter (uden cookie og csrf, med session og uden csrf, med forkert csrf, med forfalskede felter)
      c1=$(curl -s -o "$TK/nomb-p1.html" -w '%{http_code}' "$TKB/install.php" "${TKFIELDS[@]}")
      c2=$(curl -s -c "$TKJ" -b "$TKJ" -o "$TK/nomb-p2.html" -w '%{http_code}' "$TKB/install.php" "${TKFIELDS[@]}")
      c3=$(curl -s -c "$TKJ" -b "$TKJ" -o "$TK/nomb-p3.html" -w '%{http_code}' "$TKB/install.php" --data-urlencode "csrf=forkert" "${TKFIELDS[@]}")
      tok=$(curl -s -c "$TKJ" -b "$TKJ" "$TKB/install.php" | grep -o 'name="csrf" value="[^"]*"' | head -1)
      c4=$(curl -s -c "$TKJ" -b "$TKJ" -o "$TK/nomb-p4.html" -w '%{http_code}' "$TKB/install.php" -d 'db_host[]=x&db_name[]=y&csrf[]=z&seed_stock[]=1&admin_password=aaaaaaaaaaaaaaaaaaaa')
      for n in 1 2 3 4; do
        eval "cc=\$c$n"
        if [ "$cc" = "200" ] && cmp -s "$TK/nomb-get.html" "$TK/nomb-p$n.html"; then ok "119: POST nr. $n uden mbstring giver præcis manglesiden (HTTP 200)"
        else bad "119: POST nr. $n uden mbstring gav HTTP $cc og ikke præcis manglesiden"; fi
      done
      [ -z "$tok" ] && ok "119: manglesiden indeholder ingen csrf-token" || bad "119: manglesiden indeholder et csrf-felt"
      [ ! -e "$TK/rent-nomb/config.php" ] && ok "119: config.php er ikke skrevet" || bad "119: config.php blev skrevet uden mbstring"
      [ "$(tksql "SELECT COUNT(*) FROM lager_admins WHERE username='$TKADM'")" = "0" ] && ok "119: databasen er ikke rørt (administratoren blev ikke oprettet)" || bad "119: administratoren blev oprettet uden mbstring"
      if grep -qiE 'Fatal|Uncaught|Warning' "$TK/rent-nomb.log"; then bad "118/119: Fatal/Warning/Uncaught i serverloggen for den rene kopi"; else ok "118/119: ingen Fatal/Warning/Uncaught i serverloggen for den rene kopi"; fi
    else bad "PHP-krav (tester): kunne ikke lave en ren kopi/starte server uden mbstring"; fi
    tk_stop
    # --- Flere udvidelser mangler på én gang (mbstring og openssl): listen og den fælles besked bruger alle krævede udvidelser
    sed -E '/^[[:space:]]*extension[[:space:]]*=[[:space:]]*"?(php_)?openssl(\.dll|\.so)?"?[[:space:]]*$/d' "$TKINI" > "$TK/uden-mb-openssl.ini"
    tkenv2=$(php -c "$TK/uden-mb-openssl.ini" -r 'foreach(["mbstring","openssl","pdo_mysql","zip","gd","curl"] as $e) echo $e,"=",extension_loaded($e)?1:0," ";' 2>/dev/null)
    if [ "$tkenv2" != "mbstring=0 openssl=0 pdo_mysql=1 zip=1 gd=1 curl=1 " ]; then
      info "PHP-krav (tester): test uden både mbstring og openssl sprunget over (kunne ikke afledes en ini-fil uden openssl; fik '$tkenv2')"
    elif tk_copy "$TK/rent-2" && tk_start "$TK/uden-mb-openssl.ini" $((TKPORT + 4)) "$TK/rent-2" "$TK/rent-2.log"; then
      curl -s -o "$TK/to-get.html" "http://127.0.0.1:$((TKPORT + 4))/install.php"
      tkhas "$TK/to-get.html" 'Webhotellet mangler PHP-udvidelserne mbstring og openssl. Slå udvidelserne til i webhotellets kontrolpanel' "118: mangler både mbstring og openssl: besked i flertal med 'mbstring og openssl'"
      tkhas "$TK/to-get.html" '<span class="badge red">Mangler</span> mbstring' "118: mbstring står som Mangler (to mangler)"
      tkhas "$TK/to-get.html" '<span class="badge red">Mangler</span> openssl' "118: openssl står som Mangler (to mangler)"
      tkhas "$TK/to-get.html" '<span class="badge green">OK</span> curl<' "118: curl står stadig som OK (to mangler)"
      tkhasnt "$TK/to-get.html" '<form' "118: ingen formular, når både mbstring og openssl mangler"
      tk_stop
      if tk_start "$TK/uden-mb-openssl.ini" $((TKPORT + 5)) "$ROOT" "$TK/rod-2.log"; then
        curl -s -b "$JAR" -o "$TK/admin-to.html" "http://127.0.0.1:$((TKPORT + 5))/admin.php"
        tkhas "$TK/admin-to.html" '🔴 Mangler: mbstring, openssl' "120: admin.php lister både mbstring og openssl, når begge mangler"
        tkhas "$TK/admin-to.html" 'Webhotellet mangler PHP-udvidelserne mbstring og openssl.' "120: admin.php viser beskeden i flertal, når begge mangler"
      else bad "PHP-krav (tester): kunne ikke starte serveren uden mbstring og openssl på den installerede kopi"; fi
      tk_stop
    else bad "PHP-krav (tester): kunne ikke lave en ren kopi/starte server uden mbstring og openssl"; fi
  fi
fi
tk_stop
rm -rf "$TK"

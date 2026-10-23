#!/usr/bin/env bash
# PHP-krav – røgtest af hurtigsøgningen i core/bootstrap.php (kun udvikling/CI, kommer ikke med i release).
#
# Læses ind af tests/run.sh (trin 4), efter installation og admin-login. Forudsætter, at run.sh har defineret:
#   ok, bad, info, BASE, JAR (admin-cookies)  – og at config.php findes.
# Kan ikke køres alene.
#
# Dækker (spec 2026-10-23-mbstring-krav): kriterie 115.

info "PHP-krav: hurtigsøgningen i core/bootstrap.php"
PK=$(mktemp -d)
PKLOG="${HSG_PHP_LOG:-/tmp/hsg-php-server.log}"
PKL0=$(wc -l < "$PKLOG" 2>/dev/null || echo 0)
PKURL="$BASE/core/bootstrap.php?action=quick_search"
PKCT='application/json; charset=utf-8'

# pkget <navn> <q> [curl-argumenter] -> svaret i $PK/<navn>.json, "HTTP-kode|Content-Type" på stdout
pkget() { local n="$1" q="$2"; shift 2; curl -s "$@" -o "$PK/$n.json" -w '%{http_code}|%{content_type}' "$PKURL&q=$q"; }
pkhttp() { # navn svar beskrivelse
  if [ "$2" = "200|$PKCT" ] && ! grep -qiE 'Fatal|Warning|Uncaught' "$PK/$1.json"; then ok "$3: HTTP 200, $PKCT, ingen PHP-fejl"
  else bad "$3: forventet HTTP 200|$PKCT uden PHP-fejl, fik $2"; fi
}
pkempty() { # navn beskrivelse
  if printf '%s' '{"results":[]}' | cmp -s - "$PK/$1.json"; then ok "$2 giver præcis {\"results\":[]}"
  else bad "$2 giver ikke præcis {\"results\":[]} ($(head -c 200 "$PK/$1.json"))"; fi
}

r=$(pkget aabn '%C3%85BN' -b "$JAR"); pkhttp aabn "$r" "q=ÅBN med admin-login"
# Titlen skrives med \u-escape, så kommandolinjen kun indeholder ASCII (også på Windows)
res=$(php -r '$j=json_decode((string)file_get_contents($argv[1]),true); if(!is_array($j)||!is_array($j["results"]??null)){echo "ugyldig JSON";exit;} foreach($j["results"] as $r){if(is_array($r)&&($r["title"]??null)==="\u{00C5}bn lagerstyring"){echo "fundet";exit;}} echo "ikke fundet";' "$PK/aabn.json")
[ "$res" = "fundet" ] && ok "q=ÅBN med admin-login: resultatet 'Åbn lagerstyring' findes" || bad "q=ÅBN med admin-login: 'Åbn lagerstyring' $res ($(head -c 200 "$PK/aabn.json"))"

r=$(pkget aa '%C3%85' -b "$JAR"); pkhttp aa "$r" "q=Å (ét tegn) med admin-login"
pkempty aa "q=Å (ét tegn) med admin-login"

r=$(pkget anon 'a'); pkhttp anon "$r" "q=a uden cookies"
pkempty anon "q=a uden cookies"

if tail -n +"$((PKL0 + 1))" "$PKLOG" 2>/dev/null | grep -qiE 'Fatal|Warning|Uncaught'; then
  bad "PHP-krav: Fatal/Warning/Uncaught i serverloggen under hurtigsøgningen"
else ok "PHP-krav: ingen Fatal/Warning/Uncaught i serverloggen under hurtigsøgningen"; fi
rm -rf "$PK"

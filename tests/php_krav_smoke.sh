#!/usr/bin/env bash
# PHP-krav – røgtest af hurtigsøgningen i core/bootstrap.php (kun udvikling/CI, kommer ikke med i release).
#
# Læses ind af tests/run.sh (trin 4), efter installation og admin-login. Forudsætter, at run.sh har defineret:
#   ok, bad, info, BASE, JAR (admin-cookies), ROOT  – og at config.php findes.
# Kan ikke køres alene.
#
# Dækker (spec 2026-10-23-mbstring-krav): kriterie 115 og rettelsen i afsnit 10
# (søgninger, der rammer en linkbruger eller en WooCommerce-ordre, må ikke give Uncaught).

info "PHP-krav: hurtigsøgningen i core/bootstrap.php"
PK=$(mktemp -d)
PKLOG="${HSG_PHP_LOG:-/tmp/hsg-php-server.log}"
PKL0=$(wc -l < "$PKLOG" 2>/dev/null || echo 0)
PKURL="$BASE/core/bootstrap.php?action=quick_search"
PKCT='application/json; charset=utf-8'
pksql() { php "$ROOT/tests/newsletter_sql.php" "$1"; }

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
# pkhas <navn> <forventet resultat som JSON> <beskrivelse>: et resultat har alle de forventede felter.
# Det forventede er JSON i UTF-8 og sammenlignes felt for felt efter json_decode.
pkhas() {
  local res
  res=$(php -r '$j=json_decode((string)file_get_contents($argv[1]),true); $e=json_decode($argv[2],true); if(!is_array($j)||!is_array($j["results"]??null)||!is_array($e)){echo "ugyldig JSON";exit;} foreach($j["results"] as $r){if(!is_array($r))continue; $m=true; foreach($e as $k=>$v){if(($r[$k]??null)!==$v){$m=false;break;}} if($m){echo "fundet";exit;}} echo "ikke fundet";' "$PK/$1.json" "$2")
  [ "$res" = "fundet" ] && ok "$3" || bad "$3: $res ($(head -c 300 "$PK/$1.json"))"
}

r=$(pkget aabn '%C3%85BN' -b "$JAR"); pkhttp aabn "$r" "q=ÅBN med admin-login"
pkhas aabn '{"title":"Åbn lagerstyring"}' "q=ÅBN med admin-login: resultatet 'Åbn lagerstyring' findes"

r=$(pkget aa '%C3%85' -b "$JAR"); pkhttp aa "$r" "q=Å (ét tegn) med admin-login"
pkempty aa "q=Å (ét tegn) med admin-login"

r=$(pkget anon 'a'); pkhttp anon "$r" "q=a uden cookies"
pkempty anon "q=a uden cookies"

# Søgninger, der rammer en linkbruger og en WooCommerce-ordre (forespørgslerne brugte før kolonner, der ikke findes)
if pksql "DELETE FROM hsg_woocommerce_orders WHERE wc_order_id=990001" >/dev/null \
   && pksql "DELETE FROM lager_users WHERE token_hash=SHA2('hsg-php-krav-bruger',256)" >/dev/null \
   && pksql "INSERT INTO lager_users(name,role,token_hash,token_last4,active) VALUES('Pkkrav Linkbruger','user',SHA2('hsg-php-krav-bruger',256),'test',1)" >/dev/null \
   && pksql "INSERT INTO hsg_woocommerce_orders(wc_order_id,order_number,status,total_amount,customer_name,customer_email,date_created) VALUES(990001,'PK-990001','completed',123.45,'Pkkrav Testkunde','pkkrav-kunde@example.dk',NOW())" >/dev/null; then
  r=$(pkget navn 'Pkkrav' -b "$JAR"); pkhttp navn "$r" "q=Pkkrav (linkbruger og ordre) med admin-login"
  pkhas navn '{"type":"Bruger (Link)","title":"Pkkrav Linkbruger","subtitle":"Personligt link-adgang"}' "q=Pkkrav: linkbrugeren findes"
  pkhas navn '{"type":"Ordre","title":"Ordre #PK-990001 - Pkkrav Testkunde","subtitle":"Status: completed · Total: 123.45 kr."}' "q=Pkkrav: ordren findes på kundenavnet"
  r=$(pkget mail 'pkkrav-kunde%40example' -b "$JAR"); pkhttp mail "$r" "q=pkkrav-kunde@example (ordrens mail) med admin-login"
  pkhas mail '{"type":"Ordre","title":"Ordre #PK-990001 - Pkkrav Testkunde"}' "q=pkkrav-kunde@example: ordren findes på kundens mail"
  pksql "DELETE FROM hsg_woocommerce_orders WHERE wc_order_id=990001" >/dev/null
  pksql "DELETE FROM lager_users WHERE token_hash=SHA2('hsg-php-krav-bruger',256)" >/dev/null
else
  bad "PHP-krav: kunne ikke oprette testbruger og testordre i testdatabasen"
fi

if tail -n +"$((PKL0 + 1))" "$PKLOG" 2>/dev/null | grep -qiE 'Fatal|Warning|Uncaught'; then
  bad "PHP-krav: Fatal/Warning/Uncaught i serverloggen under hurtigsøgningen"
else ok "PHP-krav: ingen Fatal/Warning/Uncaught i serverloggen under hurtigsøgningen"; fi
rm -rf "$PK"

#!/usr/bin/env bash
# Tests the nginx files in deploy/nginx on a throwaway copy: a local nginx on high ports, self-signed certificates,
# a dummy public/ folder. Nothing on any server is read or changed. Runs in CI and by hand (needs nginx, curl, openssl).
#
# Each answer is read as one word: "php" (nginx handed it to PHP), "ran" (a PHP file other than index.php ran),
# "source" (a PHP file was sent as text), or else the status. With PHP-FPM installed it really runs (index.php answers
# FRONT); without it, fastcgi_pass points at a socket nobody listens on and a request handed to PHP answers 502.
# NGINX_TEST_PHP_FPM=0 forces the second way.
set -u

ROOT=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d "${TMPDIR:-/tmp}/nginx-test.XXXXXX")
HTTP=18080
HTTPS=18443
PASS=0
FAIL=0

cleanup() {
    [ -f "$T/nginx.pid" ] && nginx -p "$T" -c "$T/nginx.conf" -e "$T/logs/error.log" -s stop 2>/dev/null
    [ -f "$T/php-fpm.pid" ] && kill "$(cat "$T/php-fpm.pid")" 2>/dev/null
    sleep 0.2
    rm -rf "$T"
}
trap cleanup EXIT

command -v nginx >/dev/null || { echo "nginx is not installed"; exit 1; }
mkdir -p "$T/sites" "$T/snippets" "$T/logs" "$T/tmp" "$T/acme/.well-known/acme-challenge" \
    "$T/public/storage/sub" "$T/public/storage/mixed" "$T/public/storage/ext" "$T/public/build" "$T/public/.git" \
    "$T/public/storage/.git" "$T/public/storage/.well-known" "$T/public/storage/sub/.hidden" "$T/public/storage/v1.2"

# Certificates: one per name the files expect, self-signed, valid for two days.
cert() { # $1 certificate name, $2.. names it covers
    local name=$1 san
    shift
    san=$(printf 'DNS:%s,' "$@")
    mkdir -p "$T/certs/$name"
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 2 -subj "/CN=$name" \
        -addext "subjectAltName=${san%,}" -keyout "$T/certs/$name/privkey.pem" -out "$T/certs/$name/fullchain.pem" \
        >/dev/null 2>&1 || { echo "openssl could not make a certificate"; exit 1; }
}
cert aloperagym.om aloperagym.om www.aloperagym.om
cert app.aloperagym.om app.aloperagym.om

# The public folder: the front controller, PHP files that must never run, files that are served, files that are not.
# PHP-like names under storage/ are the ones a restored club archive could bring (any name, any content); each is its
# own file (macOS folds case, so the mixed-case ones live in their own folder).
echo '<?php echo "FRONT";' > "$T/public/index.php"
RAN='<?php echo "RAN-MARKER";'
for f in other.php storage/evil.php storage/sub/evil.php storage/mixed/Shell.PhP storage/mixed/UP.PHP \
    storage/ext/x.phtml storage/ext/x.PHTML storage/ext/x.phar storage/ext/x.php7 storage/ext/x.php5 storage/ext/x.phps \
    storage/ext/x.pht 'storage/ext/trailing.php.'; do
    echo "$RAN" > "$T/public/$f"
done
echo 'png' > "$T/public/storage/photo.png"
echo 'jpg' > "$T/public/storage/photo.jpg"
echo 'webp' > "$T/public/storage/photo.webp"
echo 'pdf' > "$T/public/storage/doc.pdf"
# Hidden files and folders under storage/ (Laravel's own .gitignore is there on every server); and names with a dot
# that do not start with one, which are served.
for f in storage/.gitignore storage/.env storage/sub/.env storage/.hidden.png storage/.user.ini storage/.git/config \
    storage/.well-known/x storage/sub/.hidden/photo.png; do
    echo 'HIDDEN' > "$T/public/$f"
done
echo 'png' > "$T/public/storage/v1.2/photo.png"
echo 'png' > "$T/public/storage/a.b.png"
echo 'body{}' > "$T/public/build/app.css"
echo 'APP_KEY=secret' > "$T/public/.env"
echo '[core]' > "$T/public/.git/config"
echo 'dump' > "$T/public/backup.sql"
echo 'token' > "$T/acme/.well-known/acme-challenge/test-token"

# The repository's files, with the server's paths and ports swapped for the throwaway ones. IPv6 listens are dropped
# (not every runner has IPv6); everything else is used exactly as written.
localise() {
    sed -e "s#/etc/letsencrypt/live/#$T/certs/#g" \
        -e "s#/var/www/alopera-gym/current/public#$T/public#g" \
        -e "s#/var/www/letsencrypt#$T/acme#g" \
        -e "s#unix:/run/php/php8.4-fpm.sock#unix:$T/php.sock#g" \
        -e "s#listen 80\([ ;]\)#listen 127.0.0.1:$HTTP\1#" \
        -e "s#listen 443 #listen 127.0.0.1:$HTTPS #" \
        -e '/listen \[::\]/d' "$1"
}
for f in "$ROOT"/deploy/nginx/*.conf; do localise "$f" > "$T/sites/$(basename "$f")"; done
localise "$ROOT/deploy/nginx/snippets/alopera-laravel.conf" > "$T/snippets/alopera-laravel.conf"
cat > "$T/fastcgi_params" <<'EOF'
fastcgi_param QUERY_STRING    $query_string;
fastcgi_param REQUEST_METHOD  $request_method;
fastcgi_param REQUEST_URI     $request_uri;
fastcgi_param SERVER_NAME     $server_name;
EOF
cat > "$T/nginx.conf" <<EOF
worker_processes 1;
pid $T/nginx.pid;
error_log $T/logs/error.log;
events {}
http {
    types { text/html html; text/css css; image/png png; application/json json; }
    default_type application/octet-stream;
    access_log off;
    client_body_temp_path $T/tmp/body;
    proxy_temp_path $T/tmp/proxy;
    fastcgi_temp_path $T/tmp/fastcgi;
    uwsgi_temp_path $T/tmp/uwsgi;
    scgi_temp_path $T/tmp/scgi;
    include $T/sites/*.conf;
}
EOF

# PHP-FPM on the socket the files point at, when one is installed: then "not run" and "not sent as text" are seen.
FPM=""
if [ "${NGINX_TEST_PHP_FPM:-1}" != 0 ]; then
    for c in php-fpm php-fpm8.4 php-fpm8.3 /usr/sbin/php-fpm8.4; do command -v "$c" >/dev/null 2>&1 && { FPM=$c; break; }; done
fi
if [ -n "$FPM" ]; then
    printf '[global]\npid = %s/php-fpm.pid\nerror_log = %s/logs/php-fpm.log\ndaemonize = yes\n[www]\nlisten = %s/php.sock\npm = static\npm.max_children = 2\n' \
        "$T" "$T" "$T" > "$T/php-fpm.conf"
    "$FPM" -y "$T/php-fpm.conf" 2>/dev/null || FPM=""
    sleep 0.3
fi
if [ -n "$FPM" ]; then echo "mode  PHP-FPM ($FPM): files really run"; else echo "mode  no PHP-FPM: a request handed to PHP answers 502"; fi

if ! out=$(nginx -t -p "$T" -c "$T/nginx.conf" -e "$T/logs/error.log" 2>&1); then
    echo "$out"
    echo "FAIL  nginx -t refuses the files in deploy/nginx"
    exit 1
fi
echo "ok    nginx -t accepts deploy/nginx ($(nginx -v 2>&1 | sed 's#.*/##'))"
nginx -p "$T" -c "$T/nginx.conf" -e "$T/logs/error.log" || { echo "nginx did not start"; exit 1; }
sleep 0.5

check() { # $1 what, $2 expected, $3 actual
    if [ "$2" = "$3" ]; then
        PASS=$((PASS + 1))
        printf 'ok    %s\n' "$1"
    else
        FAIL=$((FAIL + 1))
        printf 'FAIL  %s: expected [%s], got [%s]\n' "$1" "$2" "$3"
    fi
}
# status of an HTTPS request to a named host (the SNI and the Host are that name unless -H says otherwise)
https() { # $1 name, $2 path, rest: extra curl options
    local name=$1 path=$2
    shift 2
    curl -sk --http1.1 --path-as-is -o /dev/null -w '%{http_code}' --max-time 5 \
        --resolve "$name:$HTTPS:127.0.0.1" "$@" "https://$name:$HTTPS$path"
}
https_header() { # $1 name, $2 path, $3 header name
    curl -sk --http1.1 -o /dev/null -D - --max-time 5 --resolve "$1:$HTTPS:127.0.0.1" "https://$1:$HTTPS$2" |
        tr -d '\r' | awk -v h="$(echo "$3" | tr 'A-Z' 'a-z')" 'tolower($0) ~ "^"h":" { sub(/^[^:]*: */, ""); v = v (v == "" ? "" : ", ") $0 } END { print v }'
}
http_req() { # $1 name, $2 path → "status location"
    curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 5 --resolve "$1:$HTTP:127.0.0.1" "http://$1:$HTTP$2"
}
rc() { "$@" >/dev/null 2>&1; echo $?; }
result() { # $1 name, $2 path → php | ran | source | <status>
    local code
    code=$(curl -sk --http1.1 --path-as-is -o "$T/body" -w '%{http_code}' --max-time 5 \
        --resolve "$1:$HTTPS:127.0.0.1" "https://$1:$HTTPS$2")
    if grep -q 'RAN-MARKER' "$T/body" && ! grep -q '<?php' "$T/body"; then echo ran
    elif grep -q '<?php' "$T/body"; then echo source
    elif [ -n "$FPM" ] && [ "$code" = 200 ] && [ "$(cat "$T/body")" = FRONT ]; then echo php
    elif [ -z "$FPM" ] && [ "$code" = 502 ]; then echo php
    else echo "$code"
    fi
}
cert_of() { # the certificate's subject CN for an SNI
    echo | openssl s_client -connect "127.0.0.1:$HTTPS" -servername "$1" 2>/dev/null |
        openssl x509 -noout -subject 2>/dev/null | sed -E 's/.*CN ?= ?//'
}

echo "== unknown hosts get nothing back (no page, no redirect)"
check "HTTPS, unknown SNI: TLS handshake refused" 35 \
    "$(rc curl -sk --http1.1 --max-time 5 --resolve "evil.example:$HTTPS:127.0.0.1" "https://evil.example:$HTTPS/")"
check "HTTPS, no SNI (IP address): TLS handshake refused" 35 "$(rc curl -sk --http1.1 --max-time 5 "https://127.0.0.1:$HTTPS/")"
check "HTTPS, real SNI with a forged Host on /: closed, no reply" 52 \
    "$(rc curl -sk --http1.1 --max-time 5 --resolve "aloperagym.om:$HTTPS:127.0.0.1" -H 'Host: evil.example' "https://aloperagym.om:$HTTPS/")"
check "HTTPS, real SNI with a forged Host on /login: closed, no reply" 52 \
    "$(rc curl -sk --http1.1 --max-time 5 --resolve "app.aloperagym.om:$HTTPS:127.0.0.1" -H 'Host: evil.example' "https://app.aloperagym.om:$HTTPS/login")"
check "HTTP, forged Host: closed, no reply" 52 "$(rc curl -s --max-time 5 -H 'Host: evil.example' "http://127.0.0.1:$HTTP/")"
check "HTTP, the IP address: closed, no reply" 52 "$(rc curl -s --max-time 5 "http://127.0.0.1:$HTTP/")"
check "HTTP/1.0 without a Host: closed, no reply" 52 "$(rc curl -s -0 --max-time 5 -H 'Host:' "http://127.0.0.1:$HTTP/")"

echo "== the three names over HTTP: certificates' challenge files, then HTTPS"
check "http://aloperagym.om/plans?x=1 → https, same path" "301 https://aloperagym.om/plans?x=1" "$(http_req aloperagym.om '/plans?x=1')"
check "http://app.aloperagym.om/login → https" "301 https://app.aloperagym.om/login" "$(http_req app.aloperagym.om /login)"
check "http://www.aloperagym.om/ → https" "301 https://www.aloperagym.om/" "$(http_req www.aloperagym.om /)"
for name in aloperagym.om www.aloperagym.om app.aloperagym.om; do
    check "certificate challenge file served on http://$name" "200 " "$(http_req "$name" /.well-known/acme-challenge/test-token)"
done

echo "== each name gets its own certificate"
check "aloperagym.om" aloperagym.om "$(cert_of aloperagym.om)"
check "www.aloperagym.om" aloperagym.om "$(cert_of www.aloperagym.om)"
check "app.aloperagym.om" app.aloperagym.om "$(cert_of app.aloperagym.om)"

echo "== www goes to the bare domain"
check "https://www.aloperagym.om/plans → https://aloperagym.om/plans" "301 https://aloperagym.om/plans" \
    "$(curl -sk --http1.1 -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 5 --resolve "www.aloperagym.om:$HTTPS:127.0.0.1" "https://www.aloperagym.om:$HTTPS/plans")"

for name in aloperagym.om app.aloperagym.om; do
    echo "== $name: the application's paths reach PHP"
    for path in / /en /plans /login /up /index.php '/index.php?x=1' /webhooks/paymob /webhooks/whatsapp /api/devices/v1/heartbeat; do
        check "$path" php "$(result "$name" "$path")"
    done
    echo "== $name: no other PHP file runs, and none is sent as text (404)"
    for path in /storage/evil.php /storage/sub/evil.php /storage/mixed/Shell.PhP /storage/mixed/UP.PHP \
        /storage/ext/x.phtml /storage/ext/x.PHTML /storage/ext/x.phar /storage/ext/x.php7 /storage/ext/x.php5 \
        /storage/ext/x.phps /storage/ext/x.pht '/storage/ext/trailing.php.' /storage/evil%2ephp /storage/evil%2EPHP \
        /storage/sub/../evil.php /storage//evil.php /storage/evil.php/x.png /storage/evil.php%3fx.png /other.php /missing.php; do
        check "$path" 404 "$(result "$name" "$path")"
    done
    echo "== $name: nothing hidden under /storage/ is served (404)"
    for path in /storage/.gitignore /storage/.env /storage/sub/.env /storage/.hidden.png /storage/.user.ini \
        /storage/.git/config /storage/.well-known/x /storage/sub/.hidden/photo.png /storage/%2egitignore /storage/sub/%2Eenv \
        /storage/sub/..%2f.env /storage//.env /storage/sub/../.env /storage/.GIT/config; do
        check "$path" 404 "$(result "$name" "$path")"
    done
    echo "== $name: files that are never served"
    check "/.env" 403 "$(https "$name" /.env)"
    check "/.git/config" 403 "$(https "$name" /.git/config)"
    check "/storage/../.env" 403 "$(https "$name" /storage/../.env)"
    check "/backup.sql" 403 "$(https "$name" /backup.sql)"
    echo "== $name: files that are"
    for path in /storage/photo.png /storage/photo.jpg /storage/photo.webp /storage/doc.pdf /storage/v1.2/photo.png /storage/a.b.png; do
        check "$path" 200 "$(result "$name" "$path")"
    done
    check "/storage/missing.png" 404 "$(result "$name" /storage/missing.png)"
    check "/build/app.css" 200 "$(https "$name" /build/app.css)"
    check "/build/ cached for a year" "max-age=31536000, public, immutable" "$(https_header "$name" /build/app.css Cache-Control)"
    echo "== $name: what the answers say about the server"
    check "no nginx version in Server" nginx "$(https_header "$name" /storage/photo.png Server)"
    check "no nginx version on a 404" nginx "$(https_header "$name" /storage/evil.php Server)"
    check "X-Content-Type-Options" nosniff "$(https_header "$name" /storage/photo.png X-Content-Type-Options)"
done
check "no nginx version on the www redirect" nginx "$(https_header www.aloperagym.om / Server)"
check "the panel is not indexed" "noindex, nofollow" "$(https_header app.aloperagym.om /storage/photo.png X-Robots-Tag)"

echo
echo "nginx (deploy/nginx): $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]

#!/usr/bin/env bash
# Runs the move to releases and the deploy against a site folder on this
# machine (DEPLOY_HOST=local), with PHP's server in place of Apache:
# 1. A move whose start page fails moves everything back.
# 2. The move of a site from before releases.
# 3. A deploy: a new release, the switch, the check.
# 4. A deploy whose start page fails: current points back to the release before.
#
# Usage: bin/deploy-test.sh
set -euo pipefail

PORT=8061
PHP="${PHP:-php}"
TMP="$(mktemp -d)"
SITE="$TMP/localhost:$PORT"
URL="http://localhost:$PORT"
export DEPLOY_HOST=local DEPLOY_PATH="$SITE" SITE_URL="$URL" REMOTE_PHP="$PHP"

fail() { echo "FAIL: $*" >&2; exit 1; }
current() { basename "$(readlink "$SITE/current")"; }

! curl -s -o /dev/null "$URL/" || fail "port $PORT is busy"

# The router does what server/root.htaccess does on Apache: every request
# goes into the folder its last RewriteRule names.
cat > "$TMP/router.php" <<'PHP'
<?php
$site = $_SERVER['DOCUMENT_ROOT'];
preg_match('!RewriteRule \^\(\.\*\)\$ (\S+)\$1 \[L\]!', (string) file_get_contents("$site/.htaccess"), $m);
$www = realpath($site . '/' . rtrim($m[1] ?? 'www/', '/'));
$_SERVER['DOCUMENT_ROOT'] = $www;
$_SERVER['SCRIPT_FILENAME'] = "$www/index.php";
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir($www);
require "$www/index.php";
PHP

# A site from before releases: code and state in www/, vendor/ next to it.
mkdir -p "$SITE"
rsync -a --exclude '/content/' --exclude '/media/' --exclude '/site/accounts/' --exclude '/site/cache/' \
  --exclude '/site/sessions/' --exclude '/site/config/env.php' --exclude '/site/config/.salt' \
  --exclude '/site/config/.license' www/ "$SITE/www/"
cp -R vendor "$SITE/vendor"
mkdir -p "$SITE/www/content/error"
printf 'Title: Sven Eisenschmidt - Personal Blog\n' > "$SITE/www/content/site.txt"
printf 'Title: Error\n' > "$SITE/www/content/error/error.txt"
printf "<?php\n\nreturn ['debug' => false, 'url' => '%s'];\n" "$URL" > "$SITE/www/site/config/env.php"
printf '0123456789abcdef0123456789abcdef0123456789abcdef\n' > "$SITE/www/site/config/.salt"
printf 'RewriteEngine On\nRewriteRule ^(.*)$ www/$1 [L]\n' > "$SITE/.htaccess"

PHP_CLI_SERVER_WORKERS=4 "$PHP" -S "localhost:$PORT" -t "$SITE" "$TMP/router.php" >/dev/null 2>&1 &
SERVER=$!
trap 'pkill -P $SERVER 2>/dev/null; kill $SERVER 2>/dev/null; rm -rf "$TMP"' EXIT
until curl -s -o /dev/null "$URL/"; do sleep 0.2; done

echo "## 1. Move with a failing start page (no home page)"
! bin/migrate-to-releases.sh >/dev/null 2>&1 || fail "the move did not fail"
[ -d "$SITE/www/content" ] && [ -f "$SITE/www/site/config/.salt" ] && [ -d "$SITE/vendor" ] || fail "the state is not back in www/"
[ ! -e "$SITE/shared" ] && [ ! -e "$SITE/current" ] && [ ! -e "$SITE/releases" ] || fail "shared/, current or releases/ stayed"
grep -q 'www/\$1' "$SITE/.htaccess" || fail "the old .htaccess did not come back"
echo "OK"

echo "## 2. Move"
mkdir -p "$SITE/www/content/home"
printf 'Title: Home\n' > "$SITE/www/content/home/home.txt"
bin/migrate-to-releases.sh >/dev/null
[ -L "$SITE/current" ] && [ -f "$SITE/shared/content/home/home.txt" ] && [ -f "$SITE/shared/site/config/.salt" ] || fail "no release or no state in shared/"
[ ! -e "$SITE/www" ] && [ ! -e "$SITE/vendor" ] || fail "the old www/ or vendor/ stayed"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/")" = 200 ] || fail "the moved site does not answer 200"
first="$(current)"
echo "OK: $first"

echo "## 3. Deploy"
sleep 1
bin/deploy.sh >/dev/null
second="$(current)"
[ "$second" != "$first" ] || fail "current did not move"
[ -f "$SITE/releases/$second/www/index.php" ] && [ -L "$SITE/releases/$second/www/content" ] || fail "the release has no code or no link to the content"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/")" = 200 ] || fail "the release does not answer 200"
echo "OK: $second"

echo "## 4. Deploy with a failing start page"
# A copy of the code whose start page fails.
mkdir "$TMP/broken"
rsync -a --exclude '/.git/' --exclude '/vendor/' --exclude '/backups/' --exclude '/storage/' --exclude '/.restore-test/' \
  --exclude '/www/content/' --exclude '/www/media/' --exclude '/www/kirby/' --exclude '/www/site/cache/' \
  --exclude '/www/site/sessions/' --exclude '/www/site/accounts/' ./ "$TMP/broken/"
printf '<?php http_response_code(500); exit;\n' > "$TMP/broken/www/site/templates/home.php"
sleep 1
! (cd "$TMP/broken" && bin/deploy.sh >/dev/null 2>&1) || fail "the deploy did not fail"
[ "$(current)" = "$second" ] || fail "current is not back on $second"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/")" = 200 ] || fail "the site does not answer 200 after the way back"
echo "OK: back on $second"

#!/usr/bin/env bash
# Runs bin/deploy.sh against a site folder on this machine (DEPLOY_HOST=local),
# with PHP's server in place of Apache:
# 1. The first release of a site whose state is in shared/; env.php and
#    .salt only for the account's user.
# 2. A second release: the switch, the check, folders and files the web
#    server may read (it runs as another user than the deploy).
# 3. A release whose start page fails: the site goes back to the release before.
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
status() { curl -s -o /dev/null -w '%{http_code}' "$URL/"; }

! curl -s -o /dev/null "$URL/" || fail "port $PORT is busy"

# The router does what the site's .htaccess does on Apache: every request
# goes into the folder its last RewriteRule names.
cat > "$TMP/router.php" <<'PHP'
<?php
$site = $_SERVER['DOCUMENT_ROOT'];
preg_match('!RewriteRule \^\(\.\*\)\$ (\S+)\$1 \[L\]!', (string) @file_get_contents("$site/.htaccess"), $m);
$www = realpath($site . '/' . rtrim($m[1] ?? '', '/'));
if ($www === false || !is_file("$www/index.php")) {
    http_response_code(503);
    return true;
}
$_SERVER['DOCUMENT_ROOT'] = $www;
$_SERVER['SCRIPT_FILENAME'] = "$www/index.php";
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir($www);
require "$www/index.php";
PHP

# The state of a site, as shared/ holds it on the server.
mkdir -p "$SITE/shared/content/home" "$SITE/shared/content/error" "$SITE/shared/site/config"
printf 'Title: Sven Eisenschmidt - Personal Blog\n' > "$SITE/shared/content/site.txt"
printf 'Title: Home\n' > "$SITE/shared/content/home/home.txt"
printf 'Title: Error\n' > "$SITE/shared/content/error/error.txt"
printf '0123456789abcdef0123456789abcdef0123456789abcdef\n' > "$SITE/shared/site/config/.salt"

PHP_CLI_SERVER_WORKERS=4 "$PHP" -S "localhost:$PORT" -t "$SITE" "$TMP/router.php" >/dev/null 2>&1 &
SERVER=$!
trap 'pkill -P $SERVER 2>/dev/null; kill $SERVER 2>/dev/null; rm -rf "$TMP"' EXIT
until curl -s -o /dev/null "$URL/"; do sleep 0.2; done

echo "## 1. First release"
bin/deploy.sh >/dev/null
first="$(current)"
[ "$(status)" = 200 ] || fail "the first release does not answer 200"
[ -n "$(find "$SITE/shared/site/config" -name env.php -perm 600)" ] && [ -n "$(find "$SITE/shared/site/config" -name .salt -perm 600)" ] || fail "env.php or .salt is readable by others"
echo "OK: $first"

echo "## 2. Second release"
sleep 1
bin/deploy.sh >/dev/null
second="$(current)"
[ "$second" != "$first" ] || fail "current did not move"
[ -f "$SITE/releases/$second/www/index.php" ] && [ -L "$SITE/releases/$second/www/content" ] || fail "the release has no code or no link to the content"
[ "$(status)" = 200 ] || fail "the second release does not answer 200"
unreadable="$(find "$SITE/releases/$second" \( -type d ! -perm -o+rx \) -o \( -type f ! -perm -o+r \) | head -3)"
[ -z "$unreadable" ] || fail "the web server cannot read: $unreadable"
echo "OK: $second"

echo "## 3. Release with a failing start page"
# A copy of the code whose start page fails.
mkdir "$TMP/broken"
rsync -a --exclude '/.git/' --exclude '/vendor/' --exclude '/backups/' --exclude '/storage/' --exclude '/.restore-test/' \
  --exclude '/.claude/' --exclude '/www/content/' --exclude '/www/media/' --exclude '/www/kirby/' --exclude '/www/site/cache/' \
  --exclude '/www/site/sessions/' --exclude '/www/site/accounts/' ./ "$TMP/broken/"
printf '<?php http_response_code(500); exit;\n' > "$TMP/broken/www/site/templates/home.php"
sleep 1
! (cd "$TMP/broken" && bin/deploy.sh >/dev/null 2>&1) || fail "the deploy did not fail"
[ "$(current)" = "$second" ] || fail "current is not back on $second"
[ "$(status)" = 200 ] || fail "the site does not answer 200 after the way back"
echo "OK: back on $second"

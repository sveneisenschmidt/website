#!/usr/bin/env bash
# Tests bin/deploy.sh without a server. DEPLOY_HOST=local deploys into a
# temporary folder, and PHP's built-in web server takes the place of Apache.
# The workflow CI runs it. It needs port 8061 to be free.
# 1. The first release of a site whose state is already in shared/.
#    env.php and .salt must have mode 600. shared/ and backups/ must have
#    the .htaccess that denies every request. backups/ and
#    shared/site/accounts/ must have mode 700.
# 2. A second release. `current` must point to it. Every folder and file
#    must be readable for others, because Apache runs as another user.
# 3. A release whose start page answers 500. The health check must fail,
#    and the site must be back on the second release.
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

# router.php does for PHP's server what server/root.htaccess does on Apache.
# It serves every request from the folder that the last RewriteRule of the
# site's .htaccess names.
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

# A small state in shared/, in the layout of the server.
mkdir -p "$SITE/shared/content/home" "$SITE/shared/content/error" "$SITE/shared/site/config"
printf 'Title: Personal Blog\n' > "$SITE/shared/content/site.txt"
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
cmp -s server/deny.htaccess "$SITE/shared/.htaccess" && cmp -s server/deny.htaccess "$SITE/backups/.htaccess" || fail "shared/ or backups/ has no .htaccess that denies every request"
[ "$(find "$SITE/backups" "$SITE/shared/site/accounts" -maxdepth 0 -perm 700 | grep -c .)" = 2 ] || fail "backups/ or shared/site/accounts/ is open to others"
echo "OK: $first"

echo "## 2. Second release"
sleep 1
bin/deploy.sh >/dev/null
second="$(current)"
[ "$second" != "$first" ] || fail "current did not move"
[ -f "$SITE/releases/$second/www/index.php" ] && [ -L "$SITE/releases/$second/www/content" ] || fail "the release has no code or no symlink to the content"
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
[ "$(status)" = 200 ] || fail "the site does not answer 200 after the switch back"
echo "OK: back on $second"

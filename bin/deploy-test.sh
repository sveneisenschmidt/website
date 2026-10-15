#!/usr/bin/env bash
# Tests bin/deploy.sh without a server. DEPLOY_HOST=local deploys into a
# temporary folder, and PHP's built-in web server takes the place of Apache.
# The workflow CI runs it. It needs port 8061 to be free.
# 1. The first release of a site whose state is already in shared/.
#    env.php and .salt must have mode 600. shared/ and backups/ must have
#    the .htaccess that denies every request. backups/,
#    shared/site/accounts/ and shared/storage/ must have mode 700, the key
#    of the visitor statistics mode 600.
# 2. A second release. `current` must point to it. Every folder and file
#    must be readable for others, because Apache runs as another user.
# 3. A release whose posts answer 500 and whose start page answers 200. The
#    health check must fail at the newest post. The site must be back on the
#    second release, and its health check must pass. The failed release
#    must be gone.
# 4. A release whose switch fails after the rename of `current`. `current`
#    must be back on the second release, and the failed release must be
#    gone.
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
# The health check of bin/deploy.sh requests the newest post, so the state
# has one note.
mkdir -p "$SITE/shared/content/home" "$SITE/shared/content/error" "$SITE/shared/content/notes/20260101_hello" "$SITE/shared/site/config"
printf 'Title: Personal Blog\n' > "$SITE/shared/content/site.txt"
printf 'Title: Home\n' > "$SITE/shared/content/home/home.txt"
printf 'Title: Error\n' > "$SITE/shared/content/error/error.txt"
printf 'Title: Notes\n' > "$SITE/shared/content/notes/notes.txt"
printf 'Title: Hello\n\n----\n\nDate: 2026-01-01 12:00:00\n\n----\n\nText: Hello.\n' > "$SITE/shared/content/notes/20260101_hello/note.txt"
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
[ "$(find "$SITE/backups" "$SITE/shared/site/accounts" "$SITE/shared/storage" -maxdepth 0 -perm 700 | grep -c .)" = 3 ] || fail "backups/, shared/site/accounts/ or shared/storage/ is open to others"
[ -n "$(find "$SITE/shared/storage" -name analytics.key -perm 600)" ] || fail "the key of the visitor statistics is missing or readable by others"
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

echo "## 3. Release with failing posts"
# A copy of the code whose post template fails. The start page does not use
# that template and still answers 200.
mkdir "$TMP/broken"
rsync -a --exclude '/.git/' --exclude '/vendor/' --exclude '/backups/' --exclude '/storage/' --exclude '/.restore-test/' \
  --exclude '/.claude/' --exclude '/www/content/' --exclude '/www/media/' --exclude '/www/kirby/' --exclude '/www/site/cache/' \
  --exclude '/www/site/sessions/' --exclude '/www/site/accounts/' ./ "$TMP/broken/"
printf '<?php http_response_code(500); exit;\n' > "$TMP/broken/www/site/templates/post.php"
sleep 1
! (cd "$TMP/broken" && bin/deploy.sh >"$TMP/broken.log" 2>&1) || fail "the deploy did not fail"
grep -qx 'HTTP 500 /2026-01-01-hello/' "$TMP/broken.log" || fail "the health check did not fail at the newest post"
[ "$(current)" = "$second" ] || fail "current is not back on $second"
# The last line of the log is the newest post in the check after the switch back.
tail -1 "$TMP/broken.log" | grep -qx 'HTTP 200 /2026-01-01-hello/' || fail "the deploy did not check $second again after the switch back"
[ "$(status)" = 200 ] || fail "the site does not answer 200 after the switch back"
releases() { ls -1 "$SITE/releases" | tr '\n' ' '; }
[ "$(releases)" = "$first $second " ] || fail "a failed release is still there: $(releases)"
echo "OK: back on $second"

echo "## 4. Release whose switch fails"
# A PHP for the server side that fails the next switch once: it renames
# `current` and fails before the rename of .htaccess, as a full disk could.
cat > "$TMP/php-switch-fails" <<SH
#!/usr/bin/env bash
if [ -f "$TMP/fail-switch" ] && [[ "\$*" == *'rename(".htaccess.new"'* ]]; then
  rm "$TMP/fail-switch"
  exec "$PHP" -r 'rename("current.new", "current"); exit(1);'
fi
exec "$PHP" "\$@"
SH
chmod +x "$TMP/php-switch-fails"
touch "$TMP/fail-switch"
sleep 1
! REMOTE_PHP="$TMP/php-switch-fails" bin/deploy.sh >"$TMP/switch.log" 2>&1 || fail "the deploy did not fail"
grep -q '^The switch to .* failed\.$' "$TMP/switch.log" || fail "the switch did not fail"
[ "$(current)" = "$second" ] || fail "current is not back on $second"
[ "$(releases)" = "$first $second " ] || fail "a failed release is still there: $(releases)"
[ "$(status)" = 200 ] || fail "the site does not answer 200 after the switch back"
echo "OK: back on $second"

#!/usr/bin/env bash
# Tests that a backup and a restore give the same site again. make
# restore-test runs it on the local www/. It needs ports 8093 and 8094 free.
# 1. Backs up www/ with bin/backup.sh.
# 2. Copies the code of www/ to .restore-test/www, without state, env.php
#    and generated files.
# 3. Copies env.php, where one exists, and restores the backup into the copy.
# 4. Compares the checksums and file times of the state in both folders.
# 5. Serves both folders with PHP's built-in web server, fetches every path
#    of bin/page-paths.php from both, and compares the HTML.
#
# Usage: bin/restore-test.sh [www-root]
set -euo pipefail

WWW="${1:-www}"
TEST=".restore-test"
PORT_A=8093
PORT_B=8094
# make restore-test passes the PHP of the Makefile.
PHP="${PHP:-php}"

sha256() { if command -v sha256sum >/dev/null; then sha256sum "$@"; else shasum -a 256 "$@"; fi; }
# bin/backup.sh leaves out site/accounts/.logins, the failed logins of the
# last hour, so the comparison leaves it out too.
state_sums() { ( cd "$1" && find content site/accounts site/config/.salt site/config/.license -type f ! -name '.DS_Store' ! -path 'site/accounts/.logins' 2>/dev/null | LC_ALL=C sort | while read -r f; do sha256 "$f"; done ); }
# One PHP process for all files: a process per file would start PHP once
# for every file in content/.
mtimes() { ( cd "$1" && find content -type f ! -name '.DS_Store' | LC_ALL=C sort | "$PHP" -r 'while (($f = fgets(STDIN)) !== false) { $f = rtrim($f, "\n"); echo filemtime($f), " ", $f, "\n"; }' ); }
# Prints the first 20 differences, so a failure shows where the trees differ.
same() { local d; d=$(diff "$1" "$2") || { echo "FAIL: $3 differ"; echo "$d" | head -20; exit 1; }; }

rm -rf "$TEST"
mkdir -p "$TEST"

ARCHIVE="$(bin/backup.sh "$WWW" "$TEST/backups" | tail -1)"
echo "Backup: $ARCHIVE"

rsync -a --exclude '/content' --exclude '/media' --exclude '/site/accounts' --exclude '/site/cache' \
  --exclude '/site/sessions' --exclude '/site/config/env.php' --exclude '/site/config/.salt' \
  --exclude '/site/config/.license' "$WWW/" "$TEST/www/"
# Locally the configuration comes from .env, which make exports; an env.php
# goes into the copy only where one exists.
[ ! -f "$WWW/site/config/env.php" ] || cp -p "$WWW/site/config/env.php" "$TEST/www/site/config/env.php"
ln -s ../vendor "$TEST/vendor"   # www/index.php loads ../vendor/autoload.php

bin/restore.sh "$ARCHIVE" "$TEST/www" >/dev/null

same <(state_sums "$WWW") <(state_sums "$TEST/www") "state checksums"
same <(mtimes "$WWW") <(mtimes "$TEST/www") "file times"
echo "OK: state files identical (checksums and file times)"

# Another server on these ports would answer in place of the test servers.
for port in "$PORT_A" "$PORT_B"; do
  ! curl -s -o /dev/null "http://localhost:$port/" || { echo "FAIL: port $port is busy"; exit 1; }
done
# Kirby keeps one page cache per host name. A cache from an earlier run would
# give old HTML for the original site.
rm -rf "$WWW/site/cache/localhost_$PORT_A"
PHP_CLI_SERVER_WORKERS=4 "$PHP" -S "localhost:$PORT_A" -t "$WWW" bin/dev-router.php >/dev/null 2>&1 & A=$!
PHP_CLI_SERVER_WORKERS=4 "$PHP" -S "localhost:$PORT_B" -t "$TEST/www" bin/dev-router.php >/dev/null 2>&1 & B=$!
trap 'pkill -P $A 2>/dev/null; pkill -P $B 2>/dev/null; kill $A $B 2>/dev/null || true' EXIT
until curl -s -o /dev/null "http://localhost:$PORT_A/" && curl -s -o /dev/null "http://localhost:$PORT_B/"; do
  kill -0 "$A" "$B" 2>/dev/null || { echo "FAIL: a test server did not start"; exit 1; }
  sleep 0.2
done

paths=()
while read -r p; do paths+=("$p"); done < <("$PHP" bin/page-paths.php "$WWW")

fail=0
for p in "${paths[@]}"; do
  a=$(curl -fsS --max-time 60 "http://localhost:$PORT_A$p" | sed "s/localhost:$PORT_A/HOST/g")
  b=$(curl -fsS --max-time 60 "http://localhost:$PORT_B$p" | sed "s/localhost:$PORT_B/HOST/g")
  if [ "$a" != "$b" ]; then echo "FAIL: $p differs"; fail=1; fi
done
[ "$fail" = 0 ] || exit 1
echo "OK: ${#paths[@]} pages identical"
# The copy holds the whole state, all photos included. A failed run exits
# above and keeps the copy for debugging.
rm -rf "$TEST"

#!/usr/bin/env bash
# Deploys the code to the server, from concertshotsbyluisa's bin/deploy.sh.
# The server folder has the layout of the repository: www/ and vendor/ side
# by side, plus server/root.htaccess as its .htaccess (the document root in
# KAS is the folder itself). Touches no state.
#
# 1. Build: www/ without state in a temporary folder, composer install
#    --no-dev there (Kirby into www/kirby, the markdown-field plugin into
#    www/site/plugins, the rest into vendor/).
# 2. Snapshot of the live state on the server (if there is any), newest 30 kept.
# 3. rsync of www/ and vendor/, with --delete, but state, host config and
#    generated files are excluded, so rsync neither uploads nor deletes them.
# 4. Host config: env.php and, for a fresh site, .salt. bin/set-env.php
#    sets 'url' to SITE_URL and 'assistantKey' to ANTHROPIC_API_KEY, if set.
# 5. Page cache cleared under the site's cache lock. The start page must
#    answer 200, then bin/warm.php --pages fills the page cache again.
#
# Environment:
#   DEPLOY_PATH                       required, the site folder on the server
#   SITE_URL                          required, the canonical URL ('url' in env.php)
#   DEPLOY_HOST                       required, ssh target (alias or user@host)
#   DEPLOY_SSH_KEY_FILE               optional, private key file for ssh
#   DEPLOY_KNOWN_HOSTS_FILE           optional, known_hosts file for ssh (the workflow Deploy)
#   ANTHROPIC_API_KEY                 optional, key of the Panel assistant
#   REMOTE_PHP                        PHP on the server, default php85 (php in the ssh shell is 8.4)
set -euo pipefail

: "${DEPLOY_HOST:?DEPLOY_HOST missing}"
: "${DEPLOY_PATH:?DEPLOY_PATH missing}"
: "${SITE_URL:?SITE_URL missing}"
DEPLOY_PATH="${DEPLOY_PATH%/}"
SITE_URL="${SITE_URL%/}"
BACKUP_DIR="$DEPLOY_PATH/backups"
RPHP="${REMOTE_PHP:-php85}"

SSH_OPTS=(-o ConnectTimeout=30 -o ServerAliveInterval=30 -o BatchMode=yes)
[ -n "${DEPLOY_SSH_KEY_FILE:-}" ] && SSH_OPTS+=(-i "$DEPLOY_SSH_KEY_FILE")
[ -n "${DEPLOY_KNOWN_HOSTS_FILE:-}" ] && SSH_OPTS+=(-o UserKnownHostsFile="$DEPLOY_KNOWN_HOSTS_FILE" -o StrictHostKeyChecking=yes)
remote() { ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$@"; }
DEST="$DEPLOY_HOST:$DEPLOY_PATH"
RSYNC_SSH=(-e "ssh ${SSH_OPTS[*]}")

[ -f www/index.php ] && [ -f composer.lock ] && [ -f server/root.htaccess ] || { echo "Run from the repository root" >&2; exit 1; }

# State, host config and generated files: never in the build, never deleted on the server.
EXCLUDES=(
  --exclude '/content/' --exclude '/media/'
  --exclude '/site/accounts/' --exclude '/site/cache/' --exclude '/site/sessions/'
  --exclude '/site/config/env.php' --exclude '/site/config/.salt' --exclude '/site/config/.license'
  --exclude '.DS_Store'
)

BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

echo "== Build"
# Kirby and the markdown-field plugin come from composer.lock, not from the local copies.
rsync -a "${EXCLUDES[@]}" --exclude '/kirby/' --exclude '/site/plugins/markdown-field/' www/ "$BUILD/www/"
cp composer.json composer.lock "$BUILD/"
composer validate --no-check-publish --strict --working-dir="$BUILD"
composer install --no-dev --no-interaction --no-progress --optimize-autoloader --working-dir="$BUILD"
# The plugin is in .gitignore and must go up all the same: without it every
# save in the Panel empties excerpt and text.
for f in www/kirby/bootstrap.php www/site/plugins/markdown-field/index.php vendor/autoload.php; do
  [ -f "$BUILD/$f" ] || { echo "Build has no $f" >&2; exit 1; }
done
find "$BUILD/www/site" "$BUILD/www/index.php" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

echo "== Snapshot of the live state"
# Exit code 1 means "no live state yet"; anything else (255: ssh failed) stops the deploy.
set +e
remote "test -d '$DEPLOY_PATH/www/content' && test -d '$DEPLOY_PATH/www/kirby'"
state=$?
set -e
[ "$state" -le 1 ] || { echo "Cannot reach the server (ssh exit $state)" >&2; exit 1; }
if [ "$state" -eq 0 ]; then
  # A file the newest snapshot already holds is a hard link to it, and an
  # unchanged state makes no new snapshot (bin/backup.sh).
  remote "mkdir -p '$BACKUP_DIR' && cd '$BACKUP_DIR' && PHP=$RPHP bash -s -- --snapshot '$DEPLOY_PATH/www' '$BACKUP_DIR'" < bin/backup.sh
  # Keep the newest 30 snapshots (the names sort by time). A .website-tmp-*
  # folder older than a day is a snapshot or an upload that was killed
  # before it was complete.
  remote "cd '$BACKUP_DIR' && { ls -1d website-*/ 2>/dev/null | LC_ALL=C sort -r | tail -n +31 | xargs -r rm -rf; find . -maxdepth 1 -name '.website-tmp-*' -mmin +1440 -exec rm -rf {} +; }"
else
  echo "No live state yet, nothing to back up"
fi

echo "== Code"
remote "mkdir -p '$DEPLOY_PATH/www' '$DEPLOY_PATH/vendor'"
rsync -rlptz --delete "${RSYNC_SSH[@]}" "$BUILD/vendor/" "$DEST/vendor/"
rsync -rlptz --delete "${EXCLUDES[@]}" "${RSYNC_SSH[@]}" "$BUILD/www/" "$DEST/www/"
rsync -tpz "${RSYNC_SSH[@]}" server/root.htaccess "$DEST/.htaccess"
# KAS puts a placeholder page into a new domain folder.
remote "rm -f '$DEPLOY_PATH/index.htm'"

echo "== Host config"
# A site with content but without .salt lost its salt: a new one would
# change every media URL. Restore .salt from a backup instead.
remote "cd '$DEPLOY_PATH/www/site/config' && { test -f env.php || printf '<?php\n\nreturn [\n    \x27debug\x27 => false,\n];\n' > env.php; } && { test -f .salt || { test ! -d ../../content || { echo 'content without .salt: restore .salt from a backup' >&2; exit 1; }; $RPHP -r 'echo bin2hex(random_bytes(24)), \"\n\";' > .salt; }; }"

# Kirby runs from www/index.php: without a fixed url it would take /www as
# its base, and every link would start with /www/.
# The assistant's key travels on stdin, never on a command line, so the
# script goes up as a file first. Without a key the assistant is off; an
# existing key stays.
rsync -tpz "${RSYNC_SSH[@]}" bin/set-env.php "$DEST/set-env.php"
printf '%s' "${ANTHROPIC_API_KEY:-}" | remote "$RPHP '$DEPLOY_PATH/set-env.php' '$DEPLOY_PATH/www/site/config/env.php' 'url=$SITE_URL'; status=\$?; rm -f '$DEPLOY_PATH/set-env.php'; exit \$status"

echo "== Cache"
# Under the site's cache lock: rm -rf would race with a page that writes
# its cache entry at that moment (bin/clear-cache.php).
remote "$RPHP -- '$DEPLOY_PATH/www'" < bin/clear-cache.php

echo "== Check $SITE_URL"
code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 60 "$SITE_URL/" || true)
echo "HTTP $code"
[ "$code" = 200 ] || { echo "Unexpected status" >&2; exit 1; }

echo "== Page cache"
php bin/warm.php --pages "$SITE_URL"
echo "Deploy done"

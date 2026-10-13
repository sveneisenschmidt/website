#!/usr/bin/env bash
# Deploys the code as a new release and switches the site to it in one step.
#
# The site folder on the server (the document root in KAS is the folder
# itself; its .htaccess, from server/root.htaccess, sends every request
# into the www/ of the live release):
#   .htaccess                  names the live release
#   current -> releases/<id>   the live release, for the scripts
#   releases/<id>/             code only: www/, src/, vendor/, a link storage
#   shared/                    state and host config, one copy for every
#                              release: content, media, site/accounts,
#                              site/cache, site/sessions, site/config/env.php,
#                              .salt and .license, storage
#   backups/                   snapshots of the state (bin/backup.sh)
# A release links each state path to shared/, so a switch never moves state.
#
# 1. Build: www/ without state, composer install --no-dev, the links.
# 2. Snapshot of the state, newest 30 kept.
# 3. Upload the release; a file the live release has already becomes a hard link to it.
# 4. Host config: env.php and, for a fresh site, .salt. bin/set-env.php sets
#    'url' to SITE_URL and 'assistantKey' to ANTHROPIC_API_KEY, if set.
# 5. Switch: .htaccess and current name the new release, each replaced in
#    one rename; the page cache is cleared.
# 6. The start page must answer 200. Otherwise the site switches back to the
#    release before and the deploy fails.
# 7. bin/warm.php --pages fills the page cache; the newest 5 releases stay.
#
# A folder with state inside www/ is from before releases; this script
# refuses it.
#
# Environment:
#   DEPLOY_PATH                       required, the site folder on the server
#   SITE_URL                          required, the canonical URL ('url' in env.php)
#   DEPLOY_HOST                       required, ssh target (alias or user@host);
#                                     "local" deploys into a folder on this machine (bin/deploy-test.sh)
#   DEPLOY_SSH_KEY_FILE               optional, private key file for ssh
#   DEPLOY_KNOWN_HOSTS_FILE           optional, known_hosts file for ssh (the workflow CI)
#   ANTHROPIC_API_KEY                 optional, key of the Panel assistant
#   REMOTE_PHP                        PHP on the server, default php85 (php in the ssh shell is 8.4)
set -euo pipefail

: "${DEPLOY_HOST:?DEPLOY_HOST missing}"
: "${DEPLOY_PATH:?DEPLOY_PATH missing}"
: "${SITE_URL:?SITE_URL missing}"
DEPLOY_PATH="${DEPLOY_PATH%/}"
SITE_URL="${SITE_URL%/}"
# The folder on the server has the name of the site's host: a wrong
# DEPLOY_PATH must not overwrite another site of the same account.
SITE_HOST="${SITE_URL#*://}"
[ "$(basename "$DEPLOY_PATH")" = "$SITE_HOST" ] || { echo "DEPLOY_PATH must end in the folder $SITE_HOST" >&2; exit 1; }
RPHP="${REMOTE_PHP:-php85}"
BACKUP_DIR="$DEPLOY_PATH/backups"
SHARED="$DEPLOY_PATH/shared"
ID="$(date -u +%Y%m%dT%H%M%SZ)-$(git rev-parse --short HEAD 2>/dev/null || echo local)"

if [ "$DEPLOY_HOST" = local ]; then
  remote() { bash -c "$*"; }
  DEST="$DEPLOY_PATH"
  RSYNC_SSH=()
else
  SSH_OPTS=(-o ConnectTimeout=30 -o ServerAliveInterval=30 -o BatchMode=yes)
  [ -n "${DEPLOY_SSH_KEY_FILE:-}" ] && SSH_OPTS+=(-i "$DEPLOY_SSH_KEY_FILE")
  [ -n "${DEPLOY_KNOWN_HOSTS_FILE:-}" ] && SSH_OPTS+=(-o UserKnownHostsFile="$DEPLOY_KNOWN_HOSTS_FILE" -o StrictHostKeyChecking=yes)
  remote() { ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$@"; }
  DEST="$DEPLOY_HOST:$DEPLOY_PATH"
  RSYNC_SSH=(-e "ssh ${SSH_OPTS[*]}")
fi

[ -f www/index.php ] && [ -f composer.lock ] && [ -f server/root.htaccess ] || { echo "Run from the repository root" >&2; exit 1; }

BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT
# mktemp creates the folder as 0700, and rsync gives the release folder the
# same mode: the web server could not enter it and would answer 403.
chmod 755 "$BUILD"

echo "== Build $ID"
# State, host config and generated files never go into a release. Kirby and
# the markdown-field plugin come from composer.lock, not from the local copies.
rsync -a --exclude '/content/' --exclude '/media/' \
  --exclude '/site/accounts/' --exclude '/site/cache/' --exclude '/site/sessions/' \
  --exclude '/site/config/env.php' --exclude '/site/config/.salt' --exclude '/site/config/.license' \
  --exclude '/kirby/' --exclude '/site/plugins/markdown-field/' --exclude '.DS_Store' \
  www/ "$BUILD/www/"
# The classes of the site (Website\ in composer.json), before composer
# install: the optimized autoloader lists only the classes it finds.
rsync -a --exclude '.DS_Store' src/ "$BUILD/src/"
cp composer.json composer.lock "$BUILD/"
composer validate --no-check-publish --strict --working-dir="$BUILD"
composer install --no-dev --no-interaction --no-progress --optimize-autoloader --working-dir="$BUILD"
rm "$BUILD/composer.json" "$BUILD/composer.lock"
# The plugin is in .gitignore and must go up all the same: without it every
# save in the Panel empties excerpt and text.
for f in www/kirby/bootstrap.php www/site/plugins/markdown-field/index.php vendor/autoload.php; do
  [ -f "$BUILD/$f" ] || { echo "Build has no $f" >&2; exit 1; }
done
find "$BUILD/src" "$BUILD/www/site" "$BUILD/www/index.php" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
# The links to shared/ are relative: a release works in any site folder.
ln -s ../../../shared/content "$BUILD/www/content"
ln -s ../../../shared/media "$BUILD/www/media"
for d in accounts cache sessions; do ln -s "../../../../shared/site/$d" "$BUILD/www/site/$d"; done
for f in env.php .salt .license; do ln -s "../../../../../shared/site/config/$f" "$BUILD/www/site/config/$f"; done
# The assistant keeps its uploads next to www/ (plugins/assistant/uploads.php).
ln -s ../../shared/storage "$BUILD/storage"

# Switches the site to a release: .htaccess and current each replaced in one
# rename(), so a request sees the old release or the new one, never none.
switch_to() {
  sed "s|RELEASE_ID|$1|" server/root.htaccess > "$BUILD/htaccess"
  chmod 644 "$BUILD/htaccess"
  rsync -tpz ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$BUILD/htaccess" "$DEST/.htaccess.new"
  remote "cd '$DEPLOY_PATH' && rm -f current.new && ln -s 'releases/$1' current.new && $RPHP -r 'exit(rename(\"current.new\", \"current\") && rename(\".htaccess.new\", \".htaccess\") ? 0 : 1);'"
}

echo "== Snapshot of the state"
# releases: state in shared/. www: a site from before releases. none: a fresh site.
layout=$(remote "if [ -d '$SHARED/content' ]; then echo releases; elif [ -d '$DEPLOY_PATH/www/content' ]; then echo www; else echo none; fi")
[ "$layout" != www ] || { echo "State inside www/: the folder is from before releases" >&2; exit 1; }
previous=$(remote "if [ -L '$DEPLOY_PATH/current' ]; then basename \"\$(readlink '$DEPLOY_PATH/current')\"; fi")
if [ "$layout" = releases ]; then
  # A file the newest snapshot already holds is a hard link to it, and an
  # unchanged state makes no new snapshot (bin/backup.sh).
  remote "mkdir -p '$BACKUP_DIR' && cd '$BACKUP_DIR' && PHP=$RPHP bash -s -- --snapshot '$SHARED' '$BACKUP_DIR'" < bin/backup.sh
  # Keep the newest 30 snapshots (the names sort by time). A .website-tmp-*
  # folder older than a day is a snapshot that was killed before it was complete.
  remote "cd '$BACKUP_DIR' && { ls -1d website-*/ 2>/dev/null | LC_ALL=C sort -r | tail -n +31 | xargs -r rm -rf; find . -maxdepth 1 -name '.website-tmp-*' -mmin +1440 -exec rm -rf {} +; }"
else
  echo "No state yet, nothing to back up"
fi

echo "== Release"
remote "mkdir -p '$DEPLOY_PATH/releases/$ID'"
# --checksum: the build has new file times on every run, the content decides.
LINK=()
[ -z "$previous" ] || LINK=(--link-dest="$DEPLOY_PATH/releases/$previous")
rsync -rlptz --checksum ${LINK[@]+"${LINK[@]}"} ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$BUILD/" "$DEST/releases/$ID/"

echo "== Host config"
config="$SHARED/site/config"
remote "mkdir -p '$SHARED/content' '$SHARED/media' '$SHARED/site/accounts' '$SHARED/site/cache' '$SHARED/site/sessions' '$config' '$SHARED/storage'"
# A site with content but without .salt lost its salt: a new one would change
# every media URL. Restore .salt from a backup instead.
remote "mkdir -p '$config' && cd '$config' && { test -f env.php || printf '<?php\n\nreturn [\n    \x27debug\x27 => false,\n];\n' > env.php; } && { test -f .salt || { test '$layout' = none || { echo 'content without .salt: restore .salt from a backup' >&2; exit 1; }; $RPHP -r 'echo bin2hex(random_bytes(24)), \"\n\";' > .salt; }; }"
# Kirby runs from www/index.php: without a fixed url it would take its folder
# as base. The assistant's key travels on stdin, never on a command line, so
# the script goes up as a file first. Without a key an existing key stays.
rsync -tpz ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} bin/set-env.php "$DEST/set-env.php"
printf '%s' "${ANTHROPIC_API_KEY:-}" | remote "$RPHP '$DEPLOY_PATH/set-env.php' '$config/env.php' 'url=$SITE_URL'; status=\$?; rm -f '$DEPLOY_PATH/set-env.php'; exit \$status"

echo "== Switch to $ID"
switch_to "$ID"
# KAS puts a placeholder page into a new domain folder.
remote "rm -f '$DEPLOY_PATH/index.htm'"

clear_cache() {
  # Under the site's cache lock: rm -rf would race with a page that writes
  # its cache entry at that moment (bin/clear-cache.php).
  remote "$RPHP -- '$DEPLOY_PATH/current/www'" < bin/clear-cache.php
}
clear_cache

echo "== Check $SITE_URL"
code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 60 "$SITE_URL/" || true)
echo "HTTP $code"
if [ "$code" != 200 ]; then
  if [ -n "$previous" ]; then
    echo "Back to $previous" >&2
    switch_to "$previous"
    clear_cache
  fi
  exit 1
fi

echo "== Clean up"
# The newest 5 releases stay for a quick way back; the live one always.
remote "cd '$DEPLOY_PATH/releases' && ls -1 | LC_ALL=C sort -r | tail -n +6 | grep -v -x '$ID' | xargs -r rm -rf"

echo "== Page cache"
# Warming only speeds up the first visits: a page it cannot fetch does not
# stop the deploy.
php bin/warm.php --pages "$SITE_URL" || echo "Some pages did not warm, see above"
echo "Deploy done: $ID"

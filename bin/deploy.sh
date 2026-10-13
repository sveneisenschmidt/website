#!/usr/bin/env bash
# Deploys the code of this checkout to the live site. The workflow CI runs it
# after every push to main. bin/deploy-test.sh runs it against a local folder.
# Run it from the repository root. It needs rsync, Composer, PHP and curl on
# this machine, and ssh access to the server.
#
# The server is shared hosting at All-Inkl with Apache. In KAS, the admin
# panel of All-Inkl, the document root of the domain is the site folder. The
# site folder holds:
#   .htaccess       made from server/root.htaccess. It sends every request
#                   to the www/ folder of the live release.
#   current         a symlink to the live release, for the scripts.
#   releases/<id>/  the releases. A release is the code of one deploy: www/,
#                   src/ and vendor/. The id is the UTC time and the short
#                   commit hash.
#   shared/         the files that every release uses and no deploy replaces.
#   backups/        the snapshots. A snapshot is a copy of the state, made by
#                   bin/backup.sh --snapshot.
#
# shared/ holds four kinds of files:
#   state            content/, site/accounts/, site/config/.salt and
#                    site/config/.license. Only a backup can restore them.
#   host config      site/config/env.php. Each host has its own.
#   generated files  media/, site/cache/ and site/sessions/. Kirby makes them
#                    again from the state.
#   uploads          storage/, the files of the Panel assistant's chats.
# A release has a symlink into shared/ for each of these paths, so a switch
# moves no file.
#
# Steps:
# 1. Build the release in a temporary folder: www/ without the paths of
#    shared/, src/, composer install --no-dev, and the symlinks. Then
#    bin/manifest.php writes manifest.json, the hash of every file.
# 2. Make a snapshot of the state. The newest 30 snapshots stay.
# 3. Upload the release. A file that is unchanged since the live release
#    becomes a hard link to it and takes no new space. The uploaded release
#    must match its manifest.
# 4. Create env.php and, on a new site, .salt. bin/set-env.php sets `url` to
#    SITE_URL, and `assistantKey` to ANTHROPIC_API_KEY when it is set.
#    Deny web access to shared/ and backups/.
# 5. Switch: .htaccess and `current` point to the new release. One rename
#    replaces each of them. Then clear the page cache.
#    The page cache holds the HTML of every page that Kirby rendered.
# 6. Health check: the start page, the RSS feed and the newest post of the
#    feed must answer HTTP 200. Otherwise switch back to the previous
#    release, check it the same way and exit with 1.
# 7. Delete all releases except the newest 5. Fill the page cache again
#    with bin/warm.php --pages.
#
# Environment:
#   DEPLOY_HOST              required. The ssh target, an alias or user@host.
#                            "local" deploys into a folder on this machine.
#   DEPLOY_PATH              required. The site folder on the server.
#   SITE_URL                 required. The canonical URL of the site.
#   DEPLOY_SSH_KEY_FILE      optional. The private key for ssh.
#   DEPLOY_KNOWN_HOSTS_FILE  optional. The known_hosts file for ssh.
#   ANTHROPIC_API_KEY        optional. The API key of the Panel assistant.
#   REMOTE_PHP               optional. The PHP command on the server, default
#                            php85. In the ssh shell of the server, `php` is
#                            PHP 8.4.
set -euo pipefail

: "${DEPLOY_HOST:?DEPLOY_HOST missing}"
: "${DEPLOY_PATH:?DEPLOY_PATH missing}"
: "${SITE_URL:?SITE_URL missing}"
DEPLOY_PATH="${DEPLOY_PATH%/}"
SITE_URL="${SITE_URL%/}"
# The site folder must have the host name of SITE_URL as its name. A wrong
# DEPLOY_PATH then stops here and cannot overwrite another site of the
# same hosting account.
SITE_HOST="${SITE_URL#*://}"
[ "$(basename "$DEPLOY_PATH")" = "$SITE_HOST" ] || { echo "DEPLOY_PATH must end in the folder $SITE_HOST." >&2; exit 1; }
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

[ -f www/index.php ] && [ -f composer.lock ] && [ -f server/root.htaccess ] || { echo "Run the script from the repository root." >&2; exit 1; }

BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT
# mktemp creates the folder with mode 0700, and rsync gives that mode to
# releases/<id>/. Apache runs as another user, could not enter the folder
# and would answer 403.
chmod 755 "$BUILD"

echo "== Build $ID"
# The paths of shared/ never go into a release. Kirby and the markdown-field
# plugin are left out too: composer install below installs the versions of
# composer.lock.
rsync -a --exclude '/content/' --exclude '/media/' \
  --exclude '/site/accounts/' --exclude '/site/cache/' --exclude '/site/sessions/' \
  --exclude '/site/config/env.php' --exclude '/site/config/.salt' --exclude '/site/config/.license' \
  --exclude '/kirby/' --exclude '/site/plugins/markdown-field/' --exclude '.DS_Store' \
  www/ "$BUILD/www/"
# src/ holds the classes of the namespace `Website\`. It must be in place
# before composer install: --optimize-autoloader writes only the classes it
# finds then into its class map.
rsync -a --exclude '.DS_Store' src/ "$BUILD/src/"
cp composer.json composer.lock "$BUILD/"
composer validate --no-check-publish --strict --working-dir="$BUILD"
composer install --no-dev --no-interaction --no-progress --optimize-autoloader --working-dir="$BUILD"
rm "$BUILD/composer.json" "$BUILD/composer.lock"
# The markdown-field plugin is not in git, but the release needs it. Without
# it, every save in the Panel empties the fields excerpt and text.
for f in www/kirby/bootstrap.php www/site/plugins/markdown-field/index.php vendor/autoload.php; do
  [ -f "$BUILD/$f" ] || { echo "The build has no $f." >&2; exit 1; }
done
find "$BUILD/src" "$BUILD/www/site" "$BUILD/www/index.php" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
# The symlinks into shared/ are relative, so a release works in any site
# folder.
ln -s ../../../shared/content "$BUILD/www/content"
ln -s ../../../shared/media "$BUILD/www/media"
for d in accounts cache sessions; do ln -s "../../../../shared/site/$d" "$BUILD/www/site/$d"; done
for f in env.php .salt .license; do ln -s "../../../../../shared/site/config/$f" "$BUILD/www/site/config/$f"; done
# The Panel assistant stores its uploads in storage/ next to www/
# (src/Assistant/Uploads.php).
ln -s ../../shared/storage "$BUILD/storage"
php bin/manifest.php write "$BUILD"

# Switches the site to the release $1. A rename() replaces .htaccess and
# `current` each in one step, so a request sees the old or the new file and
# never a missing one. PHP's rename() replaces the symlink `current`; mv would
# move current.new into the folder that `current` points to.
switch_to() {
  # Only the Rewrite lines: the comments name the placeholders too.
  sed -e "/^Rewrite/s|RELEASE_ID|$1|" -e "/^Rewrite/s|SITE_HOST_PATTERN|${SITE_HOST//./\\\\.}|" -e "/^Rewrite/s|SITE_URL|$SITE_URL|" server/root.htaccess > "$BUILD/htaccess"
  chmod 644 "$BUILD/htaccess"
  rsync -tpz ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$BUILD/htaccess" "$DEST/.htaccess.new"
  remote "cd '$DEPLOY_PATH' && rm -f current.new && ln -s 'releases/$1' current.new && $RPHP -r 'exit(rename(\"current.new\", \"current\") && rename(\".htaccess.new\", \".htaccess\") ? 0 : 1);'"
}

echo "== Snapshot of the state"
# The layout of the site folder:
#   releases  the state is in shared/.
#   www       the state is in www/, as before releases existed. A release has
#             no symlink to that state, so the script stops.
#   none      a new site folder without state.
layout=$(remote "if [ -d '$SHARED/content' ]; then echo releases; elif [ -d '$DEPLOY_PATH/www/content' ]; then echo www; else echo none; fi")
[ "$layout" != www ] || { echo "The state is in www/, the layout from before releases. Nothing was deployed." >&2; exit 1; }
previous=$(remote "if [ -L '$DEPLOY_PATH/current' ]; then basename \"\$(readlink '$DEPLOY_PATH/current')\"; fi")
if [ "$layout" = releases ]; then
  # bin/backup.sh runs on the server from stdin. An unchanged state makes no
  # new snapshot.
  remote "mkdir -p '$BACKUP_DIR' && cd '$BACKUP_DIR' && PHP=$RPHP bash -s -- --snapshot '$SHARED' '$BACKUP_DIR'" < bin/backup.sh
  # Keep the newest 30 snapshots. Their names start with the UTC time, so a
  # text sort is a sort by time. A .website-tmp-* folder older than one day
  # is a snapshot whose process stopped before it was complete.
  remote "cd '$BACKUP_DIR' && { ls -1d website-*/ 2>/dev/null | LC_ALL=C sort -r | tail -n +31 | xargs -r rm -rf; find . -maxdepth 1 -name '.website-tmp-*' -mmin +1440 -exec rm -rf {} +; }"
else
  echo "The site has no state yet, so there is nothing to back up."
fi

echo "== Release"
remote "mkdir -p '$DEPLOY_PATH/releases/$ID'"
# Every build gives all files a new mtime, so --checksum makes rsync compare
# the content.
LINK=()
[ -z "$previous" ] || LINK=(--link-dest="$DEPLOY_PATH/releases/$previous")
rsync -rlptz --checksum ${LINK[@]+"${LINK[@]}"} ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$BUILD/" "$DEST/releases/$ID/"
# The check runs with the PHP of the server, as the workflow Integrity runs
# it later. A failed check stops the deploy before the switch.
remote "$RPHP -- check '$DEPLOY_PATH/releases/$ID'" < bin/manifest.php

echo "== Host config"
config="$SHARED/site/config"
remote "mkdir -p '$SHARED/content' '$SHARED/media' '$SHARED/site/accounts' '$SHARED/site/cache' '$SHARED/site/sessions' '$config' '$SHARED/storage'"
# A site with content but without .salt lost it, and a new salt would change
# every media URL. The script then stops: restore .salt from a backup.
# umask 077 creates env.php and .salt for the account user only.
remote "umask 077 && mkdir -p '$config' && cd '$config' && { test -f env.php || printf '<?php\n\nreturn [\n    \x27debug\x27 => false,\n];\n' > env.php; } && { test -f .salt || { test '$layout' = none || { echo 'The site has content but no .salt. Restore .salt from a backup.' >&2; exit 1; }; $RPHP -r 'echo bin2hex(random_bytes(24)), \"\n\";' > .salt; }; }"
# Kirby runs from releases/<id>/www/index.php. Without a fixed `url`, it
# would build its URLs from that folder. The assistant's key goes over stdin:
# other users of a shared host can read a command line with ps. Because stdin
# holds the key, set-env.php is uploaded as a file first.
# An empty ANTHROPIC_API_KEY keeps the key that env.php has.
rsync -tpz ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} bin/set-env.php "$DEST/set-env.php"
printf '%s' "${ANTHROPIC_API_KEY:-}" | remote "$RPHP '$DEPLOY_PATH/set-env.php' '$config/env.php' 'url=$SITE_URL'; status=\$?; rm -f '$DEPLOY_PATH/set-env.php'; exit \$status"
# Only PHP reads these files, and PHP runs as the user of the hosting
# account. No other user needs them, and env.php holds the assistant's key.
remote "cd '$config' && chmod 600 env.php .salt && { test ! -e .license || chmod 600 .license; }"
# Apache must never serve shared/ or backups/. server/deny.htaccess denies
# every request to them, also when the rewrite rule of .htaccess is lost.
# Mode 700 keeps Apache, which runs as another user, out of the snapshots
# and the accounts. PHP runs as the account user and still reads them.
rsync -tpz ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} server/deny.htaccess "$DEST/shared/.htaccess"
remote "mkdir -p '$BACKUP_DIR' && cp '$SHARED/.htaccess' '$BACKUP_DIR/.htaccess' && chmod 700 '$BACKUP_DIR' '$SHARED/site/accounts'"

echo "== Switch to $ID"
switch_to "$ID"
# KAS puts a placeholder page index.htm into the folder of a new domain.
remote "rm -f '$DEPLOY_PATH/index.htm'"

clear_cache() {
  # bin/clear-cache.php takes the lock of the page cache. A plain rm -rf
  # could run while a request writes its cache entry, and that request
  # would fail.
  remote "$RPHP -- '$DEPLOY_PATH/current/www'" < bin/clear-cache.php
}
clear_cache

# Prints the HTTP status of a GET request to $1 and writes the body to $2.
http_status() { curl -sS -o "${2:-/dev/null}" -w '%{http_code}' --max-time 60 "$1" || true; }

# Requests the start page, the RSS feed and the newest post of the feed.
# The start page alone does not show that a post renders. Returns 1 when
# one of them does not answer HTTP 200.
check_site() {
  local code post
  code=$(http_status "$SITE_URL/")
  echo "HTTP $code /"
  [ "$code" = 200 ] || return 1
  code=$(http_status "$SITE_URL/index.xml" "$BUILD/feed.xml")
  echo "HTTP $code /index.xml"
  [ "$code" = 200 ] || return 1
  # The first <link> inside an <item> is the newest post. The check leaves
  # out the ?source=rss that the feed adds to it.
  post=$(sed -n '/<item>/,/<\/item>/s:.*<link>\([^<?]*\).*</link>.*:\1:p' "$BUILD/feed.xml" | head -1)
  [ -n "$post" ] || { echo "The feed lists no post."; return 1; }
  code=$(http_status "$post")
  echo "HTTP $code ${post#"$SITE_URL"}"
  [ "$code" = 200 ]
}

echo "== Health check of $SITE_URL"
if ! check_site; then
  if [ -n "$previous" ]; then
    echo "Switching back to $previous" >&2
    switch_to "$previous"
    clear_cache
    echo "== Health check of $previous"
    check_site || echo "The previous release $previous fails the health check too." >&2
  fi
  exit 1
fi

echo "== Clean up"
# Delete every release except the newest 5 and the live one.
remote "cd '$DEPLOY_PATH/releases' && ls -1 | LC_ALL=C sort -r | tail -n +6 | grep -v -x '$ID' | xargs -r rm -rf"

echo "== Page cache"
# bin/warm.php requests every page once, so Kirby fills the page cache
# before the first visitor comes. That only makes the first visits faster,
# so a failed request does not fail the deploy.
php bin/warm.php --pages "$SITE_URL" || echo "Some pages did not load, see above."
echo "Deploy done: $ID"

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
# 4. Staging password: .htpasswd next to www/, the auth block in www/.htaccess.
# 5. Host config: env.php and, for a fresh site, .salt. 'url' is SITE_URL;
#    'assistantKey' is ANTHROPIC_API_KEY, if set.
# 6. Page cache cleared under the site's cache lock, then a request to the start page.
#
# Environment:
#   DEPLOY_PATH                       required, the site folder on the server
#   SITE_URL                          required, the canonical URL ('url' in env.php)
#   DEPLOY_HOST                       ssh target (alias or user@host); empty: DEPLOY_PATH
#                                     is a local folder standing in for the server (tests)
#   DEPLOY_SSH_KEY_FILE               optional, private key file for ssh
#   STAGING_USER, STAGING_PASSWORD    staging password
#   PUBLIC=1                          deploy without staging password (launch, phase 5b)
#   ANTHROPIC_API_KEY                 optional, key of the Panel assistant
#   REMOTE_PHP                        PHP on the server, default php85 (php in the ssh shell is 8.4)
set -euo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH missing}"
: "${SITE_URL:?SITE_URL missing}"
DEPLOY_PATH="${DEPLOY_PATH%/}"
SITE_URL="${SITE_URL%/}"
BACKUP_DIR="$DEPLOY_PATH/backups"
RPHP="${REMOTE_PHP:-php85}"

SSH_OPTS=(-o ConnectTimeout=30 -o ServerAliveInterval=30 -o BatchMode=yes)
[ -n "${DEPLOY_SSH_KEY_FILE:-}" ] && SSH_OPTS+=(-i "$DEPLOY_SSH_KEY_FILE")
if [ -n "${DEPLOY_HOST:-}" ]; then
  remote() { ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$@"; }
  DEST="$DEPLOY_HOST:$DEPLOY_PATH"
  RSYNC_SSH=(-e "ssh ${SSH_OPTS[*]}")
else
  remote() { bash -c "$*"; }
  DEST="$DEPLOY_PATH"
  RSYNC_SSH=()
fi

[ -f www/index.php ] && [ -f composer.lock ] && [ -f server/root.htaccess ] || { echo "Run from the repository root" >&2; exit 1; }

# Until launch the site must stay behind the staging password. Going public
# is a deliberate step: PUBLIC=1 (and a Kirby license).
if [ -z "${STAGING_USER:-}" ] || [ -z "${STAGING_PASSWORD:-}" ]; then
  [ "${PUBLIC:-0}" = "1" ] || { echo "STAGING_USER/STAGING_PASSWORD not set. Refusing to deploy a public site (set PUBLIC=1 at launch)." >&2; exit 1; }
fi

# State, host config and generated files: never in the build, never deleted on the server.
EXCLUDES=(
  --exclude '/content/' --exclude '/content.new/' --exclude '/media/'
  --exclude '/site/accounts/' --exclude '/site/cache/' --exclude '/site/sessions/' --exclude '/site/logs/'
  --exclude '/site/config/env.php' --exclude '/site/config/.salt' --exclude '/site/config/.license'
  --exclude '/.htpasswd' --exclude '.DS_Store'
)

BUILD="$(mktemp -d)"
HTACCESS="$(mktemp)"
trap 'rm -rf "$BUILD" "$HTACCESS"' EXIT

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
rsync -rlptz --delete ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$BUILD/vendor/" "$DEST/vendor/"
# www/.htaccess goes up on its own, after .htpasswd.
rsync -rlptz --delete "${EXCLUDES[@]}" --exclude '/.htaccess' ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$BUILD/www/" "$DEST/www/"

echo "== Staging password"
if [ -n "${STAGING_USER:-}" ] && [ -n "${STAGING_PASSWORD:-}" ]; then
  # The password travels on stdin, never on a command line. Apache checks it
  # on every request (page, CSS, font, each photo): bcrypt cost 5 takes about
  # 4 ms per check, the PHP default 12 took 150 ms.
  printf '%s' "$STAGING_PASSWORD" | remote "$RPHP -r '\$p = stream_get_contents(STDIN); file_put_contents(\$argv[1], \$argv[2] . \":\" . password_hash(\$p, PASSWORD_BCRYPT, [\"cost\" => 5]) . \"\n\");' '$DEPLOY_PATH/.htpasswd' '$STAGING_USER'"
  # All requests land in www/ (root .htaccess), so the block there covers the whole site.
  sed -e "s|^#AuthType Basic|AuthType Basic|" \
      -e "s|^#AuthName |AuthName |" \
      -e "s|^#AuthUserFile __HTPASSWD__|AuthUserFile $DEPLOY_PATH/.htpasswd|" \
      -e "s|^#Require valid-user|Require valid-user|" "$BUILD/www/.htaccess" > "$HTACCESS"
  grep -q "^AuthUserFile $DEPLOY_PATH/.htpasswd" "$HTACCESS" || { echo "Staging block not found in www/.htaccess" >&2; exit 1; }
else
  cp "$BUILD/www/.htaccess" "$HTACCESS"
fi
# mktemp creates 0600; Apache must be able to read the file.
chmod 644 "$HTACCESS"
rsync -tpz ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$HTACCESS" "$DEST/www/.htaccess"
rsync -tpz ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} server/root.htaccess "$DEST/.htaccess"
[ -n "${STAGING_USER:-}" ] && [ -n "${STAGING_PASSWORD:-}" ] || remote "rm -f '$DEPLOY_PATH/.htpasswd'"
# KAS puts a placeholder page into a new domain folder.
remote "rm -f '$DEPLOY_PATH/index.htm'"

echo "== Host config"
# A site with content but without .salt lost its salt: a new one would
# change every media URL. Restore .salt from a backup instead.
remote "cd '$DEPLOY_PATH/www/site/config' && { test -f env.php || printf '<?php\n\nreturn [\n    \x27debug\x27 => false,\n];\n' > env.php; } && { test -f .salt || { test ! -d ../../content || { echo 'content without .salt: restore .salt from a backup' >&2; exit 1; }; $RPHP -r 'echo bin2hex(random_bytes(24)), \"\n\";' > .salt; }; }"

# Kirby runs from www/index.php: without a fixed url it would take /www as
# its base, and every link would start with /www/.
remote "$RPHP -- '$DEPLOY_PATH/www/site/config/env.php' 'url=$SITE_URL'" < bin/set-env.php

# The assistant's key travels on stdin, never on a command line. Without it
# the assistant is off; an existing key stays.
if [ -n "${ANTHROPIC_API_KEY:-}" ]; then
  printf '%s' "$ANTHROPIC_API_KEY" | remote "$RPHP -r '\$k = trim(stream_get_contents(STDIN)); \$f = \$argv[1]; \$e = require \$f; if ((\$e[\"assistantKey\"] ?? null) === \$k) exit(0); \$e[\"assistantKey\"] = \$k; file_put_contents(\$f . \".tmp\", \"<?php\\n\\nreturn \" . var_export(\$e, true) . \";\\n\"); rename(\$f . \".tmp\", \$f); echo \"assistantKey: set\\n\";' '$DEPLOY_PATH/www/site/config/env.php'"
fi

echo "== Cache"
# Under the site's cache lock: rm -rf would race with a page that writes
# its cache entry at that moment (bin/clear-cache.php).
remote "$RPHP -- '$DEPLOY_PATH/www'" < bin/clear-cache.php

echo "== Check $SITE_URL"
AUTH=()
[ -n "${STAGING_USER:-}" ] && AUTH=(-u "$STAGING_USER:$STAGING_PASSWORD")
code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 60 ${AUTH[@]+"${AUTH[@]}"} "$SITE_URL/" || true)
echo "HTTP $code"
case "$code" in 200|404) echo "Deploy done" ;; *) echo "Unexpected status" >&2; exit 1 ;; esac

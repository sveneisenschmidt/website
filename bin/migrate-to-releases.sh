#!/usr/bin/env bash
# Moves a site from before releases to the layout of bin/deploy.sh, once.
# Before: www/ and vendor/ hold the code, the state lies inside www/.
# After: the code that runs now is the first release, the state is in
# shared/, current points to the release. Nothing is built or uploaded but
# the root .htaccess. A snapshot comes first. If the start page does not
# answer 200 afterwards, every move goes back and the old .htaccess returns.
#
# Environment: DEPLOY_HOST (ssh target, or "local" for bin/deploy-test.sh),
#              DEPLOY_PATH, SITE_URL, DEPLOY_SSH_KEY_FILE,
#              REMOTE_PHP (default php85)
set -euo pipefail

: "${DEPLOY_HOST:?DEPLOY_HOST missing}"
: "${DEPLOY_PATH:?DEPLOY_PATH missing}"
: "${SITE_URL:?SITE_URL missing}"
DEPLOY_PATH="${DEPLOY_PATH%/}"
SITE_URL="${SITE_URL%/}"
SITE_HOST="${SITE_URL#*://}"
[ "$(basename "$DEPLOY_PATH")" = "$SITE_HOST" ] || { echo "DEPLOY_PATH must end in the folder $SITE_HOST" >&2; exit 1; }
RPHP="${REMOTE_PHP:-php85}"
ID="$(date -u +%Y%m%dT%H%M%SZ)-before-releases"
R="releases/$ID"

if [ "$DEPLOY_HOST" = local ]; then
  remote() { bash -c "$*"; }
  DEST="$DEPLOY_PATH"
  RSYNC_SSH=()
else
  SSH_OPTS=(-o ConnectTimeout=30 -o ServerAliveInterval=30 -o BatchMode=yes)
  [ -n "${DEPLOY_SSH_KEY_FILE:-}" ] && SSH_OPTS+=(-i "$DEPLOY_SSH_KEY_FILE")
  remote() { ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$@"; }
  DEST="$DEPLOY_HOST:$DEPLOY_PATH"
  RSYNC_SSH=(-e "ssh ${SSH_OPTS[*]}")
fi

[ -f server/root.htaccess ] && [ -f bin/backup.sh ] || { echo "Run from the repository root" >&2; exit 1; }
remote "cd '$DEPLOY_PATH' && test -d www/content && test -d vendor && test ! -e shared && test ! -e current" \
  || { echo "Not a site from before releases: $DEPLOY_PATH" >&2; exit 1; }

echo "== Snapshot of the state"
remote "mkdir -p '$DEPLOY_PATH/backups' && cd '$DEPLOY_PATH/backups' && PHP=$RPHP bash -s -- --snapshot '$DEPLOY_PATH/www' '$DEPLOY_PATH/backups'" < bin/backup.sh

echo "== Move to $R"
# The new .htaccess goes up first and takes its place in the same call as
# the moves: the site is away for the length of one shell command.
HTACCESS="$(mktemp)"
trap 'rm -f "$HTACCESS"' EXIT
sed "s|RELEASE_ID|$ID|" server/root.htaccess > "$HTACCESS"
chmod 644 "$HTACCESS"
rsync -tpz ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$HTACCESS" "$DEST/.htaccess.new"
remote "set -e; cd '$DEPLOY_PATH'
  cp -p .htaccess .htaccess.before
  mkdir -p '$R' shared/site/config
  mv www '$R/www'
  mv vendor '$R/vendor'
  mv '$R/www/content' shared/content
  if [ -d '$R/www/media' ]; then mv '$R/www/media' shared/media; else mkdir shared/media; fi
  for d in accounts cache sessions; do
    if [ -d '$R/www/site/'\$d ]; then mv '$R/www/site/'\$d shared/site/\$d; else mkdir shared/site/\$d; fi
  done
  for f in env.php .salt .license; do
    if [ -e '$R/www/site/config/'\$f ]; then mv '$R/www/site/config/'\$f shared/site/config/\$f; fi
  done
  if [ -d storage ]; then mv storage shared/storage; else mkdir shared/storage; fi
  ln -s ../../../shared/content '$R/www/content'
  ln -s ../../../shared/media '$R/www/media'
  for d in accounts cache sessions; do ln -s ../../../../shared/site/\$d '$R/www/site/'\$d; done
  for f in env.php .salt .license; do ln -s ../../../../../shared/site/config/\$f '$R/www/site/config/'\$f; done
  ln -s ../../shared/storage '$R/storage'
  ln -s '$R' current
  mv .htaccess.new .htaccess"
remote "$RPHP -- '$DEPLOY_PATH/current/www'" < bin/clear-cache.php

echo "== Check $SITE_URL"
code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 60 "$SITE_URL/" || true)
echo "HTTP $code"
if [ "$code" != 200 ]; then
  echo "Moving back" >&2
  remote "set -e; cd '$DEPLOY_PATH'
    rm current '$R/storage' '$R/www/content' '$R/www/media'
    for d in accounts cache sessions; do rm '$R/www/site/'\$d; mv shared/site/\$d '$R/www/site/'\$d; done
    for f in env.php .salt .license; do rm '$R/www/site/config/'\$f; if [ -e shared/site/config/\$f ]; then mv shared/site/config/\$f '$R/www/site/config/'\$f; fi; done
    mv shared/content '$R/www/content'
    mv shared/media '$R/www/media'
    mv shared/storage storage
    mv '$R/www' www
    mv '$R/vendor' vendor
    mv .htaccess.before .htaccess
    rmdir shared/site/config shared/site shared '$R' releases"
  exit 1
fi
remote "rm -f '$DEPLOY_PATH/.htaccess.before'"
echo "Moved: current -> $R"

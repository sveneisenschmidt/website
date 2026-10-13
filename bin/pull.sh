#!/usr/bin/env bash
# Fetches the server's state, from concertshotsbyluisa's bin/pull.sh: a
# snapshot on the server (as the deploy makes it), downloaded as an archive
# to backups/ and restored into the local www/. Local content and accounts
# are replaced by the server's.
#
# Environment: DEPLOY_PATH (required), DEPLOY_HOST (ssh target; empty:
#              DEPLOY_PATH is local), DEPLOY_SSH_KEY_FILE, REMOTE_PHP (default php85)
set -euo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH missing}"
DEPLOY_PATH="${DEPLOY_PATH%/}"
BACKUP_DIR="$DEPLOY_PATH/backups"
RPHP="${REMOTE_PHP:-php85}"

SSH_OPTS=(-o ConnectTimeout=30 -o BatchMode=yes)
[ -n "${DEPLOY_SSH_KEY_FILE:-}" ] && SSH_OPTS+=(-i "$DEPLOY_SSH_KEY_FILE")
if [ -n "${DEPLOY_HOST:-}" ]; then
  remote() { ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$@"; }
else
  remote() { bash -c "$*"; }
fi

snapshot=$(remote "mkdir -p '$BACKUP_DIR' && cd '$BACKUP_DIR' && PHP=$RPHP bash -s -- --snapshot '$DEPLOY_PATH/www' '$BACKUP_DIR'" < bin/backup.sh | tail -1)
ARCHIVE="backups/$(basename "$snapshot").tar.gz"
mkdir -p backups
# The archive holds what bin/backup.sh would write; .part until it is complete.
remote "tar -C '$snapshot' -czf - state MANIFEST.sha256 META" > "$ARCHIVE.part"
mv "$ARCHIVE.part" "$ARCHIVE"
bin/restore.sh "$ARCHIVE" www

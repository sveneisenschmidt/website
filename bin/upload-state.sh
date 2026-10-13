#!/usr/bin/env bash
# Puts a local state on the server: the first state of staging, and the
# last import before the switch (phase 5b). Replaces the content on the
# server, never for daily work.
#
# 1. Snapshot of the server's state, if there is any (as the deploy makes it).
# 2. rsync of the local snapshot folder into the server's backups/, under a
#    temporary name until it is complete, then named with the current time:
#    it is the newest snapshot, the next one hard-links to it.
# 3. bin/restore.sh on the server: checksums first, then the state is replaced.
#
# Usage: bin/upload-state.sh <snapshot-folder from bin/backup.sh --snapshot --no-accounts>
# Environment: DEPLOY_PATH (required), DEPLOY_HOST (ssh target; empty:
#              DEPLOY_PATH is local), REMOTE_PHP (default php85)
set -euo pipefail

SNAP="${1:?Usage: bin/upload-state.sh <snapshot-folder>}"
SNAP="${SNAP%/}"
: "${DEPLOY_PATH:?DEPLOY_PATH missing}"
DEPLOY_PATH="${DEPLOY_PATH%/}"
BACKUP_DIR="$DEPLOY_PATH/backups"
RPHP="${REMOTE_PHP:-php85}"

[ -f "$SNAP/MANIFEST.sha256" ] && [ -d "$SNAP/state/content" ] || { echo "Not a snapshot folder: $SNAP" >&2; exit 1; }
# The local dev login never goes to a server.
[ ! -e "$SNAP/state/site/accounts" ] || { echo "$SNAP holds site/accounts. Make it with bin/backup.sh --snapshot --no-accounts." >&2; exit 1; }

SSH_OPTS=(-o ConnectTimeout=30 -o ServerAliveInterval=30 -o BatchMode=yes)
if [ -n "${DEPLOY_HOST:-}" ]; then
  remote() { ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$@"; }
  DEST="$DEPLOY_HOST:$BACKUP_DIR"
  RSYNC_SSH=(-e "ssh ${SSH_OPTS[*]}")
else
  remote() { bash -c "$*"; }
  DEST="$BACKUP_DIR"
  RSYNC_SSH=()
fi

remote "test -d '$DEPLOY_PATH/www/kirby'" || { echo "No code on the server: make kirby-deploy first" >&2; exit 1; }
if remote "test -d '$DEPLOY_PATH/www/content'"; then
  echo "== Snapshot of the server's state"
  remote "mkdir -p '$BACKUP_DIR' && cd '$BACKUP_DIR' && PHP=$RPHP bash -s -- --snapshot '$DEPLOY_PATH/www' '$BACKUP_DIR'" < bin/backup.sh
fi

echo "== Upload $SNAP"
remote "mkdir -p '$BACKUP_DIR'"
# -a keeps mtimes and modes, Kirby uses mtimes in media URLs. --partial: a
# second run continues a broken upload.
rsync -a --partial --stats ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$SNAP/" "$DEST/.website-tmp-upload/"
NAME="website-$(date -u +%Y%m%dT%H%M%SZ)"
remote "cd '$BACKUP_DIR' && test ! -e '$NAME' && mv .website-tmp-upload '$NAME'"

echo "== Restore $NAME"
remote "bash -s -- '$BACKUP_DIR/$NAME' '$DEPLOY_PATH/www'" < bin/restore.sh

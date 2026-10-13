#!/usr/bin/env bash
# Replaces the local state with the state of the live site. make pull runs it.
# 1. Makes a snapshot on the server with bin/backup.sh, as bin/deploy.sh does.
# 2. Downloads the snapshot as an archive into backups/.
# 3. Restores the archive into the local www/ with bin/restore.sh. The local
#    content and accounts are lost. Run make backup first to keep a copy.
#
# Environment:
#   DEPLOY_HOST          required. The ssh target, an alias or user@host.
#   DEPLOY_PATH          required. The site folder on the server.
#   DEPLOY_SSH_KEY_FILE  optional. The private key for ssh.
#   REMOTE_PHP           optional. The PHP command on the server, default php85.
set -euo pipefail

: "${DEPLOY_HOST:?DEPLOY_HOST missing}"
: "${DEPLOY_PATH:?DEPLOY_PATH missing}"
DEPLOY_PATH="${DEPLOY_PATH%/}"
BACKUP_DIR="$DEPLOY_PATH/backups"
RPHP="${REMOTE_PHP:-php85}"

SSH_OPTS=(-o ConnectTimeout=30 -o BatchMode=yes)
[ -n "${DEPLOY_SSH_KEY_FILE:-}" ] && SSH_OPTS+=(-i "$DEPLOY_SSH_KEY_FILE")
remote() { ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$@"; }

snapshot=$(remote "mkdir -p '$BACKUP_DIR' && cd '$BACKUP_DIR' && PHP=$RPHP bash -s -- --snapshot '$DEPLOY_PATH/shared' '$BACKUP_DIR'" < bin/backup.sh | tail -1)
ARCHIVE="backups/$(basename "$snapshot").tar.gz"
mkdir -p backups
# tar on the server writes the archive format of bin/backup.sh to stdout. The
# file keeps the suffix .part until the download is complete, so a broken
# download never looks like a backup.
remote "tar -C '$snapshot' -czf - state MANIFEST.sha256 META" > "$ARCHIVE.part"
mv "$ARCHIVE.part" "$ARCHIVE"
bin/restore.sh "$ARCHIVE" www

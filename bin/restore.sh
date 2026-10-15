#!/usr/bin/env bash
# Restores a backup of bin/backup.sh, an archive or a snapshot folder, into a
# site root or into shared/ on the server. make restore, bin/pull.sh and
# bin/restore-test.sh run it.
# 1. Checks every file against MANIFEST.sha256. On a mismatch, nothing changes.
# 2. Deletes the state and the generated folders media/, site/cache/ and
#    site/sessions/. Kirby makes the generated files again.
# 3. Copies the state from the backup.
# The script copies the files of a snapshot and never moves or changes them,
# because older snapshots share them as hard links. env.php stays as it is:
# make config creates it locally, bin/deploy.sh on the server.
#
# The target is one of:
#   site root  www/ with the code (kirby/), locally. Its state paths must be
#              folders and files, not symlinks.
#   shared/    the folder on the server that holds the state of every
#              release (see bin/deploy.sh). It has site/config/env.php and no
#              code. The releases link to its paths, so the restore makes the
#              folders media/, site/cache/ and site/sessions/ again, empty,
#              and gives the state the modes that bin/deploy.sh sets. No step
#              of the restore needs the code of the site, so none is left out.
# A release on the server, such as current/www, is no target: its state paths
# are symlinks into shared/. Deleting them would cut the release off from
# shared/, and the copy would land inside the release.
#
# On the server, from this checkout:
#   ssh <host> "cd <site folder> && bash -s -- backups/website-<time> shared" < bin/restore.sh
#
# Usage: bin/restore.sh <archive.tar.gz | snapshot-folder> [www-root | shared-folder]
set -euo pipefail

ARCHIVE="${1:?Usage: bin/restore.sh <archive.tar.gz | snapshot-folder> [www-root | shared-folder]}"
WWW="${2:-www}"
STATE_PATHS=(content site/accounts site/config/.salt site/config/.license)
GENERATED=(media site/cache site/sessions)

[ -f "$ARCHIVE" ] || [ -d "$ARCHIVE" ] || { echo "No such archive or snapshot: $ARCHIVE" >&2; exit 1; }
if [ -d "$WWW/kirby" ]; then
  TARGET=site
elif [ -f "$WWW/site/config/env.php" ] && [ ! -e "$WWW/index.php" ]; then
  TARGET=shared
else
  echo "Not a site root or shared/: $WWW (check out the repository, or deploy once to make shared/)" >&2
  exit 1
fi
for p in "${STATE_PATHS[@]}" "${GENERATED[@]}"; do
  [ ! -L "$WWW/$p" ] || { echo "$WWW/$p is a symlink. Restore into the folder it points to, shared/ on the server. Nothing restored." >&2; exit 1; }
done

sha256check() { if command -v sha256sum >/dev/null; then sha256sum -c --quiet "$@"; else shasum -a 256 -c --quiet "$@"; fi; }

if [ -d "$ARCHIVE" ]; then
  SRC="$(cd "$ARCHIVE" && pwd)"
else
  SRC="$(mktemp -d)"
  trap 'rm -rf "$SRC"' EXIT
  tar -C "$SRC" --exclude '._*' -xzf "$ARCHIVE"
fi
[ -f "$SRC/MANIFEST.sha256" ] && [ -d "$SRC/state/content" ] || { echo "Archive is not a site backup" >&2; exit 1; }

( cd "$SRC/state" && sha256check "$SRC/MANIFEST.sha256" ) || { echo "Checksum mismatch, nothing restored" >&2; exit 1; }

# The check above reads only the files the manifest lists. A file that the
# manifest does not list must fail the restore too.
listed=$(wc -l < "$SRC/MANIFEST.sha256" | tr -d ' ')
actual=$( cd "$SRC/state" && find . -type f ! -name '.DS_Store' ! -name '._*' | wc -l | tr -d ' ')
[ "$listed" = "$actual" ] || { echo "Archive has $actual files, manifest lists $listed" >&2; exit 1; }

for p in "${STATE_PATHS[@]}" "${GENERATED[@]}"; do rm -rf "${WWW:?}/$p"; done

for p in "${STATE_PATHS[@]}"; do
  if [ -e "$SRC/state/$p" ]; then
    mkdir -p "$WWW/$(dirname "$p")"
    cp -Rp "$SRC/state/$p" "$WWW/$p"
  fi
done
# A backup made before bin/backup.sh left out SQLite journals can hold one.
# Next to the copy of the database it would roll that copy back.
find "$WWW/content" -type f \( -name '*.sqlite-journal' -o -name '*.sqlite-wal' -o -name '*.sqlite-shm' \) -delete

if [ "$TARGET" = shared ]; then
  for p in "${GENERATED[@]}"; do mkdir -p "$WWW/$p"; done
  [ ! -d "$WWW/site/accounts" ] || chmod 700 "$WWW/site/accounts"
  for f in .salt .license; do [ ! -e "$WWW/site/config/$f" ] || chmod 600 "$WWW/site/config/$f"; done
fi

echo "Restored $listed files from $ARCHIVE into $WWW ($TARGET)"
sed 's/^/  /' "$SRC/META"

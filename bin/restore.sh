#!/usr/bin/env bash
# Restores a backup of bin/backup.sh, an archive or a snapshot folder, into a
# site root. make restore, bin/pull.sh and bin/restore-test.sh run it.
# 1. Checks every file against MANIFEST.sha256. On a mismatch, nothing changes.
# 2. Deletes the state and the generated folders media/, site/cache/ and
#    site/sessions/. Kirby makes the generated files again.
# 3. Copies the state from the backup.
# The script copies the files of a snapshot and never moves or changes them,
# because older snapshots share them as hard links. env.php stays as it is:
# make config creates it locally.
#
# Usage: bin/restore.sh <archive.tar.gz | snapshot-folder> [www-root]
set -euo pipefail

ARCHIVE="${1:?Usage: bin/restore.sh <archive.tar.gz | snapshot-folder> [www-root]}"
WWW="${2:-www}"

[ -f "$ARCHIVE" ] || [ -d "$ARCHIVE" ] || { echo "No such archive or snapshot: $ARCHIVE" >&2; exit 1; }
[ -d "$WWW/kirby" ] || { echo "Not a site root: $WWW (check out the repository first)" >&2; exit 1; }

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

rm -rf "$WWW/content" "$WWW/site/accounts" "$WWW/site/config/.salt" "$WWW/site/config/.license" \
       "$WWW/media" "$WWW/site/cache" "$WWW/site/sessions"

for p in content site/accounts site/config/.salt site/config/.license; do
  if [ -e "$SRC/state/$p" ]; then
    mkdir -p "$WWW/$(dirname "$p")"
    cp -Rp "$SRC/state/$p" "$WWW/$p"
  fi
done

echo "Restored $listed files from $ARCHIVE"
sed 's/^/  /' "$SRC/META"

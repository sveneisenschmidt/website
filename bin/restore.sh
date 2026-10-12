#!/usr/bin/env bash
# Restore a backup made by bin/backup.sh (archive or snapshot folder) into a site root.
# Checks every file against MANIFEST.sha256 before anything is replaced.
# A snapshot is only read: its files are copied, never moved or changed,
# because older snapshots share them as hard links.
# Generated folders (media, cache, sessions) are cleared; Kirby rebuilds them.
# Host config (env.php) is not touched: import it separately (make kirby-config locally).
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

# Extra files in the archive that the manifest does not list are an error too.
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

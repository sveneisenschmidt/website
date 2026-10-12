#!/usr/bin/env bash
# Backup of all site state, as one archive or as a snapshot folder.
#
# State = www/content, www/site/accounts, www/site/config/.salt, www/site/config/.license
# Everything else is code (git), host config (env.php, imported per host)
# or generated (media, cache, sessions).
#
# Usage: bin/backup.sh [--snapshot] [www-root] [output-dir]
# Output: <output-dir>/website-<UTC timestamp>.tar.gz containing state/, MANIFEST.sha256 and META.
#
# --snapshot writes the folder <output-dir>/website-<UTC timestamp>/ with the same
# content instead (the deploy's backups on the server). A file that is the same
# as in the newest snapshot (checksum, mtime, mode) becomes a hard link to it, so
# it takes space only once. If the whole state (files and folders) is the same
# as in the newest snapshot, no new snapshot is made and the script prints the
# newest one.
set -euo pipefail

SNAPSHOT=0
if [ "${1:-}" = "--snapshot" ]; then SNAPSHOT=1; shift; fi
WWW="${1:-www}"
OUT="${2:-backups}"
PATHS=(content site/accounts site/config/.salt site/config/.license)

[ -d "$WWW/kirby" ] || { echo "Not a site root: $WWW" >&2; exit 1; }
mkdir -p "$OUT"

sha256() { if command -v sha256sum >/dev/null; then sha256sum "$@"; else shasum -a 256 "$@"; fi; }
stamp() { date -u +%Y%m%dT%H%M%SZ; }

STAMP="$(stamp)"
TMP="$(mktemp -d)"
NEW=""
trap 'rm -rf "$TMP" ${NEW:+"$NEW"}' EXIT

if [ "$SNAPSHOT" = 1 ]; then
  PREV=""
  for d in "$OUT"/website-*/; do [ -d "$d" ] && PREV="${d%/}"; done
  # Its own temporary name, so a deploy and a pull at once do not meet.
  NEW="$(mktemp -d "$OUT/.website-tmp-XXXXXX")"
  STATE="$NEW/state"
else
  STATE="$TMP/state"
fi

mkdir -p "$STATE"
for p in "${PATHS[@]}"; do
  if [ -e "$WWW/$p" ]; then
    dir="$(dirname "$p")"
    mkdir -p "$STATE/$dir"
    if [ "$SNAPSHOT" = 1 ]; then
      # -a keeps mtimes and modes. rsync writes a new file and never changes
      # one in place, so a linked file in an older snapshot stays as it was.
      # -c compares content: size and mtime alone would link a file rewritten
      # within the same second, or one whose mtime was set back (migrate.php).
      # GNU rsync (server, CI) links only a file with the same mode; macOS
      # openrsync also links a file whose mode alone changed.
      LINK=()
      # rsync reads a relative --link-dest from the target folder, so it gets an absolute one.
      [ -n "$PREV" ] && [ -d "$PREV/state/$dir" ] && LINK=(--link-dest="$(cd "$PREV/state/$dir" && pwd)")
      rsync -a -c --exclude '.DS_Store' --exclude '._*' ${LINK[@]+"${LINK[@]}"} "$WWW/$p" "$STATE/$dir/"
    else
      # -p keeps mtimes and modes: a restore must be identical, not just equal in content.
      cp -Rp "$WWW/$p" "$STATE/$p"
    fi
  fi
done

[ -d "$STATE/content" ] || { echo "No content in $WWW" >&2; exit 1; }

( cd "$STATE" && find . -type f ! -name '.DS_Store' ! -name '._*' | LC_ALL=C sort | while read -r f; do sha256 "$f"; done ) > "$TMP/MANIFEST.sha256"

# Same files with the same checksums, each of them a hard link into the newest
# snapshot (so also the same mtime and mode), and the same folders (Kirby reads
# a folder in content as a page): nothing changed.
dirs() { ( cd "$1" && find . -type d | LC_ALL=C sort ); }
if [ "$SNAPSHOT" = 1 ] && [ -n "$PREV" ] && cmp -s "$TMP/MANIFEST.sha256" "$PREV/MANIFEST.sha256" \
   && [ -z "$(find "$STATE" -type f -links 1 | head -1)" ] \
   && [ "$(dirs "$STATE")" = "$(dirs "$PREV/state")" ]; then
  echo "State unchanged since $(basename "$PREV"), no new snapshot" >&2
  echo "$PREV"
  exit 0
fi

{
  echo "created: $STAMP"
  echo "git: $(git rev-parse HEAD 2>/dev/null || echo none)"
  echo "kirby: $(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"] ?? "unknown";' "$WWW/kirby/composer.json" 2>/dev/null || echo unknown)"
  echo "php: $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
  echo "files: $(wc -l < "$TMP/MANIFEST.sha256" | tr -d ' ')"
} > "$TMP/META"

if [ "$SNAPSHOT" = 1 ]; then
  mv "$TMP/MANIFEST.sha256" "$TMP/META" "$NEW/"
  # The folder gets its final name only when it is complete. rename() fails on
  # an existing folder, where mv would move it inside: two snapshots in the
  # same second (deploy and pull) take the next free second.
  tries=0
  until php -r 'exit(@rename($argv[1], $argv[2]) ? 0 : 1);' "$NEW" "$OUT/website-$STAMP"; do
    tries=$((tries + 1))
    [ "$tries" -lt 5 ] || { echo "Cannot rename $NEW to $OUT/website-$STAMP" >&2; exit 1; }
    sleep 1
    STAMP="$(stamp)"
  done
  NEW=""
  echo "$OUT/website-$STAMP"
  exit 0
fi

ARCHIVE="$OUT/website-$STAMP.tar.gz"
# macOS tar would add AppleDouble "._*" entries; GNU tar on Linux would
# extract them as real files and the restore would fail its file count.
if tar --version 2>/dev/null | grep -q bsdtar; then
  COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata -C "$TMP" -czf "$ARCHIVE" state MANIFEST.sha256 META
else
  tar -C "$TMP" -czf "$ARCHIVE" state MANIFEST.sha256 META
fi
echo "$ARCHIVE"

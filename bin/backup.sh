#!/usr/bin/env bash
# Copies the state of a site into a backup: an archive, or a snapshot folder
# with --snapshot. make backup writes an archive. bin/deploy.sh and
# bin/pull.sh make a snapshot on the server.
#
# The state is what git, Composer and Kirby cannot make again: content/,
# site/accounts/, site/config/.salt and site/config/.license. env.php is
# host config: each host has its own, so it is not in a backup. media/,
# site/cache/ and site/sessions/ are generated: Kirby makes them again.
#
# Usage: bin/backup.sh [--snapshot] [state-root] [output-dir]
#   state-root  the local www/ (default), or shared/ on the server. Both
#               hold the state at the same paths.
#   output-dir  default backups/.
#
# The archive is <output-dir>/website-<UTC time>.tar.gz. It holds state/,
# MANIFEST.sha256 with the SHA-256 checksum of every file, and META with the
# time, the git commit, the Kirby and PHP versions and the file count.
#
# A snapshot is the folder <output-dir>/website-<UTC time>/ with the same
# content. A file with the same checksum, mtime and mode as in the newest
# snapshot becomes a hard link to it and takes no new space. When nothing in
# the state changed, the script makes no snapshot and prints the newest one.
#
# On the server, `php` in the ssh shell can be another PHP than the one of
# the site. bin/deploy.sh and bin/pull.sh set PHP to the PHP of the site.
set -euo pipefail

SNAPSHOT=0
while [ $# -gt 0 ]; do
  case "$1" in
    --snapshot) SNAPSHOT=1 ;;
    *) break ;;
  esac
  shift
done
WWW="${1:-www}"
OUT="${2:-backups}"
PHP="${PHP:-php}"
PATHS=(content site/accounts site/config/.salt site/config/.license)

[ -d "$WWW/content" ] || { echo "No content in $WWW" >&2; exit 1; }
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
  # A unique temporary name: a deploy and a pull that run at the same time
  # must not write into the same folder.
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
      # -c compares the content. Size and mtime alone would link a file that
      # was rewritten within the same second, or one whose mtime was set back.
      # GNU rsync (server, CI) links only a file with the same mode. macOS
      # openrsync also links a file whose mode alone changed.
      LINK=()
      # rsync resolves a relative --link-dest from the target folder, so the
      # path must be absolute.
      [ -n "$PREV" ] && [ -d "$PREV/state/$dir" ] && LINK=(--link-dest="$(cd "$PREV/state/$dir" && pwd)")
      rsync -a -c --exclude '.DS_Store' --exclude '._*' ${LINK[@]+"${LINK[@]}"} "$WWW/$p" "$STATE/$dir/"
    else
      # -p keeps mtimes and modes. A page without a `lastmod` field gets its
      # date in the sitemap from the mtime of its content file.
      cp -Rp "$WWW/$p" "$STATE/$p"
    fi
  fi
done

# A request can write to a SQLite database under content/ while rsync or
# cp copies it, and the copy is then broken. Each database that was
# copied anew is copied again inside a read transaction: while it lasts,
# SQLite lets no writer finish a change. The copy keeps mtime and mode, as
# rsync -a and cp -p do. A file linked to the newest snapshot is unchanged
# and stays. When the second copy fails, the first copy stays and the
# backup goes on.
find "$STATE/content" -type f -name '*.sqlite' -links 1 | while read -r copy; do
  "$PHP" -r '
    [, $src, $dst] = $argv;
    $db = new PDO("sqlite:$src", null, null, [PDO::ATTR_TIMEOUT => 5]);
    $db->beginTransaction();
    $db->query("SELECT 1 FROM sqlite_master LIMIT 1")->fetchAll();
    $ok = copy($src, "$dst.tmp") && touch("$dst.tmp", filemtime($src)) && chmod("$dst.tmp", fileperms($src) & 0777) && rename("$dst.tmp", $dst);
    $db->commit();
    exit($ok ? 0 : 1);
  ' "$WWW/${copy#"$STATE"/}" "$copy" || echo "Copied $copy without the read transaction" >&2
done

# Kirby keeps the failed logins of the last hour, with hashed IP addresses,
# in site/accounts/.logins. A snapshot does not need them.
rm -f "$STATE/site/accounts/.logins"

[ -d "$STATE/content" ] || { echo "No content in $WWW" >&2; exit 1; }

( cd "$STATE" && find . -type f ! -name '.DS_Store' ! -name '._*' | LC_ALL=C sort | while read -r f; do sha256 "$f"; done ) > "$TMP/MANIFEST.sha256"

# The state is unchanged when the manifest equals the newest one, every file
# is a hard link into the newest snapshot (so mtime and mode match too), and
# the folders are the same. Folders count because Kirby reads every folder in
# content/ as a page, an empty one too.
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
  kirby="$WWW/kirby/composer.json"
  [ -f "$kirby" ] || kirby="$(dirname "$WWW")/current/www/kirby/composer.json"
  echo "kirby: $("$PHP" -r 'echo json_decode(file_get_contents($argv[1]), true)["version"] ?? "unknown";' "$kirby" 2>/dev/null || echo unknown)"
  echo "php: $("$PHP" -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
  echo "files: $(wc -l < "$TMP/MANIFEST.sha256" | tr -d ' ')"
} > "$TMP/META"

if [ "$SNAPSHOT" = 1 ]; then
  mv "$TMP/MANIFEST.sha256" "$TMP/META" "$NEW/"
  # The folder gets its final name only when it is complete. PHP's rename()
  # fails when the target folder exists, and mv would move the folder into it.
  # When a deploy and a pull make a snapshot in the same second, the second
  # snapshot takes the next free second.
  tries=0
  until "$PHP" -r 'exit(@rename($argv[1], $argv[2]) ? 0 : 1);' "$NEW" "$OUT/website-$STAMP"; do
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
# macOS tar (bsdtar) adds AppleDouble "._*" entries for extended attributes.
# GNU tar on Linux extracts them as real files, and bin/restore.sh then finds
# more files than the manifest lists.
if tar --version 2>/dev/null | grep -q bsdtar; then
  COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata -C "$TMP" -czf "$ARCHIVE" state MANIFEST.sha256 META
else
  tar -C "$TMP" -czf "$ARCHIVE" state MANIFEST.sha256 META
fi
echo "$ARCHIVE"

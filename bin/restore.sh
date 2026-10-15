#!/usr/bin/env bash
# Restores a backup of bin/backup.sh, an archive or a snapshot folder, into a
# site root or into shared/ on the server. make restore, bin/pull.sh and
# bin/restore-test.sh run it.
# 1. Checks every file against MANIFEST.sha256. On a mismatch, nothing changes.
# 2. Copies the state from the backup into a temporary folder next to the
#    target, and puts each path in place with a rename.
# 3. Deletes the old state and the generated folders media/, site/cache/
#    and site/sessions/. Kirby makes the generated files again.
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
# Environment: PHP, the PHP command for rename(), default php. Any version
# does.
#
# Usage: bin/restore.sh <archive.tar.gz | snapshot-folder> [www-root | shared-folder]
set -euo pipefail

ARCHIVE="${1:?Usage: bin/restore.sh <archive.tar.gz | snapshot-folder> [www-root | shared-folder]}"
WWW="${2:-www}"
PHP="${PHP:-php}"
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

# The copy goes into a temporary folder next to the target first. On a live
# site a request can make content/ again at any moment (the visitor
# statistics, the likes, the change log), and cp into an existing folder
# would put the backup inside it. place() puts a path in place with
# rename(). rename() refuses a folder that is not empty: then the path in
# the way moves aside, and the rename runs again. Both folders lie in the
# target, so the renames stay on one file system.
NEW="$(mktemp -d "$WWW/.restore-new-XXXXXX")"
OLD="$(mktemp -d "$WWW/.restore-old-XXXXXX")"
for p in "${STATE_PATHS[@]}"; do
  if [ -e "$SRC/state/$p" ]; then
    mkdir -p "$NEW/$(dirname "$p")"
    cp -Rp "$SRC/state/$p" "$NEW/$p"
  fi
done
# A backup made before bin/backup.sh left out SQLite journals can hold one.
# Next to the copy of the database it would roll that copy back.
find "$NEW/content" -type f -name '*.sqlite-journal' -delete

place() {
  local tries=0
  until "$PHP" -r 'exit(@rename($argv[1], $argv[2]) ? 0 : 1);' "$1" "$2"; do
    tries=$((tries + 1))
    [ "$tries" -le 5 ] || { echo "Cannot put $2 in place. The backup is in $1, the old state in $OLD." >&2; exit 1; }
    if [ -e "$2" ] || [ -L "$2" ]; then mv "$2" "$OLD/$(basename "$2").$tries"; fi
  done
}
for p in "${STATE_PATHS[@]}"; do
  if [ -e "$NEW/$p" ]; then
    mkdir -p "$WWW/$(dirname "$p")"
    place "$NEW/$p" "$WWW/$p"
  elif [ -e "$WWW/$p" ]; then
    # A path that the backup does not have, such as .license, goes too.
    mv "$WWW/$p" "$OLD/$(basename "$p")"
  fi
done
rm -rf "$NEW" "$OLD"
for p in "${GENERATED[@]}"; do rm -rf "${WWW:?}/$p"; done

if [ "$TARGET" = shared ]; then
  for p in "${GENERATED[@]}"; do mkdir -p "$WWW/$p"; done
  [ ! -d "$WWW/site/accounts" ] || chmod 700 "$WWW/site/accounts"
  for f in .salt .license; do [ ! -e "$WWW/site/config/$f" ] || chmod 600 "$WWW/site/config/$f"; done
fi

echo "Restored $listed files from $ARCHIVE into $WWW ($TARGET)"
sed 's/^/  /' "$SRC/META"

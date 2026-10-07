#!/usr/bin/env bash
#
# Rechnet jedes Bild in content/ nach sRGB um, das ein anderes Farbprofil trägt.
#
#     bash scripts/to-srgb.sh           # umrechnen
#     bash scripts/to-srgb.sh --check   # nur auflisten, nichts ändern
#
# Warum es das gibt: Hugo liest beim Verkleinern kein Farbprofil. Die WebP-Dateien
# in resources/_gen tragen die Werte aus Display P3, aber kein Profil. Der Browser
# liest sie als sRGB, und das Foto wirkt blass. Hugo hat dafür keine Einstellung,
# der Fehler liegt in der Go-Bildbibliothek (gohugoio/hugo#8298).
#
# Ein Bild mit sRGB oder ohne Profil bleibt unverändert. Ein zweiter Lauf findet
# darum nichts mehr und dauert unter einer Sekunde.
#
# Die Umrechnung überschreibt die Quelldatei. Ist die Datei unverändert in Git,
# holt `git checkout` das Original zurück. Jede andere Datei (neu oder geändert)
# kopiert das Skript vorher nach .srgb-originals/, mit demselben Pfad.
#
# Qualität 90 hält die Dateigröße etwa gleich. EXIF bleibt erhalten.
#
# Setzt voraus: macOS (sips).

set -euo pipefail

SRGB_PROFILE="/System/Library/ColorSync/Profiles/sRGB Profile.icc"
SRGB_NAME="sRGB IEC61966-2.1"
BACKUP_DIR=".srgb-originals"

check=0
[ "${1:-}" = "--check" ] && check=1

# sips gibt pro Datei zuerst den absoluten Pfad aus, danach "  profile: <Name>".
# Ein Bild ohne Profil hat keine profile-Zeile und fällt heraus.
list_wide_gamut() {
    find content -type f \( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' \) -print0 |
        xargs -0 sips -g profile 2>/dev/null |
        awk -v srgb="$SRGB_NAME" '
            /^\// { file = $0; next }
            /^  profile: / { sub(/^  profile: /, ""); if ($0 != srgb) print file }
        '
}

in_git_unchanged() {
    git ls-files --error-unmatch -- "$1" >/dev/null 2>&1 &&
        git diff --quiet HEAD -- "$1"
}

count=0
backups=0
while IFS= read -r file; do
    rel="${file#"$PWD"/}"
    count=$((count + 1))
    if [ "$check" = 1 ]; then
        echo "$rel"
        continue
    fi
    if ! in_git_unchanged "$rel"; then
        mkdir -p "$BACKUP_DIR/$(dirname "$rel")"
        cp -p "$rel" "$BACKUP_DIR/$rel"
        backups=$((backups + 1))
    fi
    sips -m "$SRGB_PROFILE" -s formatOptions 90 "$rel" --out "$rel" >/dev/null
    echo "sRGB: $rel"
done < <(list_wide_gamut)

if [ "$check" = 1 ]; then
    echo "$count Bilder ohne sRGB."
elif [ "$count" -gt 0 ]; then
    echo "$count Bilder nach sRGB umgerechnet, $backups Originale in $BACKUP_DIR/."
fi

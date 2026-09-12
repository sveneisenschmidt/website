#!/usr/bin/env bash
#
# Findet Adressen, die auf dem Server antworten, die der Build aber nicht mehr
# erzeugt und die data/redirects.yaml nicht abdeckt.
#
#     bash scripts/check-urls.sh
#
# Warum es das gibt: der Deploy läuft ohne --delete. Was einmal hochgeladen wurde,
# bleibt liegen und antwortet weiter, auch wenn es aus dem Build verschwunden ist.
# Acht Monate lang ist so niemandem aufgefallen, dass fünfzehn Adressen existierten,
# die es nicht mehr geben sollte — mit Aufrufen darauf.
#
# Die Prüfung ist auch die Voraussetzung dafür, --delete scharf zu schalten. Erst
# wenn diese Liste leer ist, kostet das Aufräumen niemanden einen Link.
#
# Setzt voraus: hugo ist gelaufen (public/ existiert), und `ssh website` erreicht
# den Server.

set -uo pipefail

REMOTE="/www/htdocs/w00611ad/sven.eisenschmidt.website"
TMP="${TMPDIR:-/tmp}/check-urls.$$"
mkdir -p "$TMP"
trap 'rm -rf "$TMP"' EXIT

if [ ! -d public ]; then
    echo "public/ fehlt. Erst 'hugo' laufen lassen." >&2
    exit 2
fi

echo "Lese den Server…"
ssh website "cd $REMOTE && find . -name index.html -not -path './pagefind/*' | sed 's|^\./||; s|index\.html$||; s|^|/|'" \
    | sed 's|^//|/|' | sort -u > "$TMP/server.txt"

echo "Lese den Build…"
find public -name index.html -not -path 'public/pagefind/*' \
    | sed 's|^public||; s|index\.html$||' | sort -u > "$TMP/build.txt"

echo "Lese die Weiterleitungen…"
# Portabel halten: BSD-sed kennt \s nicht und würde ein führendes Leerzeichen
# stehen lassen, womit comm nichts mehr findet.
grep -E '^[[:space:]]*-?[[:space:]]*from:' data/redirects.yaml \
    | sed -E 's|.*from:[[:space:]]*||' \
    | tr -d '\r' \
    | sed -E 's|^[[:space:]]+||; s|[[:space:]]+$||' \
    | sort -u > "$TMP/mapped.txt"

orphans=$(comm -23 "$TMP/server.txt" "$TMP/build.txt" | comm -23 - "$TMP/mapped.txt")

echo
printf 'Server  %5s Adressen\n' "$(wc -l < "$TMP/server.txt" | tr -d ' ')"
printf 'Build   %5s\n'          "$(wc -l < "$TMP/build.txt"  | tr -d ' ')"
printf 'Mapping %5s\n'          "$(wc -l < "$TMP/mapped.txt" | tr -d ' ')"
echo

if [ -z "$orphans" ]; then
    echo "Keine Waisen. --delete wäre gefahrlos."
    exit 0
fi

echo "Weder erzeugt noch gemappt:"
echo "$orphans" | while read -r u; do
    [ -z "$u" ] && continue
    n=$(curl -s -H 'Origin: https://sven.eisenschmidt.website' \
        "https://pop.eisenschmidt.website/api/visits?pageId=https://sven.eisenschmidt.website$u" \
        | python3 -c 'import sys,json; print(json.load(sys.stdin).get("totalVisits",""))' 2>/dev/null)
    printf '  %6s  %s\n' "${n:-–}" "$u"
done

echo
echo "Die Zahl links sind die Aufrufe, die Pop auf der Adresse zählt."
echo "Jede Zeile gehört in data/redirects.yaml oder ist bewusst ein 404."
exit 1

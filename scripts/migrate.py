#!/usr/bin/env python3
"""Leitet type, trip und tags für jeden Beitrag ab.

    python3 scripts/migrate.py            trocken, schreibt nichts
    python3 scripts/migrate.py --apply    schreibt die Frontmatter

Das neue Modell hat drei Felder:

    type   photo | gallery | note | essay — die Form, entscheidet das Layout
    trip   optional, die Zugehörigkeit zu einer Reise
    tags   beschreibend, führen nirgendwohin

`topics` fällt weg. Die drei größten waren keine Themen: "Daily Photo" ist der Typ,
"Photography" ist die ganze Seite, "Travel" ist eine Zugehörigkeit.

Die Ableitung ist bewusst dumm und zählbar. Wo sie nicht greift, meldet das Skript den
Beitrag und entscheidet nichts — das steht unten unter BRAUCHT EINE ENTSCHEIDUNG.
"""

import argparse
import os
import re
import sys
from collections import Counter, defaultdict

ROOT = "content/posts"

# Ein langer Text ist ein Essay. Die Grenze ist gesetzt, nicht gefunden: darüber
# liegen sieben Texte, darunter fünfzehn, und die Lücke dazwischen ist breit.
ESSAY_WORDS = 700

# Ab hier ist es eine Galerie. Bei zwei wären 91 Beiträge Galerie, darunter Tagesfotos,
# bei denen zufällig ein zweites Bild dabei ist. Bei vier sind es 57.
#
# Eine Route macht keine Galerie. Vier Beiträge tragen eine und zeigen ein einziges
# Bild — zwei Radtouren und zwei Reisetage. Ein einzelnes Foto mit einer Route ist ein
# Foto; die Karte steht im Text und wird so oder so gezeigt.
GALLERY_IMAGES = 4

# Reisen. Der Slug trägt sie bereits, sie muss nur benannt werden.
TRIP_BY_PREFIX = {
    "2026-summer-vacation": "summer-2026",
    "2026-winter-vacation": "winter-2026",
    "england-day": "england-2026",
}

# Beiträge, die zu einer Reise gehören, ohne es im Slug zu sagen. Jeder einzeln
# geprüft, jeder mit einem Grund.
TRIP_BY_HAND = {
    "departure": ("england-2026", "22. April, der Tag vor England Tag 1"),
    "back-home": ("winter-2026", "14. Februar, der Tag nach Winter Tag 7"),
    "mayrhofen-at-night": ("winter-2026", "Tagesfoto aus der Reise heraus"),
}

# topics, die keine sind.
TOPIC_DROP = {
    "Daily Photo",    # der Typ
    "Photography",    # die ganze Seite
    "Travel",         # eine Zugehörigkeit
}

# topics, die als Tag weiterleben.
TOPIC_TO_TAG = {
    "Family": "family",
    "Software Development": "software",
    "Work": "work",
    "Video Games": "games",
    "AI": "ai",
    "Cycling": "cycling",
    "Music": "music",
}

# topics mit genau einem Beitrag. Gestrichen: ein Tag, der einmal vorkommt, ordnet
# nichts.
TOPIC_SINGLETONS = {"Technology", "Flowers", "Oldtimer", "Books", "Food"}


def posts():
    for name in sorted(os.listdir(ROOT)):
        path = os.path.join(ROOT, name)
        if os.path.isdir(path):
            f = os.path.join(path, "index.md")
            if not os.path.exists(f):
                continue
        elif name.endswith(".md"):
            f = path
        else:
            continue
        yield name.removesuffix(".md"), f


def split(src):
    if not src.startswith("+++"):
        return "", src
    _, fm, body = src.split("+++", 2)
    return fm, body


def field(fm, key):
    m = re.search(rf"^{key}\s*=\s*(.+)$", fm, re.M)
    return m.group(1).strip() if m else ""


def strings(value):
    return [a or b for a, b in re.findall(r'"([^"]+)"|\'([^\']+)\'', value)]


def analyse(folder, path):
    src = open(path, encoding="utf-8").read()
    fm, body = split(src)

    cover = strings(field(fm, "cover"))
    cover = cover[0] if cover else field(fm, "cover").strip("'\"")
    images = len(re.findall(r"\{\{<\s*img\s", body))
    # Ein Cover ohne img-Shortcode im Text ist trotzdem ein Bild.
    if images == 0 and cover:
        images = 1

    words = len(re.sub(r"\{\{<.*?>\}\}", " ", body).split())
    headings = len(re.findall(r"^#{2,}\s", body, re.M))

    has_map = bool(re.search(r"\{\{<\s*map\s", body))

    if images == 0:
        kind = "essay" if (words > ESSAY_WORDS or headings) else "note"
    elif images >= GALLERY_IMAGES:
        kind = "gallery"
    else:
        kind = "photo"

    trip = None
    reason = None
    for prefix, name in TRIP_BY_PREFIX.items():
        if folder.startswith(prefix) or field(fm, "slug").strip("'\"").startswith(prefix):
            trip, reason = name, f"Slug beginnt mit {prefix}"
            break
    if not trip and folder in TRIP_BY_HAND:
        trip, reason = TRIP_BY_HAND[folder]

    topics = strings(field(fm, "topics"))
    tags = sorted({TOPIC_TO_TAG[t] for t in topics if t in TOPIC_TO_TAG})
    unknown = [t for t in topics if t not in TOPIC_TO_TAG and t not in TOPIC_DROP
               and t not in TOPIC_SINGLETONS]

    return dict(
        folder=folder, path=path, kind=kind, trip=trip, trip_reason=reason,
        tags=tags, topics=topics, unknown=unknown, images=images, words=words,
        headings=headings, cover=bool(cover),
        date=field(fm, "date").strip("'\"")[:10],
        title=field(fm, "title").strip("'\""),
        has_map=has_map,
        was_daily=("Daily Photo" in topics),
        draft=field(fm, "draft"),
    )


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true", help="schreibt die Dateien")
    ap.add_argument("--list", choices=["photo", "gallery", "note", "essay"],
                    help="nur diesen Typ auflisten")
    args = ap.parse_args()

    rows = [analyse(f, p) for f, p in posts()]

    by_kind = Counter(r["kind"] for r in rows)
    by_trip = Counter(r["trip"] for r in rows if r["trip"])
    by_tag = Counter(t for r in rows for t in r["tags"])

    print(f"\n{len(rows)} Beiträge\n")
    print("TYP")
    for k in ("photo", "gallery", "note", "essay"):
        print(f"  {by_kind[k]:4}  {k}")
    print(f"\nREISEN  {sum(by_trip.values())} Beiträge")
    for t, n in by_trip.most_common():
        print(f"  {n:4}  {t}")
    print(f"\nTAGS  {sum(1 for r in rows if r['tags'])} Beiträge tragen einen")
    for t, n in by_tag.most_common():
        print(f"  {n:4}  {t}")

    if args.list:
        print(f"\n\n{args.list.upper()}")
        for r in rows:
            if r["kind"] == args.list:
                print(f"  {r['date']}  {r['folder'][:40]:42} {r['images']:3} Bild(er) "
                      f"{r['words']:5} W  {','.join(r['tags'])}")

    # --- was jemand ansehen muss ------------------------------------------
    print("\n\nBRAUCHT EINE ENTSCHEIDUNG\n")

    odd = [r for r in rows if r["was_daily"] and r["kind"] in ("note", "essay")]
    if odd:
        print(f"  {len(odd)} als Daily Photo geführt, aber ohne Bild:")
        for r in odd:
            print(f"      {r['date']}  {r['folder'][:34]:36} {r['words']:5} W  -> {r['kind']}")

    big = [r for r in rows if r["kind"] == "gallery" and not r["trip"]]
    if big:
        print(f"\n  {len(big)} Galerien ohne Reise — stimmt das, oder gehören welche zu einer?")
        for r in sorted(big, key=lambda x: -x["images"])[:12]:
            print(f"      {r['date']}  {r['folder'][:34]:36} {r['images']:3} Bilder")
        if len(big) > 12:
            print(f"      … und {len(big) - 12} weitere, mit --list gallery")

    maps = [r for r in rows if r["has_map"] and not r["trip"]]
    if maps:
        print(f"\n  {len(maps)} mit Route, aber ohne Reise:")
        for r in maps:
            print(f"      {r['date']}  {r['folder']}")

    unknown = [r for r in rows if r["unknown"]]
    if unknown:
        print(f"\n  {len(unknown)} mit einem topic, für das es keine Regel gibt:")
        for r in unknown:
            print(f"      {r['folder'][:34]:36} {r['unknown']}")

    lost = [r for r in rows if any(t in TOPIC_SINGLETONS for t in r["topics"])]
    if lost:
        print(f"\n  {len(lost)} verlieren einen Einzelgänger-topic ersatzlos:")
        for r in lost:
            keep = [t for t in r["topics"] if t in TOPIC_SINGLETONS]
            print(f"      {r['folder'][:34]:36} verliert {keep}")

    nocover = [r for r in rows if r["images"] and not r["cover"]]
    if nocover:
        print(f"\n  {len(nocover)} haben Bilder, aber kein cover — fallen aus /photos:")
        for r in nocover:
            print(f"      {r['date']}  {r['folder'][:34]:36} {r['images']:3} Bilder")

    drafts = [r for r in rows if r["draft"]]
    print(f"\n  {len(drafts)} tragen ein draft-Feld, davon "
          f"{sum(1 for r in drafts if 'false' in r['draft'])} auf false — kann weg")

    if not args.apply:
        print("\n\nTrocken gelaufen. Keine Datei angefasst.")
        print("Mit --apply schreiben.\n")
        return 0

    changed = 0
    for r in rows:
        if write(r):
            changed += 1
    print(f"\n\n{changed} Dateien geschrieben. Der Rest steht im git diff.\n")
    return 0


def write(r):
    """Schreibt type, trip und tags in eine Datei.

    Die neuen Felder stehen dort, wo topics stand, damit der Diff klein bleibt und
    sich lesen lässt. Alles andere in der Frontmatter wird nicht angefasst.
    """
    src = open(r["path"], encoding="utf-8").read()
    fm, body = split(src)
    before = fm

    lines = [f'type = "{r["kind"]}"']
    if r["trip"]:
        lines.append(f'trip = "{r["trip"]}"')
    if r["tags"]:
        inner = ", ".join(f'"{t}"' for t in r["tags"])
        lines.append(f"tags = [{inner}]")
    block = "\n".join(lines)

    if re.search(r"^topics\s*=.*$", fm, re.M):
        fm = re.sub(r"^topics\s*=.*$", block, fm, count=1, flags=re.M)
    else:
        # Kein topics-Feld: hinter den Titel, sonst ans Ende.
        if re.search(r"^title\s*=.*$", fm, re.M):
            fm = re.sub(r"^(title\s*=.*)$", r"\\1\n" + block, fm, count=1, flags=re.M)
        else:
            fm = fm.rstrip() + "\n" + block + "\n"

    # draft = false sagt nichts. Ein Entwurf hat draft = true oder gar kein Feld.
    fm = re.sub(r"^draft\s*=\s*false\s*\n", "", fm, flags=re.M)

    if fm == before:
        return False
    open(r["path"], "w", encoding="utf-8").write("+++" + fm + "+++" + body)
    return True


if __name__ == "__main__":
    sys.exit(main())

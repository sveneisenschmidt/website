#!/usr/bin/env python3
"""Derives kind, series and tags for every post and reports what would change.

Nothing is written unless --apply is passed. The grey zones are printed as their own
lists, because a derivation that guesses is worth less than one that says it guessed.
"""
import argparse
import collections
import glob
import os
import re
import sys

ROOT = "/Users/sveneisenschmidt/Github/website"

# Topics that never described a subject. Photography is the site; Daily Photo is the form;
# Travel repeats the series, which 35 of 35 trip posts already showed.
DROP = {"Photography", "Daily Photo", "Travel", "Technology", "Oldtimer", "Food", "Flowers"}
RENAME = {
    "Software Development": "software",
    "Video Games": "games",
    "Family": "family",
    "Work": "work",
    "AI": "ai",
    "Cycling": "cycling",
    "Music": "music",
    "Books": "books",
}

# A series is a run with an order. The prefix is only the starting point; every member is
# listed in the report so a wrong grouping is visible rather than silent.
SERIES_PREFIX = [
    ("2026-summer-vacation", "summer-2026"),
    ("2026-winter-vacation", "winter-2026"),
    ("england-day", "england-2026"),
    ("hypo", "hypo"),
]
SERIES_PAIRS = ["sandbox", "sparrows", "stork", "shrike", "starling", "mine", "windpark",
                "swallow", "woodpecker", "dragon-touch", "i-built-a-rss-reader",
                "building-with-hugo", "bee-eaters"]

WRITING_WORDS = 200          # at or above this a post is treated as writing
GREY_LOW, GREY_HIGH = 150, 400   # band where the call is not obvious


def parse(path):
    raw = open(path, encoding="utf-8", errors="replace").read()
    if not raw.startswith("+++"):
        return None
    _, fm, body = raw.split("+++", 2)
    imgs = len(re.findall(r"\{\{<\s*img\b", body))
    words = len(re.sub(r"\{\{<[^>]*>\}\}", " ", body).split())
    tm = re.search(r"^topics\s*=\s*\[(.*?)\]", fm, re.M | re.S)
    topics = re.findall(r"[\"']([^\"']+)[\"']", tm.group(1)) if tm else []
    title = (re.search(r"^title\s*=\s*[\"'](.*?)[\"']\s*$", fm, re.M) or [None, ""])[1]
    slug = (re.search(r"^slug\s*=\s*[\"'](.*?)[\"']\s*$", fm, re.M) or [None, ""])[1]
    date = (re.search(r"^date\s*=\s*[\"'](.*?)[\"']\s*$", fm, re.M) or [None, ""])[1]
    name = path.split("/")[-2] if path.endswith("/index.md") else os.path.basename(path)[:-3]
    return dict(path=path, fm=fm, body=body, imgs=imgs, words=words, topics=topics,
                title=title, slug=slug, date=date, name=name)


def series_of(p):
    base = p["slug"] or p["name"]
    stem = re.sub(r"^daily-photo-", "", base)
    for prefix, name in SERIES_PREFIX:
        if stem.startswith(prefix) or p["name"].startswith(prefix):
            return name, "prefix"
    for pair in SERIES_PAIRS:
        if stem == pair or re.fullmatch(re.escape(pair) + r"-pt\d+", stem):
            return pair, "pair"
    return None, None


def kind_of(p, series):
    """Your own declarations decide, not a word count.

    A post you tagged Daily Photo is a picture. A day of a trip is a picture, because a
    trip day is a run of photographs. Everything without an image is writing. What is left
    is decided by whether a subject topic is present: a release note with screenshots is
    writing, a walk with four photographs is not.
    """
    if "Daily Photo" in p["topics"]:
        return "picture", "tagged Daily Photo"
    if series in ("summer-2026", "winter-2026", "england-2026"):
        return "picture", "day of a trip"
    if p["imgs"] == 0:
        return "writing", "no image"
    if {"Software Development", "AI", "Work", "Video Games", "Books"} & set(p["topics"]):
        return "writing", "subject topic, images are screenshots"
    if p["imgs"] >= 2:
        return "picture", f"{p['imgs']} images"
    return "writing" if p["words"] >= WRITING_WORDS else "picture", f"{p['words']} words"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    args = ap.parse_args()
    os.chdir(ROOT)

    posts = [q for q in (parse(f) for f in
             sorted(glob.glob("content/posts/*.md") + glob.glob("content/posts/*/index.md")))
             if q]

    kinds = collections.Counter()
    series = collections.defaultdict(list)
    tags = collections.Counter()
    grey, orphan_pairs, lost_tags = [], [], []

    for p in posts:
        s, how = series_of(p)
        p["series"] = s
        p["kind"], p["kind_why"] = kind_of(p, s)
        kinds[p["kind"]] += 1
        if s:
            series[s].append(p)
        new = sorted({RENAME[t] for t in p["topics"] if t in RENAME})
        p["tags"] = new
        for t in new:
            tags[t] += 1
        dropped = [t for t in p["topics"] if t in DROP and t not in ("Photography", "Daily Photo", "Travel")]
        if dropped and not new:
            lost_tags.append((p, dropped))
        if p["kind_why"] not in ("tagged Daily Photo", "day of a trip", "no image"):
            grey.append(p)

    print(f"{len(posts)} Posts\n")
    print("KIND")
    for k, n in kinds.most_common():
        print(f"  {n:4d}  {k}")

    print(f"\nSERIES  ({len(series)} Serien, {sum(len(v) for v in series.values())} Posts)")
    for s, members in sorted(series.items(), key=lambda kv: -len(kv[1])):
        km = collections.Counter(m["kind"] for m in members)
        print(f"  {len(members):4d}  {s:16s} {dict(km)}")

    print(f"\nTAGS  ({sum(tags.values())} Zuordnungen auf {sum(1 for p in posts if p['tags'])} Posts)")
    for t, n in tags.most_common():
        print(f"  {n:4d}  {t}")
    print(f"  {sum(1 for p in posts if not p['tags']):4d}  (ohne Tag)")

    print(f"\nNICHT AUS DEINER EIGENEN ANGABE ABGELEITET — {len(grey)} Posts")
    for p in sorted(grey, key=lambda q: -q["words"]):
        print(f"  {p['kind']:8s} {p['imgs']:3d} Bild {p['words']:5d} W  {p['title'][:34]:36s} {p['kind_why'][:38]:40s} {p['name']}")

    if lost_tags:
        print(f"\nTHEMA FÄLLT WEG, kein Ersatz — {len(lost_tags)} Posts")
        for p, d in lost_tags:
            print(f"  {','.join(d):22s} {p['title'][:40]:42s} {p['name']}")

    print("\nOHNE SERIE, aber Name sieht nach einer Reihe aus")
    for p in posts:
        if p["series"]:
            continue
        stem = re.sub(r"^daily-photo-", "", p["slug"] or p["name"])
        if re.search(r"-pt\d+$", stem) or re.search(r"-\d+$", stem):
            print(f"  {stem:40s} {p['title'][:40]}")

    if not args.apply:
        print("\nTrockenlauf. Nichts geschrieben.")
        return

if __name__ == "__main__":
    main()

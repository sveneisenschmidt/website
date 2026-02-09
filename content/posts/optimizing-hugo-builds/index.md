+++
date = '2026-02-09T10:00:00'
title = "Optimizing Hugo Builds"
topics = ['Software Development', 'Photography']
emojis = ['🛠️']
draft = true
+++

My Hugo builds on GitHub Actions were taking over two minutes. The site has 273 processed images and growing. Every push meant waiting. Here's how I got the image processing step from ~103 seconds down to under 1 second.

<!--more-->

## The Problem

I keep original camera JPEGs in the Git repo, right next to my markdown files. Hugo resizes them on build using a custom shortcode:

```go-html-template
{{- $img := .Page.Resources.GetMatch (.Get "src") -}}
{{- $resized := $img.Fit "2048x2048" -}}
```

Locally this works fine because Hugo caches the processed images in `resources/_gen`. But on GitHub Actions, every run starts fresh. No cache, no history. Hugo processes all 273 images from scratch, every single time. That's where the 103 seconds went.

## The Fix

Three changes to `.github/workflows/deploy.yml`. One of them does most of the heavy lifting.

### 1. Cache Hugo's Image Processing

The key insight: Hugo stores its image cache in a directory controlled by the `HUGO_CACHEDIR` environment variable. On GitHub Actions, this defaults to somewhere in `/tmp` — but the exact path varies depending on your setup. Set it explicitly so you know what to cache.

```yaml
- name: Cache Hugo resources
  uses: actions/cache@v4
  with:
    path: /tmp/hugo_cache
    key: hugo-${{ runner.os }}-${{ hashFiles('content/**/*.jpg', 'content/**/*.jpeg', 'content/**/*.png', 'content/**/*.webp', 'content/**/*.JPG') }}
    restore-keys: |
      hugo-${{ runner.os }}-

- name: Build
  env:
    HUGO_CACHEDIR: /tmp/hugo_cache
  run: |
    hugo --minify
```

The cache key is a hash of all image files in `content/`. When you add or change an image, the key changes. The `restore-keys` fallback ensures the old cache is still restored, so Hugo only processes the new images and skips the rest.

This single change took the build from 103 seconds to under 1 second.

### 2. Shallow Clone

The repo is over 200MB because of all the images. A full clone with history is pointless for a static site build.

```yaml
- uses: actions/checkout@v4
  with:
    fetch-depth: 1
```

Saves 5–15 seconds depending on repo size. Only safe if you don't use `--enableGitInfo` or `.GitInfo` in your templates.

### 3. npm Cache

I use [Pagefind](https://pagefind.app/) for search, which runs via `npx`. Caching npm avoids re-downloading the binary on every run.

```yaml
- name: Setup Node
  uses: actions/setup-node@v4
  with:
    node-version: "20"
    cache: "npm"

- name: Install dependencies
  run: npm ci
```

Saves another 3–8 seconds.

## Results

| | Before | After |
|---|---|---|
| Hugo build | ~103s | <1s |
| Git checkout | ~15s | ~5s |
| npm install | ~8s | ~3s |

The first run after adding the cache is still slow — the cache needs to be built. Every subsequent run benefits from it. Since my workflow runs on a cron schedule every 30 minutes, the cache stays warm.

## The Gotcha

Finding the right cache path was harder than it should have been. Hugo's documentation mentions `:cacheDir` as a token in cache configuration, but doesn't make it obvious where that resolves to on different systems. I wasted several CI runs caching the wrong directory before adding a debug step to find out where Hugo actually puts its files.

The lesson: don't assume. Add a debug step, check what exists after the build, then configure your cache.

```yaml
- name: Debug cache paths
  run: |
    find /tmp/hugo_cache -type f 2>/dev/null | head -20 || echo "NOT FOUND"
    find resources/_gen -type f 2>/dev/null | head -20 || echo "NOT FOUND"
```

In my case, `HUGO_CACHEDIR` pointed to `/tmp/hugo_cache` and that's where all the processed images ended up. `resources/_gen` was empty on the CI runner. Setting `HUGO_CACHEDIR` explicitly in the build step removes any ambiguity.

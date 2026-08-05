+++
date = '2026-02-09T10:00:00'
title = "Optimizing Hugo Builds"
topics = ["Software Development"]
emojis = ["🛠️"]
draft = true
+++

I keep original camera JPEGs in my Git repo. A Sony RX100 VII and a Canon EOS 2000D, files between 4 and 8 MB each, sitting right next to the markdown. Hugo resizes all of them on every build. 273 images at last count. On GitHub Actions that meant over two minutes per deploy, most of it spent on image processing that produced the exact same output every time.

<!--more-->

The problem is simple: GitHub Actions runners are ephemeral. Hugo caches processed images locally, but on CI that cache is gone after every run. So Hugo starts from zero, every single time.

The fix is also simple, once you know where Hugo actually puts its cache. And that's where I wasted time. Hugo's docs mention `:cacheDir` as a token in the [cache configuration](https://gohugo.io/configuration/caches/), but they don't tell you where that resolves to on a GitHub Actions runner. I assumed `resources/_gen` first because that's where Hugo caches things locally. Pushed it, didn't work. Tried `/tmp/hugo_cache` next. Pushed again, still nothing saved. I went through four CI runs before I did what I should have done from the start: added a debug step to look at what actually exists after the build.

```yaml
- name: Debug
  run: |
    find /tmp/hugo_cache -type f 2>/dev/null | head -20 || echo "NOT FOUND"
    find resources/_gen -type f 2>/dev/null | head -20 || echo "NOT FOUND"
```

`/tmp/hugo_cache` had all the processed images. `resources/_gen` was empty. The missing piece was setting `HUGO_CACHEDIR` explicitly in the build step so Hugo uses a predictable path, and then caching that path with `actions/cache`.

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
  run: hugo --minify
```

The cache key hashes all image files. When you add a new photo, the key changes, but the `restore-keys` fallback still restores the old cache. Hugo then only processes the new image and skips the rest.

That single change took the Hugo build from 103 seconds to under 1 second. I also added `fetch-depth: 1` to the checkout step and npm caching for Pagefind, but those are minor compared to the image cache. The checkout still takes about 16 seconds because even a shallow clone has to download ~280MB of images. That's the bottleneck now, and the only way to fix it would be moving images out of the repo. I'm not going to do that. I like having everything in one place.

Total deploy time went from over two minutes to 38 seconds. Most of that is the checkout and the SSH deploy. The actual build is negligible now.

The lesson I took away from this is boring but worth repeating: don't assume where tools put their files. Check. Especially on CI where the environment is different from your machine. A five-line debug step would have saved me an hour of pushing broken configs.

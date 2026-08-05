+++
date = '2026-03-17T08:00:00'
draft = false
title = 'Building This Website with Hugo (Pt. 2)'
topics = ["Software Development"]
emojis = ["🛠️"]
+++

A few months of running this site in production and things changed. This documents what and why, and hopefully saves someone who is just starting out with Hugo some time. The first post covers the initial setup: [Building This Website with Hugo](/posts/building-with-hugo/).

<!--more-->

## Shortcodes

### img: EXIF and captions

I updated the [`img`](https://github.com/sveneisenschmidt/website/blob/main/layouts/shortcodes/img.html) shortcode and it now reads EXIF data directly from the JPEG. Hugo supports this natively with `.Exif`. Camera make and model, focal length, aperture, exposure time, ISO are all read from the file. I also trimmed the make prefix from the model string, since some cameras write `Sony Sony RX100 VII` otherwise. You're likely fine with using `.Exif` directly. 

```go-html-template
{{- with $img.Exif -}}
{{- $make := .Tags.Make -}}
{{- $model := .Tags.Model -}}
{{- if and $make $model (strings.HasPrefix $model $make) -}}
    {{- $model = strings.TrimPrefix $make $model | strings.TrimLeft " " -}}
{{- end -}}
{{- with .Tags.FocalLength }} · {{ lang.FormatNumber 0 . }}mm{{ end -}}
{{- with .Tags.FNumber }} · ƒ/{{ lang.FormatNumber 1 . }}{{ end -}}
{{- with .Tags.ExposureTime }} · {{ . }}s{{ end -}}
{{- with .Tags.ISOSpeedRatings }} · ISO {{ . }}{{ end -}}
{{- end -}}
```

I also added `caption` and `photographer` parameters. `caption` renders as a `<figcaption>`. `photographer` shows up in the EXIF line with a camera emoji, useful when Nadine takes the photo and I steal it for my blog but want to give credit. So nice of me.

### photos: cover images only

The [`photos`](https://github.com/sveneisenschmidt/website/blob/main/layouts/shortcodes/photos.html) shortcode originally looped through all images in all posts. With more posts that stopped making sense. It now shows only cover images, one per post. The shortcode reads the `cover` param from frontmatter and uses that image for the grid thumbnail. This allows me to give focus on my cover pictures I select consciously.

I also added placeholder `<span>` elements to fill incomplete grid rows, so the last row does not break the layout when the count is not divisible by two or three. This is a purely aesthetic change for desktop and larger screens.

### email and rawhtml

Two small additions that are so minor no one else will be using them: The [`email`](https://github.com/sveneisenschmidt/website/blob/main/layouts/shortcodes/email.html) shortcode renders a mailto link from the site config so I do not hardcode the address in markdown. [`rawhtml`](https://github.com/sveneisenschmidt/website/blob/main/layouts/shortcodes/rawhtml.html) passes its inner content through as-is. Hugo escapes raw HTML in markdown by default, including `<script>` tags. I needed it on the about page to embed the JavaScript block that fetches site statistics from the [Pop](https://github.com/sveneisenschmidt/pop) API.

## Deployment

### FTP to SSH

I upgraded my hosting package to get SSH access, so I switched from FTP to SSH. Obviously SSH is faster, more reliable, and does not require sending credentials in plaintext. The [`easingthemes/ssh-deploy`](https://github.com/sveneisenschmidt/website/blob/main/.github/workflows/deploy.yml) action handles it. SSH credentials go into repository secrets, same as the FTP ones previously.

### IndexNow

Search engines crawl on their own schedule and they seemed to never pick up anything from my website. [IndexNow](https://www.indexnow.org/) lets you notify them directly after a deploy instead of waiting. It does not work with Google. _Who wouldn't want to be found. /s_ The [workflow](https://github.com/sveneisenschmidt/website/blob/main/.github/workflows/deploy.yml) submits all URLs from the sitemap after every build. The API key lives as a text file in `static/` and the workflow reads it from the filename with `basename`.

```yaml
- name: Submit to IndexNow
  run: |
    INDEXNOW_KEY=$(basename static/*-*-*-*-*.txt .txt)
    URLS=$(curl -s https://sven.eisenschmidt.website/sitemap.xml | grep -oE 'https://[^<]+')
    URL_LIST=$(echo "$URLS" | jq -R -s -c 'split("\n") | map(select(length > 0))')
    curl -X POST "https://api.indexnow.org/indexnow" \
      -H "Content-Type: application/json" \
      -d "{\"host\":\"sven.eisenschmidt.website\",\"key\":\"$INDEXNOW_KEY\",\"urlList\":$URL_LIST}"
```

### Cron: every 30 minutes

The visit counter is baked into the HTML at build time, there is no JavaScript fetching it on the client. Without regular rebuilds the number goes stale. The cron runs every 30 minutes to keep it fresh. Running that frequently only makes sense because the build cache described in the build performance section below keeps the total deploy time around one and a half minutes.

## Analytics

I did not want much Javascript on my website (ideally none), especially no JavaScript tracker and third-party services for analytics. I pull the Apache access logs from the server and analyze them locally with [GoAccess](https://goaccess.io/). One command in the [Makefile](https://github.com/sveneisenschmidt/website/blob/main/Makefile) fetches the compressed logs over SSH, pipes them through `goaccess`, and opens the HTML report.

```makefile
logs:
	@ssh xxx 'zcat /www/htdocs/*/logs/access_log*.gz' > logs/access.log
	@goaccess logs/access.log -o logs/report.html \
	  --log-format='%h - - [%d:%t %^] "%r" %s %b "%R" "%u" "%^" "%^"' \
	  --date-format='%d/%b/%Y' \
	  --time-format='%H:%M:%S'
	@open logs/report.html
```

## Build performance

GitHub Actions runners are not persistent. Hugo caches processed images locally, but on CI that cache is gone after every run. With just over 200 original JPEGs between 4 and 8 MB each at the time of writing, that meant close to two minutes per deploy for just regenerating image sizes.

The fix requires two things. First, telling Hugo explicitly where to put its cache by setting `HUGO_CACHEDIR` in the build step and adding this to [`hugo.toml`](https://github.com/sveneisenschmidt/website/blob/main/hugo.toml):

```toml
[caches.images]
dir = ":cacheDir/images"
```

Without this, Hugo does not use the expected path and the cache misses every time. Second, using [`actions/cache`](https://github.com/sveneisenschmidt/website/blob/main/.github/workflows/deploy.yml) pointed at that same path.

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

Build time went from close to two minutes to under 1 second. Total deploy time is now about a minute and a half at the time of writing. Since the original measurement the image count has roughly tripled. Without the cache, deploy times would be well beyond five minutes by now and growing with every post. Build time stays flat regardless of how many images are in the repo. The bottleneck now is the shallow clone, close to 1GB of content in the repo. Moving images out would fix it. I am not going to do that.

+++
date = '2026-01-05T08:45:00'
draft = false
title = 'Building This Website with Hugo'
topics = ['Software Development']
+++

This website runs on [Hugo](https://gohugo.io/), a static site generator written in Go. I launched it in early January 2026, so if you're reading this when it's fresh, this is all just a few days old.

<!--more-->

## Why Hugo?

I wanted something fast and simple without a CMS or database. Hugo generates static HTML files that I upload via FTP to a shared host. I looked at [Jekyll](https://jekyllrb.com/) too but didn't like the approach and Ruby never clicked for me. Hugo also showed up more in searches, so I went with it. Lazy, but it worked out.

I can't remember the last time I built something this quickly and was this happy with it. I hope Hugo is never going away. It's a joy to use.

## Content Types

The site isn't just a blog. I wanted different sections for different things: long-form posts, a media log, a photo gallery, and search. Hugo handles all of this with markdown files in folders.

```
content/
├── posts/
│   ├── hello-world.md
│   └── death-stranding-2/
│       ├── index.md
│       └── IMG_0447.jpg
├── log/
│   ├── movies/
│   ├── games/
│   └── tv/
├── photos.md
├── search.md
└── about/
```

*Posts can be single files or folders with images, log entries are organized by type, standalone pages like photos and search are at the root.*

### Posts

Nothing custom here, just how Hugo works out of the box. Each post is either a single markdown file or a folder with an `index.md` and images. I use folders when I want to include photos. Drop them next to the markdown, reference them in a shortcode, and Hugo resizes and optimizes them on build.

### Log

I track movies, games, and TV shows in the media log. Each entry is a markdown file. `build.render = 'never'` prevents Hugo from creating individual pages. Entries only appear in lists.

The log has subfolders for movies, games, and TV shows. Each subfolder becomes its own list page. The main log page uses `.RegularPagesRecursive` to show all entries and `.Sections` to generate filter links. Adding new categories like books or music would just be another subfolder.

<details>
<summary>Log entry frontmatter</summary>

```toml
+++
title = 'Superman'
date = '2025-09-03'
type = 'movie'
rating = 7
link = 'https://www.imdb.com/title/tt5950044/'
build.render = 'never'
+++
```

*Each entry has a type, rating, and external link. Hugo skips rendering individual pages.*
</details>

<details>
<summary>Log list template</summary>

```go-html-template
{{ with .Sections }}
<p>Browse: {{ range $i, $s := . }}
  {{ if $i }}, {{ end }}
  <a href="{{ $s.RelPermalink }}">{{ $s.Title }}</a>
{{ end }}</p>
{{ end }}
```

*Generates filter links from subfolders automatically.*
</details>

### Photos

The `/photos` page shows all images from my posts. A shortcode loops over all posts, grabs their images, and generates square thumbnails with `.Fill "768x768 Center"`.

<details>
<summary>Photos shortcode</summary>

```go-html-template
{{ range where site.RegularPages "Section" "posts" }}
  {{ range .Resources.ByType "image" }}
    {{ $thumb := .Fill "768x768 Center" }}
    <a href="{{ $post.Permalink }}">
      <img src="{{ $thumb.RelPermalink }}" loading="lazy"/>
    </a>
  {{ end }}
{{ end }}
```

*Loops through all posts, grabs images, creates square thumbnails linking back to the post.*
</details>

### Search

I use [Pagefind](https://pagefind.app/) for search. It indexes the HTML at build time and loads the index on demand. I ripped out Pagefind's default UI and built a simple one: input field, Enter to search, results as links.

Pagefind has a big beta release coming up. The current version works but I had trouble with advanced glob patterns, and search result quality is inconsistent. Hoping the beta fixes some of this.

<details>
<summary>Pagefind search</summary>

```javascript
const pagefind = await import("/pagefind/pagefind.js");
await pagefind.init();
const results = await pagefind.search(query);

for (const result of results.results) {
    if (result.score < 0.5) continue;
    const data = await result.data();
    // render result
}
```

*Loads the search index on demand, filters low-score results.*
</details>

### Reactions

I added emoji reactions at the bottom of posts. They come from [pop](https://github.com/sveneisenschmidt/pop), a widget I built alongside this site. Self-hosted, PHP with SQLite, about 3KB on the frontend. Building both projects in parallel was fun, and seeing them come together at the end even more so.

<details>
<summary>Pop widget init</summary>

```html
<script src="https://pop.eisenschmidt.website/pop.min.js" defer></script>
<script defer>
    Pop.init({
        endpoint: "https://pop.eisenschmidt.website/api",
        pageId: "{{ .Permalink }}",
        el: "#pop",
        emojis: ["❤️", "👍", "💡"],
        renderReactions: true,
        trackVisits: true
    });
</script>
```

*Loads the widget, configures three emoji reactions, tracks visits per page.*
</details>

## Development

For the first time in years: no Docker. Not for local tooling, not for deployment. I was spending more time debugging Docker setups than writing actual code. node and hugo on the host, done. Feels good.

### Customizations

Hugo does a lot out of the box, but I had to dig into the docs for a few things. Here's what I ended up using:

**Content**
- **`build.render = 'never'`** - Log entries don't get their own pages. They only show up in lists.
- **Custom taxonomy** - I use `topics` instead of Hugo's default `tags` and `categories`. One taxonomy, simpler URLs.
- **Page bundles** - Posts with images are folders instead of single files. The markdown goes in `index.md`, images sit next to it as page resources.

**Templates**
- **Shortcodes** - Custom `img` and `photos` shortcodes that wrap Hugo's image processing and keep the markdown clean.
- **Custom meta tags** - Built my own `meta.html` partial for SEO. It pulls the description from frontmatter, falls back to summary, then site description. For Open Graph images, it looks for a `cover` param in the post and runs it through `.Fill "1200x630 Center webp q80"`.
- **Inline CSS and JS** - Instead of linking external files, I inline minified CSS and JS directly in the `<head>`. Hugo's `resources.Get` loads the asset, `minify` compresses it, `safeCSS`/`safeJS` outputs it inline.

**Config**
- **Syntax highlighting** - Disabled the default inline styles (`noClasses = false`) so I can style code blocks with CSS.

### Project Structure

Hugo puts templates in a `themes/` folder by default. I started that way but moved everything to the project root. I don't plan to switch themes, so the extra folder added nothing.

<details>
<summary>Before and after</summary>

```
# Before                    # After
├── layouts/                ├── layouts/
│   └── (empty)             │   ├── _default/
├── themes/                 │   ├── partials/
│   └── website/            │   ├── posts/
│       └── layouts/        │   └── shortcodes/
│           ├── _default/   └── (no themes folder)
│           ├── partials/
│           └── ...
```

*Removed the theme layer. All templates now sit directly in `layouts/`.*
</details>

### Image Handling

A custom `img` shortcode resizes images. Originals stay in the content folder, Hugo serves optimized versions.

<details>
<summary>Image shortcode</summary>

```go-html-template
{{- $img := .Page.Resources.GetMatch (.Get "src") -}}
{{- $resized := $img.Fit "1440x1440" -}}
<img src="{{ $resized.RelPermalink }}" class="hero">
```

*Finds the image by name, resizes to max 1440px, outputs an img tag.*
</details>

### HEIC to JPEG Conversion

My iPhone saves photos as HEIC, which Hugo doesn't support. A shell script finds them and converts to JPEG with `sips` (built into macOS, surprisingly useful). I commit images in original size, Hugo handles the rest.

<details>
<summary>HEIC conversion script</summary>

```bash
#!/bin/bash
convert_heic() {
    find content -type f \( -iname "*.heic" \) | while read f; do
        sips -s format jpeg -Z 1440 "$f" --out "${f%.*}.jpg" && rm "$f"
    done
}

convert_heic

if [ "$1" = "--watch" ]; then
    fswatch -0 content | while read -d "" event; do
        if [[ "$event" =~ \.[hH][eE][iI][cC]$ ]]; then
            convert_heic
        fi
    done
fi
```

*Finds HEIC files, converts to JPEG, deletes originals. Watch mode re-runs on new files.*
</details>

### Build and Deploy

A Makefile ties it together. HEIC converter runs in background during dev, RSS feed gets copied from posts to root (Hugo can't limit the home feed to one section), Pagefind indexes blog posts only, FTP upload with 10 parallel connections.

No CI/CD. `make publish` from my machine.

<details>
<summary>Makefile</summary>

```makefile
check-deps:
    @command -v hugo >/dev/null 2>&1 || { echo "hugo required"; exit 1; }
    @command -v sips >/dev/null 2>&1 || { echo "sips required"; exit 1; }
    @command -v fswatch >/dev/null 2>&1 || { echo "fswatch required"; exit 1; }
    @command -v npx >/dev/null 2>&1 || { echo "npx required"; exit 1; }
    @command -v lftp >/dev/null 2>&1 || { echo "lftp required"; exit 1; }

dev: check-deps
    @trap 'kill 0' EXIT; \
    ./scripts/convert-heic.sh --watch & \
    hugo server --buildDrafts

build:
    rm -rf public/*
    hugo --minify
    cp public/posts/index.xml public/index.xml
    npx pagefind --site public --glob 'posts/*/**/*.html'
    rm -f public/pagefind/pagefind-ui.*

publish: build
    git add -A
    git commit -m "Update site $(date +%Y-%m-%d\ %H:%M)"
    git push origin main
    lftp -e "mirror -R --delete --parallel=10 public/ ..." 
```

*Three commands: dev runs Hugo with HEIC watcher, build creates the site with Pagefind, publish commits and uploads via FTP.*
</details>

### Hosting

I've been using [all-inkl](https://all-inkl.com/) since 2005. Free domains, FTP access, done. The threshold to get something static running was basically zero.

First time in 10 years I'm touching Apache. My hoster runs it and I forgot how much goes into a `.htaccess`: compression, caching, content types. All the stuff CDNs usually handle for you. Speaking of which: no CDN. Feels like overhead for a static site. Also I'm terrified of DNS. Every time I touch nameservers something breaks.

Homepage is about 12KB (4KB gzipped).

<details>
<summary>Apache .htaccess</summary>

```apache
# Compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css 
      application/javascript application/json
</IfModule>

# Caching
<FilesMatch "\.(woff2|jpg|png|webp)$">
    Header set Cache-Control "max-age=31536000, immutable"
</FilesMatch>
```

*Enables gzip compression for text files, sets one-year cache headers for static assets.*
</details>

### Writing

Posts are markdown files with frontmatter. `make dev` to preview, drafts stay in git with `draft = true`.

<details>
<summary>Post frontmatter</summary>

```toml
+++
date = '2026-01-05'
draft = true
title = 'Building This Website with Hugo'
topics = ['software-development']
+++
```

*Title, date, topics, draft status. The `<!--more-->` marker in the body sets where the excerpt ends.*
</details>

## What's Missing

Publishing requires my machine. I could write drafts in the GitHub app and commit to the repo, but I still need my machine to deploy. The fix is GitHub Actions. FTP credentials in repository secrets, ftp upload in the workflow. Once deployment is on GitHub Actions, I'll open source the whole site.

Scheduled posts are another thing. Hugo [won't render future-dated content by default](https://gohugo.io/getting-started/usage/), not even in the dev server. Posts only appear after their date has passed and the site is rebuilt. I'd need a GitHub Action that runs on a schedule and triggers a build. Not hard, just haven't done it yet.

I also want to add books and music to the media log. 

The whole setup took a weekend. Most of that went into design decisions and CSS, not Hugo. I'd do it again.

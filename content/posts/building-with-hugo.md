+++
date = '2026-01-05T07:00:00'
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

I wanted different sections for different things: long-form posts, a media log, a photo gallery, and search. Hugo handles all of this with markdown files in folders.

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

Hugo's default content handling. Each post is either a single markdown file or a folder with an `index.md` and images. I use folders when I want to include photos. Drop them next to the markdown, reference them in a shortcode, and Hugo resizes and optimizes them on build.

### Log

I track movies, games, and TV shows in the media log. Each entry is a markdown file. `build.render = 'never'` prevents Hugo from creating individual pages. Entries only appear in lists.

The log has subfolders for movies, games, and TV shows. Each subfolder becomes its own list page. The main log page uses `.RegularPagesRecursive` to show all entries and `.Sections` to generate filter links. Adding new categories like books or music would just be another subfolder.

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

```go-html-template
{{ with .Sections }}
<p>Browse: {{ range $i, $s := . }}
  {{ if $i }}, {{ end }}
  <a href="{{ $s.RelPermalink }}">{{ $s.Title }}</a>
{{ end }}</p>
{{ end }}
```

*Generates filter links from subfolders automatically.*

### Photos

The `/photos` page shows all images from my posts. A shortcode loops over all posts, grabs their images, and generates square thumbnails with `.Fill "768x768 Center"`.

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

### Search

I use [Pagefind](https://pagefind.app/) for search. It indexes the HTML at build time and loads the index on demand. I ripped out Pagefind's default UI and built a simple one: input field, Enter to search, results as links.

I read Pagefind has a beta release coming up but could not find any specific information online. The current version works but I had trouble with advanced glob patterns, and search result quality is inconsistent (I used a score filter > 0.5for this). The beta might fix some of this.

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

### Reactions

I added emoji reactions at the bottom of posts. They come from [pop](https://github.com/sveneisenschmidt/pop), a cookie-less widget I built alongside this site. Self-hosted, PHP with SQLite, about 3KB on the frontend. Building both projects in parallel was fun, and seeing them come together at the end even more so.

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

## Development

For the first time in years: no Docker. Not for local tooling, not for deployment. I was spending more time debugging Docker setups than writing actual code. node and hugo on the host, done. Feels good.

### Customizations

A few things required digging into the docs:

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

*Removed the theme layer. All templates now sit directly in layouts/.*

### Image Handling

A custom `img` shortcode resizes images. Originals stay in the content folder, Hugo serves optimized versions.

```go-html-template
{{- $img := .Page.Resources.GetMatch (.Get "src") -}}
{{- $resized := $img.Fit "1440x1440" -}}
<img src="{{ $resized.RelPermalink }}" class="hero">
```

*Finds the image by name, resizes to max 1440px, outputs an img tag.*

### HEIC to JPEG Conversion

My iPhone saves photos as HEIC, which Hugo doesn't support. A shell script finds them and converts to JPEG with `sips` (built into macOS, surprisingly useful). I commit images in original size, Hugo handles the rest.

```bash
#!/bin/bash
convert_heic() {
    find content -type f \( -iname "*.heic" \) | while read f; do
        sips -s format jpeg "$f" --out "${f%.*}.jpg" && rm "$f"
    done
}

convert_heic
```

*Finds HEIC files, converts to JPEG, deletes originals.*

### Build and Deploy

A Makefile ties it together. HEIC converter runs in background during dev, RSS feed gets copied from posts to root (Hugo can't limit the home feed to one section), Pagefind indexes blog posts only, FTP upload with 10 parallel connections.

No CI/CD, just `make publish` from my machine.

```makefile
dev:
    hugo server --buildDrafts

build:
    hugo --minify
    npx pagefind --site public --glob 'posts/*/**/*.html'

publish: build
    lftp -e "mirror -R --delete --parallel=10 public/ ..."
```

*Three commands: dev runs Hugo, build creates the site with Pagefind, publish uploads via FTP.*

### Hosting

I've been using [all-inkl](https://all-inkl.com/) since 2005 for free domains and FTP access. The threshold to get something static running was basically zero.

First time in 10 years I'm touching Apache. My hoster runs it and I forgot how much goes into a `.htaccess`: compression, caching, content types. All the stuff CDNs usually handle for you. Speaking of which: no CDN. Feels like overhead for a static site. Also I'm terrified of DNS. Every time I touch nameservers something breaks.

Homepage is about 12KB (4KB gzipped).

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

### Writing

Posts are markdown files with frontmatter. `make dev` to preview, drafts stay in git with `draft = true`.

```toml
+++
date = '2026-01-05'
draft = true
title = 'Building This Website with Hugo'
topics = ['Software Development']
+++
```

*Title, date, topics, draft status. Drafts stay in git until ready.*

## What's Missing

Publishing requires my machine. I could write drafts in the GitHub app and commit to the repo, but I still need my machine to deploy. GitHub Actions with FTP credentials in repository secrets would fix that. Once deployment runs there, I'll open source the whole site.

Scheduled posts are another thing. Hugo [won't render future-dated content by default](https://gohugo.io/getting-started/usage/), not even in the dev server. Posts only appear after their date has passed and the site is rebuilt. I'd need a GitHub Action that runs on a schedule and triggers a build. Not hard, just haven't done it yet.

I also want to add books and music to the media log. 

The whole setup took a weekend. Most of that went into design decisions and CSS, not Hugo. I'd do it again.

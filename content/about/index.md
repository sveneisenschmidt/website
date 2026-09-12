+++
date = '2026-01-02T12:03:26+01:00'
showDate = false
draft = false
title = 'About'
label = 'Personal blog'
# Von Hand: wann die Seite zuletzt stimmte. Hugo liest das als .Lastmod.
lastmod = '2026-09-12'
build.list = 'never'
+++

![A picture of Sven Eisenschmidt](sven.jpg)
{class="inline"}

I'm Sven, and I live near Leipzig, Germany with my wife and two children.

I like gaming, cycling, and building things that connect other things. I dislike cryptocurrencies, AI slop, and my Reddit filter list is 1000+ words and subreddits long.

I work as Chief Technology & Product Officer at [roadsurfer](https://roadsurfer.com), where we help people explore the world in campervans. Before that, I did similar things at Delivery Hero, trivago, and a few startups.

Sometimes I build [open source tools](https://github.com/sveneisenschmidt) for fun.

You can find me on [GitHub](https://github.com/sveneisenschmidt), [LinkedIn](https://www.linkedin.com/in/sveneisenschmidt/), [Instagram](https://www.instagram.com/sveneisenschmidt/), or [Strava](https://www.strava.com/athletes/sveneisenschmidt).

This is a private, non-commercial website. No business activities, no ads, no affiliate links, no monetization. Don't bother.

### Things I Built

Software I built in my spare time, most of it small, self-hosted, and written for myself. Everything except Hypo is on [GitHub](https://github.com/sveneisenschmidt).

[Hypo](https://hypo.eisenschmidt.website/?source=website): A macOS app for importing and culling photos from a camera. It copies to a folder or to Apple Photos, reads every copy back before anything gets deleted from the card, and opens every RAW format your Mac can decode.

[reader](https://github.com/sveneisenschmidt/reader): A self-hosted RSS reader in PHP with SQLite. I gave up on RSS a long time ago because no reader matched what I wanted. This one does, and I use it several times a day.

[pop](https://github.com/sveneisenschmidt/pop): A cookie-less widget for emoji reactions and visit counts. Self-hosted, PHP with SQLite, about 3KB on the frontend. It runs the reactions under every post here.

[website](https://github.com/sveneisenschmidt/website): This site. Hugo, custom theme, vanilla CSS, no JavaScript frameworks. The whole setup took a weekend, and most of that went into design decisions rather than Hugo.

[claude-rc-manager](https://github.com/sveneisenschmidt/claude-rc-manager): A menu bar app that keeps pre-configured Claude Code sessions running in my project folders, so a session is already up when I have an idea and want to start it from my phone.

[cwt](https://github.com/sveneisenschmidt/cwt): Claude worktrees. A shell script that opens a Claude initiated git worktree session in the editor I actually want to work in.

[dragon-touch-mcp](https://github.com/sveneisenschmidt/dragon-touch-mcp): MCP and CLI remote control for a Dragon Touch wall tablet over ADB. Switch tabs, read device settings, take screenshots, and push a custom browser onto the device.

[n8n-openai-bridge](https://github.com/sveneisenschmidt/n8n-openai-bridge): Connects chat frontends to n8n workflows. It was used at roadsurfer by regular people until n8n shipped the same thing built in. I was happy about it.

[yay](https://github.com/sveneisenschmidt/yay): A gamification engine that no one used.

### Blogroll

[Jasper Tandy](https://jasper.tandy.is): Monthly recaps, music, interesting links, my favorite personal blog and the inspiration for this one.

### About this Website

Built with [Hugo](https://gohugo.io/), a static site generator. Visits counted by [Pop](https://github.com/sveneisenschmidt/pop), my own tiny feedback library. Custom theme, vanilla CSS, no JavaScript frameworks. Hosted on [All-Inkl](https://all-inkl.com/) in Germany.

Read more about how this site was built in the [building with Hugo](/2026-01-05-building-with-hugo/) post. The full source code is [available on GitHub](https://github.com/sveneisenschmidt/website).

{{< rawhtml >}}
<div id="site-stats"></div>
<script defer>
document.addEventListener("DOMContentLoaded", () => {
    const d = location.origin;
    const pl = (n, w) => `${n} ${w}${n === 1 ? "" : "s"}`;
    const apiBase = "https://pop.eisenschmidt.website/api/stats";

    fetch(`${apiBase}?pageIdFilter=${d}`).then(r => r.json()).then(data => {
        const p = data.pages;
        if (!p.length) return;
        const sum = (k) => p.reduce((s, x) => s + x[k], 0);
        const top = p.filter(x => x.pageId.includes("/posts/") || /\/\d{4}-\d{2}-\d{2}-/.test(x.pageId))
            .sort((a, b) => b.totalVisits - a.totalVisits).slice(0, 5)
            .map(x => `<li><a href="${x.pageId.replace(d, "")}">${x.pageId.replace(d, "")}</a> - ${pl(x.totalVisits, "visit")}</li>`).join("");
        document.getElementById("site-stats").innerHTML = `<h4>Statistics</h4>
            <p><strong>${sum("uniqueVisitors")}</strong> unique visitors and <strong>${sum("totalVisits")}</strong> total visits across <strong>${p.length}</strong> pages. Powered by <a href="https://github.com/sveneisenschmidt/pop">Pop</a>.</p>
            <p>Most visited posts:</p><ul>${top}</ul>`;
    }).catch(() => {});
});
</script>
{{< /rawhtml >}}

+++
date = '2026-01-02T12:03:26+01:00'
showDate = false
draft = false
title = 'About'
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

### About this Website

Built with [Hugo](https://gohugo.io/), a static site generator. Search powered by [Pagefind](https://pagefind.app/). Reactions on posts via [Pop](https://github.com/sveneisenschmidt/pop), my own tiny feedback library. Custom theme, vanilla CSS, no JavaScript frameworks. Hosted on [All-Inkl](https://all-inkl.com/) in Germany.

Read more about how this site was built in the [building with Hugo](/posts/building-with-hugo/) post. The full source code is [available on GitHub](https://github.com/sveneisenschmidt/website).

{{< rawhtml >}}
<div id="site-stats"></div>
<script defer>
document.addEventListener("DOMContentLoaded", () => {
    const d = location.origin;
    const pl = (n, w) => `${n} ${w}${n === 1 ? "" : "s"}`;
    fetch("https://pop.eisenschmidt.website/api/stats").then(r => r.json()).then(data => {
    const p = data.pages.filter(x => x.pageId.startsWith(d));
        if (!p.length) return;
        const sum = (k) => p.reduce((s, x) => s + x[k], 0);
        const top = p.filter(x => x.pageId.includes("/posts/"))
            .sort((a, b) => b.totalVisits - a.totalVisits).slice(0, 5)
            .map(x => `<li><a href="${x.pageId.replace(d, "")}">${x.pageId.replace(d, "")}</a> - ${pl(x.totalVisits, "visit")}, ${pl(x.totalReactions, "reaction")}</li>`).join("");
        document.getElementById("site-stats").innerHTML = `<h4>Statistics</h4>
            <p><strong>${sum("uniqueVisitors")}</strong> unique visitors, <strong>${sum("totalVisits")}</strong> total visits, <strong>${sum("totalReactions")}</strong> reactions across <strong>${p.length}</strong> pages. Powered by <a href="https://github.com/sveneisenschmidt/pop">Pop</a>.</p>
            <p>Most visited posts:</p><ul>${top}</ul>`;
    }).catch(() => {});
});
</script>
{{< /rawhtml >}}

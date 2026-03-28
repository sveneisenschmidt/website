+++
slug = 'daily-photo-packed'
date = '2026-03-28T17:40:00'
title = 'Packed'
topics = ['Daily Photo']
cover = 'IMG_3916.jpeg'
emojis = ['🧳']
+++

Today was packed, in the morning I went out to use the fantastic weather to get some good shots of birds again, the usual Sparrow and a first time shot of a Goldfinch.

<!--more-->

{{< img src="IMG_3916.jpeg" alt="Goldfinch perched on a bare branch against a deep blue sky" >}}

Before I headed out I tinkered with the [Dragon Touch](/posts/daily-photo-dragon-touch/). It's just a weird piece of hard- and software. I knew before buying that it was running Android and I might get more out of it. The stock software does not offer switching between tabs like calendar, chores, achievements based on a schedule or other conditions - so I was curious to find ways connecting to it, first idea was to find some kind of web interface, similar to what usually is there for network-enabled printers. But oh my, I found the ADB port was open and gave full access to the OS, the running apps including the Dragon Touch app, the apk source and stored secrets! [Claude](https://claude.com/) was helpful to find the initial access vector and doing a proof of concept where I can remotely switch the active tab, from calendar to chores and back.

I did not expect it to be completely open and it gives me second thoughts if I want a piece of hard- and software open like this running in my household, I might move it to a separate network.

I also researched the cloud backend API, but I am not sure how far I want to take this, getting my device or account blocked is not something I am looking for. Work in progress in [this pull request](https://github.com/sveneisenschmidt/dragon-touch-mcp/pull/2).

I [open sourced](https://github.com/sveneisenschmidt/dragon-touch-mcp) the tool I created and pushed it to [npm](https://www.npmjs.com/package/dragon-touch-mcp), it supports both mcp and cli remote controlling of the most basic functions: switch tabs, get device settings, get Dragon Touch app settings, take screenshots. It's fun to tell Claude, after adding the tool as a mcp plugin, to show the chores or take a screenshot.

This afternoon Nadine and I went to a local photographer's exhibition in the [Volkshaus Groitzsch](https://volkshaus-groitzsch.de). Five local photographers showed their works and gave workshops, [one is a friend of Nadine](https://www.wiesenkind-fotografie.de). I couldn't believe my eyes,  not a single photo of a bird. Nadine and I joked we might join them next year with our mediocre bird shots. That will show them.

So more pictures of this morning's birds, to forget about the horrible security implications of having the Dragon Touch running in our household.

{{< img src="IMG_3904.jpeg" alt="Sparrow perched on branches with blue ribbon ties against a pale sky" >}}

{{< img src="IMG_3907.jpeg" alt="Sparrow perched on bare branches in front of a brick wall" >}}

{{< img src="IMG_3918.jpeg" alt="Goldfinch perched on a bare branch against a deep blue sky" >}}

+++
date = '2026-01-08T19:00:00'
title = "So I built a RSS Reader"
topics = ["Software Development"]
+++

So I built an RSS reader. I wanted something simple for myself. Read my feeds, mark things as read, move on.
 
<!--more-->

{{< img src="reader.jpg" alt="Screenshot of Reader running as a web app" >}}

I called it [Reader](https://github.com/sveneisenschmidt/reader). Yes, very creative, I know.

I wrote it in PHP with SQLite. I have been finding a lot of enjoyment working in a more traditional stack (PHP over Node.js) and server-side rendering over SPAs. PHP as a language and Symfony as a framework did develop pretty well in the last years while I did not pay much attention.

Back to **Reader**. The interface has three columns. Feeds on the left, articles in the middle, reading pane on the right. I organize my subscriptions in folders and pull down to refresh. The latter has been fun to develop and it was a small challenge to get it working across devices and browsers.

In the articles list, a green dot shows me what is new. This is a different status to mark things as read. I wanted it specifically this way so I can skim through quickly what is new and still make marking things as read a conscious choice. I might get back to this later and add a timer or so for when an article is marked as read, for now it is when I choose to or do a click out to an article.

I added mandatory 2FA because I'm cautious with self-hosted and self-written stuff. I like how the interface for the account setup and onboarding turned out.

Setting it up on all-inkl.com, my shared hoster, was a pain. There is a fine line between building everything you can during the deployment process but then run migrations and cache warming on the host.

I haven't tested it with more than 20 feeds. It'll probably crash. But then I have something new to fix. (Jasper warned me abouth this, he is probably right.)

I built it for myself. The code is open source. Maybe it's useful for someone else too.

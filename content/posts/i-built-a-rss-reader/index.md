+++
date = '2026-01-08T19:00:00'
title = "So I built an RSS Reader"
topics = ["Software Development"]
emojis = ["📰"]
+++

So I built an RSS reader. I wanted something simple for myself. Read my feeds, mark things as read, move on.
 
<!--more-->
I called it [Reader](https://github.com/sveneisenschmidt/reader). Yes, very creative, I know.

I wrote it in PHP with SQLite. I've been enjoying working with a more traditional stack (PHP over Node.js) and server-side rendering over SPAs. PHP as a language and Symfony as a framework have developed pretty well over the last few years while I wasn't paying attention.

{{< img src="reader.jpg" alt="Screenshot of Reader running as a web app" >}}

Back to **Reader**. The interface has three columns. Feeds on the left, articles in the middle, reading pane on the right. I organize my subscriptions in folders and pull down to refresh. The latter has been fun to develop and it was a small challenge to get it working across devices and browsers.

In the articles list, a green dot shows me what is new. This is a different status to mark things as read. I wanted it this way so I can skim through quickly what is new and still make marking things as read a conscious choice. I might get back to this later and add a timer or so for when articles are marked as read, for now it's when I choose to or click out to an article.

I added mandatory 2FA because I'm cautious with self-hosted and self-written stuff. I like how the interface for the account setup and onboarding turned out, this is where you set up 2FA initially. I added [screenshots](https://github.com/sveneisenschmidt/reader?tab=readme-ov-file#setup) to the official GitHub repository for the setup routine too.

Setting it up on all-inkl.com, my shared hoster, was a pain. There is a fine line between building everything you can during the deployment process but then run migrations and cache warming on the host. So I was trying to get away with as much as posible shifting to the GitHub action that builds everything.

I spent half of today annoyed by all-inkl.com not offering proper crontab support but only http-based crons, a super simple version of webhooks. I thought I was smarter than them and spawned a worker with *nohup* during deployment just to see it get killed after 10 minutes silently by some kind of janitor job on the host. Eventually I gave in and implemented webhooks. I like how it turned out though, it inspired me to show a "connection" status on the [profile page](https://github.com/sveneisenschmidt/reader?tab=readme-ov-file#profile) for background jobs. This way the feeds get refreshed in the background while I am not looking.

I haven't tested it with more than 20 feeds. It'll probably crash. But then I have something new to fix. (Jasper warned me about this, he's probably right.)

I built it for myself. The code is open source. Maybe it's useful for someone else too.

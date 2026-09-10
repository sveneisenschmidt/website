+++
date = '2026-09-10T16:30:00'
draft = false
title = "Worktrees"
topics = ["Software Development"]
emojis = ["🌳"]
+++

On my trip in April I remember asking Jasper who in the world uses worktrees, what is that supposed to be as a concept, and why is it suddenly pushed at me from every direction.

<!--more-->

Fast forward to this week, and I have used nothing but worktrees for seven days straight. With a good setup the parallelisation I get out of them is hard to argue with.

The challenge is that I like working in an editor that does not run in the terminal, and worktrees keep creating new folders. The clean solution would be a good editor with a TUI built into Claude Code, and Claude Code Desktop already has that.

As an alternative I wrote [cwt](https://github.com/sveneisenschmidt/cwt), claude worktrees, a shell script that starts the editor of my choice with a Claude initiated worktree session. The idea behind it is that Claude keeps full control over the worktree with the right permissions, and still sits embedded in Zed without me spawning it in Zed. It opens automatically the way I want it.

```
$ cwt "work on issue #237"
```

Remote control is always on through my [Claude RC Manager](/posts/claude-rc-manager/), so I can keep working on the same session in Zed from my phone.

{{< img src="cwt-zed.png" alt="Zed with a worktree open and Claude Code running in the right panel" >}}

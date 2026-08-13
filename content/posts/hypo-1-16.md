+++
slug = 'hypo-1-16'
date = '2026-08-13T13:00:00'
title = "Hypo 1.16"
topics = ["Photography", "Software Development"]
emojis = ["🛠️"]
draft = true
+++

Back from vacation, after using the previous [Hypo release (1.15)](https://hypo.eisenschmidt.website/?source=website#releases) on a few thousand photos in July, there were a few things I wanted to do better. 

<!--more-->

Whilst populating the grid up front with previews, EXIF, AF and other metadata costs time, it is a conscious choice for Hypo to avoid importing whole sessions of raw files, and that should not mean it feels slow, but after the latest releases it felt slower. I get that what I am competing against is tools like Lightroom which run on an imported set and feel snappy. I do not think populating a grid and offering high-resolution previews must be slow and I remember from early tests with PTP-connected cameras it being (or feeling) faster. 

Good news: it feels snappy again with release 1.16 after changing how requests to the camera via PTP are batched and resolved, requests for multiple images are batched together but resolved asynchronously as each image becomes ready. This allows Hypo to utilize the connection capacity while the camera returns data at its own pace; the outcome is a lot more organic population of the grid. 

There are smaller fixes around using LRU caches that keep memory consumption in check but make navigation between pictures a lot snappier.

[Grab Hypo 1.16 here](https://hypo.eisenschmidt.website/?source=website#releases).

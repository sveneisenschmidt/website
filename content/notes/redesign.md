+++
date = '2026-09-12T09:00:00'
title = "Redesign"
type = "note"
draft = false
+++

I rebuilt the website. A lot of what used to be here is gone and every address has changed. If you've got a bookmark or a feed reader pointed at this site, both still work.

<!--more-->

Every address moved. A post that lived at `/posts/pipes/` is now at `/2026-09-10-pipes/`, so the date is part of the link. Redirects keep every old one working and I checked them against the live feed before shipping, so your feed reader won't notice. The only thing really gone is the log this site used to have, sorry, those posts are gone forever.

Topics and tags are gone. A general archive took their place: every post by year and month, day and title, on one page. Five separate pages filter it by type, so it works without JavaScript. Switch the filter to photos and the rows turn into a grid of thumbnails. It's the page I use now when I go looking for something I wrote. The old topic list never worked because you needed to guess the topic first.

Search and the reactions went with them, and so did the navigation. You'll find a simple back link on every post. I decided against a header that follows you down the page for now, so you do not need to see my name all the time when reading. Maybe I do that with the post title instead. That's not a bad idea!

Blogroll and projects moved into the about page.

Everything is one of four things now: a photo, a day of a trip, a note, an essay (don't challenge me on that, I don't write essays but long texts, the word just fits the expectation I have towards this site). The sorting is a bit quirky, a post appears among the photos because it has a lead image, not because I tagged it. A trip is a property a post can have rather than a type of its own, so a photo taken on a trip is still a photo. I trust readers, they're smart and will figure it out.

Under every photo there's a strip with the camera data. Camera, lens, focal length, aperture, exposure, each a small label with its value underneath. The strip is dark against the photo and picks up its bottom corners, so the photo and the data are one block. There's something about it that made me very happy when I went through our [summer vacation photos](/2026-07-29-2026-summer-vacation-19/) with it. The caption is in there, and so is the photographer. Nadine takes quite a few of the photos I put here and her name belongs next to the camera that took them. The strip is flexible and works with and without caption, photographer and gear information.

The front page is now two columns, a photo on the left and what I've been writing on the right. I sat Nadine in front of it to get feedback. It wasn't as obvious as it had been in my head, so the small labels came in: featured photo, previously featured, timeline. I wanted to get there without them and I haven't found the way yet.

The map broke and it took me a while to see why, the map images were loading fine, they just had API KEY REQUIRED written across them. CARTO stopped handing out their map images without an account, so it runs on plain OpenStreetMap images now. Brighter than I'd like, but it's nobody's key and nobody's quota.

It's time to push it out now, it won't get better and there's plenty of time ahead for further tweaks.

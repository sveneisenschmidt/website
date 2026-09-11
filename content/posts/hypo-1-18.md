+++
slug = 'hypo-1-18'
date = '2026-09-10T21:00:00'
title = "Hypo 1.18"
topics = ["Photography", "Software Development"]
emojis = ["🛠️"]
draft = true
+++

Hypo 1.18 is [out](https://hypo.eisenschmidt.website/?source=website#releases). After months of adding features I wanted to get the fundamentals right, so I went through the whole app like a professional user would, and like someone who dumps a card at the end of every shoot. It is the biggest release so far.

<!--more-->

Hypo copied a file and, as long as the write didn't throw an internal error, offered to delete the original. A failing card can return an incomplete file without an error, so a truncated copy counted as imported. Now every copy gets read back and compared with the card before anything is deleted. That roughly doubles the copy time, and there's no setting to turn it off. Three smaller bugs in the same area: cameras reuse file names, so the second card's IMG_0001.CR3 counted as imported because the first card's was already in the folder, Hypo now checks the file, not the name. The JPEG of a RAW+JPEG pair was deleted from the card but never imported. And on the built-in SD slot of a MacBook Pro there is no Trash, the delete dialog now says so.

Culling is one keystroke per photo now, select and move on. Home, End, Page Up and Page Down move the grid, the filters have shortcuts. Zoom works like in Lightroom: a photo opens fitted to the window, double-click jumps to your last zoom level where you clicked, 1x is real pixels, and the level stays from photo to photo. I didn't copy Lightroom's reject flag. A check means import, no check means reject, you check the keepers, Select All, delete the rest. A third state would only separate not looked at yet from rejected, and you look at every photo anyway.

Hypo used to open ten RAW formats and ignored the rest. It now opens every RAW format your macOS can decode, hundreds of cameras, and the list grows when Apple adds one. Formats without a vendor-specific reader get no autofocus overlay, everything else works.

Before an import starts you see the size of the selection and the free space at the destination, for folders and Apple Photos. A running import can be stopped, failed photos retried, and the folder or album shows its full path and is remembered.

Smaller things. Every full RAW decode left a copy of the file behind in a temp folder, well over a hundred gigabytes on my Mac, filed under System Data where you can't see it. The image cache setting was applied twice, so Hypo held twice the memory you allowed. Exposure adjustment, histogram and previews from a USB camera no longer block the UI, the settings panel looks the same on every tab, the histogram shows a placeholder while it computes, and the thumbnail strip keeps up during an import.

[Grab Hypo 1.18 here](https://hypo.eisenschmidt.website/?source=website#releases).

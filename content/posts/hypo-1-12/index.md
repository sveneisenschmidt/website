+++
slug = 'hypo-1-12'
date = '2026-06-28T21:00:00'
title = "Hypo 1.12"
topics = ['Software Development', 'Photography']
emojis = ['📷']
draft = false
+++

Hypo 1.12 is [out](https://hypo.eisenschmidt.website/downloads/Hypo-1.12.0.dmg), under the hood I focused on a modularized app architecture for plug-and-play support of new camera bodies and RAW formats to make my future life easier, a slightly refined UI and UX, a reworked autofocus and subject visualisation. The latter I think is pretty cool and unique, I haven't seen that in other photo culling apps.

<!--more-->

{{< img src="screenshot-preview.png" alt="Hypo preview of a perched kestrel with the blue eye-detection box, zoomed in" caption="Preview" >}}

Apple's ImageIO already decodes the RAW, the gap I closed is mostly around the MakerNotes - the internal vendor-specific information that tells you all about the camera, the lens, settings and per-frame outcomes like the autofocus points, the subject and eye detection. Each manufacturer writes them its own way, different by model! None of it is read by the RAW interface provided by Apple. With the new architecture I was able to bring five new bodies, and I collected 2.7 GB of RAWs to test against, to work out how each one stores its information so Hypo can read it. There are well over a thousand bodies out there, so there is a long way to go.

- New cameras and formats: Panasonic RW2, Leica DNG and RWL, Olympus ORF, Pentax K-3 III, Ricoh GR III / GR IIIx. Overall improved stability for Canon, Fujifilm, Sony and Nikon.
- Region-based autofocus overlay with framed boxes and colour-coded badges, white for focus, blue for subject, red for eye (Super cool and proud of it! Check out the Hypo website to see what it looks like).
- Subject and eye detection across Sony, Nikon, Fujifilm, Canon.
- Focus settings shown in the EXIF and info overlay, because settings != per-frame outcomes.
- Redesigned welcome screen with a tips carousel because there's quite a bit to customize.
- Drag-to-install DMG, because we all love this.

[Direct download](https://hypo.eisenschmidt.website/downloads/Hypo-1.12.0.dmg) or read the [release notes](https://hypo.eisenschmidt.website/#releases).

---

{{< img src="screenshot-welcome.png" alt="Hypo welcome screen with a rotating greeting and a tip" caption="Welcome" >}}

{{< img src="screenshot-grid.png" alt="Hypo grid view with a bird of prey in flight and the blue subject-tracking box" caption="Grid" >}}

{{< img src="screenshot-import.png" alt="Hypo import dialog confirming photos from a Canon EOS R5 to Apple Photos" caption="Import" >}}

{{< img src="screenshot-performance.png" alt="Hypo performance settings with RAW decode, preview size and cache options" caption="Settings" >}}



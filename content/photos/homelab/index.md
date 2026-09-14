+++
date = '2026-09-14T20:30:00'
title = "Homelab"
type = "photo"
cover = "1C4A8177.jpeg"
+++

After work I redid my homelab, from scratch this time. The old setup had me cornered with local certificates, mDNS and HTTPS, so the Pi 5 got a fresh Raspberry Pi OS, boots from the NVMe now, and runs [Dokploy](https://dokploy.com) with every service as a container inside.

<!--more-->

{{< img src="1C4A8177.jpeg" alt="Green meadow with a group of tall poplars under a blue summer sky" caption="From the walk with Aika today" >}}

Dokploy also has an MCP server, which is handy for quick debugging and spinning up a service. Yet I use Ansible to build the whole machine from an empty SD card, the secrets stay encrypted in the repo. I had forgotten that Ansible saves the encryption key to the macOS keychain, I don't even know what all the secrets are this way. The first service I configured was [ntfy](https://ntfy.sh), my push notifications no longer pass through someone else's server. I used them mostly for build notifications from GitHub. None of it faces the internet, no port on the router is open, the Pi talks to my phone and my Macs through [Tailscale](https://tailscale.com) only. It took me a while to make peace with a VPN that is always on. I remember Wireguard being very demanding on my iPhone's battery.

The GitHub Actions for this website and for Hypo (except the Xcode stuff) run on the Pi now too, I was running low on free minutes since I picked up developing Hypo and writing daily posts, so a deploy of this post passes through my Mac Mini and the Pi at home. [Uptime Kuma](https://uptime.kuma.pet) watches every service and pings me when one dies (I can't get over the stupid name of this service, it put me off using it in the past), [Dozzle](https://dozzle.dev) shows me container logs better than Dokploy does, [diun](https://github.com/crazy-max/diun) tells me when an image has an update, and [changedetection.io](https://changedetection.io) watches RSS feeds for changes and pushes me the link, I had wanted to try that one for a while. A dashboard ties it together, a tiny bit of a terminal look, with the stats of the Pi and the Synology on it. The Synology handed out its numbers over SNMP which I didn't know about too - cool stuff. Claude Code built all of it with me over the afternoon, my part was mostly saying no to things.

{{< img src="dashboard.png" alt="Homelab dashboard, black page with orange labels, services, links and stats for the Pi and the Synology" >}}

Tilian was at sports in the evening, so I bridged the time in the car, remote on the homelab with Claude Code, and dinner on the steering wheel.

{{< img src="1C4A8180.jpeg" alt="Takeaway bowl balanced on a car steering wheel" >}}

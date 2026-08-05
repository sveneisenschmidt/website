+++
date = '2026-07-03T21:00:00'
draft = true
title = "Testing Hypo on macOS Versions I Don't Run"
topics = ["Software Development"]
emojis = ["🛠️"]
+++

I recently lowered [Hypo](https://hypo.eisenschmidt.website/)'s deployment target from macOS 26 (Tahoe) down to macOS 15 (Sequoia), to reach users who have not upgraded yet. The change compiled, every unit test passed, and nobody had ever seen the app running on macOS 15. That is the trouble with deployment target changes: what actually differs between the two systems is visual, window chrome, toolbar materials, sidebar rendering, spacing, and SwiftUI resolves all of that per OS at runtime. My PR convention requires screenshots for UI changes, and a screenshot taken on my dev machine only proves the app on macOS 26.

I had no macOS 15 hardware, and downgrading a Mac is impractical, Apple Silicon Macs often cannot go back below the OS they shipped with. What remained was a virtual machine on the same laptop. This post documents the tooling I built for that: Tart VMs, one bash script, a handful of make targets, and a few gotchas that cost real time.

<!--more-->

## Sighting, not CI

I call the use case "sighting": a human looks at the running app on the other OS and judges chrome and layout. It is deliberately not CI and not automated UI testing. Unit tests keep running on the host, XCUITests keep running on real hardware. The VM exists so a person can see the app on an OS they do not run.

## Why Tart

[Tart](https://tart.run/) by Cirrus Labs (`brew install cirruslabs/cli/tart`) wraps Apple's Virtualization.framework in a CLI: `tart clone`, `tart run`, `tart ip`, `tart stop`, `tart delete`. Three things made it fit:

- Cirrus Labs publishes ready-made base images on ghcr.io, `ghcr.io/cirruslabs/macos-sequoia-base:latest` and `ghcr.io/cirruslabs/macos-tahoe-base:latest`. They boot straight into an auto-logged-in session, user `admin`, password `admin`, with the Screen Recording permission already granted to that user. That last grant is what later lets `screencapture` work over ssh without any GUI interaction to approve a permission dialog.
- The first image pull downloads roughly 40 to 50 GB. After that, creating a VM is a local clone.
- Everything is scriptable from a shell, which means the whole flow can live in a Makefile and be driven by a coding agent.

The constraints: Apple Silicon only, Virtualization.framework runs at most two macOS guests at once, each VM costs about 50 GB of disk, and there is no USB passthrough, which has a design consequence I get to below. For macOS versions without a prebuilt image, `tart create --from-ipsw=/path/to/Restore.ipsw` builds a VM from a restore image; my script keeps that as a fallback path.

## The architecture

All logic lives in one bash script (`scripts/vm.sh`) with subcommands (`setup`, `up`, `run`, `shot`, `stop`, `stop-all`, `destroy`), parameterized by an environment variable, `VM=sequoia` or `VM=tahoe`. The Makefile targets in front are one-liners that set `VM` and call the script. Adding a third macOS version means adding one line to a case statement.

```
host                                     guest (Tart VM, e.g. hypo-sequoia)
─────                                    ──────────────────────────────────
xcodebuild Debug ─┐
test fixtures ────┤ stage
                  ▼
build/vm-shared/ ──(virtiofs, --dir)──►  /Volumes/My Shared Files/hypo
                  ▲                            │ copy app to $HOME
build/vm-shots/ ◄─┘◄── screencapture ──────────┘ exec binary with env var

control channel: ssh (sshpass, password auth)
display: VNC server in the guest, viewed via open "vnc://<guest-ip>"
```

*The host builds, the guest only executes. One shared directory carries traffic in both directions.*

The guest has no Xcode, no repo checkout, no toolchain. A single shared directory (`build/vm-shared`, mounted via `tart run --dir="hypo:build/vm-shared"`, appearing in the guest as `/Volumes/My Shared Files/hypo`) carries the app bundle and test data in and the screenshots out. Tart's directory sharing uses virtiofs, which causes two of the failures further down.

The guest boots headless. `tart run --vnc --no-graphics` starts a VNC server inside the guest and opens no window on the host. Viewing is a separate, explicit step: `open "vnc://$(tart ip hypo-sequoia)"` opens macOS Screen Sharing on the guest display. The `--no-graphics` flag is required next to `--vnc`; without it, tart itself opens the vnc:// URL in Screen Sharing on every boot, which is wrong for a flow that is usually driven by scripts and only sometimes watched by a human.

Command execution in the guest goes over ssh, authenticated with `sshpass -p admin` because the base images use password login.

## Test data without hardware

Hypo's normal data source is an SD card in a reader, which a VM cannot see, no USB passthrough. The tooling does not solve this in the VM layer; the app already had the seam. Hypo's Debug build reads an environment variable, `HYPO_UI_TEST_VOLUME_PATH`, and treats that directory as a mounted camera volume, the same seam that drives the XCUITest suite on the host. Two properties make it work in the guest: the Debug build is unsandboxed (separate entitlements file), so it may read a path outside its container, including the virtiofs mount, and the repo carries its UI-test fixtures as committed binary files, real RAW files from Canon, Sony, Nikon, Fujifilm, Olympus, plus JPEGs. The staging step copies exactly these image files into the shared directory, and the app in the guest shows a volume named `fixtures` with real photos in the grid.

The generalizable point: cross-version sighting starts inside the app. Anything the app normally gets from hardware needs a debug-only injection seam, an env var, a launch flag, a mock source, and the VM tooling then only has to deliver files.

## The Makefile surface

```make
VM ?= sequoia

vm-setup:                  # clone the base image; idempotent, first run downloads ~40-50 GB
	@VM=$(VM) ./scripts/vm.sh setup
vm-run:                    # build Debug, stage app + fixtures, boot, launch in the guest
	@VM=$(VM) ./scripts/vm.sh run
vm-sequoia:                # boot only + open the VNC viewer (no build, no deploy)
	@VM=sequoia ./scripts/vm.sh up
vm-tahoe:
	@VM=tahoe ./scripts/vm.sh up
vm-shot:                   # screenshot taken inside the guest -> build/vm-shots/
	@VM=$(VM) ./scripts/vm.sh shot
vm-stop:
	@VM=$(VM) ./scripts/vm.sh stop
vm-stop-all:
	@./scripts/vm.sh stop-all
vm-destroy:                # delete the VM, free ~50 GB
	@VM=$(VM) ./scripts/vm.sh destroy
```

Two distinct entry points, matching two usage modes. `make vm-run` is the full pipeline for sighting a current build; it ends without opening a viewer because its usual consumer is a coding agent that follows up with `make vm-shot`. `make vm-sequoia` and `make vm-tahoe` boot the VM and open Screen Sharing, for a human who just wants to watch or poke around. Both mount the shared directory, so a screenshot works after either boot path.

One host-side detail the script does not cover: the Xcode project is generated with XcodeGen, and `vm.sh run` calls `xcodebuild` directly, so `xcodegen generate` has to run before it. In my repo this is a standing build rule rather than part of the VM script.

## "Up" is three different states

The script waits for three readiness levels in sequence, and each one exists because the previous one is not enough:

1. **The VM has an IP.** `tart ip` polls until the DHCP lease appears. This happens well before the guest finishes booting; an ssh attempt at this point fails with "No route to host".
2. **sshd accepts connections.** The script probes port 22 with `nc -z -w 2` in a loop. Once this passes, shell commands work.
3. **The Aqua session exists.** sshd comes up before console auto-login completes. A GUI app launched over ssh in that window starts as a process without a WindowServer connection: it stays alive, opens no window, and is invisible to System Events, which makes the failure mode confusing, the process list says the app runs, the screen says nothing. The script waits for the `Dock` process as the signal that the login session is up:

```bash
vm_ssh 'i=0; until pgrep -x Dock >/dev/null; do i=$((i+1)); [ "$i" -ge 60 ] && exit 1; sleep 2; done'
```

*The Dock only exists once the Aqua session is up.*

The launch itself packs three decisions into one ssh line:

```bash
vm_ssh "rm -rf \$HOME/Hypo.app && cp -R '$GUEST_SHARED/Hypo.app' \$HOME/ \
  && (HYPO_UI_TEST_VOLUME_PATH='$GUEST_SHARED/fixtures' \
      nohup \$HOME/Hypo.app/Contents/MacOS/Hypo >/tmp/hypo.log 2>&1 </dev/null &)"
```

- The app is copied out of the shared mount into `$HOME` before launching: it runs faster from local disk and avoids executing off virtiofs.
- The binary is exec'd directly (`Hypo.app/Contents/MacOS/Hypo`) instead of `open Hypo.app`, because `open` hands the launch to LaunchServices and the environment variable does not survive that handoff. Without the env var there is no fake volume and the app starts empty.
- Only the launch is backgrounded, inside a subshell. With a bare trailing `&` the whole `&&` chain would be backgrounded, ssh would tear down immediately, and the SIGHUP could kill the multi-gigabyte `cp` mid-copy. The `nohup ... </dev/null &` inside parentheses detaches only the app; the `rm` and `cp` run synchronously first. Stdout and stderr go to `/tmp/hypo.log` in the guest, which is the first place to look when the window does not appear.

Restaging has its own rule: never rebuild `build/vm-shared` under a running VM. The guest's virtiofs mount pins the original directory inode, so `rm -rf` plus recreate leaves the guest looking at an empty, stale directory while the host sees fresh files. `vm.sh run` therefore stops a running VM before staging; every run boots fresh against a fresh share. The practical consequence is that a `vm-run` always includes a reboot, which costs about a minute and is intentional.

## Screenshots are taken inside the guest

When `tart run` is started from a non-GUI context, an agent's shell, a CI job, an ssh session on the host, there is no host window at all, so host-side window capture has nothing to grab. The screenshot has to happen inside the guest:

```bash
vm_ssh "osascript -e 'tell application \"System Events\" to if exists process \"Hypo\" then set frontmost of process \"Hypo\" to true' >/dev/null 2>&1 || true; sleep 1; \
  screencapture -x /tmp/vm-shot.png \
  && cat /tmp/vm-shot.png > '$GUEST_SHARED/vm-shot.png'"
```

*Bring the app to the front, capture, push the PNG through the share.*

Then the host moves the PNG from the share into `build/vm-shots/<vm>-<timestamp>.png`. Three details in that one command:

- Bringing the app to the front uses System Events, not `open -a Hypo`. Because the app was exec'd as a bare binary, LaunchServices never associated that process with the bundle; `open -a` would conclude the app is not running and start a second instance, one without the env var and therefore without photos.
- The file travels through the share via `cat source > dest` instead of `cp`. `screencapture` attaches extended attributes to the PNG, virtiofs rejects xattr writes, and `cp` fails with "Permission denied". `cat` writes only the bytes.
- This works without any permission dialog because the cirruslabs images pre-grant Screen Recording to the admin user.

The resulting PNG is the deliverable of the whole pipeline. It goes into the PR description as evidence that the app renders correctly on the other macOS version, next to the dev-host screenshot. A coding agent can also read the PNG to verify the run before reporting anything: the pass criterion is a photo grid with fixture thumbnails, and an empty window or a "no source" state counts as failure regardless of exit codes.

## Gotchas that cost real time

Each of these produced a misleading symptom before I found the cause:

- **Symptom:** ssh fails with "Too many authentication failures" although the password is correct. **Cause:** an ssh-agent loaded with keys; ssh offers every key first and the guest's connection limit trips before password auth is attempted. **Fix:** force password-only auth (`-o PubkeyAuthentication=no -o PreferredAuthentications=password`).
- **Symptom:** right after boot, sshd rejects the correct password a few times, then accepts it. **Cause:** opendirectoryd is still warming up while sshd already answers. **Fix:** retry the ssh command up to five times with short sleeps instead of failing on the first attempt.
- **Symptom:** the guest display stays at "looks like 1024x768" although a resolution was set. **Cause:** `tart set --display` interprets the size in points by default and maps it to a scaled HiDPI mode the guest ignores. **Fix:** give the framebuffer size in pixels explicitly, `tart set "$NAME" --display 1920x1200px`.
- **Symptom:** after restaging, the guest sees an empty shared folder while the host sees the files. **Cause:** virtiofs pinned the deleted directory's inode. **Fix:** stop the VM before restaging.
- **Symptom:** on every boot a Screen Sharing window pops up uninvited. **Cause:** `--vnc` without `--no-graphics`; tart opens the URL itself. **Fix:** always pair them, keep viewing an explicit command.
- **Symptom:** the app process runs in the guest, no window ever appears. **Cause:** launched after sshd came up but before the Aqua session existed. **Fix:** wait for the Dock process before launching GUI apps over ssh.

One more for the road: match VM names in `tart list` with word boundaries (`grep -E "(^|[[:space:]])$NAME([[:space:]]|\$)"`), because the column layout of `tart list` varies between tart versions and a substring match lets `hypo-sequoia-old` shadow `hypo-sequoia`.

## The daily loop

After the one-time setup (`brew install cirruslabs/cli/tart sshpass`, then `make vm-setup VM=sequoia` with its big download), the working loop is three commands:

```bash
make vm-run VM=sequoia               # build Debug, stage, boot, launch: a few minutes
open "vnc://$(tart ip hypo-sequoia)" # watch it live; guest login admin/admin
make vm-shot VM=sequoia              # PNG into build/vm-shots/ for the PR
```

A sequencing detail that generalizes: when I introduced the tooling, I validated it by running the unchanged, current app in a Tahoe VM, the same OS as my dev host, before the deployment-target change landed. With the app held constant, any failure in that first run had to be a tooling bug. The OS-version comparison came afterwards, on top of a chain that was already proven. That ordering kept two unknowns, new tooling and new OS behaviour, from being debugged at the same time.

Disk is the main price, about 50 GB per macOS version, plus the one-time image download. The recurring time cost is small; a `vm-run` is a Debug build plus roughly a minute of boot. The framework's two-guest limit has not been a practical constraint for comparing two OS versions side by side.

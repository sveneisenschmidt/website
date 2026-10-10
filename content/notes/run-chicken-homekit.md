+++
date = '2026-10-10T14:45:00'
title = "Run-Chicken in HomeKit"
type = "note"
+++

Our [coop](/2026-01-05-chicken/) has a Run-Chicken door that opens in the morning and closes in the evening. We control it with the manufacturer's app over Bluetooth, which reaches about one meter, so remote control meant standing right next to the door and connecting manually, which often took up to a minute. In the evening, when one of the chickens missed the schedule and was still outside, that didn't work reliably.

The door is in Apple Home now, and this is how I got it there, in case anyone have the same door and comes here from a web search.

<!--more-->

You need three things: Home Assistant, the community integration [ha-run-chicken](https://github.com/bkanuka/ha-run-chicken), and an ESP32 board near the coop running [ESPHome](https://esphome.io/) as a Bluetooth proxy. The proxy is on your Wi-Fi and bridges the Bluetooth connection to the door. My Home Assistant installation runs in Docker (via Dokploy) on a Raspberry Pi in my homelab, and the coop is out of range of the Pi's own Bluetooth.

For the proxy I use an M5Stack Atom Lite (got it for ~20€), a small ESP32 board, with my own ESPHome configuration. It's not much:

```yaml
esphome:
  name: run-chicken-proxy

esp32:
  board: m5stack-atom
  framework:
    type: esp-idf

wifi:
  ssid: !secret wifi_ssid
  password: !secret wifi_password

logger:

api:
  encryption:
    key: !secret api_key

ota:
  - platform: esphome
    password: !secret ota_password

esp32_ble_tracker:
  scan_parameters:
    active: true

bluetooth_proxy:
  active: true
```

The proxy has to run in active mode (`bluetooth_proxy: active: true`), because opening and closing the door requires a Bluetooth connection, a passive proxy only receives advertisements. The four `!secret` values go into a `secrets.yaml` next to the configuration. It's the syntax from Ansible, if your are OK with it you can use plaintext secrets. Shouldn't be an issue as you bake it into the firmware of the ESP32.

I flashed the first firmware over USB, subsequent updates can now go over Wi-Fi once it's connected. If you'd rather not write a configuration, ESPHome has a [browser installer](https://esphome.io/projects/?type=bluetooth) for a ready-made proxy firmware.

The Bluetooth chip in the Run-Chicken door just isn't good, so I had to put the proxy very close to the door (< 1m): only at one to two meters without a wall the signal was there, at three meters through a wall there was none.

For all of this you need a Home Assistant installation. In Home Assistant, add the proxy with the ESPHome integration, using its address and its API encryption key, the one from the yaml file of the firmware.

Copy the folder `custom_components/run_chicken` from the ha-run-chicken repository into the config directory of Home Assistant and restart. Then add the Run-Chicken integration for your door. Ours is a T-80. After you set up the proxy, it can take a few minutes until the door shows up.

Add the HomeKit Bridge - which is there by default in any Home Assistant isntallation, include the door, then pair it with the Home app on your iPhone. If Home Assistant runs in Docker, start the container with `network_mode: host`, otherwise the bridge doesn't show up in the Home app.

The door reports open and closed but no position, and without a position Apple Home shows it as a window covering. I've chosen garage door as the device class instead.

Now Nadine and I see the status of the door in Apple Home, and when a chicken isn't in the coop yet, we can open and close the door remotely and check with the camera in the coop. I might move the automation from the Run-Chicken app to Apple Home next.

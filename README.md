# DockTail for Unraid

Run [DockTail](https://docktail.org) on Unraid: expose Docker containers as native
Tailscale Services from `docktail.*` container labels — HTTP, HTTPS, TCP,
TLS-terminated TCP and Funnel — without giving each app its own Tailscale device.

DockTail runs as a service on the Unraid host, next to the official Tailscale
plugin's `tailscaled`, and is managed from **Settings → Network Services → DockTail**.

## Credits

DockTail itself is written by **[marvinvr](https://github.com/marvinvr)** —
[marvinvr/docktail](https://github.com/marvinvr/docktail). This repository is only
the Unraid packaging around it: it pins that project as a submodule, builds its
binary unmodified, and adds the webGUI pages and service script Unraid needs. All
credit for DockTail's design and implementation belongs upstream.

## Requirements

- Unraid 7.0 or newer.
- The official **Tailscale** plugin, running.
- A **tagged** Unraid node. `tailscaled` refuses to host a Tailscale Service from an
  untagged node, and the Tailscale plugin has no setting for advertised tags:

  ```
  tailscale up --advertise-tags=tag:server --reset
  ```

  `--reset` briefly drops the Tailscale connection. The **DockTail** tab checks this for you.
- A Tailscale OAuth client (scope `all`) or an API key, so DockTail can create and
  tag Service definitions in the Control Plane.

## Install

Community Apps → **Apps** → search for *DockTail*, or **Plugins → Install Plugin**:

```
https://raw.githubusercontent.com/vcolombo/docktail-unraid/main/plugin/docktail.plg
```

## Use

1. **Settings** — enter the OAuth credentials, set *Enable DockTail* to *Yes*, Apply.
2. **DockTail** — confirm every preflight check passes.
3. **Labels** — build the label string for a container, then paste it into
   *Docker → container → Advanced View → Extra Parameters → Apply*. Unraid's container
   editor has no label field, so Extra Parameters is the only route.

Example: expose a container listening on port 80 as `svc:unraid-test`:

```
--label docktail.service.enable=true --label docktail.service.name=unraid-test --label docktail.service.port=80
```

## Tabs

| Tab | What it does |
|---|---|
| DockTail | Opening view: service state with Start/Stop/Restart, environment preflight (Tailscale, `tailscaled`, Docker, node tags, credentials, Funnel), and labelled containers joined against what `tailscaled` advertises. |
| Settings | Credentials, tags, reconcile interval, log level. Credentials are stored separately in a `0600` file that is excluded from Unraid Connect's flash backup. |
| Labels | Generates the `--label` string. It only produces text — it never edits container templates and never recreates containers. |

## Layout on disk

| Thing | Path |
|---|---|
| Settings | `/boot/config/plugins/docktail/docktail.cfg` |
| Credentials (`0600`) | `/boot/config/plugins/docktail/credentials.cfg` |
| Service script | `/usr/local/etc/rc.d/rc.docktail` |
| Binary | `/usr/local/emhttp/plugins/docktail/bin/docktail` |
| Log | `/var/log/docktail.log` |

The service is bound to the Docker lifecycle through the `docker_started` and
`stopping_docker` events, so DockTail withdraws its Services before Docker stops and
re-advertises them once Docker is back.

## Development

```sh
./bump.sh 1.7.9   # repin the DockTail submodule and the version stamp
./build.sh        # stage src/ (binary + VERSION)
./build.sh --package   # additionally build a local .txz (GNU tar required)
```

Releases are cut by creating a GitHub Release whose **tag and name are both**
`YYYY.MM.DD`. For a second release the same day, append a **zero-padded**
counter: `.01`, `.02`, … `.10`. Mark it as a pre-release to publish only the
`-preview` channel.

The padding is load-bearing. Unraid compares third-party plugin versions with
**`strcmp`**, not `version_compare` — see `strcmp($latest,$version) > 0` in
`dynamix.plugin.manager/include/ShowPlugins.php` and the same in
`scripts/plugincheck`. So `2026.09.03.10` is *string-older* than
`2026.09.03.9` and the update is never offered, even though it is numerically
newer. Unpadded counters work only up to `.9`. Letters are also out, because
they break Slackware package versioning.

## Documentation

- [DockTail upstream source (marvinvr/docktail)](https://github.com/marvinvr/docktail)
- [DockTail documentation](https://docktail.org)
- [Unraid installation](https://docktail.org/#installation)

## License

AGPL-3.0, matching the DockTail binary this plugin ships. DockTail is
copyright its upstream author, [marvinvr](https://github.com/marvinvr); the
Unraid packaging in this repository is copyright
[vcolombo](https://github.com/vcolombo). Both are AGPL-3.0.

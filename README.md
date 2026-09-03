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

`docktail.funnel.*` labels additionally require **Allow Funnel** in the Tailscale
plugin's own settings. While that is off, the Tailscale plugin strips Funnel entries
out of the serve config it shares with DockTail, silently removing the Funnels
DockTail created. The DockTail tab checks this, but only once a container asks for a
Funnel.

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

Every key in `docktail.cfg` and `credentials.cfg` maps 1:1 onto the DockTail
environment variable of the same name, except `ENABLE_DOCKTAIL`, which is
plugin-local and only gates the service script. Empty values are not exported, so
DockTail's own defaults apply. Both files survive plugin removal, following the
Unraid convention that plugin settings persist.

The service is bound to the Docker lifecycle through the `docker_started` and
`stopping_docker` events, so DockTail withdraws its Services before Docker stops and
re-advertises them once Docker is back.

## Development

```sh
./bump.sh 1.7.9   # repin the DockTail submodule and the version stamp
./build.sh        # stage src/ (binary + VERSION)
./build.sh --package   # additionally build a local .txz (GNU tar required)
```

`.github/workflows/upstream.yml` watches `marvinvr/docktail` daily and opens a
PR when a newer **stable** tag appears (pre-release lines such as
`2.0.0-cloud.16` are ignored). It repins the submodule and `docktailVersion`
together and proves the new pin cross-compiles, but merging is deliberately
manual: the plugin models DockTail's label keys, its `serve status --json`
shape and its shutdown budget by hand, so one release should mean one tested
pairing. Run it on demand from the Actions tab.

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

- [DockTail documentation](https://docktail.org) — labels, protocols, Tailscale admin setup
- [DockTail upstream source (marvinvr/docktail)](https://github.com/marvinvr/docktail)

Unraid-specific setup is documented here, in this README and in the plugin's own help
text: every field on all three tabs is clickable and explains itself. docktail.org
covers DockTail itself and says nothing about this plugin.

## License

AGPL-3.0, matching the DockTail binary this plugin ships. DockTail is
copyright its upstream author, [marvinvr](https://github.com/marvinvr); the
Unraid packaging in this repository is copyright
[vcolombo](https://github.com/vcolombo). Both are AGPL-3.0.

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
2. **DockTail** — review the environment preflight checks. These do not prove that a
   particular application is reachable.
3. **Labels** — choose the container and configure both **Connect to application**
   (the backend protocol and port) and **Access through Tailscale** (the client-facing
   protocol and port). Review the route preview. A port suggested by the Unraid
   template is a starting point, not proof that the application listens there.
4. Copy the generated Extra Parameters into
   *Docker → container → Advanced View → Extra Parameters → Apply*. The builder
   does not edit templates or restart containers itself.
5. Add the generated, narrowly scoped grant and policy test in Tailscale's Access
   controls editor. Choose the intended user or group explicitly; host approval
   does not grant clients access to the Service.
6. Back on **DockTail**, use **Check connection** for the container. Then open the
   service URL from the intended client device.

Example: expose a container listening on port 80 as `svc:unraid-test`:

```
--label docktail.service.enable=true --label docktail.service.name=unraid-test --label docktail.service.port=80
```

The application port and Tailscale port need not match. For example, an application
speaking HTTP on port 4859 can be exposed over HTTPS on port 443:

```sh
--label docktail.service.enable=true --label docktail.service.name=homey --label docktail.service.port=4859 --label docktail.service.protocol=http --label docktail.service.service-port=443 --label docktail.service.service-protocol=https
```

The corresponding access grant targets **443**, not 4859:

```json
{
  "src": ["you@example.com"],
  "dst": ["svc:homey"],
  "ip": ["tcp:443"]
}
```

Replace the example identity with an actual Tailscale user login: an email address,
`username@github`, or `username@passkey`. Existing policy-defined groups such as
`group:household` and synced groups such as `group:admins@example.com` are also
supported. For a group, enter an actual member's login for the policy test.
Merge the grant into the existing policy; do not replace the policy with this
fragment. A policy test uses the user's login as `src` and
`["svc:homey:443"]` as `accept`.

`docktail.funnel.*` labels additionally require **Allow Funnel** in the Tailscale
plugin's own settings. While that is off, the Tailscale plugin strips Funnel entries
out of the serve config it shares with DockTail, silently removing the Funnels
DockTail created. The DockTail tab checks this, but only once a container asks for a
Funnel.

## Tabs

| Tab | What it does |
|---|---|
| DockTail | Service controls, environment preflight, local proxy configuration, and on-demand per-container connection diagnostics. Local configuration is not a promise of client access. |
| Settings | Credentials, tags, reconcile interval, log level. Credentials are stored separately in a `0600` file that is excluded from Unraid Connect's flash backup. |
| Labels | Both sides of the connection, route preview, template port suggestions, Extra Parameters, and scoped access-policy snippets. It produces text only: it does not edit templates, recreate containers, or change tailnet policy. |

### Connection diagnostics

**Check connection** separates backend connectivity, local proxy configuration,
the tailnet-wide Service definition, and host approval/readiness. It is read-only
and runs on demand, not automatically for every container when the page loads.
Use **Dismiss** to close the results or stop waiting for a running check. Late
responses cannot reopen the panel; host-side probes may still finish within their
time limit. **Check connection** remains available to run a fresh check.
Existing credentials are used for Control Plane reads; missing permissions or an
unavailable API are reported as unknown, not as a passing check. The plugin does
not request broader permissions or automatically modify shared Service definitions.

If the Service definition allows a different port from the local endpoint, review
that definition in Tailscale's admin console. Definitions are shared across hosts;
changing one can affect other hosts advertising the same Service.

Host-side diagnostics cannot establish access for a particular laptop or user.
A missing grant can make a Service's MagicDNS name appear nonexistent, even with
an approved, ready host. Check the grant and test the URL from the intended client.
An HTTP response establishes transport reachability, not application health:
authentication, setup, licensing, and subscription failures can remain.

The bounded check currently covers the primary Service and Funnel with
unambiguous IPv4 backend targets. Indexed Service endpoints and unresolved
destinations are explicitly left unverified rather than guessed. Backend HTTP
checks inspect the root response headers without following redirects or reading
application pages. The intended client endpoint is shown when the local Tailscale
DNS suffix is available; the Labels preview otherwise uses a clearly marked
tailnet placeholder.

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

AGPL-3.0, matching the DockTail binary this plugin ships — upstream is AGPL-3.0,
so anything conveying its compiled form must be too. DockTail is copyright its
upstream author, [marvinvr](https://github.com/marvinvr); the Unraid packaging in
this repository is copyright [vcolombo](https://github.com/vcolombo).

The package installs `LICENSE` and `SOURCE` alongside the binary in
`/usr/local/emhttp/plugins/docktail`. `SOURCE` records the upstream repository,
tag and **exact commit** the shipped binary was built from, which is what
AGPL-3.0 section 6 asks for: a user holding the binary can always find the
source it came from.

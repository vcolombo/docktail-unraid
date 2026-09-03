<?php

/*
    Copyright (C) 2026  vcolombo

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace DockTail;

final class Status
{
    public const TAILSCALE_BIN    = '/usr/local/sbin/tailscale';
    public const TAILSCALED_SOCK  = '/var/run/tailscale/tailscaled.sock';
    public const DOCKER_BIN       = '/usr/bin/docker';
    public const DOCKER_SOCK      = '/var/run/docker.sock';
    public const TAILSCALE_CFG    = '/boot/config/plugins/tailscale/tailscale.cfg';

    /**
     * @return array{out: string, code: int}
     */
    private static function run(string $command): array
    {
        $output = [];
        $code   = 1;
        @exec($command . ' 2>/dev/null', $output, $code);

        return ['out' => implode("\n", $output), 'code' => $code];
    }

    /**
     * "Running" or "Stopped", straight from the rc script so the page can never
     * disagree with the service itself.
     */
    public static function serviceState(): string
    {
        $result = self::run(escapeshellarg(RC_SCRIPT) . ' status');

        return str_contains($result['out'], 'Running') ? 'Running' : 'Stopped';
    }

    /**
     * Ordered environment checks. `ok` is true/false, or null for an advisory
     * row that must not read as a hard failure.
     *
     * `help` explains what the check is for and is always available by clicking
     * the check name; `remedy` is shown unconditionally when the check is not
     * passing.
     *
     * @return list<array{ok: bool|null, label: string, detail: string, help: string, remedy: string}>
     */
    public static function preflight(): array
    {
        $rows = [];

        $rows[] = [
            'ok'     => file_exists(self::TAILSCALE_BIN),
            'label'  => 'Tailscale plugin installed',
            'detail' => self::TAILSCALE_BIN,
            'help'   => 'DockTail does not bundle Tailscale. It runs the <code>tailscale</code> CLI that the official '
                . 'Tailscale plugin symlinks into <code>/usr/local/sbin</code>, which is also why the CLI and '
                . '<code>tailscaled</code> versions always match on Unraid.',
            'remedy' => 'Install the official Tailscale plugin from Community Apps. DockTail uses its tailscale CLI and tailscaled.',
        ];

        $rows[] = [
            'ok'     => file_exists(self::TAILSCALED_SOCK),
            'label'  => 'tailscaled running',
            'detail' => self::TAILSCALED_SOCK,
            'help'   => 'The Tailscale daemon socket DockTail writes serve and Funnel configuration to. If it is missing, '
                . 'Tailscale is installed but not running, and DockTail refuses to start rather than failing every '
                . 'reconcile cycle.',
            'remedy' => 'Enable Tailscale under Settings -> Network Services -> Tailscale.',
        ];

        $rows[] = [
            'ok'     => file_exists(self::DOCKER_SOCK),
            'label'  => 'Docker running',
            'detail' => self::DOCKER_SOCK,
            'help'   => 'DockTail discovers containers and watches Docker events through this socket. It is absent before '
                . 'the array starts, which is normal: the plugin starts DockTail on the <code>docker_started</code> '
                . 'event instead.',
            'remedy' => 'Start the array and enable Docker under Settings -> Docker.',
        ];

        $rows[] = self::nodeTagRow();
        $rows[] = self::credentialsRow();

        $funnelRow = self::funnelRow();
        if ($funnelRow !== null) {
            $rows[] = $funnelRow;
        }

        return $rows;
    }

    /**
     * tailscaled refuses to host a Service from an untagged node
     * ("service hosts must be tagged nodes"), and the Tailscale plugin has no
     * setting for advertised tags, so this is the single most common reason
     * DockTail cannot advertise anything on Unraid.
     *
     * @return array{ok: bool|null, label: string, detail: string, help: string, remedy: string}
     */
    private static function nodeTagRow(): array
    {
        $help = 'Tailscale only lets <strong>tagged</strong> nodes host a Service: an untagged node is rejected with '
            . '<code>service hosts must be tagged nodes</code>. The tags shown here are the ones this Unraid node '
            . 'advertises, and they are separate from the tags DockTail puts on each Service. The official Tailscale '
            . 'plugin has no setting for them, so they have to be set from the terminal. This is the most common '
            . 'reason DockTail starts cleanly but advertises nothing.';

        $remedy = 'Run <code>tailscale up --advertise-tags=tag:server --reset</code> from the Unraid terminal, '
            . 'or re-authenticate this node with a tagged auth key. The Tailscale plugin has no tags setting, '
            . 'and <code>--reset</code> briefly drops the Tailscale connection.';

        if ( ! file_exists(self::TAILSCALE_BIN)) {
            return ['ok' => null, 'label' => 'Node is tagged', 'detail' => 'Tailscale CLI not available', 'help' => $help, 'remedy' => $remedy];
        }

        $result = self::run(escapeshellarg(self::TAILSCALE_BIN) . ' status --json');
        $status = json_decode($result['out'], true);

        if ( ! is_array($status) || ! isset($status['Self']) || ! is_array($status['Self'])) {
            return ['ok' => null, 'label' => 'Node is tagged', 'detail' => 'Could not read tailscale status', 'help' => $help, 'remedy' => $remedy];
        }

        if ( ! array_key_exists('Tags', $status['Self'])) {
            // Older tailscaled builds omit the field entirely. Advisory only:
            // this check must never look like a hard failure it is not.
            return [
                'ok'     => null,
                'label'  => 'Node is tagged',
                'detail' => 'This Tailscale version does not report node tags',
                'help'   => $help,
                'remedy' => $remedy,
            ];
        }

        $tags = is_array($status['Self']['Tags']) ? $status['Self']['Tags'] : [];

        return [
            'ok'     => $tags !== [],
            'label'  => 'Node is tagged',
            'detail' => $tags === [] ? 'no tags advertised' : implode(', ', array_map('strval', $tags)),
            'help'   => $help,
            'remedy' => $remedy,
        ];
    }

    /** @return array{ok: bool|null, label: string, detail: string, help: string, remedy: string} */
    private static function credentialsRow(): array
    {
        $cfg   = Config::read();
        $oauth = ($cfg['TAILSCALE_OAUTH_CLIENT_ID'] ?? '') !== '' && ($cfg['TAILSCALE_OAUTH_CLIENT_SECRET'] ?? '') !== '';
        $apiKey = ($cfg['TAILSCALE_API_KEY'] ?? '') !== '';

        return [
            'ok'     => $oauth || $apiKey,
            'label'  => 'Control Plane credentials',
            'detail' => $oauth ? 'OAuth client' : ($apiKey ? 'API key' : 'automatic service creation disabled'),
            'help'   => 'Advertising a Service locally needs no credentials, but <em>creating</em> the Service definition in '
                . 'your tailnet does. With credentials DockTail creates and tags definitions for you; without them, '
                . 'every Service name you use must already exist in the tailnet policy or the advertisement is '
                . 'rejected. OAuth is preferred over an API key because API keys expire after 90 days. When both '
                . 'are set, OAuth wins.',
            'remedy' => 'Set an OAuth client (or an API key) on the Settings tab. Without credentials DockTail cannot create '
                . 'or tag Service definitions, so Services must already exist in the tailnet policy.',
        ];
    }

    /**
     * Only relevant once a container actually asks for a Funnel: on Unraid 7.2+
     * the Tailscale plugin strips Funnel entries out of the serve config and
     * re-POSTs it whenever its own ALLOW_FUNNEL is off, which silently removes
     * DockTail's Funnels.
     *
     * @return array{ok: bool|null, label: string, detail: string, help: string, remedy: string}|null
     */
    private static function funnelRow(): ?array
    {
        $wantsFunnel = false;
        foreach (self::labelledContainers() as $container) {
            if (($container['labels']['docktail.funnel.enable'] ?? '') === 'true') {
                $wantsFunnel = true;
                break;
            }
        }

        if ( ! $wantsFunnel) {
            return null;
        }

        $tsCfg   = is_file(self::TAILSCALE_CFG) ? (@parse_ini_file(self::TAILSCALE_CFG) ?: []) : [];
        $allowed = ($tsCfg['ALLOW_FUNNEL'] ?? '0') === '1';

        return [
            'ok'     => $allowed,
            'label'  => 'Funnel allowed by the Tailscale plugin',
            'detail' => $allowed ? 'ALLOW_FUNNEL=1' : 'ALLOW_FUNNEL is not enabled',
            'help'   => 'This row only appears because a container carries a <code>docktail.funnel.enable</code> label. '
                . 'Funnel exposes a service to the <strong>public internet</strong>, not just your tailnet. The '
                . 'Tailscale plugin owns the same serve configuration DockTail writes to, and while its own '
                . '"Allow Funnel" setting is off it deletes Funnel entries and rewrites that configuration &mdash; '
                . "silently removing DockTail's Funnels shortly after they are created.",
            'remedy' => 'Enable "Allow Funnel" under Settings -> Network Services -> Tailscale. While it is off, the Tailscale '
                . 'plugin removes Funnel entries from the serve config, including the ones DockTail creates.',
        ];
    }

    /**
     * Services currently advertised by tailscaled, keyed by "svc:<name>".
     *
     * @return array{services: array<string, array<string, mixed>>, raw: string}
     */
    public static function advertisedServices(): array
    {
        if ( ! file_exists(self::TAILSCALE_BIN)) {
            return ['services' => [], 'raw' => ''];
        }

        $json   = self::run(escapeshellarg(self::TAILSCALE_BIN) . ' serve status --json');
        $parsed = json_decode($json['out'], true);

        if (is_array($parsed) && isset($parsed['Services']) && is_array($parsed['Services'])) {
            return ['services' => $parsed['Services'], 'raw' => ''];
        }

        // No Services key: show the plain-text output rather than nothing.
        $plain = self::run(escapeshellarg(self::TAILSCALE_BIN) . ' serve status');

        return ['services' => [], 'raw' => $plain['out']];
    }

    /**
     * Running containers carrying any docktail.* label.
     *
     * Deliberately the Docker CLI and not Unraid's DockerClient: that class
     * only lifts net.unraid.docker.* labels and drops arbitrary ones.
     *
     * Memoised: one page render asks for this list from both the preflight
     * checks and the service table, and each call costs one `docker ps` plus
     * one `docker inspect` per running container.
     *
     * @return list<array{id: string, name: string, labels: array<string, string>}>
     */
    public static function labelledContainers(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];

        if ( ! file_exists(self::DOCKER_SOCK) || ! file_exists(self::DOCKER_BIN)) {
            return $cache;
        }

        $list = self::run(escapeshellarg(self::DOCKER_BIN) . " ps --format '{{.ID}} {{.Names}}'");
        if ($list['out'] === '') {
            return $cache;
        }

        $containers = [];
        foreach (explode("\n", $list['out']) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$id, $name] = array_pad(explode(' ', $line, 2), 2, '');
            if ($id === '') {
                continue;
            }

            $inspect = self::run(escapeshellarg(self::DOCKER_BIN) . ' inspect --format ' . escapeshellarg('{{json .Config.Labels}}') . ' ' . escapeshellarg($id));
            $labels  = json_decode($inspect['out'], true);
            if ( ! is_array($labels)) {
                continue;
            }

            $docktailLabels = [];
            foreach ($labels as $key => $value) {
                if (str_starts_with((string) $key, 'docktail.')) {
                    $docktailLabels[(string) $key] = (string) $value;
                }
            }

            if ($docktailLabels === []) {
                continue;
            }

            $containers[] = ['id' => $id, 'name' => $name, 'labels' => $docktailLabels];
        }

        $cache = $containers;

        return $cache;
    }

    /**
     * One container/service row per labelled container, joined against what
     * tailscaled currently advertises.
     *
     * @param  array<string, array<string, mixed>> $advertised
     * @return list<array{container: string, service: string, port: string, protocol: string, advertised: bool}>
     */
    public static function serviceRows(array $advertised): array
    {
        $rows = [];

        foreach (self::labelledContainers() as $container) {
            $labels = $container['labels'];

            if (($labels['docktail.service.enable'] ?? '') === 'true') {
                $name = $labels['docktail.service.name'] ?? '';
                $port = $labels['docktail.service.port'] ?? '';
                $rows[] = [
                    'container'  => $container['name'],
                    'service'    => $name === '' ? '(missing docktail.service.name)' : 'svc:' . $name,
                    'port'       => $port === '' ? '(missing docktail.service.port)' : $port,
                    'protocol'   => $labels['docktail.service.protocol'] ?? ($port === '443' ? 'https' : 'http'),
                    'advertised' => $name !== '' && isset($advertised['svc:' . $name]),
                ];
            }

            if (($labels['docktail.funnel.enable'] ?? '') === 'true') {
                $name = $labels['docktail.service.name'] ?? '';
                $rows[] = [
                    'container'  => $container['name'],
                    'service'    => ($name === '' ? '(funnel)' : 'svc:' . $name) . ' (funnel)',
                    'port'       => $labels['docktail.funnel.port'] ?? '',
                    'protocol'   => $labels['docktail.funnel.protocol'] ?? 'https',
                    'advertised' => $name !== '' && isset($advertised['svc:' . $name]),
                ];
            }
        }

        return $rows;
    }

    /**
     * Payload for status.php, so the page can refresh without a full reload.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        $advertised = self::advertisedServices();

        return [
            'state'            => self::serviceState(),
            'preflight'        => self::preflight(),
            'rows'             => self::serviceRows($advertised['services']),
            'advertised'       => array_keys($advertised['services']),
            'serveStatusPlain' => $advertised['raw'],
            'pluginVersion'    => pluginVersion(),
            'docktailVersion'  => docktailVersion(),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function renderBody(array $snapshot): string
    {
        ob_start();
        $state = (string) $snapshot['state'];
        $token = csrfToken();
        ?>
<dl>
    <dt>Service:</dt>
    <dd class="docktail-inline"><span class="<?= $state === 'Running' ? 'green-text' : 'orange-text'; ?>"><?= h($state); ?></span></dd>
</dl>
<blockquote class="inline_help">
    Whether the DockTail service is running on this Unraid host. It only starts when
    <em>Enable DockTail</em> is set on the Settings tab <strong>and</strong> both Docker and
    <code>tailscaled</code> are up, so <em>Stopped</em> right after a boot or an array stop is
    expected rather than an error. The plugin starts it again on the
    <code>docker_started</code> event.
</blockquote>

<form method="POST" id="docktail_control" action="/plugins/docktail/apply.php" target="progressFrame">
<input type="hidden" name="csrf_token" value="<?= h($token); ?>">
<input type="hidden" name="action" id="docktail_action" value="restart">
<dl>
    <dt>Service controls:</dt>
    <dd class="docktail-inline">
        <input type="button" value="Start" onclick="docktailControl('start')">
        <input type="button" value="Stop" onclick="docktailControl('stop')">
        <input type="button" value="Restart" onclick="docktailControl('restart')">
        <input type="button" value="Refresh" onclick="docktailRefresh()">
    </dd>
</dl>
<blockquote class="inline_help">
    <strong>Start</strong> and <strong>Restart</strong> honour the <em>Enable DockTail</em>
    setting: neither starts anything while it is set to No.
    <strong>Stop</strong> waits for DockTail to withdraw every Service it advertises before
    returning, which can take up to 35 seconds &mdash; killing it sooner would leave Services
    advertised on the tailnet with nothing behind them.
    <strong>Refresh</strong> only re-reads this page; it does not touch the service.
</blockquote>
</form>

<table class="unraid tablesorter"><thead><tr><td>Preflight</td></tr></thead></table>

<blockquote class="inline_help">
    These checks cover everything DockTail needs from the rest of the system. Click any check
    name to read what it is for. A failing check shows what to do about it without being
    clicked.
</blockquote>

<?php foreach ($snapshot['preflight'] as $row) {
    $ok   = $row['ok'];
    $mark = $ok === true ? '<span class="green-text">&#10004;</span>'
        : ($ok === false ? '<span class="red-text">&#10008;</span>' : '<span class="orange-text">&#9432;</span>');
    ?>
<dl>
    <dt><?= h($row['label']); ?>:</dt>
    <dd class="docktail-inline"><?= $mark; ?> <span class="docktail-detail"><?= h($row['detail']); ?></span></dd>
</dl>
<?php if ($ok !== true) { ?>
<div class="docktail-remedy"><?= $row['remedy']; ?></div>
<?php } ?>
<blockquote class="inline_help"><?= $row['help']; ?></blockquote>
<?php } ?>

<table class="unraid tablesorter"><thead><tr><td>Containers</td></tr></thead></table>
<blockquote class="inline_help">
    Every running container carrying a <code>docktail.*</code> label, joined against what
    <code>tailscaled</code> currently advertises.
    <strong>Service</strong> is the tailnet name (<code>svc:&lt;name&gt;</code>) taken from
    <code>docktail.service.name</code>; <strong>Container port</strong> is the port inside the
    container that traffic is proxied to.
    <strong>Advertised</strong> means <code>tailscaled</code> is serving that Service right now
    &mdash; a cross here with DockTail running usually means the Service definition does not
    exist in your tailnet, this node is not tagged, or the reconcile interval has not elapsed
    yet. A tick while DockTail is <em>Stopped</em> means something else advertised it: another
    DockTail instance, or a leftover from <em>Skip shutdown cleanup</em>.
</blockquote>

<?php if ($snapshot['rows'] === []) { ?>
<div class="docktail-remedy">
    No running container carries a <code>docktail.*</code> label. Use the Labels tab to
    generate one, then paste it into the container's Extra Parameters field.
</div>
<?php } else { ?>
<table class="unraid tablesorter">
<thead><tr><th>Container</th><th>Service</th><th>Container port</th><th>Protocol</th><th>Advertised</th></tr></thead>
<tbody>
<?php foreach ($snapshot['rows'] as $row) { ?>
    <tr>
        <td><?= h($row['container']); ?></td>
        <td><?= h($row['service']); ?></td>
        <td><?= h($row['port']); ?></td>
        <td><?= h($row['protocol']); ?></td>
        <td><?= $row['advertised'] ? '<span class="green-text">&#10004;</span>' : '<span class="red-text">&#10008;</span>'; ?></td>
    </tr>
<?php } ?>
</tbody>
</table>
<?php } ?>

<?php if (($snapshot['serveStatusPlain'] ?? '') !== '') { ?>
<div class="docktail-remedy">
    This Tailscale version does not report Services as JSON; raw
    <code>tailscale serve status</code> output follows.
</div>
<pre><?= h((string) $snapshot['serveStatusPlain']); ?></pre>
<?php } ?>

<table class="unraid tablesorter"><thead><tr><td>Versions</td></tr></thead></table>

<dl>
    <dt>Plugin version:</dt>
    <dd><?= h((string) $snapshot['pluginVersion']); ?></dd>
</dl>
<blockquote class="inline_help">
    Version of this Unraid plugin, read from the installed manifest in
    <code>/var/log/plugins</code>. It is dated (<code>YYYY.MM.DD</code>) and is independent of
    the DockTail version below.
</blockquote>

<dl>
    <dt>DockTail version:</dt>
    <dd><?= h((string) $snapshot['docktailVersion']); ?></dd>
</dl>
<blockquote class="inline_help">
    Version of the DockTail daemon this plugin ships. The plugin pins one specific DockTail
    release and builds it unmodified, so this only changes when the plugin is updated.
</blockquote>

<dl>
    <dt>Log:</dt>
    <dd><a href="/webGui/scripts/tail_log&amp;arg1=docktail.log" onclick="openBox(this.href,'DockTail Log',600,900,true);return false;"><?= h(LOG_FILE); ?></a></dd>
</dl>
<blockquote class="inline_help">
    Everything DockTail and the service script write. Start here when a Service will not
    advertise: it records the resolved configuration at startup and the reason for each
    rejected Service. It is rotated daily because <code>/var/log</code> is a RAM filesystem.
    Raise <em>Log level</em> to <code>debug</code> on the Settings tab for the label-parsing
    detail.
</blockquote>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * The DockTail tab: the plugin's opening view.
     */
    public static function render(): string
    {
        $token = csrfToken();

        ob_start(); ?>
<table class="unraid tablesorter"><thead><tr><td>DockTail</td></tr></thead></table>
<blockquote class="inline_help">
    DockTail watches Docker containers, reads <code>docktail.*</code> labels, and exposes
    matching containers as native Tailscale Services &mdash; without giving each app its own
    Tailscale device.
    <br><br>
    <strong>Settings</strong> holds the credentials and the enable switch, and
    <strong>Labels</strong> generates the <code>--label</code> string for a container's Extra
    Parameters field.
    <br><br>
    Every field name on these tabs is clickable and explains itself; the Help button in the
    header toggles all of them at once.
    <br><br>
    Full documentation: <a href="https://docktail.org" target="_blank">docktail.org</a>
</blockquote>

<div id="docktail_status"><?= self::renderBody(self::snapshot()); ?></div>

<script>
function docktailControl(action) {
    $('#docktail_action').val(action);
    $('#docktail_control').submit();
    // The rc script waits for a graceful drain, so give it time before the
    // page re-reads the state.
    setTimeout(docktailRefresh, 8000);
}

function docktailRefresh() {
    $.post('/plugins/docktail/status.php', {csrf_token: '<?= h($token); ?>'}, function(data) {
        $('#docktail_status').html(data.html);

        // The replacement fragment arrives unwired, and Unraid's page-load
        // wiring cannot reach it. Rebind, and respect the global Help toggle so
        // a refresh does not collapse help the user had open.
        docktailBindHelp('#docktail_status');
        if ($('.nav-item.HelpButton').hasClass('active')) {
            $('#docktail_status blockquote.inline_help').show();
        }
    }, 'json');
}
</script>
        <?php
        return '<div class="docktail-help-scope">' . (string) ob_get_clean() . '</div>' . pageAssets();
    }
}

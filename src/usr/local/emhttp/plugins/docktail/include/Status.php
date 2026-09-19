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
    /**
     * How long a start, stop or restart can legitimately take, in seconds.
     *
     * Asked of rc.docktail rather than written down here. The script owns
     * every number in it - the lifecycle wait and how many turns of it a
     * queue is allowed, the drain, the config lock's wait, the visibility
     * wait - and the last time this was a constant on both sides they
     * drifted: the page gave up at 150s on an action the script could still
     * be doing at 195s, and told somebody it had failed.
     *
     * Clamped because it ends up in set_time_limit() and an XHR timeout: a
     * script that prints nonsense (or nothing, on an older version that has
     * no budget action) must not turn into an unbounded wait or an instant
     * one.
     */
    public static function lifecycleBudget(): int
    {
        $result = self::run(escapeshellarg(RC_SCRIPT) . ' budget');
        $budget = (int) trim($result['out']);

        if ($budget < 60 || $budget > 600) {
            return 240;
        }

        return $budget;
    }

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
     * Local Serve entries, keyed by "svc:<name>". Presence proves only that
     * proxy configuration exists, not approval, readiness or client access.
     *
     * Three outcomes, and they must not be confused: a config with Services, a
     * config with none (normal - no local Service entries), and output
     * that could not be read at all. Treating the middle case as the last one
     * made an empty serve config report itself as a Tailscale version problem.
     *
     * @return array{services: array<string, array<string, mixed>>, config: array, raw: string, degraded: bool}
     */
    public static function advertisedServices(): array
    {
        if ( ! file_exists(self::TAILSCALE_BIN)) {
            return ['services' => [], 'config' => [], 'raw' => '', 'degraded' => true];
        }

        $result = self::run(escapeshellarg(self::TAILSCALE_BIN) . ' serve status --json');
        $out    = $result['out'];
        $parsed = json_decode($out, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // The CLI can print warnings ahead of the JSON; DockTail strips
            // these too before parsing.
            $brace = strpos($out, '{');
            if ($brace !== false) {
                $parsed = json_decode(substr($out, $brace), true);
            }
        }

        if ($result['code'] === 0 && json_last_error() === JSON_ERROR_NONE && ($parsed === null || is_array($parsed))
            && (! isset($parsed['Services']) || is_array($parsed['Services']))) {
            // Valid JSON. "null" and "{}" both mean no local Serve entries,
            // which is a state, not a failure.
            $services = is_array($parsed) && isset($parsed['Services']) && is_array($parsed['Services'])
                ? $parsed['Services']
                : [];

            return ['services' => $services, 'config' => $parsed ?? [], 'raw' => '', 'degraded' => false];
        }

        // Never return raw CLI output: a proxy URL can contain credentials.
        return ['services' => [], 'config' => [], 'raw' => '', 'degraded' => true];
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
        return self::inspectLabelledContainers()['containers'];
    }

    /**
     * Whether any running container's `docker inspect` failed during the last
     * labelledContainers() pass. When true the enrolled list is incomplete, so
     * an empty result must not be presented as confirmed "nothing enrolled".
     */
    public static function labelledContainersInspectionFailed(): bool
    {
        return self::inspectLabelledContainers()['inspectionFailed'];
    }

    /**
     * Memoised sweep of running containers and their docktail.* labels.
     *
     * @return array{containers: list<array{id: string, name: string, labels: array<string, string>}>, inspectionFailed: bool}
     */
    private static function inspectLabelledContainers(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $empty = ['containers' => [], 'inspectionFailed' => false];

        if ( ! file_exists(self::DOCKER_SOCK) || ! file_exists(self::DOCKER_BIN)) {
            return $cache = $empty;
        }

        $list = self::run(escapeshellarg(self::DOCKER_BIN) . " ps --format '{{.ID}} {{.Names}}'");
        if ($list['code'] !== 0) {
            return $cache = ['containers' => [], 'inspectionFailed' => true];
        }
        if ($list['out'] === '') {
            return $cache = $empty;
        }

        $containers       = [];
        $inspectionFailed = false;
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
            // A command or JSON failure marks the scan incomplete. A valid JSON
            // null is an ordinary container with no labels, not a failure.
            if ($inspect['code'] !== 0 || (json_last_error() !== JSON_ERROR_NONE)) {
                $inspectionFailed = true;
                continue;
            }
            if ($labels === null) {
                continue;
            }
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

        return $cache = ['containers' => $containers, 'inspectionFailed' => $inspectionFailed];
    }

    /**
     * One container/service row per labelled container, joined against what
     * the local Serve configuration. The legacy method name above is retained
     * for callers; the UI must not call this an advertisement/access verdict.
     *
     * @param  array<string, array<string, mixed>> $advertised
     * @return list<array<string, mixed>>
     */
    public static function serviceRows(array $advertised, ?array $serve = null): array
    {
        $rows = [];

        foreach (self::labelledContainers() as $container) {
            $labels = $container['labels'];

            if (($labels['docktail.service.enable'] ?? '') === 'true') {
                $name = $labels['docktail.service.name'] ?? '';
                $port = $labels['docktail.service.port'] ?? '';
                $rows[] = [
                    'id'         => $container['id'],
                    'kind'       => 'service',
                    'container'  => $container['name'],
                    'service'    => $name === '' ? '(missing docktail.service.name)' : (str_starts_with($name, 'svc:') ? $name : 'svc:' . $name),
                    'port'       => $port === '' ? '(missing docktail.service.port)' : $port,
                    'protocol'   => $labels['docktail.service.protocol'] ?? ($port === '443' ? 'https' : 'http'),
                    'localConfigured' => $name !== '' && isset($advertised[str_starts_with($name, 'svc:') ? $name : 'svc:' . $name]),
                ];
            }

            if (($labels['docktail.funnel.enable'] ?? '') === 'true') {
                $rows[] = [
                    'id'         => $container['id'],
                    'kind'       => 'funnel',
                    'container'  => $container['name'],
                    'service'    => '(node Funnel)',
                    'port'       => $labels['docktail.funnel.port'] ?? '',
                    'protocol'   => in_array($labels['docktail.funnel.protocol'] ?? 'https', ['tcp', 'tls-terminated-tcp'], true) ? 'tcp' : 'http',
                    'localConfigured' => $serve === null ? null : self::funnelEntryPresent($serve, $labels),
                ];
            }
        }

        return $rows;
    }

    /** A Funnel lives in the node's top-level Serve config, not Services. */
    private static function funnelEntryPresent(array $serve, array $labels): bool
    {
        $port = $labels['docktail.funnel.funnel-port'] ?? '443';
        foreach (($serve['AllowFunnel'] ?? []) as $hostPort => $allowed) {
            if ($allowed === true && str_ends_with((string) $hostPort, ':' . $port)
                && isset($serve['TCP'][$port])) {
                return true;
            }
        }
        return false;
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
            'rows'             => self::serviceRows($advertised['services'], $advertised['config']),
            'inspectionFailed' => self::labelledContainersInspectionFailed(),
            'advertised'       => array_keys($advertised['services']),
            'serveStatusPlain' => $advertised['raw'],
            'serveUnreadable'  => $advertised['degraded'],
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

<form method="POST" id="docktail_control" action="/plugins/docktail/apply.php">
<input type="hidden" name="csrf_token" value="<?= h($token); ?>">
<input type="hidden" name="action" id="docktail_action" value="restart">
<dl>
    <dt>Service controls:</dt>
    <dd class="docktail-inline">
        <input type="button" class="docktail-control" value="Start" onclick="docktailControl('start')">
        <input type="button" class="docktail-control" value="Stop" onclick="docktailControl('stop')">
        <input type="button" class="docktail-control" value="Restart" onclick="docktailControl('restart')">
        <input type="button" class="docktail-control" value="Refresh" onclick="docktailRefresh()">
        <span id="docktail_control_note" class="docktail-apply-result"></span>
    </dd>
</dl>
<blockquote class="inline_help">
    <strong>Start</strong> and <strong>Restart</strong> honour the <em>Enable DockTail</em>
    setting: neither starts anything while it is set to No.
    <strong>Stop</strong> waits for DockTail to withdraw every Service it advertises before
    returning, which can take up to 35 seconds &mdash; longer if it has to wait for a start or
    stop that is already running &mdash; and killing it sooner would leave Services
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
    Running containers with Service or Funnel labels, joined against the local
    <code>tailscaled</code> Serve configuration.
    <strong>Service</strong> is the tailnet name (<code>svc:&lt;name&gt;</code>) taken from
    <code>docktail.service.name</code>; <strong>Application port</strong> and
    <strong>Application protocol</strong> describe the backend, not the client-facing endpoint.
    <strong>Local proxy config</strong> reports only an entry's presence. It does not prove
    that its backend is correct, the host is approved or ready, or a client can resolve or
    access it. A Funnel entry is node-wide and can belong to another container.
    <strong>Check connection</strong> reads current configuration and probes that container's
    backend on demand (up to 20 seconds). Each result has its own status and remedy.
</blockquote>

<?php if ( ! empty($snapshot['inspectionFailed'])) { ?>
<div class="docktail-remedy">
    Some running containers could not be inspected, so this list may be incomplete.
    Refresh to retry; if it persists, check that Docker is healthy.
</div>
<?php } ?>
<?php if ($snapshot['rows'] === []) { ?>
<?php if (empty($snapshot['inspectionFailed'])) { ?>
<div class="docktail-remedy">
    No running container enables a DockTail Service or Funnel. Use the Labels tab to
    generate the labels, then paste them into the container's Extra Parameters field.
</div>
<?php } ?>
<?php } else { ?>
<table class="unraid tablesorter docktail-container-table">
<thead><tr><th>Container</th><th>Service</th><th>Application port</th><th>Application protocol</th><th>Local proxy config</th><th>Connection</th></tr></thead>
<tbody>
<?php foreach ($snapshot['rows'] as $row) { ?>
    <tr>
        <td data-label="Container"><?= h($row['container']); ?></td>
        <td data-label="Service"><?= h($row['service']); ?></td>
        <td data-label="Application port"><?= h($row['port']); ?></td>
        <td data-label="Application protocol"><?= h($row['protocol']); ?></td>
        <td data-label="Local proxy config"><?= $snapshot['serveUnreadable'] || $row['localConfigured'] === null ? 'Unknown' : ($row['localConfigured'] ? 'Entry present (unchecked)' : 'Entry absent'); ?></td>
        <td data-label="Connection">
            <input type="button" class="docktail-check" value="Check connection" data-container="<?= h($row['id']); ?>" onclick="docktailCheck(this)">
        </td>
    </tr>
    <tr class="docktail-check-detail docktail-hidden tablesorter-childRow"><td colspan="6">
        <input type="button" value="Dismiss" onclick="docktailInvalidateStatus(); $(this).closest('tr').prev().find('.docktail-check').trigger('focus')">
        <div class="docktail-check-result" role="status" aria-live="polite"></div>
    </td></tr>
<?php } ?>
</tbody>
</table>
<?php } ?>

<?php
// An enabled Service can be waiting for reconciliation. Funnel-only containers
// do not need a Service entry and must not trigger this message.
if (array_filter($snapshot['rows'], static fn (array $row): bool => $row['kind'] === 'service') !== []
    && $snapshot['advertised'] === [] && $state === 'Running' && ! $snapshot['serveUnreadable']) { ?>
<div class="docktail-remedy">
    No local Service entries were found. After a start or restart DockTail configures them
    on its next reconcile pass (60 seconds by default). Refresh after that interval; if
    entries remain absent, check
    <code>/var/log/docktail.log</code>.
</div>
<?php } ?>

<?php if ($snapshot['serveUnreadable']) { ?>
<div class="docktail-remedy">
    Could not read the local Serve configuration. Local proxy state is unknown.
    Check the Tailscale plugin and try Refresh again.
</div>
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
var docktailStatusEpoch = 0;
var docktailCheckRequest = null;
var docktailRefreshRequest = null;
var docktailControlBusy = false;
var docktailStatusToken = <?= json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

// A refresh, check or service control invalidates all older callbacks. Aborting
// alone is insufficient: a completed response can already have queued a handler.
function docktailInvalidateStatus() {
    docktailStatusEpoch++;
    if (docktailCheckRequest) {
        docktailCheckRequest.abort();
        docktailCheckRequest = null;
    }
    if (docktailRefreshRequest) {
        docktailRefreshRequest.abort();
        docktailRefreshRequest = null;
    }
    $('.docktail-check').prop('disabled', docktailControlBusy).val('Check connection');
    $('.docktail-check-detail').addClass('docktail-hidden').find('.docktail-check-result').empty();
    $('#docktail_control_note').removeClass('docktail-apply-error').text('');
    return docktailStatusEpoch;
}

function docktailCheck(button) {
    if (docktailControlBusy || docktailCheckRequest) {
        return;
    }
    var epoch = docktailInvalidateStatus();
    var input = $(button);
    var container = input.attr('data-container');
    var detail = input.closest('tr').next('.docktail-check-detail').removeClass('docktail-hidden');
    var result = detail.find('.docktail-check-result');
    result.text('Checking this container from the Unraid host (up to 20 seconds)...');
    $('.docktail-check').prop('disabled', true);
    input.val('Checking...');

    function current() {
        return epoch === docktailStatusEpoch && $.contains(document, button);
    }
    function failure(message) {
        result.empty().append($('<span>').addClass('orange-text').text(message));
    }
    docktailCheckRequest = $.ajax({
        url: '/plugins/docktail/status.php',
        type: 'POST',
        dataType: 'json',
        timeout: 25000,
        data: {csrf_token: docktailStatusToken, action: 'check_connection', container: container}
    }).done(function(data) {
        if (!current()) {
            return;
        }
        var states = {
            pass: ['Confirmed', 'green-text'],
            fail: ['Problem', 'red-text'],
            warning: ['Advisory', 'orange-text'],
            unknown: ['Unknown', 'orange-text'],
            not_verified: ['Not verified', 'orange-text'],
            not_applicable: ['Not applicable', '']
        };
        if (!data || data.container !== container || !Array.isArray(data.checks) || !data.checks.length
            || data.checks.some(function(check) {
                return !check || !Object.prototype.hasOwnProperty.call(states, check.status)
                    || typeof check.label !== 'string' || typeof check.detail !== 'string'
                    || typeof check.remedy !== 'string';
            })) {
            failure('No usable check result was returned. State is unknown; retry or refresh.');
            return;
        }
        result.empty().append($('<p>').text('Host-side snapshot. Client access remains unverified.'
            + (typeof data.checkedAt === 'string' ? ' Checked at ' + data.checkedAt : '')));
        var table = $('<table>').addClass('unraid docktail-check-table');
        var body = $('<tbody>').appendTo(table);
        data.checks.forEach(function(check) {
            var state = states[check.status];
            var row = $('<tr>').appendTo(body);
            $('<td>').text(check.label).appendTo(row);
            $('<td>').append($('<span>').addClass(state[1]).text(state[0])).appendTo(row);
            var cell = $('<td>').append($('<div>').text(check.detail)).appendTo(row);
            if (check.remedy) {
                cell.append($('<div>').addClass('docktail-remedy').text(check.remedy));
            }
        });
        // Every result is inserted as text. No server, label or API string is
        // interpreted as HTML, a URL, a CSS selector or a JavaScript fragment.
        result.append(table);
    }).fail(function(xhr, status) {
        if (!current()) {
            return;
        }
        failure(status === 'timeout'
            ? 'Check timed out. Results are unknown; retry after checking Docker and Tailscale.'
            : (xhr.status === 403 ? 'Request rejected (HTTP 403). Reload the page to refresh the Unraid session and CSRF token.'
                : 'Connection check failed. Results are unknown; retry or refresh.'));
    }).always(function() {
        if (current()) {
            docktailCheckRequest = null;
            $('.docktail-check').prop('disabled', false).val('Check connection');
        }
    });
}

/*
 * Submitted over AJAX rather than into the hidden progressFrame. A stop waits
 * up to 35 seconds for DockTail to withdraw its Services - and before that, up
 * to RC_LOCK_WAIT for any start or stop already in flight - and posting into a
 * frame nobody can see made that indistinguishable from a dead button.
 */
function docktailControl(action) {
    if (docktailControlBusy) {
        return;
    }
    docktailControlBusy = true;
    docktailInvalidateStatus();
    var note = $('#docktail_control_note');
    var buttons = $('.docktail-control');
    var message = '';
    var failed = false;

    var progress = {
        start: 'Starting DockTail...',
        stop: 'Stopping DockTail - waiting for it to withdraw its Services. Up to 35 seconds, longer if another start or stop is still finishing...',
        restart: 'Restarting DockTail - the stop waits for Services to withdraw. Up to 35 seconds, longer if another start or stop is still finishing...'
    };

    note.removeClass('docktail-apply-error').text(progress[action] || 'Working...');
    buttons.prop('disabled', true);
    $('#docktail_action').val(action);

    $.ajax({
        url: $('#docktail_control').attr('action'),
        type: 'POST',
        data: $('#docktail_control').serialize(),
        // Past the endpoint's own budget, which comes from rc.docktail: the
        // lifecycle wait and the turns of it a queue is allowed, then the
        // drain and the start. All three numbers - script, endpoint, browser
        // - come from that one place now, because when they were written
        // down separately they drifted, and a browser that gives up first
        // reports a failure for work that is still running.
        timeout: <?= (Status::lifecycleBudget() + 30) * 1000; ?>
    }).done(function(data) {
        // The endpoint's first line is the answer; the rest is rc output.
        message = String(data).split('\n')[0].trim() || 'Done.';

        // A stop withdraws every Service, so after starting again the table
        // stays empty until the next reconcile pass. Say so, rather than
        // leaving a screen of crosses to be read as a failure.
        if (action === 'start' || action === 'restart') {
            message += ' Services re-advertise on the next reconcile pass.';
        }
    }).fail(function(xhr) {
        failed  = true;
        // The body first, when there is one: a refused action answers 409
        // with the reason on its first line - another start or stop holding
        // the lifecycle lock - and "HTTP 409" on its own tells nobody that.
        var reason = xhr.responseText ? String(xhr.responseText).split('\n')[0].trim() : '';
        message = xhr.statusText === 'timeout'
            ? 'Timed out waiting for the service script. Reload to see the current state.'
            : (reason || 'Request failed: HTTP ' + xhr.status + '. See /var/log/docktail.log.');
    }).always(function() {
        docktailControlBusy = false;
        buttons.prop('disabled', false);
        // Refresh either way: the action may well have taken effect even if the
        // request did not come back cleanly. The refresh replaces the fragment
        // holding this note, so the message has to be re-applied afterwards.
        docktailRefresh(message, failed);
    });
}

function docktailRefresh(message, failed) {
    if (docktailControlBusy) {
        return;
    }
    var epoch = docktailInvalidateStatus();
    $('#docktail_control_note').removeClass('docktail-apply-error').text('Refreshing...');
    docktailRefreshRequest = $.ajax({
        url: '/plugins/docktail/status.php',
        type: 'POST',
        dataType: 'json',
        timeout: 25000,
        data: {csrf_token: docktailStatusToken, action: 'snapshot'}
    }).done(function(data) {
        if (epoch !== docktailStatusEpoch) {
            return;
        }
        if (!data || typeof data.html !== 'string') {
            $('#docktail_control_note').addClass('docktail-apply-error').text('Refresh returned no usable snapshot; retry.');
            return;
        }
        $('#docktail_status').html(data.html);

        // The replacement fragment arrives unwired, and Unraid's page-load
        // wiring cannot reach it. Rebind, and respect the global Help toggle so
        // a refresh does not collapse help the user had open.
        docktailBindHelp('#docktail_status');
        if ($('.nav-item.HelpButton').hasClass('active')) {
            $('#docktail_status blockquote.inline_help').show();
        }

        if (message) {
            $('#docktail_control_note')
                .toggleClass('docktail-apply-error', !!failed)
                .text(message);
        }
    }).fail(function(xhr, status) {
        if (epoch === docktailStatusEpoch) {
            $('#docktail_control_note').addClass('docktail-apply-error')
                .text(status === 'timeout' ? 'Refresh timed out; retry.' : 'Refresh failed; reload the page or retry.');
        }
    }).always(function() {
        if (epoch === docktailStatusEpoch) {
            docktailRefreshRequest = null;
        }
    });
}
</script>
        <?php
        return '<div class="docktail-help-scope">' . (string) ob_get_clean() . '</div>' . pageAssets();
    }
}

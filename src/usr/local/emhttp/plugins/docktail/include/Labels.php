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

/**
 * Builds the `--label` string for Unraid's Extra Parameters field.
 *
 * Unraid's container editor has no label field: arbitrary Docker labels are
 * only reachable through Extra Parameters. This builder only produces text. It
 * never edits templates-user XML and never recreates containers, because
 * dockerMan applies a template change by stopping and removing the container.
 */
final class Labels
{
    public const TARGET_PROTOCOLS  = ['http', 'https', 'https+insecure', 'tcp', 'tls-terminated-tcp'];
    public const SERVICE_PROTOCOLS = ['http', 'https', 'tcp', 'tls-terminated-tcp'];
    public const FUNNEL_PROTOCOLS  = ['https', 'http', 'tcp', 'tls-terminated-tcp'];
    public const FUNNEL_PORTS      = ['443', '8443', '10000'];

    /**
     * @param  array<string, mixed>       $input
     * @return string|list<string>        label string, or the validation errors
     */
    public static function build(array $input)
    {
        $get = static fn (string $key): string => trim((string) ($input[$key] ?? ''));
        $on  = static fn (string $key): bool => in_array((string) ($input[$key] ?? ''), ['1', 'true', 'on'], true);

        $serviceMode = $on('service_enable');
        $funnelMode  = $on('funnel_enable');

        $errors = [];
        $labels = [];

        if ( ! $serviceMode && ! $funnelMode) {
            return ['Enable a Tailscale Service, a Funnel, or both.'];
        }

        // A funnel-only container carries no service name: DockTail's
        // funnel-only path leaves ServiceName empty.
        $serviceName = $get('service_name');
        if ($serviceMode && $serviceName === '') {
            $errors[] = 'Service name is required.';
        } elseif ($serviceName !== '' && preg_match('/^[a-zA-Z0-9-]+$/', $serviceName) !== 1) {
            $errors[] = 'Service name may only contain letters, digits and hyphens.';
        }

        $targetPort      = $get('service_port');
        $servicePort     = $get('service_service_port');
        $targetProtocol  = $get('service_protocol');
        $serviceProtocol = $get('service_service_protocol');

        if ($serviceMode) {
            if ($targetPort === '') {
                $errors[] = 'Container port is required.';
            } elseif ( ! self::isPort($targetPort)) {
                $errors[] = 'Container port must be a number between 1 and 65535.';
            }
            if ($servicePort !== '' && ! self::isPort($servicePort)) {
                $errors[] = 'Service port must be a number between 1 and 65535.';
            }
            if ($targetProtocol !== '' && ! in_array($targetProtocol, self::TARGET_PROTOCOLS, true)) {
                $errors[] = 'Container protocol must be one of: ' . implode(', ', self::TARGET_PROTOCOLS) . '.';
            }
            if ($serviceProtocol !== '' && ! in_array($serviceProtocol, self::SERVICE_PROTOCOLS, true)) {
                $errors[] = 'Service protocol must be one of: ' . implode(', ', self::SERVICE_PROTOCOLS) . '.';
            }
        }

        $effectiveTargetProtocol  = self::effectiveTargetProtocol($targetProtocol, $targetPort);
        $effectiveServiceProtocol = self::effectiveServiceProtocol($serviceProtocol, $servicePort, $effectiveTargetProtocol);
        $isHttpService            = in_array($effectiveServiceProtocol, ['http', 'https'], true);

        $path = $get('service_path');
        if ($path !== '' && ! str_starts_with($path, '/')) {
            $errors[] = 'Service path must start with "/".';
        }
        if ($path !== '' && $path !== '/' && ! $isHttpService) {
            $errors[] = 'Service path is only supported for HTTP/HTTPS services.';
        }

        $proxyProtocol = $get('service_proxy_protocol');
        if ($proxyProtocol !== '') {
            if ( ! in_array($proxyProtocol, ['1', '2'], true)) {
                $errors[] = 'PROXY protocol must be 1 or 2.';
            } elseif ($isHttpService) {
                $errors[] = 'PROXY protocol is only supported for TCP forwarding (service protocol tcp or tls-terminated-tcp).';
            }
        }

        $funnelPort       = $get('funnel_port');
        $funnelPublicPort = $get('funnel_funnel_port');
        $funnelProtocol   = $get('funnel_protocol');
        $funnelPath       = $get('funnel_path');

        if ($funnelMode) {
            if ($funnelPort === '') {
                $errors[] = 'Funnel container port is required when Funnel is enabled.';
            } elseif ( ! self::isPort($funnelPort)) {
                $errors[] = 'Funnel container port must be a number between 1 and 65535.';
            }
            if ($funnelProtocol !== '' && ! in_array($funnelProtocol, self::FUNNEL_PROTOCOLS, true)) {
                $errors[] = 'Funnel protocol must be one of: ' . implode(', ', self::FUNNEL_PROTOCOLS) . '.';
            }

            $effectiveFunnelProtocol = $funnelProtocol === '' ? 'https' : $funnelProtocol;
            $isHttpFunnel            = in_array($effectiveFunnelProtocol, ['http', 'https'], true);
            $effectiveFunnelPublic   = $funnelPublicPort === '' ? '443' : $funnelPublicPort;

            if ($isHttpFunnel && ! in_array($effectiveFunnelPublic, self::FUNNEL_PORTS, true)) {
                $errors[] = 'Funnel public port must be 443, 8443 or 10000 for HTTP/HTTPS funnels.';
            }
            if ($funnelPath !== '' && ! str_starts_with($funnelPath, '/')) {
                $errors[] = 'Funnel path must start with "/".';
            }
            if ($funnelPath !== '' && ! $isHttpFunnel) {
                $errors[] = 'Funnel path is only supported for HTTP/HTTPS funnels.';
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        // Only non-default keys are emitted, so the result stays short enough
        // to paste into Extra Parameters and read back later.
        if ($serviceMode) {
            $labels['docktail.service.enable'] = 'true';
            $labels['docktail.service.name']   = $serviceName;
            $labels['docktail.service.port']   = $targetPort;

            if ($targetProtocol !== '' && $targetProtocol !== self::defaultTargetProtocol($targetPort)) {
                $labels['docktail.service.protocol'] = $targetProtocol;
            }
            if ($serviceProtocol !== '') {
                $labels['docktail.service.service-protocol'] = $serviceProtocol;
            }
            if ($servicePort !== '') {
                $labels['docktail.service.service-port'] = $servicePort;
            }
            if ($path !== '' && $path !== '/') {
                $labels['docktail.service.path'] = $path;
            }
            if ($proxyProtocol !== '') {
                $labels['docktail.service.proxy-protocol'] = $proxyProtocol;
            }
        }

        $description = $get('service_description');
        if ($description !== '') {
            $labels['docktail.service.description'] = $description;
        }
        // Default is direct container-IP proxying, so only an explicit "no"
        // produces a label.
        if (in_array($get('service_direct'), ['0', 'false', 'no'], true)) {
            $labels['docktail.service.direct'] = 'false';
        }
        $network = $get('service_network');
        if ($network !== '') {
            $labels['docktail.service.network'] = $network;
        }
        $tags = self::normalizeList($get('tags'));
        if ($tags !== '') {
            $labels['docktail.tags'] = $tags;
        }

        if ($funnelMode) {
            $labels['docktail.funnel.enable'] = 'true';
            $labels['docktail.funnel.port']   = $funnelPort;

            if ($funnelPublicPort !== '' && $funnelPublicPort !== '443') {
                $labels['docktail.funnel.funnel-port'] = $funnelPublicPort;
            }
            if ($funnelProtocol !== '' && $funnelProtocol !== 'https') {
                $labels['docktail.funnel.protocol'] = $funnelProtocol;
            }
            if ($funnelPath !== '' && $funnelPath !== '/') {
                $labels['docktail.funnel.path'] = $funnelPath;
            }
        }

        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = self::labelArgument($key, (string) $value);
        }

        return implode(' ', $parts);
    }

    private static function labelArgument(string $key, string $value): string
    {
        // Quote only when the value would otherwise be split by the shell.
        if (preg_match('/^[A-Za-z0-9._:\/,+-]+$/', $value) === 1) {
            return sprintf('--label %s=%s', $key, $value);
        }

        return sprintf('--label "%s=%s"', $key, str_replace(['\\', '"'], ['\\\\', '\\"'], $value));
    }

    private static function isPort(string $value): bool
    {
        return preg_match('/^\d+$/', $value) === 1 && (int) $value >= 1 && (int) $value <= 65535;
    }

    private static function defaultTargetProtocol(string $targetPort): string
    {
        return $targetPort === '443' ? 'https' : 'http';
    }

    private static function effectiveTargetProtocol(string $declared, string $targetPort): string
    {
        return $declared !== '' ? $declared : self::defaultTargetProtocol($targetPort);
    }

    /**
     * Mirrors DockTail's own defaulting so the validation below matches what
     * the daemon will actually do with these labels.
     */
    private static function effectiveServiceProtocol(string $declared, string $servicePort, string $targetProtocol): string
    {
        if ($declared !== '') {
            return $declared;
        }
        if ($targetProtocol === 'tcp' || $targetProtocol === 'tls-terminated-tcp') {
            return $targetProtocol;
        }
        if ($servicePort === '') {
            return 'http';
        }

        return $servicePort === '443' ? 'https' : 'http';
    }

    private static function normalizeList(string $value): string
    {
        $parts = array_filter(array_map('trim', explode(',', $value)), static fn (string $p): bool => $p !== '');

        return implode(',', array_unique($parts));
    }

    /** @return list<string> */
    public static function containerNames(): array
    {
        if ( ! file_exists(Status::DOCKER_SOCK) || ! file_exists(Status::DOCKER_BIN)) {
            return [];
        }

        $out = [];
        $code = 1;
        @exec(escapeshellarg(Status::DOCKER_BIN) . " ps --format '{{.Names}}' 2>/dev/null", $out, $code);

        $names = [];
        foreach ($out as $line) {
            $line = trim($line);
            if ($line !== '') {
                $names[] = $line;
            }
        }
        sort($names);

        return $names;
    }

    /**
     * Labels tab.
     */
    public static function render(): string
    {
        $token      = csrfToken();
        $containers = self::containerNames();

        ob_start(); ?>
<table class="unraid tablesorter"><thead><tr><td>Label Builder</td></tr></thead></table>

<blockquote class="inline_help">
    Unraid's container editor has no label field. Fill this in, then paste the
    generated string into <em>Docker &rarr; container &rarr; Advanced View &rarr;
    Extra Parameters &rarr; Apply</em>. This page only generates text; it never
    edits or recreates your containers.
    <br><br>
    Click any field name to read what it does. The Help button in the header toggles all of
    them at once.
</blockquote>

<form id="docktail_labels" onsubmit="docktailGenerate();return false;">
<input type="hidden" name="csrf_token" value="<?= h($token); ?>">

<dl>
    <dt>Container:</dt>
    <dd>
        <select name="container" size="1">
            <option value="">-- pick a container --</option>
<?php foreach ($containers as $name) { ?>
            <option value="<?= h($name); ?>"><?= h($name); ?></option>
<?php } ?>
        </select>
    </dd>
</dl>
<blockquote class="inline_help">
    Only used to remind you where the labels go &mdash; the generated string is
    identical for every container.
</blockquote>

<dl>
    <dt>Expose as a Tailscale Service:</dt>
    <dd>
        <select name="service_enable" size="1" class="narrow">
            <option value="1" selected>Yes</option>
            <option value="0">No</option>
        </select>
    </dd>
</dl>
<blockquote class="inline_help">
    Whether to expose this container on your tailnet as a Tailscale Service. Set this to No
    and enable Funnel below to publish a container to the public internet only.
</blockquote>

<dl>
    <dt>Service name:</dt>
    <dd><input type="text" name="service_name" placeholder="unraid-test"></dd>
</dl>
<blockquote class="inline_help">
    Becomes <code>svc:&lt;name&gt;</code> on the tailnet and is reachable at
    <code>&lt;name&gt;.&lt;tailnet&gt;.ts.net</code>.
</blockquote>

<dl>
    <dt>Container port:</dt>
    <dd><input type="text" name="service_port" placeholder="80" class="narrow"></dd>
</dl>
<blockquote class="inline_help">
    The port the application listens on inside the container.
</blockquote>

<dl>
    <dt>Container protocol:</dt>
    <dd>
        <select name="service_protocol" size="1" class="narrow">
            <option value="">default</option>
            <option value="http">http</option>
            <option value="https">https</option>
            <option value="https+insecure">https+insecure</option>
            <option value="tcp">tcp</option>
            <option value="tls-terminated-tcp">tls-terminated-tcp</option>
        </select>
    </dd>
</dl>
<blockquote class="inline_help">
    Defaults to <code>http</code>, or <code>https</code> when the container port
    is 443. Use <code>https+insecure</code> for a self-signed backend.
</blockquote>

<dl>
    <dt>Service protocol:</dt>
    <dd>
        <select name="service_service_protocol" size="1" class="narrow">
            <option value="">default</option>
            <option value="http">http</option>
            <option value="https">https</option>
            <option value="tcp">tcp</option>
            <option value="tls-terminated-tcp">tls-terminated-tcp</option>
        </select>
    </dd>
</dl>
<blockquote class="inline_help">
    The protocol Tailscale speaks to <em>clients</em>, as opposed to <em>Container protocol</em>
    above, which is what your container speaks. Leave it on default and DockTail picks
    <code>https</code> for service port 443 and <code>http</code> otherwise, or matches the
    container protocol when that is TCP.
    <br><br>
    Use <code>tls-terminated-tcp</code> to have Tailscale hold the TLS certificate and forward
    plain TCP to the container.
</blockquote>

<dl>
    <dt>Service port:</dt>
    <dd><input type="text" name="service_service_port" placeholder="443" class="narrow"></dd>
</dl>
<blockquote class="inline_help">
    Port Tailscale listens on for this Service. Defaults to 443 for
    <code>https</code>, otherwise 80.
</blockquote>

<dl>
    <dt>Service path:</dt>
    <dd><input type="text" name="service_path" placeholder="/"></dd>
</dl>
<blockquote class="inline_help">
    HTTP/HTTPS only. Defaults to <code>/</code>.
</blockquote>

<dl>
    <dt>PROXY protocol:</dt>
    <dd>
        <select name="service_proxy_protocol" size="1" class="narrow">
            <option value="">off</option>
            <option value="1">1</option>
            <option value="2">2</option>
        </select>
    </dd>
</dl>
<blockquote class="inline_help">
    TCP forwarding only. Tailscale rejects a PROXY header on HTTP/HTTPS services.
</blockquote>

<dl>
    <dt>Description:</dt>
    <dd><input type="text" name="service_description"></dd>
</dl>
<blockquote class="inline_help">
    Synced to the Tailscale admin console as the Service comment.
</blockquote>

<dl>
    <dt>Direct container IP:</dt>
    <dd>
        <select name="service_direct" size="1" class="narrow">
            <option value="1" selected>Yes</option>
            <option value="0">No</option>
        </select>
    </dd>
</dl>
<blockquote class="inline_help">
    Default. Set to No to proxy through the container's published host port
    instead &mdash; needed when the container is not on a network Unraid can route to.
</blockquote>

<dl>
    <dt>Docker network:</dt>
    <dd><input type="text" name="service_network" placeholder="bridge"></dd>
</dl>
<blockquote class="inline_help">
    Which network to take the container IP from, when the container is attached
    to more than one.
</blockquote>

<dl>
    <dt>Tags:</dt>
    <dd><input type="text" name="tags" placeholder="tag:container"></dd>
</dl>
<blockquote class="inline_help">
    Comma-separated. Overrides the default service tags from the Settings tab.
</blockquote>

<table class="unraid tablesorter"><thead><tr><td>Funnel (public internet)</td></tr></thead></table>

<dl>
    <dt>Enable Funnel:</dt>
    <dd>
        <select name="funnel_enable" size="1" class="narrow">
            <option value="0" selected>No</option>
            <option value="1">Yes</option>
        </select>
    </dd>
</dl>
<blockquote class="inline_help">
    Exposes the container to the public internet. Also requires "Allow Funnel"
    in the Tailscale plugin, otherwise it removes DockTail's Funnel entries.
</blockquote>

<dl>
    <dt>Funnel container port:</dt>
    <dd><input type="text" name="funnel_port" class="narrow"></dd>
</dl>
<blockquote class="inline_help">
    The port inside the container that Funnel traffic is proxied to. Required when Funnel is
    enabled, and independent of the Service <em>Container port</em> above, so one container can
    publish a different port to the internet than it does to the tailnet.
</blockquote>

<dl>
    <dt>Funnel public port:</dt>
    <dd>
        <select name="funnel_funnel_port" size="1" class="narrow">
            <option value="">443 (default)</option>
            <option value="8443">8443</option>
            <option value="10000">10000</option>
        </select>
    </dd>
</dl>
<blockquote class="inline_help">
    The public-facing port. Tailscale only permits 443, 8443 or 10000 for HTTP and HTTPS
    funnels; a TCP funnel may use any port.
</blockquote>

<dl>
    <dt>Funnel protocol:</dt>
    <dd>
        <select name="funnel_protocol" size="1" class="narrow">
            <option value="">https (default)</option>
            <option value="http">http</option>
            <option value="tcp">tcp</option>
            <option value="tls-terminated-tcp">tls-terminated-tcp</option>
        </select>
    </dd>
</dl>
<blockquote class="inline_help">
    The protocol Funnel serves to the internet. Defaults to <code>https</code>, which is
    almost always what you want, since Tailscale terminates TLS with a public certificate.
</blockquote>

<dl>
    <dt>Funnel path:</dt>
    <dd><input type="text" name="funnel_path" placeholder="/"></dd>
</dl>
<blockquote class="inline_help">
    HTTP/HTTPS funnels only; defaults to <code>/</code>. Setting this alongside a TCP funnel
    protocol is rejected, because a TCP forward has no URL to match a path against.
</blockquote>

<dl>
    <dt>&nbsp;</dt>
    <dd><input type="submit" value="Generate"></dd>
</dl>
</form>

<dl>
    <dt>Extra Parameters:</dt>
    <dd><textarea id="docktail_labels_out" rows="5" cols="80" readonly></textarea></dd>
</dl>
<blockquote class="inline_help">
    The generated label string. Paste it into
    <em>Docker &rarr; container &rarr; Advanced View &rarr; Extra Parameters</em>, then Apply
    &mdash; Unraid will recreate the container with the labels attached.
    <br><br>
    Only non-default keys appear here, so the string stays short enough to read back later. If
    the box fills with lines starting <code>#</code>, those are validation errors, not labels.
</blockquote>
<dl>
    <dt>&nbsp;</dt>
    <dd><input type="button" value="Copy" onclick="docktailCopy()"></dd>
</dl>

<script>
function docktailGenerate() {
    $.post('/plugins/docktail/labelgen.php', $('#docktail_labels').serialize(), function(data) {
        $('#docktail_labels_out').val(data);
    });
}

function docktailCopy() {
    var out = document.getElementById('docktail_labels_out');
    out.select();
    document.execCommand('copy');
    out.setSelectionRange(0, 0);
}
</script>
        <?php
        return (string) ob_get_clean();
    }
}

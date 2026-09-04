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

    public const TEMPLATE_DIR = '/boot/config/plugins/dockerMan/templates-user';

    /**
     * Everything the form needs about one container: the docktail.* labels it
     * already carries, the ports it exposes, and the Extra Parameters its
     * dockerMan template holds.
     *
     * @return array{found: bool, labels: array<string, string>, ports: list<string>, extraParams: string, hasTemplate: bool}
     */
    public static function containerInfo(string $name): array
    {
        $info = ['found' => false, 'labels' => [], 'ports' => [], 'extraParams' => '', 'hasTemplate' => false];

        if ($name === '' || ! file_exists(Status::DOCKER_SOCK) || ! file_exists(Status::DOCKER_BIN)) {
            return $info;
        }

        // One inspect call: labels, the ports declared by the image, and the
        // published-port map (which also covers ports published without being
        // declared EXPOSE).
        $format = '{{json .Config.Labels}}' . "\t" . '{{json .Config.ExposedPorts}}' . "\t" . '{{json .NetworkSettings.Ports}}';
        $out    = [];
        $code   = 1;
        @exec(
            escapeshellarg(Status::DOCKER_BIN) . ' inspect --format ' . escapeshellarg($format) . ' ' . escapeshellarg($name) . ' 2>/dev/null',
            $out,
            $code
        );
        if ($code !== 0 || $out === []) {
            return $info;
        }

        $parts = explode("\t", implode('', $out));
        if (count($parts) < 3) {
            return $info;
        }

        $info['found'] = true;

        $labels = json_decode($parts[0], true);
        if (is_array($labels)) {
            foreach ($labels as $key => $value) {
                if (str_starts_with((string) $key, 'docktail.')) {
                    $info['labels'][(string) $key] = (string) $value;
                }
            }
        }

        $ports = [];
        foreach ([json_decode($parts[1], true), json_decode($parts[2], true)] as $set) {
            if ( ! is_array($set)) {
                continue;
            }
            foreach (array_keys($set) as $spec) {
                // "8080/tcp" -> "8080"; UDP is not something Tailscale serves.
                [$port, $proto] = array_pad(explode('/', (string) $spec, 2), 2, 'tcp');
                if ($proto === 'tcp' && preg_match('/^\d+$/', $port) === 1) {
                    $ports[$port] = true;
                }
            }
        }
        $info['ports'] = array_map('strval', array_keys($ports));
        sort($info['ports'], SORT_NUMERIC);

        $template = self::templateFor($name);
        if ($template !== null) {
            $info['hasTemplate'] = true;
            $info['extraParams'] = $template;
        }

        return $info;
    }

    /**
     * The ExtraParams string from the container's dockerMan template, or null
     * when no template matches. Read-only: the plugin never writes these files,
     * because dockerMan only applies a template change by stopping and removing
     * the container.
     */
    private static function templateFor(string $name): ?string
    {
        foreach ((array) @glob(self::TEMPLATE_DIR . '/*.xml') as $file) {
            $xml = @simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }
            if (trim((string) ($xml->Name ?? '')) !== $name) {
                continue;
            }

            return trim(html_entity_decode((string) ($xml->ExtraParams ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }

        return null;
    }

    /**
     * Split an Extra Parameters string into tokens, keeping each token's byte
     * offsets and treating quoted spans as part of the token they belong to.
     *
     * Offsets matter: removal splices the original string, so every argument we
     * do not touch survives byte for byte - including the user's own spacing.
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    public static function tokenizeParams(string $params): array
    {
        $tokens = [];
        $len    = strlen($params);
        $i      = 0;

        while ($i < $len) {
            if (ctype_space($params[$i])) {
                $i++;
                continue;
            }

            $start = $i;
            $quote = '';
            for (; $i < $len; $i++) {
                $c = $params[$i];

                if ($quote !== '') {
                    if ($c === '\\' && $quote === '"' && $i + 1 < $len) {
                        $i++;
                        continue;
                    }
                    if ($c === $quote) {
                        $quote = '';
                    }
                    continue;
                }
                if ($c === '"' || $c === "'") {
                    $quote = $c;
                    continue;
                }
                if (ctype_space($c)) {
                    break;
                }
            }

            $tokens[] = ['text' => substr($params, $start, $i - $start), 'start' => $start, 'end' => $i];
        }

        return $tokens;
    }

    private static function dequote(string $value): string
    {
        $len = strlen($value);
        if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
            $inner = substr($value, 1, -1);

            return $value[0] === '"' ? str_replace(['\\"', '\\\\'], ['"', '\\'], $inner) : $inner;
        }

        return $value;
    }

    /**
     * Remove every `--label docktail.*` argument and leave everything else
     * untouched. Handles the two-token form, the `--label=k=v` form, and quoted
     * values containing spaces.
     */
    public static function stripDocktailLabels(string $params): string
    {
        $tokens = self::tokenizeParams($params);
        $cuts   = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $text = $tokens[$i]['text'];

            if ($text === '--label' || $text === '-l') {
                if ($i + 1 < $n && str_starts_with(self::dequote($tokens[$i + 1]['text']), 'docktail.')) {
                    $cuts[] = [$tokens[$i]['start'], $tokens[$i + 1]['end']];
                    $i++;
                }
                continue;
            }

            foreach (['--label=', '-l='] as $prefix) {
                if (str_starts_with($text, $prefix)
                    && str_starts_with(self::dequote(substr($text, strlen($prefix))), 'docktail.')) {
                    $cuts[] = [$tokens[$i]['start'], $tokens[$i]['end']];
                    break;
                }
            }
        }

        // Splice back to front so earlier offsets stay valid.
        $result = $params;
        foreach (array_reverse($cuts) as [$from, $to]) {
            $result = substr($result, 0, $from) . substr($result, $to);
        }

        // Only collapse whitespace that the removal itself created.
        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $result));
    }

    /**
     * The full value to paste into Extra Parameters: the container's existing
     * arguments with its DockTail labels replaced by the generated ones.
     */
    public static function mergeExtraParams(string $existing, string $labels): string
    {
        $kept = self::stripDocktailLabels($existing);

        if ($kept === '') {
            return $labels;
        }
        if ($labels === '') {
            return $kept;
        }

        return $kept . ' ' . $labels;
    }

    /**
     * Turn a container's existing docktail.* labels back into form values, so a
     * labelled container is edited rather than described from memory.
     *
     * @param  array<string, string> $labels
     * @return array<string, string>
     */
    public static function formValues(array $labels): array
    {
        $map = [
            'docktail.service.enable'           => 'service_enable',
            'docktail.service.name'             => 'service_name',
            'docktail.service.port'             => 'service_port',
            'docktail.service.protocol'         => 'service_protocol',
            'docktail.service.service-protocol' => 'service_service_protocol',
            'docktail.service.service-port'     => 'service_service_port',
            'docktail.service.path'             => 'service_path',
            'docktail.service.proxy-protocol'   => 'service_proxy_protocol',
            'docktail.service.description'      => 'service_description',
            'docktail.service.direct'           => 'service_direct',
            'docktail.service.network'          => 'service_network',
            'docktail.tags'                     => 'tags',
            'docktail.funnel.enable'            => 'funnel_enable',
            'docktail.funnel.port'              => 'funnel_port',
            'docktail.funnel.funnel-port'       => 'funnel_funnel_port',
            'docktail.funnel.protocol'          => 'funnel_protocol',
            'docktail.funnel.path'              => 'funnel_path',
        ];

        $values = [];
        foreach ($map as $label => $field) {
            if ( ! array_key_exists($label, $labels)) {
                continue;
            }
            $value = $labels[$label];

            // The selects are 1/0; the labels are true/false.
            if (in_array($field, ['service_enable', 'funnel_enable'], true)) {
                $value = $value === 'true' ? '1' : '0';
            } elseif ($field === 'service_direct') {
                $value = $value === 'false' ? '0' : '1';
            }

            $values[$field] = $value;
        }

        return $values;
    }

    /**
     * Every running container with its DockTail enrolment state.
     *
     * One sweep, not one `docker inspect` per row: Status::labelledContainers()
     * is memoised for the request, so this costs the same whether it is called
     * once or by every caller on the page.
     *
     * @return list<array{name: string, service: string, enrolled: bool, funnel: bool}>
     */
    public static function overview(): array
    {
        $labelled = [];
        foreach (Status::labelledContainers() as $container) {
            $labelled[$container['name']] = $container['labels'];
        }

        return self::overviewFrom(self::containerNames(), $labelled);
    }

    /**
     * @param  list<string>                       $names
     * @param  array<string, array<string,string>> $labelled
     * @return list<array{name: string, service: string, enrolled: bool, funnel: bool}>
     */
    public static function overviewFrom(array $names, array $labelled): array
    {
        $rows = [];
        foreach ($names as $name) {
            $labels  = $labelled[$name] ?? [];
            $service = $labels['docktail.service.name'] ?? '';

            $rows[] = [
                'name'     => $name,
                'service'  => $service === '' ? '' : 'svc:' . $service,
                'enrolled' => $labels !== [],
                'funnel'   => ($labels['docktail.funnel.enable'] ?? '') === 'true',
            ];
        }

        return $rows;
    }

    /**
     * Warnings - not errors - about reusing a Service name.
     *
     * Two containers advertising one Service reconcile against each other
     * indefinitely, which is confusing to diagnose from the log. Reuse is still
     * legitimate when replacing a container, so this never blocks generation.
     *
     * @return list<string>
     */
    public static function nameWarnings(string $serviceName, string $container): array
    {
        if (trim($serviceName) === '') {
            return [];
        }

        return self::nameWarningsFrom(
            $serviceName,
            $container,
            Status::labelledContainers(),
            array_keys(Status::advertisedServices()['services'])
        );
    }

    /**
     * The decision itself, over data rather than over Docker.
     *
     * @param  list<array{name: string, labels: array<string, string>}> $containers
     * @param  list<string>                                             $advertised  "svc:<name>" keys
     * @return list<string>
     */
    public static function nameWarningsFrom(string $serviceName, string $container, array $containers, array $advertised): array
    {
        $serviceName = trim($serviceName);
        if ($serviceName === '') {
            return [];
        }

        $warnings = [];
        $ownsName = false;

        foreach ($containers as $other) {
            if (($other['labels']['docktail.service.name'] ?? '') !== $serviceName) {
                continue;
            }
            if ($other['name'] === $container) {
                $ownsName = true;
                continue;
            }
            $warnings[] = 'Container ' . htmlspecialchars($other['name'], ENT_QUOTES)
                . ' already uses this service name. Two containers advertising one Service will '
                . 'fight over it, each undoing the other on every reconcile pass.';
        }

        // Advertised on this node but claimed by no labelled container: a
        // leftover, or something outside DockTail's control.
        if ($warnings === [] && ! $ownsName && in_array('svc:' . $serviceName, $advertised, true)) {
            $warnings[] = 'svc:' . htmlspecialchars($serviceName, ENT_QUOTES)
                . ' is already advertised on this node by something else. If that is a leftover, it clears on the '
                . 'next reconcile pass; if another host advertises it, the two will compete.';
        }

        return $warnings;
    }

    /**
     * Human-readable summary of a container's ports.
     *
     * A container that publishes a range (say 50000-50100) otherwise prints a
     * hundred numbers and buries the handful that matter, so consecutive runs
     * collapse and the list is capped. The full list still populates the port
     * field's dropdown.
     *
     * @param list<string> $ports
     */
    public static function summarizePorts(array $ports, int $maxGroups = 10): string
    {
        $numbers = array_values(array_unique(array_map('intval', $ports)));
        sort($numbers, SORT_NUMERIC);

        if ($numbers === []) {
            return '';
        }

        $groups = [];
        $start  = $numbers[0];
        $prev   = $numbers[0];

        foreach (array_slice($numbers, 1) as $port) {
            if ($port === $prev + 1) {
                $prev = $port;
                continue;
            }
            $groups[] = [$start, $prev];
            $start    = $port;
            $prev     = $port;
        }
        $groups[] = [$start, $prev];

        $rendered = [];
        foreach (array_slice($groups, 0, $maxGroups) as [$from, $to]) {
            if ($from === $to) {
                $rendered[] = (string) $from;
            } elseif ($to === $from + 1) {
                // A run of two reads worse as a range than as two numbers.
                $rendered[] = $from . ', ' . $to;
            } else {
                $rendered[] = $from . '-' . $to;
            }
        }

        $hidden = count($groups) - $maxGroups;
        if ($hidden > 0) {
            $rendered[] = '+' . $hidden . ' more';
        }

        return implode(', ', $rendered);
    }

    /**
     * A service name suggestion derived from the container name: Tailscale
     * Service names allow only letters, digits and hyphens.
     */
    public static function suggestName(string $containerName): string
    {
        $name = strtolower($containerName);
        $name = (string) preg_replace('/[^a-z0-9-]+/', '-', $name);
        $name = trim((string) preg_replace('/-{2,}/', '-', $name), '-');

        return $name;
    }

    /**
     * Labels tab.
     */
    public static function render(): string
    {
        $token      = csrfToken();
        $overview   = self::overview();

        ob_start(); ?>
<!-- Unraid does not load these globally; its own pages that use the switch
     control pull them in the same way (see dynamix/WG0.page). -->
<link type="text/css" rel="stylesheet" href="/webGui/styles/jquery.switchbutton.css">
<script src="/webGui/javascript/jquery.switchbutton.js"></script>

<span class="status vhshift"><input type="checkbox" class="advancedview"></span>
<table class="unraid tablesorter"><thead><tr><td>Label Builder</td></tr></thead></table>
<blockquote class="inline_help">
    Unraid's container editor has no label field, so DockTail is configured with
    container labels that go in <em>Extra Parameters</em>. Pick a container and this
    builds the whole field value for you, preserving any other arguments already set
    there.
    <br><br>
    Paste it into <em>Docker &rarr; container &rarr; Advanced View &rarr; Extra
    Parameters</em> and Apply. This page only generates text; it never edits or
    recreates your containers.
    <br><br>
    Click any field name to read what it does. Switch to <em>Advanced View</em> for the
    rest of the options.
</blockquote>

<form id="docktail_labels">
<input type="hidden" name="csrf_token" value="<?= h($token); ?>">

<dl>
    <dt>Container:</dt>
    <dd>
        <select name="container" id="docktail_container" size="1">
            <option value="">-- pick a container --</option>
<?php foreach ($overview as $row) { ?>
            <option value="<?= h($row['name']); ?>"><?= h($row['name']); ?><?= $row['enrolled'] ? ' (enrolled)' : ''; ?></option>
<?php } ?>
        </select>
        <span id="docktail_container_note" class="docktail-apply-result"></span>
    </dd>
</dl>
<blockquote class="inline_help">
    Picking a container fills in a suggested service name, lists the ports it exposes,
    and loads any <code>docktail.*</code> labels it already carries &mdash; so an
    already-enrolled container is edited rather than described again from scratch.
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
    <dd><input type="text" name="service_name" id="docktail_service_name" placeholder="unraid-test"></dd>
</dl>
<blockquote class="inline_help">
    Becomes <code>svc:&lt;name&gt;</code> on the tailnet and is reachable at
    <code>&lt;name&gt;.&lt;tailnet&gt;.ts.net</code>. Letters, digits and hyphens only.
</blockquote>

<dl>
    <dt>Container port:</dt>
    <dd>
        <input type="text" name="service_port" id="docktail_service_port" placeholder="80" class="narrow" list="docktail_ports">
        <datalist id="docktail_ports"></datalist>
        <span id="docktail_port_note" class="docktail-apply-result"></span>
    </dd>
</dl>
<blockquote class="inline_help">
    The port the application listens on inside the container. The list is populated from
    the ports the chosen container exposes or publishes.
</blockquote>

<dl>
    <dt>Enable Funnel:</dt>
    <dd>
        <select name="funnel_enable" id="docktail_funnel_enable" size="1" class="narrow">
            <option value="0" selected>No</option>
            <option value="1">Yes</option>
        </select>
    </dd>
</dl>
<blockquote class="inline_help">
    Exposes the container to the <strong>public internet</strong>, not just your tailnet.
    Also requires "Allow Funnel" in the Tailscale plugin, otherwise it removes DockTail's
    Funnel entries.
</blockquote>

<div class="advanced">

<table class="unraid tablesorter"><thead><tr><td>Service (advanced)</td></tr></thead></table>

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

<table class="unraid tablesorter"><thead><tr><td>Funnel (advanced)</td></tr></thead></table>

<dl>
    <dt>Funnel container port:</dt>
    <dd><input type="text" name="funnel_port" class="narrow" list="docktail_ports"></dd>
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

</div>
</form>

<table class="unraid tablesorter"><thead><tr><td>Extra Parameters</td></tr></thead></table>

<div id="docktail_labels_errors" class="docktail-remedy docktail-hidden"></div>
<div id="docktail_labels_warnings" class="docktail-warning docktail-hidden"></div>

<dl>
    <dt>Paste into Extra Parameters:</dt>
    <dd>
        <textarea id="docktail_labels_out" rows="5" cols="80" readonly spellcheck="false"></textarea>
        <span id="docktail_merge_note" class="docktail-apply-result"></span>
    </dd>
</dl>
<blockquote class="inline_help">
    The complete value for the container's <em>Extra Parameters</em> field: its existing
    arguments with the DockTail labels replaced. Replace the whole field with this, then
    Apply &mdash; Unraid recreates the container with the labels attached.
    <br><br>
    Only non-default label keys are emitted, so the string stays short enough to read back
    later. If the container has no dockerMan template, only the labels are shown and you
    should append them to whatever the field already contains.
</blockquote>

<dl>
    <dt>Actions:</dt>
    <dd class="docktail-inline">
        <input type="button" value="Copy" onclick="docktailCopy()">
        <input type="button" value="Remove DockTail labels" onclick="docktailStrip()">
        <span id="docktail_copy_note" class="docktail-apply-result"></span>
    </dd>
</dl>
<blockquote class="inline_help">
    <strong>Remove DockTail labels</strong> replaces the box with this container's Extra
    Parameters minus every <code>docktail.*</code> label, leaving your other arguments
    untouched &mdash; paste that back to un-enrol the container.
    <br><br>
    The Service stays advertised until DockTail's next reconcile pass notices the container
    no longer asks for it. Whether the Service <em>definition</em> is also deleted from your
    tailnet is governed by <em>Delete unused services</em> on the Settings tab.
</blockquote>

<table class="unraid tablesorter"><thead><tr><td>Enrolment</td></tr></thead></table>
<blockquote class="inline_help">
    Which running containers carry <code>docktail.*</code> labels. A container with no
    labels is simply not exposed by DockTail; nothing is wrong with it.
    <br><br>
    Pick one in <em>Container</em> above to edit its labels, or to enrol it for the
    first time.
</blockquote>

<?php if ($overview === []) { ?>
<div class="docktail-remedy">
    No running containers found. Start the array and Docker, then reload.
</div>
<?php } else { ?>
<table class="unraid tablesorter">
<thead><tr><th>Container</th><th>Service</th><th>Funnel</th><th>Enrolled</th></tr></thead>
<tbody>
<?php foreach ($overview as $row) { ?>
    <tr>
        <td><?= h($row['name']); ?></td>
        <td><?= $row['service'] === '' ? '&mdash;' : h($row['service']); ?></td>
        <td><?= $row['funnel'] ? '<span class="orange-text">yes</span>' : '&mdash;'; ?></td>
        <td><?= $row['enrolled'] ? '<span class="green-text">&#10004;</span>' : '&mdash;'; ?></td>
    </tr>
<?php } ?>
</tbody>
</table>
<?php } ?>


<script>
var docktailGenTimer = null;

/* Regenerated on every change: a Generate button meant the box could sit there
   showing a value that no longer matched the form. */
function docktailGenerate() {
    clearTimeout(docktailGenTimer);
    docktailGenTimer = setTimeout(function() {
        $.post('/plugins/docktail/labelgen.php', $('#docktail_labels').serialize() + '&action=generate', function(data) {
            var errors = $('#docktail_labels_errors');

            if (data.errors && data.errors.length) {
                // Errors are shown separately: emitting them into the output box
                // made an invalid config look pasteable.
                errors.html(data.errors.join('<br>')).removeClass('docktail-hidden');
                $('#docktail_labels_warnings').addClass('docktail-hidden').empty();
                $('#docktail_labels_out').val('');
                return;
            }

            errors.addClass('docktail-hidden').empty();

            // Warnings are advisory and must not read as failures: reusing a
            // service name is legitimate when replacing a container.
            var warnings = $('#docktail_labels_warnings');
            if (data.warnings && data.warnings.length) {
                warnings.html(data.warnings.join('<br>')).removeClass('docktail-hidden');
            } else {
                warnings.addClass('docktail-hidden').empty();
            }

            $('#docktail_labels_out').val(data.extraParams || '');
            // Written to its own element: the container picker's note reports the
            // load, and a regeneration must not wipe it.
            $('#docktail_merge_note').text(data.merged
                ? 'Existing Extra Parameters preserved.'
                : (data.hasTemplate ? '' : 'No dockerMan template found; labels only.'));
        }, 'json');
    }, 150);
}

/*
 * Pristine field values, snapshotted once so switching containers can return
 * the form to a known state. Without this, values from the previously selected
 * container survive - the visible symptom being a service name that does not
 * follow the dropdown, and the subtler one being a stale advanced value (tags,
 * direct, a path) silently emitted for the newly selected container.
 */
var docktailDefaults = {};

function docktailSnapshotDefaults() {
    $('#docktail_labels').find('input,select,textarea').each(function() {
        var name = $(this).attr('name');
        if (name && name !== 'csrf_token' && name !== 'container') {
            docktailDefaults[name] = $(this).val();
        }
    });
}

function docktailResetForm() {
    $.each(docktailDefaults, function(name, value) {
        $('#docktail_labels [name="' + name + '"]').val(value);
    });
}

function docktailLoadContainer() {
    var name = $('#docktail_container').val();
    var note = $('#docktail_container_note');

    if (!name) {
        docktailResetForm();
        $('#docktail_ports').empty();
        $('#docktail_port_note').text('');
        note.text('');
        docktailGenerate();
        return;
    }

    $.post('/plugins/docktail/labelgen.php', {
        csrf_token: $('#docktail_labels input[name="csrf_token"]').val(),
        action: 'load',
        container: name
    }, function(data) {
        var ports = data.ports || [];

        // The form always describes the container that is selected now.
        docktailResetForm();

        $('#docktail_ports').html($.map(ports, function(p) {
            return '<option value="' + p + '"></option>';
        }).join(''));
        // The summary collapses published ranges; the dropdown above still
        // offers every individual port.
        $('#docktail_port_note').text(ports.length
            ? 'Exposes: ' + (data.portSummary || ports.join(', '))
            : 'No TCP ports detected.');

        if (data.labelled) {
            // Load what the container already declares, so this is an edit.
            $.each(data.values, function(field, value) {
                var el = $('#docktail_labels [name="' + field + '"]');
                if (el.length) {
                    el.val(value);
                }
            });
            note.text('Loaded existing DockTail labels.');
        } else {
            $('#docktail_service_name').val(data.suggestName || '');

            // Only unambiguous when the container exposes exactly one port;
            // otherwise leave it for the user to pick from the dropdown.
            if (ports.length === 1) {
                $('#docktail_service_port').val(ports[0]);
            }
            note.text('');
        }

        docktailGenerate();
    }, 'json');
}

/*
 * Un-enrol: swap the output for the container's Extra Parameters with every
 * docktail.* label removed. Deliberately does not touch the form, so the
 * generated value is one click away again.
 */
function docktailStrip() {
    var container = $('#docktail_container').val();
    var note = $('#docktail_copy_note');

    if (!container) {
        note.text('Pick a container first.');
        return;
    }

    $.post('/plugins/docktail/labelgen.php', {
        csrf_token: $('#docktail_labels input[name="csrf_token"]').val(),
        action: 'strip',
        container: container
    }, function(data) {
        $('#docktail_labels_errors').addClass('docktail-hidden').empty();
        $('#docktail_labels_warnings').addClass('docktail-hidden').empty();
        $('#docktail_labels_out').val(data.extraParams || '');

        if (!data.hasTemplate) {
            note.text('No dockerMan template found; clear the docktail.* labels from Extra Parameters by hand.');
        } else if (!data.hadLabels) {
            note.text('That container carries no DockTail labels.');
        } else if (data.extraParams) {
            note.text('DockTail labels removed - paste this back to un-enrol.');
        } else {
            note.text('DockTail labels removed - Extra Parameters should now be empty.');
        }

        $('#docktail_merge_note').text('');
    }, 'json');
}

function docktailCopy() {
    var text = $('#docktail_labels_out').val();
    var note = $('#docktail_copy_note');

    if (!text) {
        note.text('Nothing to copy.');
        return;
    }

    var fallback = function() {
        var el = document.getElementById('docktail_labels_out');
        el.select();
        var ok = document.execCommand('copy');
        el.setSelectionRange(0, 0);
        note.text(ok ? 'Copied.' : 'Copy failed - select the text and copy manually.');
    };

    // execCommand is deprecated and silent; prefer the async API and keep it
    // only as a fallback for non-secure contexts.
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function() {
            note.text('Copied.');
        }, fallback);
    } else {
        fallback();
    }
    setTimeout(function() { note.text(''); }, 4000);
}

$(function() {
    // Before anything can alter them.
    docktailSnapshotDefaults();

    var advanced = $.cookie ? $.cookie('docktail_labels_view') === 'advanced' : false;

    // Fall back to a plain checkbox if the switch plugin is unavailable, rather
    // than leaving the advanced fields unreachable.
    if ($.fn.switchButton) {
        $('.advancedview').switchButton({
            labels_placement: 'left',
            on_label: 'Advanced View',
            off_label: 'Basic View',
            checked: advanced
        });
    } else {
        $('.advancedview').prop('checked', advanced);
    }

    $('.advanced').toggle(advanced);

    $('.advancedview').change(function() {
        var on = $('.advancedview').is(':checked');
        $('.advanced').toggle(on, 'slow');
        if ($.cookie) {
            $.cookie('docktail_labels_view', on ? 'advanced' : 'basic', {expires: 3650});
        }
    });

    $('#docktail_container').change(docktailLoadContainer);
    $('#docktail_labels').find('input,select,textarea').not('#docktail_container').on('input change', docktailGenerate);

    docktailGenerate();
});
</script>
        <?php
        return '<div class="docktail-help-scope">' . (string) ob_get_clean() . '</div>' . pageAssets();
    }
}

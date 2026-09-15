<?php

/*
    Copyright (C) 2026  vcolombo

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program. If not, see <https://www.gnu.org/licenses/>.
*/

namespace DockTail;

/**
 * Loaded only by status.php's explicit POST action. No reconciliation, policy
 * reads, API writes, persistent tokens, or caller-selected probe destinations.
 */
final class ConnectionCheck
{
    private const BUDGET_SECONDS = 20;
    private const MAX_BYTES = 262144;
    private float $deadline;
    private array $checks = [];

    private function __construct()
    {
        $this->deadline = self::now() + self::BUDGET_SECONDS;
    }

    /** Status belongs to each check; there is deliberately no overall "ok". */
    public static function run(string $container): array
    {
        $check = new self();
        try {
            $check->inspect($container);
        } catch (\Throwable $error) {
            // Exception messages, CLI output and response bodies can contain
            // credentials. Return only fixed, actionable text.
            $check->add('check', 'Connection check', 'unknown', 'The check could not finish.', 'Refresh the Status tab and retry.');
        }
        $check->add('client', 'Client DNS and access', 'not_verified',
            'Not verified: this host cannot prove your laptop identity, DNS answer, grants or route acceptance.',
            'On the intended client, connect to Tailscale, resolve the Service name and open its frontend port. If DNS returns NXDOMAIN, ask the tailnet administrator to check that client identity has a narrowly scoped grant to this Service; also check client DNS and route settings.');
        $check->add('application', 'Application health and license', 'not_verified',
            'An HTTP response, including HTTP 200, does not establish application health or license validity. TCP only tests opening a socket.',
            'Open the application as its intended user and check its own health and license status.');
        return [
            'container' => $container,
            'checkedAt' => gmdate('c'),
            'checks' => $check->checks,
        ];
    }

    private static function now(): float
    {
        return hrtime(true) / 1000000000;
    }

    private function remaining(float $maximum): float
    {
        return max(0, min($maximum, $this->deadline - self::now()));
    }

    private function add(string $id, string $label, string $status, string $detail, string $remedy = ''): void
    {
        $this->checks[] = compact('id', 'label', 'status', 'detail', 'remedy');
    }

    /** Fixed read-only CLI calls, with escaped arguments and bounded output/time. */
    private function command(array $args): ?array
    {
        $seconds = $this->remaining(2);
        if ($seconds < 0.05 || ! function_exists('proc_open') || ! is_executable($args[0])) {
            return null;
        }
        // exec replaces the shell so termination kills the actual Docker or
        // Tailscale process, not just a shell waiting for it. Neither spawns a
        // credential-bearing command. Discard stderr without buffering it.
        $process = @proc_open('exec ' . implode(' ', array_map('escapeshellarg', $args)), [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes);
        if ( ! is_resource($process)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        $until = self::now() + $seconds;
        $out = '';
        $code = -1;
        $complete = false;
        while (self::now() < $until) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false || strlen($out) + strlen($chunk) > self::MAX_BYTES) {
                break;
            }
            $out .= $chunk;
            $state = proc_get_status($process);
            if ( ! $state['running']) {
                $code = $state['exitcode'];
                $tail = stream_get_contents($pipes[1], self::MAX_BYTES - strlen($out) + 1);
                if ($tail !== false) {
                    $out .= $tail;
                    $complete = strlen($out) <= self::MAX_BYTES;
                }
                break;
            }
            usleep(10000);
        }
        if ( ! $complete) {
            @proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        @proc_close($process);
        if ( ! $complete || $code !== 0) {
            return null;
        }
        // Tailscale may put a version warning before the JSON object.
        $start = strpos($out, '{');
        $data = json_decode($start === false ? trim($out) : substr($out, $start), true, 64);
        if (json_last_error() !== JSON_ERROR_NONE || ($data !== null && ! is_array($data))) {
            return null;
        }
        return $data ?? [];
    }

    private function inspect(string $container): void
    {
        // Only a Docker ID from the table is accepted, never a name, URL,
        // command, Docker option, network address, or frontend supplied by POST.
        if (preg_match('/\A[a-f0-9]{12,64}\z/D', $container) !== 1) {
            $this->add('container', 'Container', 'fail', 'Invalid container ID.', 'Refresh the table and choose an existing container.');
            return;
        }
        // Do not use a full inspect (it contains environment secrets). The
        // Labels helper's found/labels/ports fields remain unchanged; here a
        // bounded inspect is necessary because its exec has no wall-time cap.
        $format = '{"id":{{json .Id}},"running":{{json .State.Running}},"labels":{{json .Config.Labels}},'
            . '"mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}},'
            . '"bindings":{{json .HostConfig.PortBindings}},"ports":{{json .NetworkSettings.Ports}},'
            . '"exposed":{{json (index .Config "ExposedPorts")}}}';
        $info = $this->command([Status::DOCKER_BIN, 'inspect', '--type', 'container', '--format', $format, $container]);
        if ($info === null || ! is_string($info['id'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $info['id']) !== 1 || ! str_starts_with($info['id'], $container)) {
            $this->add('container', 'Container', 'unknown', 'Container inspection failed, timed out, or the container was replaced.', 'Refresh the table and check Docker before retrying.');
            return;
        }
        if (($info['running'] ?? null) !== true) {
            $this->add('container', 'Container', 'fail', 'The selected container is not running.', 'Start the intended container, refresh, then check again.');
            return;
        }
        $labels = is_array($info['labels'] ?? null) ? $info['labels'] : [];
        foreach ($labels as $key => $value) {
            if ( ! str_starts_with((string) $key, 'docktail.') || ! is_string($value)) {
                unset($labels[$key]);
            }
        }
        $service = ($labels['docktail.service.enable'] ?? '') === 'true';
        $funnel = ($labels['docktail.funnel.enable'] ?? '') === 'true';
        if ( ! $service && ! $funnel) {
            $this->add('container', 'Container labels', 'fail', 'This container no longer enables a Service or Funnel.', 'Refresh the table; use Labels to inspect its current enrollment.');
            return;
        }
        $this->add('container', 'Container labels and network', 'pass', 'Read current labels and network settings from the running container.');
        $serve = $this->command([Status::TAILSCALE_BIN, 'serve', 'status', '--json']);
        $node = $this->command([Status::TAILSCALE_BIN, 'status', '--json']);
        if ($node === null || ($node['BackendState'] ?? '') !== 'Running' || ($node['Self']['Online'] ?? null) !== true) {
            $this->add('node', 'Tailscale host online', 'unknown', 'Tailscale is offline or its current online state could not be confirmed.', 'Check the Tailscale plugin connection, then retry. Local configuration can remain while offline.');
        } else {
            $this->add('node', 'Tailscale host online', 'pass', 'Local Tailscale status reports this host online. This does not test client access.');
        }

        $primary = null;
        if ($service) {
            $primary = $this->endpoint($labels, false);
            if ($primary !== null) {
                $suffix = $node['CurrentTailnet']['MagicDNSSuffix'] ?? '';
                if ($suffix === '' && is_string($node['Self']['DNSName'] ?? null)) {
                    $parts = explode('.', rtrim($node['Self']['DNSName'], '.'), 2);
                    $suffix = $parts[1] ?? '';
                }
                if (is_string($suffix) && preg_match('/\A[a-z0-9-]+\.ts\.net\z/D', $suffix) === 1) {
                    $host = substr($primary['service'], 4) . '.' . $suffix . ':' . $primary['frontendPort'];
                    $address = $primary['http'] ? $primary['frontend'] . '://' . $host . $primary['path'] : $host . ' (' . $primary['frontend'] . ')';
                    $this->add('frontend', 'Intended client endpoint', 'not_verified', $address,
                        'Open this endpoint from the intended client after checking its access grant. The address alone does not establish reachability.');
                } else {
                    $this->add('frontend', 'Intended client endpoint', 'unknown', 'The tailnet DNS suffix could not be determined.', 'Check the Service address in the Tailscale admin console.');
                }
            }
            $this->localChecks($primary, $info, $labels, $serve, false);
        }
        if ($funnel) {
            $this->localChecks($this->endpoint($labels, true), $info, $labels, $serve, true);
        }
        if ($service && $primary !== null) {
            $this->controlChecks($primary, $node);
        } elseif ($service) {
            $this->controlUnknown('The primary Service labels are invalid.', 'Correct the labels and retry the control-plane checks.');
        } else {
            $this->add('definition', 'Service definition and host readiness', 'not_applicable', 'A Funnel uses the node frontend, not a tailnet Service definition.');
        }
        foreach (array_keys($labels) as $key) {
            if (preg_match('/\Adocktail\.service\.\d+\./', $key) === 1) {
                $this->add('additional', 'Additional Service endpoints', 'not_verified',
                    'This bounded check covers the primary Service and Funnel. Indexed Service labels were also found.',
                    'Check each additional frontend and backend separately; these results do not cover them.');
                break;
            }
        }
    }

    private static function port($value): ?int
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/\A[0-9]{1,5}\z/D', (string) $value) !== 1) {
            return null;
        }
        $port = (int) $value;
        return $port > 0 && $port <= 65535 ? $port : null;
    }

    /** Mirrors docker.resolveProtocols and Funnel defaults in the pinned core. */
    private function endpoint(array $labels, bool $funnel): ?array
    {
        $prefix = $funnel ? 'docktail.funnel.' : 'docktail.service.';
        $backendPort = self::port($labels[$prefix . 'port'] ?? '');
        $backend = $labels['docktail.service.protocol'] ?? '';
        $backend = $backend === '' ? ($backendPort === 443 ? 'https' : 'http') : $backend;
        $frontend = $labels[$prefix . ($funnel ? 'protocol' : 'service-protocol')] ?? '';
        $port = $labels[$prefix . ($funnel ? 'funnel-port' : 'service-port')] ?? '';
        if ($funnel) {
            $frontend = $frontend === '' ? 'https' : $frontend;
            $port = $port === '' ? '443' : $port;
            $backend = in_array($frontend, ['tcp', 'tls-terminated-tcp'], true) ? 'tcp' : 'http';
        } else {
            if ($port === '' && $frontend === '') {
                $port = '80';
                $frontend = in_array($backend, ['tcp', 'tls-terminated-tcp'], true) ? $backend : 'http';
            } elseif ($port === '') {
                $port = $frontend === 'https' ? '443' : '80';
            } elseif ($frontend === '') {
                $frontend = in_array($backend, ['tcp', 'tls-terminated-tcp'], true) ? $backend : ($port === '443' ? 'https' : 'http');
            }
        }
        $frontendPort = self::port($port);
        $name = $labels['docktail.service.name'] ?? '';
        $name = str_starts_with($name, 'svc:') ? substr($name, 4) : $name;
        $http = in_array($frontend, ['http', 'https'], true);
        $path = trim($labels[$prefix . 'path'] ?? '');
        $path = $path === '' ? '/' : $path;
        $proxy = $funnel ? '' : ($labels['docktail.service.proxy-protocol'] ?? '');
        if ($backendPort === null || $frontendPort === null
            || ! in_array($backend, Labels::TARGET_PROTOCOLS, true)
            || ! in_array($frontend, Labels::SERVICE_PROTOCOLS, true)
            || (! $funnel && preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $name) !== 1)
            || strlen($path) > 1024 || ! str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7f?#]/', $path)
            || (! $http && array_key_exists($prefix . 'path', $labels))
            || ($proxy !== '' && ($http || ! in_array($proxy, ['1', '2'], true)))
            || ($funnel && $http && ! in_array((string) $frontendPort, Labels::FUNNEL_PORTS, true))) {
            $this->add($funnel ? 'funnel-labels' : 'service-labels', $funnel ? 'Funnel labels' : 'Service labels', 'fail',
                'Required port, protocol, name, path or PROXY protocol labels are invalid or unsupported.', 'Review this container on the Labels tab, apply the intended values, then retry.');
            return null;
        }
        return compact('backendPort', 'backend', 'frontend', 'frontendPort', 'http', 'path', 'proxy') + ['service' => 'svc:' . $name];
    }

    /** Derive only a literal container IP or the core's host-loopback target. */
    private function target(array $endpoint, array $info, array $labels): ?array
    {
        $port = $endpoint['backendPort'];
        $ip = null;
        if (($info['mode'] ?? '') === 'host') {
            $ip = '127.0.0.1';
        } elseif (($labels['docktail.service.direct'] ?? '') === 'false') {
            $key = $port . '/tcp';
            $binding = $info['bindings'][$key][0]['HostPort'] ?? '';
            $binding = $binding === '' ? ($info['ports'][$key][0]['HostPort'] ?? '') : $binding;
            $port = self::port($binding);
            $ip = '127.0.0.1';
        } elseif (($info['mode'] ?? '') !== 'none') {
            $networks = is_array($info['networks'] ?? null) ? $info['networks'] : [];
            $network = $labels['docktail.service.network'] ?? '';
            if ($network !== '') {
                if (isset($networks[$network])) {
                    $ip = $networks[$network]['IPAddress'] ?? null;
                } else {
                    $matches = [];
                    foreach ($networks as $name => $entry) {
                        if (str_ends_with((string) $name, '_' . $network)) {
                            $matches[] = $entry['IPAddress'] ?? '';
                        }
                    }
                    // The core's map iteration can pick either suffix match.
                    // Never guess which one a live Serve entry should use.
                    $ip = count($matches) === 1 ? $matches[0] : null;
                }
            } else {
                ksort($networks, SORT_STRING);
                $ip = $networks['bridge']['IPAddress'] ?? '';
                if ($ip === '') {
                    foreach ($networks as $entry) {
                        if (($entry['IPAddress'] ?? '') !== '') {
                            $ip = $entry['IPAddress'];
                            break;
                        }
                    }
                }
            }
        }
        if ( ! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $port === null) {
            return null;
        }
        return ['host' => $ip, 'port' => $port, 'protocol' => $endpoint['http'] ? $endpoint['backend'] : 'tcp'];
    }

    /** Never display raw Serve URLs, which may contain credentials or tokens. */
    private static function proxyTarget($value, bool $http): ?array
    {
        if ( ! is_string($value) || strlen($value) > 1024 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            return null;
        }
        if ( ! str_contains($value, '://')) {
            $value = ($http ? 'http' : 'tcp') . '://' . $value;
        }
        $url = parse_url($value);
        if ( ! is_array($url) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || ! in_array($url['path'] ?? '', ['', '/'], true)) {
            return null;
        }
        $protocol = $url['scheme'] ?? '';
        if ( ! in_array($protocol, $http ? ['http', 'https', 'https+insecure'] : ['tcp', 'tls-terminated-tcp'], true)) {
            return null;
        }
        $host = $url['host'] ?? '';
        $host = $host === 'localhost' ? '127.0.0.1' : $host;
        $port = self::port($url['port'] ?? ($protocol === 'http' ? 80 : ($http ? 443 : 0)));
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $port === null) {
            return null;
        }
        return ['host' => $host, 'port' => $port, 'protocol' => $http ? $protocol : 'tcp'];
    }

    private static function address(array $target): string
    {
        return $target['protocol'] . '://' . $target['host'] . ':' . $target['port'];
    }

    private function localChecks(?array $endpoint, array $info, array $labels, ?array $serve, bool $funnel): void
    {
        $id = $funnel ? 'funnel' : 'service';
        $title = $funnel ? 'Funnel' : 'Service';
        if ($endpoint === null) {
            $this->add($id . '-local', $title . ' local proxy config', 'unknown', 'The labels do not identify a valid frontend to compare.', 'Correct the labels and retry.');
            $this->add($id . '-backend', $title . ' backend', 'unknown', 'No validated backend target is available.', 'Correct the labels before probing.');
            return;
        }
        $target = $this->target($endpoint, $info, $labels);
        $this->add($id . '-target', $title . ' backend target', $target === null ? 'unknown' : 'pass',
            $target === null ? 'Could not derive an unambiguous IPv4 target from current labels and Docker networking.' : self::address($target) . ' derived from current labels and Docker networking.',
            $target === null ? 'Check the selected network or published TCP port. Host networking uses 127.0.0.1; a container with several matching networks needs an explicit network.' : '');
        $port = $endpoint['frontendPort'];
        $config = $funnel ? $serve : ($serve['Services'][$endpoint['service']] ?? null);
        $handler = is_array($config) ? ($config['TCP'][$port] ?? null) : null;
        $actual = null;
        $matches = false;
        if ($serve === null || (isset($serve['Services']) && ! is_array($serve['Services']))) {
            $this->add($id . '-local', $title . ' local proxy config', 'unknown', 'Local Serve configuration could not be read within its time/data limit.', 'Check tailscaled and retry.');
        } elseif ( ! is_array($handler)) {
            $this->add($id . '-local', $title . ' local proxy config', 'fail', 'No local frontend on port ' . $port . ' was found for this endpoint.', 'Check the labels and DockTail log; allow the next reconciliation to run.');
        } else {
            $protocol = ! empty($handler['TerminateTLS']) ? 'tls-terminated-tcp'
                : (($handler['HTTPS'] ?? false) === true ? 'https' : (($handler['HTTP'] ?? false) === true ? 'http' : 'tcp'));
            $destination = $handler['TCPForward'] ?? null;
            $paths = [];
            $allowed = ! $funnel;
            if ($endpoint['http']) {
                foreach (($config['Web'] ?? []) as $hostPort => $web) {
                    if ( ! str_ends_with((string) $hostPort, ':' . $port)) {
                        continue;
                    }
                    if ($funnel && ($serve['AllowFunnel'][$hostPort] ?? null) !== true) {
                        continue;
                    }
                    if (isset($web['Handlers'][$endpoint['path']]['Proxy'])) {
                        $paths[] = $web['Handlers'][$endpoint['path']]['Proxy'];
                        $allowed = true;
                    }
                }
                $destination = count($paths) === 1 ? $paths[0] : null;
            } elseif ($funnel) {
                foreach (($serve['AllowFunnel'] ?? []) as $hostPort => $enabled) {
                    if ($enabled === true && str_ends_with((string) $hostPort, ':' . $port)) {
                        $allowed = true;
                    }
                }
            }
            $actual = self::proxyTarget($destination, $endpoint['http']);
            $matches = $target !== null && $actual === $target && $protocol === $endpoint['frontend'] && $allowed
                && (int) ($handler['ProxyProtocol'] ?? 0) === (int) $endpoint['proxy'];
            $this->add($id . '-local', $title . ' local proxy config', $matches ? 'pass' : 'fail',
                $matches ? 'Local frontend ' . $protocol . ':' . $port . ' and backend match this container. Approval and client access are separate checks.'
                    : 'Local frontend or backend does not match the labels/network, or its handler cannot be safely identified.'
                        . ($actual === null ? '' : ' Local backend: ' . self::address($actual) . '.')
                        . ($target === null ? '' : ' Expected backend: ' . self::address($target) . '.'),
                $matches ? '' : 'Review container/backend port versus frontend port, protocol and path on Labels. Check the next reconciliation; do not change the shared Service definition to compensate for a wrong backend.');
        }
        // Probe a configured backend only after confining it to this container.
        // If local config differs, the separately labelled desired-target probe
        // can still distinguish a bad backend label from frontend/API problems.
        if ($target !== null) {
            $this->probe($id . '-backend', $title . ($matches ? ' configured backend' : ' label-derived backend (local match unverified)'), $target);
        } else {
            $this->add($id . '-backend', $title . ' backend', 'unknown', 'No confined target could be derived; no probe was sent.', 'Resolve the network or port mapping and retry.');
        }
        if ( ! $matches) {
            $this->add($id . '-configured-probe', $title . ' configured backend probe', 'not_verified',
                'The configured backend was not independently probed because a matching container target was not established.', 'Resolve the local-config result and check again.');
        }
        $declared = isset($info['exposed'][$endpoint['backendPort'] . '/tcp']) || array_key_exists($endpoint['backendPort'] . '/tcp', $info['ports'] ?? []);
        if ( ! $declared && ($info['mode'] ?? '') !== 'host') {
            $this->add($id . '-port-hint', $title . ' container port declaration', 'warning',
                'The labelled backend TCP port is not declared or published by Docker. This is advisory: EXPOSE is not required for an application to listen.',
                'Confirm the application actually listens on the labelled container port. A local Serve entry can exist even when that backend port is wrong.');
        }
    }

    private function probe(string $id, string $label, array $target): void
    {
        if ($target['protocol'] === 'tcp') {
            $seconds = $this->remaining(1.5);
            if ($seconds < 0.05) {
                $this->add($id, $label, 'unknown', 'The action time budget was exhausted.', 'Retry the check.');
                return;
            }
            $socket = @stream_socket_client('tcp://' . $target['host'] . ':' . $target['port'], $errno, $error, $seconds, STREAM_CLIENT_CONNECT);
            if ($socket === false) {
                $this->add($id, $label, 'fail', 'TCP connection refused or timed out from this host.', 'Confirm the backend port and Docker network, and that the application is listening.');
            } else {
                fclose($socket);
                $this->add($id, $label, 'pass', 'TCP connection opened from this host. HTTP, TLS, PROXY protocol negotiation and application health were not tested.');
            }
            return;
        }
        $insecure = $target['protocol'] === 'https+insecure';
        $url = ($insecure ? 'https' : $target['protocol']) . '://' . $target['host'] . ':' . $target['port'] . '/';
        $response = $this->request($url, [], null, true, $insecure);
        if ($response['error'] !== '') {
            $this->add($id, $label, $response['error'] === 'transport' ? 'fail' : 'unknown',
                $response['error'] === 'transport' ? 'No HTTP response: connection, timeout or TLS verification failure.' : 'HTTP probe unavailable or exceeded its time/data limit.',
                'Confirm the application backend port/protocol and Docker network. For HTTPS, check its certificate configuration.');
            return;
        }
        $code = $response['code'];
        $tlsNote = $insecure ? ' Backend certificate verification was skipped because the label uses https+insecure.' : '';
        if ($code === 401 || $code === 403) {
            $this->add($id, $label, 'warning', 'HTTP ' . $code . ': reachable from this host; authentication or authorization is required.' . $tlsNote,
                'Sign in with the intended application account. This is not a transport failure.');
        } elseif ($code >= 200 && $code < 300) {
            $this->add($id, $label, 'pass', 'HTTP ' . $code . ' from the backend root on this host.' . $tlsNote . ' Application health, license and client access remain unverified.');
        } elseif ($code >= 300 && $code < 400) {
            $this->add($id, $label, 'warning', 'HTTP ' . $code . ': backend reachable; redirect was not followed.' . $tlsNote, 'Check the application redirect/base URL from the intended client.');
        } else {
            $this->add($id, $label, 'warning', 'HTTP ' . $code . ': backend reachable, but the root request returned an application error.' . $tlsNote,
                'Check the application route and logs. An HTTP error is distinct from a failed connection.');
        }
    }

    /**
     * Bounded in-process HTTP. The only POST is to the fixed OAuth token URL;
     * every service/hosts request is GET. There is no arbitrary-method option.
     * Bodies/errors/headers never leave this class. Backend bodies are discarded.
     */
    private function request(string $url, array $headers = [], ?string $tokenForm = null, bool $backend = false, bool $insecure = false): array
    {
        $out = ['code' => 0, 'data' => null, 'error' => 'unavailable'];
        $seconds = $this->remaining($backend ? 2 : 3);
        if ($seconds < 0.05 || ! function_exists('curl_init')) {
            return $out;
        }
        // With NOSIGNAL, a synchronous resolver can exceed curl's timeout.
        // Fixed API hostnames require asynchronous DNS to honor this action's
        // wall-time budget. Backend targets are literal IPs and need no DNS.
        if ( ! $backend && (((int) (curl_version()['features'] ?? 0)) & CURL_VERSION_ASYNCHDNS) === 0) {
            return $out;
        }
        if ($tokenForm !== null && $url !== 'https://api.tailscale.com/api/v2/oauth/token') {
            return $out;
        }
        $curl = curl_init($url);
        if ($curl === false) {
            return $out;
        }
        $body = '';
        $bytes = 0;
        $headerBytes = 0;
        $limited = false;
        $headerLimited = false;
        $headerCode = 0;
        $backendHeadersComplete = false;
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => $backend ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => min(1000, (int) ceil($seconds * 1000)),
            CURLOPT_TIMEOUT_MS => (int) ceil($seconds * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROXY => '',
            CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_SSL_VERIFYPEER => ! ($backend && $insecure),
            CURLOPT_SSL_VERIFYHOST => $backend && $insecure ? 0 : 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headerBytes, &$headerLimited, &$headerCode, &$backendHeadersComplete, $backend): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 16384) {
                    $headerLimited = true;
                    return 0;
                }
                if (preg_match('/\AHTTP\/\S+ ([0-9]{3})(?:[ \r\n])/', $line, $match) === 1) {
                    $headerCode = (int) $match[1];
                }
                if ($backend && trim($line) === '' && $headerCode >= 200) {
                    // A complete HTTP response header proves HTTP reachability.
                    // Stop before the body, so a login page or streaming body
                    // cannot turn a known 401/403 into a transport failure.
                    $backendHeadersComplete = true;
                    return 0;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$bytes, &$limited, $backend): int {
                $bytes += strlen($chunk);
                if ($bytes > ($backend ? 1024 : self::MAX_BYTES)) {
                    $limited = true;
                    return 0;
                }
                if ( ! $backend) {
                    $body .= $chunk;
                }
                return strlen($chunk);
            },
        ];
        if ($tokenForm !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $tokenForm;
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }
        try {
            if ( ! curl_setopt_array($curl, $options)) {
                return $out;
            }
            $result = @curl_exec($curl);
            $out['code'] = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($headerLimited || ($limited && ! $backend)) {
                $out['error'] = 'limit';
            } elseif ($result === false && ! ($backend && $backendHeadersComplete && $out['code'] >= 200)) {
                $out['error'] = 'transport';
            } elseif ($out['code'] < 200) {
                $out['error'] = 'transport';
            } else {
                $out['error'] = '';
                if ( ! $backend && $out['code'] === 200) {
                    $out['data'] = json_decode($body, true, 64);
                    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($out['data'])) {
                        $out['error'] = 'invalid';
                    }
                }
            }
            return $out;
        } finally {
            curl_close($curl);
        }
    }

    private static function unavailable(array $response): string
    {
        if ($response['code'] === 403) {
            return 'API returned HTTP 403: the configured credential cannot read this resource. Result unknown.';
        }
        if ($response['code'] === 401) {
            return 'API returned HTTP 401: the configured credential was rejected. Result unknown.';
        }
        if ($response['error'] !== '') {
            return 'API read unavailable, timed out, exceeded its data limit, or returned invalid data. Result unknown.';
        }
        return 'API returned HTTP ' . $response['code'] . '. Result unknown.';
    }

    private function controlChecks(array $endpoint, ?array $node): void
    {
        $cfg = Config::read();
        $token = '';
        $reason = '';
        $remedy = 'Use the Tailscale admin console to inspect this Service with an authorized account. This check does not request broader scopes or change policy.';
        try {
            if (($cfg['TAILSCALE_OAUTH_CLIENT_ID'] ?? '') !== '' && ($cfg['TAILSCALE_OAUTH_CLIENT_SECRET'] ?? '') !== '') {
                // Same preference and grant as the core client: no scope field,
                // no extra privileges, no refresh/persistent token cache.
                $response = $this->request('https://api.tailscale.com/api/v2/oauth/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
                    'grant_type' => 'client_credentials',
                    'client_id' => $cfg['TAILSCALE_OAUTH_CLIENT_ID'],
                    'client_secret' => $cfg['TAILSCALE_OAUTH_CLIENT_SECRET'],
                ], '', '&', PHP_QUERY_RFC3986));
                if ($response['error'] === '' && $response['code'] === 200 && is_string($response['data']['access_token'] ?? null)
                    && strtolower((string) ($response['data']['token_type'] ?? '')) === 'bearer') {
                    $token = $response['data']['access_token'];
                } else {
                    $reason = 'OAuth token unavailable. ' . self::unavailable($response);
                }
                unset($response);
            } else {
                $token = $cfg['TAILSCALE_API_KEY'] ?? '';
            }
            if ($token === '' || strlen($token) > 8192 || preg_match('/[\x00-\x20\x7f]/', $token)) {
                $reason = $reason !== '' ? $reason : 'No usable configured OAuth/API credential is available. Control-plane state is unknown.';
                $this->controlUnknown($reason, $remedy);
                return;
            }
            $tailnet = $cfg['TAILSCALE_TAILNET'] ?? '-';
            $tailnet = $tailnet === '' ? '-' : $tailnet;
            if (strlen($tailnet) > 253 || preg_match('/[\x00-\x20\x7f]/', $tailnet)) {
                $this->controlUnknown('The configured tailnet is invalid; control-plane state is unknown.', 'Review Tailnet on Settings.');
                return;
            }
            // Contracts: upstream docktail/tailscale/client.go getService and
            // listServiceHosts. Service ports are tcp:<frontend>, never the
            // backend port; host identity is nodeId == status.Self.ID.
            $url = 'https://api.tailscale.com/api/v2/tailnet/' . rawurlencode($tailnet)
                . '/services/' . rawurlencode($endpoint['service']);
            $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
            $definition = $this->request($url, $headers);
            $this->definitionCheck($definition, $endpoint);
            // Read independently even if the definition GET is forbidden.
            $hosts = $this->request($url . '/devices', $headers);
            $this->hostChecks($hosts, $node);
        } finally {
            // Request-local only; never send credentials, tokens or response
            // bodies to the browser, a subprocess, a file or a log.
            unset($token, $cfg, $headers, $definition, $hosts);
        }
    }

    private function controlUnknown(string $reason, string $remedy): void
    {
        $this->add('definition', 'Service definition frontend ports', 'unknown', $reason, $remedy);
        $this->add('approval', 'Service host approval', 'unknown', $reason, $remedy);
        $this->add('readiness', 'Service host readiness', 'unknown', $reason, $remedy);
    }

    private function definitionCheck(array $response, array $endpoint): void
    {
        $remedy = 'Review the Service definition in the Tailscale admin console with its owner. It is tailnet-global and can serve other hosts; this check never changes it.';
        if ($response['code'] === 404 && $response['error'] === '') {
            $this->add('definition', 'Service definition frontend ports', 'fail', 'No Service definition was found in the configured tailnet (HTTP 404).', $remedy);
            return;
        }
        if ($response['error'] !== '' || $response['code'] !== 200
            || ($response['data']['name'] ?? '') !== $endpoint['service'] || ! is_array($response['data']['ports'] ?? null)) {
            $this->add('definition', 'Service definition frontend ports', 'unknown', self::unavailable($response), $remedy);
            return;
        }
        $ports = $response['data']['ports'];
        $covered = false;
        $unrecognized = false;
        $safePorts = [];
        foreach ($ports as $spec) {
            if ( ! is_string($spec) || preg_match('/\A(tcp|udp):([0-9]{1,5})(?:-([0-9]{1,5}))?\z/D', $spec, $m) !== 1
                || self::port($m[2]) === null || self::port($m[3] ?? $m[2]) === null || (int) ($m[3] ?? $m[2]) < (int) $m[2]) {
                $unrecognized = true;
                continue;
            }
            if (count($safePorts) < 16) {
                $safePorts[] = $spec;
            }
            if ($m[1] === 'tcp' && $endpoint['frontendPort'] >= (int) $m[2] && $endpoint['frontendPort'] <= (int) ($m[3] ?? $m[2])) {
                $covered = true;
            }
        }
        $expected = 'tcp:' . $endpoint['frontendPort'];
        $status = $covered ? 'pass' : ($unrecognized ? 'unknown' : 'fail');
        $this->add('definition', 'Service definition frontend ports', $status,
            $covered ? 'The shared definition includes frontend ' . $expected . '. Extra ports may serve other hosts; this does not prove host readiness.'
                : ($unrecognized ? 'The definition contains unrecognized port syntax; coverage of ' . $expected . ' is unknown.'
                    : 'Frontend mismatch: this container requires ' . $expected . ', but the definition lists ' . ($safePorts === [] ? 'no ports' : implode(', ', $safePorts)) . '.'),
            $covered ? '' : $remedy . ' Compare frontend ports, not the application backend port.');
    }

    private function hostChecks(array $response, ?array $node): void
    {
        $remedy = 'Inspect this Unraid host under the Service in the Tailscale admin console. Approval and correct frontend configuration are separate requirements.';
        if ($response['error'] !== '' || $response['code'] !== 200 || ! is_array($response['data']['hosts'] ?? null)) {
            $reason = self::unavailable($response);
            $this->add('approval', 'Service host approval', 'unknown', $reason, $remedy);
            $this->add('readiness', 'Service host readiness', 'unknown', $reason, $remedy);
            return;
        }
        $nodeID = $node['Self']['ID'] ?? null;
        if ( ! is_string($nodeID) || $nodeID === '') {
            $this->add('approval', 'Service host approval', 'unknown', 'Local node identity is unavailable; API hosts cannot be matched safely.', $remedy);
            $this->add('readiness', 'Service host readiness', 'unknown', 'Local node identity is unavailable.', $remedy);
            return;
        }
        $matches = [];
        foreach ($response['data']['hosts'] as $host) {
            if (is_array($host) && ($host['nodeId'] ?? null) === $nodeID) {
                $matches[] = $host;
            }
        }
        if (count($matches) !== 1) {
            $reason = $matches === [] ? 'No matching nodeId was found in the Service hosts response.' : 'The API returned ambiguous host records.';
            $this->add('approval', 'Service host approval', 'unknown', $reason, $remedy);
            $this->add('readiness', 'Service host readiness', 'unknown', $reason, $remedy);
            return;
        }
        $approval = $matches[0]['approvalLevel'] ?? null;
        $configured = $matches[0]['configured'] ?? null;
        // Exact known states only: never substring-match "approved" within
        // "unapproved" or infer readiness from approval alone.
        $approved = in_array($approval, ['approved:manual', 'approved:auto'], true);
        $pending = in_array($approval, ['pending', 'pending-approval', 'unapproved', 'not-approved'], true);
        $this->add('approval', 'Service host approval', $approved ? 'pass' : ($pending ? 'fail' : 'unknown'),
            $approved ? 'The API reports this host ' . $approval . '. Readiness is checked separately.'
                : ($pending ? 'This Service host is not approved.' : 'The API approvalLevel is missing or unrecognized; approval is unknown.'),
            $approved ? '' : $remedy);
        $online = ($node['BackendState'] ?? '') === 'Running' && ($node['Self']['Online'] ?? null) === true;
        if (is_string($configured) && ($configured === 'partially-configured' || str_starts_with($configured, 'partially-configured:'))) {
            $this->add('readiness', 'Service host readiness', 'fail', 'The API reports partially-configured, even if host approval has passed.',
                'Compare the shared Service frontend ports with this host Serve config. Fix the backend separately. Coordinate any definition change with the Service owner.');
        } elseif ($configured === 'ready' && $approved && $online) {
            $this->add('readiness', 'Service host readiness', 'pass', 'The API reports ready and approved, and local Tailscale status reports online. Client reachability remains unverified.');
        } elseif (in_array($configured, ['not-configured', 'unconfigured', 'draining'], true)) {
            $this->add('readiness', 'Service host readiness', 'fail', 'The API reports the host is not ready for new Service connections.', $remedy);
        } else {
            $this->add('readiness', 'Service host readiness', 'unknown',
                ! $online ? 'The host is offline or its online state is unknown; a cached API ready value is not sufficient.'
                    : 'Readiness cannot be confirmed: configured is missing/unrecognized or approval has not been established.', $remedy);
        }
    }
}

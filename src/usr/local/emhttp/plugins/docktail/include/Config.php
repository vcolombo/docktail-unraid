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
 * Settings live in two files. Everything except ENABLE_DOCKTAIL maps 1:1 onto
 * the DockTail environment variable of the same name; ENABLE_DOCKTAIL is
 * plugin-local and only gates the rc script.
 *
 * Credentials are kept in a separate 0600 file that the install script excludes
 * from Unraid Connect's flash backup, so tailnet credentials never leave the
 * server.
 */
final class Config
{
    public const SETTINGS_FILE    = CONFIG_DIR . '/docktail.cfg';
    public const CREDENTIALS_FILE = CONFIG_DIR . '/credentials.cfg';

    /** @var list<string> */
    public const SETTING_KEYS = [
        'ENABLE_DOCKTAIL',
        'TAILSCALE_TAILNET',
        'DEFAULT_SERVICE_TAGS',
        'IGNORE_SERVICE_NAMES',
        'DELETE_UNUSED_SERVICES',
        'SKIP_SHUTDOWN_CLEANUP',
        'RECONCILE_INTERVAL',
        'LOG_LEVEL',
    ];

    /** @var list<string> */
    public const SECRET_KEYS = [
        'TAILSCALE_OAUTH_CLIENT_ID',
        'TAILSCALE_OAUTH_CLIENT_SECRET',
        'TAILSCALE_API_KEY',
        'DOCKTAIL_CLOUD_KEY',
    ];

    public const LOG_LEVELS = ['debug', 'info', 'warn', 'error'];

    /**
     * Shipped defaults merged under the user's settings, plus the credentials.
     *
     * @return array<string, string>
     */
    public static function read(): array
    {
        $values = self::defaults();

        // parse_plugin_cfg() is only defined when Unraid's webGUI helpers are
        // loaded (i.e. on a .page render), so the endpoints get a plain merge.
        if (function_exists('parse_plugin_cfg')) {
            $merged = \parse_plugin_cfg(PLUGIN_NAME);
            if (is_array($merged)) {
                $values = array_merge($values, array_map('strval', $merged));
            }
        } else {
            $values = array_merge($values, self::readFile(self::SETTINGS_FILE));
        }

        $values = array_merge($values, self::readFile(self::CREDENTIALS_FILE));

        foreach (self::SECRET_KEYS as $key) {
            $values[$key] ??= '';
        }

        return $values;
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return self::readFile(PLUGIN_ROOT . '/default.cfg');
    }

    /** @return array<string, string> */
    private static function readFile(string $file): array
    {
        if ( ! is_file($file)) {
            return [];
        }
        $parsed = @parse_ini_file($file);

        return is_array($parsed) ? array_map('strval', $parsed) : [];
    }

    /**
     * Persist both files. Written to a temporary file and renamed, so a
     * concurrent rc.docktail start never sources a half-written config.
     *
     * @param array<string, string> $settings
     * @param array<string, string> $secrets
     */
    public static function write(array $settings, array $secrets): bool
    {
        if ( ! is_dir(CONFIG_DIR) && ! @mkdir(CONFIG_DIR, 0755, true)) {
            return false;
        }

        $ok = self::writeFile(self::SETTINGS_FILE, $settings, 0644);
        $ok = self::writeFile(self::CREDENTIALS_FILE, $secrets, 0600) && $ok;

        return $ok;
    }

    /** @param array<string, string> $values */
    private static function writeFile(string $file, array $values, int $mode): bool
    {
        $body = '';
        foreach ($values as $key => $value) {
            $key = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string) $key));
            if ($key === '' || $key === null) {
                continue;
            }
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
            $body .= sprintf("%s=\"%s\"\n", $key, $escaped);
        }

        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $body) === false) {
            return false;
        }
        @chmod($tmp, $mode);
        if ( ! @rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        @chmod($file, $mode);

        return true;
    }

    /**
     * Coerce the settings half of a POST. Every field is constrained to a value
     * DockTail actually accepts, so a bad form submission can never produce a
     * daemon that crash-loops on startup.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, string>
     */
    public static function coerceSettings(array $post): array
    {
        $current = self::read();
        $out     = [];

        foreach (['ENABLE_DOCKTAIL', 'DELETE_UNUSED_SERVICES', 'SKIP_SHUTDOWN_CLEANUP'] as $key) {
            $raw = (string) ($post[$key] ?? $current[$key] ?? '0');
            // Exported verbatim: DockTail parses these with strconv.ParseBool,
            // which accepts "1" and "0".
            $out[$key] = $raw === '1' ? '1' : '0';
        }

        $tailnet                  = trim((string) ($post['TAILSCALE_TAILNET'] ?? ''));
        $out['TAILSCALE_TAILNET'] = $tailnet === '' ? '-' : $tailnet;

        $out['DEFAULT_SERVICE_TAGS'] = self::normalizeList((string) ($post['DEFAULT_SERVICE_TAGS'] ?? ''));
        if ($out['DEFAULT_SERVICE_TAGS'] === '') {
            $out['DEFAULT_SERVICE_TAGS'] = 'tag:container';
        }
        $out['IGNORE_SERVICE_NAMES'] = self::normalizeList((string) ($post['IGNORE_SERVICE_NAMES'] ?? ''));

        $interval = trim((string) ($post['RECONCILE_INTERVAL'] ?? ''));
        $out['RECONCILE_INTERVAL'] = preg_match('/^\d+(ms|s|m|h)$/', $interval) === 1 ? $interval : '60s';

        $level             = strtolower(trim((string) ($post['LOG_LEVEL'] ?? '')));
        $out['LOG_LEVEL']  = in_array($level, self::LOG_LEVELS, true) ? $level : 'info';

        return $out;
    }

    /**
     * Credentials are taken verbatim after trim(): they are opaque tokens and
     * any normalisation beyond whitespace would corrupt them.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, string>
     */
    public static function coerceSecrets(array $post): array
    {
        $out = [];
        foreach (self::SECRET_KEYS as $key) {
            $out[$key] = trim((string) ($post[$key] ?? ''));
        }

        return $out;
    }

    private static function normalizeList(string $value): string
    {
        $parts = array_filter(array_map('trim', explode(',', $value)), static fn (string $p): bool => $p !== '');

        return implode(',', array_unique($parts));
    }

    /**
     * Settings tab.
     */
    public static function render(): string
    {
        $cfg   = self::read();
        $token = csrfToken();

        $select = static function (string $name, array $options, string $selected, string $class = 'narrow'): string {
            $html = sprintf("<select name='%s' size='1' class='%s'>", h($name), h($class));
            foreach ($options as $value => $label) {
                $html .= sprintf(
                    "<option value='%s'%s>%s</option>",
                    h((string) $value),
                    ((string) $value === $selected ? ' selected' : ''),
                    h($label)
                );
            }

            return $html . '</select>';
        };

        $yesNo = ['1' => 'Yes', '0' => 'No'];

        ob_start(); ?>
<form method="POST" action="/plugins/docktail/apply.php" target="progressFrame">
<input type="hidden" name="csrf_token" value="<?= h($token); ?>">
<input type="hidden" name="action" value="save">

<table class="unraid tablesorter"><thead><tr><td>DockTail Settings</td></tr></thead></table>

<dl>
    <dt>Enable DockTail:</dt>
    <dd><?= $select('ENABLE_DOCKTAIL', $yesNo, $cfg['ENABLE_DOCKTAIL'] ?? '0'); ?></dd>
</dl>
<blockquote class="inline_help">
    Runs DockTail as a service on the Unraid host, bound to the Docker lifecycle.
    It requires the Tailscale plugin to be running and this node to be tagged &mdash;
    see the Status tab.
</blockquote>

<table class="unraid tablesorter"><thead><tr><td>Tailscale Control Plane</td></tr></thead></table>

<dl>
    <dt>OAuth Client ID:</dt>
    <dd><input type="text" name="TAILSCALE_OAUTH_CLIENT_ID" value="<?= h($cfg['TAILSCALE_OAUTH_CLIENT_ID'] ?? ''); ?>" autocomplete="off"></dd>
</dl>
<dl>
    <dt>OAuth Client Secret:</dt>
    <dd><input type="password" name="TAILSCALE_OAUTH_CLIENT_SECRET" value="<?= h($cfg['TAILSCALE_OAUTH_CLIENT_SECRET'] ?? ''); ?>" autocomplete="new-password"></dd>
</dl>
<blockquote class="inline_help">
    An OAuth client with the <code>all</code> scope, created in the Tailscale admin
    console. DockTail needs Control Plane credentials to create and tag Service
    definitions. When both an OAuth client and an API key are set, OAuth wins.
</blockquote>

<dl>
    <dt>API Key:</dt>
    <dd><input type="password" name="TAILSCALE_API_KEY" value="<?= h($cfg['TAILSCALE_API_KEY'] ?? ''); ?>" autocomplete="new-password"></dd>
</dl>
<blockquote class="inline_help">
    Alternative to the OAuth client. Tailscale API keys expire after 90 days, so
    an OAuth client is the better choice for an always-on server.
</blockquote>

<dl>
    <dt>Tailnet:</dt>
    <dd><input type="text" name="TAILSCALE_TAILNET" value="<?= h($cfg['TAILSCALE_TAILNET'] ?? '-'); ?>"></dd>
</dl>
<blockquote class="inline_help">
    Tailnet name for Control Plane API calls. Leave as <code>-</code> to use the
    tailnet the credentials belong to.
</blockquote>

<table class="unraid tablesorter"><thead><tr><td>Services</td></tr></thead></table>

<dl>
    <dt>Default service tags:</dt>
    <dd><input type="text" name="DEFAULT_SERVICE_TAGS" value="<?= h($cfg['DEFAULT_SERVICE_TAGS'] ?? 'tag:container'); ?>"></dd>
</dl>
<blockquote class="inline_help">
    Comma-separated ACL tags applied to Services that carry no
    <code>docktail.tags</code> label. These tags must exist in your tailnet policy
    file before DockTail can use them.
</blockquote>

<dl>
    <dt>Ignored service names:</dt>
    <dd><input type="text" name="IGNORE_SERVICE_NAMES" value="<?= h($cfg['IGNORE_SERVICE_NAMES'] ?? ''); ?>"></dd>
</dl>
<blockquote class="inline_help">
    Comma-separated Service names DockTail must never touch. Use this for Services
    you manage by hand on the same node.
</blockquote>

<dl>
    <dt>Delete unused services:</dt>
    <dd><?= $select('DELETE_UNUSED_SERVICES', $yesNo, $cfg['DELETE_UNUSED_SERVICES'] ?? '0'); ?></dd>
</dl>
<blockquote class="inline_help">
    Deletes Service definitions from the Control Plane once no container advertises
    them. Requires Control Plane credentials; without them the cleanup is skipped.
</blockquote>

<dl>
    <dt>Skip shutdown cleanup:</dt>
    <dd><?= $select('SKIP_SHUTDOWN_CLEANUP', $yesNo, $cfg['SKIP_SHUTDOWN_CLEANUP'] ?? '0'); ?></dd>
</dl>
<blockquote class="inline_help">
    Leaves Services and Funnels advertised when DockTail stops. Keeps them
    reachable across a DockTail restart, but any Service whose container is gone
    stays advertised and its hostname keeps resolving to a dead backend until
    DockTail runs again.
</blockquote>

<table class="unraid tablesorter"><thead><tr><td>Advanced</td></tr></thead></table>

<dl>
    <dt>Reconcile interval:</dt>
    <dd><input type="text" name="RECONCILE_INTERVAL" value="<?= h($cfg['RECONCILE_INTERVAL'] ?? '60s'); ?>" class="narrow"></dd>
</dl>
<blockquote class="inline_help">
    Go duration such as <code>30s</code>, <code>60s</code> or <code>5m</code>.
    DockTail also reacts to Docker events, so this only bounds how quickly it
    notices drift.
</blockquote>

<dl>
    <dt>Log level:</dt>
    <dd><?= $select('LOG_LEVEL', ['debug' => 'debug', 'info' => 'info', 'warn' => 'warn', 'error' => 'error'], $cfg['LOG_LEVEL'] ?? 'info'); ?></dd>
</dl>
<blockquote class="inline_help">
    Written to <code>/var/log/docktail.log</code>.
</blockquote>

<dl>
    <dt>DockTail Cloud key:</dt>
    <dd><input type="password" name="DOCKTAIL_CLOUD_KEY" value="<?= h($cfg['DOCKTAIL_CLOUD_KEY'] ?? ''); ?>" autocomplete="new-password"></dd>
</dl>
<blockquote class="inline_help">
    Optional. Leave empty unless you use DockTail Cloud; DockTail is completely
    inert without it.
</blockquote>

<dl>
    <dt>&nbsp;</dt>
    <dd>
        <input type="submit" name="#apply" value="Apply">
        <input type="button" value="Done" onclick="done()">
    </dd>
</dl>

</form>
        <?php
        return (string) ob_get_clean();
    }
}

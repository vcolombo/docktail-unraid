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

const PLUGIN_NAME = 'docktail';
const PLUGIN_ROOT = '/usr/local/emhttp/plugins/docktail';
const CONFIG_DIR  = '/boot/config/plugins/docktail';
const RC_SCRIPT   = '/usr/local/etc/rc.d/rc.docktail';
const RESTART_SH  = PLUGIN_ROOT . '/restart.sh';
const LOG_FILE    = '/var/log/docktail.log';

require_once PLUGIN_ROOT . '/include/Config.php';
require_once PLUGIN_ROOT . '/include/Status.php';
require_once PLUGIN_ROOT . '/include/Labels.php';

/**
 * Reject any request that does not carry the webGUI's CSRF token. Both POST
 * endpoints call this before touching the filesystem or the rc script.
 */
function requireCsrf(): void
{
    $var   = @parse_ini_file('/var/local/emhttp/var.ini');
    $token = is_array($var) ? ($var['csrf_token'] ?? '') : '';

    if ($token === '' || ! isset($_POST['csrf_token']) || ! hash_equals((string) $token, (string) $_POST['csrf_token'])) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
}

function csrfToken(): string
{
    $var = @parse_ini_file('/var/local/emhttp/var.ini');
    return is_array($var) ? (string) ($var['csrf_token'] ?? '') : '';
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

/**
 * Installed plugin version, parsed out of the manifest Unraid keeps in
 * /var/log/plugins. The preview channel installs under a different name, so
 * both are checked.
 */
function pluginVersion(): string
{
    foreach (['/var/log/plugins/docktail.plg', '/var/log/plugins/docktail-preview.plg'] as $plg) {
        if ( ! is_file($plg)) {
            continue;
        }
        $contents = @file_get_contents($plg);
        if ($contents !== false && preg_match('/version\s*=\s*"([^"]+)"/', $contents, $m) === 1) {
            return $m[1];
        }
    }

    return 'unknown';
}

/**
 * DockTail release stamped into the packaged binary by build.sh.
 */
function docktailVersion(): string
{
    $file = PLUGIN_ROOT . '/VERSION';
    if ( ! is_file($file)) {
        return 'unknown';
    }
    $parts = preg_split('/\s+/', trim((string) @file_get_contents($file))) ?: [];

    return $parts[1] ?? 'unknown';
}

/**
 * Container page: a one-screen summary plus where to go next.
 */
function renderOverview(): string
{
    $state = Status::serviceState();

    ob_start(); ?>
<table class="unraid tablesorter"><thead><tr><td>DockTail</td></tr></thead></table>

<blockquote class="inline_help">
    DockTail watches Docker containers, reads <code>docktail.*</code> labels, and
    exposes matching containers as native Tailscale Services &mdash; without giving
    each app its own Tailscale device.
    <br><br>
    <strong>Settings</strong> holds the credentials and the enable switch,
    <strong>Status</strong> checks the environment and shows what is advertised, and
    <strong>Labels</strong> generates the <code>--label</code> string for a container's
    Extra Parameters field.
    <br><br>
    Every field name on these tabs is clickable and explains itself; the Help button in the
    header toggles all of them at once.
    <br><br>
    Full documentation: <a href="https://docktail.org" target="_blank">docktail.org</a>
</blockquote>

<dl>
    <dt>Service:</dt>
    <dd><span class="<?= $state === 'Running' ? 'green-text' : 'orange-text'; ?>"><?= h($state); ?></span></dd>
</dl>
<blockquote class="inline_help">
    Whether the DockTail service is running on this host. It starts only when
    <em>Enable DockTail</em> is set on the Settings tab and both Docker and
    <code>tailscaled</code> are up. The Status tab has the full preflight and the controls.
</blockquote>

<dl>
    <dt>Plugin version:</dt>
    <dd><?= h(pluginVersion()); ?></dd>
</dl>
<blockquote class="inline_help">
    Version of this Unraid plugin, dated <code>YYYY.MM.DD</code>. Updated through
    Plugins or Community Apps, independently of the DockTail version below.
</blockquote>

<dl>
    <dt>DockTail version:</dt>
    <dd><?= h(docktailVersion()); ?></dd>
</dl>
<blockquote class="inline_help">
    Version of the DockTail daemon this plugin ships. The plugin pins one DockTail release
    and builds it unmodified, so this changes only when the plugin is updated.
</blockquote>
    <?php
    return (string) ob_get_clean();
}

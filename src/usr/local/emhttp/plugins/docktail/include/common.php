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
 * Click-to-toggle wiring for this plugin's help blocks.
 *
 * Unraid does this itself in DefaultPageLayout/BodyInlineJS.php, but only for
 * markup present at page load, and only when the pinned row contains a <td> -
 * a table whose header row uses <th> is silently skipped. This binder covers
 * what Unraid misses and, on the DockTail tab, the fragment injected over AJAX.
 *
 * It defers past Unraid's own ready handler and skips any label Unraid already
 * pinned (it marks those with cursor:help). Binding a second handler to the
 * same label would toggle the block twice per click, which looks exactly like
 * help being broken.
 */
function helpScript(): string
{
    ob_start(); ?>
<script>
function docktailBindHelp(scope) {
    var root = scope ? $(scope) : $('.docktail-help-scope');

    root.find('blockquote.inline_help').each(function() {
        var help = $(this);
        if (!help.attr('id')) {
            help.attr('id', 'docktailhelp' + Math.random().toString(36).slice(2));
        }
        var helpId = help.attr('id');

        var pin = help.prev();
        if (!pin.prop('nodeName')) {
            pin = help.parent().prev();
        }
        while (pin.prop('nodeName') && pin.prop('nodeName').search(/(table|dl)/i) == -1) {
            pin = pin.prev();
        }
        if (!pin.prop('nodeName')) {
            return;
        }

        pin.find('dt:last,tr:first').each(function() {
            var node = $(this);

            // Already pinned, by Unraid or by an earlier pass.
            if (/cursor:\s*help/i.test(node.attr('style') || '')) {
                return;
            }
            // Same exclusions Unraid applies: a label that is only a control or
            // a spacer is not a label.
            var html = node.html() || '';
            if (!html || html.search(/(<input|<select|nbsp;)/i) >= 0) {
                return;
            }

            node.css('cursor', 'help').click(function() {
                $('#' + helpId).toggle('slow');
            });
        });
    });
}

// Deferred: Unraid registers its own ready handler after this one, so a plain
// ready callback would run first and double-bind everything it then pins.
$(function() { setTimeout(function() { docktailBindHelp(); }, 0); });
</script>
        <?php
    return (string) ob_get_clean();
}

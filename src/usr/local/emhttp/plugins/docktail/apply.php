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

require_once '/usr/local/emhttp/plugins/docktail/include/common.php';

// CSRF is already enforced by Unraid's auto_prepend_file (local_prepend.php),
// which rejects a bad token with 403 and strips the field from $_POST.

header('Content-Type: text/plain; charset=utf-8');

$action = (string) ($_POST['action'] ?? 'save');

switch ($action) {
    case 'save':
        $settings = Config::coerceSettings($_POST);
        $secrets  = Config::coerceSecrets($_POST);

        if ( ! Config::write($settings, $secrets)) {
            http_response_code(500);
            echo "Failed to write DockTail configuration.\n";
            break;
        }

        echo "Settings saved.\n";
        // Deferred restart, so this request returns immediately.
        @exec(escapeshellarg(RESTART_SH) . ' > /dev/null 2>&1');
        echo $settings['ENABLE_DOCKTAIL'] === '1'
            ? "DockTail is restarting.\n"
            : "DockTail is disabled and will be stopped.\n";
        break;

    case 'start':
    case 'stop':
    case 'restart':
        // Direct, not deferred: an explicit stop must wait for DockTail to
        // withdraw its Tailscale Services before the request returns.
        $output = [];
        $code   = 1;
        @exec(escapeshellarg(RC_SCRIPT) . ' ' . escapeshellarg($action) . ' 2>&1', $output, $code);
        echo "rc.docktail {$action}: " . Status::serviceState() . "\n";
        if ($output !== []) {
            echo implode("\n", $output) . "\n";
        }
        break;

    default:
        http_response_code(400);
        echo "Unknown action.\n";
}

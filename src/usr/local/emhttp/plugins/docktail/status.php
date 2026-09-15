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

// CSRF is already enforced by Unraid's auto_prepend_file (local_prepend.php).
// It removes csrf_token after validation; do not attempt to validate it again.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Use POST from the Status tab.']);
    exit;
}

$action = $_POST['action'] ?? 'snapshot';
if ( ! is_string($action) || ! in_array($action, ['snapshot', 'check_connection'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown Status action.']);
    exit;
}

if ($action === 'check_connection') {
    $container = $_POST['container'] ?? null;
    if ( ! is_string($container) || preg_match('/\A[a-f0-9]{12,64}\z/D', $container) !== 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Choose a container from the Status table.']);
        exit;
    }
    // No URL, host, service, port, credentials or command can be supplied by
    // the caller. All probe/API targets come from inspected server-side state.
    if (array_diff(array_keys($_POST), ['action', 'container', 'csrf_token']) !== []) {
        http_response_code(400);
        echo json_encode(['error' => 'Unexpected connection-check parameter.']);
        exit;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    require_once '/usr/local/emhttp/plugins/docktail/include/ConnectionCheck.php';
    echo json_encode(ConnectionCheck::run($container), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$snapshot = Status::snapshot();

// The rendered fragment travels with the data so the DockTail tab has exactly
// one renderer, in PHP, instead of a second copy of it in JavaScript.
echo json_encode([
    'state'           => $snapshot['state'],
    'pluginVersion'   => $snapshot['pluginVersion'],
    'docktailVersion' => $snapshot['docktailVersion'],
    'localServices'   => $snapshot['advertised'],
    'html'            => Status::renderBody($snapshot),
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

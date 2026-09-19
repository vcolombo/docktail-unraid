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

        // The revision the form was rendered against. A save composed against
        // an older pair is refused rather than applied: renames are atomic but
        // nothing orders two of them, so a request whose answer never reached
        // the browser - a timeout, a dropped connection - could otherwise land
        // after the retry that replaced it and win.
        // Required, not optional: treating an absent field as "write anyway"
        // would let any caller - including a settings fragment rendered before
        // this field existed - skip the fence entirely.
        if ( ! isset($_POST['revision']) || ! is_string($_POST['revision']) || $_POST['revision'] === '') {
            http_response_code(409);
            header('X-DockTail-Stale: 1');
            echo "Not saved: this form was rendered by an older version of the settings page "
                . "and cannot be checked against what is stored. Reload the tab and try again.\n";
            break;
        }

        $result = Config::write($settings, $secrets, (string) $_POST['revision']);
        if ($result['status'] === 'contended') {
            http_response_code(409);
            header('X-DockTail-Stale: 1');
            echo "Not saved: another save or the boot-time migration is holding the configuration "
                . "lock, so this one could not be checked against what is stored. Try again.\n";
            break;
        }

        if ($result['status'] === 'stale') {
            http_response_code(409);
            header('X-DockTail-Stale: 1');
            echo "Not saved: the stored configuration changed since this page was loaded, "
                . "so this form would overwrite it. Reload to see what is stored.\n";
            break;
        }

        if ($result['status'] !== 'ok') {
            http_response_code(500);
            echo "Failed to write DockTail configuration.\n";
            break;
        }

        // What the form should carry from here on, so a second Apply without a
        // reload is not refused as stale. Taken inside write()'s own lock: a
        // revision read after that lock is released can belong to a later
        // writer, and handing it to this form would let it overwrite that
        // writer's save.
        header('X-DockTail-Revision: ' . $result['revision']);

        // First, because the page renders the whole reply as one line of text
        // and a refusal is the part the person needs to read. "Dropped from"
        // covers all three shapes: a secret is emptied, the tailnet falls back
        // to "-", and a list keeps every entry but the offending one.
        $refused = Config::refusedFields($_POST);
        if ($refused !== []) {
            // Read by the page to keep Apply armed - see docktailApply().
            header('X-DockTail-Refused: ' . count($refused));
            printf(
                "A backtick, a line break or a NUL byte cannot be stored in a config value, so %s dropped from: %s.\n",
                count($refused) === 1 ? 'it was' : 'they were',
                implode(', ', $refused)
            );
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
        //
        // The budget is the whole thing rc.docktail can legitimately spend:
        // waiting for the lifecycle lock (RC_LOCK_WAIT, 65s - a restart can
        // hold it for a stop, a config-lock wait and a visibility wait) and
        // then doing the work (a 35s drain, plus a start). Cut this short and
        // the request reports a failure for an action that is still running,
        // which is how Services end up advertised with nothing behind them
        // while the page says the stop failed.
        @set_time_limit(150);

        // $code initialised to a failure: @exec() leaves it untouched if it
        // cannot run at all, and a stop that never ran must not read as one
        // that succeeded.
        $output = [];
        $code   = 1;
        @exec(escapeshellarg(RC_SCRIPT) . ' ' . escapeshellarg($action) . ' 2>&1', $output, $code);

        // The exit status is not the whole answer, and neither is the state
        // on its own - what matters is whether the state is the one this
        // action was asking for.
        //
        // rc.docktail exits zero for several outcomes that are not the
        // requested one: a start declined because DockTail is disabled or
        // because Docker is not up yet, and a stop that sent its SIGKILL and
        // found the daemon still there. Reporting those as "DockTail is now
        // stopped" after a Start, or "now running" after a Stop, is the page
        // telling somebody the opposite of what happened.
        $state    = Status::serviceState();
        $running  = $state === 'Running';
        $enabled  = (Config::read()['ENABLE_DOCKTAIL'] ?? '') === '1';
        $wanted   = $action === 'stop' ? false : $enabled;
        $happened = $running === $wanted;

        // 409 for all of it, because the page shows the first line of the
        // body rather than the status code, and every case here is "the
        // thing you asked for is not what is true now".
        if ($code !== 0) {
            http_response_code(409);
            echo 'DockTail did not ' . $action . ': the service script exited ' . $code
                . ' without completing. It is ' . strtolower($state)
                . ". See /var/log/docktail.log.\n";
        } elseif ( ! $happened) {
            http_response_code(409);

            // The reason is in the script's own output - disabled in
            // settings, no Docker socket, a missing binary, a daemon that
            // outlived its SIGKILL - so the first line points at it rather
            // than guessing which one it was.
            echo 'DockTail is ' . strtolower($state) . ', which is not what ' . $action
                . ' asked for. The service script did not say it failed; its output is below'
                . ($output === [] ? ', but it printed nothing - see /var/log/docktail.log' : '')
                . ".\n";
        } else {
            echo 'DockTail is now ' . strtolower($state) . ".\n";
        }

        if ($output !== []) {
            echo implode("\n", $output) . "\n";
        }
        break;

    default:
        http_response_code(400);
        echo "Unknown action.\n";
}

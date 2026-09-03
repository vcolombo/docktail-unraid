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

header('Content-Type: application/json; charset=utf-8');

$container = trim((string) ($_POST['container'] ?? ''));

switch ((string) ($_POST['action'] ?? 'generate')) {
    case 'load':
        // Picking a container: report what it already has so the form can be
        // populated instead of filled in from memory.
        $info = Labels::containerInfo($container);

        echo json_encode([
            'found'       => $info['found'],
            'values'      => Labels::formValues($info['labels']),
            'labelled'    => $info['labels'] !== [],
            'ports'       => $info['ports'],
            'portSummary' => Labels::summarizePorts($info['ports']),
            'suggestName' => Labels::suggestName($container),
        ], JSON_UNESCAPED_SLASHES);
        break;

    case 'strip':
        // Un-enrolling: the container's Extra Parameters with every
        // docktail.* label removed and everything else preserved.
        $info    = Labels::containerInfo($container);
        $stripped = Labels::stripDocktailLabels($info['extraParams']);

        echo json_encode([
            'errors'      => [],
            'extraParams' => $stripped,
            'hasTemplate' => $info['hasTemplate'],
            'hadLabels'   => $info['labels'] !== [],
        ], JSON_UNESCAPED_SLASHES);
        break;

    default:
        $result = Labels::build($_POST);

        if (is_array($result)) {
            echo json_encode(['errors' => $result, 'warnings' => [], 'labels' => '', 'extraParams' => ''], JSON_UNESCAPED_SLASHES);
            break;
        }

        // The paste target is the whole Extra Parameters field, so hand back the
        // container's existing arguments with its DockTail labels replaced.
        // Anything else the user set there is preserved byte for byte.
        $info     = Labels::containerInfo($container);
        $existing = $info['extraParams'];

        echo json_encode([
            'errors'      => [],
            'warnings'    => Labels::nameWarnings((string) ($_POST['service_name'] ?? ''), $container),
            'labels'      => $result,
            'extraParams' => Labels::mergeExtraParams($existing, $result),
            'merged'      => $info['hasTemplate'] && Labels::stripDocktailLabels($existing) !== '',
            'hasTemplate' => $info['hasTemplate'],
        ], JSON_UNESCAPED_SLASHES);
}

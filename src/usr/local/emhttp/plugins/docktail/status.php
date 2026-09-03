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

requireCsrf();

$snapshot = Status::snapshot();

// The rendered fragment travels with the data so the Status tab has exactly one
// renderer, in PHP, instead of a second copy of it in JavaScript.
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'state'           => $snapshot['state'],
    'pluginVersion'   => $snapshot['pluginVersion'],
    'docktailVersion' => $snapshot['docktailVersion'],
    'advertised'      => $snapshot['advertised'],
    'html'            => Status::renderBody($snapshot),
], JSON_UNESCAPED_SLASHES);

#!/bin/bash
# Repin the DockTail submodule and the version stamped into the binary in one
# step, so plugin/plugin.json can never drift from the checked-out source.
set -euo pipefail
V="${1:?usage: bump.sh <docktail-version>}"
git -C docktail fetch --tags origin
git -C docktail checkout "refs/tags/${V}"
jq --arg v "$V" '.docktailVersion = $v' plugin/plugin.json > plugin/plugin.json.tmp
mv plugin/plugin.json.tmp plugin/plugin.json
git add docktail plugin/plugin.json
echo "pinned docktail ${V}; commit and open a PR"

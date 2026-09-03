#!/bin/bash
# Stage the Slackware package tree under src/. The release action performs the
# tar itself, so this script only builds the binary and fixes permissions.
set -euo pipefail

DOCKTAIL_VERSION=$(jq -r .docktailVersion plugin/plugin.json)
DEST=src/usr/local/emhttp/plugins/docktail

mkdir -p "$DEST/bin"

# CGO_ENABLED=0 keeps the binary free of any glibc coupling so it runs on
# Unraid's Slackware userland. Unraid is x86_64-only, so amd64 is the only
# target that matters.
( cd docktail && CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath \
    -ldflags "-w -s -X github.com/marvinvr/docktail/cloud.agentVersion=${DOCKTAIL_VERSION}" \
    -o "../${DEST}/bin/docktail" . )

printf 'docktail %s\n' "$DOCKTAIL_VERSION" > "$DEST/VERSION"

chmod 0755 src/usr/local/etc/rc.d/rc.docktail "$DEST/restart.sh" "$DEST/stop.sh" src/install/doinst.sh

if [ "${1:-}" = "--package" ]; then          # local smoke test only; CI never passes this
  mkdir -p build
  ( cd src && tar --owner=0 --group=0 -cJf ../build/docktail-unraid-local-noarch-1.txz * )
fi

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

# The package conveys a compiled AGPL-3.0 work, so it must carry the licence
# text (AGPL-3.0 sections 4 and 5) and identify the corresponding source
# (section 6). The exact upstream commit is recorded, not just the tag, so the
# binary a user has can always be traced to the source it was built from.
cp LICENSE "$DEST/LICENSE"

DOCKTAIL_COMMIT=$(git -C docktail rev-parse HEAD 2>/dev/null || echo 'unknown')
cat > "$DEST/SOURCE" <<EOF
DockTail ${DOCKTAIL_VERSION}

This plugin ships a compiled, unmodified build of DockTail, which is licensed
under the GNU Affero General Public License v3.0 (see LICENSE in this
directory). DockTail is copyright its upstream authors.

Corresponding source for the binary in bin/docktail:

  repository: https://github.com/marvinvr/docktail
  tag:        ${DOCKTAIL_VERSION}
  commit:     ${DOCKTAIL_COMMIT}

Built with: CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath

The Unraid packaging around it is licensed AGPL-3.0 as well, and its source is
at https://github.com/vcolombo/docktail-unraid
EOF

chmod 0755 src/usr/local/etc/rc.d/rc.docktail "$DEST/restart.sh" "$DEST/stop.sh" src/install/doinst.sh

if [ "${1:-}" = "--package" ]; then          # local smoke test only; CI never passes this
  mkdir -p build
  ( cd src && tar --owner=0 --group=0 -cJf ../build/docktail-unraid-local-noarch-1.txz * )
fi

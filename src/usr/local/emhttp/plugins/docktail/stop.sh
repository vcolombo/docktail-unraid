#!/bin/bash
# Synchronous stop. This runs from the stopping_docker event and must finish
# draining while tailscaled and Docker are still alive, otherwise DockTail
# leaves stale Tailscale Services advertised on the tailnet.

exec /usr/local/etc/rc.d/rc.docktail stop

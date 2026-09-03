#!/bin/bash
# Deferred restart, so blocking Unraid events (and the .plg install script)
# return immediately.

echo "$(date '+%Y-%m-%d %H:%M:%S') restart.sh: restarting DockTail in 5 seconds" >> /var/log/docktail.log
echo "sleep 5 ; /usr/local/etc/rc.d/rc.docktail restart" | at now 2>/dev/null

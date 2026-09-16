( cd usr/local/emhttp/plugins/docktail/event ; rm -rf docker_started ; ln -sf ../restart.sh docker_started )
( cd usr/local/emhttp/plugins/docktail/event ; rm -rf stopping_docker ; ln -sf ../stop.sh stopping_docker )

chmod 0644 /etc/logrotate.d/docktail
chown root:root /etc/logrotate.d/docktail

# Values written before the plugin escaped $ are still unescaped on the flash,
# and nothing rewrites them until the next Apply, so normalise them once here.
# Failure is not fatal: a config that cannot be parsed is left exactly as it is.
php -r 'require "/usr/local/emhttp/plugins/docktail/include/common.php"; \DockTail\Config::normalizeStoredFiles();' >/dev/null 2>&1 || true

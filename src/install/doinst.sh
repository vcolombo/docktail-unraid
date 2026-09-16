( cd usr/local/emhttp/plugins/docktail/event ; rm -rf docker_started ; ln -sf ../restart.sh docker_started )
( cd usr/local/emhttp/plugins/docktail/event ; rm -rf stopping_docker ; ln -sf ../stop.sh stopping_docker )

chmod 0644 /etc/logrotate.d/docktail
chown root:root /etc/logrotate.d/docktail

# Values written before the plugin escaped $ are still unescaped on the flash,
# and nothing rewrites them until the next Apply, so normalise them here. This
# runs on every boot, with the rewrite itself a no-op once converted.
#
# Not fatal - a config that cannot be parsed is left exactly as it is - but a
# file that needed converting and could not be written is said out loud, since
# rc.docktail then goes on sourcing the raw values.
php -r 'require "/usr/local/emhttp/plugins/docktail/include/common.php"; exit(\DockTail\Config::normalizeStoredFiles() ? 0 : 1);' >/dev/null 2>&1 \
  || echo "docktail: could not rewrite a legacy config in /boot/config/plugins/docktail; a \$ in a stored value will still be expanded when the service starts"

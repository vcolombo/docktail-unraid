( cd usr/local/emhttp/plugins/docktail/event ; rm -rf docker_started ; ln -sf ../restart.sh docker_started )
( cd usr/local/emhttp/plugins/docktail/event ; rm -rf stopping_docker ; ln -sf ../stop.sh stopping_docker )

chmod 0644 /etc/logrotate.d/docktail
chown root:root /etc/logrotate.d/docktail

# Values written before the plugin escaped $ are still unescaped on the flash,
# and nothing rewrites them until the next Apply, so normalise them here. This
# runs on every boot, with the rewrite itself a no-op once converted.
#
# Both outcomes are said out loud, because neither is visible anywhere else:
# a value dropped for containing a backtick, and a file that needed converting
# but could not be written - rc.docktail then goes on sourcing the raw values,
# where a $ is expanded and a backtick makes bash abandon the rest of the file.
# Neither is fatal to the install.
php -r '
  require "/usr/local/emhttp/plugins/docktail/include/common.php";
  $r = \DockTail\Config::normalizeStoredFiles();
  foreach ($r["dropped"] as $field) {
      echo "docktail: dropped the stored $field - it contained a backtick, which cannot be written to a file the service sources\n";
  }
  exit($r["ok"] ? 0 : 1);
' 2>/dev/null \
  || echo "docktail: could not rewrite a legacy config in /boot/config/plugins/docktail; until the next Apply a stored \$ is still expanded and a stored backtick still blanks every later setting"

( cd usr/local/emhttp/plugins/docktail/event ; rm -rf docker_started ; ln -sf ../restart.sh docker_started )
( cd usr/local/emhttp/plugins/docktail/event ; rm -rf stopping_docker ; ln -sf ../stop.sh stopping_docker )

chmod 0644 /etc/logrotate.d/docktail
chown root:root /etc/logrotate.d/docktail

# Values written before the plugin escaped $ are still unescaped on the flash.
# rc.docktail reads rather than sources them, so they are no longer dangerous,
# but PHP's reader and the shell's reader only agree on the escaped form - so
# normalise them here. This runs on every boot, and the rewrite is a no-op once
# converted.
#
# Both outcomes are said out loud, because neither is visible anywhere else:
# a value dropped for carrying a backtick or a line break, and a file that
# needed converting but could not be written. Neither is fatal to the install.
php -r '
  require "/usr/local/emhttp/plugins/docktail/include/common.php";
  $r = \DockTail\Config::normalizeStoredFiles();
  foreach ($r["dropped"] as $field) {
      echo "docktail: dropped the stored $field - it contained a backtick or a line break, which cannot be stored in these files\n";
  }
  exit($r["ok"] ? 0 : 1);
' 2>/dev/null \
  || echo "docktail: could not rewrite a legacy config in /boot/config/plugins/docktail; DockTail still reads it, but a value written with an unescaped \$ arrives with that \$ intact only after the next Apply"

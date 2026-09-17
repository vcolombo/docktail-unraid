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
      echo "docktail: dropped the stored $field - it held a backtick, a line break or a NUL byte, none of which can be stored in these files\n";
  }
  foreach ($r["ignored"] as $key) {
      echo "docktail: removed $key from the stored config - it is not a DockTail setting and nothing read it\n";
  }
  foreach ($r["unparseable"] as $file) {
      echo "docktail: left $file alone - PHP cannot parse it, so the settings page shows defaults while the service still reads whatever lines are valid. Repair the file (keep a copy first); pressing Apply would save the defaults over what is running.\n";
  }
  exit($r["ok"] ? 0 : 1);
' 2>/dev/null \
  || echo "docktail: could not rewrite a legacy config in /boot/config/plugins/docktail; DockTail reads it either way, but PHP's reader and the shell reader only agree on the escaped form until an Apply rewrites it"

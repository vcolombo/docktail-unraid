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
# a value dropped for carrying a backtick or a line break, a secret moved out
# of the world-readable settings file, and a file that needed converting but
# could not be written. None of them is fatal to the install.
# /tmp is world-writable and this runs as root at boot, so the file is created
# by mktemp rather than named - a pre-existing symlink at a guessable path
# would otherwise be a root write anywhere on the system.
migration_err=$(mktemp 2> /dev/null) || migration_err=/dev/null

# display_errors is pinned to stderr because php-cli defaults it to STDOUT,
# where a warning would be indistinguishable from this script's own report
# lines - and where the old 2>/dev/null never suppressed it either.
php -d display_errors=stderr -r '
  require "/usr/local/emhttp/plugins/docktail/include/common.php";
  $r = \DockTail\Config::normalizeStoredFiles();
  foreach ($r["dropped"] as $field) {
      echo "docktail: dropped the stored $field - it held a backtick, a line break or a NUL byte, none of which can be stored in these files\n";
  }
  foreach ($r["superseded"] as $field) {
      echo "docktail: removed a second $field from docktail.cfg - credentials.cfg already holds one, and that is the one in use\n";
  }
  foreach ($r["stranded"] as $field) {
      echo "docktail: left the stored $field in docktail.cfg - it belongs in credentials.cfg, which PHP cannot parse. Repair that file and press Apply.\n";
  }
  foreach ($r["moved"] as $field) {
      echo "docktail: moved the stored $field out of docktail.cfg into credentials.cfg (0600) - it was in the world-readable file\n";
  }
  foreach ($r["protected"] as $file) {
      echo "docktail: tightened $file to 0600 - it holds a credential that cannot be moved out of it, so its contents are no longer world-readable\n";
  }
  foreach ($r["exposed"] as $file) {
      echo "docktail: $file holds a credential, cannot be parsed, and could not even be made private - the credential is readable by every local user. Fix its permissions and repair the file.\n";
  }
  foreach ($r["ignored"] as $key) {
      echo "docktail: removed $key from the stored config - it is not a DockTail setting and nothing read it\n";
  }
  foreach ($r["malformed"] as $key) {
      echo "docktail: removed $key from the stored config - the line was not valid KEY=\"value\", so the service was already skipping it\n";
  }
  foreach ($r["unparseable"] as $file) {
      echo "docktail: left $file alone - it contains a NUL byte, which neither the settings page nor the service can read, so both fall back to the shipped defaults for whatever it held. Repair the file (keep a copy first); pressing Apply would save those defaults over it.\n";
  }
  foreach ($r["unreadable"] as $file) {
      echo "docktail: could not read $file at all - it is still there, so nothing was changed, and the service is running on the shipped defaults for whatever it holds. Check the flash device.\n";
  }
  exit($r["ok"] ? 0 : 1);
' 2> "$migration_err" \
  || {
    echo "docktail: could not rewrite a legacy config in /boot/config/plugins/docktail; DockTail reads it either way, but PHP's reader and the shell reader only agree on the escaped form until an Apply rewrites it"
    # And why, which the line above cannot say: a missing php, a parse error
    # and a broken include path all land here and look identical without it.
    # A non-zero exit with nothing on stderr is the ordinary case - a file
    # that needed converting but could not be written - so this stays quiet.
    while IFS= read -r line; do
      echo "docktail: php said: $line"
    done < "$migration_err"
  }

[ "$migration_err" = /dev/null ] || rm -f "$migration_err"

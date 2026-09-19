<?php

/*
    Copyright (C) 2026  vcolombo

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace DockTail;

/**
 * Settings live in two files. Everything except ENABLE_DOCKTAIL maps 1:1 onto
 * the DockTail environment variable of the same name; ENABLE_DOCKTAIL is
 * plugin-local and only gates the rc script.
 *
 * Credentials are kept in a separate 0600 file that the install script excludes
 * from Unraid Connect's flash backup, so tailnet credentials never leave the
 * server.
 */
final class Config
{
    public const SETTINGS_FILE    = CONFIG_DIR . '/docktail.cfg';
    public const CREDENTIALS_FILE = CONFIG_DIR . '/credentials.cfg';

    /**
     * The shipped defaults, which live with the plugin rather than on the
     * flash: an install replaces this file, and nothing takes the config lock
     * to do it, so anything pairing its values with a revision has to read it
     * exactly once.
     */
    public const DEFAULTS_FILE = PLUGIN_ROOT . '/default.cfg';


    // /var/run, not /tmp and not the flash: /tmp is world-writable, so any
    // local process could create this file first and hold it, and a blocking
    // wait on it would stall every Apply. /var/run is root-owned, and the
    // plugin already keeps its pidfile there.
    public const LOCK_FILE = '/var/run/docktail-config.lock';

    // Seconds to wait for the lock before giving up and going ahead anyway.
    // Kept equal to CONFIG_LOCK_WAIT in rc.docktail: the two sides wait on each
    // other, so one number is one behaviour to reason about.
    public const LOCK_WAIT = 5;

    /**
     * Seconds after which a staged file is treated as abandoned rather than in
     * progress. Staging is a create and a write of a few hundred bytes, so
     * this is orders of magnitude beyond any live one - it exists because the
     * lock fails open, which means a save really can be staging while the boot
     * migration holds the lock.
     */
    public const TEMP_ABANDONED_AFTER = 900;

    /** @var list<string> */
    public const SECRET_KEYS = [
        'TAILSCALE_OAUTH_CLIENT_ID',
        'TAILSCALE_OAUTH_CLIENT_SECRET',
        'TAILSCALE_API_KEY',
        'DOCKTAIL_CLOUD_KEY',
    ];

    /**
     * Every key a config file may carry, mirroring config_key_is_known() in
     * rc.docktail. The migration needs it because renderBody() canonicalises a
     * key - it uppercases and strips - so rewriting an unrecognised
     * `enable_docktail="1"` would turn a line the reader ignores into one it
     * obeys. Anything not here is removed rather than promoted.
     *
     * @var list<string>
     */
    public const KNOWN_KEYS = [
        'ENABLE_DOCKTAIL',
        'TAILSCALE_TAILNET',
        'DEFAULT_SERVICE_TAGS',
        'IGNORE_SERVICE_NAMES',
        'DELETE_UNUSED_SERVICES',
        'SKIP_SHUTDOWN_CLEANUP',
        'RECONCILE_INTERVAL',
        'LOG_LEVEL',
        'TAILSCALE_OAUTH_CLIENT_ID',
        'TAILSCALE_OAUTH_CLIENT_SECRET',
        'TAILSCALE_API_KEY',
        'DOCKTAIL_CLOUD_KEY',
    ];

    /**
     * Settings DockTail reads as a comma-separated list, where one bad entry
     * is dropped rather than the whole value - see normalizeList().
     *
     * @var list<string>
     */
    public const LIST_SETTINGS = ['DEFAULT_SERVICE_TAGS', 'IGNORE_SERVICE_NAMES'];

    /**
     * Every field whose value reaches a config file as free text, so every
     * field where a value can be refused - see containsUnstorable(). The
     * labels are what the settings page calls them below, because a refusal is
     * reported to the person looking at that form, not at the cfg file. This
     * is the reporting list only: a new free-text field belongs here and in
     * the coercion that actually persists it - coerceSecrets() for a
     * credential, coerceSettings() for a setting.
     *
     * @var array<string, string>
     */
    public const REFUSABLE_FIELDS = [
        'TAILSCALE_OAUTH_CLIENT_ID'     => 'OAuth Client ID',
        'TAILSCALE_OAUTH_CLIENT_SECRET' => 'OAuth Client Secret',
        'TAILSCALE_API_KEY'             => 'API Key',
        'DOCKTAIL_CLOUD_KEY'            => 'DockTail Cloud key',
        'TAILSCALE_TAILNET'             => 'Tailnet',
        'DEFAULT_SERVICE_TAGS'          => 'Default service tags',
        'IGNORE_SERVICE_NAMES'          => 'Ignored service names',
    ];

    public const LOG_LEVELS = ['debug', 'info', 'warn', 'error'];

    /**
     * Shipped defaults merged under the user's settings, plus the credentials.
     *
     * Under the shared lock, because this reads two files that are written as a
     * pair: Status and ConnectionCheck would otherwise be able to pick up an
     * OAuth client ID beside the secret it replaced and report a control-plane
     * failure that is an artefact of the read, not of the credentials.
     *
     * @return array<string, string>
     */
    public static function read(): array
    {
        return self::withLock(static fn (): array => self::storedValues(), LOCK_SH);
    }

    /**
     * The values and the revision that describes them, from one lock interval.
     *
     * The settings page needs both, and needs them to agree: taking them from
     * two separate locks lets a save land in between, and the form would then
     * show one pair while its hidden token named another - submitting it
     * unchanged would pass the fence and overwrite what landed.
     *
     * @return array{values: array<string, string>, revision: string, locked: bool}
     *         revision is '' when the lock could not be taken, which makes any
     *         save from the rendered form refuse rather than risk overwriting
     *         whatever the lock holder is committing
     */
    public static function snapshot(): array
    {
        return self::withLock(static function (bool $locked): array {
            // One read, two uses: the values on the form and the revision that
            // fences them have to describe the same defaults.
            $defaults = self::defaultsBody();
            $values   = self::storedValues($defaults);

            // Without the lock the two reads are not one snapshot: a save can
            // land between them and the form would carry the old values with
            // the new revision - which would then pass the fence and overwrite
            // that save. So the revision is withheld rather than guessed, and
            // an empty one is refused by apply.php with the reload message.
            // The page still renders: showing the settings is useful even when
            // saving them has to wait for whatever holds the lock.
            return [
                'values'   => $values,
                'revision' => $locked ? self::revisionOfStored($defaults) : '',
                'locked'   => $locked,
            ];
        }, LOCK_SH);
    }

    /**
     * Every read on this side goes through the same parser the service uses,
     * rather than parse_ini_file() or Unraid's parse_plugin_cfg(). Both of
     * those run PHP's ini scanner, which turns an unquoted `true` into `1` and
     * an unquoted `none` into nothing, while rc.docktail's reader keeps the
     * text. Quoted values - all this plugin writes - come back identically
     * either way, so nothing changes for a file the settings page wrote; what
     * this removes is the disagreement over a hand-edited line, where the page
     * would show a value the service is not using.
     *
     * parse_plugin_cfg() is not needed for its merge either: it layers
     * default.cfg under the stored settings, which is what defaults() does
     * here, from the same file.
     *
     * Callers hold the lock; this does not take it.
     *
     * @return array<string, string>
     */
    private static function storedValues(?string $defaultsBody = null): array
    {
        $values = self::defaults($defaultsBody);
        $values = array_merge($values, self::readAsService(self::SETTINGS_FILE));
        $values = array_merge($values, self::readAsService(self::CREDENTIALS_FILE));

        foreach (self::SECRET_KEYS as $key) {
            $values[$key] ??= '';
        }

        return $values;
    }

    /**
     * The shipped defaults.
     *
     * $body lets a caller hand over bytes it has already read: an install
     * replaces default.cfg without taking this lock, so reading it twice - once
     * for the form and once for the revision - can pair old values with a new
     * hash, and the fence would then accept a form built from defaults that no
     * longer exist.
     *
     * @return array<string, string>
     */
    public static function defaults(?string $body = null): array
    {
        if ($body === null) {
            return self::readAsService(self::DEFAULTS_FILE);
        }

        return array_intersect_key(
            self::parseBody($body)['values'],
            array_flip(self::KNOWN_KEYS)
        );
    }

    /** The bytes of the shipped defaults, or false if they cannot be read. */
    private static function defaultsBody(): string|false
    {
        return @file_get_contents(self::DEFAULTS_FILE);
    }

    /**
     * The values rc.docktail would export from this file, and only those: a
     * line it skips - or a key it does not recognise, which it refuses in
     * config_key_is_known() - is a line the service does not act on, so
     * showing it on the settings page would describe a configuration that is
     * not running.
     *
     * The migration uses parseAsReader() directly instead, because it has to
     * report the keys nothing reads before it removes them.
     *
     * @return array<string, string>
     */
    private static function readAsService(string $file): array
    {
        return array_intersect_key(
            self::parseAsReader($file)['values'],
            array_flip(self::KNOWN_KEYS)
        );
    }

    /**
     * The file as rc.docktail's reader sees it, which is not what
     * parse_ini_file() sees: PHP accepts `ENABLE_DOCKTAIL=1` unquoted and
     * lowercase keys, the reader accepts neither. The migration has to use
     * this stricter view, because renderBody() would otherwise canonicalise a
     * line that currently does nothing into one the reader obeys - an
     * unquoted `ENABLE_DOCKTAIL=1` would start the daemon on the next boot.
     *
     * Mirrors read_config() and unescape_value() in rc.docktail: KEY="value"
     * with `\\`, `\"` and `\$` unescaped, an unescaped quote or a dangling
     * backslash rejected.
     *
     * @return array{values: array<string, string>, malformed: list<string>}
     *         malformed names lines the reader would skip on syntax alone -
     *         kept apart from keys it reads but does not recognise, because
     *         the two need different things said about them
     */
    private static function parseAsReader(string $file): array
    {
        // What is at the path, before opening it. file_get_contents() on a
        // FIFO blocks until something writes to it, and on a character device
        // it reads until the read fails - either one hangs a settings page
        // render or an Apply, from a path the installer deliberately leaves
        // alone when it is not a regular file. rc.docktail refuses the same
        // thing with [ -f ], and the two readers have to agree about what is
        // readable or the page and the service disagree about the config.
        //
        // Reported as unreadable, which is exactly what it is: the file is
        // there, nothing was read, and the caller must not treat that as an
        // empty config.
        if (file_exists($file) && ! is_file($file)) {
            return ['values' => [], 'malformed' => [], 'nul' => false, 'unreadable' => true];
        }

        $raw = @file_get_contents($file);

        // A file that exists and cannot be read is not an empty file. It is
        // its own answer, because the difference matters twice: the settings
        // page would otherwise show defaults for a config that is still there,
        // and the migration would compare its rendering against "", see
        // nothing worth writing, and report success for a file it never read.
        if ($raw === false && is_file($file)) {
            return ['values' => [], 'malformed' => [], 'nul' => false, 'unreadable' => true];
        }

        return self::parseBody((string) $raw);
    }

    /**
     * The same reader, over bytes somebody else read.
     *
     * @return array{values: array<string, string>, malformed: list<string>, nul: bool, unreadable: bool}
     */
    private static function parseBody(string $body): array
    {
        $values    = [];
        $malformed = [];

        // The whole file, not the line: a NUL is the one byte the shell cannot
        // carry, and `read` drops it before the pattern match runs - so the
        // shell cannot tell which record held it, and a NUL inside a *key*
        // could even close up into a name it recognises. Neither reader can
        // agree with the other on a single line of such a file, so both refuse
        // all of it and say so. rc.docktail does the same check, the same way.
        if (strpos($body, "\0") !== false) {
            return ['values' => [], 'malformed' => [], 'nul' => true, 'unreadable' => false];
        }

        // Split on \n by hand: file() with FILE_IGNORE_NEW_LINES strips a
        // whole \r\n, where `read -r` keeps the \r and the reader then drops
        // exactly one. Getting that wrong accepts KEY="1"\r\r - a line the
        // service skips - and promotes it.
        foreach (explode("\n", $body) as $line) {
            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^([A-Z][A-Z0-9_]*)="(.*)"$/', $line, $m) !== 1) {
                $malformed[] = self::lineLabel($line);
                continue;
            }

            $value = self::unescapeValue($m[2]);
            if ($value === null) {
                $malformed[] = $m[1];
                continue;
            }

            // A NUL is the one byte the shell cannot carry: bash drops it
            // silently, so `declare -g` there produces a shorter string than
            // this function would return, and the two readers would disagree
            // about a value neither can spell. Refused on this side too, so
            // the settings page shows a default rather than a value the
            // service is not using, and the migration removes the line.
            if (strpos($value, "\0") !== false) {
                $malformed[] = $m[1];
                continue;
            }

            $values[$m[1]] = $value;
        }

        return ['values' => $values, 'malformed' => $malformed, 'nul' => false, 'unreadable' => false];
    }

    /**
     * Undo renderBody()'s escaping, or null when the value could not have come
     * from it - an unescaped quote means the closing quote was not where the
     * line said it was, and a dangling backslash means PHP's parser and the
     * reader would disagree about where the value ends.
     */
    private static function unescapeValue(string $value): ?string
    {
        $out    = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '\\') {
                if ($i + 1 >= $length) {
                    return null;
                }
                $next = $value[$i + 1];
                if ($next === '\\' || $next === '"' || $next === '$') {
                    $out .= $next;
                    $i++;
                    continue;
                }
            } elseif ($char === '"') {
                return null;
            }

            $out .= $char;
        }

        return $out;
    }

    /** A line named for a log message, without spilling a credential into it. */
    private static function lineLabel(string $line): string
    {
        return preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $m) === 1
            ? $m[1]
            : 'an unrecognised line';
    }

    /**
     * What the stored pair looks like right now, as one short string.
     *
     * The settings page renders it into the form and apply.php hands it back,
     * so a save can be refused when it was composed against a config that has
     * since changed. Atomic renames order nothing: a save whose answer never
     * arrived - a browser timeout, a dropped connection - can still be inside
     * write() while the person reloads and saves again, and without this the
     * older form would land last and win.
     *
     * Content, not mtime: a flash filesystem's timestamps are coarse, and two
     * saves within the same second are exactly the case this exists for.
     */
    public static function revision(): string
    {
        return self::withLock(static fn (): string => self::revisionOfStored(), LOCK_SH);
    }

    /**
     * The same thing, for callers that already hold the lock.
     *
     * '' when any file that exists cannot be read: the fence's whole job is to
     * notice that what the form was built from has changed, and a file nobody
     * can read has unknown content - two different unknowns would hash the
     * same, so a form rendered over one would pass the fence after the other.
     * An empty revision is refused by apply.php with the reload message.
     *
     * default.cfg is in it because the form shows those values: a plugin
     * update that replaces the shipped defaults has to make an open form
     * stale, or Apply would write the old ones back into docktail.cfg and
     * mask the new ones.
     */

    /**
     * @param  list<string> $files
     * @return string '' when one of them exists and cannot be read
     */
    private static function revisionOf(array $files): string
    {
        $parts = [];
        foreach ($files as $file) {
            $body = @file_get_contents($file);
            if ($body === false) {
                if (is_file($file)) {
                    return '';
                }

                $parts[] = 'absent';
                continue;
            }

            $parts[] = hash('sha256', $body);
        }

        return substr(hash('sha256', implode('.', $parts)), 0, 16);
    }

    private static function revisionOfStored(string|false|null $defaultsBody = null): string
    {
        // The defaults are hashed from the bytes the caller read, when it read
        // them: reading the file again here is what allowed an install between
        // the two reads to produce old values with a new hash.
        $defaults = $defaultsBody === null ? self::defaultsBody() : $defaultsBody;
        if ($defaults === false && is_file(self::DEFAULTS_FILE)) {
            return '';
        }

        $parts = [$defaults === false ? 'absent' : hash('sha256', $defaults)];

        foreach ([self::SETTINGS_FILE, self::CREDENTIALS_FILE] as $file) {
            $body = @file_get_contents($file);
            if ($body === false) {
                if (is_file($file)) {
                    return '';
                }

                // Absent is a state, and a different one from unreadable.
                $parts[] = 'absent';
                continue;
            }

            $parts[] = hash('sha256', $body);
        }

        return substr(hash('sha256', implode('.', $parts)), 0, 16);
    }

    /**
     * Persist both files, as one change.
     *
     * Each file is staged and renamed, so a concurrent rc.docktail read never
     * sees a half-written config - and both are staged before either is
     * renamed, so a flash that fills up between them leaves the previous pair
     * in place rather than this submission's settings beside the last one's
     * credentials.
     *
     * @param  array<string, string> $settings
     * @param  array<string, string> $secrets
     * @param  string|null $expected the revision the form was composed
     *         against, or null to write regardless
     * @return array{status: 'ok'|'stale'|'contended'|'failed', revision: string} the
     *         revision is the one this call leaves behind, taken before the
     *         lock is released: computing it afterwards can hand the caller a
     *         later writer's revision, and a form carrying that would pass the
     *         fence and overwrite the save it belongs to. stale means the
     *         stored pair changed since $expected and nothing was written;
     *         contended means the lock could not be taken, so the fence could
     *         not be trusted and nothing was written either.
     */
    public static function write(array $settings, array $secrets, ?string $expected = null): array
    {
        return self::withLock(static function (bool $locked) use ($settings, $secrets, $expected): array {
            // A fence needs the lock. withLock() fails open after five
            // seconds, and without it this comparison is a read of a pair
            // another writer is in the middle of replacing: the form's
            // revision can still match what is on disk while the holder is
            // about to commit something else, and then this write lands on top
            // of it. So a fenced write refuses instead. The migration, which
            // passes no revision, keeps the fail-open behaviour - it runs at
            // boot with nobody to retry it.
            if ($expected !== null && ! $locked) {
                return ['status' => 'contended', 'revision' => self::revisionOfStored()];
            }

            // Inside the lock, with the write: checking it anywhere else is
            // the same race in a different place.
            if ($expected !== null && $expected !== self::revisionOfStored()) {
                return ['status' => 'stale', 'revision' => self::revisionOfStored()];
            }

            // Inside the lock: two first-time saves would otherwise both see
            // the directory missing, and the one whose mkdir() lost would
            // report a failure for a directory that now exists.
            if ( ! is_dir(CONFIG_DIR) && ! @mkdir(CONFIG_DIR, 0755, true) && ! is_dir(CONFIG_DIR)) {
                return ['status' => 'failed', 'revision' => self::revisionOfStored()];
            }

            $staged = [];
            foreach ([
                self::SETTINGS_FILE    => [$settings, 0644],
                self::CREDENTIALS_FILE => [$secrets, 0600],
            ] as $file => [$values, $mode]) {
                $tmp = self::stageFile($file, $values, $mode);
                if ($tmp === false) {
                    self::discardStaged($staged);

                    return ['status' => 'failed', 'revision' => self::revisionOfStored()];
                }

                $staged[$file] = [$tmp, $mode];
            }

            // Checked again here, against the staged bytes already on disk:
            // this lock does not cover default.cfg, which an install replaces
            // without taking it - and that file is in the revision because the
            // form shows its values. Without this, an install landing between
            // the first check and the commit would let a form built from the
            // old defaults write them into docktail.cfg as explicit settings,
            // masking the new ones. The window left is the commit itself.
            if ($expected !== null && $expected !== self::revisionOfStored()) {
                self::discardStaged($staged);

                return ['status' => 'stale', 'revision' => self::revisionOfStored()];
            }

            $ok = self::commitStaged($staged);

            return [
                'status'   => $ok ? 'ok' : 'failed',
                'revision' => self::revisionOfStored(),
            ];
        });
    }

    /**
     * Serialise everything that rewrites the pair of config files. Per-file
     * atomicity is not enough on its own: two writers interleaving their
     * renames would leave docktail.cfg from one submission beside
     * credentials.cfg from another, and the migration below is a
     * read-modify-write that would otherwise clobber an Apply landing between
     * its read and its rename.
     *
     * The lock lives in /var/run - see LOCK_FILE - so taking it costs no flash
     * write and no unprivileged process can hold it first.
     * rc.docktail takes it too, shared, before reading the pair - see
     * load_config() there. Writers alone are not enough: a start landing
     * between the two renames reads one file from this save and the other from
     * the last one, and the boot path is where that collides, because
     * doinst.sh runs the migration below while the service is starting.
     *
     * It fails open twice over: an unopenable lock file, or one already held
     * for longer than LOCK_WAIT, must not stop somebody saving their settings.
     * Losing the serialisation is a worse-but-rare outcome; a webGUI that hangs
     * on Apply is an immediate one. LOCK_WAIT is the same number rc.docktail
     * waits, so a held lock means the same thing on both sides.
     *
     * Readers pass LOCK_SH, which lets concurrent page renders and status polls
     * through while still excluding a writer mid-rename.
     *
     * @template T
     * @param  callable(): T $work
     * @param  int           $operation   LOCK_EX to write, LOCK_SH to read
     * @param  ?string       $consequence what losing the lock means for this
     *                                    caller, when it is not what the
     *                                    operation implies
     * @return T
     */
    private static function withLock(callable $work, int $operation = LOCK_EX, ?string $consequence = null)
    {
        // What losing the lock costs follows from the operation for two of the
        // three callers; the boot migration is the exception, because it
        // declines to run rather than running unlocked, and says so itself.
        $consequence ??= $operation === LOCK_SH
            ? 'A save landing in the middle of this read could be picked up half-applied.'
            : 'A save landing together with this one could leave one file from each.';

        // Created under 077, not created and then tightened: with a umask of
        // 022 the file would exist as 0644 for the moment in between, long
        // enough for another local user to open it read-only and hold a shared
        // lock - which pushes every writer past the wait below and out to the
        // fails-open path, the serialisation gone. rc.docktail does the same.
        // What is at the path, before opening it: fopen() on a FIFO blocks
        // in write mode until a reader appears, which would hang every config
        // read and every save - before flock(), before its timeout, before
        // the fail-open path that exists precisely so this code never waits
        // on a lock forever. Absent is fine, and so is a regular file.
        //
        // is_link() first, and on its own: file_exists() follows the link, so
        // a dangling one looks like an absent path - and fopen(, 'c') would
        // then create whatever it points at, anywhere on the filesystem, as
        // root. One that does resolve is refused too, because a lock taken on
        // an inode somebody else chose is not serialisation.
        if (is_link(self::LOCK_FILE) || (file_exists(self::LOCK_FILE) && ! is_file(self::LOCK_FILE))) {
            self::logUnlocked(self::LOCK_FILE . ' is a symlink or not a regular file', $operation, $consequence);

            return $work(false);
        }

        $previousUmask = umask(0077);
        $handle = @fopen(self::LOCK_FILE, 'c');
        umask($previousUmask);

        if ($handle === false) {
            self::logUnlocked(self::LOCK_FILE . ' could not be opened', $operation, $consequence);

            return $work(false);
        }

        // An existing file from an older version of this plugin is tightened
        // here, the way the shell side does it - through protect(), so a
        // symlink standing in for the lock file is not a way to have root
        // change the mode of something else. Serialisation is unaffected
        // either way: flock() is on the open handle, not on the name.
        self::protect(self::LOCK_FILE);

        // Non-blocking with a bounded retry, rather than waiting forever on
        // whatever is holding it.
        $locked = false;
        for ($attempt = 0; $attempt < self::LOCK_WAIT * 20; $attempt++) {
            if (@flock($handle, $operation | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(50_000);
        }

        if ( ! $locked) {
            self::logUnlocked('still held after ' . self::LOCK_WAIT . 's', $operation, $consequence);
        }

        try {
            // Whether the lock was actually taken. Most of the work here is
            // safe either way - that is what failing open means - but removing
            // another writer's staged file is not tidying up if that writer is
            // still using it.
            return $work($locked);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Failing open is deliberate, but silent failing open is not diagnosable:
     * a page that read one file from a save and the other from the previous
     * one looks like a plugin bug and leaves no trace of the interleaving.
     * rc.docktail says the same thing into the same file, so both sides of
     * the pair read alike in /var/log/docktail.log.
     *
     * The same lock is taken from three places, and what losing it means is
     * different in each: a page render can read a half-applied pair, an
     * unfenced save can interleave with another, and the boot migration now
     * declines to run at all. So the consequence is the caller's to state -
     * withLock() cannot know it, and a generic sentence was wrong for
     * whichever caller it did not describe.
     */
    private static function logUnlocked(string $reason, int $operation, string $consequence): void
    {
        $line = sprintf(
            "%s %s: %s the config without the lock: %s. %s\n",
            date('Y-m-d H:i:s'),
            PHP_SAPI === 'cli' ? 'config migration' : 'settings page',
            $operation === LOCK_SH ? 'Reading' : 'Writing',
            $reason,
            $consequence
        );

        @file_put_contents(defined('LOG_FILE') ? LOG_FILE : '/var/log/docktail.log', $line, FILE_APPEND);
    }

    /**
     * Re-write both files through stageFile() and commitStaged(), so a value
     * stored by a plugin version that escaped only \ and " ends up in the one
     * spelling both readers agree on. Run from doinst.sh, because writing new
     * values correctly does nothing for the credential already sitting on the
     * flash, and nothing rewrites it until the person next presses Apply.
     *
     * This is no longer about safety. rc.docktail reads these files rather
     * than sourcing them, so an unescaped `$` in a legacy value is inert
     * either way - it just arrives at the daemon differently depending on
     * which reader saw it, and PHP's ini parser is the one that would keep
     * the backslash. Canonicalising removes that disagreement. It also drops
     * keys nothing reads, which matters because renderBody() would otherwise
     * canonicalise `enable_docktail` into a key the reader obeys.
     *
     * A secret found in docktail.cfg is moved rather than rewritten. Both
     * files are read into one config, so a hand-edited or legacy API key in
     * the settings file works - and rewriting it there would re-publish it at
     * 0644 in a file Unraid Connect backs up, which is the whole reason the
     * credentials file exists. It moves into the 0600 file if that file has
     * nothing under the same key; it is dropped if it does, because a
     * credential set through the settings page is the one to keep, and dropped
     * if the credentials file cannot be read at all, because leaving it in a
     * file that gets uploaded is worse than losing it.
     *
     * An unstorable value is dropped, and dropped differently from
     * coerceSecrets(), which writes every secret key and stores '' for a
     * refused one so the settings page shows the field empty. Here the key is
     * removed outright: this runs with nobody watching, and an empty
     * LOG_LEVEL would override default.cfg with nothing, where an absent one
     * falls back to the shipped value. A credential ends up the same either
     * way - read() fills a missing secret with ''. Which fields went is
     * returned rather than swallowed: an upgrade that empties a saved
     * credential has to say so.
     *
     * A file that is unparseable is left alone; there is nothing to normalise
     * and overwriting it would lose settings. A missing credentials file is
     * created only if there is a secret to move into it. Nothing is written
     * unless the escaped rendering actually differs from what is on the flash:
     * Unraid reinstalls the package on every boot, so an unconditional
     * rewrite would burn a flash write and recreate the credential temp file
     * each time, forever.
     *
     * Every outcome is reported under its own reason, because they ask
     * different things of whoever reads the boot log.
     *
     * @return array{ok: bool, deferred?: bool, dropped: list<string>,
     *         superseded: list<string>,
     *         stranded: list<string>, unremoved: list<string>, moved: list<string>,
     *         protected: list<string>, exposed: list<string>,
     *         quarantined: list<string>,
     *         ignored: list<string>, malformed: list<string>,
     *         unparseable: list<string>, linked: list<string>}
     *         ok is false only when a file that needed converting could not be
     *         written; deferred says the config lock was held by a save, so
     *         nothing was read or written at all and the next boot tries
     *         again; dropped names fields whose value could not be stored;
     *         superseded names settings-file secrets that lost to a credential
     *         already stored; stranded names ones removed because the
     *         credentials file could not be read and the settings file is in
     *         the flash backup; moved names secrets
     *         relocated into the 0600 file; protected names files tightened to
     *         0600 because they hold a credential and cannot be rewritten;
     *         exposed names ones where even that failed; quarantined names
     *         settings files moved aside because they hold a credential, cannot
     *         be read, and are part of the flash backup where they were; ignored names keys
     *         nothing reads; malformed names lines the service skips on syntax
     *         alone; unparseable names files PHP could not parse at all;
     *         linked names config paths that are symlinks, which this leaves
     *         untouched rather than replacing with a plain file
     */
    public static function normalizeStoredFiles(): array
    {
        return self::withLock(static function (bool $locked): array {
            $modes = [self::SETTINGS_FILE => 0644, self::CREDENTIALS_FILE => 0600];

            // No running total for ok: a file that cannot be converted
            // abandons the whole migration, so reaching the end means it
            // worked. One array, because every field of it is a category of
            // thing to say out loud and they are filled from three places.
            $report = [
                'dropped'     => [],
                'superseded'  => [],
                'stranded'    => [],
                'unremoved'   => [],
                'moved'       => [],
                'protected'   => [],
                'exposed'     => [],
                'quarantined' => [],
                'ignored'     => [],
                'malformed'   => [],
                'unparseable' => [],
                'unreadable'  => [],
                'linked'      => [],
            ];
            // Without the lock, nothing. withLock() fails open after five
            // seconds, and this rewrite is a read-stage-rename of the whole
            // pair: run unlocked beside an Apply that is mid-commit, it reads
            // the pre-save values and renames them over what the Apply just
            // wrote - the user's settings gone, or a credential moved back
            // into the world-readable file. The fenced save refuses for the
            // same reason.
            //
            // Deferring is cheap here in a way it is not for a save: this runs
            // on every boot and the rewrite is a no-op once converted, so the
            // next boot does it - and the Apply holding the lock writes the
            // escaped form anyway, which is what the migration is for. The
            // report says so rather than claiming there was nothing to do.
            if ( ! $locked) {
                return ['ok' => true, 'deferred' => true] + $report;
            }

            $staged = [];
            // What each staged file would report, held back until it lands.
            $pending = [];

            // Temps from a writer that died between staging and committing.
            // Only with the lock actually in hand: this deliberately fails
            // open after five seconds, and a slow writer past that point still
            // owns its staged file. A pid in the name does not say whether
            // that pid is still working - and the writer would be another
            // process's, not this one's, so checking liveness would be a race
            // of its own.
            if ($locked) {
                self::sweepAbandonedTemps(array_keys($modes));
            }

            // Read both before writing either: a secret in the settings file
            // has to know whether the credentials file already holds one.
            $state = [];
            foreach (array_keys($modes) as $file) {
                // A link is left exactly as it is. is_file() follows one, so
                // this would otherwise read through it and then rename over
                // it - replacing an arrangement somebody made deliberately
                // with a plain file, silently, on the next boot. A dangling
                // one reads as absent and gets the same treatment for the
                // same reason. Nothing here is this function's to replace.
                if (is_link($file)) {
                    $report['linked'][] = $file;
                    $state[$file]       = ['values' => [], 'malformed' => [], 'usable' => false];

                    continue;
                }

                $read = is_file($file)
                    ? self::parseAsReader($file)
                    : ['values' => [], 'malformed' => [], 'nul' => false, 'unreadable' => false];

                // Reported and left alone: a file holding a NUL is the one
                // file neither reader will take a line from, and one that
                // exists but cannot be read is one this function knows
                // nothing about. Either way there is nothing to normalise and
                // a rewrite would destroy what is there. Everything else is
                // readable - a stray line is reported as malformed and
                // removed by the pass below - which is why there is no longer
                // a separate "PHP cannot parse it" category: since read() uses
                // this same reader, that distinction described nothing.
                if ($read['nul'] || $read['unreadable']) {
                    $state[$file] = ['values' => [], 'malformed' => [], 'usable' => false];

                    // Named as left alone only if it is going to be: the
                    // quarantine below moves it, and reporting both would be
                    // two contradictory lines about one file.
                    if ( ! self::holdsSecret($file)) {
                        $report[$read['unreadable'] ? 'unreadable' : 'unparseable'][] = $file;

                        continue;
                    }

                    // A credential in a file nothing can read, so it cannot be
                    // moved out line by line - and for docktail.cfg a chmod is
                    // not enough either, because that file is part of Unraid
                    // Connect's flash backup whatever its mode. So the file is
                    // moved aside instead: renamed, kept, 0600, under a name
                    // the backup ignores and the temp sweep does not touch.
                    // Nothing is lost that was not already lost - neither
                    // reader could use a line of it - and the person has the
                    // file to repair.
                    if ($file === self::SETTINGS_FILE) {
                        // Timestamp for a person, random suffix so two
                        // installs in the same second - or two migrations at
                        // once - cannot have one replace the other's repair
                        // copy.
                        $aside = sprintf(
                            '%s.unreadable.%s.%s',
                            $file,
                            date('Ymd-His'),
                            bin2hex(random_bytes(3))
                        );
                        if ( ! file_exists($aside) && @rename($file, $aside)) {
                            // Reported as quarantined only if it is actually
                            // private now: the aside keeps the mode it had, so
                            // a failed chmod leaves the credential readable
                            // and claiming protection would be false.
                            // The aside is named with why it was moved, so the
                            // install log can say "cannot be read" rather than
                            // claiming a NUL byte it has not seen.
                            $report[self::protect($aside) ? 'quarantined' : 'exposed'][] =
                                $read['unreadable'] ? $aside . ' (unreadable)' : $aside;
                            continue;
                        }

                        // The rename failed, so the credential is still under
                        // the name the flash backup includes. A chmod does not
                        // fix that - it only stops local readers - so this is
                        // an exposure whatever it returns.
                        self::protect($file);
                        $report['exposed'][] = $file;
                        $report[$read['unreadable'] ? 'unreadable' : 'unparseable'][] = $file;
                        continue;
                    }

                    // credentials.cfg is excluded from the backup already, so
                    // the mode is the whole exposure there - and a failed
                    // chmod is the loudest thing this function can find.
                    $report[self::protect($file) ? 'protected' : 'exposed'][] = $file;
                    $report[$read['unreadable'] ? 'unreadable' : 'unparseable'][] = $file;

                    continue;
                }

                $state[$file] = ['values' => $read['values'], 'malformed' => $read['malformed'], 'usable' => true];
            }

            $state = self::relocateSecrets($state, $report);

            // Enforced whether or not the bytes change, and before the
            // rewrite: a file already in canonical form is never rewritten -
            // that is the flash-write saving - so this is the only thing that
            // would ever tighten a credentials file an older version left at
            // 0644. Only the credentials file: docktail.cfg is settings, it is
            // backed up by Unraid Connect on purpose, and nothing that belongs
            // in the other file is left in it.
            // Every tighten goes through protect(), which refuses a symlink.
            foreach ($modes as $file => $mode) {
                if ($mode !== 0600 || ! is_file($file) || (@fileperms($file) & 0777) === 0600) {
                    continue;
                }

                $report[self::protect($file) ? 'protected' : 'exposed'][] = $file;
            }

            foreach ($modes as $file => $mode) {
                if ( ! $state[$file]['usable']) {
                    continue;
                }

                $values = $state[$file]['values'];
                if ($values === [] && $state[$file]['malformed'] === [] && ! is_file($file)) {
                    continue;
                }

                $lost    = [];
                $unknown = [];
                $broken  = $state[$file]['malformed'];
                foreach ($values as $key => $value) {
                    // Only keys the reader obeys survive the rewrite. Anything
                    // else - an unknown name, or a line the reader skips -
                    // would be canonicalised by renderBody() into something it
                    // does obey, which is a config change nobody asked for.
                    if ( ! in_array($key, self::KNOWN_KEYS, true)) {
                        $unknown[] = $key;
                        unset($values[$key]);
                        continue;
                    }

                    if ( ! self::containsUnstorable($value)) {
                        continue;
                    }

                    // A list keeps its clean entries, exactly as
                    // coerceSettings() treats one: dropping the whole key
                    // would silently stop ignoring services the person asked
                    // DockTail to leave alone.
                    $cleaned = in_array($key, self::LIST_SETTINGS, true) ? self::normalizeList($value) : '';
                    $lost[]  = self::REFUSABLE_FIELDS[$key] ?? $key;

                    if ($cleaned === '') {
                        unset($values[$key]);
                        continue;
                    }

                    $values[$key] = $cleaned;
                }

                if (self::renderBody($values) === (string) @file_get_contents($file)) {
                    continue;
                }

                $tmp = self::stageFile($file, $values, $mode);
                if ($tmp === false) {
                    // Nothing lands. Converting the settings while the
                    // credentials keep their legacy spelling leaves the pair
                    // mixed, and doinst.sh schedules its restart either way -
                    // so the whole migration is abandoned and reported, with
                    // both files exactly as the old version left them.
                    self::discardStaged($staged);

                    return self::migrationFailed($report);
                }

                $staged[$file] = [$tmp, $mode];
                $pending[]     = [$lost, $unknown, $broken];
            }

            // Both conversions land together or neither does: a rename that
            // fails after the first one succeeded is the same mixed pair, and
            // the reports are held back until the files are actually on disk.
            // A moved secret is two writes - out of one file, into the other -
            // so a half-landed pair would duplicate it at 0644.
            if ($staged !== [] && ! self::commitStaged($staged)) {
                return self::migrationFailed($report);
            }

            foreach ($pending as [$lost, $unknown, $broken]) {
                $report['dropped']   = array_merge($report['dropped'], $lost);
                $report['ignored']   = array_merge($report['ignored'], $unknown);
                $report['malformed'] = array_merge($report['malformed'], $broken);
            }

            return ['ok' => true] + $report;
        }, LOCK_EX, 'Nothing was read or written, so a save in progress keeps what it saved; the next boot converts the config instead.');
    }

    /**
     * Make a credential-bearing file private - unless it is a symlink.
     *
     * chmod() follows links and has no option not to, so a link at one of
     * these paths turns every tighten below into root setting the mode of
     * whatever it points at, chosen by whoever placed the link, on every boot.
     * Replacing a link the operator put there is not this function's decision,
     * and following it is worse than leaving it, so a link is a failure to
     * protect - which the callers already report as an exposure for the
     * operator to look at.
     *
     * The installer refuses the same thing for the same reason. This is the
     * boot side of it, and it is the one that runs every time.
     */
    private static function protect(string $file): bool
    {
        return ! is_link($file) && @chmod($file, 0600);
    }

    /**
     * Take any secret out of the settings file.
     *
     * read() merges both files, so a secret in docktail.cfg is a working
     * credential - and rewriting it where it sits would re-publish it at 0644.
     * It moves into the 0600 file when that file has nothing under the key,
     * and is dropped when it does, because a credential set through the
     * settings page is the one to keep. If the credentials file cannot be
     * written - unparseable - the secret is dropped rather than left behind in
     * a file about to be rewritten world-readable.
     *
     * @param  array<string, array{values: array<string, string>, malformed: list<string>, usable: bool}> $state
     * @param  list<string> $moved   filled with the fields relocated
     * @param  list<string> $dropped filled with the fields discarded
     * @return array<string, array{values: array<string, string>, malformed: list<string>, usable: bool}>
     */
    private static function relocateSecrets(array $state, array &$report): array
    {
        if ( ! $state[self::SETTINGS_FILE]['usable']) {
            return $state;
        }

        foreach (self::SECRET_KEYS as $key) {
            if ( ! isset($state[self::SETTINGS_FILE]['values'][$key])) {
                continue;
            }

            $value = $state[self::SETTINGS_FILE]['values'][$key];
            unset($state[self::SETTINGS_FILE]['values'][$key]);
            $label = self::REFUSABLE_FIELDS[$key] ?? $key;

            // Each outcome is reported under its own reason, because they call
            // for different things from whoever reads the log: a value that
            // cannot be stored needs correcting, one that lost to an existing
            // credential needs nothing, and one stranded by an unparseable
            // credentials file needs that file repaired.
            if ($value === '') {
                continue;
            }

            if (self::containsUnstorable($value)) {
                $report['dropped'][] = $label;
                continue;
            }

            if ( ! $state[self::CREDENTIALS_FILE]['usable']) {
                // Removed, not kept. Keeping it was the previous answer and it
                // was wrong for a reason a chmod cannot fix: docktail.cfg is a
                // settings file, so it is *not* excluded from Unraid Connect's
                // flash backup - and the settings page promises a credential
                // never leaves this server. A 0600 secret in a file that gets
                // uploaded breaks that promise more quietly than losing the
                // value does, so the value goes and the report says exactly
                // what to do about it.
                $report['stranded'][] = $label;
                continue;
            }

            // What is already in the credentials file only wins if it is going
            // to survive this migration. A value carrying a backtick is about
            // to be dropped by the pass below, and preferring it over a usable
            // legacy value would leave the person with no credential at all.
            $existing = $state[self::CREDENTIALS_FILE]['values'][$key] ?? '';
            if ($existing !== '' && ! self::containsUnstorable($existing)) {
                $report['superseded'][] = $label;
                continue;
            }

            // Said out loud too: the value being overwritten here is one
            // somebody set through the settings page, and it is only being
            // overwritten because this migration cannot store it.
            if ($existing !== '') {
                $report['dropped'][] = $label;
            }

            $state[self::CREDENTIALS_FILE]['values'][$key] = $value;
            $report['moved'][]                             = $label;
        }

        return $state;
    }

    /**
     * Does this file hold a credential?
     *
     * Asked of the file the readers have refused, so it cannot go through
     * parseAsReader() - that returns nothing for a file holding a NUL, which
     * would answer "no secret here" about a file whose first line is an API
     * key. It reads the bytes and looks for the assignment, deliberately
     * loosely: this decides whether to tighten a mode, where a false positive
     * costs nothing and a false negative leaves a credential readable.
     */
    private static function holdsSecret(string $file): bool
    {
        $body = @file_get_contents($file);

        // A file that exists and cannot be read has to be assumed to hold one:
        // this decides whether to move it out of the flash backup, and "I could
        // not look" is not "there is nothing there". A readable empty file is
        // genuinely empty.
        if ($body === false) {
            return is_file($file);
        }

        if ($body === '') {
            return false;
        }

        $keys = implode('|', array_map('preg_quote', self::SECRET_KEYS));

        // Scanned twice: as it is, and with NUL bytes removed. A NUL can sit
        // inside the key name or straight after the `=`, which would hide the
        // assignment from any pattern - and a file holding a NUL is exactly
        // the file this scan is asked about, because that is the file neither
        // reader will touch. Stripping them cannot invent a credential: the
        // key name and its value still have to be there.
        $bodies = [$body];
        if (strpos($body, "\0") !== false) {
            $bodies[] = str_replace("\0", '', $body);
        }

        // Deliberately looser than the reader: this decides whether to move a
        // file out of the flash backup, so it has to catch a hand-edited line
        // the reader would reject - leading space, lowercase key, spaces around
        // the `=`, no quotes at all. A false positive costs a rename and a log
        // line; a false negative leaves a credential in a file that gets
        // uploaded. A non-empty value is still required: KEY="" is how the
        // settings page stores a field somebody cleared.
        // The assignment anywhere on a line, with no requirement about the
        // first byte of the value: `KEY=" secret"` is a credential, and so is
        // one sitting after bytes no parser would accept. The single exception
        // is an explicitly empty value, which is how the settings page stores
        // a field somebody cleared - matching that would quarantine a file
        // over a field that holds nothing.
        // The empty-value exception applies to the body as it is: `KEY=""` is
        // how the settings page stores a cleared field. It must NOT apply to
        // the NUL-stripped copy, where `KEY="\0"` becomes `KEY=""` - a value
        // made of NUL bytes is still bytes somebody put in a credential field,
        // in a file that is about to be left in the flash backup.
        if (preg_match('/(?:' . $keys . ')[ \t]*=[ \t]*(?!""[ \t]*(?:$|[\r\n]))/im', $body) === 1) {
            return true;
        }

        return isset($bodies[1])
            && preg_match('/(?:' . $keys . ')[ \t]*=/im', $bodies[1]) === 1;
    }


    /**
     * Remove staged files nobody is going to commit.
     *
     * By age, not by name. The pid in the name says nothing about liveness,
     * and holding the lock is not proof that no writer is staging: write()
     * fails open after its own bounded wait, so a save that timed out on an
     * earlier holder can be mid-stage while this runs. Staging is two syscalls
     * on a file of a few hundred bytes, so anything untouched for a quarter of
     * an hour is abandoned; anything newer is left for the next boot.
     *
     * `<file>.tmp` is swept the same way: versions of this plugin before the
     * pid suffix staged there, and a credentials write that failed under one of
     * them left the whole secret in `credentials.cfg.tmp`, which nothing reads
     * and nothing else would remove.
     *
     * @param list<string> $files the destinations whose temps to sweep
     */
    private static function sweepAbandonedTemps(array $files): void
    {
        $cutoff = time() - self::TEMP_ABANDONED_AFTER;

        foreach ($files as $file) {
            foreach (array_merge([$file . '.tmp'], glob($file . '.tmp.*') ?: []) as $stale) {
                if ( ! is_file($stale)) {
                    continue;
                }

                $touched = @filemtime($stale);
                if ($touched !== false && $touched < $cutoff) {
                    @unlink($stale);
                    continue;
                }

                // Kept, because it may belong to a writer that is still going
                // - but not left readable. A version of this plugin before the
                // private creation wrote the body first and set the mode
                // afterwards, so a crash could leave the whole credential at
                // 0644 under the fixed name, and the grace period above is
                // exactly how long that would sit there. A chmod costs the
                // live writer nothing: its own temp is already 0600, and the
                // mode is not what it is about to rename.
                // Through protect(), like every other tighten: a symlink
                // here - dropped in under a name this glob matches - would
                // otherwise have root setting 0600 on its target. A link is
                // never something this function wrote, so there is nothing
                // lost by leaving it exactly as it is.
                if ((@fileperms($stale) & 0777) !== 0600) {
                    self::protect($stale);
                }
            }
        }
    }

    /**
     * @param  array<string, list<string>> $report what happened before the write failed
     * @return array<string, bool|list<string>>
     */
    private static function migrationFailed(array $report): array
    {
        // A credential this run was going to move is still in the settings
        // file, because nothing was written. That file is part of the flash
        // backup, so the mode is a consolation rather than a fix - but it is
        // the only thing available here, and saying nothing at all was the
        // previous behaviour. The fields go in unremoved, which tells the
        // person exactly what is still where.
        $unremoved = array_merge($report['stranded'], $report['moved']);
        $protected = $report['protected'];
        $exposed   = $report['exposed'];

        // Whatever the report said: a duplicate that lost precedence, or a
        // line the reader rejected, leaves a credential in that file too, and
        // the categories those produce are not in $unremoved. The file is what
        // matters here, not which decision put the secret in it.
        if (self::holdsSecret(self::SETTINGS_FILE)) {
            if (self::protect(self::SETTINGS_FILE)) {
                $protected[] = self::SETTINGS_FILE;
            } else {
                $exposed[] = self::SETTINGS_FILE;
            }
        }

        // Nothing landed on disk, so the file-level reports are dropped:
        // saying a credential was moved while it is still sitting where it was
        // would be worse than saying nothing. What did happen regardless of
        // the write - a mode tightened, a credential left exposed, a file that
        // cannot be read - is kept, because those are true either way.
        //
        // A stranded secret becomes its own outcome here. The successful path
        // removes it from docktail.cfg; when the write fails it is still in
        // there, in a file the flash backup includes, so reporting it as
        // removed would be exactly backwards.
        return [
            'ok'          => false,
            'dropped'     => [],
            'superseded'  => [],
            'stranded'    => [],
            'unremoved'   => $unremoved,
            'moved'       => [],
            'protected'   => $protected,
            'exposed'     => $exposed,
            'quarantined' => $report['quarantined'],
            'ignored'     => [],
            'malformed'   => [],
            'unparseable' => $report['unparseable'],
            'unreadable'  => $report['unreadable'],
            // Carried, not emptied: a symlinked config path is still a
            // symlinked config path when the write that failed had nothing to
            // do with it, and every caller of this reads the same keys.
            'linked'      => $report['linked'],
        ];
    }

    /**
     * A value written here is read back by two different parsers: PHP's
     * parse_ini_file() (and Unraid's own parse_plugin_cfg()) on this side, and
     * rc.docktail's own KEY="value" reader on the other, which unescapes `\\`,
     * `\"` and `\$` and nothing else.
     *
     * Those three are what is escaped here, and the set is not arbitrary: they
     * are exactly what both readers agree on. PHP's ini parser reads `\$` back
     * as a plain `$`, so a credential containing one survives the round trip
     * intact.
     *
     * rc.docktail used to source these files, which made bash the parser and
     * every `$` in a credential a command substitution. It does not any more,
     * so this escaping is no longer what separates data from code - but it is
     * still what keeps the file safe for anything that does source it, and
     * what keeps PHP's reader and the shell's reader agreeing.
     *
     * A backtick and a line break cannot be spelled in a way both readers
     * accept, so they are refused at validation time instead - see
     * containsUnstorable() and its callers below.
     *
     * @param array<string, string> $values
     */
    private static function renderBody(array $values): string
    {
        $body = '';
        foreach ($values as $key => $value) {
            $key = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string) $key));
            if ($key === '' || $key === null) {
                continue;
            }
            $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], (string) $value);
            $body .= sprintf("%s=\"%s\"\n", $key, $escaped);
        }

        return $body;
    }

    /**
     * Write a body next to where it belongs, without putting it there.
     *
     * The pair has to move together - new settings beside the previous
     * credentials is a config nobody submitted - so a caller with two files to
     * write stages both and only then commits. A rename within a directory is
     * atomic and needs no space, so once both temps exist the commit is as
     * close to all-or-nothing as a filesystem allows; the window that remains
     * is a rename failing on I/O, not on the config being too big for the
     * flash or the write being cut short.
     *
     * @param  array<string, string> $values
     * @return string|false the staged path, or false if nothing was staged
     */
    private static function stageFile(string $file, array $values, int $mode): string|false
    {
        return self::stageBytes($file, self::renderBody($values), $mode);
    }

    /**
     * Write bytes to a temp beside where they are going, private from the
     * moment they exist.
     *
     * The secret goes in before any chmod could run, so the mode has to be
     * right at creation: a `credentials.cfg` temp sitting 0644 in a 0755
     * directory with the whole OAuth client in it is the same leak as the real
     * file having the wrong mode. `x` creates it or fails, so it never follows
     * a symlink somebody left at the name, and never inherits the mode of a
     * file already there.
     *
     * The name carries the pid and eight random hex digits, because the lock
     * around all of this deliberately fails open after five seconds: two
     * writers can be here at once, and on one fixed `.tmp` path the second
     * would unlink the first's staged bytes and the commit would publish
     * whichever won. Abandoned temps are swept by the migration at boot.
     *
     * @return string|false the staged path, or false if nothing was staged
     */
    private static function stageBytes(string $file, string $body, int $mode): string|false
    {
        $tmp = sprintf('%s.tmp.%d.%s', $file, getmypid(), bin2hex(random_bytes(4)));

        $previousUmask = umask(0077);
        $handle        = @fopen($tmp, 'x');
        umask($previousUmask);
        if ($handle === false) {
            return false;
        }

        $written = @fwrite($handle, $body);
        @fclose($handle);
        if ($written !== strlen($body)) {
            // A short write leaves a truncated temp, and the flash filling up
            // is exactly when that happens. Renaming it would publish it.
            @unlink($tmp);

            return false;
        }

        // Widening, where the file is meant to be readable: settings are 0644.
        // Before the rename, so the file is never briefly wrong at its final
        // name.
        @chmod($tmp, $mode);

        return $tmp;
    }

    /**
     * Move staged files into place, and put back what was there if one of them
     * fails once another has already landed.
     *
     * Renaming within a directory is the most reliable write a filesystem
     * offers, but it is not free of failure, and the pair is only worth
     * staging if a late failure cannot still publish half of it. What was on
     * disk is read before the first rename and written back after a failed
     * one, so the observable end states are: the new pair, or the old pair.
     *
     * The restore is itself a write and can itself fail - on a flash that has
     * just failed a rename, that is not unlikely. There is nothing below it to
     * fall back on; the caller reports a failed save either way, and the
     * settings page then shows what is actually stored.
     *
     * What this is atomic against is everything inside one process: a failed
     * write, a failed rename, a rollback, a worker that dies. What it is not
     * atomic against is the machine stopping between the two renames - a power
     * loss during an Apply can leave the new settings beside the previous
     * credentials. Nothing short of a journal fixes that, which is more
     * machinery than two files of a few hundred bytes deserve, and a marker
     * file was tried and removed: it cannot tell an interrupted save from a
     * hand-edited docktail.cfg, which is a thing people do and this code
     * supports, so it reported the supported case as a fault. The observable
     * consequence is that the settings page shows the mixed pair and an Apply
     * writes a consistent one.
     *
     * @param array<string, array{string, int}> $staged destination => [staged path, mode]
     */
    private static function commitStaged(array $staged): bool
    {
        // Contents and mode: a settings file that was tightened to 0600
        // because it holds a credential must not come back from a rollback at
        // the 0644 the new one was going to have.
        $previous = [];
        foreach (array_keys($staged) as $file) {
            $was = @file_get_contents($file);

            // Absent and unreadable are different answers. null means there
            // was no file, and a rollback deletes what this commit created;
            // an existing file that cannot be read has no rollback at all, so
            // nothing is renamed - publishing the new pair would leave no way
            // back to a config that is still sitting there.
            if ($was === false) {
                if (is_file($file)) {
                    self::discardStaged($staged);

                    return false;
                }

                $previous[$file] = null;
                continue;
            }

            $mode            = @fileperms($file);
            $previous[$file] = [$was, $mode === false ? 0600 : ($mode & 0777)];
        }

        $landed = [];
        foreach ($staged as $file => [$tmp, $mode]) {
            if (@rename($tmp, $file)) {
                // Again after the rename: an existing file keeps its own mode
                // through a rename onto it.
                @chmod($file, $mode);
                $landed[] = $file;
                continue;
            }

            @unlink($tmp);
            self::discardStaged(array_diff_key($staged, array_flip($landed)));
            self::restore($landed, $previous);

            return false;
        }

        return true;
    }

    /**
     * @param list<string>                                 $landed   files already renamed
     * @param array<string, array{string, int}|null>       $previous file => [bytes, mode] before, null if absent
     */
    private static function restore(array $landed, array $previous): void
    {
        foreach ($landed as $file) {
            $before = $previous[$file] ?? null;
            if ($before === null) {
                // There was no file before this commit created one.
                @unlink($file);
                continue;
            }

            // The mode it had, not the mode the new one was going to have: a
            // settings file holding a stranded credential is 0600, and putting
            // its bytes back at 0644 would publish them. Staged the same way
            // as a new value, so the bytes are never in a loose temp either.
            [$was, $mode] = $before;

            $tmp = self::stageBytes($file, $was, $mode);
            if ($tmp === false) {
                continue;
            }

            if ( ! @rename($tmp, $file)) {
                @unlink($tmp);
                continue;
            }

            @chmod($file, $mode);
        }
    }

    /** @param array<string, array{string, int}> $staged */
    private static function discardStaged(array $staged): void
    {
        foreach ($staged as [$tmp]) {
            @unlink($tmp);
        }
    }

    /**
     * Coerce the settings half of a POST. Every field is constrained to a value
     * DockTail actually accepts, so a bad form submission can never produce a
     * daemon that crash-loops on startup.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, string>
     */
    public static function coerceSettings(array $post): array
    {
        $current = self::read();
        $out     = [];

        foreach (['ENABLE_DOCKTAIL', 'DELETE_UNUSED_SERVICES', 'SKIP_SHUTDOWN_CLEANUP'] as $key) {
            $raw = (string) ($post[$key] ?? $current[$key] ?? '0');
            // Exported verbatim: DockTail parses these with strconv.ParseBool,
            // which accepts "1" and "0".
            $out[$key] = $raw === '1' ? '1' : '0';
        }

        $tailnet = trim((string) ($post['TAILSCALE_TAILNET'] ?? ''));
        // "-" is DockTail's own "whichever tailnet the credentials belong to",
        // which is the right thing to fall back to for a value we refuse.
        if ($tailnet === '' || self::containsUnstorable($tailnet)) {
            $tailnet = '-';
        }
        $out['TAILSCALE_TAILNET'] = $tailnet;

        $out['DEFAULT_SERVICE_TAGS'] = self::normalizeList((string) ($post['DEFAULT_SERVICE_TAGS'] ?? ''));
        if ($out['DEFAULT_SERVICE_TAGS'] === '') {
            $out['DEFAULT_SERVICE_TAGS'] = 'tag:container';
        }
        $out['IGNORE_SERVICE_NAMES'] = self::normalizeList((string) ($post['IGNORE_SERVICE_NAMES'] ?? ''));

        $interval = trim((string) ($post['RECONCILE_INTERVAL'] ?? ''));
        $out['RECONCILE_INTERVAL'] = preg_match('/^\d+(ms|s|m|h)$/', $interval) === 1 ? $interval : '60s';

        $level             = strtolower(trim((string) ($post['LOG_LEVEL'] ?? '')));
        $out['LOG_LEVEL']  = in_array($level, self::LOG_LEVELS, true) ? $level : 'info';

        return $out;
    }

    /**
     * Credentials are taken verbatim after trim(): they are opaque tokens and
     * any normalisation beyond whitespace would corrupt them.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, string>
     */
    public static function coerceSecrets(array $post): array
    {
        $out = [];
        foreach (self::SECRET_KEYS as $key) {
            $value = trim((string) ($post[$key] ?? ''));
            // Dropped rather than stored, and reported by apply.php through
            // refusedFields(): keeping the previous value would be worse - the
            // person would believe a credential was saved that was not.
            $out[$key] = self::containsUnstorable($value) ? '' : $value;
        }

        return $out;
    }

    /**
     * A value that cannot be stored in these files at all.
     *
     * A line break, because rc.docktail reads them one KEY="value" line at a
     * time: a value carrying a newline would be an unrecognised line and get
     * skipped, losing the setting rather than storing it.
     *
     * A NUL, because a shell variable cannot hold one. PHP would store it
     * happily and the reader could not reproduce it, so the two sides would
     * disagree about what was saved - which is the whole failure this guard
     * exists to prevent.
     *
     * A backtick, because PHP's ini parser keeps the backslash of an escaped
     * one while bash strips it, so there is no single spelling both readers
     * agree on. rc.docktail no longer sources these files, so this is no
     * longer the difference between data and code - but a file that stays
     * safe to source is worth keeping, and a value nobody can round-trip is
     * not worth storing.
     *
     * `$` is not in here: renderBody() escapes it as `\$`, which PHP reads
     * back as a plain `$` and rc.docktail unescapes the same way, so a
     * credential containing one is stored and read back intact.
     *
     * Note the callers trim() first, so a line break or a NUL at either end
     * is removed rather than refused - a token pasted with a trailing newline
     * still saves. Only one inside the value is refused, which is the case no
     * amount of trimming can rescue.
     */
    private static function containsUnstorable(string $value): bool
    {
        // NUL checked on its own: strpbrk()'s character list is a C string,
        // and while PHP 8.5 does match a NUL in it, nothing in the docs
        // promises that, and strpos() is free.
        return strpos($value, "\0") !== false || strpbrk($value, "`\r\n") !== false;
    }

    /**
     * The fields of a POST carrying a value that cannot be stored, labelled as
     * the settings page labels them, so apply.php can say which value it
     * dropped. A refusal has to be spoken: an emptied credential behind a bare
     * "Settings saved." leaves the same wrong belief as keeping the old value
     * would.
     *
     * Only that rule is reported. The other coercions replace a bad
     * value with a stated default the page shows on its next render -
     * RECONCILE_INTERVAL falling back to 60s, say - so they speak for
     * themselves; an emptied password field does not.
     *
     * @param  array<string, mixed>  $post
     * @return list<string>
     */
    public static function refusedFields(array $post): array
    {
        $refused = [];
        foreach (self::REFUSABLE_FIELDS as $key => $label) {
            $raw = trim((string) ($post[$key] ?? ''));

            // A list is judged per entry, because that is how normalizeList()
            // treats it: `tag:a,\ntag:b` loses nothing once each entry is
            // trimmed, so reporting a refusal there would name a field whose
            // value was stored whole.
            $unstorable = in_array($key, self::LIST_SETTINGS, true)
                ? self::listHasUnstorableEntry($raw)
                : self::containsUnstorable($raw);

            if ($unstorable) {
                $refused[] = $label;
            }
        }

        return $refused;
    }

    private static function listHasUnstorableEntry(string $value): bool
    {
        foreach (array_map('trim', explode(',', $value)) as $entry) {
            if ($entry !== '' && self::containsUnstorable($entry)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeList(string $value): string
    {
        $parts = array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $p): bool => $p !== '' && ! self::containsUnstorable($p)
        );

        return implode(',', array_unique($parts));
    }

    /**
     * Settings tab.
     */
    public static function render(): string
    {
        $stored = self::snapshot();
        $cfg    = $stored['values'];
        $token = csrfToken();

        $select = static function (string $name, array $options, string $selected, string $class = 'narrow'): string {
            $html = sprintf("<select name='%s' size='1' class='%s'>", h($name), h($class));
            foreach ($options as $value => $label) {
                $html .= sprintf(
                    "<option value='%s'%s>%s</option>",
                    h((string) $value),
                    ((string) $value === $selected ? ' selected' : ''),
                    h($label)
                );
            }

            return $html . '</select>';
        };

        $yesNo = ['1' => 'Yes', '0' => 'No'];

        ob_start(); ?>
<form method="POST" id="docktail_settings" action="/plugins/docktail/apply.php" onsubmit="docktailApply();return false;">
<input type="hidden" name="csrf_token" value="<?= h($token); ?>">
<input type="hidden" name="action" value="save">
<!-- What the stored pair looked like when this form was rendered. apply.php
     refuses a save that was composed against an older one rather than letting
     it overwrite whatever landed since - see Config::write(). -->
<input type="hidden" name="revision" id="docktail_revision" value="<?= h($stored['revision']); ?>">

<table class="unraid tablesorter"><thead><tr><td>DockTail Settings</td></tr></thead></table>

<blockquote class="inline_help">
    Click any setting name to read what it does. The Help button in the header toggles
    all of them at once.
</blockquote>

<dl>
    <dt>Enable DockTail:</dt>
    <dd><?= $select('ENABLE_DOCKTAIL', $yesNo, $cfg['ENABLE_DOCKTAIL'] ?? '0'); ?></dd>
</dl>
<blockquote class="inline_help">
    Runs DockTail as a service on this Unraid host, tied to the Docker lifecycle: it starts
    once Docker is up and drains before Docker stops.
    Setting this to No stops the service, which withdraws every Service it advertises unless
    <em>Skip shutdown cleanup</em> is on.
    DockTail also needs the Tailscale plugin running and this node tagged &mdash; the Status
    tab checks both.
    <br><br>
    Only run one DockTail per host. If you already run DockTail as a Docker container, stop it
    before enabling this, or the two will fight over the same serve configuration.
</blockquote>

<table class="unraid tablesorter"><thead><tr><td>Tailscale Control Plane</td></tr></thead></table>

<dl>
    <dt>OAuth Client ID:</dt>
    <dd><input type="text" name="TAILSCALE_OAUTH_CLIENT_ID" value="<?= h($cfg['TAILSCALE_OAUTH_CLIENT_ID'] ?? ''); ?>" autocomplete="off"></dd>
</dl>
<blockquote class="inline_help">
    The client ID half of a Tailscale OAuth client, created under
    <em>Settings &rarr; OAuth clients</em> in the Tailscale admin console. It needs the
    <code>all</code> scope.
    <br><br>
    Credentials are what let DockTail <em>create</em> Service definitions in your tailnet.
    Without them it can still advertise Services, but only ones that already exist in your
    tailnet policy. When both an OAuth client and an API key are set, OAuth wins.
</blockquote>

<dl>
    <dt>OAuth Client Secret:</dt>
    <dd><input type="password" name="TAILSCALE_OAUTH_CLIENT_SECRET" value="<?= h($cfg['TAILSCALE_OAUTH_CLIENT_SECRET'] ?? ''); ?>" autocomplete="new-password"></dd>
</dl>
<blockquote class="inline_help">
    The secret half of the OAuth client. Shown only once when you create the client in the
    Tailscale admin console, so generate a new client if you did not keep it.
    <br><br>
    Stored in <code>/boot/config/plugins/docktail/credentials.cfg</code> with mode
    <code>0600</code>, which the plugin excludes from Unraid Connect's flash backup so it is
    never uploaded to the cloud.
    <br><br>
    A backtick <em>inside</em> a credential is refused rather than stored, as is a line break
    or a NUL: the service reads this file one <code>KEY="value"</code> line at a time, and
    none of the three survives that round trip. Whitespace at either end &mdash; including a
    trailing newline from a paste &mdash; is trimmed rather than refused. Every other
    character, <code>$</code> included, is stored as typed.
</blockquote>

<dl>
    <dt>API Key:</dt>
    <dd><input type="password" name="TAILSCALE_API_KEY" value="<?= h($cfg['TAILSCALE_API_KEY'] ?? ''); ?>" autocomplete="new-password"></dd>
</dl>
<blockquote class="inline_help">
    An alternative to the OAuth client above &mdash; set one or the other, not both.
    Tailscale API keys expire after 90 days, at which point DockTail silently stops being able
    to create Service definitions, so an OAuth client is the better choice for an always-on
    server.
</blockquote>

<dl>
    <dt>Tailnet:</dt>
    <dd><input type="text" name="TAILSCALE_TAILNET" value="<?= h($cfg['TAILSCALE_TAILNET'] ?? '-'); ?>"></dd>
</dl>
<blockquote class="inline_help">
    Which tailnet the Control Plane API calls apply to.
    Leave this as <code>-</code> and DockTail uses whichever tailnet the credentials belong to,
    which is what you want unless the credentials can reach more than one.
</blockquote>

<table class="unraid tablesorter"><thead><tr><td>Services</td></tr></thead></table>

<dl>
    <dt>Default service tags:</dt>
    <dd><input type="text" name="DEFAULT_SERVICE_TAGS" value="<?= h($cfg['DEFAULT_SERVICE_TAGS'] ?? 'tag:container'); ?>"></dd>
</dl>
<blockquote class="inline_help">
    Comma-separated ACL tags applied to Services whose container carries no
    <code>docktail.tags</code> label.
    <br><br>
    These tags must already exist in your tailnet policy file, and your ACLs must permit the
    devices you expect to reach the tagged Services. These are tags on the <em>Service</em>,
    which are separate from the tags on this Unraid <em>node</em> shown on the DockTail tab.
</blockquote>

<dl>
    <dt>Ignored service names:</dt>
    <dd><input type="text" name="IGNORE_SERVICE_NAMES" value="<?= h($cfg['IGNORE_SERVICE_NAMES'] ?? ''); ?>"></dd>
</dl>
<blockquote class="inline_help">
    Comma-separated Service names DockTail must never touch, written without the
    <code>svc:</code> prefix.
    Use this for Services you manage by hand on this node, or ones another tool owns, so
    DockTail neither reconciles nor deletes them.
</blockquote>

<dl>
    <dt>Delete unused services:</dt>
    <dd><?= $select('DELETE_UNUSED_SERVICES', $yesNo, $cfg['DELETE_UNUSED_SERVICES'] ?? '0'); ?></dd>
</dl>
<blockquote class="inline_help">
    Deletes a Service <em>definition</em> from your tailnet once no host anywhere advertises
    it. Off by default, because it removes tailnet configuration rather than just local serve
    state.
    <br><br>
    The decision uses the tailnet-wide advertiser count, so this is safe with several DockTail
    instances: a Service any other host advertises is never deleted, and nothing is deleted
    when an API call fails. It needs Control Plane credentials; without them the cleanup is
    skipped entirely. Note it can also delete definitions DockTail never created if nothing
    advertises them &mdash; protect those with <em>Ignored service names</em>.
</blockquote>

<dl>
    <dt>Skip shutdown cleanup:</dt>
    <dd><?= $select('SKIP_SHUTDOWN_CLEANUP', $yesNo, $cfg['SKIP_SHUTDOWN_CLEANUP'] ?? '0'); ?></dd>
</dl>
<blockquote class="inline_help">
    Normally DockTail withdraws every Service and Funnel it advertises when it stops, so
    nothing it configured stays reachable while it is down.
    <br><br>
    Turning this on leaves them advertised, which keeps services reachable across a DockTail
    restart &mdash; but a Service whose container is gone stays advertised too, and its
    hostname keeps resolving to a dead backend until DockTail runs again.
</blockquote>

<table class="unraid tablesorter"><thead><tr><td>Advanced</td></tr></thead></table>

<dl>
    <dt>Reconcile interval:</dt>
    <dd><input type="text" name="RECONCILE_INTERVAL" value="<?= h($cfg['RECONCILE_INTERVAL'] ?? '60s'); ?>" class="narrow"></dd>
</dl>
<blockquote class="inline_help">
    How often DockTail compares your labels against what is actually advertised and fixes any
    difference. A Go duration: <code>250ms</code>, <code>30s</code>, <code>5m</code>,
    <code>1h</code>. Anything unparseable falls back to <code>60s</code>.
    <br><br>
    DockTail also reacts to Docker events as they happen, so this only bounds how quickly it
    notices drift that produced no event &mdash; a Service removed in the Tailscale admin
    console, for example.
</blockquote>

<dl>
    <dt>Log level:</dt>
    <dd><?= $select('LOG_LEVEL', ['debug' => 'debug', 'info' => 'info', 'warn' => 'warn', 'error' => 'error'], $cfg['LOG_LEVEL'] ?? 'info'); ?></dd>
</dl>
<blockquote class="inline_help">
    Verbosity of <code>/var/log/docktail.log</code>, linked from the DockTail tab.
    <code>debug</code> adds the label parsing and defaulting decisions for each container,
    which is what to use when a Service is not advertised and the reason is not obvious.
    Rotated daily, because <code>/var/log</code> is a RAM filesystem.
</blockquote>

<dl>
    <dt>DockTail Cloud key:</dt>
    <dd><input type="password" name="DOCKTAIL_CLOUD_KEY" value="<?= h($cfg['DOCKTAIL_CLOUD_KEY'] ?? ''); ?>" autocomplete="new-password"></dd>
</dl>
<blockquote class="inline_help">
    Optional, for the hosted DockTail Cloud dashboard. Leave it empty unless you use that:
    the reporting module is completely inert without a key, and DockTail behaves exactly as it
    does now.
</blockquote>

<dl>
    <dt>&nbsp;</dt>
    <dd class="docktail-inline">
        <input type="submit" name="#apply" value="Apply">
        <input type="button" value="Done" onclick="done()">
        <!-- A live region: the whole point of this span is that a refusal is
             announced, and a screen reader has no other way to learn that a
             save came back with a field it would not store. -->
        <span id="docktail_apply_result" class="docktail-apply-result"
              role="status" aria-live="polite" aria-atomic="true"></span>
    </dd>
</dl>

</form>

<script>
/*
 * Submitted over AJAX rather than into the hidden progressFrame, so the outcome
 * is actually visible: a settings write that fails server-side used to be
 * indistinguishable from one that worked.
 *
 * serialize() keeps the hidden csrf_token field, which Unraid's
 * auto_prepend_file requires on every POST.
 */
/*
 * On window, not in this script's scope: the settings page is an AJAX-injected
 * fragment, so switching tabs and coming back re-runs this whole block. A
 * `var` here would reset the in-flight marker while the POST it names is still
 * running, which is exactly the guard below being asked to do its job.
 */
window.docktailApplyState = window.docktailApplyState || {
    request: null,
    dirty: false,
    // A save whose answer never arrived. The form cannot be trusted to
    // describe what is stored, and a retry could land behind it.
    unknown: false,
    // The form matches what was last written, so there is nothing to save.
    // Kept on window because the server renders the button enabled every time
    // the fragment is injected, and an unchanged form that can be submitted is
    // a restart, and a write over whatever another tab saved since.
    clean: false,
    // Bumped by every render. A POST records the generation it was composed
    // from, so its answer can tell whether the form it is about to write to is
    // still the same form - an AJAX fragment can be replaced mid-flight, and
    // another tab can save in the meantime.
    generation: 0,
    bound: false
};

// Every render, not just the first: the fragment arrives with a fresh enabled
// Apply and an empty result line, so whatever this page already knows has to
// be put back onto it.
$(function() {
    var state = window.docktailApplyState;
    var nodes = docktailApplyNodes();

    state.generation++;

    if (state.unknown) {
        docktailApplyUnknown(nodes, 'A previous save did not report back and may still be in '
            + 'progress. Reload to see what is stored before saving again: ');
    } else if (state.request || (state.clean && ! state.dirty)) {
        nodes.apply.prop('disabled', true);
    }

    if (state.bound) {
        // Re-rendered fragment: the handler from the first render is delegated
        // from document and still live, and would fire twice.
        return;
    }

    // An edit means the form no longer matches what was last written, so Apply
    // has to be usable whatever the previous answer was - otherwise a change
    // made just after a clean save, or while one was in flight, cannot be
    // submitted without reloading the tab.
    state.bound = true;
    $(document).on('input change', '#docktail_settings input, #docktail_settings select', function() {
        state.dirty = true;
        state.clean = false;
        if ( ! state.request && ! state.unknown) {
            $('#docktail_settings').find('input[value="Apply"]').prop('disabled', false);
        }
    });
});

/*
 * A save nobody can describe the outcome of. The message carries the way out
 * with it, because the flag lives on window so that re-rendering the fragment
 * cannot clear it - only a page load can, and this is the button that does it.
 */
function docktailApplyUnknown(nodes, message) {
    nodes.out.addClass('docktail-apply-error')
        .empty()
        .append(document.createTextNode(message))
        .append($('<button type="button">')
            .text('Reload')
            .on('click', function() {
                window.location.reload();
            }));
    nodes.apply.prop('disabled', true);
}

/*
 * Looked up on every use rather than held: an AJAX-injected fragment can be
 * replaced between a POST leaving and its answer arriving, and the nodes this
 * page started with are then detached - still writable, and invisible.
 */
function docktailApplyNodes() {
    var form = $('#docktail_settings');

    return {
        form: form,
        out: $('#docktail_apply_result'),
        apply: form.find('input[value="Apply"]')
    };
}

function docktailApply() {
    var nodes = docktailApplyNodes();
    var form = nodes.form;
    var out = nodes.out;
    var apply = nodes.apply;
    var state = window.docktailApplyState;

    // One save at a time. Disabling Apply is not the guard: this function is
    // reachable programmatically and from a form submit, and two answers
    // arriving out of order would describe values the form no longer holds.
    if (state.request) {
        return;
    }

    // A previous save timed out, or its connection dropped, while the server
    // kept going. Aborting the XHR stopped nothing: that request can still be
    // inside Config::write(), and a retry accepted now could land first and
    // then be overwritten by the older form it was meant to replace. Renames
    // are atomic but they are not ordered, and nothing here can order them.
    if (state.unknown) {
        docktailApplyUnknown(nodes, 'A previous save did not report back and may still be in '
            + 'progress. Reload to see what is stored before saving again: ');

        return;
    }

    // Nothing to save. The button is disarmed for this, but the button is not
    // the guard: a form submit reaches here too, and a redundant save means a
    // redundant restart - and a write over whatever another tab has saved
    // since this form was rendered.
    if (state.clean && ! state.dirty) {
        return;
    }

    state.dirty = false;
    out.removeClass('docktail-apply-error').text('Saving...');
    apply.prop('disabled', true);

    // What this POST is answering for: the render it came from and the fence
    // token it carried.
    var sentGeneration = state.generation;
    var sentRevision = $('#docktail_revision').val();

    // Bounded like the Status tab's POSTs: Apply is disarmed until an answer
    // arrives, so a request left pending must time out or the form stays dead.
    state.request = $.ajax({
        url: form.attr('action'),
        type: 'POST',
        timeout: 25000,
        data: form.serialize()
    })
        .done(function(data, status, xhr) {
            // Re-selected, not closed over: the fragment may have been
            // replaced while this POST was open, and writing to the nodes this
            // call started with would put the answer into a detached form
            // nobody can see.
            var live = docktailApplyNodes();

            // A refused value is a partial save: flag it like a failure and
            // rearm Apply, because the person has a value to correct and would
            // otherwise have to reload the tab to resubmit it. An edit made
            // while this was in flight rearms it for the same reason.
            var refused = xhr.getResponseHeader('X-DockTail-Refused');
            live.out.toggleClass('docktail-apply-error', !!refused)
                .text(String(data).trim() || 'Settings saved.');

            // The form on screen may not be the form that submitted this: the
            // fragment can be re-rendered mid-flight, and it comes back with
            // the values and the token that were stored at *its* render. Only
            // the submitting form may be advanced - writing this answer's
            // token onto a newer one would pair a newer token with older
            // fields, which is how a later edit overwrites a save that already
            // landed. A newer form needs no help: its token is already current
            // or its own save will be refused.
            var stillMine = state.generation === sentGeneration
                && $('#docktail_revision').val() === sentRevision;
            var revision = xhr.getResponseHeader('X-DockTail-Revision');

            if ( ! stillMine) {
                // The form on screen is a different one, so its token is its
                // own. If that token is already the one this save produced -
                // the fragment was re-rendered after the write landed - then
                // it matches what is stored and there is nothing to save, so
                // it is clean. Otherwise its Apply has to be put back, because
                // this request greyed it: the render saw a request in flight.
                // Not when something was refused: that answer carries a value
                // the person still has to correct, and greying Apply over it
                // would hide the only thing they can act on.
                if (revision && ! refused && ! state.dirty
                    && $('#docktail_revision').val() === revision) {
                    state.clean = true;
                    live.apply.prop('disabled', true);

                    return;
                }

                live.apply.prop('disabled', ! state.dirty && state.clean);

                return;
            }

            if (revision) {
                $('#docktail_revision').val(revision);
            }

            state.clean = ! refused && ! state.dirty;
            live.apply.prop('disabled', state.clean);
        })
        .fail(function(xhr, status) {
            var live = docktailApplyNodes();

            // Three outcomes say nothing about what is on disk. A timeout and
            // a transport failure (status 0 - the connection dropped, or the
            // tab navigated) can both land after PHP has already written the
            // pair. So can a 500: Config::write() reports failure for a rename
            // that failed after an earlier one succeeded, and if the restore
            // failed as well the pair on disk is mixed. All three leave Apply
            // disarmed with the Reload button that clears the state, because a
            // retry could be overtaken by the save it was meant to replace, or
            // be written on top of a state nobody has looked at.
            //
            // A 403 is the exception: Unraid's CSRF check runs before any of
            // this plugin's code, so nothing was written and a retry is safe.
            // 409: the stored pair changed since this form was rendered, so
            // the server refused it rather than overwriting. Nothing was
            // written, but this form cannot be trusted either - it describes a
            // configuration that is no longer the one stored.
            if (xhr.status === 409) {
                state.unknown = true;
                docktailApplyUnknown(live, String(xhr.responseText || '').trim()
                    || 'Not saved: the stored configuration changed since this page was loaded.');

                return;
            }

            if (status === 'timeout' || xhr.status === 0 || xhr.status >= 500) {
                state.unknown = true;
                docktailApplyUnknown(live, status === 'timeout'
                    ? 'Could not save: the request timed out, and it may still have been applied. '
                      + 'Reload to see what is stored: '
                    : xhr.status === 0
                        ? 'Could not save: the connection dropped before an answer arrived, and it '
                          + 'may still have been applied. Reload to see what is stored: '
                        : 'Could not save: the server reported HTTP ' + xhr.status + '. Part of the '
                          + 'save may have been written - see /var/log/docktail.log. Reload to see '
                          + 'what is stored: ');

                return;
            }

            var detail = xhr.status === 403
                ? 'the webGUI rejected the request (CSRF). Reload the page and try again.'
                : 'HTTP ' + xhr.status + '. See /var/log/docktail.log.';
            live.out.addClass('docktail-apply-error').text('Could not save: ' + detail);
            live.apply.prop('disabled', false);
        })
        .always(function() {
            state.request = null;
        });
}
</script>
        <?php
        return '<div class="docktail-help-scope">' . (string) ob_get_clean() . '</div>' . pageAssets();
    }
}

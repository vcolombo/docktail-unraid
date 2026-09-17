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

    // /var/run, not /tmp and not the flash: /tmp is world-writable, so any
    // local process could create this file first and hold it, and a blocking
    // wait on it would stall every Apply. /var/run is root-owned, and the
    // plugin already keeps its pidfile there.
    public const LOCK_FILE = '/var/run/docktail-config.lock';

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
     * @return array<string, string>
     */
    public static function read(): array
    {
        $values = self::defaults();

        // parse_plugin_cfg() is only defined when Unraid's webGUI helpers are
        // loaded (i.e. on a .page render), so the endpoints get a plain merge.
        if (function_exists('parse_plugin_cfg')) {
            $merged = \parse_plugin_cfg(PLUGIN_NAME);
            if (is_array($merged)) {
                $values = array_merge($values, array_map('strval', $merged));
            }
        } else {
            $values = array_merge($values, self::readFile(self::SETTINGS_FILE));
        }

        $values = array_merge($values, self::readFile(self::CREDENTIALS_FILE));

        foreach (self::SECRET_KEYS as $key) {
            $values[$key] ??= '';
        }

        return $values;
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return self::readFile(PLUGIN_ROOT . '/default.cfg');
    }

    /** @return array<string, string> */
    private static function readFile(string $file): array
    {
        return self::parseFile($file) ?? [];
    }

    /**
     * Parsed values, or null when the file exists but PHP cannot parse it.
     *
     * Callers that only want values use readFile(); the migration needs the
     * distinction, because a file that fails to parse is not the same as an
     * empty one or one holding nothing but comments - only the first means the
     * settings page and the service disagree about what is configured.
     *
     * @return array<string, string>|null
     */
    private static function parseFile(string $file): ?array
    {
        if ( ! is_file($file)) {
            return [];
        }
        $parsed = @parse_ini_file($file);

        return is_array($parsed) ? array_map('strval', $parsed) : null;
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
     * @return array{values: array<string, string>, rejected: list<string>}
     */
    private static function parseAsReader(string $file): array
    {
        $values   = [];
        $rejected = [];

        foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = rtrim($line, "\r");
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^([A-Z][A-Z0-9_]*)="(.*)"$/', $line, $m) !== 1) {
                $rejected[] = self::lineLabel($line);
                continue;
            }

            $value = self::unescapeValue($m[2]);
            if ($value === null) {
                $rejected[] = $m[1];
                continue;
            }

            $values[$m[1]] = $value;
        }

        return ['values' => $values, 'rejected' => $rejected];
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
     * Persist both files. Written to a temporary file and renamed, so a
     * concurrent rc.docktail read never sees a half-written config.
     *
     * @param array<string, string> $settings
     * @param array<string, string> $secrets
     */
    public static function write(array $settings, array $secrets): bool
    {
        return self::withLock(static function () use ($settings, $secrets): bool {
            // Inside the lock: two first-time saves would otherwise both see
            // the directory missing, and the one whose mkdir() lost would
            // report a failure for a directory that now exists.
            if ( ! is_dir(CONFIG_DIR) && ! @mkdir(CONFIG_DIR, 0755, true) && ! is_dir(CONFIG_DIR)) {
                return false;
            }

            $ok = self::writeFile(self::SETTINGS_FILE, $settings, 0644);

            return self::writeFile(self::CREDENTIALS_FILE, $secrets, 0600) && $ok;
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
     *
     * It fails open twice over: an unopenable lock file, or one already held
     * for longer than the wait below, must not stop somebody saving their
     * settings. Losing the serialisation is a worse-but-rare outcome; a webGUI
     * that hangs on Apply is an immediate one.
     *
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    private static function withLock(callable $work)
    {
        $handle = @fopen(self::LOCK_FILE, 'c');
        if ($handle === false) {
            return $work();
        }

        // Non-blocking with a bounded retry, rather than waiting forever on
        // whatever is holding it.
        for ($attempt = 0; $attempt < 40; $attempt++) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                break;
            }
            usleep(50_000);
        }

        try {
            return $work();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Re-write both files through writeFile(), so a value stored by a plugin
     * version that escaped only \ and " ends up in the one spelling both
     * readers agree on. Run from doinst.sh, because writing new values
     * correctly does nothing for the credential already sitting on the flash,
     * and nothing rewrites it until the person next presses Apply.
     *
     * This is no longer about safety. rc.docktail reads these files rather
     * than sourcing them, so an unescaped `$` in a legacy value is inert
     * either way - it just arrives at the daemon differently depending on
     * which reader saw it, and PHP's ini parser is the one that would keep
     * the backslash. Canonicalising removes that disagreement. It also drops
     * keys nothing reads, which matters because renderBody() would otherwise
     * canonicalise `enable_docktail` into a key the reader obeys.
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
     * A file that is missing or unparseable is left alone; there is nothing to
     * normalise and overwriting it would lose settings. Nothing is written
     * unless the escaped rendering actually differs from what is on the flash:
     * Unraid reinstalls the package on every boot, so an unconditional rewrite
     * would burn a flash write and recreate the credential temp file each
     * time, forever.
     *
     * @return array{ok: bool, dropped: list<string>, ignored: list<string>,
     *         unparseable: list<string>} ok is false only when a file that
     *         needed converting could not be written; dropped names the fields
     *         whose value did not survive; ignored names keys removed because
     *         nothing reads them; unparseable names files left alone because
     *         PHP could not parse them at all
     */
    public static function normalizeStoredFiles(): array
    {
        return self::withLock(static function (): array {
            $ok          = true;
            $dropped     = [];
            $ignored     = [];
            $unparseable = [];

            foreach ([self::SETTINGS_FILE => 0644, self::CREDENTIALS_FILE => 0600] as $file => $mode) {
                if ( ! is_file($file)) {
                    continue;
                }

                // Reported, not rewritten: a file PHP cannot parse is one the
                // settings page shows as defaults while the service still uses
                // whatever lines are valid, and guessing at it is how a
                // credential gets lost. An empty or comments-only file parses
                // fine and is simply left alone.
                if (self::parseFile($file) === null) {
                    $unparseable[] = $file;
                    continue;
                }

                $read   = self::parseAsReader($file);
                $values = $read['values'];
                if ($values === [] && $read['rejected'] === []) {
                    continue;
                }

                $lost    = [];
                $unknown = $read['rejected'];
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

                if (self::renderBody($values) === @file_get_contents($file)) {
                    continue;
                }

                if ( ! self::writeFile($file, $values, $mode)) {
                    // Nothing changed on disk, so nothing is reported: saying
                    // a credential was dropped while it is still sitting
                    // there would be worse than saying nothing.
                    $ok = false;
                    continue;
                }

                $dropped = array_merge($dropped, $lost);
                $ignored = array_merge($ignored, $unknown);
            }

            return [
                'ok'          => $ok,
                'dropped'     => $dropped,
                'ignored'     => $ignored,
                'unparseable' => $unparseable,
            ];
        });
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

    /** @param array<string, string> $values */
    private static function writeFile(string $file, array $values, int $mode): bool
    {
        $body = self::renderBody($values);
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $body) === false) {
            return false;
        }
        @chmod($tmp, $mode);
        if ( ! @rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        @chmod($file, $mode);

        return true;
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
        $cfg   = self::read();
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
        <span id="docktail_apply_result" class="docktail-apply-result"></span>
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
var docktailApplyRequest = null;
var docktailApplyDirty = false;

// An edit means the form no longer matches what was last written, so Apply has
// to be usable whatever the previous answer was - otherwise a change made just
// after a clean save, or while one was in flight, cannot be submitted without
// reloading the tab.
$(function() {
    $('#docktail_settings').on('input change', 'input, select', function() {
        docktailApplyDirty = true;
        if ( ! docktailApplyRequest) {
            $('#docktail_settings').find('input[value="Apply"]').prop('disabled', false);
        }
    });
});

function docktailApply() {
    var form = $('#docktail_settings');
    var out = $('#docktail_apply_result');
    var apply = form.find('input[value="Apply"]');

    // One save at a time. Disabling Apply is not the guard: this function is
    // reachable programmatically and from a form submit, and two answers
    // arriving out of order would describe values the form no longer holds.
    if (docktailApplyRequest) {
        return;
    }

    docktailApplyDirty = false;
    out.removeClass('docktail-apply-error').text('Saving...');
    apply.prop('disabled', true);

    // Bounded like the Status tab's POSTs: Apply is disarmed until an answer
    // arrives, so a request left pending must time out or the form stays dead.
    docktailApplyRequest = $.ajax({
        url: form.attr('action'),
        type: 'POST',
        timeout: 25000,
        data: form.serialize()
    })
        .done(function(data, status, xhr) {
            // A refused value is a partial save: flag it like a failure and
            // rearm Apply, because the person has a value to correct and would
            // otherwise have to reload the tab to resubmit it. An edit made
            // while this was in flight rearms it for the same reason.
            var refused = xhr.getResponseHeader('X-DockTail-Refused');
            out.toggleClass('docktail-apply-error', !!refused)
               .text(String(data).trim() || 'Settings saved.');
            apply.prop('disabled', ! refused && ! docktailApplyDirty);
        })
        .fail(function(xhr, status) {
            var detail = status === 'timeout'
                ? 'the request timed out. It may still have been applied - reload the tab to '
                  + 'see what is stored before retrying.'
                : xhr.status === 403
                    ? 'the webGUI rejected the request (CSRF). Reload the page and try again.'
                    : 'HTTP ' + xhr.status + '. See /var/log/docktail.log.';
            out.addClass('docktail-apply-error').text('Could not save: ' + detail);
            // Rearmed: a browser-side timeout says nothing about what the
            // server did, and a form that can never be submitted again is
            // worse than a retry that may repeat a write which is atomic
            // anyway - writeFile() renames into place.
            apply.prop('disabled', false);
        })
        .always(function() {
            docktailApplyRequest = null;
        });
}
</script>
        <?php
        return '<div class="docktail-help-scope">' . (string) ob_get_clean() . '</div>' . pageAssets();
    }
}

<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Config;

use InvalidArgumentException;
use TomVondracek\RequestAnalytics\Storage\StorageTarget;

/**
 * Typed, validated view of `config/extensions/tomvondracek-requestanalytics.yaml`.
 *
 * Built in two steps, so the hot path doesn't validate: validate() checks the
 * YAML once and gives the constructor arguments, which ConfigLoader caches;
 * fromValidated() only resolves `%env()%` (never cached) and builds the object.
 *
 * @phpstan-type Arguments array{enabled: bool, ownHosts: list<string>, aliasHosts: list<string>, aliasTarget: ?string, aliasMode: string, aliasRedirectStatus: int, aliasParam: string, excludePattern: ?string, routeTemplates: bool, searchParams: list<string>, countReloads: bool, storageDatabase: string, spoolDir: string, maxSpoolMb: int, aggregationTrigger: string, timeBudgetMs: int, chunkSize: int, visitIntervalMinutes: int, rollupUrl: ?string, rollupToken: ?string, widgetTarget: string, widgetDefaultRange: string, widgetCompare: string, widgetMetric: string, honorGpc: bool, honorDnt: bool, optOutMode: string, reportThresholdK: int, retentionMonths: int, rawMaxDays: int, viewRoles: non-empty-list<string>, deleteRoles: non-empty-list<string>, warnings: list<string>, publicDir?: ?string, projectDir?: string}
 */
final readonly class RequestAnalyticsConfig
{
    public const ALIAS_MODE_COUNT = 'count';
    public const ALIAS_MODE_TAG = 'tag';
    public const TRIGGER_ADMIN = 'admin';
    public const TRIGGER_CRON = 'cron';
    public const TRIGGER_BOTH = 'both';
    public const OPT_OUT_SKIP = 'skip';
    public const OPT_OUT_MINIMAL = 'minimal';

    /**
     * The periods of the analytics page, up to today: the last 7 or 30 days, 3 months, the year to date, the last 12 months or 3 years.
     */
    public const RANGES = ['7d', '30d', '3m', 'ytd', '1y', '3y'];

    public const COMPARISONS = ['previous', 'year', 'none'];
    public const METRICS = ['pageviews', 'entries'];
    public const WIDGET_TARGETS = ['dashboard_below_header', 'dashboard_bottom', 'dashboard_aside_top', 'dashboard_aside_middle', 'dashboard_aside_bottom'];
    public const WIDGET_TARGET_DEFAULT = 'dashboard_aside_top';

    /**
     * About three million page views or bot requests that wait for the rollup.
     */
    public const MAX_SPOOL_MB_DEFAULT = 1024;

    public const STORAGE_DATABASE_DEFAULT = '%kernel.project_dir%/var/data/request-analytics.db';

    /**
     * How long a visit may wait in the spool for the rollup; 0 = until the
     * rollup. Visits start a rollup themselves (`visit_interval_minutes`), so
     * nothing waits long unless the database is unreachable.
     */
    public const RAW_MAX_DAYS_DEFAULT = 0;

    /**
     * How often a visit starts a rollup, at most: once an hour.
     */
    public const VISIT_INTERVAL_MINUTES_DEFAULT = 60;

    /**
     * The secret of the rollup URL (RollupUrlListener): long enough not to be
     * guessed, and only characters a URL keeps as they are.
     */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9._~-]{32,256}$/';

    /**
     * Different characters a token has at least: `aaaa…` or `abab…` is no secret.
     */
    private const TOKEN_MIN_DISTINCT = 10;

    private const TOKEN_EXPECTED = 'a secret of 32 to 256 letters, digits and `-._~` (with `rollup_url`), best from the environment, e.g. from `php -r "echo bin2hex(random_bytes(24));"`';

    /**
     * The path of the rollup URL: one of the site's own, plain characters.
     */
    private const ROLLUP_URL_PATTERN = '~^/[A-Za-z0-9._\~-]+(?:/[A-Za-z0-9._\~-]+)*$~';

    /**
     * Delimiter of the combined `exclude_paths` expression: a character no
     * pattern written in YAML contains, so none of them needs escaping.
     */
    private const REGEX_DELIMITER = "\x01";

    /**
     * Who may see the statistics, and who may delete them, unless the config
     * says otherwise. Bolt's ROLE_DEVELOPER includes ROLE_ADMIN.
     */
    public const ACCESS_ROLES_DEFAULT = ['ROLE_ADMIN'];

    /**
     * Bolt's web root below the project directory, unless `bolt.public_folder` says otherwise.
     */
    public const PUBLIC_FOLDER_DEFAULT = 'public';

    /**
     * A role of Symfony's security: `ROLE_` and capitals, digits, underscores.
     */
    private const ROLE_PATTERN = '/^ROLE_[A-Z0-9_]+$/';

    /**
     * @param list<string> $ownHosts lowercase
     * @param list<string> $aliasHosts lowercase; hosts, or other markers in tag mode
     * @param list<string> $searchParams
     */
    private function __construct(
        public bool $enabled,
        public array $ownHosts,
        public array $aliasHosts,
        /** Scheme and host (and an optional base path) without a trailing slash. */
        public ?string $aliasTarget,
        public string $aliasMode,
        public int $aliasRedirectStatus,
        public string $aliasParam,
        /** All `exclude_paths` as one expression; null when there are none. */
        public ?string $excludePattern,
        /** Replace ids, UUIDs and hashes in paths with `:id`. */
        public bool $routeTemplates,
        public array $searchParams,
        public bool $countReloads,
        /** The SQLite file, or the URL of the database, of the extension's tables (see StorageTarget). */
        public string $storageDatabase,
        public string $spoolDir,
        /** The spool takes at most this much; 0 = no limit. */
        public int $maxSpoolMb,
        public string $aggregationTrigger,
        public int $timeBudgetMs,
        public int $chunkSize,
        /** A visit starts a rollup at most this often (after its response is sent); 0 = never. */
        public int $visitIntervalMinutes,
        /** The path a hosting's cron calls to start a rollup (`?token=…`); null = off. */
        public ?string $rollupUrl,
        /** The secret the rollup URL needs; null while its environment variable is not set. */
        public ?string $rollupToken,
        public string $widgetTarget,
        public string $widgetDefaultRange,
        public string $widgetCompare,
        public string $widgetMetric,
        public bool $honorGpc,
        public bool $honorDnt,
        public string $optOutMode,
        public int $reportThresholdK,
        public int $retentionMonths,
        /** Spool files older than this many days are deleted unread; 0 = kept until the rollup. */
        public int $rawMaxDays,
        /** @var non-empty-list<string> roles that see the statistics (any one of them) */
        public array $viewRoles,
        /** @var non-empty-list<string> roles that may delete statistics (any one of them, besides one of $viewRoles) */
        public array $deleteRoles,
        /** @var list<string> problems that don't stop the extension, such as unknown keys */
        public array $warnings = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $config raw (possibly partial) YAML config
     * @param string $projectDir replaces `%kernel.project_dir%` in paths
     *
     * @throws InvalidArgumentException for invalid values
     */
    public static function fromArray(array $config, string $projectDir): self
    {
        return self::fromValidated(self::validate($config, $projectDir));
    }

    /**
     * The constructor arguments of a valid config, `%env()%` still unresolved.
     *
     * @internal
     *
     * @param array<array-key, mixed> $config raw (possibly partial) YAML config
     * @param string $projectDir replaces `%kernel.project_dir%` in paths
     * @param string $publicFolder Bolt's web root below the project (`bolt.public_folder`): no file of the statistics may be in it
     *
     * @return Arguments
     *
     * @throws InvalidArgumentException for invalid values
     */
    public static function validate(array $config, string $projectDir, string $publicFolder = self::PUBLIC_FOLDER_DEFAULT): array
    {
        $root = new ConfigSection($config);
        $aliases = $root->section('aliases');
        $storage = $root->section('storage');
        $collector = $root->section('collector');
        $aggregation = $root->section('aggregation');
        $widget = $root->section('widget');
        $privacy = $root->section('privacy');
        $access = $root->section('access');

        $aliasHosts = self::lowercase($aliases->stringList('hosts'));
        foreach ($aliasHosts as $host) {
            // Stored with every hit through the alias: letters, digits, dots, hyphens.
            if (preg_match('/^[\p{L}\p{N}][\p{L}\p{M}\p{N}._-]*$/u', $host) !== 1 || mb_strlen($host) > 255) {
                throw $aliases->invalid('hosts', 'a list of host names or markers of letters, digits, dots and hyphens', $host);
            }
        }
        $aliasTarget = self::aliasTarget($aliases, $aliasHosts);
        $aliasParam = $aliases->string('param') ?? 'ra_via';
        if (preg_match('/^[a-z][a-z0-9_]*$/i', $aliasParam) !== 1) {
            throw $aliases->invalid('param', 'a query parameter name of letters, digits and underscores', $aliasParam);
        }

        $storageDatabase = self::storageDatabase($storage, $projectDir);

        // No longer used: the User-Agent is classified in each process (see
        // UserAgentParser). Still read, so a config of version 1.0 is valid.
        $collector->enum('ua_cache', ['apcu', 'none'], 'apcu');

        $arguments = [
            'enabled' => $root->bool('enabled', true),
            'ownHosts' => self::lowercase($root->stringList('own_hosts')),
            'aliasHosts' => $aliasHosts,
            'aliasTarget' => $aliasTarget,
            'aliasMode' => $aliases->enum('mode', [self::ALIAS_MODE_COUNT, self::ALIAS_MODE_TAG], self::ALIAS_MODE_COUNT),
            'aliasRedirectStatus' => $aliases->enum('redirect_status', [301, 302, 307, 308], 301),
            'aliasParam' => $aliasParam,
            'excludePattern' => self::excludePattern($root),
            'routeTemplates' => $root->bool('route_templates', true),
            'searchParams' => $root->stringList('search_params'),
            'countReloads' => $root->bool('count_reloads', true),
            'storageDatabase' => $storageDatabase,
            'spoolDir' => str_replace('%kernel.project_dir%', $projectDir, $collector->string('spool_dir') ?? '%kernel.project_dir%/var/request-analytics/spool'),
            'maxSpoolMb' => $collector->int('max_spool_mb', self::MAX_SPOOL_MB_DEFAULT, 0),
            'aggregationTrigger' => $aggregation->enum('trigger', [self::TRIGGER_ADMIN, self::TRIGGER_CRON, self::TRIGGER_BOTH], self::TRIGGER_ADMIN),
            'timeBudgetMs' => $aggregation->int('time_budget_ms', 1500, 0, 600_000),
            'chunkSize' => $aggregation->int('chunk_size', 25_000, 1),
            'visitIntervalMinutes' => $aggregation->int('visit_interval_minutes', self::VISIT_INTERVAL_MINUTES_DEFAULT, 0, 1440),
            'rollupUrl' => self::rollupUrl($aggregation),
            'rollupToken' => self::token($aggregation),
            'widgetTarget' => $widget->enum('target', self::WIDGET_TARGETS, self::WIDGET_TARGET_DEFAULT),
            'widgetDefaultRange' => $widget->enum('default_range', self::RANGES, '30d'),
            'widgetCompare' => $widget->enum('compare', self::COMPARISONS, 'previous'),
            'widgetMetric' => $widget->enum('metric', self::METRICS, 'pageviews'),
            'honorGpc' => $privacy->bool('honor_gpc', true),
            'honorDnt' => $privacy->bool('honor_dnt', true),
            'optOutMode' => $privacy->enum('opt_out_mode', [self::OPT_OUT_SKIP, self::OPT_OUT_MINIMAL], self::OPT_OUT_MINIMAL),
            'reportThresholdK' => $privacy->int('report_threshold_k', 5, 1),
            'retentionMonths' => $privacy->int('retention_months', 25, 0),
            'rawMaxDays' => $privacy->int('raw_max_days', self::RAW_MAX_DAYS_DEFAULT, 0),
            'viewRoles' => self::roles($access, 'view'),
            'deleteRoles' => self::roles($access, 'delete'),
            'warnings' => [],
            // Checked once `%env()%` is resolved, in fromValidated().
            'publicDir' => self::publicDir($projectDir, $publicFolder),
            // Relative paths are resolved against it, in fromValidated().
            'projectDir' => $projectDir,
        ];

        // A key nobody reads is most likely a typo; it is reported, but it
        // must not stop the statistics.
        foreach ([$root, $aliases, $storage, $collector, $aggregation, $widget, $privacy, $access] as $section) {
            $unknown = $section->unknownKeys();
            if ($unknown !== null) {
                $arguments['warnings'][] = $unknown;
            }
        }

        return $arguments;
    }

    /**
     * @internal
     *
     * @param Arguments $arguments from validate()
     */
    public static function fromValidated(array $arguments): self
    {
        $projectDir = $arguments['projectDir'] ?? '';
        unset($arguments['projectDir']);

        // A relative path would depend on the working directory: `public/` for
        // the web server, the project for the console, so two databases.
        $storageDatabase = self::env($arguments['storageDatabase']) ?? $arguments['storageDatabase'];
        $sqlitePath = StorageTarget::sqlitePath($storageDatabase);
        if ($sqlitePath !== null && ! self::isAbsolute($sqlitePath)) {
            $storageDatabase = self::absolute($sqlitePath, $projectDir);
        }

        $arguments['storageDatabase'] = $storageDatabase;
        $arguments['rollupToken'] = $arguments['rollupToken'] === null ? null : self::resolvedToken($arguments['rollupToken'], $arguments['warnings']);
        if ($arguments['rollupUrl'] !== null && $arguments['rollupToken'] === null) {
            $arguments['warnings'][] = sprintf('The Request Analytics `aggregation.rollup_url` is set, but `aggregation.rollup_token` is empty (its environment variable is not set): the URL answers no call. Check config/extensions/%s.yaml and .env.local.', ConfigLoader::CONFIG_BASENAME);
        }
        $arguments['spoolDir'] = self::absolute(mb_rtrim(self::env($arguments['spoolDir']) ?? $arguments['spoolDir'], '/\\'), $projectDir);

        // Anything in the web root can be downloaded by anybody.
        $publicDir = $arguments['publicDir'] ?? null;
        unset($arguments['publicDir']);
        if ($publicDir !== null) {
            foreach (['storage.database' => StorageTarget::sqlitePath($arguments['storageDatabase']), 'collector.spool_dir' => $arguments['spoolDir']] as $key => $path) {
                if ($path !== null && self::isInside($path, $publicDir)) {
                    throw new InvalidArgumentException(sprintf('The Request Analytics `%s` must not be in the public folder of the site (%s), where anybody could download it. Check config/extensions/%s.yaml.', $key, $publicDir, ConfigLoader::CONFIG_BASENAME));
                }
            }
        }

        return new self(...$arguments);
    }

    /**
     * The SQLite file (the default) or the URL of a MySQL, MariaDB or
     * PostgreSQL database of the extension's tables: always a database of
     * their own, never Bolt's (`main` of earlier versions is refused; that
     * the file or URL is not Bolt's database is checked before connecting,
     * see AnalyticsDatabase). Checked here unless it comes from `%env()%`,
     * which is only known at runtime.
     */
    private static function storageDatabase(ConfigSection $storage, string $projectDir): string
    {
        $database = $storage->string('database') ?? self::STORAGE_DATABASE_DEFAULT;
        if ($database === 'main') {
            throw $storage->invalid('database', "the path of a SQLite file or the URL of a database of the statistics' own (they are never stored in Bolt's database)", $database);
        }

        $database = str_replace('%kernel.project_dir%', $projectDir, $database);
        if (! str_contains($database, '%env(')) {
            StorageTarget::fromConfig($database);
        }

        return $database;
    }

    /**
     * A list of roles; none = the default.
     *
     * @return non-empty-list<string>
     */
    private static function roles(ConfigSection $access, string $key): array
    {
        $roles = $access->stringList($key);
        foreach ($roles as $role) {
            if (preg_match(self::ROLE_PATTERN, $role) !== 1) {
                throw $access->invalid($key, 'a list of roles such as ROLE_ADMIN', $role);
            }
        }

        return $roles === [] ? self::ACCESS_ROLES_DEFAULT : $roles;
    }

    /**
     * The web root of the site; null without a project directory (tools, tests).
     */
    private static function publicDir(string $projectDir, string $publicFolder): ?string
    {
        if ($projectDir === '') {
            return null;
        }

        return self::canonical($projectDir . '/' . mb_trim($publicFolder === '' ? self::PUBLIC_FOLDER_DEFAULT : $publicFolder, '/\\'));
    }

    /**
     * Whether $path is $directory or below it, `.` and `..` resolved, and
     * links too where the path exists.
     */
    private static function isInside(string $path, string $directory): bool
    {
        $path = self::canonical($path);

        return $path === $directory || str_starts_with($path, $directory . '/');
    }

    /**
     * The absolute path as the filesystem resolves it: the part that exists
     * through realpath() (links and `..`), the rest that does not exist yet
     * segment by segment.
     */
    /**
     * @internal
     */
    public static function canonical(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $missing = [];
        $existing = $path;
        while (realpath($existing) === false) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                break;
            }

            array_unshift($missing, basename($existing));
            $existing = $parent;
        }

        $real = realpath($existing);
        $segments = explode('/', is_string($real) ? str_replace('\\', '/', $real) : $existing);
        foreach ($missing as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * Below the project directory unless it is absolute; as it is without a
     * project directory (tools, tests).
     */
    private static function absolute(string $path, string $projectDir): string
    {
        return $projectDir === '' || self::isAbsolute($path) ? $path : mb_rtrim($projectDir, '/\\') . '/' . mb_ltrim($path, '/\\');
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('~^[A-Za-z]:[/\\\\]~', $path) === 1;
    }

    /**
     * Whether requests to this host are answered with the alias redirect.
     */
    public function isAlias(string $host): bool
    {
        return $this->aliasTarget !== null && in_array($host, $this->aliasHosts, true);
    }

    /**
     * Whether an `ra_via` marker from the URL may be stored.
     */
    public function isAliasMarker(string $marker): bool
    {
        return in_array($marker, $this->aliasHosts, true);
    }

    public function isOwnHost(string $host): bool
    {
        return in_array($host, $this->ownHosts, true);
    }

    /**
     * @param string $path decoded request path
     */
    public function isExcluded(string $path): bool
    {
        return $this->excludePattern !== null && preg_match($this->excludePattern, $path) === 1;
    }

    /**
     * Whether opening the dashboard starts a rollup.
     */
    public function rollupOnAdminVisit(): bool
    {
        return $this->aggregationTrigger !== self::TRIGGER_CRON;
    }

    /**
     * Whether a visit starts a rollup, at most every `visit_interval_minutes`:
     * not with `trigger: cron`, which keeps rollups out of web requests.
     */
    public function rollupOnVisit(): bool
    {
        return $this->enabled && $this->aggregationTrigger !== self::TRIGGER_CRON && $this->visitIntervalMinutes > 0;
    }

    /**
     * The path of the rollup URL; null (`false`, the default) when it is off.
     */
    private static function rollupUrl(ConfigSection $aggregation): ?string
    {
        $url = $aggregation->stringOrOff('rollup_url');
        if ($url === null) {
            return null;
        }

        $url = mb_rtrim($url, '/');
        if (preg_match(self::ROLLUP_URL_PATTERN, $url) !== 1 || mb_strlen($url) > 255) {
            throw $aggregation->invalid('rollup_url', 'false, or a path of the site such as /_request-analytics/rollup (letters, digits, `/-._~`)', $url);
        }

        return $url;
    }

    /**
     * The secret of the rollup URL as written in the config; checked here
     * unless it comes from `%env()%`, which is only known at runtime.
     * Required with `rollup_url`.
     */
    private static function token(ConfigSection $aggregation): ?string
    {
        $token = $aggregation->secret('rollup_token', self::TOKEN_EXPECTED);
        if ($token === null) {
            if ($aggregation->stringOrOff('rollup_url') !== null) {
                throw $aggregation->invalid('rollup_token', self::TOKEN_EXPECTED, null);
            }

            return null;
        }

        if (! str_contains($token, '%env(') && ! self::isStrongToken($token)) {
            throw $aggregation->invalid('rollup_token', self::TOKEN_EXPECTED, '(hidden)');
        }

        return $token;
    }

    /**
     * The secret with `%env()%` resolved; null when the variable is not set,
     * or holds no usable secret. A wrong variable must not stop the
     * statistics: the URL is off then, and the dashboard says why.
     *
     * @param list<string> $warnings
     */
    private static function resolvedToken(string $token, array &$warnings): ?string
    {
        $resolved = self::env($token);
        if ($resolved !== null && ! self::isStrongToken($resolved)) {
            $warnings[] = sprintf('The Request Analytics `aggregation.rollup_token` must be %s; the rollup URL is off until it is. Check config/extensions/%s.yaml and the environment variable it names.', self::TOKEN_EXPECTED, ConfigLoader::CONFIG_BASENAME);

            return null;
        }

        return $resolved;
    }

    private static function isStrongToken(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1 && count(array_unique(mb_str_split($token))) >= self::TOKEN_MIN_DISTINCT;
    }

    /**
     * @param list<string> $aliasHosts
     */
    private static function aliasTarget(ConfigSection $aliases, array $aliasHosts): ?string
    {
        $target = $aliases->string('target');
        if ($target === null) {
            if ($aliasHosts !== []) {
                throw $aliases->invalid('target', 'the URL the alias hosts redirect to, such as https://example.com,', null);
            }

            return null;
        }

        $parts = parse_url($target);
        $scheme = is_array($parts) ? mb_strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? mb_strtolower($parts['host'] ?? '') : '';
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['query']) || isset($parts['fragment'])) {
            throw $aliases->invalid('target', 'an http(s) URL without a query string, such as https://example.com,', $target);
        }

        if (in_array($host, $aliasHosts, true)) {
            throw $aliases->invalid('target', 'a host that is not one of `aliases.hosts` (that would redirect forever),', $target);
        }

        return mb_rtrim($target, '/');
    }

    private static function excludePattern(ConfigSection $root): ?string
    {
        $patterns = [];
        foreach ($root->stringList('exclude_paths') as $pattern) {
            if (str_contains($pattern, self::REGEX_DELIMITER) || @preg_match(self::REGEX_DELIMITER . $pattern . self::REGEX_DELIMITER, '') === false) {
                throw $root->invalid('exclude_paths', 'a list of valid regular expressions (without delimiters)', $pattern);
            }

            $patterns[] = '(?:' . $pattern . ')';
        }

        return $patterns === [] ? null : self::REGEX_DELIMITER . implode('|', $patterns) . self::REGEX_DELIMITER . 'i';
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function lowercase(array $values): array
    {
        return array_values(array_unique(array_map(static fn (string $value): string => mb_rtrim(mb_strtolower($value), '.'), $values)));
    }

    /**
     * Replaces `%env(NAME)%`, the way Bolt's own config does. An unset variable
     * is an empty string; a value that ends up empty is null.
     */
    private static function env(string $value): ?string
    {
        if (! str_contains($value, '%env(')) {
            $value = mb_trim($value);

            return $value === '' ? null : $value;
        }

        $resolved = preg_replace_callback('/%env\(([A-Za-z0-9_]+)\)%/', static function (array $matches): string {
            $env = $_ENV[$matches[1]] ?? $_SERVER[$matches[1]] ?? getenv($matches[1]);

            return is_string($env) ? $env : '';
        }, $value);

        return $resolved === null || mb_trim($resolved) === '' ? null : mb_trim($resolved);
    }
}

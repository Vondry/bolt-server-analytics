<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Collector;

use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;

/**
 * Device class, browser family, OS family and bot family of a request. Every
 * value comes from a fixed list, so the stored dimensions stay small.
 *
 * The classification of a User-Agent string is memoised, in the process and
 * (optionally) in APCu. The cache holds nothing about a visitor: its key is a
 * hash of the string, its value the classification.
 */
final class UserAgentParser
{
    public const DEVICE_DESKTOP = 'desktop';
    public const DEVICE_MOBILE = 'mobile';
    public const DEVICE_TABLET = 'tablet';
    public const DEVICE_TV = 'tv';
    public const OTHER = 'other';
    public const OS_ANDROID = 'android';
    public const OS_IOS = 'ios';
    public const OS_LINUX = 'linux';
    public const BOT_GENERIC = 'generic';

    /**
     * Words of a User-Agent that admits to being automated, for the bots no
     * family names. A contact URL (`+http…`) is one: browsers never send it.
     */
    private const BOT_PATTERN = '~bot|crawl|spider|slurp|fetch|scan|scrape|monitor|preview|headless|lighthouse|pagespeed|curl|wget|python-requests|httpclient|go-http-client|java/|axios|node-fetch|okhttp|facebookexternalhit|nutch|heritrix|\+https?://~i';

    /**
     * Case-insensitive alternatives, checked in this order before the pattern
     * above; the first match names the family. Many agents do not call
     * themselves bots (`Claude-User`, `Perplexity-User`, `Google-Agent`,
     * `Scrapy`), so a family stands on its own. Its tokens must never appear
     * in a browser's User-Agent.
     *
     * The AI families are one per operator, whatever the agent does for it
     * (training crawler, search index, fetch on a user's question, hosted
     * browser). Google's AI agents are kept apart from Googlebot, which is a
     * search engine.
     */
    private const BOT_FAMILIES = [
        // Search engines.
        'googlebot' => 'googlebot[/-]|google-inspectiontool|googleother|adsbot-google|mediapartners-google|storebot-google|apis-google|google-safety|google-read-aloud|google-site-verification|google-cws|feedfetcher-google|googleproducer|googlemessages|google-pinpoint|google-ads-conversions',
        'bingbot' => 'bingbot|bingpreview|microsoftpreview|msnbot|adidxbot',
        'seznambot' => 'seznambot|seznamhomepagecrawler|seznam screenshot-generator|sklikbot',
        'applebot' => 'applebot',
        'duckduckbot' => 'duckduckbot|duckassistbot',
        // Every Yandex robot links to yandex.com/bots; YaBrowser does not.
        'yandexbot' => 'yandex\w*bot|yandex\.(?:com|ru)/bots|yadirectfetcher',
        'baiduspider' => 'baiduspider',
        'petalbot' => 'petalbot',
        'qwant' => 'qwantbot|qwantify',
        'mojeek' => 'mojeekbot',
        'kagi' => 'kagibot',
        'yahoo' => 'yahoo! slurp|y!j-',
        'naver' => 'yeti/\d|naverbot',
        'daum' => 'daumoa|daum/\d',
        'coccoc' => 'coccocbot|coccoc/\d',
        'seekport' => 'seekportbot|seekport crawler',
        'mailru' => 'mail\.ru_bot',
        // Not "sogou": the Sogou mobile browser says so too.
        'sogou' => 'sogou[\w ]*spider',
        'qihoo' => '360spider|haosouspider',
        'yisou' => 'yisouspider',

        // SEO tools.
        'ahrefs' => 'ahrefs',
        'semrush' => 'semrush|siteauditbot|splitsignalbot|rytebot',
        'mj12bot' => 'mj12bot',
        'dotbot' => 'dotbot|rogerbot',
        'screamingfrog' => 'screaming frog seo spider',
        'sitebulb' => 'sitebulb',
        'blexbot' => 'blexbot',
        'serpstat' => 'serpstatbot',
        'dataforseo' => 'dataforseobot',
        'barkrowler' => 'barkrowler',
        'seranking' => 'serankingbacklinksbot',
        'sistrix' => 'sistrix',
        'seobility' => 'seobilitybot',
        'xovi' => 'xovibot|xovionpagecrawler',
        'oncrawl' => 'oncrawl',
        'megaindex' => 'megaindex',
        'siteimprove' => 'siteimprove',
        'contentking' => 'contentkingapp',

        // AI operators.
        'gemini' => 'google-agent|googleagent-|google-gemininotebook|google-notebooklm|google-cloudvertexbot',
        'gptbot' => 'gptbot|chatgpt-user|oai-searchbot|oai-adsbot',
        'claudebot' => 'claudebot|claude-user|claude-searchbot|claude-agent|claude-web|anthropic-ai',
        'perplexitybot' => 'perplexitybot|perplexity-user',
        'mistral' => 'mistralai-user',
        'bytespider' => 'bytespider',
        'amazonbot' => 'amazonbot',
        'ccbot' => 'ccbot',

        // Link previews, monitoring, scripts.
        'facebook' => 'facebookexternalhit|facebookbot|meta-externalagent',
        'twitterbot' => 'twitterbot',
        'linkedinbot' => 'linkedinbot',
        'pinterestbot' => 'pinterestbot|pinterest/0\.',
        'slackbot' => 'slackbot|slack-imgproxy',
        'telegrambot' => 'telegrambot',
        'discordbot' => 'discordbot',
        'uptimerobot' => 'uptimerobot',
        'lighthouse' => 'lighthouse|pagespeed',
        'headless' => 'headless',
        'curl' => '^curl/',
        'wget' => '^wget/',
        'python' => 'python-requests|python-urllib|python-httpx|aiohttp|httpx|pycurl|scrapy',
        'perl' => 'libwww-perl|lwp::',
        'go' => 'go-http-client',
        'java' => 'java/|okhttp|apache-httpclient',
        'node' => 'axios|node-fetch|undici',
    ];

    /**
     * A family's token starts a word ("commoncrawl.org" is not Oncrawl).
     * After these words it is a bot copying another one's
     * rules ("like Googlebot", "compatible with Googlebot", "Following
     * Mediapartners-Google", "Fake-Googlebot", "TelegramBot (like
     * TwitterBot)"), not that bot.
     */
    private const IMITATION = '(?<!like |with |following |fake-|like a )';

    private const DEVICE_TV_PATTERN = '~SmartTV|SMART-TV|Tizen|Web0S|AppleTV|CrKey|BRAVIA|HbbTV~i';
    private const DEVICE_TABLET_PATTERN = '~iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))~i';
    private const DEVICE_MOBILE_PATTERN = '~Mobi|iPhone|iPod|Android.*Mobile|Windows Phone~i';

    /**
     * Checked in this order.
     */
    private const BROWSERS = [
        'edge' => '~Edg/|EdgA/|EdgiOS/~',
        'opera' => '~OPR/|Opera~',
        'samsung' => '~SamsungBrowser/~',
        'yandex' => '~YaBrowser/~',
        'firefox' => '~Firefox/|FxiOS/~',
        'chrome' => '~CriOS/|Chrome/~',
        'safari' => '~Version/.*Safari/~',
    ];

    /**
     * Checked in this order.
     */
    private const OPERATING_SYSTEMS = [
        'windows' => '~Windows~',
        'android' => '~Android~',
        'ios' => '~iPhone|iPad|iPod~',
        'macos' => '~Mac OS X|Macintosh~',
        'chromeos' => '~CrOS~',
        'linux' => '~Linux~',
    ];

    /**
     * Brands of `Sec-CH-UA`, lowercase.
     */
    private const BRANDS = [
        'google chrome' => 'chrome',
        'microsoft edge' => 'edge',
        'opera' => 'opera',
        'opera gx' => 'opera',
        'samsung internet' => 'samsung',
        'yandex' => 'yandex',
        'yabrowser' => 'yandex',
        'brave' => 'brave',
        'vivaldi' => 'vivaldi',
        'duckduckgo' => 'duckduckgo',
        'android webview' => 'webview',
        'chromium' => 'chromium',
    ];

    /**
     * Values of `Sec-CH-UA-Platform`, lowercase.
     */
    private const PLATFORMS = [
        'windows' => 'windows',
        'android' => 'android',
        'ios' => 'ios',
        'macos' => 'macos',
        'chrome os' => 'chromeos',
        'chromium os' => 'chromeos',
        'linux' => 'linux',
    ];

    private const MEMO_SIZE = 256;
    private const APCU_TTL = 86400;

    /** @var array<string, UserAgentInfo> */
    private array $memo = [];

    public function __construct(
        private readonly ConfigLoader $configLoader,
    ) {
    }

    /**
     * Classification of the User-Agent string alone.
     */
    public function parse(string $userAgent): UserAgentInfo
    {
        if (isset($this->memo[$userAgent])) {
            return $this->memo[$userAgent];
        }

        $apcuKey = $this->apcuKey($userAgent);
        $info = $apcuKey === null ? null : UserAgentInfo::fromArray(apcu_fetch($apcuKey));

        if (! $info instanceof UserAgentInfo) {
            $info = self::classify($userAgent);
            if ($apcuKey !== null) {
                apcu_store($apcuKey, $info->toArray(), self::APCU_TTL);
            }
        }

        if (count($this->memo) >= self::MEMO_SIZE) {
            $this->memo = [];
        }

        return $this->memo[$userAgent] = $info;
    }

    /**
     * `Sec-CH-UA-Mobile: ?1` wins over the User-Agent string.
     */
    public function device(UserAgentInfo $info, ?string $secChUaMobile): string
    {
        return $secChUaMobile !== null && mb_trim($secChUaMobile) === '?1' ? self::DEVICE_MOBILE : $info->device;
    }

    /**
     * The first brand of `Sec-CH-UA` that is not a GREASE entry, `Chromium` only
     * when it is the only one; the User-Agent string without the header.
     */
    public function browser(UserAgentInfo $info, ?string $secChUa): string
    {
        $brands = self::brands($secChUa);
        if ($brands === []) {
            return $info->browser;
        }

        foreach (array_keys($brands) as $brand) {
            if ($brand !== 'chromium') {
                return self::BRANDS[$brand] ?? self::OTHER;
            }
        }

        return 'chromium';
    }

    /**
     * The brands of `Sec-CH-UA` with their major version, lowercase, in the
     * order sent, without GREASE entries (`"Not_A Brand"`, `"Not)A;Brand"`, …:
     * fake brands browsers add on purpose). Empty without the header.
     *
     * @return array<string, int|null> brand => major version
     */
    public static function brands(?string $secChUa): array
    {
        if ($secChUa === null || preg_match_all('~"((?:[^"\\\\]|\\\\.)*)"\s*;\s*v="?(\d*)~', $secChUa, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }

        $brands = [];
        foreach ($matches as [, $brand, $version]) {
            $brand = mb_strtolower(mb_trim(stripslashes($brand)));
            if ($brand === '' || preg_match('~not.?a.?brand~', $brand) === 1) {
                continue;
            }

            $brands[$brand] ??= $version === '' ? null : (int) $version;
        }

        return $brands;
    }

    /**
     * The OS family of `Sec-CH-UA-Platform`; null without the header or with
     * an empty or "Unknown" one.
     */
    public static function platform(?string $secChUaPlatform): ?string
    {
        if ($secChUaPlatform === null) {
            return null;
        }

        $platform = mb_strtolower(mb_trim($secChUaPlatform, " \t\""));
        if ($platform === '' || $platform === 'unknown') {
            return null;
        }

        return self::PLATFORMS[$platform] ?? self::OTHER;
    }

    /**
     * `Sec-CH-UA-Platform` wins over the User-Agent string.
     */
    public function os(UserAgentInfo $info, ?string $secChUaPlatform): string
    {
        return self::platform($secChUaPlatform) ?? $info->os;
    }

    private static function classify(string $userAgent): UserAgentInfo
    {
        return new UserAgentInfo(
            self::deviceOf($userAgent),
            self::first(self::BROWSERS, $userAgent) ?? self::OTHER,
            self::first(self::OPERATING_SYSTEMS, $userAgent) ?? self::OTHER,
            self::botOf($userAgent),
            preg_match('~(?:Chrome|CriOS)/(\d+)~', $userAgent, $matches) === 1 ? (int) $matches[1] : null,
        );
    }

    private static function botOf(string $userAgent): ?string
    {
        if (mb_trim($userAgent) === '') {
            return self::BOT_GENERIC;
        }

        foreach (self::BOT_FAMILIES as $family => $tokens) {
            if (preg_match('~' . self::IMITATION . '\\b(?:' . $tokens . ')~i', $userAgent) === 1) {
                return $family;
            }
        }

        return preg_match(self::BOT_PATTERN, $userAgent) === 1 ? self::BOT_GENERIC : null;
    }

    private static function deviceOf(string $userAgent): string
    {
        return match (true) {
            preg_match(self::DEVICE_TV_PATTERN, $userAgent) === 1 => self::DEVICE_TV,
            preg_match(self::DEVICE_TABLET_PATTERN, $userAgent) === 1 => self::DEVICE_TABLET,
            preg_match(self::DEVICE_MOBILE_PATTERN, $userAgent) === 1 => self::DEVICE_MOBILE,
            default => self::DEVICE_DESKTOP,
        };
    }

    /**
     * @param array<string, string> $patterns name => regular expression
     */
    private static function first(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }

    private function apcuKey(string $userAgent): ?string
    {
        if ($this->configLoader->load()->uaCache !== RequestAnalyticsConfig::UA_CACHE_APCU
            || ! function_exists('apcu_enabled') || ! apcu_enabled()
        ) {
            return null;
        }

        return 'request_analytics.ua.' . hash('xxh128', $userAgent);
    }
}

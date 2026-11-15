<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Collector;

use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;
use TomVondracek\RequestAnalytics\SiteTime;

/**
 * Turns a request into the row that is stored for it, or into nothing. Uses
 * only what the browser sends unprompted; raw values (IP, User-Agent, Referer)
 * never leave this class.
 */
final readonly class HitClassifier
{
    /**
     * Chromium has sent `Sec-Fetch-*` since version 80; 90 leaves a margin.
     */
    private const CHROMIUM_WITH_FETCH_METADATA = 90;

    /**
     * The bot family of a client whose headers contradict its User-Agent.
     */
    public const BOT_DISGUISED = 'disguised';

    /**
     * Weak signals that, together, make a client a bot.
     */
    private const DISGUISE_SIGNALS = 2;

    public function __construct(
        private ConfigLoader $configLoader,
        private UserAgentParser $userAgentParser,
        private ChannelResolver $channelResolver,
        private SiteTime $siteTime,
    ) {
    }

    /**
     * A page of the site: a page view, a 404 or a bot request.
     *
     * @param int $status status code of the response
     */
    public function classify(Request $request, int $status): ?Hit
    {
        $config = $this->configLoader->load();
        if (! $this->isCollected($request, $config)) {
            return null;
        }

        [$day, $hour] = $this->dayAndHour($request);
        $userAgent = $this->userAgentParser->parse((string) $request->headers->get('User-Agent'));

        $bot = $this->bot($request, $userAgent);
        if ($bot !== null) {
            return new Hit($day, $hour, Hit::KIND_BOT, '', bot: $bot);
        }

        if (! self::isPageNavigation($request->headers)) {
            return null;
        }

        $kind = match (true) {
            $status === 404 => Hit::KIND_NOT_FOUND,
            // 304: the browser revalidated its cached copy and shows it.
            $status >= 200 && $status < 300, $status === 304 => Hit::KIND_PAGEVIEW,
            default => null,
        };
        if ($kind === null) {
            return null;
        }

        $isReload = self::isReload($request->headers);
        if ($isReload && ! $config->countReloads) {
            return null;
        }

        $path = Normalizer::path($request->getPathInfo(), $config->routeTemplates);
        $optOut = self::isOptOut($request->headers, $config);
        if ($optOut && $config->optOutMode === RequestAnalyticsConfig::OPT_OUT_SKIP) {
            return null;
        }

        $via = self::aliasMarker($request, $config);
        $channel = $this->channelResolver->resolve($request, $config, $via !== null);

        if ($optOut) {
            // Whether the page was an entry is about the navigation, not the
            // visitor; without it the entries of the trend would miss these visits.
            return self::minimal($day, $hour, $kind, $path, isEntry: $kind === Hit::KIND_PAGEVIEW && $channel->isEntry());
        }

        if ($kind === Hit::KIND_NOT_FOUND) {
            return new Hit($day, $hour, $kind, $path, ref: $channel->referrer);
        }

        return new Hit(
            $day,
            $hour,
            $kind,
            $path,
            $this->userAgentParser->device($userAgent, $request->headers->get('Sec-CH-UA-Mobile')),
            $this->userAgentParser->browser($userAgent, $request->headers->get('Sec-CH-UA')),
            $this->userAgentParser->os($userAgent, $request->headers->get('Sec-CH-UA-Platform')),
            self::language($request->headers),
            $channel->channel,
            $channel->source,
            $channel->campaign,
            $channel->isEntry(),
            $isReload,
            ref: $channel->channel === Channel::INTERNAL ? $channel->referrer : null,
            via: $via,
            term: self::searchTerm($request, $config),
        );
    }

    /**
     * The redirect from an alias host to the site. The row keeps the channel and
     * source of the hop: it is the first request, so its headers are the
     * reliable ones. Bots and prefetches are redirected without a row.
     */
    public function classifyAliasHop(Request $request): ?Hit
    {
        $config = $this->configLoader->load();
        if (! $this->isCollected($request, $config) || ! $this->isVisitorNavigation($request)) {
            return null;
        }

        [$day, $hour] = $this->dayAndHour($request);
        $path = Normalizer::path($request->getPathInfo(), $config->routeTemplates);
        $via = mb_substr($request->getHost(), 0, 255);

        if (self::isOptOut($request->headers, $config)) {
            return $config->optOutMode === RequestAnalyticsConfig::OPT_OUT_SKIP ? null : self::minimal($day, $hour, Hit::KIND_ALIAS, $path, via: $via);
        }

        $channel = $this->channelResolver->resolve($request, $config);

        return new Hit($day, $hour, Hit::KIND_ALIAS, $path, channel: $channel->channel, source: $channel->source, campaign: $channel->campaign, via: $via);
    }

    private function isCollected(Request $request, RequestAnalyticsConfig $config): bool
    {
        return $config->enabled
            && $request->isMethod(Request::METHOD_GET)
            && ! $config->isExcluded(Normalizer::decodedPath($request->getPathInfo()));
    }

    /**
     * A person opening a page: no bot, no prefetch, no asset or API call.
     */
    private function isVisitorNavigation(Request $request): bool
    {
        $userAgent = $this->userAgentParser->parse((string) $request->headers->get('User-Agent'));

        return $this->bot($request, $userAgent) === null && self::isPageNavigation($request->headers);
    }

    /**
     * The bot family; null for a browser.
     *
     * Besides the User-Agent, a request whose headers contradict it: a
     * User-Agent is easy to copy, the headers a real browser adds are not.
     * Like browser fingerprinting libraries do, several independent signals
     * are weighed and only enough of them together decide, so a real visitor
     * with one odd header is still counted.
     */
    private function bot(Request $request, UserAgentInfo $userAgent): ?string
    {
        if ($userAgent->bot !== null) {
            return $userAgent->bot;
        }

        return $this->isDisguised($request, $userAgent) ? self::BOT_DISGUISED : null;
    }

    /**
     * A client that claims to be a recent Chromium browser but does not
     * behave like one.
     */
    private function isDisguised(Request $request, UserAgentInfo $userAgent): bool
    {
        $major = $userAgent->chromiumMajor;
        $headers = $request->headers;
        if ($major === null || $major < self::CHROMIUM_WITH_FETCH_METADATA) {
            return false;
        }

        $missingFetchMetadata = ! $headers->has('Sec-Fetch-Mode');
        $missingLanguage = ! $headers->has('Accept-Language');

        // Chrome on iOS is WebKit with a Chromium name: no Client Hints.
        if (str_contains((string) $headers->get('User-Agent'), 'CriOS/') || $userAgent->os === UserAgentParser::OS_IOS) {
            return $missingFetchMetadata && $missingLanguage;
        }

        $brands = UserAgentParser::brands($headers->get('Sec-CH-UA'));

        // Strong: the browser reports another version than its User-Agent.
        // Every Chromium browser (Chrome, Edge, Opera, Samsung, Brave, …)
        // sends its engine as "Chromium" with the version of "Chrome/" in the
        // User-Agent.
        $engine = $brands['chromium'] ?? null;
        if ($engine !== null && $engine !== $major) {
            return true;
        }

        $platform = UserAgentParser::platform($headers->get('Sec-CH-UA-Platform'));

        return array_sum([
            $missingFetchMetadata,
            $missingLanguage,
            // Client Hints come only over HTTPS (and on localhost); behind a
            // proxy that needs `framework.trusted_proxies` to be known.
            $request->isSecure() && $brands === [],
            // Nothing but the engine: a bare Chromium build, e.g. a headless one.
            array_keys($brands) === ['chromium'],
            $platform !== null && self::contradicts($platform, $userAgent->os),
        ]) >= self::DISGUISE_SIGNALS;
    }

    /**
     * Whether the OS of `Sec-CH-UA-Platform` and the one of the User-Agent
     * cannot both be true. "Request desktop site" on Android sends a Linux
     * User-Agent with the Android platform; that is a real browser.
     */
    private static function contradicts(string $platform, string $userAgentOs): bool
    {
        if ($platform === UserAgentParser::OTHER || $userAgentOs === UserAgentParser::OTHER || $platform === $userAgentOs) {
            return false;
        }

        return ! ($platform === UserAgentParser::OS_ANDROID && $userAgentOs === UserAgentParser::OS_LINUX);
    }

    private static function isPageNavigation(HeaderBag $headers): bool
    {
        return ! self::isPrefetch($headers) && self::isDocument($headers);
    }

    private static function isDocument(HeaderBag $headers): bool
    {
        if ($headers->has('Sec-Fetch-Dest')) {
            return $headers->get('Sec-Fetch-Dest') === 'document' && $headers->get('Sec-Fetch-Mode') === 'navigate';
        }

        return str_contains(mb_strtolower((string) $headers->get('Accept')), 'text/html');
    }

    private static function isPrefetch(HeaderBag $headers): bool
    {
        return str_contains(mb_strtolower((string) $headers->get('Sec-Purpose')), 'prefetch')
            || mb_strtolower((string) $headers->get('Purpose')) === 'prefetch'
            || mb_strtolower((string) $headers->get('X-Moz')) === 'prefetch'
            || mb_strtolower((string) $headers->get('X-Purpose')) === 'preview';
    }

    private static function isReload(HeaderBag $headers): bool
    {
        $cacheControl = mb_strtolower((string) $headers->get('Cache-Control'));

        return str_contains($cacheControl, 'max-age=0') || str_contains($cacheControl, 'no-cache');
    }

    private static function isOptOut(HeaderBag $headers, RequestAnalyticsConfig $config): bool
    {
        return ($config->honorGpc && mb_trim((string) $headers->get('Sec-GPC')) === '1')
            || ($config->honorDnt && mb_trim((string) $headers->get('DNT')) === '1');
    }

    /**
     * The row for a visitor who sent an opt-out signal: what was requested and
     * when, nothing about who requested it (language `und`).
     */
    private static function minimal(string $day, int $hour, string $kind, string $path, ?string $via = null, bool $isEntry = false): Hit
    {
        return new Hit(
            $day,
            $hour,
            $kind,
            $path,
            Hit::OPT_OUT,
            Hit::OPT_OUT,
            Hit::OPT_OUT,
            channel: Hit::OPT_OUT,
            source: Hit::OPT_OUT,
            campaign: Hit::OPT_OUT,
            isEntry: $isEntry,
            via: $via,
        );
    }

    /**
     * Base subtag of the first `Accept-Language` entry: `cs-CZ,cs;q=0.9` is `cs`.
     */
    private static function language(HeaderBag $headers): string
    {
        $first = mb_strtolower(mb_trim(explode(',', (string) $headers->get('Accept-Language'))[0]));

        return preg_match('/^([a-z]{2,3})(?:[-_;]|$)/', $first, $matches) === 1 ? $matches[1] : Hit::UNKNOWN_LANGUAGE;
    }

    /**
     * The alias marker of the URL (`?ra_via=…`), if the config lists it.
     */
    private static function aliasMarker(Request $request, RequestAnalyticsConfig $config): ?string
    {
        $marker = $request->query->all()[$config->aliasParam] ?? null;
        if (! is_string($marker)) {
            return null;
        }

        $marker = mb_rtrim(mb_strtolower(mb_trim($marker)), '.');

        return $config->isAliasMarker($marker) ? mb_substr($marker, 0, 255) : null;
    }

    private static function searchTerm(Request $request, RequestAnalyticsConfig $config): ?string
    {
        $query = $request->query->all();
        foreach ($config->searchParams as $parameter) {
            $term = $query[$parameter] ?? null;
            if (is_string($term) && mb_trim($term) !== '') {
                return Normalizer::searchTerm($term);
            }
        }

        return null;
    }

    /**
     * @return array{string, int} day (Y-m-d) and hour (0-23) in the site timezone
     */
    private function dayAndHour(Request $request): array
    {
        $timestamp = $request->server->get('REQUEST_TIME');
        $receivedAt = is_numeric($timestamp) ? $this->siteTime->at((int) $timestamp) : $this->siteTime->now();

        return [$receivedAt->format('Y-m-d'), (int) $receivedAt->format('G')];
    }
}

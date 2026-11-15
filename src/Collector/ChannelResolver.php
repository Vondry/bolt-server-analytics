<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Collector;

use Symfony\Component\HttpFoundation\Request;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;

/**
 * Traffic source of a request: campaign parameters, click IDs, then the
 * Referer. The first rule that matches wins.
 *
 * The lists of search engines, social networks and AI assistants follow
 * Matomo's maintained lists (matomo-org/searchengine-and-social-list); the
 * `utm_medium` rules follow GA4's default channel group.
 */
final class ChannelResolver
{
    /**
     * `utm_medium` (lowercase) => channel, checked in this order. A paid
     * medium from a social network is Social.
     */
    private const MEDIUMS = [
        Channel::SOCIAL => '~^(?:sm|.*social.*)$~',
        Channel::EMAIL => '~^(?:e[ _-]?mail|newsletter|mail)$~',
        Channel::DISPLAY => '~^(?:display|banner|expandable|interstitial|cpm)$~',
        Channel::AFFILIATE => '~^affiliates?$~',
        Channel::PAID_SEARCH => '~^(?:.*cp.*|ppc|sem|retargeting|paid.*)$~',
        Channel::ORGANIC_SEARCH => '~^organic$~',
        Channel::REFERRAL => '~^(?:referral|link)$~',
    ];

    /**
     * Newsletter tools tagged by their source only.
     */
    private const EMAIL_SOURCES = '~^(?:e[ _-]?mail|newsletter|mailchimp|ecomail|smartemailing|mailerlite|brevo|sendinblue|klaviyo)$~';

    /**
     * Only the presence of a click ID is used, never its value. Names are
     * case-sensitive, as the platforms send them.
     */
    private const CLICK_IDS = [
        'gclid' => [Channel::PAID_SEARCH, 'google_ads'],
        'gbraid' => [Channel::PAID_SEARCH, 'google_ads'],
        'wbraid' => [Channel::PAID_SEARCH, 'google_ads'],
        // Stays when Safari's Link Tracking Protection removes gclid.
        'gad_source' => [Channel::PAID_SEARCH, 'google_ads'],
        'dclid' => [Channel::DISPLAY, 'google_ads'],
        'msclkid' => [Channel::PAID_SEARCH, 'microsoft_ads'],
        // Sklik adds it to every ad click; with its auto-tagging the UTM
        // parameters (utm_source=seznam, utm_medium=cpc) come first anyway.
        'sznclid' => [Channel::PAID_SEARCH, 'seznam'],
        'yclid' => [Channel::PAID_SEARCH, 'yandex_direct'],
        'fbclid' => [Channel::SOCIAL, 'meta'],
        'ttclid' => [Channel::SOCIAL, 'tiktok'],
        'li_fat_id' => [Channel::SOCIAL, 'linkedin'],
        'twclid' => [Channel::SOCIAL, 'x'],
        'ScCid' => [Channel::SOCIAL, 'snapchat'],
        'epik' => [Channel::SOCIAL, 'pinterest'],
        'rdt_cid' => [Channel::SOCIAL, 'reddit'],
    ];

    /**
     * Host (without `www.`) or a parent domain of it => assistant.
     */
    private const AI_ASSISTANTS = [
        'chatgpt.com' => 'chatgpt',
        'chat.openai.com' => 'chatgpt',
        'perplexity.ai' => 'perplexity',
        'claude.ai' => 'claude',
        'gemini.google.com' => 'gemini',
        'bard.google.com' => 'gemini',
        'notebooklm.google.com' => 'notebooklm',
        'copilot.microsoft.com' => 'copilot',
        'chat.mistral.ai' => 'mistral',
        'chat.deepseek.com' => 'deepseek',
        'grok.com' => 'grok',
        'meta.ai' => 'meta_ai',
        'duck.ai' => 'duck_ai',
        'you.com' => 'you',
        'kimi.com' => 'kimi',
        'chat.qwen.ai' => 'qwen',
        'doubao.com' => 'doubao',
    ];

    /**
     * Host (without `www.`) => engine. Exact hosts: other services of the same
     * company are no searches. Seznam: only its search pages; the seznam.cz
     * homepage and its feed are a portal, its webmail is e-mail.
     */
    private const SEARCH_ENGINES = [
        'search.seznam.cz' => 'seznam',
        'videa.seznam.cz' => 'seznam',
        'obrazky.seznam.cz' => 'seznam',
        'bing.com' => 'bing',
        'cn.bing.com' => 'bing',
        'search.yahoo.co.jp' => 'yahoo',
        'ya.ru' => 'yandex',
        'search.naver.com' => 'naver',
        'search.daum.net' => 'daum',
        'go.mail.ru' => 'mail.ru',
        'search.brave.com' => 'brave',
        'search.aol.com' => 'aol',
        'search.lilo.org' => 'lilo',
        'baidu.com' => 'baidu',
        'm.baidu.com' => 'baidu',
        'so.com' => '360',
        'm.so.com' => '360',
        'so.360.cn' => '360',
        'coccoc.com' => 'coccoc',
    ];

    /**
     * Host (without `www.`) or a parent domain of it => engine: domains that
     * serve nothing but search.
     */
    private const SEARCH_ENGINE_DOMAINS = [
        'duckduckgo.com' => 'duckduckgo',
        'ecosia.org' => 'ecosia',
        'startpage.com' => 'startpage',
        'qwant.com' => 'qwant',
        'kagi.com' => 'kagi',
        'mojeek.com' => 'mojeek',
        'sogou.com' => 'sogou',
        'swisscows.com' => 'swisscows',
        'presearch.com' => 'presearch',
        'metager.de' => 'metager',
        'ask.com' => 'ask',
    ];

    /**
     * Engines with one domain per country.
     */
    private const SEARCH_ENGINE_PATTERNS = [
        'google' => '~^google\.[a-z]{2,3}(?:\.[a-z]{2})?$~',
        'yandex' => '~^yandex\.[a-z]{2,3}(?:\.[a-z]{2})?$~',
        'yahoo' => '~^(?:[a-z]{2}\.)?search\.yahoo\.com$~',
        'bing' => '~^[a-z]{2}\.bing\.com$~',
    ];

    /**
     * Host (without `www.`) or a parent domain of it => network.
     */
    private const SOCIAL_NETWORKS = [
        'facebook.com' => 'facebook',
        'fb.me' => 'facebook',
        'messenger.com' => 'facebook',
        'instagram.com' => 'instagram',
        'threads.net' => 'threads',
        'threads.com' => 'threads',
        't.co' => 'x',
        'x.com' => 'x',
        'twitter.com' => 'x',
        'bsky.app' => 'bluesky',
        'mastodon.social' => 'mastodon',
        'linkedin.com' => 'linkedin',
        'lnkd.in' => 'linkedin',
        'youtube.com' => 'youtube',
        'youtu.be' => 'youtube',
        'reddit.com' => 'reddit',
        'tiktok.com' => 'tiktok',
        'pinterest.com' => 'pinterest',
        'pin.it' => 'pinterest',
        'snapchat.com' => 'snapchat',
        't.me' => 'telegram',
        'web.telegram.org' => 'telegram',
        'whatsapp.com' => 'whatsapp',
        'wa.me' => 'whatsapp',
        'discord.com' => 'discord',
        'discord.gg' => 'discord',
        'vk.com' => 'vk',
        'ok.ru' => 'odnoklassniki',
        'tumblr.com' => 'tumblr',
        'news.ycombinator.com' => 'hacker_news',
    ];

    /**
     * Networks with one domain per country.
     */
    private const SOCIAL_NETWORK_PATTERNS = [
        'pinterest' => '~^(?:[a-z]{2}\.)?pinterest\.[a-z]{2,3}(?:\.[a-z]{2})?$~',
    ];

    /**
     * Webmail host (without `www.`) => provider.
     */
    private const WEBMAIL = [
        'email.seznam.cz' => 'seznam',
        'mail.google.com' => 'gmail',
        'outlook.live.com' => 'outlook',
        'outlook.office.com' => 'outlook',
        'outlook.office365.com' => 'outlook',
        'mail.yahoo.com' => 'yahoo',
        'mail.proton.me' => 'proton',
        'mail.centrum.cz' => 'centrum',
        'mail.atlas.cz' => 'atlas',
        'e.mail.ru' => 'mail.ru',
        'mail.yandex.ru' => 'yandex',
    ];

    /**
     * Android app (the package of an `android-app://` Referer) => channel and source.
     */
    private const APPS = [
        'com.google.android.googlequicksearchbox' => [Channel::ORGANIC_SEARCH, 'google'],
        'com.google.android.gm' => [Channel::EMAIL, 'gmail'],
        'com.microsoft.office.outlook' => [Channel::EMAIL, 'outlook'],
        'cz.seznam.email' => [Channel::EMAIL, 'seznam'],
        'com.openai.chatgpt' => [Channel::AI_ASSISTANT, 'chatgpt'],
        'ai.perplexity.app.android' => [Channel::AI_ASSISTANT, 'perplexity'],
        'com.anthropic.claude' => [Channel::AI_ASSISTANT, 'claude'],
        'com.google.android.apps.bard' => [Channel::AI_ASSISTANT, 'gemini'],
        'com.facebook.katana' => [Channel::SOCIAL, 'facebook'],
        'com.facebook.lite' => [Channel::SOCIAL, 'facebook'],
        'com.facebook.orca' => [Channel::SOCIAL, 'facebook'],
        'com.instagram.android' => [Channel::SOCIAL, 'instagram'],
        'com.instagram.barcelona' => [Channel::SOCIAL, 'threads'],
        'com.twitter.android' => [Channel::SOCIAL, 'x'],
        'com.linkedin.android' => [Channel::SOCIAL, 'linkedin'],
        'com.reddit.frontpage' => [Channel::SOCIAL, 'reddit'],
        'com.zhiliaoapp.musically' => [Channel::SOCIAL, 'tiktok'],
        'com.pinterest' => [Channel::SOCIAL, 'pinterest'],
        'com.snapchat.android' => [Channel::SOCIAL, 'snapchat'],
        'org.telegram.messenger' => [Channel::SOCIAL, 'telegram'],
        'com.whatsapp' => [Channel::SOCIAL, 'whatsapp'],
        'com.discord' => [Channel::SOCIAL, 'discord'],
    ];

    /**
     * `utm_source` names (without a domain) that name a social network.
     */
    private const SOCIAL_SOURCES = ['fb', 'ig', 'twitter', 'meta'];

    /**
     * @param bool $hasAliasMarker the URL carries an alias marker that is listed in the config
     */
    public function resolve(Request $request, RequestAnalyticsConfig $config, bool $hasAliasMarker = false): Channel
    {
        $medium = self::query($request, 'utm_medium');
        $source = self::query($request, 'utm_source');
        $campaign = self::query($request, 'utm_campaign');
        if ($medium !== null || $source !== null || $campaign !== null) {
            return self::fromCampaign($medium, $source, $campaign);
        }

        foreach (self::CLICK_IDS as $parameter => [$channel, $clickSource]) {
            if (self::query($request, $parameter) !== null) {
                return new Channel($channel, $clickSource);
            }
        }

        $referer = mb_trim((string) $request->headers->get('Referer'));
        if ($referer !== '') {
            $channel = $this->fromReferer($referer, $request, $config);
            if ($channel instanceof Channel) {
                return $channel;
            }
        }

        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if ($hasAliasMarker || $fetchSite === 'none') {
            return new Channel(Channel::DIRECT);
        }

        // A click within the site whose Referer was withheld (`Referrer-Policy:
        // no-referrer`, `rel="noreferrer"`): the browser still says it came
        // from this origin. Internal, without the page it came from.
        if ($fetchSite === 'same-origin') {
            return new Channel(Channel::INTERNAL);
        }

        return new Channel(Channel::UNKNOWN);
    }

    /**
     * A link tagged with UTM parameters: `utm_medium` decides the channel,
     * without it `utm_source` does. A source that is a known domain gets the
     * name a Referer from it gets (`chatgpt.com` is `chatgpt`).
     */
    private static function fromCampaign(?string $medium, ?string $source, ?string $campaign): Channel
    {
        $source = Normalizer::label($source ?? '');
        $known = str_contains($source, '.') ? self::fromHost(Normalizer::host($source)) : null;
        $sourceName = $known instanceof Channel ? $known->source : $source;
        $campaign = Normalizer::label($campaign ?? '');

        if ($medium !== null) {
            $channel = self::first(self::MEDIUMS, Normalizer::label($medium)) ?? Channel::CAMPAIGN;
            if ($channel === Channel::PAID_SEARCH && self::isSocial($known, $sourceName)) {
                $channel = Channel::SOCIAL;
            }

            return new Channel($channel, $sourceName, $campaign);
        }

        $channel = match (true) {
            $known instanceof Channel && $known->channel !== Channel::REFERRAL => $known->channel,
            in_array($sourceName, self::AI_ASSISTANTS, true) => Channel::AI_ASSISTANT,
            self::isSocial($known, $sourceName) => Channel::SOCIAL,
            in_array($sourceName, self::SEARCH_ENGINES, true) || in_array($sourceName, self::SEARCH_ENGINE_DOMAINS, true)
                || array_key_exists($sourceName, self::SEARCH_ENGINE_PATTERNS) => Channel::ORGANIC_SEARCH,
            preg_match(self::EMAIL_SOURCES, $sourceName) === 1 => Channel::EMAIL,
            default => Channel::CAMPAIGN,
        };

        return new Channel($channel, $sourceName, $campaign);
    }

    private static function isSocial(?Channel $known, string $source): bool
    {
        return $known?->channel === Channel::SOCIAL
            || in_array($source, self::SOCIAL_NETWORKS, true)
            || in_array($source, self::SOCIAL_SOURCES, true);
    }

    /**
     * Null for a Referer that names no host (malformed, `about:blank`, …).
     */
    private function fromReferer(string $referer, Request $request, RequestAnalyticsConfig $config): ?Channel
    {
        $parts = parse_url($referer);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = mb_strtolower($parts['scheme']);
        if ($scheme === 'android-app') {
            $package = Normalizer::label($parts['host']);
            [$channel, $source] = self::APPS[$package] ?? [Channel::APP, $package];

            return new Channel($channel, $source, '', $package);
        }

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $fullHost = mb_rtrim(mb_strtolower($parts['host']), '.');
        if ($fullHost === $request->getHost() || $config->isOwnHost($fullHost)) {
            return new Channel(Channel::INTERNAL, '', '', Normalizer::path($parts['path'] ?? '/', $config->routeTemplates));
        }

        // No host name (Normalizer::host() gives ''): nothing to say where the visit came from.
        $host = Normalizer::label(Normalizer::host($fullHost));

        return $host === '' ? null : self::fromHost($host);
    }

    /**
     * Channel and source of a host without `www.`; a referral if it is none
     * of the known ones.
     */
    private static function fromHost(string $host): Channel
    {
        $assistant = self::match(self::AI_ASSISTANTS, $host);
        if ($assistant !== null) {
            return new Channel(Channel::AI_ASSISTANT, $assistant, '', $host);
        }

        $engine = self::SEARCH_ENGINES[$host]
            ?? self::match(self::SEARCH_ENGINE_DOMAINS, $host)
            ?? self::first(self::SEARCH_ENGINE_PATTERNS, $host);
        if ($engine !== null) {
            return new Channel(Channel::ORGANIC_SEARCH, $engine, '', $host);
        }

        $network = self::match(self::SOCIAL_NETWORKS, $host) ?? self::first(self::SOCIAL_NETWORK_PATTERNS, $host);
        if ($network !== null) {
            return new Channel(Channel::SOCIAL, $network, '', $host);
        }

        $webmail = self::WEBMAIL[$host] ?? null;
        if ($webmail !== null) {
            return new Channel(Channel::EMAIL, $webmail, '', $host);
        }

        return new Channel(Channel::REFERRAL, $host, '', $host);
    }

    /**
     * A non-empty query parameter; null for a missing, empty or array value.
     */
    private static function query(Request $request, string $name): ?string
    {
        $value = $request->query->all()[$name] ?? null;

        return is_string($value) && mb_trim($value) !== '' ? $value : null;
    }

    /**
     * @param array<string, string> $patterns name => regular expression
     */
    private static function first(array $patterns, string $value): ?string
    {
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $domains
     */
    private static function match(array $domains, string $host): ?string
    {
        foreach ($domains as $domain => $name) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $name;
            }
        }

        return null;
    }
}

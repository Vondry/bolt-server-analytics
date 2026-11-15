<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Collector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Collector\ChannelResolver;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;
use TomVondracek\RequestAnalytics\Tests\Support\Requests;

final class ChannelResolverTest extends TestCase
{
    /**
     * @param array<string, string|null> $headers
     * @param array{string, string, string, ?string} $expected channel, source, campaign, referrer
     */
    #[DataProvider('requests')]
    public function testResolve(string $url, array $headers, array $expected): void
    {
        $config = RequestAnalyticsConfig::fromArray([
            'own_hosts' => ['rockfest-kurim.cz', 'www.rockfest-kurim.cz'],
        ], '/project');

        $channel = (new ChannelResolver())->resolve(Requests::browser($url, $headers), $config);

        self::assertSame($expected, [$channel->channel, $channel->source, $channel->campaign, $channel->referrer]);
        self::assertSame($expected[0] !== 'internal', $channel->isEntry());
    }

    /**
     * @return iterable<string, array{string, array<string, string|null>, array{string, string, string, ?string}}>
     */
    public static function requests(): iterable
    {
        $site = 'https://rockfest-kurim.cz';
        $crossSite = [
            'Sec-Fetch-Site' => 'cross-site',
        ];

        yield 'typed address' => [$site . '/', [], ['direct', '', '', null]];
        yield 'no referer, not typed' => [$site . '/', $crossSite, ['unknown', '', '', null]];
        yield 'old browser without fetch metadata' => [$site . '/', [
            'Sec-Fetch-Site' => null,
        ], ['unknown', '', '', null]];

        yield 'utm cpc' => [$site . '/?utm_medium=CPC&utm_source=Google&utm_campaign=Rock%20Fest%202026', [], ['paid_search', 'google', 'rock fest 2026', null]];
        yield 'utm newsletter' => [$site . '/?utm_medium=newsletter&utm_source=mailchimp', [], ['email', 'mailchimp', '', null]];
        yield 'utm banner' => [$site . '/?utm_medium=banner', [], ['display', '', '', null]];
        yield 'utm paid_social' => [$site . '/?utm_medium=paid_social&utm_source=facebook', [], ['social', 'facebook', '', null]];
        yield 'utm affiliate' => [$site . '/?utm_medium=affiliate', [], ['affiliate', '', '', null]];
        yield 'utm of another kind' => [$site . '/?utm_medium=qr&utm_source=poster', [], ['campaign', 'poster', '', null]];
        yield 'utm wins over the referer' => [$site . '/?utm_medium=email', [
            'Referer' => 'https://www.google.com/',
        ] + $crossSite, ['email', '', '', null]];
        // utm_medium as GA4's default channel group reads it.
        yield 'utm e-mail' => [$site . '/?utm_medium=e-mail&utm_source=ecomail', [], ['email', 'ecomail', '', null]];
        yield 'utm paid-social' => [$site . '/?utm_medium=paid-social&utm_source=fb', [], ['social', 'fb', '', null]];
        yield 'utm cpc from a social network' => [$site . '/?utm_medium=cpc&utm_source=facebook', [], ['social', 'facebook', '', null]];
        yield 'utm cpm' => [$site . '/?utm_medium=cpm', [], ['display', '', '', null]];
        yield 'utm organic' => [$site . '/?utm_medium=organic&utm_source=google', [], ['organic_search', 'google', '', null]];
        yield 'utm referral' => [$site . '/?utm_medium=referral&utm_source=partner.example', [], ['referral', 'partner.example', '', null]];
        yield 'sklik auto-tagging' => [$site . '/?utm_source=seznam&utm_medium=cpc&utm_campaign=podzim&sznclid=abc', [
            'Referer' => 'https://search.seznam.cz/',
        ] + $crossSite, ['paid_search', 'seznam', 'podzim', null]];

        // Without utm_medium, utm_source decides.
        yield 'utm source and campaign only' => [$site . '/?utm_source=newsletter&utm_campaign=nov', [], ['email', 'newsletter', 'nov', null]];
        yield 'utm source only, unknown' => [$site . '/?utm_source=poster', [], ['campaign', 'poster', '', null]];
        yield 'utm campaign only' => [$site . '/?utm_campaign=nov', [], ['campaign', '', 'nov', null]];
        yield 'chatgpt adds utm_source' => [$site . '/?utm_source=chatgpt.com', [
            'Referer' => 'https://chatgpt.com/',
        ] + $crossSite, ['ai_assistant', 'chatgpt', '', null]];
        yield 'utm source of a social network' => [$site . '/?utm_source=instagram', [], ['social', 'instagram', '', null]];
        yield 'utm source of a search engine' => [$site . '/?utm_source=www.seznam.cz', [], ['campaign', 'seznam.cz', '', null]];
        yield 'utm as an array is ignored' => [$site . '/?utm_medium[]=cpc', [], ['direct', '', '', null]];

        yield 'gclid' => [$site . '/?gclid=abc', [], ['paid_search', 'google_ads', '', null]];
        yield 'wbraid' => [$site . '/?wbraid=abc', [], ['paid_search', 'google_ads', '', null]];
        yield 'msclkid' => [$site . '/?msclkid=abc', [], ['paid_search', 'microsoft_ads', '', null]];
        yield 'fbclid' => [$site . '/?fbclid=abc', [
            'Referer' => 'https://l.facebook.com/',
        ] + $crossSite, ['social', 'meta', '', null]];
        yield 'ttclid' => [$site . '/?ttclid=abc', [], ['social', 'tiktok', '', null]];
        yield 'li_fat_id' => [$site . '/?li_fat_id=abc', [], ['social', 'linkedin', '', null]];
        yield 'gad_source without gclid' => [$site . '/?gad_source=1', [
            'Referer' => 'https://www.google.com/',
        ] + $crossSite, ['paid_search', 'google_ads', '', null]];
        yield 'dclid' => [$site . '/?dclid=abc', [], ['display', 'google_ads', '', null]];
        yield 'sklik click id' => [$site . '/?sznclid=abc', [
            'Referer' => 'https://search.seznam.cz/',
        ] + $crossSite, ['paid_search', 'seznam', '', null]];
        yield 'sklik ad on the seznam homepage' => [$site . '/?sznclid=abc', [
            'Referer' => 'https://www.seznam.cz/',
        ] + $crossSite, ['paid_search', 'seznam', '', null]];
        yield 'yclid' => [$site . '/?yclid=abc', [], ['paid_search', 'yandex_direct', '', null]];
        yield 'twclid' => [$site . '/?twclid=abc', [], ['social', 'x', '', null]];
        yield 'ScCid' => [$site . '/?ScCid=abc', [], ['social', 'snapchat', '', null]];
        yield 'epik' => [$site . '/?epik=abc', [], ['social', 'pinterest', '', null]];
        yield 'rdt_cid' => [$site . '/?rdt_cid=abc', [], ['social', 'reddit', '', null]];
        yield 'empty click id' => [$site . '/?gclid=', [], ['direct', '', '', null]];

        yield 'internal' => [$site . '/program', [
            'Referer' => $site . '/kapely/?page=2',
            'Sec-Fetch-Site' => 'same-origin',
        ], ['internal', '', '', '/kapely']];
        yield 'internal from another own host' => [$site . '/program', [
            'Referer' => 'https://WWW.rockfest-kurim.cz',
            'Sec-Fetch-Site' => 'same-site',
        ], ['internal', '', '', '/']];
        yield 'internal from the request host' => ['https://staging.example/program', [
            'Referer' => 'https://staging.example/',
        ], ['internal', '', '', '/']];
        // Referrer-Policy: no-referrer: no Referer, but the browser tells it came from the site.
        yield 'internal without a referer' => [$site . '/program', [
            'Sec-Fetch-Site' => 'same-origin',
        ], ['internal', '', '', null]];
        // Another subdomain may be another site.
        yield 'same site without a referer' => [$site . '/program', [
            'Sec-Fetch-Site' => 'same-site',
        ], ['unknown', '', '', null]];

        yield 'google' => [$site . '/', [
            'Referer' => 'https://www.google.com/',
        ] + $crossSite, ['organic_search', 'google', '', 'google.com']];
        yield 'google.cz' => [$site . '/', [
            'Referer' => 'https://www.google.cz/',
        ] + $crossSite, ['organic_search', 'google', '', 'google.cz']];
        yield 'google.co.uk' => [$site . '/', [
            'Referer' => 'https://www.google.co.uk/',
        ] + $crossSite, ['organic_search', 'google', '', 'google.co.uk']];
        yield 'seznam' => [$site . '/', [
            'Referer' => 'https://search.seznam.cz/',
        ] + $crossSite, ['organic_search', 'seznam', '', 'search.seznam.cz']];
        yield 'bing' => [$site . '/', [
            'Referer' => 'https://www.bing.com/',
        ] + $crossSite, ['organic_search', 'bing', '', 'bing.com']];
        yield 'duckduckgo' => [$site . '/', [
            'Referer' => 'https://duckduckgo.com/',
        ] + $crossSite, ['organic_search', 'duckduckgo', '', 'duckduckgo.com']];
        yield 'yandex' => [$site . '/', [
            'Referer' => 'https://yandex.ru/',
        ] + $crossSite, ['organic_search', 'yandex', '', 'yandex.ru']];
        yield 'brave' => [$site . '/', [
            'Referer' => 'https://search.brave.com/',
        ] + $crossSite, ['organic_search', 'brave', '', 'search.brave.com']];
        yield 'webmail of a search engine is e-mail' => [$site . '/', [
            'Referer' => 'https://email.seznam.cz/',
        ] + $crossSite, ['email', 'seznam', '', 'email.seznam.cz']];
        yield 'gmail is e-mail' => [$site . '/', [
            'Referer' => 'https://mail.google.com/',
        ] + $crossSite, ['email', 'gmail', '', 'mail.google.com']];
        yield 'outlook is e-mail' => [$site . '/', [
            'Referer' => 'https://outlook.live.com/',
        ] + $crossSite, ['email', 'outlook', '', 'outlook.live.com']];

        // Seznam: only its search pages are searches.
        yield 'seznam videos' => [$site . '/', [
            'Referer' => 'https://videa.seznam.cz/',
        ] + $crossSite, ['organic_search', 'seznam', '', 'videa.seznam.cz']];
        yield 'seznam images' => [$site . '/', [
            'Referer' => 'https://obrazky.seznam.cz/',
        ] + $crossSite, ['organic_search', 'seznam', '', 'obrazky.seznam.cz']];
        yield 'seznam homepage feed is a referral' => [$site . '/', [
            'Referer' => 'https://www.seznam.cz/',
        ] + $crossSite, ['referral', 'seznam.cz', '', 'seznam.cz']];
        yield 'seznam news is a referral' => [$site . '/', [
            'Referer' => 'https://www.seznamzpravy.cz/',
        ] + $crossSite, ['referral', 'seznamzpravy.cz', '', 'seznamzpravy.cz']];
        yield 'seznam maps is a referral' => [$site . '/', [
            'Referer' => 'https://mapy.com/',
        ] + $crossSite, ['referral', 'mapy.com', '', 'mapy.com']];
        yield 'zbozi.cz is a referral' => [$site . '/', [
            'Referer' => 'https://www.zbozi.cz/',
        ] + $crossSite, ['referral', 'zbozi.cz', '', 'zbozi.cz']];

        yield 'yandex ya.ru' => [$site . '/', [
            'Referer' => 'https://ya.ru/',
        ] + $crossSite, ['organic_search', 'yandex', '', 'ya.ru']];
        yield 'duckduckgo html' => [$site . '/', [
            'Referer' => 'https://html.duckduckgo.com/',
        ] + $crossSite, ['organic_search', 'duckduckgo', '', 'html.duckduckgo.com']];
        yield 'yahoo japan' => [$site . '/', [
            'Referer' => 'https://search.yahoo.co.jp/',
        ] + $crossSite, ['organic_search', 'yahoo', '', 'search.yahoo.co.jp']];
        yield 'naver' => [$site . '/', [
            'Referer' => 'https://search.naver.com/',
        ] + $crossSite, ['organic_search', 'naver', '', 'search.naver.com']];
        yield 'baidu' => [$site . '/', [
            'Referer' => 'https://www.baidu.com/',
        ] + $crossSite, ['organic_search', 'baidu', '', 'baidu.com']];
        yield 'qwant' => [$site . '/', [
            'Referer' => 'https://www.qwant.com/',
        ] + $crossSite, ['organic_search', 'qwant', '', 'qwant.com']];
        yield 'ecosia' => [$site . '/', [
            'Referer' => 'https://www.ecosia.org/',
        ] + $crossSite, ['organic_search', 'ecosia', '', 'ecosia.org']];
        yield 'google maps is not a search' => [$site . '/', [
            'Referer' => 'https://maps.google.com/',
        ] + $crossSite, ['referral', 'maps.google.com', '', 'maps.google.com']];

        yield 'chatgpt' => [$site . '/', [
            'Referer' => 'https://chatgpt.com/',
        ] + $crossSite, ['ai_assistant', 'chatgpt', '', 'chatgpt.com']];
        yield 'perplexity' => [$site . '/', [
            'Referer' => 'https://www.perplexity.ai/',
        ] + $crossSite, ['ai_assistant', 'perplexity', '', 'perplexity.ai']];
        yield 'gemini is not google search' => [$site . '/', [
            'Referer' => 'https://gemini.google.com/',
        ] + $crossSite, ['ai_assistant', 'gemini', '', 'gemini.google.com']];
        yield 'claude' => [$site . '/', [
            'Referer' => 'https://claude.ai/',
        ] + $crossSite, ['ai_assistant', 'claude', '', 'claude.ai']];
        yield 'copilot' => [$site . '/', [
            'Referer' => 'https://copilot.microsoft.com/',
        ] + $crossSite, ['ai_assistant', 'copilot', '', 'copilot.microsoft.com']];

        yield 'facebook' => [$site . '/', [
            'Referer' => 'https://www.facebook.com/',
        ] + $crossSite, ['social', 'facebook', '', 'facebook.com']];
        yield 'facebook link shim' => [$site . '/', [
            'Referer' => 'https://l.facebook.com/',
        ] + $crossSite, ['social', 'facebook', '', 'l.facebook.com']];
        yield 't.co' => [$site . '/', [
            'Referer' => 'https://t.co/',
        ] + $crossSite, ['social', 'x', '', 't.co']];
        yield 'instagram' => [$site . '/', [
            'Referer' => 'https://l.instagram.com/',
        ] + $crossSite, ['social', 'instagram', '', 'l.instagram.com']];
        yield 'threads' => [$site . '/', [
            'Referer' => 'https://www.threads.com/',
        ] + $crossSite, ['social', 'threads', '', 'threads.com']];
        yield 'bluesky' => [$site . '/', [
            'Referer' => 'https://bsky.app/',
        ] + $crossSite, ['social', 'bluesky', '', 'bsky.app']];
        yield 'telegram' => [$site . '/', [
            'Referer' => 'https://t.me/',
        ] + $crossSite, ['social', 'telegram', '', 't.me']];
        yield 'pinterest of a country' => [$site . '/', [
            'Referer' => 'https://cz.pinterest.com/',
        ] + $crossSite, ['social', 'pinterest', '', 'cz.pinterest.com']];
        yield 'pinterest.cz' => [$site . '/', [
            'Referer' => 'https://www.pinterest.cz/',
        ] + $crossSite, ['social', 'pinterest', '', 'pinterest.cz']];
        yield 'not facebook' => [$site . '/', [
            'Referer' => 'https://notfacebook.com/',
        ] + $crossSite, ['referral', 'notfacebook.com', '', 'notfacebook.com']];

        yield 'android app' => [$site . '/', [
            'Referer' => 'android-app://com.example.events/',
        ] + $crossSite, ['app', 'com.example.events', '', 'com.example.events']];
        yield 'google app is google search' => [$site . '/', [
            'Referer' => 'android-app://com.google.android.googlequicksearchbox/',
        ] + $crossSite, ['organic_search', 'google', '', 'com.google.android.googlequicksearchbox']];
        yield 'gmail app is e-mail' => [$site . '/', [
            'Referer' => 'android-app://com.google.android.gm/',
        ] + $crossSite, ['email', 'gmail', '', 'com.google.android.gm']];
        yield 'facebook app is social' => [$site . '/', [
            'Referer' => 'android-app://com.facebook.katana/',
        ] + $crossSite, ['social', 'facebook', '', 'com.facebook.katana']];
        yield 'chatgpt app is an ai assistant' => [$site . '/', [
            'Referer' => 'android-app://com.openai.chatgpt/',
        ] + $crossSite, ['ai_assistant', 'chatgpt', '', 'com.openai.chatgpt']];
        yield 'referral' => [$site . '/', [
            'Referer' => 'https://WWW.Kurim.cz/akce?id=5',
        ] + $crossSite, ['referral', 'kurim.cz', '', 'kurim.cz']];
        yield 'referer without a host' => [$site . '/', [
            'Referer' => 'about:blank',
        ], ['direct', '', '', null]];
        yield 'referer of another scheme' => [$site . '/', [
            'Referer' => 'ftp://files.example.org/',
        ], ['direct', '', '', null]];
    }

    public function testAliasMarkerMakesAVisitDirect(): void
    {
        $config = RequestAnalyticsConfig::fromArray([], '/project');
        $request = Requests::browser('https://rockfest-kurim.cz/?ra_via=rockfestkurim.cz', [
            'Sec-Fetch-Site' => 'cross-site',
        ]);

        self::assertSame('direct', (new ChannelResolver())->resolve($request, $config, true)->channel);
        self::assertSame('unknown', (new ChannelResolver())->resolve($request, $config)->channel);
    }

    public function testSourceIsTruncated(): void
    {
        $config = RequestAnalyticsConfig::fromArray([], '/project');
        $request = Requests::browser('https://rockfest-kurim.cz/', [
            'Referer' => 'https://' . str_repeat('a', 60) . '.' . str_repeat('b', 60) . '.example/',
        ]);

        self::assertSame(64, mb_strlen((new ChannelResolver())->resolve($request, $config)->source));
    }
}

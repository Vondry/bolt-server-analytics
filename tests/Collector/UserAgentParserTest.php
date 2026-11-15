<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Collector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Collector\UserAgentInfo;
use TomVondracek\RequestAnalytics\Collector\UserAgentParser;
use TomVondracek\RequestAnalytics\Tests\Support\Project;
use TomVondracek\RequestAnalytics\Tests\Support\Requests;

final class UserAgentParserTest extends TestCase
{
    private Project $project;
    private UserAgentParser $parser;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->parser = new UserAgentParser($this->project->configLoader());
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[DataProvider('browsers')]
    public function testBrowsers(string $userAgent, string $device, string $browser, string $os): void
    {
        $info = $this->parser->parse($userAgent);

        self::assertSame([$device, $browser, $os, null], [$info->device, $info->browser, $info->os, $info->bot]);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function browsers(): iterable
    {
        yield 'Chrome on Windows' => [Requests::CHROME, 'desktop', 'chrome', 'windows'];
        yield 'Safari on iPhone' => [Requests::SAFARI_IPHONE, 'mobile', 'safari', 'ios'];
        yield 'Firefox on Linux' => [Requests::FIREFOX_LINUX, 'desktop', 'firefox', 'linux'];
        yield 'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0', 'desktop', 'edge', 'windows'];
        yield 'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', 'desktop', 'safari', 'macos'];
        yield 'Chrome on Android phone' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', 'mobile', 'chrome', 'android'];
        yield 'Chrome on Android tablet' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'tablet', 'chrome', 'android'];
        yield 'Safari on iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'tablet', 'safari', 'ios'];
        yield 'Chrome on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.90 Mobile/15E148 Safari/604.1', 'mobile', 'chrome', 'ios'];
        yield 'Firefox on Android' => ['Mozilla/5.0 (Android 14; Mobile; rv:132.0) Gecko/132.0 Firefox/132.0', 'mobile', 'firefox', 'android'];
        yield 'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', 'mobile', 'samsung', 'android'];
        yield 'Opera' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/115.0.0.0', 'desktop', 'opera', 'windows'];
        yield 'Yandex' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 YaBrowser/24.10.0.0 Safari/537.36', 'desktop', 'yandex', 'windows'];
        yield 'Chrome OS' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'desktop', 'chrome', 'chromeos'];
        yield 'Smart TV' => ['Mozilla/5.0 (SMART-TV; Linux; Tizen 7.0) AppleWebKit/537.36 (KHTML, like Gecko) 94.0.4606.31/7.0 TV Safari/537.36', 'tv', 'other', 'linux'];
        yield 'something else' => ['Lynx/2.9.0', 'desktop', 'other', 'other'];
    }

    #[DataProvider('bots')]
    public function testBots(string $userAgent, string $family): void
    {
        self::assertSame($family, $this->parser->parse($userAgent)->bot);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bots(): iterable
    {
        yield 'empty' => ['', 'generic'];
        yield 'blank' => ['  ', 'generic'];
        yield 'Googlebot' => [Requests::GOOGLEBOT, 'googlebot'];
        yield 'Googlebot smartphone' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'googlebot'];
        yield 'Bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'bingbot'];
        yield 'SeznamBot' => ['Mozilla/5.0 (compatible; SeznamBot/4.0; +http://napoveda.seznam.cz/seznambot-intro/)', 'seznambot'];
        yield 'Applebot' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)', 'applebot'];
        yield 'Ahrefs' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', 'ahrefs'];
        yield 'Semrush' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)', 'semrush'];
        yield 'SeznamBot, current' => ['Mozilla/5.0 (compatible; SeznamBot/4.0; +https://o-seznam.cz/napoveda/vyhledavani/en/seznambot-crawler/)', 'seznambot'];
        yield 'Seznam homepage crawler' => ['SeznamHomepageCrawler/v1.0.6', 'seznambot'];
        yield 'Bing AdIdxBot' => ['Mozilla/5.0 (compatible; adidxbot/2.0; +http://www.bing.com/bingbot.htm)', 'bingbot'];
        yield 'Microsoft preview' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0 MicrosoftPreview/2.0 +https://aka.ms/MicrosoftPreview', 'bingbot'];
        yield 'DuckDuckBot' => ['DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)', 'duckduckbot'];
        yield 'DuckAssistBot' => ['DuckAssistBot/1.2; (+http://duckduckgo.com/duckassistbot.html)', 'duckduckbot'];
        yield 'Yandex robot without "bot" in its name' => ['Mozilla/5.0 (compatible; YandexMetrika/2.0; +http://yandex.com/bots)', 'yandexbot'];
        yield 'Baidu renderer' => ['Mozilla/5.0 (compatible; Baiduspider-render/2.0; +http://www.baidu.com/search/spider.html)', 'baiduspider'];
        yield 'Qwant' => ['Mozilla/5.0 (compatible; Qwantbot/1.0_800166; +https://help.qwant.com/bot/)', 'qwant'];
        yield 'Mojeek' => ['Mozilla/5.0 (compatible; MojeekBot/0.11; +https://www.mojeek.com/bot.html)', 'mojeek'];
        yield 'Kagi' => ['Mozilla/5.0 (compatible; Kagibot/1.0; +https://kagi.com/bot)', 'kagi'];
        yield 'Yahoo Slurp' => ['Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)', 'yahoo'];
        yield 'Naver Yeti' => ['Mozilla/5.0 (compatible; Yeti/1.1; +http://naver.me/bot)', 'naver'];
        yield 'Coc Coc' => ['Mozilla/5.0 (compatible; coccocbot-web/1.0; +http://help.coccoc.com/searchengine)', 'coccoc'];
        yield 'Seekport' => ['Mozilla/5.0 (compatible; SeekportBot; +https://bot.seekport.com)', 'seekport'];
        yield 'Sogou' => ['Sogou web spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm#07)', 'sogou'];
        yield '360 Search' => ['Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.89 Safari/537.1; 360Spider', 'qihoo'];
        yield 'AhrefsSiteAudit' => ['Mozilla/5.0 (compatible; AhrefsSiteAudit/6.1; +http://ahrefs.com/robot/site-audit)', 'ahrefs'];
        yield 'Semrush site audit' => ['Mozilla/5.0 (compatible; SiteAuditBot/1.1; +https://siteauditbot.com)', 'semrush'];
        yield 'Semrush SplitSignal' => ['Mozilla/5.0 (compatible; SplitSignalBot/1.0)', 'semrush'];
        yield 'Moz rogerbot' => ['rogerbot/1.0 (http://moz.com/help/pro/what-is-rogerbot-, rogerbot-crawler+partager@moz.com)', 'dotbot'];
        yield 'Majestic' => ['Mozilla/5.0 (compatible; MJ12bot/v1.4.8; http://mj12bot.com/)', 'mj12bot'];
        yield 'Screaming Frog' => ['Screaming Frog SEO Spider/20.4', 'screamingfrog'];
        yield 'Sitebulb' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6439.0 Mobile Safari/537.36 +https://sitebulb.com', 'sitebulb'];
        yield 'BLEXBot' => ['Mozilla/5.0 (compatible; BLEXBot/1.0; +http://webmeup-crawler.com/)', 'blexbot'];
        yield 'Serpstat' => ['serpstatbot/2.1 (advanced backlink tracking bot; https://serpstatbot.com/; abuse@serpstatbot.com)', 'serpstat'];
        yield 'DataForSEO' => ['Mozilla/5.0 (compatible; DataForSeoBot; +https://dataforseo.com/dataforseo-bot)', 'dataforseo'];
        yield 'Babbar' => ['Mozilla/5.0 (compatible; Barkrowler/0.9; +https://babbar.tech/crawler)', 'barkrowler'];
        yield 'SE Ranking' => ['Mozilla/5.0 (compatible; SERankingBacklinksBot/1.0; +https://seranking.com/backlinks-crawler)', 'seranking'];
        yield 'Oncrawl' => ['Mozilla/5.0 (compatible; OnCrawl/1.0; +http://www.oncrawl.com)', 'oncrawl'];
        yield 'Common Crawl is not Oncrawl' => ['CCBot/2.0 (https://commoncrawl.org/faq/)', 'ccbot'];

        // Bots that copy another bot's robots.txt rules name it; that is not the bot.
        yield 'like Googlebot' => ['FreshRSS/1.24 (Linux; https://freshrss.org) like Googlebot', 'generic'];
        yield 'compatible with Googlebot' => ['SentiBot www.sentibot.eu (compatible with Googlebot)', 'generic'];
        yield 'like Feedfetcher' => ['Feedly/1.0 (+http://www.feedly.com/fetcher.html; like FeedFetcher-Google)', 'generic'];
        yield 'following Mediapartners' => ['Mozilla/5.0 (compatible; MSIE or Firefox mutant; not on Windows server;) Daumoa/4.0 (Following Mediapartners-Google)', 'daum'];
        yield 'Telegram, like Twitter' => ['TelegramBot (like TwitterBot)', 'telegrambot'];

        yield 'Facebook' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'facebook'];
        yield 'Meta AI crawler' => ['meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)', 'facebook'];

        // AI operators, one family each, whatever the agent does for them.
        yield 'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.4; +https://openai.com/gptbot', 'gptbot'];
        yield 'OAI-SearchBot' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36; compatible; OAI-SearchBot/1.4; +https://openai.com/searchbot', 'gptbot'];
        yield 'ChatGPT-User' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 'gptbot'];
        yield 'OAI-AdsBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-AdsBot/1.0; +https://openai.com/adsbot', 'gptbot'];
        yield 'ClaudeBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'claudebot'];
        yield 'Claude-SearchBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0; +Claude-SearchBot@anthropic.com)', 'claudebot'];
        yield 'Claude-User' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 'claudebot'];
        yield 'Claude-Agent' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-Agent/1.0) Chrome/154.0.0.0 Safari/537.36', 'claudebot'];
        yield 'PerplexityBot' => ['Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 'perplexitybot'];
        yield 'Perplexity-User' => ['Mozilla/5.0 (compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)', 'perplexitybot'];
        yield 'Google-Agent' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 (compatible; Google-Agent; +https://developers.google.com/crawling/docs/crawlers-fetchers/google-agent)', 'gemini'];
        yield 'Gemini Notebook' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 (compatible; Google-GeminiNotebook; +https://developers.google.com/crawling/docs/crawlers-fetchers/google-gemininotebook)', 'gemini'];
        yield 'NotebookLM' => ['Google-NotebookLM', 'gemini'];
        yield 'Vertex AI Agents' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Google-CloudVertexBot; +https://cloud.google.com/enterprise-search) Chrome/138.0.0.0 Safari/537.36', 'gemini'];
        yield 'MistralAI-User' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; MistralAI-User/1.0; +https://docs.mistral.ai/robots)', 'mistral'];

        // Google fetchers that do not call themselves bots.
        yield 'GoogleOther' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GoogleOther) Chrome/138.0.0.0 Safari/537.36', 'googlebot'];
        yield 'Google Read Aloud' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 (compatible; Google-Read-Aloud; +https://support.google.com/webmasters/answer/1061943)', 'googlebot'];
        yield 'Google-Safety' => ['Google-Safety', 'googlebot'];
        yield 'Google Messages' => ['GoogleMessages', 'googlebot'];

        yield 'headless Chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/130.0.0.0 Safari/537.36', 'headless'];
        yield 'Lighthouse' => ['Mozilla/5.0 (Linux; Android 11; moto g power) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse', 'lighthouse'];
        yield 'curl' => ['curl/8.7.1', 'curl'];
        yield 'python-requests' => ['python-requests/2.32.3', 'python'];
        yield 'Python urllib' => ['Python-urllib/3.12', 'python'];
        yield 'aiohttp' => ['Python/3.12 aiohttp/3.9.5', 'python'];
        yield 'httpx' => ['python-httpx/0.27.0', 'python'];
        yield 'PycURL' => ['PycURL/7.45.2 libcurl/8.5.0', 'python'];
        yield 'Scrapy' => ['Scrapy/2.11.2 (+https://scrapy.org)', 'python'];
        yield 'Perl' => ['libwww-perl/6.72', 'perl'];
        yield 'Go' => ['Go-http-client/2.0', 'go'];
        yield 'Node undici' => ['undici', 'node'];
        yield 'unknown crawler' => ['SomeCrawler/1.0', 'generic'];
        yield 'uptime monitor' => ['Site24x7 monitor', 'generic'];
        yield 'Nutch' => ['Apache Nutch/1.20', 'generic'];
        yield 'contact URL' => ['Mozilla/5.0 (compatible; Acme/1.0; +https://acme.example/about)', 'generic'];
    }

    /**
     * Families are checked without the generic pattern, so their tokens must
     * not match what real people's browsers send.
     */
    #[DataProvider('peopleNamedLikeBots')]
    public function testBrowsersThatMentionAnOperatorAreNotBots(string $userAgent): void
    {
        self::assertNull($this->parser->parse($userAgent)->bot);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleNamedLikeBots(): iterable
    {
        yield 'Pinterest app' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0.0.0 Mobile Safari/537.36 [Pinterest/Android]'];
        yield 'Google app' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) GSA/340.0.692929618 Mobile/15E148 Safari/604.1'];
        yield 'Sogou mobile browser' => ['Mozilla/5.0 (Linux; Android 12; V2134A) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0.4389.72 Mobile Safari/537.36 SogouMobileBrowser/12.6.5'];
        yield 'Coc Coc browser' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) coc_coc_browser/130.0.264 Chrome/124.0.6367.264 Safari/537.36'];
        yield 'Daum app' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 DaumApps/8.4.0 DaumDevice/mobile'];
        yield 'Yandex Browser' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 YaBrowser/24.10.0.0 Safari/537.36'];
        yield 'Facebook app' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/22B83 [FBAN/FBIOS;FBAV/490.0.0.40.107;FBBV/123]'];
    }

    public function testChromiumMajorVersion(): void
    {
        self::assertSame(130, $this->parser->parse(Requests::CHROME)->chromiumMajor);
        self::assertNull($this->parser->parse(Requests::FIREFOX_LINUX)->chromiumMajor);
        self::assertNull($this->parser->parse(Requests::SAFARI_IPHONE)->chromiumMajor);
    }

    public function testClassificationIsMemoised(): void
    {
        self::assertSame($this->parser->parse(Requests::CHROME), $this->parser->parse(Requests::CHROME));
    }

    /**
     * The classification of a User-Agent string is shared between requests;
     * the key is a hash, so APCu holds nothing a visitor sent.
     */
    #[RequiresPhpExtension('apcu')]
    public function testClassificationIsSharedThroughApcu(): void
    {
        if (! apcu_enabled()) {
            self::markTestSkipped('APCu is off; set apc.enable_cli=1.');
        }

        $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) Test/' . bin2hex(random_bytes(4));
        $key = 'request_analytics.ua.' . hash('xxh128', $userAgent);

        $info = $this->parser->parse($userAgent);
        self::assertSame($info->toArray(), apcu_fetch($key));

        // Another process (a fresh parser) uses the cached classification.
        apcu_store($key, ['tv', 'other', 'other', null, null]);
        self::assertSame('tv', (new UserAgentParser($this->project->configLoader()))->parse($userAgent)->device);

        $this->project->configure([
            'collector' => [
                'ua_cache' => 'none',
            ],
        ]);
        self::assertSame('desktop', (new UserAgentParser($this->project->configLoader()))->parse($userAgent)->device, 'ua_cache: none leaves APCu alone');
        apcu_delete($key);
    }

    public function testMemoStaysBounded(): void
    {
        $first = $this->parser->parse('Agent/0');
        for ($index = 1; $index <= 300; ++$index) {
            $this->parser->parse('Agent/' . $index);
        }

        self::assertNotSame($first, $this->parser->parse('Agent/0'));
        self::assertEquals($first, $this->parser->parse('Agent/0'));
    }

    public function testMobileClientHintWinsOverTheUserAgent(): void
    {
        $desktop = $this->parser->parse(Requests::CHROME);

        self::assertSame('mobile', $this->parser->device($desktop, '?1'));
        self::assertSame('desktop', $this->parser->device($desktop, '?0'));
        self::assertSame('desktop', $this->parser->device($desktop, null));
    }

    #[DataProvider('brands')]
    public function testBrowserFromClientHints(?string $secChUa, string $expected): void
    {
        self::assertSame($expected, $this->parser->browser($this->parser->parse(Requests::CHROME), $secChUa));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function brands(): iterable
    {
        yield 'no header' => [null, 'chrome'];
        yield 'empty header' => ['', 'chrome'];
        yield 'Chrome' => ['"Chromium";v="130", "Google Chrome";v="130", "Not?A_Brand";v="99"', 'chrome'];
        yield 'Edge, GREASE first' => ['"Not/A)Brand";v="8", "Chromium";v="130", "Microsoft Edge";v="130"', 'edge'];
        yield 'Brave' => ['"Brave";v="130", "Chromium";v="130", "Not_A Brand";v="24"', 'brave'];
        yield 'Chromium only' => ['"Chromium";v="130", "Not A;Brand";v="99"', 'chromium'];
        yield 'GREASE with escapes' => ['"Not\\"A\\\\Brand";v="8", "Opera";v="115", "Chromium";v="130"', 'opera'];
        yield 'unknown brand' => ['"Chromium";v="130", "Some New Browser";v="1"', 'other'];
        yield 'only GREASE' => ['"Not A;Brand";v="99"', 'chrome'];
    }

    public function testOsFromClientHints(): void
    {
        $info = $this->parser->parse(Requests::CHROME);

        self::assertSame('windows', $this->parser->os($info, null));
        self::assertSame('macos', $this->parser->os($info, '"macOS"'));
        self::assertSame('chromeos', $this->parser->os($info, '"Chrome OS"'));
        self::assertSame('other', $this->parser->os($info, '"Fuchsia"'));
        self::assertSame('windows', $this->parser->os($info, '"Unknown"'));
        self::assertSame('windows', $this->parser->os($info, '""'));
    }

    public function testInfoSurvivesTheCacheFormat(): void
    {
        $info = $this->parser->parse(Requests::CHROME);

        self::assertEquals($info, UserAgentInfo::fromArray($info->toArray()));
        self::assertNull(UserAgentInfo::fromArray(false), 'a cache miss');
        self::assertNull(UserAgentInfo::fromArray(['desktop', 'chrome']));
        self::assertNull(UserAgentInfo::fromArray(['desktop', 'chrome', 'windows', 5, null]));
    }
}

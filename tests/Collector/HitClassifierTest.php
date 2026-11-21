<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Collector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Collector\HitClassifier;
use TomVondracek\RequestAnalytics\Tests\Support\Project;
use TomVondracek\RequestAnalytics\Tests\Support\Requests;

final class HitClassifierTest extends TestCase
{
    private const SITE = 'https://rockfest-kurim.cz';

    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->project->configure([
            'own_hosts' => ['rockfest-kurim.cz', 'www.rockfest-kurim.cz'],
            'aliases' => [
                'hosts' => ['rockfestkurim.cz', 'www.rockfestkurim.cz', 'poster-a2'],
                'target' => self::SITE,
            ],
            'exclude_paths' => ['^/bolt', '^/theme/', '\.(css|js|png)$'],
            'search_params' => ['q', 's'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testPageView(): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/program/'));

        self::assertSame([
            'day' => '2026-11-13',
            'hour' => 10,
            'kind' => 'pageview',
            'path' => '/program',
            'device' => 'desktop',
            'browser' => 'chrome',
            'os' => 'windows',
            'lang' => 'cs',
            'channel' => 'direct',
            'source' => '',
            'campaign' => '',
            'is_entry' => true,
            'is_reload' => false,
            'ref' => null,
            'bot' => null,
            'via' => null,
            'term' => null,
        ], $hit?->toRow());
    }

    /**
     * The shape of a Sklik ad link (retargeting banner): pipes in the
     * campaign, a file name in `utm_content`, an empty `utm_term`, a number in
     * `utm_id`. Only channel, source and campaign are stored; the path holds
     * no query, and `utm_content`, `utm_term` and `utm_id` are never read.
     */
    public function testAdLinkKeepsOnlyChannelSourceAndCampaign(): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/?utm_source=seznam&utm_medium=qx7rv&utm_campaign=RTG+%7c+BNR+%7c+Fest26&utm_content=vlny_poster_2031_kampan-11.png&utm_term=&utm_id=5938140', [
            'Referer' => 'https://www.seznam.cz/',
            'Sec-Fetch-Site' => 'cross-site',
        ]));

        self::assertSame([
            'day' => '2026-11-13',
            'hour' => 10,
            'kind' => 'pageview',
            'path' => '/',
            'device' => 'desktop',
            'browser' => 'chrome',
            'os' => 'windows',
            'lang' => 'cs',
            'channel' => 'campaign',
            'source' => 'seznam',
            'campaign' => 'rtg bnr fest26',
            'is_entry' => true,
            'is_reload' => false,
            'ref' => null,
            'bot' => null,
            'via' => null,
            'term' => null,
        ], $hit?->toRow());
    }

    public function testDayAndHourAreInTheSiteTimezone(): void
    {
        $request = Requests::browser(self::SITE . '/');
        $request->server->set('REQUEST_TIME', strtotime('2026-12-31 23:30:00 UTC'));

        $hit = $this->classify($request);

        self::assertSame('2027-01-01', $hit?->day);
        self::assertSame(0, $hit->hour);
    }

    public function testInternalNavigationKeepsTheReferrerPath(): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/program', [
            'Referer' => self::SITE . '/kapely/?page=2',
            'Sec-Fetch-Site' => 'same-origin',
        ]));

        self::assertSame('internal', $hit?->channel);
        self::assertFalse($hit->isEntry);
        self::assertSame('/kapely', $hit->ref);
    }

    /**
     * A site that sends `Referrer-Policy: no-referrer`: a click between its
     * pages is no new visit, and not a move from a known page either.
     */
    public function testInternalNavigationWithoutAReferrerIsNoVisit(): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/program', [
            'Sec-Fetch-Site' => 'same-origin',
        ]));

        self::assertSame(['internal', false, null], [$hit?->channel, $hit?->isEntry, $hit?->ref]);
    }

    public function testEntryFromAnotherSiteKeepsNoReferrer(): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/', [
            'Referer' => 'https://www.facebook.com/',
            'Sec-Fetch-Site' => 'cross-site',
        ]));

        self::assertSame(['social', 'facebook', true, null], [$hit?->channel, $hit?->source, $hit?->isEntry, $hit?->ref]);
    }

    public function testClientHintsRefineTheUserAgent(): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/', [
            'Sec-CH-UA' => '"Not/A)Brand";v="8", "Chromium";v="130", "Microsoft Edge";v="130"',
            'Sec-CH-UA-Mobile' => '?1',
            'Sec-CH-UA-Platform' => '"Android"',
        ]));

        self::assertSame(['mobile', 'edge', 'android'], [$hit?->device, $hit?->browser, $hit?->os]);
    }

    #[DataProvider('languages')]
    public function testLanguage(?string $acceptLanguage, string $expected): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/', [
            'Accept-Language' => $acceptLanguage,
        ]));

        self::assertSame($expected, $hit?->lang);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function languages(): iterable
    {
        yield 'region' => ['cs-CZ,cs;q=0.9', 'cs'];
        yield 'plain' => ['EN', 'en'];
        yield 'with quality' => ['de;q=0.8', 'de'];
        yield 'three letters' => ['fil-PH', 'fil'];
        yield 'wildcard' => ['*', 'und'];
        yield 'missing' => [null, 'und'];
        yield 'nonsense' => ['<script>', 'und'];
    }

    public function testSearchTermIsRedacted(): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/search?s=Jan+Novak+777+123+456'));

        self::assertSame('jan novak (redacted)', $hit?->term);
        self::assertNull($this->classify(Requests::browser(self::SITE . '/search?q=&x=1'))?->term);
        self::assertNull($this->classify(Requests::browser(self::SITE . '/search?q[]=a'))?->term);
    }

    public function testReloadIsFlagged(): void
    {
        self::assertTrue($this->classify(Requests::browser(self::SITE . '/', [
            'Cache-Control' => 'max-age=0',
        ]))?->isReload);
        self::assertTrue($this->classify(Requests::browser(self::SITE . '/', [
            'Cache-Control' => 'no-cache',
        ]))?->isReload);
    }

    public function testReloadsCanBeIgnored(): void
    {
        $this->project->configure([
            'count_reloads' => false,
        ]);

        self::assertNull($this->classify(Requests::browser(self::SITE . '/', [
            'Cache-Control' => 'max-age=0',
        ])));
        self::assertNotNull($this->classify(Requests::browser(self::SITE . '/')));
    }

    public function testNotFoundKeepsWhereTheLinkWas(): void
    {
        $internal = $this->classify(Requests::browser(self::SITE . '/stary-program', [
            'Referer' => self::SITE . '/kapely',
            'Sec-Fetch-Site' => 'same-origin',
        ]), 404);
        $external = $this->classify(Requests::browser(self::SITE . '/stary-program', [
            'Referer' => 'https://www.kurim.cz/akce',
            'Sec-Fetch-Site' => 'cross-site',
        ]), 404);
        $typed = $this->classify(Requests::browser(self::SITE . '/stary-program'), 404);

        self::assertNotNull($internal);
        self::assertSame(['not_found', '/stary-program', '/kapely'], [$internal->kind, $internal->path, $internal->ref]);
        self::assertSame('kurim.cz', $external?->ref);
        self::assertNull($typed?->ref);
        self::assertSame(['', '', '', '', false], [$internal->device, $internal->browser, $internal->os, $internal->channel, $internal->isEntry], 'only the broken link is kept');
    }

    public function testBotKeepsOnlyItsFamily(): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/program', [
            'User-Agent' => Requests::GOOGLEBOT,
            'Accept' => '*/*',
            'Sec-Fetch-Dest' => null,
            'Sec-Fetch-Mode' => null,
        ]));

        self::assertSame([
            'day' => '2026-11-13',
            'hour' => 10,
            'kind' => 'bot',
            'path' => '',
            'device' => '',
            'browser' => '',
            'os' => '',
            'lang' => 'und',
            'channel' => '',
            'source' => '',
            'campaign' => '',
            'is_entry' => false,
            'is_reload' => false,
            'ref' => null,
            'bot' => 'googlebot',
            'via' => null,
            'term' => null,
        ], $hit?->toRow());
    }

    /**
     * AI agents that fetch a page on a user's question do not call themselves
     * bots, and send headers a page view would pass with.
     */
    public function testAiAgentThatDoesNotSayBotIsNoPageView(): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/program', [
            'User-Agent' => 'Mozilla/5.0 (compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)',
            'Sec-Fetch-Dest' => null,
            'Sec-Fetch-Mode' => null,
            'Sec-Fetch-Site' => null,
            'Sec-Fetch-User' => null,
        ]));

        self::assertSame(Hit::KIND_BOT, $hit?->kind);
        self::assertSame('perplexitybot', $hit->bot);
    }

    public function testBotIsCountedWhateverTheStatus(): void
    {
        $request = Requests::browser(self::SITE . '/wp-login.php', [
            'User-Agent' => 'curl/8.7.1',
        ]);

        self::assertSame('curl', $this->classify($request, 404)?->bot);
        self::assertSame('curl', $this->classify($request, 500)?->bot);
    }

    /**
     * Requests that copy a browser's User-Agent but not what the browser
     * sends along with it.
     *
     * @return iterable<string, array{string, array<string, string|null>}>
     */
    public static function disguised(): iterable
    {
        $noFetchMetadata = [
            'Sec-Fetch-Dest' => null,
            'Sec-Fetch-Mode' => null,
            'Sec-Fetch-Site' => null,
            'Sec-Fetch-User' => null,
        ];

        yield 'script with a copied Chrome User-Agent and Accept-Language' => [self::SITE . '/', [
            'User-Agent' => Requests::CHROME,
        ] + $noFetchMetadata];
        yield 'headless Chromium on a Linux server, User-Agent set to Windows' => [self::SITE . '/', [
            'User-Agent' => Requests::CHROME,
            'Sec-CH-UA' => '"Chromium";v="130", "Not?A_Brand";v="99"',
            'Sec-CH-UA-Mobile' => '?0',
            'Sec-CH-UA-Platform' => '"Linux"',
        ]];
        yield 'User-Agent of Chrome 130, Client Hints of Chrome 118' => [self::SITE . '/', [
            'User-Agent' => Requests::CHROME,
            'Sec-CH-UA' => '"Chromium";v="118", "Google Chrome";v="118", "Not=A?Brand";v="99"',
            'Sec-CH-UA-Mobile' => '?0',
            'Sec-CH-UA-Platform' => '"Windows"',
        ]];
    }

    /**
     * @param array<string, string|null> $headers
     */
    #[DataProvider('disguised')]
    public function testClientWhoseHeadersContradictItsUserAgentIsABot(string $url, array $headers): void
    {
        self::assertSame(HitClassifier::BOT_DISGUISED, $this->classify(Requests::browser($url, $headers))?->bot);
    }

    /**
     * Real browsers that differ from desktop Chrome in one way or another, with
     * the headers they send: they stay visitors.
     *
     * @return iterable<string, array{string, array<string, string|null>, array{string, string, string}}>
     */
    public static function realBrowsers(): iterable
    {
        yield 'Edge' => [self::SITE . '/', [
            'User-Agent' => Requests::CHROME . ' Edg/130.0.0.0',
            'Sec-CH-UA' => '"Microsoft Edge";v="130", "Chromium";v="130", "Not?A_Brand";v="99"',
            'Sec-CH-UA-Mobile' => '?0',
            'Sec-CH-UA-Platform' => '"Windows"',
        ], ['desktop', 'edge', 'windows']];
        yield 'Opera: its own version in the brand, the engine in the User-Agent' => [self::SITE . '/', [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/115.0.0.0',
            'Sec-CH-UA' => '"Opera";v="115", "Chromium";v="129", "Not=A?Brand";v="8"',
            'Sec-CH-UA-Mobile' => '?0',
            'Sec-CH-UA-Platform' => '"Windows"',
        ], ['desktop', 'opera', 'windows']];
        yield 'Samsung Internet' => [self::SITE . '/', [
            'User-Agent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
            'Sec-CH-UA' => '"Chromium";v="122", "Not(A:Brand";v="24", "Samsung Internet";v="26.0"',
            'Sec-CH-UA-Mobile' => '?1',
            'Sec-CH-UA-Platform' => '"Android"',
        ], ['mobile', 'samsung', 'android']];
        yield 'a Chromium build on Linux, with only the engine as brand' => [self::SITE . '/', [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            'Sec-CH-UA' => '"Chromium";v="130", "Not?A_Brand";v="99"',
            'Sec-CH-UA-Mobile' => '?0',
            'Sec-CH-UA-Platform' => '"Linux"',
        ], ['desktop', 'chromium', 'linux']];
        yield 'Chrome on Android asking for the desktop site' => [self::SITE . '/', [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            'Sec-CH-UA' => '"Chromium";v="130", "Google Chrome";v="130", "Not?A_Brand";v="99"',
            'Sec-CH-UA-Mobile' => '?0',
            'Sec-CH-UA-Platform' => '"Android"',
        ], ['desktop', 'chrome', 'android']];
        yield 'Chrome on iOS: WebKit, no Client Hints' => [self::SITE . '/', [
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.90 Mobile/15E148 Safari/604.1',
        ], ['mobile', 'chrome', 'ios']];
        yield 'Chrome over plain HTTP: no Client Hints, no fetch metadata' => ['http://rockfest-kurim.cz/', [
            'User-Agent' => Requests::CHROME,
            'Sec-Fetch-Dest' => null,
            'Sec-Fetch-Mode' => null,
            'Sec-Fetch-Site' => null,
            'Sec-Fetch-User' => null,
        ], ['desktop', 'chrome', 'windows']];
        yield 'an older Android WebView without Client Hints' => [self::SITE . '/', [
            'User-Agent' => 'Mozilla/5.0 (Linux; Android 13; Pixel 7; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/115.0.5790.166 Mobile Safari/537.36',
        ], ['mobile', 'chrome', 'android']];
    }

    /**
     * @param array<string, string|null> $headers
     * @param array{string, string, string} $expected device, browser, OS
     */
    #[DataProvider('realBrowsers')]
    public function testRealBrowsersAreNoBots(string $url, array $headers, array $expected): void
    {
        $hit = $this->classify(Requests::browser($url, $headers));

        self::assertSame(Hit::KIND_PAGEVIEW, $hit?->kind);
        self::assertSame($expected, [$hit->device, $hit->browser, $hit->os]);
    }

    public function testEmptyUserAgentIsABot(): void
    {
        self::assertSame('generic', $this->classify(Requests::browser(self::SITE . '/', [
            'User-Agent' => null,
        ]))?->bot);
    }

    public function testChromiumWithoutItsHeadersIsABot(): void
    {
        $bare = [
            'Sec-Fetch-Dest' => null,
            'Sec-Fetch-Mode' => null,
            'Sec-Fetch-Site' => null,
            'Sec-Fetch-User' => null,
            'Accept-Language' => null,
        ];

        self::assertSame(HitClassifier::BOT_DISGUISED, $this->classify(Requests::browser(self::SITE . '/', $bare))?->bot);
        self::assertSame('pageview', $this->classify(Requests::browser(self::SITE . '/', [
            'Accept-Language' => 'cs',
        ] + $bare))?->kind, 'one of the two headers is enough');
        self::assertSame('pageview', $this->classify(Requests::browser(self::SITE . '/', [
            'User-Agent' => str_replace('Chrome/130', 'Chrome/79', Requests::CHROME),
        ] + $bare))?->kind, 'an old Chromium did not send them');
    }

    /**
     * Firefox 90+ and Safari 16.4+ have no Client Hints, but send fetch
     * metadata and their languages with every page: a script that copies
     * their User-Agent and neither header is no visitor.
     */
    public function testFirefoxOrSafariWithoutTheirHeadersIsABot(): void
    {
        $bare = [
            'Sec-Fetch-Dest' => null,
            'Sec-Fetch-Mode' => null,
            'Sec-Fetch-Site' => null,
            'Sec-Fetch-User' => null,
            'Accept-Language' => null,
        ];

        foreach ([Requests::FIREFOX_LINUX, Requests::SAFARI_IPHONE] as $userAgent) {
            self::assertSame(HitClassifier::BOT_DISGUISED, $this->classify(Requests::browser(self::SITE . '/', [
                'User-Agent' => $userAgent,
            ] + $bare))?->bot, $userAgent);
            self::assertSame('pageview', $this->classify(Requests::browser(self::SITE . '/', [
                'User-Agent' => $userAgent,
                'Accept-Language' => 'cs',
            ] + $bare))?->kind, 'one of the two headers is enough: no fetch metadata over plain HTTP');
            self::assertSame('pageview', $this->classify(Requests::browser(self::SITE . '/', [
                'User-Agent' => $userAgent,
            ]))?->kind, 'with its headers it is a visitor');
        }

        self::assertSame('pageview', $this->classify(Requests::browser(self::SITE . '/', [
            'User-Agent' => str_replace(['rv:132.0', 'Firefox/132.0'], ['rv:89.0', 'Firefox/89.0'], Requests::FIREFOX_LINUX),
        ] + $bare))?->kind, 'an old Firefox did not send them');
    }

    /**
     * Puppeteer or Playwright with the User-Agent of a normal Chrome: the
     * Client Hints still name the headless browser.
     */
    public function testHeadlessChromiumIsABotWhateverItsUserAgent(): void
    {
        self::assertSame(HitClassifier::BOT_HEADLESS, $this->classify(Requests::browser(self::SITE . '/', [
            'Sec-CH-UA' => '"Chromium";v="130", "HeadlessChrome";v="130", "Not?A_Brand";v="99"',
        ]))?->bot);
    }

    /**
     * Agents that sign their requests (Web Bot Auth) may send the User-Agent
     * and headers of a browser; a browser never signs.
     *
     * @param array<string, string> $headers
     */
    #[DataProvider('signedAgents')]
    public function testSignedAgentIsABot(array $headers, string $family): void
    {
        self::assertSame($family, $this->classify(Requests::browser(self::SITE . '/', $headers))?->bot);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function signedAgents(): iterable
    {
        $signature = [
            'Signature-Input' => 'sig1=("@authority" "signature-agent");created=1795000000;keyid="poqkLGiymh_W0uP6PZFw-dvez3QJT5SolqXBCW38r0U";tag="web-bot-auth"',
            'Signature' => 'sig1=:jdq0SqOwHdyHr9+r5jw3iYZH6aNGKijYp/EstF4RQTQdi5N5YYKrD+mCT1HA1nZDsi6nJKuHxUi/5Syp3rLWBA==:',
        ];

        yield 'ChatGPT agent' => [[
            'Signature-Agent' => '"https://chatgpt.com"',
        ] + $signature, 'gptbot'];
        yield 'dictionary form, subdomain' => [[
            'Signature-Agent' => 'agent1="https://agent.anthropic.com"',
        ] + $signature, 'claudebot'];
        yield 'unknown operator' => [[
            'Signature-Agent' => '"https://agents.example.com"',
        ] + $signature, 'generic'];
        yield 'signature without an agent' => [$signature, 'generic'];
    }

    /**
     * A page the browser had cached and revalidated (`If-None-Match`,
     * `If-Modified-Since`) was opened all the same: a page view.
     */
    public function testNotModifiedIsAPageview(): void
    {
        self::assertSame(Hit::KIND_PAGEVIEW, $this->classify(Requests::browser(self::SITE . '/', [
            'If-None-Match' => '"abc"',
        ]), 304)?->kind);
    }

    /**
     * @param array<string, string|null> $headers
     */
    #[DataProvider('ignored')]
    public function testIgnored(string $path, array $headers = [], int $status = 200, string $method = Request::METHOD_GET): void
    {
        self::assertNull($this->classify(Requests::browser(self::SITE . $path, $headers, $method), $status));
    }

    /**
     * @return iterable<string, array{0: string, 1?: array<string, string|null>, 2?: int, 3?: string}>
     */
    public static function ignored(): iterable
    {
        yield 'excluded path' => ['/bolt/login'];
        yield 'excluded directory' => ['/theme/skeleton/index.html'];
        yield 'excluded extension' => ['/files/photo.PNG'];
        yield 'encoded excluded path' => ['/%62olt/login'];
        yield 'POST' => ['/', [], 200, Request::METHOD_POST];
        yield 'HEAD' => ['/', [], 200, Request::METHOD_HEAD];
        yield 'redirect' => ['/', [], 302];
        yield 'server error' => ['/', [], 500];
        yield 'forbidden' => ['/', [], 403];
        yield 'image request' => ['/', [
            'Sec-Fetch-Dest' => 'image',
            'Sec-Fetch-Mode' => 'no-cors',
        ]];
        yield 'fetch() of a page' => ['/', [
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
        ]];
        yield 'iframe' => ['/', [
            'Sec-Fetch-Dest' => 'iframe',
        ]];
        yield 'no fetch metadata, not html' => ['/', [
            'Sec-Fetch-Dest' => null,
            'Sec-Fetch-Mode' => null,
            'Accept' => 'application/json',
        ]];
        yield 'speculation rules prefetch' => ['/', [
            'Sec-Purpose' => 'prefetch;prerender',
        ]];
        yield 'link prefetch' => ['/', [
            'Purpose' => 'prefetch',
        ]];
        yield 'Firefox prefetch' => ['/', [
            'X-Moz' => 'prefetch',
        ]];
        yield 'Safari preview' => ['/', [
            'X-Purpose' => 'preview',
        ]];
    }

    public function testOldBrowserWithoutFetchMetadataCountsByItsAcceptHeader(): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/', [
            'User-Agent' => Requests::SAFARI_IPHONE,
            'Sec-Fetch-Dest' => null,
            'Sec-Fetch-Mode' => null,
            'Sec-Fetch-Site' => null,
        ]));

        self::assertSame(['pageview', 'mobile', 'safari', 'ios', 'unknown'], [$hit?->kind, $hit?->device, $hit?->browser, $hit?->os, $hit?->channel]);
    }

    public function testDisabledCollectsNothing(): void
    {
        $this->project->configure([
            'enabled' => false,
        ]);

        self::assertNull($this->classify(Requests::browser(self::SITE . '/')));
        self::assertNull($this->project->classifier()->classifyAliasHop(Requests::browser('https://rockfestkurim.cz/')));
    }

    #[DataProvider('optOutSignals')]
    public function testOptOutKeepsOnlyWhatWasRequested(string $header): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/program?q=tajne&utm_medium=email&ra_via=poster-a2', [
            $header => '1',
            'Referer' => self::SITE . '/kapely',
            'Cache-Control' => 'max-age=0',
        ]));

        self::assertSame([
            'day' => '2026-11-13',
            'hour' => 10,
            'kind' => 'pageview',
            'path' => '/program',
            'device' => '(opt_out)',
            'browser' => '(opt_out)',
            'os' => '(opt_out)',
            'lang' => 'und',
            'channel' => '(opt_out)',
            'source' => '(opt_out)',
            'campaign' => '(opt_out)',
            'is_entry' => true,
            'is_reload' => false,
            'ref' => null,
            'bot' => null,
            'via' => null,
            'term' => null,
        ], $hit?->toRow(), 'a campaign link: an entry');
    }

    /**
     * The entries of the trend include visitors who opted out; whether a page
     * was an entry says nothing about the visitor.
     */
    public function testOptOutPageViewKeepsWhetherItWasAnEntry(): void
    {
        $typed = $this->classify(Requests::browser(self::SITE . '/program', [
            'Sec-GPC' => '1',
        ]));
        $internal = $this->classify(Requests::browser(self::SITE . '/program', [
            'Sec-GPC' => '1',
            'Referer' => self::SITE . '/kapely',
            'Sec-Fetch-Site' => 'same-origin',
        ]));

        self::assertSame([true, '(opt_out)', null], [$typed?->isEntry, $typed?->channel, $typed?->ref]);
        self::assertSame([false, '(opt_out)', null], [$internal?->isEntry, $internal?->channel, $internal?->ref], 'the referrer path is not kept');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function optOutSignals(): iterable
    {
        yield 'Global Privacy Control' => ['Sec-GPC'];
        yield 'Do Not Track' => ['DNT'];
    }

    public function testOptOutCanSkipTheRequest(): void
    {
        $this->project->configure([
            'privacy' => [
                'opt_out_mode' => 'skip',
            ],
        ]);

        self::assertNull($this->classify(Requests::browser(self::SITE . '/', [
            'Sec-GPC' => '1',
        ])));
        self::assertNull($this->classify(Requests::browser(self::SITE . '/nope', [
            'DNT' => '1',
        ]), 404));
    }

    public function testOptOutSignalsCanBeIgnored(): void
    {
        $this->project->configure([
            'privacy' => [
                'honor_gpc' => false,
                'honor_dnt' => false,
            ],
        ]);

        self::assertSame('desktop', $this->classify(Requests::browser(self::SITE . '/', [
            'Sec-GPC' => '1',
            'DNT' => '1',
        ]))?->device);
    }

    public function testDntZeroIsNoOptOut(): void
    {
        self::assertSame('desktop', $this->classify(Requests::browser(self::SITE . '/', [
            'DNT' => '0',
        ]))?->device);
    }

    public function testAliasMarkerOnTheLandingPage(): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/?ra_via=RockfestKurim.cz', [
            'Sec-Fetch-Site' => 'cross-site',
        ]));

        self::assertSame(['pageview', 'rockfestkurim.cz', 'direct', true], [$hit?->kind, $hit?->via, $hit?->channel, $hit?->isEntry]);
    }

    public function testAliasMarkerMayBeAnyListedName(): void
    {
        self::assertSame('poster-a2', $this->classify(Requests::browser(self::SITE . '/vstupenky?ra_via=poster-a2'))?->via);
    }

    public function testUnlistedAliasMarkerIsDropped(): void
    {
        $hit = $this->classify(Requests::browser(self::SITE . '/?ra_via=evil.example', [
            'Sec-Fetch-Site' => 'cross-site',
        ]));

        self::assertNull($hit?->via);
        self::assertSame('unknown', $hit?->channel);
    }

    /**
     * Example A of the spec: the alias typed into the address bar.
     */
    public function testAliasHopTyped(): void
    {
        $hit = $this->project->classifier()
            ->classifyAliasHop(Requests::browser('https://rockfestkurim.cz/program?x=1'));

        self::assertSame([
            'day' => '2026-11-13',
            'hour' => 10,
            'kind' => 'alias',
            'path' => '/program',
            'device' => '',
            'browser' => '',
            'os' => '',
            'lang' => 'und',
            'channel' => 'direct',
            'source' => '',
            'campaign' => '',
            'is_entry' => false,
            'is_reload' => false,
            'ref' => null,
            'bot' => null,
            'via' => 'rockfestkurim.cz',
            'term' => null,
        ], $hit?->toRow());
    }

    /**
     * Example B of the spec: a Facebook post links to the alias.
     */
    public function testAliasHopKeepsTheOriginalSource(): void
    {
        $hit = $this->project->classifier()
            ->classifyAliasHop(Requests::browser('https://www.rockfestkurim.cz/', [
                'Referer' => 'https://www.facebook.com/',
                'Sec-Fetch-Site' => 'cross-site',
            ]));

        self::assertSame(['alias', 'www.rockfestkurim.cz', '/', 'social', 'facebook'], [$hit?->kind, $hit?->via, $hit?->path, $hit?->channel, $hit?->source]);
    }

    public function testAliasHopOfBotsPrefetchesAndAssetsIsNotCounted(): void
    {
        $classifier = $this->project->classifier();

        self::assertNull($classifier->classifyAliasHop(Requests::browser('https://rockfestkurim.cz/', [
            'User-Agent' => Requests::GOOGLEBOT,
        ])));
        self::assertNull($classifier->classifyAliasHop(Requests::browser('https://rockfestkurim.cz/', [
            'Sec-Purpose' => 'prefetch',
        ])));
        self::assertNull($classifier->classifyAliasHop(Requests::browser('https://rockfestkurim.cz/favicon.png')));
        self::assertNull($classifier->classifyAliasHop(Requests::browser('https://rockfestkurim.cz/', [], Request::METHOD_POST)));
    }

    public function testAliasHopWithOptOut(): void
    {
        $request = Requests::browser('https://rockfestkurim.cz/program', [
            'Sec-GPC' => '1',
            'Referer' => 'https://www.facebook.com/',
        ]);

        $hit = $this->project->classifier()
            ->classifyAliasHop($request);
        self::assertSame(['alias', '/program', 'rockfestkurim.cz', '(opt_out)', '(opt_out)'], [$hit?->kind, $hit?->path, $hit?->via, $hit?->channel, $hit?->source]);

        $this->project->configure([
            'privacy' => [
                'opt_out_mode' => 'skip',
            ],
        ]);
        self::assertNull($this->project->classifier()->classifyAliasHop($request));
    }

    /**
     * Whatever a request sends, a row must fit the columns it is stored in.
     */
    public function testEveryValueFitsItsColumn(): void
    {
        $long = str_repeat('žluťoučký-kůň-', 40);
        $requests = [
            Requests::browser(self::SITE . '/' . rawurlencode($long) . '?q=' . rawurlencode($long) . '&utm_medium=' . rawurlencode($long) . '&utm_source=' . rawurlencode($long) . '&utm_campaign=' . rawurlencode($long), [
                'Accept-Language' => $long,
                'Sec-CH-UA' => '"' . $long . '";v="1"',
                'Sec-CH-UA-Platform' => '"' . $long . '"',
            ]),
            Requests::browser(self::SITE . '/a', [
                'Referer' => 'https://' . $long . '.example/' . $long,
            ]),
            Requests::browser(self::SITE . '/a', [
                'Referer' => self::SITE . '/' . rawurlencode($long),
                'Sec-Fetch-Site' => 'same-origin',
            ]),
        ];

        foreach ($requests as $request) {
            foreach ([200, 404] as $status) {
                $hit = $this->classify($request, $status);
                self::assertNotNull($hit);
                self::assertEquals($hit, Hit::fromRow($hit->toRow()), 'a row that does not fit is rejected by fromRow()');
            }
        }
    }

    public function testIdsInPathsBecomeRouteTemplates(): void
    {
        $request = Requests::browser(self::SITE . '/product/12345', [
            'Referer' => self::SITE . '/order/987654?step=2',
            'Sec-Fetch-Site' => 'same-origin',
        ]);
        $hit = $this->classify($request);
        self::assertSame(['/product/:id', '/order/:id'], [$hit?->path, $hit?->ref]);

        $notFound = $this->classify(Requests::browser(self::SITE . '/reset/5d41402abc4b2a76b9719d911017c592'), 404);
        self::assertSame('/reset/:id', $notFound?->path, 'tokens stay out of the broken-link report too');

        $this->project->configure([
            'route_templates' => false,
        ]);
        self::assertSame('/product/12345', $this->classify($request)?->path);
    }

    private function classify(Request $request, int $status = 200): ?Hit
    {
        return $this->project->classifier()
            ->classify($request, $status);
    }
}

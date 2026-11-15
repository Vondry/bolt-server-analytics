<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Controller;

use Bolt\Event\Subscriber\ZoneSubscriber;
use Bolt\Widget\Injector\RequestZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Controller\ApiController;
use TomVondracek\RequestAnalytics\Report\SourcesReport;
use TomVondracek\RequestAnalytics\Security\AnalyticsVoter;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Tests\Support\Database;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;
use TomVondracek\RequestAnalytics\Tests\Support\Translations;

#[Group('database')]
final class ApiControllerTest extends TestCase
{
    private const TOKEN = 'valid-token';
    private const ROLLUP_TOKEN = 'valid-rollup-token';
    private const ERASE_TOKEN = 'valid-erase-token';

    private Pipeline $pipeline;
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->pipeline = new Pipeline();
        $this->logger = new MemoryLogger();
    }

    protected function tearDown(): void
    {
        $this->pipeline->remove();
    }

    public function testRoutesAreBelowTheBackendUrlAndNeedTheDashboardPermission(): void
    {
        $prefix = (new ReflectionClass(ApiController::class))->getAttributes(Route::class)[0]->newInstance();
        self::assertSame('%bolt.backend_url%/extension/request-analytics/api', $prefix->path);
        self::assertSame('request_analytics_api_', $prefix->name);

        foreach ([
            'trend' => ['/trend', [Request::METHOD_GET], ApiController::ROUTE_TREND],
            'rollup' => ['/rollup', [Request::METHOD_POST], ApiController::ROUTE_ROLLUP],
            'breakdown' => ['/breakdown', [Request::METHOD_GET], ApiController::ROUTE_BREAKDOWN],
            'pages' => ['/pages', [Request::METHOD_GET], ApiController::ROUTE_PAGES],
            'summary' => ['/summary', [Request::METHOD_GET], ApiController::ROUTE_SUMMARY],
            'brokenLinks' => ['/broken-links', [Request::METHOD_GET], ApiController::ROUTE_BROKEN_LINKS],
            'searches' => ['/searches', [Request::METHOD_GET], ApiController::ROUTE_SEARCHES],
            'sources' => ['/sources', [Request::METHOD_GET], ApiController::ROUTE_SOURCES],
            'campaigns' => ['/campaigns', [Request::METHOD_GET], ApiController::ROUTE_CAMPAIGNS],
            'aliases' => ['/aliases', [Request::METHOD_GET], ApiController::ROUTE_ALIASES],
            'pageFlow' => ['/page-flow', [Request::METHOD_GET], ApiController::ROUTE_PAGE_FLOW],
            'bots' => ['/bots', [Request::METHOD_GET], ApiController::ROUTE_BOTS],
            'hours' => ['/hours', [Request::METHOD_GET], ApiController::ROUTE_HOURS],
        ] as $action => [$path, $methods, $name]) {
            $method = new ReflectionMethod(ApiController::class, $action);
            $route = $method->getAttributes(Route::class)[0]->newInstance();

            self::assertSame([$path, $methods, $name], [$route->path, $route->methods, $prefix->name . $route->name]);
            self::assertSame(AnalyticsVoter::VIEW, $method->getAttributes(IsGranted::class)[0]->newInstance()->attribute);
        }
    }

    /**
     * Deleting statistics is for the roles of `access.delete` (AnalyticsVoter).
     */
    public function testDeletingNeedsTheAdminRole(): void
    {
        foreach ([
            'erasePreview' => ['/erase/preview', [Request::METHOD_GET], ApiController::ROUTE_ERASE_PREVIEW],
            'erase' => ['/erase', [Request::METHOD_POST], ApiController::ROUTE_ERASE],
        ] as $action => [$path, $methods, $name]) {
            $method = new ReflectionMethod(ApiController::class, $action);
            $route = $method->getAttributes(Route::class)[0]->newInstance();

            self::assertSame([$path, $methods, $name], [$route->path, $route->methods, 'request_analytics_api_' . $route->name]);
            self::assertSame(AnalyticsVoter::DELETE, $method->getAttributes(IsGranted::class)[0]->newInstance()->attribute);
        }
    }

    /**
     * Not an API: without the token of the page's session (a script, a link,
     * an `<img>`) every endpoint refuses, before it reads anything.
     */
    public function testEveryEndpointNeedsTheTokenOfThePage(): void
    {
        $this->pipeline->daily([
            '2026-11-13' => 5,
        ]);
        $range = '?from=2026-11-07&to=2026-11-13&path=/&days=7';
        $controller = $this->controller();

        $endpoints = [
            'trend' => $controller->trend(...),
            'breakdown' => $controller->breakdown(...),
            'pages' => $controller->pages(...),
            'summary' => $controller->summary(...),
            'brokenLinks' => $controller->brokenLinks(...),
            'searches' => $controller->searches(...),
            'pageFlow' => $controller->pageFlow(...),
            'bots' => $controller->bots(...),
            'hours' => $controller->hours(...),
            'sources' => $controller->sources(...),
            'campaigns' => $controller->campaigns(...),
            'aliases' => $controller->aliases(...),
            'erasePreview' => $controller->erasePreview(...),
        ];

        foreach ($endpoints as $action => $endpoint) {
            foreach ([[], [ApiController::CSRF_HEADER => 'wrong'], [ApiController::CSRF_HEADER => self::ERASE_TOKEN]] as $headers) {
                $request = Request::create('/x' . $range);
                $request->headers->add($headers);
                $response = $endpoint($request);

                self::assertSame(403, $response->getStatusCode(), $action);
                self::assertSame('csrf', self::data($response)['error'], $action);
            }

            self::assertSame(200, $endpoint(self::fromPage('/x' . $range))->getStatusCode(), $action . ' with the token');
        }

        self::assertSame(403, $controller->rollup(Request::create('/rollup', 'POST'))->getStatusCode());
        self::assertSame(1, $this->pipeline->database->count($this->pipeline->database->tables->daily()), 'nothing deleted');
    }

    /**
     * A browser request of another site is refused, even with the cookie and a
     * token it somehow had.
     */
    public function testRequestsOfAnotherSiteAreRefused(): void
    {
        foreach ([['Sec-Fetch-Site' => 'cross-site'], ['Sec-Fetch-Site' => 'same-site'], ['Sec-Fetch-Site' => 'none'], ['Origin' => 'https://evil.example'], ['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://evil.example']] as $headers) {
            $request = self::fromPage('/summary?from=2026-11-07&to=2026-11-13');
            $request->headers->remove('Sec-Fetch-Site');
            $request->headers->add($headers);

            $response = $this->controller()->summary($request);

            self::assertSame(403, $response->getStatusCode(), (string) json_encode($headers));
            self::assertSame('origin', self::data($response)['error']);
        }

        $request = self::fromPage('https://localhost/summary?from=2026-11-07&to=2026-11-13');
        $request->headers->set('Origin', 'https://localhost');
        self::assertSame(200, $this->controller()->summary($request)->getStatusCode(), 'its own origin');
    }

    /**
     * Behind a proxy that passes on another `Host` (`app:8080`) than the
     * visitor used: the public host is one of `own_hosts`, so its `Origin` is
     * this site.
     */
    public function testTheOriginMayBeAnOwnHostBehindAProxy(): void
    {
        $request = self::fromPage('http://app:8080/summary?from=2026-11-07&to=2026-11-13');
        $request->headers->set('Origin', 'https://www.example.com');
        self::assertSame(403, $this->controller()->summary($request)->getStatusCode(), 'not listed');

        $this->pipeline->project->configure([
            'own_hosts' => ['WWW.example.com'],
        ]);
        self::assertSame(200, $this->controller()->summary($request)->getStatusCode(), 'listed in own_hosts');
    }

    /**
     * Not stored, framed, sniffed or embedded by another site; errors too.
     */
    public function testAnswersCarryTheSecurityHeaders(): void
    {
        foreach ([$this->controller()->summary(self::fromPage('/summary?from=2026-11-07&to=2026-11-13')), $this->controller()->summary(Request::create('/summary'))] as $response) {
            self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
            self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
            self::assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
            self::assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        }
    }

    public function testErasePreviewCountsWhatWouldGo(): void
    {
        $this->pipeline->daily([
            '2026-11-06' => [100, 40],
            '2026-11-13' => [5, 2],
        ]);

        $response = $this->controller()
            ->erasePreview(self::fromPage('/erase/preview?days=7'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame([
            'from' => '2026-11-07',
            'to' => '2026-11-13',
            'firstDay' => '2026-11-13',
            'pageviews' => 5,
            'visits' => 2,
        ], self::data($response));
        self::assertSame(105, self::data($this->controller()->erasePreview(self::fromPage('/erase/preview?days=all')))['pageviews']);
    }

    public function testEraseDeletesThePeriodLogsWhoDidItAndSaysSoAfterTheReload(): void
    {
        $this->pipeline->daily([
            '2026-11-06' => 100,
            '2026-11-13' => 5,
        ]);
        $request = self::erasePost('7', [
            ApiController::CSRF_HEADER => self::ERASE_TOKEN,
        ]);

        $response = $this->controller('cs', user: 'admin')
            ->erase($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['from' => '2026-11-07', 'rows' => 1, 'spoolFiles' => 0], self::data($response));
        self::assertSame(['2026-11-06'], array_map(static fn (mixed $day): string => is_string($day) ? mb_substr($day, 0, 10) : '', $this->pipeline->database->connection->fetchFirstColumn(sprintf('SELECT day FROM %s', $this->pipeline->database->tables->daily()))));
        self::assertSame([[
            'level' => 'notice',
            'message' => 'request-analytics: admin deleted the statistics from 2026-11-07 on (1 rows, 0 spool files).',
        ]], $this->logger->records);
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);
        self::assertSame(['success' => ['Statistiky zvoleného období byly smazány.']], $session->getFlashBag()->all(), 'in the backend language');
    }

    public function testEraseEverything(): void
    {
        $this->pipeline->daily([
            '2020-01-01' => 100,
            '2026-11-13' => 5,
        ]);
        $request = self::erasePost('all', [
            ApiController::CSRF_HEADER => self::ERASE_TOKEN,
        ]);

        $response = $this->controller()
            ->erase($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull(self::data($response)['from']);
        self::assertSame(0, $this->pipeline->database->count($this->pipeline->database->tables->daily()));
        self::assertSame('request-analytics: unknown user deleted all statistics (2 rows, 0 spool files).', $this->logger->records[0]['message']);
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);
        self::assertSame(['success' => ['All statistics were deleted.']], $session->getFlashBag()->all());
    }

    public function testEraseNeedsItsOwnValidToken(): void
    {
        $this->pipeline->daily([
            '2026-11-13' => 5,
        ]);

        foreach ([[], [
            ApiController::CSRF_HEADER => 'wrong',
        ], [
            // The token of the other requests is not enough.
            ApiController::CSRF_HEADER => self::TOKEN,
        ]] as $headers) {
            $response = $this->controller()
                ->erase(self::erasePost('all', $headers));

            self::assertSame(403, $response->getStatusCode());
            self::assertSame('csrf', self::data($response)['error']);
        }

        self::assertSame(1, $this->pipeline->database->count($this->pipeline->database->tables->daily()), 'nothing deleted');
        self::assertSame([], $this->logger->records);
    }

    public function testEraseNeedsAPeriod(): void
    {
        foreach (['', '0', '-3', '7.5', 'some', '36501'] as $days) {
            $response = $this->controller()
                ->erase(self::erasePost($days, [
                    ApiController::CSRF_HEADER => self::ERASE_TOKEN,
                ]));

            self::assertSame(400, $response->getStatusCode(), $days);
            self::assertSame('invalid', self::data($response)['error']);
            $message = self::data($response)['message'];
            self::assertIsString($message);
            self::assertStringContainsString('"days"', $message);
        }

        self::assertSame(400, $this->controller()->erasePreview(self::fromPage('/erase/preview'))->getStatusCode());
    }

    public function testEraseWhileARollupRunsAsksToTryAgain(): void
    {
        $lock = $this->pipeline->rollupService()
            ->lock();
        self::assertTrue($lock->acquire());

        try {
            $response = $this->controller(eraserWaitMs: 50)
                ->erase(self::erasePost('1', [
                    ApiController::CSRF_HEADER => self::ERASE_TOKEN,
                ]));
        } finally {
            $lock->release();
        }

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'busy', 'message' => 'The statistics are being aggregated right now. Try again in a minute.'], self::data($response));
    }

    /**
     * Messages come in the user's backend language: Bolt applies it only to
     * requests whose controller is in the backend zone.
     */
    public function testIsInBoltsBackendZone(): void
    {
        $request = Request::create('/bolt/extension/request-analytics/api/trend');
        $request->attributes->set('_controller', ApiController::class . '::trend');

        self::assertSame(RequestZone::BACKEND, (new ZoneSubscriber())->setZone($request));
    }

    public function testTrendUsesTheConfiguredDefaults(): void
    {
        $this->pipeline->project->configure([
            'widget' => [
                'default_range' => '1y',
                'compare' => 'none',
                'metric' => 'entries',
            ],
        ]);
        $this->pipeline->daily([
            '2026-11-13' => [10, 4],
        ]);

        $response = $this->controller()
            ->trend(self::fromPage('/bolt/extension/request-analytics/api/trend'));
        $data = self::data($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame(['1y', 'month', 'entries', 'none'], [$data['range'], $data['interval'], $data['metric'], $data['compare']]);
        self::assertIsArray($data['total']);
        self::assertSame(4, $data['total']['value']);
        self::assertNull($data['aggregatedUntil'], 'no rollup yet');
        self::assertSame(0, $data['pending']);
    }

    public function testTrendWithParameters(): void
    {
        $this->pipeline->daily([
            '2026-11-10' => 5,
            '2026-11-09' => 3,
        ]);
        $this->pipeline->rawHitWriter()
            ->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/'));

        $data = self::data($this->controller()->trend(self::fromPage('/trend', 'GET', [
            'range' => 'custom',
            'interval' => 'day',
            'metric' => 'pageviews',
            'compare' => 'previous',
            'from' => '2026-11-09',
            'to' => '2026-11-15',
        ])));

        self::assertIsArray($data['buckets']);
        self::assertCount(7, $data['buckets']);
        self::assertIsArray($data['total']);
        self::assertSame(8, $data['total']['value']);
        self::assertSame(1, $data['pending'], 'the raw row that waits for the rollup');
    }

    public function testTrendWithInvalidParameterIsABadRequest(): void
    {
        $response = $this->controller()
            ->trend(self::fromPage('/trend', 'GET', [
                'range' => 'hours',
            ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'error' => 'invalid',
            'message' => '"range" must be one of 7d | 30d | 3m | ytd | 1y | 3y | custom, "hours" given.',
        ], self::data($response));
    }

    public function testBreakdownOfARangeHidesValuesBelowTheThreshold(): void
    {
        $this->pipeline->project->configure([
            'privacy' => [
                'report_threshold_k' => 3,
            ],
        ]);
        $this->pipeline->clients('2026-11-10', 10, 'mobile', 'safari', 'ios', entry: true);
        $this->pipeline->clients('2026-11-11', 2, 'tablet', 'firefox', 'linux');

        $response = $this->controller()
            ->breakdown(self::fromPage('/breakdown', 'GET', [
                'from' => '2026-11-09',
                'to' => '2026-11-15',
            ]));
        $data = self::data($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        // Up to today, compared with as many days before.
        self::assertSame(['pageviews', '2026-11-09', '2026-11-13', '2026-11-04', '2026-11-08', 12], [$data['metric'], $data['from'], $data['to'], $data['previousFrom'], $data['previousTo'], $data['total']]);
        self::assertIsArray($data['dimensions']);
        self::assertSame([
            [
                'key' => 'mobile',
                'value' => 10,
                'previous' => 0,
            ],
            [
                'key' => 'other',
                'value' => 2,
                'previous' => 0,
            ],
        ], $data['dimensions']['device']);

        $uncompared = self::data($this->controller()->breakdown(self::fromPage('/breakdown', 'GET', [
            'from' => '2026-11-09',
            'to' => '2026-11-15',
            'compare' => 'none',
        ])));
        self::assertNull($uncompared['previousTotal']);

        $entries = self::data($this->controller()->breakdown(self::fromPage('/breakdown', 'GET', [
            'from' => '2026-11-09',
            'to' => '2026-11-15',
            'metric' => 'entries',
        ])));
        self::assertSame(10, $entries['total']);
    }

    public function testPageFlowOfAPage(): void
    {
        // At least `report_threshold_k` (5 by default) to be named.
        $this->pipeline->transitions('2026-11-10', '/', '/nl/page/about', 6);
        $this->pipeline->transitions('2026-11-10', '/page/about', '/contact', 3);

        $data = self::data($this->controller()->pageFlow(self::fromPage('/page-flow', 'GET', [
            'from' => '2026-11-09',
            'to' => '2026-11-15',
            'path' => '/page/about',
        ])));

        self::assertSame(['/page/about', 'About us'], [$data['path'], $data['title']]);
        self::assertSame([
            [
                'key' => '/',
                'title' => null,
                'hits' => 6,
            ],
        ], $data['cameFrom']);
        self::assertSame(3, $data['wentToOther']);

        $missing = $this->controller()->pageFlow(self::fromPage('/page-flow', 'GET', [
            'from' => '2026-11-09',
            'to' => '2026-11-15',
        ]));
        self::assertSame(400, $missing->getStatusCode());
    }

    public function testBotsAndHoursOfARange(): void
    {
        $this->pipeline->bots('2026-11-10', 'gptbot', 7);
        $this->pipeline->hourly('2026-11-10', 9, 5);

        $bots = self::data($this->controller()->bots(self::fromPage('/bots', 'GET', [
            'from' => '2026-11-09',
            'to' => '2026-11-15',
        ])));
        $hours = self::data($this->controller()->hours(self::fromPage('/hours', 'GET', [
            'from' => '2026-11-09',
            'to' => '2026-11-15',
        ])));

        self::assertSame([7, 0], [$bots['requests'], $bots['previousRequests']]);
        self::assertSame([5, 5], [$hours['total'], $hours['max']]);
    }

    public function testPagesOfARange(): void
    {
        $this->pipeline->pages('2026-11-10', '/page/about', 4);
        $this->pipeline->pages('2026-11-10', '/nl/page/about', 2);
        $this->pipeline->pages('2026-11-10', '/', 1);

        $response = $this->controller()
            ->pages(self::fromPage('/pages', 'GET', [
                'from' => '2026-11-09',
                'to' => '2026-11-15',
            ]));
        $data = self::data($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame(['pageviews', 'most', 1, 1, 2], [$data['metric'], $data['order'], $data['page'], $data['pages'], $data['total']]);
        self::assertIsArray($data['rows']);
        self::assertSame([
            'key' => '/page/about',
            'title' => 'About us',
            'views' => 6,
            'previous' => 0,
            'locales' => [
                'en' => 4,
                'nl' => 2,
            ],
        ], $data['rows'][0]);

        $least = self::data($this->controller()->pages(self::fromPage('/pages', 'GET', [
            'from' => '2026-11-09',
            'to' => '2026-11-15',
            'order' => 'least',
            'q' => 'about',
            'page' => '1',
        ])));
        self::assertSame(['least', 'about', 1], [$least['order'], $least['search'], $least['total']]);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidPagesRequests(): iterable
    {
        yield 'no range' => [[], '"from" and "to" are required.'];
        yield 'page' => [[
            'from' => '2026-11-09',
            'to' => '2026-11-15',
            'page' => '0',
        ], '"page" must be a number from 1, "0" given.'];
        yield 'order' => [[
            'from' => '2026-11-09',
            'to' => '2026-11-15',
            'order' => 'best',
        ], '"order" must be one of most | least, "best" given.'];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('invalidPagesRequests')]
    public function testInvalidPagesRequestIsABadRequest(array $query, string $message): void
    {
        $response = $this->controller()
            ->pages(self::fromPage('/pages', 'GET', $query));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'error' => 'invalid',
            'message' => $message,
        ], self::data($response));
    }

    /**
     * Sources, campaigns and aliases use the threshold and the aliases of the
     * config: a configured alias nobody used yet is there with 0.
     */
    public function testSourcesCampaignsAndAliases(): void
    {
        $this->pipeline->project->configure([
            'aliases' => [
                'hosts' => ['rockfestkurim.cz', 'poster-a2'],
                'target' => 'https://rockfest-kurim.cz',
            ],
            'privacy' => [
                'report_threshold_k' => 3,
            ],
        ]);
        $this->pipeline->sources('2026-11-10', '/', 9, 'email', 'newsletter', 'autumn');
        $this->pipeline->sources('2026-11-10', '/', 2, 'referral', 'tiny.example');
        $this->pipeline->aliases('2026-11-10', 'rockfestkurim.cz', '/', 4);
        $range = [
            'from' => '2026-11-09',
            'to' => '2026-11-15',
        ];

        $sources = self::data($this->controller()->sources(self::fromPage('/sources', 'GET', $range + [
            'channel' => 'referral',
        ])));
        $campaigns = self::data($this->controller()->campaigns(self::fromPage('/campaigns', 'GET', $range)));
        $aliases = self::data($this->controller()->aliases(self::fromPage('/aliases', 'GET', $range)));

        self::assertIsArray($sources['rows']);
        self::assertSame([11, 'referral', 1, 1], [$sources['visits'], $sources['channel'], $sources['campaigns'], $sources['aliases']]);
        self::assertSame([SourcesReport::OTHER], array_column($sources['rows'], 'source'), 'below the threshold of 3');
        self::assertIsArray($campaigns['rows']);
        self::assertSame([['autumn', 9]], array_map(static fn (mixed $row): array => is_array($row) ? [$row['campaign'] ?? null, $row['visits'] ?? null] : [], $campaigns['rows']));
        self::assertIsArray($aliases['rows']);
        self::assertSame(['rockfestkurim.cz', 'poster-a2'], array_column($aliases['rows'], 'via'));
    }

    public function testSummaryOfARange(): void
    {
        $this->pipeline->daily([
            '2026-11-10' => [8, 3],
            '2026-11-05' => [4, 2],
        ]);

        $data = self::data($this->controller()->summary(self::fromPage('/summary', 'GET', [
            'from' => '2026-11-09',
            'to' => '2026-11-15',
        ])));

        self::assertSame(['2026-11-13', '2026-11-04'], [$data['to'], $data['previousFrom']]);
        self::assertIsArray($data['current']);
        self::assertIsArray($data['previous']);
        self::assertSame([8, 3, 4, 2], [$data['current']['pageviews'], $data['current']['visits'], $data['previous']['pageviews'], $data['previous']['visits']]);

        $none = self::data($this->controller()->summary(self::fromPage('/summary', 'GET', [
            'from' => '2026-11-09',
            'to' => '2026-11-15',
            'compare' => 'none',
        ])));
        self::assertNull($none['previous']);

        $explicit = self::data($this->controller()->summary(self::fromPage('/summary', 'GET', [
            'from' => '2026-11-09',
            'to' => '2026-11-15',
            'compareFrom' => '2026-11-05',
            'compareTo' => '2026-11-05',
        ])));
        self::assertSame(['2026-11-05', '2026-11-05'], [$explicit['previousFrom'], $explicit['previousTo']]);
        self::assertIsArray($explicit['previous']);
        self::assertSame(4, $explicit['previous']['pageviews']);
    }

    public function testBrokenLinksAndSearchesUseTheThreshold(): void
    {
        $this->pipeline->project->configure([
            'privacy' => [
                'report_threshold_k' => 3,
            ],
        ]);
        $this->pipeline->notFound('2026-11-10', '/old', 5, 'example.org');
        $this->pipeline->notFound('2026-11-10', '/typed-once', 1);
        $this->pipeline->search('2026-11-10', 'program', 4);
        $this->pipeline->search('2026-11-10', 'rare', 1);
        $range = [
            'from' => '2026-11-09',
            'to' => '2026-11-15',
        ];

        $broken = self::data($this->controller()->brokenLinks(self::fromPage('/broken-links', 'GET', $range)));
        $searches = self::data($this->controller()->searches(self::fromPage('/searches', 'GET', $range + [
            'compare' => 'none',
        ])));

        self::assertIsArray($broken['rows']);
        self::assertSame([['/old'], 1, 6], [array_column($broken['rows'], 'path'), $broken['hidden'], $broken['requests']]);
        self::assertIsArray($searches['rows']);
        self::assertSame([['program'], 1, null], [array_column($searches['rows'], 'term'), $searches['hidden'], $searches['previousFrom']]);
    }

    public function testSourcesNeedARange(): void
    {
        $request = self::fromPage('/sources', 'GET', [
            'from' => '2026-11-09',
        ]);
        $controller = $this->controller();

        foreach ([$controller->sources($request), $controller->campaigns($request), $controller->aliases($request)] as $response) {
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('"from" and "to" are required.', self::data($response)['message'] ?? null);
        }
    }

    public function testBreakdownNeedsARange(): void
    {
        $response = $this->controller()
            ->breakdown(self::fromPage('/breakdown', 'GET', [
                'from' => '2026-11-09',
            ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'error' => 'invalid',
            'message' => '"from" and "to" are required.',
        ], self::data($response));
    }

    public function testArrayParametersAreIgnored(): void
    {
        $data = self::data($this->controller()->trend(self::fromPage('/trend?range[]=7d&interval[]=x')));

        self::assertSame(['30d', 'day'], [$data['range'], $data['interval']]);
    }

    public function testRollupAggregatesAndReportsTheResult(): void
    {
        $this->pipeline->rawHitWriter()
            ->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', isEntry: true));
        $controller = $this->controller();

        $response = $controller->rollup(self::post([
            ApiController::CSRF_HEADER => self::TOKEN,
        ]));
        $data = self::data($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['processed', 'pending', 'durationMs', 'busy'], array_keys($data));
        self::assertSame([1, 0, false], [$data['processed'], $data['pending'], $data['busy']]);

        $trend = self::data($controller->trend(self::fromPage('/trend?range=7d&metric=entries')));
        self::assertIsArray($trend['total']);
        self::assertSame(1, $trend['total']['value']);
        self::assertSame('2026-11-13T10:52:00+01:00', $trend['aggregatedUntil']);
    }

    /**
     * `trigger: cron`: only cron aggregates, also when a viewer posts to the
     * endpoint by hand.
     */
    public function testRollupLeavesTheWorkToCronWhenConfigured(): void
    {
        $this->pipeline->project->configure([
            'aggregation' => [
                'trigger' => 'cron',
            ],
        ]);
        $this->pipeline->rawHitWriter()
            ->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', isEntry: true));

        $data = self::data($this->controller()->rollup(self::post([
            ApiController::CSRF_HEADER => self::TOKEN,
        ])));

        self::assertSame([0, 1, false], [$data['processed'], $data['pending'], $data['busy']]);
        self::assertSame(1, $this->pipeline->database->count($this->pipeline->database->tables->hit()), 'nothing aggregated');
    }

    public function testRollupAcceptsTheTokenAsAFormField(): void
    {
        $request = Request::create('/rollup', 'POST', [
            '_csrf_token' => self::TOKEN,
        ]);

        self::assertSame(200, $this->controller()->rollup($request)->getStatusCode());
    }

    public function testRollupWithoutAValidTokenIsForbidden(): void
    {
        $this->pipeline->rawHitWriter()
            ->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/'));

        foreach ([self::post([]), self::post([
            ApiController::CSRF_HEADER => 'wrong',
        ])] as $request) {
            $response = $this->controller()
                ->rollup($request);

            self::assertSame(403, $response->getStatusCode());
            self::assertSame('csrf', self::data($response)['error']);
        }

        self::assertSame(1, $this->pipeline->database->count($this->pipeline->database->tables->hit()), 'nothing ran');
    }

    /**
     * Right after installing, the tables don't exist: the first request creates them.
     */
    public function testMissingTablesAreCreatedOnFirstUse(): void
    {
        $this->pipeline = new Pipeline($this->pipeline->project, new Database(false));

        $trend = $this->controller()
            ->trend(self::fromPage('/trend'));
        $rollup = $this->controller()
            ->rollup(self::post([
                ApiController::CSRF_HEADER => self::TOKEN,
            ]));

        self::assertSame(200, $trend->getStatusCode(), (string) $trend->getContent());
        self::assertSame(200, $rollup->getStatusCode(), (string) $rollup->getContent());
        self::assertSame(0, $this->pipeline->database->count($this->pipeline->database->tables->daily()));
    }

    public function testInvalidConfigIsReported(): void
    {
        $this->pipeline->project->configure([
            'widget' => [
                'default_range' => 'hours',
            ],
        ]);

        $response = $this->controller()
            ->trend(self::fromPage('/trend'));

        self::assertSame(400, $response->getStatusCode());
        self::assertIsString(self::data($response)['message']);
        self::assertStringContainsString('`widget.default_range` must be one of', self::data($response)['message']);
    }

    public function testOtherFailuresAreLoggedNotLeaked(): void
    {
        // A view where the table was: the extension can neither read it nor
        // repair it, on any platform (a table with other columns, a server
        // would let it repair by adding them).
        $connection = $this->pipeline->database->connection;
        $daily = $this->pipeline->database->tables->daily();
        $connection->executeStatement(sprintf('ALTER TABLE %s RENAME TO bolt_ra_daily_away', $daily));
        $connection->executeStatement(sprintf('CREATE VIEW %s AS SELECT 1 AS other', $daily));

        try {
            $response = $this->controller()
                ->trend(self::fromPage('/trend'));
        } finally {
            $connection->executeStatement(sprintf('DROP VIEW %s', $daily));
            $connection->executeStatement(sprintf('ALTER TABLE bolt_ra_daily_away RENAME TO %s', $daily));
        }

        self::assertSame(500, $response->getStatusCode());
        self::assertSame([
            'error' => 'failed',
            'message' => 'Request Analytics failed; see the log for details.',
        ], self::data($response));
        self::assertSame('error', $this->logger->records[0]['level'] ?? null);
    }

    /**
     * Messages for the administrator come in the backend's language.
     */
    public function testErrorsAreTranslated(): void
    {
        $czech = $this->controller('cs')
            ->rollup(self::post([]));
        self::assertSame('CSRF token není platný. Načtěte stránku znovu.', self::data($czech)['message']);

        $this->pipeline = new Pipeline($this->pipeline->project, new Database(false));
        $setup = self::data($this->controller('de', $this->databaseThatCanNotHelp())->trend(self::fromPage('/trend')));
        self::assertIsString($setup['message']);
        self::assertSame('Die Tabellen von Request Analytics fehlen. Führen Sie `bin/console request-analytics:install` aus.', $setup['message']);
    }

    /**
     * When creating the tables did not help (e.g. a table prefix that changed
     * meanwhile), the administrator is told how to create them.
     */
    public function testTablesThatAreStillMissingTellHowToSetUp(): void
    {
        $this->pipeline = new Pipeline($this->pipeline->project, new Database(false));

        $response = $this->controller('en', $this->databaseThatCanNotHelp())
            ->trend(self::fromPage('/trend'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame([
            'error' => 'setup',
            'message' => 'The Request Analytics tables are missing. Run `bin/console request-analytics:install`.',
        ], self::data($response));
    }

    /**
     * Creates its tables under another prefix than the one the queries use.
     */
    private function databaseThatCanNotHelp(): AnalyticsDatabase
    {
        return AnalyticsDatabase::fixed($this->pipeline->database->connection, 'other_', $this->pipeline->project->dir);
    }

    private function controller(string $locale = 'en', ?AnalyticsDatabase $database = null, ?string $user = null, int $eraserWaitMs = 0): ApiController
    {
        $csrf = self::createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')
            ->willReturnCallback(static fn (CsrfToken $token): bool => match ($token->getId()) {
                ApiController::CSRF_TOKEN_ID => in_array($token->getValue(), [self::TOKEN, self::ROLLUP_TOKEN], true),
                ApiController::ERASE_CSRF_TOKEN_ID => $token->getValue() === self::ERASE_TOKEN,
                default => false,
            });
        $tokenStorage = new TokenStorage();
        if ($user !== null) {
            $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser($user, null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        }

        return new ApiController(
            $this->pipeline->project->boltConfig(),
            $this->pipeline->project->configLoader(),
            $this->pipeline->trendQuery(),
            $this->pipeline->breakdownQuery(),
            $this->pipeline->pagesReport(new FakePageCatalog('en', ['en', 'nl'], [
                '/page/about' => 'About us',
            ])),
            $this->pipeline->sourcesReport(),
            $this->pipeline->summaryReport(),
            $this->pipeline->contentGapsReport(),
            $this->pipeline->pageFlowReport(new FakePageCatalog('en', ['en', 'nl'], [
                '/page/about' => 'About us',
            ])),
            $this->pipeline->trafficReport(),
            $this->pipeline->rollupService(),
            $database ?? $this->pipeline->analyticsDatabase(),
            $csrf,
            $this->logger,
            Translations::translator($locale),
            $this->pipeline->eraser($eraserWaitMs),
            $tokenStorage,
        );
    }

    /**
     * A request of the page's scripts: with the session's token in its header,
     * from the site itself.
     *
     * @param array<string, mixed> $parameters
     */
    private static function fromPage(string $uri, string $method = 'GET', array $parameters = []): Request
    {
        $request = Request::create($uri, $method, $parameters);
        $request->headers->set(ApiController::CSRF_HEADER, self::TOKEN);
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        return $request;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function erasePost(string $days, array $headers): Request
    {
        $request = Request::create('/bolt/extension/request-analytics/api/erase', 'POST', [
            'days' => $days,
        ]);
        $request->headers->add($headers);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function post(array $headers): Request
    {
        $request = Request::create('/bolt/extension/request-analytics/api/rollup', 'POST');
        $request->headers->add($headers);

        return $request;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function data(JsonResponse $response): array
    {
        $data = json_decode((string) $response->getContent(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}

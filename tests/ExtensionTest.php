<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests;

use Bolt\Configuration\Config;
use Bolt\Widget\Injector\QueueProcessor;
use Bolt\Widget\Injector\RequestZone;
use Bolt\Widgets;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Extension;
use TomVondracek\RequestAnalytics\Security\AnalyticsVoter;
use TomVondracek\RequestAnalytics\Tests\Support\Project;
use TomVondracek\RequestAnalytics\Tests\Support\Translations;
use TomVondracek\RequestAnalytics\Widget\TrendWidget;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ExtensionTest extends TestCase
{
    private bool $canView = true;
    private Project $project;
    private FilesystemLoader $loader;
    private Widgets $widgets;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->loader = new FilesystemLoader();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testName(): void
    {
        self::assertSame('Request Analytics', (new Extension())->getName());
        self::assertSame(Extension::TWIG_NAMESPACE, (new Extension())->getSlug());
    }

    /**
     * Bolt's ConfigTrait (used by `extensions:configure --with-config`) and
     * ConfigLoader must agree on the config filename, or the project's config
     * file is never read.
     */
    public function testConfigFilenameMatchesTheLoader(): void
    {
        $filenames = $this->extension(RequestZone::BACKEND)
            ->getConfigFilenames();

        self::assertIsString($filenames['main'] ?? null);
        self::assertIsString($filenames['local'] ?? null);
        self::assertSame(ConfigLoader::CONFIG_BASENAME . '.yaml', basename($filenames['main']));
        self::assertSame(ConfigLoader::CONFIG_BASENAME . '_local.yaml', basename($filenames['local']));
    }

    public function testBackendRequestGetsTheWidgetAndTheTwigNamespace(): void
    {
        $this->project->configure([
            'widget' => [
                'target' => 'dashboard_bottom',
            ],
        ]);

        $this->extension(RequestZone::BACKEND)
            ->initialize();

        self::assertContains(dirname(__DIR__) . '/templates', $this->loader->getPaths(Extension::TWIG_NAMESPACE));
        $widgets = $this->widgets->listWidgetsForTarget('dashboard_bottom');
        self::assertCount(1, $widgets);
        self::assertInstanceOf(TrendWidget::class, $widgets->first());
    }

    /**
     * initialize() runs before the response of every request: public pages
     * must not pay for the dashboard widget.
     */
    public function testFrontendRequestSetsUpNothing(): void
    {
        foreach ([RequestZone::FRONTEND, RequestZone::ASYNC, RequestZone::NOWHERE] as $zone) {
            $this->extension($zone)
                ->initialize();

            self::assertSame([], $this->loader->getNamespaces());
            self::assertCount(0, $this->widgets->listWidgetsForTarget('dashboard_aside_top'));
            self::assertFileDoesNotExist($this->project->dir . '/var/cache/request-analytics-config.php', 'the config was not even read');
        }
    }

    public function testDisabledExtensionHasNoWidget(): void
    {
        $this->project->configure([
            'enabled' => false,
        ]);

        $this->extension(RequestZone::BACKEND)
            ->initialize();

        self::assertCount(0, $this->widgets->listWidgetsForTarget('dashboard_aside_top'));
    }

    public function testInvalidConfigShowsUpOnTheDashboard(): void
    {
        $this->project->configure([
            'enabled' => 'maybe',
        ]);

        $this->extension(RequestZone::BACKEND)
            ->initialize();

        $html = $this->widgets->renderWidgetsForTarget('dashboard_aside_top');
        self::assertStringContainsString('The Request Analytics `enabled` must be true or false', $html);
    }

    /**
     * Who may not see the statistics gets no card: not even what is wrong
     * with the config, which names files of the server.
     */
    public function testUsersWhoMayNotSeeTheStatisticsGetNoCard(): void
    {
        $this->canView = false;

        $this->extension(RequestZone::BACKEND)
            ->initialize();
        self::assertCount(0, $this->widgets->listWidgetsForTarget('dashboard_aside_top'));

        $this->project->configure([
            'enabled' => 'maybe',
        ]);
        $this->extension(RequestZone::BACKEND)
            ->initialize();
        self::assertSame('', mb_trim($this->widgets->renderWidgetsForTarget('dashboard_aside_top')));
    }

    public function testWithoutARequestNothingHappens(): void
    {
        $this->extension(null)
            ->initialize();

        self::assertCount(0, $this->widgets->listWidgetsForTarget('dashboard_aside_top'));
    }

    private function extension(?string $zone): Extension
    {
        $requests = new RequestStack();
        if ($zone !== null) {
            $request = Request::create('https://example.com/bolt/');
            RequestZone::setToRequest($request, $zone);
            $requests->push($request);
        }

        $twig = new Environment($this->loader);
        // Bolt has it from Symfony's Twig bridge.
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'token-for-' . $id));
        $twig->addExtension(new TranslationExtension(Translations::translator()));
        $this->widgets = new Widgets($requests, self::createStub(QueueProcessor::class), $twig, new ArrayAdapter(), new Stopwatch());

        $container = new Container(new ParameterBag([
            'kernel.cache_dir' => $this->project->dir . '/var/cache',
            'kernel.project_dir' => $this->project->dir,
        ]));
        $container->set('twig', $twig);
        $container->set('request_stack', $requests);
        $container->set('router', new UrlGenerator(new RouteCollection(), new RequestContext()));
        $container->set(Widgets::class, $this->widgets);
        $container->set(Config::class, $this->project->boltConfig());
        $checker = self::createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')
            ->willReturnCallback(fn (mixed $attribute): bool => $this->canView && $attribute === AnalyticsVoter::VIEW);
        $container->set('security.authorization_checker', $checker);

        $extension = new Extension();
        $extension->injectObjects([
            'manager' => null,
            'query' => null,
            'container' => $container,
        ]);

        return $extension;
    }
}

<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests;

use Bolt\Configuration\Config;
use Bolt\Repository\ContentRepository;
use Bolt\Twig\ContentExtension;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Extension\Extension as ContainerExtension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\ServiceValueResolver;
use Symfony\Component\HttpKernel\DependencyInjection\RegisterControllerArgumentLocatorsPass;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use TomVondracek\RequestAnalytics\Aggregation\RollupService;
use TomVondracek\RequestAnalytics\Aggregation\RollupState;
use TomVondracek\RequestAnalytics\Aggregation\SpoolIngestor;
use TomVondracek\RequestAnalytics\Aggregation\StatisticsEraser;
use TomVondracek\RequestAnalytics\Collector\AliasListener;
use TomVondracek\RequestAnalytics\Collector\CollectListener;
use TomVondracek\RequestAnalytics\Collector\HitClassifier;
use TomVondracek\RequestAnalytics\Command\InstallCommand;
use TomVondracek\RequestAnalytics\Command\RollupCommand;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Controller\AnalyticsController;
use TomVondracek\RequestAnalytics\Controller\ApiController;
use TomVondracek\RequestAnalytics\Diagnostics\FailureReporter;
use TomVondracek\RequestAnalytics\Extension;
use TomVondracek\RequestAnalytics\Insight\DatabaseInsightData;
use TomVondracek\RequestAnalytics\Insight\InsightFormatter;
use TomVondracek\RequestAnalytics\Insight\InsightsReport;
use TomVondracek\RequestAnalytics\Insight\Rule\BrokenLinkRule;
use TomVondracek\RequestAnalytics\Insight\Rule\BrowserRegressionRule;
use TomVondracek\RequestAnalytics\Insight\Rule\DataTrustRule;
use TomVondracek\RequestAnalytics\Insight\Rule\MissingLanguageRule;
use TomVondracek\RequestAnalytics\Insight\Rule\SearchGapRule;
use TomVondracek\RequestAnalytics\Insight\Rule\UnreachedTranslationRule;
use TomVondracek\RequestAnalytics\Insight\Rule\UnusedTranslationRule;
use TomVondracek\RequestAnalytics\Insight\Rule\VisitDropRule;
use TomVondracek\RequestAnalytics\Menu\AnalyticsMenu;
use TomVondracek\RequestAnalytics\Query\BreakdownQuery;
use TomVondracek\RequestAnalytics\Query\TrendQuery;
use TomVondracek\RequestAnalytics\Report\BoltPageCatalog;
use TomVondracek\RequestAnalytics\Report\ContentGapsReport;
use TomVondracek\RequestAnalytics\Report\PageFlowReport;
use TomVondracek\RequestAnalytics\Report\PagesReport;
use TomVondracek\RequestAnalytics\Report\SourcesReport;
use TomVondracek\RequestAnalytics\Report\SummaryReport;
use TomVondracek\RequestAnalytics\Report\TrafficReport;
use TomVondracek\RequestAnalytics\Security\AnalyticsVoter;
use TomVondracek\RequestAnalytics\SiteTime;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Tests\Support\Project;
use TomVondracek\RequestAnalytics\Writer\SpoolHitWriter;
use Twig\Environment;

/**
 * Guards the registrations: what Bolt's generated config/services_bolt.yaml does
 * with the extension's classes, and config/services.yaml and config/routes.yaml,
 * which `extensions:configure` copies into the project.
 */
final class ConfigServicesTest extends TestCase
{
    private const VENDOR_PATH = '%kernel.project_dir%/vendor/tomvondracek/bolt-request-analytics';

    /**
     * Services the kernel uses, which therefore have to compile.
     */
    private const ENTRY_POINTS = [BoltPageCatalog::class, TrendQuery::class, BreakdownQuery::class, RollupState::class, SpoolIngestor::class, SpoolHitWriter::class, InstallCommand::class, CollectListener::class, AliasListener::class, ApiController::class, AnalyticsController::class, RollupCommand::class, AnalyticsMenu::class, AnalyticsVoter::class, ConfigLoader::class];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/request-analytics-services-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    /**
     * The tables are only ever in the extension's own SQLite file, so nothing
     * of Bolt's Doctrine setup (connection, entity mapping, migrations) is
     * configured or touched.
     */
    public function testBoltsDoctrineConfigIsLeftAlone(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__) . '/config/services.yaml');
        self::assertIsArray($config);
        self::assertArrayNotHasKey('doctrine', $config);
    }

    /**
     * No service of the extension gets Bolt's database connection or entity
     * manager: the tables are read and written through AnalyticsDatabase,
     * which only opens the extension's own database, and asks Bolt's
     * connection for its parameters alone.
     */
    public function testNoServiceGetsBoltsDatabase(): void
    {
        $container = $this->compile(true);
        $checked = 0;
        foreach ($container->getDefinitions() as $id => $definition) {
            if (! str_starts_with((string) $definition->getClass(), 'TomVondracek\\RequestAnalytics\\')) {
                continue;
            }

            ++$checked;
            foreach ([Connection::class, 'closure of ' . Connection::class, EntityManagerInterface::class] as $bolts) {
                if ($id !== AnalyticsDatabase::class) {
                    self::assertNotContains($bolts, self::arguments($container, $id), $id);
                }
            }
        }

        self::assertGreaterThan(30, $checked);
        self::assertSame(
            [ConfigLoader::class, dirname(__DIR__), 'closure of ' . Connection::class],
            self::arguments($container, AnalyticsDatabase::class),
            "only to refuse Bolt's database as the storage, by the parameters of its connection"
        );
    }

    /**
     * DoctrineBundle adds every service that is a DBAL driver middleware to
     * Bolt's own connection; Bolt registers every class of the extension as a
     * service. So no class may be one: the pragmas of the analytics file
     * (WAL, relaxed fsync) must never reach Bolt's database.
     */
    public function testNoServiceIsADriverMiddlewareForBoltsConnection(): void
    {
        // What services_bolt.yaml registers: every class below src/ but Entity.
        $middlewares = [];
        $src = dirname(__DIR__) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            $relative = mb_substr($file->getPathname(), mb_strlen($src) + 1, -4);
            if ($file->getExtension() !== 'php' || str_starts_with($relative, 'Entity/')) {
                continue;
            }

            $class = 'TomVondracek\\RequestAnalytics\\' . str_replace('/', '\\', $relative);
            if (class_exists($class) && is_subclass_of($class, Middleware::class)) {
                $middlewares[] = $class;
            }
        }

        self::assertSame([], $middlewares);
    }

    /**
     * The services that read and write the tables get the connection of
     * AnalyticsDatabase, through an expression: it must evaluate in a real container.
     */
    public function testQueriesUseTheAnalyticsConnection(): void
    {
        $container = $this->compile(true);
        foreach ([TrendQuery::class, BreakdownQuery::class, RollupState::class, SpoolIngestor::class, RollupService::class] as $service) {
            self::assertContains('expression ' . AnalyticsDatabase::CONNECTION, self::arguments($container, $service), $service);
        }

        $project = new Project();
        try {
            $project->configure([
                'storage' => [
                    'database' => $project->dir . '/var/data/analytics.db',
                ],
            ]);
            // As the kernel runs it: dumped to PHP.
            $class = 'RequestAnalyticsTestContainer' . bin2hex(random_bytes(4));
            $code = (new PhpDumper($container))->dump([
                'class' => $class,
            ]);
            self::assertIsString($code);
            eval('?>' . $code);
            $dumped = new $class();
            self::assertInstanceOf(Container::class, $dumped);

            foreach ([$container, $dumped] as $built) {
                $built->set(ContainerInterface::class, new Container());
                $built->set(Config::class, $project->boltConfig());
                $built->set(Connection::class, DriverManager::getConnection([
                    'driver' => 'pdo_sqlite',
                    'memory' => true,
                ]));
                $built->set(EntityManagerInterface::class, self::createStub(EntityManagerInterface::class));
                $built->set(ClockInterface::class, new MockClock());

                $query = $built->get(TrendQuery::class);
                self::assertInstanceOf(TrendQuery::class, $query);
                $connection = (new ReflectionProperty(TrendQuery::class, 'connection'))->getValue($query);
                self::assertInstanceOf(Connection::class, $connection);
                self::assertSame($project->dir . '/var/data/analytics.db', $connection->getParams()['path'] ?? null);
            }
        } finally {
            $project->remove();
        }
    }

    public function testTwigNamespaceIsRegisteredForTheShippedTemplates(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__) . '/config/services.yaml');
        self::assertIsArray($config);

        self::assertSame([
            'paths' => [
                self::VENDOR_PATH . '/templates' => Extension::TWIG_NAMESPACE,
            ],
        ], $config['twig'] ?? null);
        self::assertFileExists(dirname(__DIR__) . '/templates/widget/trend.html.twig');
        self::assertFileExists(dirname(__DIR__) . '/templates/page/analytics.html.twig');
    }

    public function testTranslationsAreRegistered(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__) . '/config/services.yaml');
        self::assertIsArray($config);

        self::assertSame([
            'translator' => [
                'paths' => [self::VENDOR_PATH . '/translations'],
            ],
        ], $config['framework'] ?? null);
        self::assertFileExists(dirname(__DIR__) . '/translations/' . Extension::TRANSLATION_DOMAIN . '.en.yaml');
    }

    public function testRoutesLoadTheControllerDirectory(): void
    {
        $routes = Yaml::parseFile(dirname(__DIR__) . '/config/routes.yaml');

        self::assertSame([
            'request_analytics' => [
                'resource' => '../../vendor/tomvondracek/bolt-request-analytics/src/Controller/',
                'type' => 'attribute',
            ],
        ], $routes);
    }

    public function testPackageNameMatchesThePathsInTheConfig(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['extra'] ?? null);

        self::assertSame('tomvondracek/bolt-request-analytics', $composer['name'] ?? null);
        self::assertSame('bolt-extension', $composer['type'] ?? null);
        self::assertSame(Extension::class, $composer['extra']['entrypoint'] ?? null);
    }

    /**
     * Right after `composer require`, Bolt has added the extension to
     * config/services_bolt.yaml, but `extensions:configure` (which needs a working
     * container itself) has not copied config/services.yaml yet.
     */
    public function testContainerCompilesBeforeExtensionsConfigure(): void
    {
        $this->assertServicesAreAutowired($this->compile(false));
    }

    public function testContainerCompilesAfterExtensionsConfigure(): void
    {
        $this->assertServicesAreAutowired($this->compile(true));
    }

    private function compile(bool $configured): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', dirname(__DIR__));
        $container->setParameter('kernel.cache_dir', $this->dir);
        $container->setParameter('kernel.secret', 'app-secret');
        $container->setParameter('bolt.backend_url', '/bolt');
        $container->setParameter('kernel.environment', 'prod');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.default_locale', 'en');
        $container->setParameter('locales_array', ['en', 'nl']);
        $container->setParameter('bolt.public_folder', 'public_html');

        // Services of FrameworkBundle, SecurityBundle, DoctrineBundle and Bolt.
        foreach ([ContainerInterface::class, Config::class, Connection::class, EntityManagerInterface::class, ClockInterface::class, LoggerInterface::class, UrlGeneratorInterface::class, RequestStack::class, CsrfTokenManagerInterface::class, TranslatorInterface::class, AuthorizationCheckerInterface::class, AccessDecisionManagerInterface::class, TokenStorageInterface::class, Environment::class, ContentRepository::class, ContentExtension::class, CacheInterface::class] as $service) {
            $container->register($service)
                ->setSynthetic(true);
        }
        // SecurityBundle tags every voter.
        $container->registerForAutoconfiguration(VoterInterface::class)
            ->addTag('security.voter');
        $container->registerForAutoconfiguration(AbstractController::class)
            ->addTag('controller.service_arguments')
            ->addTag('container.service_subscriber');
        $container->register('argument_resolver.service', ServiceValueResolver::class)
            ->addArgument(null);
        $container->addCompilerPass(new RegisterControllerArgumentLocatorsPass());

        // What Bolt's ExtensionCompilerPass writes to config/services_bolt.yaml.
        $src = dirname(__DIR__) . '/src';
        file_put_contents($this->dir . '/services_bolt.yaml', Yaml::dump([
            'services' => [
                '_defaults' => [
                    'autowire' => true,
                    'autoconfigure' => true,
                    'bind' => [],
                ],
                'TomVondracek\RequestAnalytics\\' => [
                    'resource' => $src . '/*',
                    'exclude' => $src . '/{Entity,Exception}',
                ],
            ],
        ], 4, 2));
        (new YamlFileLoader($container, new FileLocator($this->dir)))->load('services_bolt.yaml');

        if ($configured) {
            // config/services.yaml configures Doctrine, the translator and Twig, which aren't part of this container.
            foreach (['doctrine', 'framework', 'twig'] as $alias) {
                $container->registerExtension(new class($alias) extends ContainerExtension {
                    public function __construct(
                        private readonly string $alias,
                    ) {
                    }

                    public function load(array $configs, ContainerBuilder $container): void
                    {
                    }

                    public function getAlias(): string
                    {
                        return $this->alias;
                    }
                });
            }
            (new YamlFileLoader($container, new FileLocator(dirname(__DIR__) . '/config')))->load('services.yaml');
        }

        // Listeners, controllers, commands and Twig extensions are referenced by the
        // kernel in a project; public here, so errors in them surface when compiling.
        foreach (self::ENTRY_POINTS as $service) {
            $container->getDefinition($service)
                ->setPublic(true);
        }
        $container->compile(true);

        return $container;
    }

    /**
     * Bolt builds its backend menu from the services with this tag; the
     * project's `_instanceof` rule for the interface only covers its own
     * services, so the extension tags the item itself.
     */
    public function testMenuItemIsTaggedForTheBackendMenu(): void
    {
        self::assertTrue($this->compile(true)->getDefinition(AnalyticsMenu::class)->hasTag('bolt.extension_backend_menu'));
    }

    private function assertServicesAreAutowired(ContainerBuilder $container): void
    {
        self::assertSame(
            [HitClassifier::class, SpoolHitWriter::class, FailureReporter::class, '/bolt', 'prod', 'bool'],
            self::arguments($container, CollectListener::class),
            'the collector only appends to the spool, knows the backend URL and whether the site is in development'
        );
        self::assertSame(
            [ConfigLoader::class, FailureReporter::class],
            self::arguments($container, AliasListener::class),
            'the alias listener runs before every response: it classifies nothing'
        );
        self::assertSame(
            [Config::class, ConfigLoader::class, TrendQuery::class, BreakdownQuery::class, PagesReport::class, SourcesReport::class, SummaryReport::class, ContentGapsReport::class, PageFlowReport::class, TrafficReport::class, RollupService::class, AnalyticsDatabase::class, CsrfTokenManagerInterface::class, LoggerInterface::class, TranslatorInterface::class, StatisticsEraser::class, TokenStorageInterface::class],
            self::arguments($container, ApiController::class)
        );
        self::assertSame(
            [Config::class, ConfigLoader::class, UrlGeneratorInterface::class, Environment::class, BoltPageCatalog::class, InsightsReport::class, InsightFormatter::class, AuthorizationCheckerInterface::class, SiteTime::class],
            self::arguments($container, AnalyticsController::class)
        );
        // The analytics page and the menu item (its warning icon) share it.
        self::assertSame(
            ['iterator of ' . implode(', ', [BrokenLinkRule::class, BrowserRegressionRule::class, DataTrustRule::class, MissingLanguageRule::class, SearchGapRule::class, UnreachedTranslationRule::class, UnusedTranslationRule::class, VisitDropRule::class]), DatabaseInsightData::class, BoltPageCatalog::class, ConfigLoader::class, SiteTime::class, CacheInterface::class, LoggerInterface::class],
            self::arguments($container, InsightsReport::class),
            'every rule, found by its interface; the aggregates from the tables'
        );
        self::assertSame(
            [UrlGeneratorInterface::class, TranslatorInterface::class, AuthorizationCheckerInterface::class, ConfigLoader::class, RequestStack::class, InsightsReport::class],
            self::arguments($container, AnalyticsMenu::class)
        );
        self::assertSame(
            [ConfigLoader::class, AccessDecisionManagerInterface::class],
            self::arguments($container, AnalyticsVoter::class),
            'who may see and delete the statistics comes from the config'
        );
        self::assertTrue($container->getDefinition(AnalyticsVoter::class)->hasTag('security.voter'), 'Symfony asks it');

        self::assertSame(
            [Config::class, ContentRepository::class, ContentExtension::class, CacheInterface::class, LoggerInterface::class, 'en', ['en', 'nl']],
            array_map(static fn (mixed $argument): mixed => $argument instanceof Reference ? (string) $argument : $argument, array_values($container->getDefinition(BoltPageCatalog::class)->getArguments())),
            'the pages of the site come from Bolt; the languages from its parameters'
        );

        // Scalars come from kernel parameters, not from binds of the project;
        // Bolt's public folder, which no file of the statistics may be in.
        $loader = $container->getDefinition(ConfigLoader::class);
        self::assertSame([$this->dir, dirname(__DIR__), 'public_html'], array_slice($loader->getArguments(), 1));
        self::assertTrue($container->hasDefinition(Extension::class) === false || $container->getDefinition(Extension::class)->getArguments() === []);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @param array<array-key, mixed>|null $values arguments to describe instead of those of the service
     *
     * @return list<string>
     */
    private static function arguments(ContainerBuilder $container, string $service, ?array $values = null): array
    {
        // Services used once are inlined as their definition.
        return array_values(array_map(static fn (mixed $argument): string => match (true) {
            $argument instanceof Reference => (string) $argument,
            $argument instanceof Definition => (string) $argument->getClass(),
            $argument instanceof Expression => 'expression ' . $argument,
            $argument instanceof ServiceClosureArgument => 'closure of ' . self::arguments(new ContainerBuilder(), '', $argument->getValues())[0],
            $argument instanceof IteratorArgument => 'iterator of ' . implode(', ', self::sorted(self::arguments(new ContainerBuilder(), '', $argument->getValues()))),
            is_string($argument) => $argument,
            default => get_debug_type($argument),
        }, $values ?? $container->getDefinition($service)->getArguments()));
    }
}

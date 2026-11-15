<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics;

use Bolt\Extension\BaseExtension;
use Bolt\Widget\Injector\RequestZone;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;
use TomVondracek\RequestAnalytics\Security\AnalyticsVoter;
use TomVondracek\RequestAnalytics\Widget\TrendWidget;

final class Extension extends BaseExtension
{
    public const TWIG_NAMESPACE = 'request-analytics';

    /**
     * Translation domain of the extension's texts (translations/request_analytics.<locale>.yaml).
     */
    public const TRANSLATION_DOMAIN = 'request_analytics';

    public function getName(): string
    {
        return 'Request Analytics';
    }

    /**
     * Runs on every request, before the response. The collector and the alias
     * redirect are listeners of their own; the only thing to set up here is the
     * dashboard widget, so public requests leave right away.
     */
    public function initialize(): void
    {
        if (! $this->isBackendRequest()) {
            return;
        }

        // Runs on kernel.controller, after the firewall: the user is known.
        // Who may not see the statistics gets no card, not even a config error.
        if (! $this->canView()) {
            return;
        }

        // The namespace is also registered at container level, in the
        // extension's config/services.yaml. This runtime registration is the
        // fallback for projects that have not run `extensions:configure` yet.
        $this->addTwigNamespace(self::TWIG_NAMESPACE);

        try {
            $config = $this->getRequestAnalyticsConfig();
        } catch (Throwable $exception) {
            // An administrator should see what is wrong with the config.
            $this->addWidget(new TrendWidget(configError: $exception->getMessage()));

            return;
        }

        if ($config->enabled) {
            $router = $this->getService('router');
            $this->addWidget(new TrendWidget($config, $router instanceof UrlGeneratorInterface ? $router : null));
        }
    }

    public function getRequestAnalyticsConfig(): RequestAnalyticsConfig
    {
        $container = $this->getContainer();
        $cacheDir = $container->getParameter('kernel.cache_dir');
        $projectDir = $container->getParameter('kernel.project_dir');
        $publicFolder = $container->hasParameter('bolt.public_folder') ? $container->getParameter('bolt.public_folder') : null;

        return (new ConfigLoader(
            $this->getBoltConfig(),
            is_string($cacheDir) ? $cacheDir : sys_get_temp_dir(),
            is_string($projectDir) ? $projectDir : '',
            is_string($publicFolder) ? $publicFolder : RequestAnalyticsConfig::PUBLIC_FOLDER_DEFAULT,
        ))->load();
    }

    /**
     * Without the authorization checker (a broken container) nobody does.
     */
    private function canView(): bool
    {
        try {
            $checker = $this->getService('security.authorization_checker');

            return $checker instanceof AuthorizationCheckerInterface && $checker->isGranted(AnalyticsVoter::VIEW);
        } catch (Throwable) {
            return false;
        }
    }

    private function isBackendRequest(): bool
    {
        try {
            return RequestZone::isForBackend($this->getRequest());
        } catch (Throwable) {
            return false;
        }
    }
}

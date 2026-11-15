<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Menu;

use Bolt\Menu\ExtensionBackendMenuInterface;
use Knp\Menu\MenuItem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Controller\AnalyticsController;
use TomVondracek\RequestAnalytics\Extension;
use TomVondracek\RequestAnalytics\Insight\InsightsReport;
use TomVondracek\RequestAnalytics\Security\AnalyticsVoter;

/**
 * The "Analytics" item of the backend menu, leading to the analytics page.
 * Its icon turns into a warning while several insights ask for a fix, so it
 * shows on every backend page, not only on the analytics page.
 *
 * Bolt renders only the name and the icon of an item; `icon` goes into the
 * class of the icon, so it can carry a colour too. Bolt may cache the menu
 * (`general/caching/backend_menu`, off by default): the icon then follows
 * the insights that much later.
 *
 * Bolt collects these through the `bolt.extension_backend_menu` tag, which the
 * extension's config/services.yaml adds.
 */
final readonly class AnalyticsMenu implements ExtensionBackendMenuInterface
{
    public const NAME = 'Request Analytics';
    public const ICON = 'fa-chart-pie';
    public const ALERT_ICON = 'fa-exclamation-triangle text-warning';

    /**
     * From this many insights that ask for a fix (InsightsResult::fixes()).
     */
    public const ALERT_FIXES = 2;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private AuthorizationCheckerInterface $authorizationChecker,
        private ConfigLoader $configLoader,
        private RequestStack $requestStack,
        private InsightsReport $insightsReport,
    ) {
    }

    public function addItems(MenuItem $menu): void
    {
        if (! $this->authorizationChecker->isGranted(AnalyticsVoter::VIEW) || ! $this->enabled()) {
            return;
        }

        try {
            $uri = $this->urlGenerator->generate(AnalyticsController::ROUTE);
        } catch (Throwable) {
            // The routes are only there after `extensions:configure`.
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $alert = $this->insightsReport->fixes($request?->getHost() ?? '') >= self::ALERT_FIXES;

        $menu->addChild(self::NAME, [
            'uri' => $uri,
            'extras' => [
                'name' => $this->translator->trans('Analytics', [], Extension::TRANSLATION_DOMAIN),
                'icon' => $alert ? self::ALERT_ICON : self::ICON,
                'active' => $request?->attributes->get('_route') === AnalyticsController::ROUTE,
            ],
        ]);
    }

    /**
     * Like the dashboard card, the item goes with `enabled: false`; a broken
     * config keeps it, the page then says what is wrong.
     */
    private function enabled(): bool
    {
        try {
            return $this->configLoader->load()->enabled;
        } catch (Throwable) {
            return true;
        }
    }
}

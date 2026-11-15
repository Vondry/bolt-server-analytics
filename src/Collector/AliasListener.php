<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Collector;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;
use TomVondracek\RequestAnalytics\Diagnostics\FailureReporter;

/**
 * Answers requests to a domain alias with the redirect to the site, and has the
 * hop counted. A redirect never becomes the Referer of the next request, so the
 * alias can only be seen here, on the hop itself.
 *
 * In `tag` mode the redirect normally happens outside the application; should
 * an alias host reach it anyway, the redirect carries the alias marker and the
 * landing page view is the one that counts.
 *
 * Runs before the response of every request, so it only reads the (cached)
 * config. The hop is only marked here; CollectListener classifies and stores
 * it once the redirect has been sent.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
final readonly class AliasListener
{
    /**
     * Before routing (the router listener has 32).
     */
    public const PRIORITY = 256;

    public function __construct(
        private ConfigLoader $configLoader,
        private FailureReporter $failures,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        try {
            $config = $this->configLoader->load();
            $host = $request->getHost();
            if (! $config->enabled || ! $config->isAlias($host)) {
                return;
            }

            $location = $config->aliasTarget . self::requestTarget($request);

            if ($config->aliasMode === RequestAnalyticsConfig::ALIAS_MODE_TAG) {
                $location .= (str_contains($location, '?') ? '&' : '?') . $config->aliasParam . '=' . rawurlencode($host);
            } else {
                $request->attributes->set(CollectListener::ALIAS_HOP_ATTRIBUTE, true);
            }

            // Browsers keep a plain 301 forever and would skip the alias on later visits.
            $event->setResponse(new RedirectResponse($location, $config->aliasRedirectStatus, [
                'Cache-Control' => 'no-store',
            ]));
        } catch (Throwable $exception) {
            // A broken config must not take the site down: no alias handling then.
            $this->failures->report('alias redirect', $exception);
        }
    }

    /**
     * The path and query of the request, safe to append to the target: always
     * after exactly one `/` (`https://example.com` followed by `@evil.example`
     * or `.evil.example` would be another host), a backslash as a slash (as
     * browsers read it), and every byte outside the syntax of a URL
     * percent-encoded, so no line break, space or markup reaches the
     * `Location` header. A server passes on what the client sent; most refuse
     * such a request line, but not every one.
     */
    private static function requestTarget(Request $request): string
    {
        $target = '/' . mb_ltrim(str_replace('\\', '/', $request->getRequestUri()), '/');

        return preg_replace_callback(
            '~[^A-Za-z0-9\-._\~:/?@!$&()*+,;=%\[\]]~',
            static fn (array $byte): string => rawurlencode($byte[0]),
            $target,
        ) ?? '/';
    }
}

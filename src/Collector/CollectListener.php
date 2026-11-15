<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Collector;

use Bolt\Widget\Injector\RequestZone;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;
use TomVondracek\RequestAnalytics\Diagnostics\FailureReporter;
use TomVondracek\RequestAnalytics\Writer\HitWriterInterface;

/**
 * The hot path: after the response has been sent, classify the request and
 * append one row. No reads, no aggregation; a failure is logged (level
 * `error`, throttled) and forgotten: analytics must never break a page.
 *
 * Nothing is collected while the site is developed, tested or debugged
 * (`APP_ENV=dev` or `APP_DEBUG=1`): those requests are not visits.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, priority: self::PRIORITY)]
final readonly class CollectListener
{
    /**
     * After everything else that runs once the response is out.
     */
    public const PRIORITY = -1024;

    /**
     * Request attribute (`true`) of a request to an alias host that
     * AliasListener answered with the redirect in `count` mode: the hop is
     * classified here, after the redirect has been sent, like any other hit.
     */
    public const ALIAS_HOP_ATTRIBUTE = '_ra_alias_hop';

    public function __construct(
        private HitClassifier $classifier,
        private HitWriterInterface $writer,
        private FailureReporter $failures,
        #[Autowire(param: 'bolt.backend_url')]
        private string $backendUrl = '/bolt',
        #[Autowire(param: 'kernel.environment')]
        private string $environment = 'prod',
        #[Autowire(param: 'kernel.debug')]
        private bool $debug = false,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        if ($this->environment === 'dev' || $this->debug) {
            return;
        }

        $request = $event->getRequest();

        try {
            $hit = $request->attributes->get(self::ALIAS_HOP_ATTRIBUTE) === true
                ? $this->classifier->classifyAliasHop($request)
                : $this->classify($request, $event->getResponse());

            if ($hit instanceof Hit) {
                // Exactly one append, no reads.
                $this->writer->write($hit);
            }
        } catch (Throwable $exception) {
            $this->failures->report('collection', $exception);
        }
    }

    private function classify(Request $request, Response $response): ?Hit
    {
        $status = $response->getStatusCode();
        $zone = RequestZone::getFromRequest($request);

        // A 404 never gets the frontend zone when no route matched: Bolt sets
        // the zone after routing, and renders the error page in a sub-request.
        // Asset paths are kept out by `exclude_paths`.
        $isFrontend = $zone === RequestZone::FRONTEND
            || ($status === Response::HTTP_NOT_FOUND && in_array($zone, [RequestZone::NOWHERE, RequestZone::ERROR], true) && ! $this->isBackend($request));

        return $isFrontend ? $this->classifier->classify($request, $status) : null;
    }

    /**
     * Below the backend URL, wherever the project put it.
     */
    private function isBackend(Request $request): bool
    {
        $backend = '/' . mb_trim($this->backendUrl, '/');
        $path = $request->getPathInfo();

        return $backend !== '/' && ($path === $backend || str_starts_with($path, $backend . '/'));
    }
}

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
use TomVondracek\RequestAnalytics\Diagnostics\DebugDump;
use TomVondracek\RequestAnalytics\Diagnostics\FailureReporter;
use TomVondracek\RequestAnalytics\Writer\HitWriterInterface;

/**
 * The hot path: after the response has been sent, classify the request and
 * append one row. No reads, no aggregation; a failure is logged (level
 * `error`, throttled) and forgotten: analytics must never break a page.
 *
 * Nothing is collected while the site is developed, tested or debugged
 * (`APP_ENV=dev` or `APP_DEBUG=1`): those requests are not visits.
 *
 * With the debug dump on (`debug.dump`), every request of the site (not of
 * the backend) is also written there with what was decided, also while the
 * site is developed: then it says what would have been collected. A failure
 * of the dump never stops the collection.
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

    /**
     * Request attribute (`true`) of a request the extension answered for
     * itself (the rollup URL): no visit, no broken link.
     */
    public const NOT_COUNTED_ATTRIBUTE = '_ra_not_counted';

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
        private ?DebugDump $debugDump = null,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->get(self::NOT_COUNTED_ATTRIBUTE) === true) {
            return;
        }

        $developed = $this->environment === 'dev' || $this->debug;
        $trace = $this->isDumped($request) ? new ClassificationTrace() : null;
        if ($developed && ! $trace instanceof ClassificationTrace) {
            return;
        }

        $hit = null;
        $collected = false;

        try {
            $hit = $request->attributes->get(self::ALIAS_HOP_ATTRIBUTE) === true
                ? $this->classifier->classifyAliasHop($request, $trace)
                : $this->classify($request, $event->getResponse(), $trace);

            if ($hit instanceof Hit) {
                if ($developed) {
                    $trace?->skip(ClassificationTrace::DEVELOPMENT);
                } else {
                    // Exactly one append, no reads.
                    $this->writer->write($hit);
                    $collected = true;
                }
            }
        } catch (Throwable $exception) {
            $this->failures->report('collection', $exception);
            $trace?->skip('failed: ' . $exception->getMessage());
        }

        if ($trace instanceof ClassificationTrace && $this->debugDump instanceof DebugDump) {
            try {
                $this->debugDump->dump($request, $event->getResponse(), $hit, $trace, $collected);
            } catch (Throwable $exception) {
                $this->failures->report('debug dump', $exception);
            }
        }
    }

    /**
     * Whether the request goes to the debug dump: it is on, and the request
     * is one of the site, not of the backend.
     */
    private function isDumped(Request $request): bool
    {
        if (! $this->debugDump instanceof DebugDump || $this->isBackend($request) || RequestZone::getFromRequest($request) === RequestZone::BACKEND) {
            return false;
        }

        try {
            return $this->debugDump->isActive();
        } catch (Throwable) {
            // A broken config: reported where it is loaded for the collection.
            return false;
        }
    }

    private function classify(Request $request, Response $response, ?ClassificationTrace $trace = null): ?Hit
    {
        $status = $response->getStatusCode();
        $zone = RequestZone::getFromRequest($request);

        // A 404 never gets the frontend zone when no route matched: Bolt sets
        // the zone after routing, and renders the error page in a sub-request.
        // Asset paths are kept out by `exclude_paths`.
        $isFrontend = $zone === RequestZone::FRONTEND
            || ($status === Response::HTTP_NOT_FOUND && in_array($zone, [RequestZone::NOWHERE, RequestZone::ERROR], true) && ! $this->isBackend($request));

        if (! $isFrontend) {
            $trace?->skip(ClassificationTrace::NOT_FRONTEND);

            return null;
        }

        return $this->classifier->classify($request, $status, $trace);
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

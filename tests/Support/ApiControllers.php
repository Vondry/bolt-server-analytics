<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use TomVondracek\RequestAnalytics\Controller\ApiController;
use TomVondracek\RequestAnalytics\Report\PageCatalogInterface;

/**
 * The API controller of a pipeline, for the security tests: its token is
 * TOKEN, the one of deleting ERASE_TOKEN.
 */
final class ApiControllers
{
    public const TOKEN = 'api-token';
    public const ERASE_TOKEN = 'erase-token';

    public static function make(Pipeline $pipeline, MemoryLogger $logger, ?PageCatalogInterface $catalog = null): ApiController
    {
        $catalog ??= new FakePageCatalog('en', ['en', 'nl']);

        return new ApiController(
            $pipeline->project->boltConfig(),
            $pipeline->project->configLoader(),
            $pipeline->trendQuery(),
            $pipeline->breakdownQuery(),
            $pipeline->pagesReport($catalog),
            $pipeline->sourcesReport($catalog),
            $pipeline->summaryReport(),
            $pipeline->contentGapsReport($catalog),
            $pipeline->pageFlowReport($catalog),
            $pipeline->trafficReport(),
            $pipeline->rollupService(),
            $pipeline->analyticsDatabase(),
            self::tokens(),
            $logger,
            Translations::translator(),
            $pipeline->eraser(),
            new TokenStorage(),
        );
    }

    /**
     * A request of the page's scripts.
     *
     * @param array<string, mixed> $query
     */
    public static function request(array $query, string $method = Request::METHOD_GET): Request
    {
        $request = Request::create('/api', $method, $query);
        $request->headers->set(ApiController::CSRF_HEADER, self::TOKEN);
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        return $request;
    }

    private static function tokens(): CsrfTokenManagerInterface
    {
        return new class(self::TOKEN, self::ERASE_TOKEN) implements CsrfTokenManagerInterface {
            public function __construct(
                private readonly string $api,
                private readonly string $erase,
            ) {
            }

            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, '');
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, '');
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return match ($token->getId()) {
                    ApiController::CSRF_TOKEN_ID => $token->getValue() === $this->api,
                    ApiController::ERASE_CSRF_TOKEN_ID => $token->getValue() === $this->erase,
                    default => false,
                };
            }
        };
    }
}

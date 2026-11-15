<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Security;

use Symfony\Component\HttpFoundation\Response;

/**
 * Headers of every response of the extension: the Analytics page and its
 * endpoints. Bolt sends none of them.
 *
 * - never stored by a browser or a proxy
 * - never in a frame of another site (clickjacking the delete dialog)
 * - never read as anything but its declared type (MIME sniffing)
 * - never embedded by another site (`<script src>` of the JSON)
 * - the backend address never sent along when a link is followed
 */
final class ResponseHeaders
{
    /**
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    public static function apply(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}

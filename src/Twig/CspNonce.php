<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Twig;

use Throwable;
use Twig\Environment;

/**
 * The CSP nonces for inline scripts and styles, when the project has a
 * `csp_nonce()` Twig function (NelmioSecurityBundle); Bolt itself sends no
 * Content-Security-Policy.
 */
final class CspNonce
{
    /**
     * @return array{script: ?string, style: ?string}
     */
    public static function all(Environment $twig): array
    {
        return [
            'script' => self::of($twig, 'script'),
            'style' => self::of($twig, 'style'),
        ];
    }

    public static function of(Environment $twig, string $directive): ?string
    {
        try {
            if ($twig->getFunction('csp_nonce') === null) {
                return null;
            }

            $nonce = mb_trim($twig->createTemplate(sprintf("{{ csp_nonce('%s') }}", $directive))->render());

            return $nonce === '' ? null : $nonce;
        } catch (Throwable) {
            return null;
        }
    }
}

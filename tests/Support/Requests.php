<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use Symfony\Component\HttpFoundation\Request;

/**
 * Requests the way browsers and bots send them.
 */
final class Requests
{
    public const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    /**
     * The Client Hints Chrome 130 on Windows sends with every HTTPS request.
     */
    public const CHROME_CLIENT_HINTS = [
        'Sec-CH-UA' => '"Chromium";v="130", "Google Chrome";v="130", "Not?A_Brand";v="99"',
        'Sec-CH-UA-Mobile' => '?0',
        'Sec-CH-UA-Platform' => '"Windows"',
    ];

    public const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';
    public const FIREFOX_LINUX = 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0';
    public const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /**
     * 2026-11-13 10:30 in Prague.
     */
    public const TIME = '2026-11-13 09:30:00 UTC';

    /**
     * A page opened by typing the address in a current Chrome.
     *
     * @param array<string, string|null> $headers added to (or, with null, removed from) what the browser sends
     */
    public static function browser(string $url, array $headers = [], string $method = Request::METHOD_GET): Request
    {
        $request = Request::create($url, $method);
        $request->server->set('REQUEST_TIME', strtotime(self::TIME));

        // A real Chrome sends its Client Hints over HTTPS; another User-Agent
        // gets only the hints a test gives.
        if (! array_key_exists('User-Agent', $headers) && $request->isSecure()) {
            $headers += self::CHROME_CLIENT_HINTS;
        }

        $headers += [
            'User-Agent' => self::CHROME,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'cs-CZ,cs;q=0.9,en;q=0.8',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
        ];

        // Request::create() adds headers of its own.
        foreach (['User-Agent', 'Accept', 'Accept-Language', 'Accept-Charset'] as $default) {
            $request->headers->remove($default);
        }

        foreach ($headers as $name => $value) {
            if ($value !== null) {
                $request->headers->set($name, $value);
            }
        }

        return $request;
    }
}

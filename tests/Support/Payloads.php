<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use TomVondracek\RequestAnalytics\Collector\Normalizer;

/**
 * Malicious input, the way attackers send it, for the security tests: every
 * family a value of a request can carry into the statistics and back out on
 * the Analytics page.
 */
final class Payloads
{
    /**
     * @return array<string, string> name => payload
     */
    public static function all(): array
    {
        return [
            // Cross-site scripting: HTML, attributes, URLs, JavaScript strings.
            'xss script' => '<script>alert(1)</script>',
            'xss attribute breakout' => '"><img src=x onerror=alert(document.cookie)>',
            'xss single-quote breakout' => "'-alert(1)-'",
            'xss svg' => '<svg/onload=alert(1)>',
            'xss closing script' => '</script><script>alert(1)</script>',
            'xss javascript url' => 'javascript:alert(1)',
            'xss data url' => 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
            'xss template literal' => '`${alert(1)}`',
            'xss entities' => '&lt;script&gt;alert(1)&lt;/script&gt;',
            'xss double encoded' => '%253Cscript%253Ealert(1)%253C/script%253E',
            'xss unicode escapes' => '<script>alert(1)</script>',
            'xss polyglot' => "jaVasCript:/*-/*`/*\\`/*'/*\"/**/(/* */oNcliCk=alert() )//%0D%0A%0d%0a//</stYle/</titLe/</teXtarEa/</scRipt/--!>\\x3csVg/<sVg/oNloAd=alert()//>\\x3e",
            'xss css expression' => '<style>body{background:url("javascript:alert(1)")}</style>',
            // Template injection: Twig, other engines.
            'twig expression' => '{{7*7}}',
            'twig rce' => '{{_self.env.registerUndefinedFilterCallback("exec")}}{{_self.env.getFilter("id")}}',
            'twig tag' => "{% include '/etc/passwd' %}",
            'twig app' => '{{ app.request.server.all|join(",") }}',
            'twig comment' => '{# x #}',
            'ruby/java expression' => '#{7*7}${7*7}',
            // PHP.
            'php tag' => "<?php system('id'); ?>",
            'php short echo' => '<?= phpinfo() ?>',
            'php serialized object' => 'O:8:"stdClass":1:{s:1:"a";s:1:"b";}',
            'php stream wrapper' => 'php://filter/convert.base64-encode/resource=index.php',
            'php phar' => 'phar:///tmp/x.phar/x',
            // SQL.
            'sql tautology' => "' OR '1'='1",
            'sql drop' => "'; DROP TABLE ra_hit; --",
            'sql union' => '1 UNION SELECT username, password FROM bolt_user --',
            'sql double quote' => '" OR ""="',
            'sql comment' => "admin'--",
            'sql like wildcards' => "%' OR 1=1 --",
            'sql escaped quote' => "\\'; DROP TABLE ra_daily; --",
            'sqlite attach' => "1; ATTACH DATABASE '/tmp/pwned.db' AS pwned; --",
            'sql concat' => "' || (SELECT sqlite_version()) || '",
            'sql stacked mysql' => "1'; SELECT SLEEP(5); #",
            // Headers, logs, files.
            'crlf header injection' => "x\r\nSet-Cookie: pwned=1",
            'encoded crlf' => '%0d%0aLocation:%20https://evil.example',
            'log injection' => "x\n[2026-01-01T00:00:00] app.CRITICAL: forged entry",
            'path traversal' => '../../../../etc/passwd',
            'encoded traversal' => '..%2f..%2f..%2fetc%2fpasswd',
            'protocol relative' => '//evil.example/path',
            'backslash host' => '\\\\evil.example\\path',
            'null byte' => "image.php\x00.jpg",
            // Unicode tricks.
            'bidi override' => "invoice\u{202E}fdp.exe",
            'zero-width' => "ad\u{200B}min\u{FEFF}",
            'zalgo' => 'z' . str_repeat("\u{0301}", 40),
            'invalid utf-8' => "\xC3\x28\xA0\xA1",
            'overlong utf-8' => "\xC0\xAF\xC0\xAE",
            // Others.
            'csv formula' => '=HYPERLINK("https://evil.example","x")',
            'json breakout' => '"},"injected":{"__proto__":{"isAdmin":true}},"x":"',
            'nosql operator' => '{"$gt":""}',
            'ldap filter' => '*)(uid=*))(|(uid=*',
            'placeholder' => '%count% %language% {{ trend }}',
            'very long' => str_repeat('<script>', 20_000),
        ];
    }

    /**
     * What may never be in a stored value, whatever the column: markup,
     * quotes, template and code openers, line breaks, NUL, invisible and
     * bidi characters, invalid UTF-8.
     */
    public static function unsafeIn(string $value): ?string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return 'invalid UTF-8';
        }

        foreach ([
            '<' => 'markup',
            '>' => 'markup',
            '"' => 'a double quote',
            '`' => 'a backtick',
            '\\' => 'a backslash',
            '{' => 'a brace',
            '}' => 'a brace',
            "\r" => 'a line break',
            "\n" => 'a line break',
            "\0" => 'NUL',
            '<?' => 'PHP',
            '${' => 'a template expression',
        ] as $needle => $what) {
            if (str_contains($value, $needle)) {
                return $what;
            }
        }

        if (preg_match('/[\p{Cc}\p{Cf}\p{Co}\p{Cn}]/u', $value) === 1) {
            return 'an invisible or control character';
        }

        if (preg_match('/\p{M}{3}/u', $value) === 1) {
            return 'stacked combining marks';
        }

        return null;
    }

    /**
     * A stored value of a column that becomes a link: a path of the site.
     */
    public static function isSitePath(string $value): bool
    {
        return $value === '' || (Normalizer::isPath($value) && preg_match('~^/(?![/\\\\])~', $value) === 1);
    }
}

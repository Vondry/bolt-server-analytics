<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Collector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Collector\Normalizer;

final class NormalizerTest extends TestCase
{
    #[DataProvider('paths')]
    public function testPath(string $path, string $expected): void
    {
        self::assertSame($expected, Normalizer::path($path));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        yield 'root' => ['/', '/'];
        yield 'empty' => ['', '/'];
        yield 'trailing slash' => ['/program/', '/program'];
        yield 'duplicate slashes' => ['//program///2026//', '/program/2026'];
        yield 'query and fragment' => ['/program?day=1#top', '/program'];
        yield 'encoded' => ['/kapely/%C5%BElu%C5%A5ou%C4%8Dk%C3%BD', '/kapely/žluťoučký'];
        // Kept, but encoded: the address keeps its meaning and shows no markup or invisible character.
        yield 'control characters' => ["/a%0D%0Ab\x00", '/a%0D%0Ab%00'];
        yield 'invalid utf-8' => ['/a%FFb', '/a%3Fb'];
        yield 'markup' => ['/a/<script>alert(1)</script>', '/a/%3Cscript%3Ealert(1)%3C/script%3E'];
        yield 'quotes and backtick' => ['/a"b\'c`d', '/a%22b%27c%60d'];
        yield 'spaces' => ['/my page', '/my%20page'];
        yield 'bidi override' => ['/invoice%E2%80%AEfdp.exe', '/invoice%E2%80%AEfdp.exe'];
        yield 'zero-width space' => ['/ab%E2%80%8Bc', '/ab%E2%80%8Bc'];
        yield 'emoji' => ['/%F0%9F%98%80', '/%F0%9F%98%80'];
        yield 'letters of any script are kept' => ['/kapely/žluťoučký-kůň/日本', '/kapely/žluťoučký-kůň/日本'];
        // A browser reads `\` as `/`: `/\evil.com` would be a link to another site.
        yield 'backslash' => ['/\\evil.com', '/evil.com'];
        yield 'encoded backslash' => ['/%5C%5Cevil.com/x', '/evil.com/x'];
        yield 'encoded slashes' => ['/%2F%2Fevil.com', '/evil.com'];
        // One text, one row: é composed or as e + accent.
        yield 'decomposed letter' => ['/cafe%CC%81', '/café'];
        yield 'stacked accents' => ['/z%CC%81%CC%81%CC%81%CC%81x', "/\u{017A}\u{0301}\u{0301}x"];
        yield 'without leading slash' => ['program', '/program'];
        yield 'e-mail address is redacted' => ['/unsubscribe/jan.novak@example.cz', '/unsubscribe/(redacted)'];
        yield 'encoded e-mail address is redacted' => ['/odhlasit/jan%40example.cz/hotovo', '/odhlasit/(redacted)/hotovo'];
    }

    #[DataProvider('templatedPaths')]
    public function testRouteTemplates(string $path, string $expected): void
    {
        self::assertSame($expected, Normalizer::path($path, true));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function templatedPaths(): iterable
    {
        yield 'numeric id' => ['/product/12345', '/product/:id'];
        yield 'several ids' => ['/order/123456/item/98765/', '/order/:id/item/:id'];
        yield 'uuid' => ['/ticket/3F2504E0-4F89-11D3-9A0C-0305E82C3301', '/ticket/:id'];
        yield 'hash' => ['/unsubscribe/5d41402abc4b2a76b9719d911017c592', '/unsubscribe/:id'];
        yield 'year and month are kept' => ['/news/2026/11', '/news/2026/11'];
        yield 'small content id is kept' => ['/entries/42', '/entries/42'];
        yield 'slug with digits is kept' => ['/kapely/top-10-2026', '/kapely/top-10-2026'];
        yield 'short hex word is kept' => ['/cafe/beef', '/cafe/beef'];
        yield 'nothing to replace' => ['/program', '/program'];
        yield 'ulid' => ['/reset/01HZY8K3T2V7Q9XWBM4C5D6E7F', '/reset/:id'];
        yield 'random token' => ['/verify/aZ3kQ9mXv2LpT7rWbN4c', '/verify/:id'];
        yield 'json web token' => ['/login/eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U', '/login/:id'];
        yield 'long lowercase slug is kept' => ['/novinky/rockfest-kurim-2026-program-zverejnen', '/novinky/rockfest-kurim-2026-program-zverejnen'];
        yield 'long word without digits is kept' => ['/kapely/TheRollingStonesTributeBand', '/kapely/TheRollingStonesTributeBand'];
    }

    public function testRouteTemplatesAreOffUnlessAsked(): void
    {
        self::assertSame('/product/12345', Normalizer::path('/product/12345'));
    }

    public function testPathIsTruncatedTo255Characters(): void
    {
        $path = Normalizer::path('/' . str_repeat('ž', 300));

        self::assertSame(255, mb_strlen($path));
        self::assertTrue(mb_check_encoding($path, 'UTF-8'));
    }

    public function testDecodedPathKeepsTheTrailingSlash(): void
    {
        self::assertSame('/theme/', Normalizer::decodedPath('/theme/?x=1'));
        self::assertSame('/a b', Normalizer::decodedPath('/a%20b'));
    }

    #[DataProvider('searchTerms')]
    public function testSearchTerm(string $term, ?string $expected): void
    {
        self::assertSame($expected, Normalizer::searchTerm($term));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function searchTerms(): iterable
    {
        yield 'lowercase and spaces' => ["  Rock   FEST\tKuřim ", 'rock fest kuřim'];
        yield 'empty' => ['   ', null];
        yield 'e-mail' => ['kontakt Jan.Novak@Example.com prosím', 'kontakt (redacted) prosím'];
        yield 'phone' => ['volejte +420 777 123 456', 'volejte (redacted)'];
        yield 'phone with dashes' => ['777-123-456', '(redacted)'];
        yield 'long digit run' => ['objednávka 20261113', 'objednávka (redacted)'];
        yield 'short numbers stay' => ['rockfest 2026', 'rockfest 2026'];
        yield 'price stays' => ['vstupenky 450 kč', 'vstupenky 450 kč'];
    }

    public function testSearchTermIsTruncatedTo64Characters(): void
    {
        self::assertSame(64, mb_strlen((string) Normalizer::searchTerm(str_repeat('žluť ', 40))));
    }

    public function testLabel(): void
    {
        self::assertSame('summer sale', Normalizer::label("  Summer \n SALE "));
        self::assertSame(64, mb_strlen(Normalizer::label(str_repeat('a', 100))));
        self::assertSame('', Normalizer::label(''));
    }

    public function testHost(): void
    {
        self::assertSame('example.com', Normalizer::host('WWW.Example.com.'));
        self::assertSame('m.facebook.com', Normalizer::host('m.facebook.com'));
        self::assertSame('bücher.de', Normalizer::host('BÜCHER.de'));
    }

    public function testHostIsEmptyForWhatIsNoHostName(): void
    {
        foreach (['evil.com/<x>', 'goo gle.com', '[::1]', '-bad.com', 'a..b', "a\u{202E}b.com", str_repeat('a.', 130) . 'com'] as $host) {
            self::assertSame('', Normalizer::host($host), $host);
        }
    }

    public function testLabelKeepsOnlyLettersDigitsAndPlainPunctuation(): void
    {
        self::assertSame('b spring /b sale', Normalizer::label('<b>Spring</b> "sale"'));
        self::assertSame("o'reilly c++", Normalizer::label("O'Reilly C++"));
        self::assertSame('7*7', Normalizer::label('{{7*7}}'));
        self::assertSame('xgnp.exe', Normalizer::label("x\u{202E}gnp.exe"), 'no bidi override');
        self::assertSame('zürich ost', Normalizer::label("Zürich\u{00A0}Ost"));
        self::assertSame('léto', Normalizer::label('léto 🎸'));
    }

    public function testSearchTermIsSafeToo(): void
    {
        self::assertSame('script alert(1) /script', Normalizer::searchTerm('<script>alert(1)</script>'));
    }

    public function testStoredValuesAreCheckedAgainstTheSameWhitelists(): void
    {
        foreach (['', '/', '/a/b', '/café', '/a%3Cb', '/(redacted)', '/product/:id'] as $path) {
            self::assertTrue(Normalizer::isPath($path), $path);
        }
        foreach (['a', '//evil.com', '/\\evil.com', '/<b>', '/a b', "/a\u{202E}"] as $path) {
            self::assertFalse(Normalizer::isPath($path), $path);
        }

        foreach (['', 'google', "o'reilly", 'léto 2026', 'example.com', '(redacted)'] as $label) {
            self::assertTrue(Normalizer::isLabel($label), $label);
        }
        foreach (['<b>', '"x"', 'a\\b', '{x}', "a\u{202E}", "z\u{0301}\u{0301}\u{0301}", "a\nb"] as $label) {
            self::assertFalse(Normalizer::isLabel($label), $label);
        }

        self::assertTrue(Normalizer::isToken('samsung internet'));
        self::assertTrue(Normalizer::isToken('(opt_out)'));
        self::assertFalse(Normalizer::isToken('Chrome<'));
    }
}

<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;
use TomVondracek\RequestAnalytics\Extension;
use TomVondracek\RequestAnalytics\Tests\Support\Translations;

/**
 * The catalogs in translations/: every language of Bolt's backend, plus those
 * of the other Bolt extensions (appolodev/bolt-seo: `pt`, `ro`), each with
 * every message of the English one.
 */
final class TranslationsTest extends TestCase
{
    public const LOCALES = ['en', 'bg', 'cs', 'de', 'el', 'es', 'fr', 'hu', 'it', 'nl', 'pl', 'pt', 'pt_BR', 'ro', 'ru', 'sk', 'tr', 'uk', 'zh_CN'];

    public function testEveryLanguageHasACatalog(): void
    {
        $files = glob(self::directory() . '/*.yaml');
        self::assertIsArray($files);

        $locales = array_map(static fn (string $file): string => explode('.', basename($file))[1], $files);
        sort($locales);
        $expected = self::LOCALES;
        sort($expected);

        self::assertSame($expected, $locales);
    }

    public function testEnglishCatalogTranslatesEveryMessageToItself(): void
    {
        foreach (self::catalog('en') as $message => $translation) {
            self::assertSame($message, $translation);
        }
    }

    #[DataProvider('locales')]
    public function testCatalogHasEveryMessageWithItsPlaceholders(string $locale): void
    {
        $english = self::catalog('en');
        $catalog = self::catalog($locale);

        self::assertSame(array_keys($english), array_keys($catalog), sprintf('The messages of "%s" differ from the English ones.', $locale));
        foreach ($catalog as $message => $translation) {
            self::assertNotSame('', mb_trim($translation), sprintf('"%s" is empty in "%s".', $message, $locale));
            self::assertSame(self::placeholders($message), self::placeholders($translation), sprintf('The placeholders of "%s" differ in "%s".', $message, $locale));
        }
    }

    #[DataProvider('locales')]
    public function testSymfonyLoadsTheCatalogInTheExtensionsDomain(string $locale): void
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', self::file($locale), $locale, Extension::TRANSLATION_DOMAIN);

        self::assertSame(self::catalog($locale)['Page visits'], $translator->trans('Page visits', [], Extension::TRANSLATION_DOMAIN));
        self::assertSame('Page visits', $translator->trans('Page visits'), 'not in the shared "messages" domain');
    }

    /**
     * A user whose backend is in one of Bolt's languages sees the analytics
     * in that language too, not in English.
     */
    public function testEveryBackendLanguageOfBoltIsCovered(): void
    {
        $files = glob(dirname(__DIR__) . '/vendor/bolt/core/translations/messages.*.xlf');
        self::assertIsArray($files);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $locale = explode('.', basename($file))[1];
            $menu = Translations::translator($locale)->trans('Analytics', [], Extension::TRANSLATION_DOMAIN);

            self::assertContains($locale, self::LOCALES, sprintf('Bolt\'s backend speaks "%s".', $locale));
            if ($locale !== 'en') {
                self::assertNotSame('Analytics', $menu, sprintf('The menu item is English in "%s".', $locale));
            }
        }
    }

    /**
     * Every text the code translates is in the catalog, and the catalog holds
     * nothing that is no longer used.
     */
    public function testCatalogMatchesTheMessagesTheCodeUses(): void
    {
        $used = [];

        $templates = glob(dirname(__DIR__) . '/templates/*/*.html.twig');
        self::assertIsArray($templates);
        foreach ($templates as $file) {
            preg_match_all("~'((?:[^'\\\\]|\\\\.)+)'\\|trans\\(\\{[^}]*\\}, domain\\)~", (string) file_get_contents($file), $matches);
            array_push($used, ...$matches[1]);
        }

        foreach (self::sources() as $file) {
            preg_match_all("~->trans\\('((?:[^'\\\\]|\\\\.)+)'~", (string) file_get_contents($file), $matches);
            array_push($used, ...$matches[1]);
        }
        array_push($used, ...self::insightMessages());

        $used = array_values(array_unique($used));
        sort($used);
        $known = array_keys(self::catalog('en'));
        sort($known);

        self::assertNotEmpty($used);
        self::assertSame($known, $used);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (self::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * The texts of the insights: the sentences of the rules
     * (`Message::of('…')`) and the texts of their card.
     *
     * @return list<string>
     */
    public static function insightMessages(): array
    {
        $messages = [];
        foreach (self::sources() as $file) {
            if (str_contains($file, '/src/Insight/')) {
                preg_match_all("~Message::of\\('((?:[^'\\\\]|\\\\.)+)'~", (string) file_get_contents($file), $matches);
                array_push($messages, ...$matches[1]);
            }
        }

        preg_match_all("~'((?:[^'\\\\]|\\\\.)+)'\\|trans\\(\\{[^}]*\\}, domain\\)~", (string) file_get_contents(dirname(__DIR__) . '/templates/page/_insights.html.twig'), $matches);
        array_push($messages, ...$matches[1]);

        return array_values(array_unique(array_map(static fn (string $message): string => str_replace("\\'", "'", $message), $messages)));
    }

    /**
     * @return list<string> every PHP file below src/
     */
    private static function sources(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public static function directory(): string
    {
        return dirname(__DIR__) . '/translations';
    }

    /**
     * @return array<string, string>
     */
    private static function catalog(string $locale): array
    {
        $catalog = Yaml::parseFile(self::file($locale));
        self::assertIsArray($catalog);

        $messages = [];
        foreach ($catalog as $message => $translation) {
            self::assertIsString($translation, sprintf('"%s" in "%s" is not a plain text.', $message, $locale));
            $messages[(string) $message] = $translation;
        }

        return $messages;
    }

    private static function file(string $locale): string
    {
        return self::directory() . '/' . Extension::TRANSLATION_DOMAIN . '.' . $locale . '.yaml';
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $message): array
    {
        preg_match_all('/%[a-z_]+%/', $message, $matches);
        $placeholders = array_values(array_unique($matches[0]));
        sort($placeholders);

        return $placeholders;
    }
}

<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use TomVondracek\RequestAnalytics\Extension;

/**
 * A translator with the extension's catalogs, as a project registers them.
 */
final class Translations
{
    public static function translator(string $locale = 'en'): Translator
    {
        $translator = new Translator($locale);
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());

        $files = glob(dirname(__DIR__, 2) . '/translations/' . Extension::TRANSLATION_DOMAIN . '.*.yaml');
        foreach ($files === false ? [] : $files as $file) {
            $translator->addResource('yaml', $file, explode('.', basename($file))[1], Extension::TRANSLATION_DOMAIN);
        }

        return $translator;
    }
}

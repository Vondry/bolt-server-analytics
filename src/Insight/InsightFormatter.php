<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use NumberFormatter;
use Symfony\Component\Intl\Languages;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use TomVondracek\RequestAnalytics\Collector\Channel;
use TomVondracek\RequestAnalytics\Extension;

/**
 * Turns insights into what the page shows: the sentence and its next step in
 * the backend language, with numbers, days and language names formatted for
 * it.
 *
 * The result is HTML: every text is escaped here, then values are marked up
 * (a page title in italics, a path as code), so the template prints it raw.
 *
 * @phpstan-type View array{id: string, kind: string, section: string, anchor: string, days: int, compared: bool, more: int, html: string}
 */
final readonly class InsightFormatter
{
    /**
     * Names of the channels, as the sources card names them.
     */
    private const CHANNELS = [
        Channel::ORGANIC_SEARCH => 'Organic search',
        Channel::AI_ASSISTANT => 'AI assistants',
        Channel::PAID_SEARCH => 'Paid search',
        Channel::SOCIAL => 'Social',
        Channel::EMAIL => 'Email',
        Channel::DISPLAY => 'Display',
        Channel::AFFILIATE => 'Affiliate',
        Channel::CAMPAIGN => 'Campaign',
        Channel::REFERRAL => 'Referral',
        Channel::DIRECT => 'Direct',
        Channel::APP => 'App',
        Channel::UNKNOWN => 'Unknown',
    ];

    /**
     * Product names stay as they are, as on the browsers card.
     */
    private const BROWSERS = [
        'chrome' => 'Chrome',
        'safari' => 'Safari',
        'firefox' => 'Firefox',
        'edge' => 'Edge',
        'opera' => 'Opera',
        'samsung' => 'Samsung Internet',
        'yandex' => 'Yandex',
        'brave' => 'Brave',
        'vivaldi' => 'Vivaldi',
        'duckduckgo' => 'DuckDuckGo',
        'webview' => 'Android WebView',
        'chromium' => 'Chromium',
    ];

    /**
     * Device classes are translated, as on the devices card.
     */
    private const DEVICES = [
        'desktop' => 'Desktop',
        'mobile' => 'Mobile',
        'tablet' => 'Tablet',
        'tv' => 'TV',
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<Insight> $insights
     * @param ?string $locale null = the translator's
     *
     * @return list<View>
     */
    public function format(array $insights, ?string $locale = null): array
    {
        $locale ??= $this->translator->getLocale();

        return array_map(fn (Insight $insight): array => [
            'id' => $insight->id,
            'kind' => $insight->kind,
            'section' => $insight->section,
            'anchor' => $insight->target(),
            'days' => $insight->days,
            'compared' => $insight->compared,
            'more' => $insight->more,
            'html' => $this->sentence($insight->message, $locale)
                . ($insight->action instanceof Message ? ' <span class="ra-insight__action">→ ' . $this->sentence($insight->action, $locale) . '</span>' : ''),
        ], $insights);
    }

    /**
     * What the card says when no insight fires: waiting for statistics, too
     * few visits, or nothing to act on.
     *
     * @param ?string $locale null = the translator's
     *
     * @return array{state: string, html: string}|null
     */
    public function status(?InsightsStatus $status, ?string $locale = null): ?array
    {
        if (! $status instanceof InsightsStatus) {
            return null;
        }

        return [
            'state' => $status->state,
            'html' => $this->sentence($status->message, $locale ?? $this->translator->getLocale()),
        ];
    }

    /**
     * The translated sentence, escaped, with its values marked up. A sentence
     * that starts with the name of a language, channel or device starts with
     * a capital letter ("Polish: …", also where language names are lowercase).
     */
    public function sentence(Message $message, string $locale): string
    {
        $template = $this->translator->trans($message->key, [], Extension::TRANSLATION_DOMAIN, $locale);
        $html = htmlspecialchars($template, ENT_QUOTES | ENT_HTML5);

        $values = [];
        foreach ($message->params as $name => $param) {
            $placeholder = '%' . $name . '%';
            $values[$placeholder] = $this->param($param, $locale, str_starts_with($template, $placeholder));
        }

        return strtr($html, $values);
    }

    private function param(Param $param, string $locale, bool $capital = false): string
    {
        $text = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5);
        // Names that are lowercase in some languages; hosts, terms and titles stay as they are.
        $name = static fn (string $value): string => htmlspecialchars($capital ? mb_ucfirst($value) : $value, ENT_QUOTES | ENT_HTML5);
        $value = $param->value;

        return match ($param->type) {
            Param::NUMBER => $text($this->number(is_numeric($value) ? (float) $value : 0.0, $locale)),
            Param::DECIMAL => $text($this->number(is_numeric($value) ? (float) $value : 0.0, $locale, 1)),
            Param::PERCENT => $text($this->percent(is_numeric($value) ? (float) $value : 0.0, $locale)),
            Param::CHANGE => $text(self::sign(is_numeric($value) ? (float) $value : 0.0) . $this->percent(abs(is_numeric($value) ? (float) $value : 0.0), $locale)),
            Param::DAY => $text($this->day(is_string($value) ? $value : '', $locale)),
            Param::PAGE => $this->page($param, $locale),
            Param::PATH, Param::CODE => '<code>' . htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_HTML5) . '</code>',
            Param::TERM => '<em>' . $text(is_string($value) ? $value : '') . '</em>',
            Param::LANGUAGE => $name($this->language(is_string($value) ? $value : '', $locale)),
            Param::CHANNEL => $name($this->label(self::CHANNELS[is_string($value) ? $value : ''] ?? (is_string($value) ? $value : ''), $locale)),
            Param::BROWSER => $text(self::BROWSERS[is_string($value) ? $value : ''] ?? mb_ucfirst(is_string($value) ? $value : '')),
            Param::DEVICE => $name($this->label(self::DEVICES[is_string($value) ? $value : ''] ?? (is_string($value) ? $value : ''), $locale)),
            Param::LIST => implode(', ', array_map(fn (Param $item, int $index): string => $this->param($item, $locale, $capital && $index === 0), is_array($value) ? $value : [], array_keys(is_array($value) ? $value : []))),
            default => $text(is_scalar($value) ? (string) $value : ''),
        };
    }

    /**
     * A page by its title, the homepage by its name, else by its path.
     */
    private function page(Param $param, string $locale): string
    {
        $path = is_string($param->value) ? $param->value : '';
        $title = $param->title ?? ($param->home ? $this->label('Homepage', $locale) : null);
        $html = $title === null
            ? '<code>' . htmlspecialchars($path, ENT_QUOTES | ENT_HTML5) . '</code>'
            : '<em title="' . htmlspecialchars($path, ENT_QUOTES | ENT_HTML5) . '">' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5) . '</em>';

        if ($param->delta !== null) {
            $html .= ' (' . htmlspecialchars(self::sign($param->delta) . $this->number(abs($param->delta), $locale), ENT_QUOTES | ENT_HTML5) . ')';
        }

        return $html;
    }

    private function label(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], Extension::TRANSLATION_DOMAIN, $locale);
    }

    private function number(float $value, string $locale, int $decimals = 0): string
    {
        if (extension_loaded('intl')) {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $formatted = $formatter->format($value);
            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return number_format($value, $decimals, '.', ' ');
    }

    /**
     * Whole percent, one decimal below 1 % (0.2 %), never 0 % for a share
     * that is not 0.
     */
    private function percent(float $share, string $locale): string
    {
        $decimals = $share > 0 && $share < 0.01 ? 1 : 0;
        if ($share > 0 && $share < 0.001) {
            $share = 0.001;
        }

        if (extension_loaded('intl')) {
            $formatter = new NumberFormatter($locale, NumberFormatter::PERCENT);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $formatted = $formatter->format($share);
            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return number_format($share * 100, $decimals, '.', ' ') . ' %';
    }

    /**
     * A short day without the year, such as "4 Nov".
     */
    private function day(string $day, string $locale): string
    {
        try {
            $date = new DateTimeImmutable($day, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return $day;
        }

        if (extension_loaded('intl')) {
            $pattern = (new IntlDatePatternGenerator($locale))->getBestPattern('dMMM');
            $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, is_string($pattern) ? $pattern : 'd MMM');
            $formatted = $formatter->format($date);
            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return $date->format('j M');
    }

    /**
     * The name of a language in the backend language; its code when unknown.
     */
    private function language(string $code, string $locale): string
    {
        try {
            return class_exists(Languages::class) ? Languages::getName($code, $locale) : $code;
        } catch (Throwable) {
            return $code;
        }
    }

    private static function sign(float|int $value): string
    {
        return $value < 0 ? '−' : ($value > 0 ? '+' : '');
    }
}

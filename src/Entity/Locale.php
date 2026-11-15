<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Page views and visits per language (the first of `Accept-Language`).
 *
 * One dimension (or a few that belong together) per table, never all of them:
 * a row of page × device × browser × language could describe a
 * single visitor on a small site. `is_entry` splits page views from visits.
 *
 * Schema only: the table is read and written with plain SQL, the entity
 * exists so that the extension can create the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ra_locales', options: TableNames::OPTIONS)]
class Locale
{
    /** Site timezone. */
    #[ORM\Id]
    #[ORM\Column(name: 'day', type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $day;

    #[ORM\Id]
    #[ORM\Column(name: 'lang', type: Types::STRING, length: 8)]
    public string $lang = '';

    #[ORM\Id]
    #[ORM\Column(name: 'is_entry', type: Types::BOOLEAN)]
    public bool $isEntry = false;

    #[ORM\Column(name: 'hits', type: Types::INTEGER, options: ['default' => 0])]
    public int $hits = 0;
}

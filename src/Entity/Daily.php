<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * One row per day: the totals behind the dashboard trend.
 *
 * Schema only: the table is read and written with plain SQL, the entity
 * exists so that Doctrine migrations own the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ra_daily', options: TableNames::OPTIONS)]
class Daily
{
    /** Site timezone. */
    #[ORM\Id]
    #[ORM\Column(name: 'day', type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $day;

    #[ORM\Column(name: 'pageviews', type: Types::INTEGER, options: ['default' => 0])]
    public int $pageviews = 0;

    #[ORM\Column(name: 'entries', type: Types::INTEGER, options: ['default' => 0])]
    public int $entries = 0;

    #[ORM\Column(name: 'not_found', type: Types::INTEGER, options: ['default' => 0])]
    public int $notFound = 0;

    #[ORM\Column(name: 'bots', type: Types::INTEGER, options: ['default' => 0])]
    public int $bots = 0;

    #[ORM\Column(name: 'alias', type: Types::INTEGER, options: ['default' => 0])]
    public int $alias = 0;
}

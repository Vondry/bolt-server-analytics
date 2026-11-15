<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Page views per hour of the day.
 *
 * Schema only: the table is read and written with plain SQL, the entity
 * exists so that Doctrine migrations own the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ra_hourly', options: TableNames::OPTIONS)]
class Hourly
{
    /** Site timezone. */
    #[ORM\Id]
    #[ORM\Column(name: 'day', type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $day;

    /** 0-23, site timezone. */
    #[ORM\Id]
    #[ORM\Column(name: 'hour', type: Types::SMALLINT)]
    public int $hour = 0;

    #[ORM\Column(name: 'hits', type: Types::INTEGER, options: ['default' => 0])]
    public int $hits = 0;
}

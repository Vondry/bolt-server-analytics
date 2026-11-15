<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Requests by bots, per bot family.
 *
 * Schema only: the table is read and written with plain SQL, the entity
 * exists so that Doctrine migrations own the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ra_bots', options: TableNames::OPTIONS)]
class Bot
{
    /** Site timezone. */
    #[ORM\Id]
    #[ORM\Column(name: 'day', type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $day;

    #[ORM\Id]
    #[ORM\Column(name: 'bot', type: Types::STRING, length: 32)]
    public string $bot = '';

    #[ORM\Column(name: 'hits', type: Types::INTEGER, options: ['default' => 0])]
    public int $hits = 0;
}

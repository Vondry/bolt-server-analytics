<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Entries (page views that did not come from the site itself) per landing page,
 * channel, source and campaign.
 *
 * Schema only: the table is read and written with plain SQL, the entity
 * exists so that Doctrine migrations own the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ra_sources', options: TableNames::OPTIONS)]
class Source
{
    /** Site timezone. */
    #[ORM\Id]
    #[ORM\Column(name: 'day', type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $day;

    #[ORM\Id]
    #[ORM\Column(name: 'path', type: Types::STRING, length: 255)]
    public string $path = '';

    #[ORM\Id]
    #[ORM\Column(name: 'channel', type: Types::STRING, length: 16)]
    public string $channel = '';

    #[ORM\Id]
    #[ORM\Column(name: 'source', type: Types::STRING, length: 64)]
    public string $source = '';

    #[ORM\Id]
    #[ORM\Column(name: 'campaign', type: Types::STRING, length: 64)]
    public string $campaign = '';

    #[ORM\Column(name: 'hits', type: Types::INTEGER, options: ['default' => 0])]
    public int $hits = 0;
}

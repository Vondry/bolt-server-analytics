<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * 404 responses, with the page (or the external host) that linked to them.
 *
 * Schema only: the table is read and written with plain SQL, the entity
 * exists so that Doctrine migrations own the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ra_not_found', options: TableNames::OPTIONS)]
class NotFound
{
    /** Site timezone. */
    #[ORM\Id]
    #[ORM\Column(name: 'day', type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $day;

    #[ORM\Id]
    #[ORM\Column(name: 'path', type: Types::STRING, length: 255)]
    public string $path = '';

    /** Referrer path or host; '' when there was none. */
    #[ORM\Id]
    #[ORM\Column(name: 'ref', type: Types::STRING, length: 255)]
    public string $ref = '';

    #[ORM\Column(name: 'hits', type: Types::INTEGER, options: ['default' => 0])]
    public int $hits = 0;
}

<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Internal navigation: page views of `to_path` that came from `from_path`.
 *
 * Schema only: the table is read and written with plain SQL, the entity
 * exists so that Doctrine migrations own the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ra_transitions', options: TableNames::OPTIONS)]
class Transition
{
    /** Site timezone. */
    #[ORM\Id]
    #[ORM\Column(name: 'day', type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $day;

    #[ORM\Id]
    #[ORM\Column(name: 'from_path', type: Types::STRING, length: 255)]
    public string $fromPath = '';

    #[ORM\Id]
    #[ORM\Column(name: 'to_path', type: Types::STRING, length: 255)]
    public string $toPath = '';

    #[ORM\Column(name: 'hits', type: Types::INTEGER, options: ['default' => 0])]
    public int $hits = 0;
}

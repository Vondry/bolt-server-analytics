<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Bookkeeping of the rollup, in a single row with id 1. Updating that row first
 * in every rollup transaction also serialises rollups across servers. How far
 * the spool files got is in `ra_spool` (SpoolProgress), one row per file.
 *
 * Schema only: the table is read and written with plain SQL, the entity
 * exists so that Doctrine migrations own the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ra_state', options: TableNames::OPTIONS)]
class State
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::SMALLINT)]
    public int $id = 0;

    /** UTC. */
    #[ORM\Column(name: 'last_rollup_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $lastRollupAt = null;

    /** Site timezone; the retention cleanup runs once a day. */
    #[ORM\Column(name: 'last_purge_day', type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $lastPurgeDay = null;

    /**
     * The periods whose statistics were deleted (StatisticsEraser), as JSON:
     * `[{"from": "Y-m-d", "to": "Y-m-d"}]`, site timezone. The insights do not
     * take their empty days for an outage.
     */
    #[ORM\Column(name: 'erased_periods', type: Types::TEXT, nullable: true)]
    public ?string $erasedPeriods = null;
}

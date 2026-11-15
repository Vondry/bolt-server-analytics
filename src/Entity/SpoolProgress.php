<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * How far a claimed spool file has been moved into `ra_hit`: saved in the
 * transaction of every batch, so a file is never counted twice, also when the
 * rollup stops halfway or the process dies.
 *
 * One row per file, not one for the whole site: with several web servers, each
 * moves its own spool into the shared database, and the position of one
 * server's file must not replace that of another's. A claimed file's name
 * carries a random part, so the names of two servers never meet.
 *
 * Schema only: the table is read and written with plain SQL.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ra_spool', options: TableNames::OPTIONS)]
class SpoolProgress
{
    /** Name of the claimed file, `<YYYYMMDDHH>.ndjson.<random>.ingest`. */
    #[ORM\Id]
    #[ORM\Column(name: 'file_name', type: Types::STRING, length: 255)]
    public string $fileName = '';

    /** Bytes of the file that are in `ra_hit`. */
    #[ORM\Column(name: 'bytes_read', type: Types::BIGINT)]
    public int $bytesRead = 0;
}

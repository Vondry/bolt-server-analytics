<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Raw row: one classified request, with derived dimensions only (no IP, no
 * User-Agent, no exact time). Appended by the collector and deleted as soon as
 * the rollup has counted it. No secondary indexes, so an insert is an append.
 *
 * Schema only: the table is read and written with plain SQL, the entity
 * exists so that Doctrine migrations own the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ra_hit', options: TableNames::OPTIONS)]
class Hit
{
    /** IDENTITY, so a plain SQL insert gets its id on every platform. */
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    public ?int $id = null;

    /** Site timezone. */
    #[ORM\Column(name: 'day', type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $day;

    /** 0-23, site timezone. */
    #[ORM\Column(name: 'hour', type: Types::SMALLINT)]
    public int $hour = 0;

    /** pageview | not_found | bot | alias */
    #[ORM\Column(name: 'kind', type: Types::STRING, length: 10)]
    public string $kind = '';

    #[ORM\Column(name: 'path', type: Types::STRING, length: 255)]
    public string $path = '';

    #[ORM\Column(name: 'device', type: Types::STRING, length: 16)]
    public string $device = '';

    #[ORM\Column(name: 'browser', type: Types::STRING, length: 16)]
    public string $browser = '';

    #[ORM\Column(name: 'os', type: Types::STRING, length: 16)]
    public string $os = '';

    #[ORM\Column(name: 'lang', type: Types::STRING, length: 8)]
    public string $lang = '';

    #[ORM\Column(name: 'channel', type: Types::STRING, length: 16)]
    public string $channel = '';

    #[ORM\Column(name: 'source', type: Types::STRING, length: 64)]
    public string $source = '';

    #[ORM\Column(name: 'campaign', type: Types::STRING, length: 64)]
    public string $campaign = '';

    #[ORM\Column(name: 'is_entry', type: Types::BOOLEAN)]
    public bool $isEntry = false;

    #[ORM\Column(name: 'is_reload', type: Types::BOOLEAN)]
    public bool $isReload = false;

    /** Internal referrer path (pageview) or referrer path / host (not_found). */
    #[ORM\Column(name: 'ref', type: Types::STRING, length: 255, nullable: true)]
    public ?string $ref = null;

    /** Bot family. */
    #[ORM\Column(name: 'bot', type: Types::STRING, length: 32, nullable: true)]
    public ?string $bot = null;

    /** Alias host: of the redirect hop, or from the alias marker in the URL. */
    #[ORM\Column(name: 'via', type: Types::STRING, length: 255, nullable: true)]
    public ?string $via = null;

    /** Redacted site-search term. */
    #[ORM\Column(name: 'term', type: Types::STRING, length: 64, nullable: true)]
    public ?string $term = null;
}

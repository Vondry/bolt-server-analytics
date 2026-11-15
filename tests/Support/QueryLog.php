<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

/**
 * DBAL middleware that records every executed statement, and can run code right
 * after a statement: to see what a component sends to the database, and to let
 * something happen at an exact moment of a transaction.
 */
final class QueryLog implements Middleware
{
    /** @var list<string> */
    public array $statements = [];

    /** @var list<array{string, Closure(string): void}> */
    private array $listeners = [];

    /**
     * @param string $pattern regular expression the SQL has to match
     * @param Closure(string): void $listener called after the statement ran
     */
    public function after(string $pattern, Closure $listener): void
    {
        $this->listeners[] = [$pattern, $listener];
    }

    public function clear(): void
    {
        $this->statements = [];
        $this->listeners = [];
    }

    /**
     * @return list<string>
     */
    public function matching(string $pattern): array
    {
        return array_values(array_filter($this->statements, static fn (string $sql): bool => preg_match($pattern, $sql) === 1));
    }

    public function executed(string $sql): void
    {
        $this->statements[] = $sql;
        foreach ($this->listeners as [$pattern, $listener]) {
            if (preg_match($pattern, $sql) === 1) {
                $listener($sql);
            }
        }
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this) extends AbstractDriverMiddleware {
            public function __construct(
                Driver $driver,
                private readonly QueryLog $log,
            ) {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): DriverConnection
            {
                return new class(parent::connect($params), $this->log) extends AbstractConnectionMiddleware {
                    public function __construct(
                        DriverConnection $connection,
                        private readonly QueryLog $log,
                    ) {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        return new class(parent::prepare($sql), $sql, $this->log) extends AbstractStatementMiddleware {
                            public function __construct(
                                Statement $statement,
                                private readonly string $sql,
                                private readonly QueryLog $log,
                            ) {
                                parent::__construct($statement);
                            }

                            public function execute(): Result
                            {
                                $result = parent::execute();
                                $this->log->executed($this->sql);

                                return $result;
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $result = parent::query($sql);
                        $this->log->executed($sql);

                        return $result;
                    }

                    public function exec(string $sql): int|string
                    {
                        $result = parent::exec($sql);
                        $this->log->executed($sql);

                        return $result;
                    }
                };
            }
        };
    }
}

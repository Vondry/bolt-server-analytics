<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Storage\StorageTarget;

final class StorageTargetTest extends TestCase
{
    public function testSqliteFile(): void
    {
        foreach (['/project/var/data/request-analytics.db', 'sqlite:////project/var/data/request-analytics.db'] as $value) {
            $target = StorageTarget::fromConfig($value);

            self::assertSame(StorageTarget::SQLITE, $target->platform);
            self::assertSame('/project/var/data/request-analytics.db', $target->path());
            self::assertSame('pdo_sqlite', $target->extension());
            self::assertSame('/project/var/data/request-analytics.db', StorageTarget::sqlitePath($value));
        }
    }

    public function testDatabaseServers(): void
    {
        $mysql = StorageTarget::fromConfig('mariadb://stats:s%40cret@db.example:3307/bolt_stats?serverVersion=10.11.0-MariaDB');
        self::assertSame(StorageTarget::MYSQL, $mysql->platform);
        self::assertNull($mysql->path());
        self::assertSame('pdo_mysql', $mysql->extension());
        self::assertSame('s@cret', $mysql->params['password'] ?? null);
        self::assertSame('10.11.0-MariaDB', $mysql->params['serverVersion'] ?? null);

        $postgres = StorageTarget::fromConfig('postgresql://stats:secret@localhost/bolt_stats');
        self::assertSame(StorageTarget::PGSQL, $postgres->platform);
        self::assertSame('pdo_pgsql', $postgres->extension());
        self::assertNull(StorageTarget::sqlitePath('postgresql://stats:secret@localhost/bolt_stats'));
    }

    /**
     * Messages and the install command name the database, never the password.
     */
    public function testDescriptionLeavesOutThePassword(): void
    {
        self::assertSame('mysql://stats@db.example:3307/bolt_stats', StorageTarget::fromConfig('mysql://stats:secret@db.example:3307/bolt_stats')->describe());
        self::assertSame('postgresql://db.example/bolt_stats', StorageTarget::fromConfig('postgresql://db.example/bolt_stats')->describe());
        self::assertSame('/project/stats.db', StorageTarget::fromConfig('/project/stats.db')->describe());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, bool}>
     */
    public static function boltConnections(): iterable
    {
        $project = sys_get_temp_dir();
        $own = 'mysql://stats:secret@db.example:3306/bolt_stats';

        yield 'Bolt\'s SQLite file' => [$project . '/var/data/bolt.sqlite', ['driver' => 'pdo_sqlite', 'path' => $project . '/var/data/bolt.sqlite'], true];
        yield 'Bolt\'s SQLite file, written another way' => [$project . '/var/../var/data/bolt.sqlite', ['driver' => 'pdo_sqlite', 'path' => $project . '/var/data/bolt.sqlite'], true];
        yield 'Bolt\'s SQLite file as a URL' => ['sqlite:///' . $project . '/var/data/bolt.sqlite', ['driver' => 'pdo_sqlite', 'path' => $project . '/var/data/bolt.sqlite'], true];
        yield 'another SQLite file' => [$project . '/var/data/request-analytics.db', ['driver' => 'pdo_sqlite', 'path' => $project . '/var/data/bolt.sqlite'], false];
        yield 'Bolt in memory' => [$project . '/var/data/request-analytics.db', ['driver' => 'pdo_sqlite', 'memory' => true], false];
        yield 'Bolt on MySQL, statistics in SQLite' => [$project . '/var/data/request-analytics.db', ['driver' => 'pdo_mysql', 'host' => 'db.example', 'dbname' => 'bolt'], false];
        yield 'Bolt\'s MySQL database' => [$own, ['driver' => 'pdo_mysql', 'host' => 'db.example', 'port' => 3306, 'dbname' => 'bolt_stats'], true];
        yield 'Bolt\'s MySQL database, default port' => [$own, ['driver' => 'pdo_mysql', 'host' => 'DB.example', 'dbname' => 'BOLT_STATS'], true];
        yield 'Bolt\'s MySQL database through mysqli' => [$own, ['driver' => 'mysqli', 'host' => 'db.example', 'dbname' => 'bolt_stats'], true];
        yield 'Bolt\'s primary' => [$own, ['driver' => 'pdo_mysql', 'primary' => ['host' => 'db.example', 'dbname' => 'bolt_stats'], 'replica' => []], true];
        yield 'another database on Bolt\'s server' => [$own, ['driver' => 'pdo_mysql', 'host' => 'db.example', 'dbname' => 'bolt'], false];
        yield 'the same name on another server' => [$own, ['driver' => 'pdo_mysql', 'host' => 'db2.example', 'dbname' => 'bolt_stats'], false];
        yield 'the same name on another port' => [$own, ['driver' => 'pdo_mysql', 'host' => 'db.example', 'port' => 3307, 'dbname' => 'bolt_stats'], false];
        yield 'localhost and 127.0.0.1' => ['postgresql://stats@localhost/bolt', ['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => '5432', 'dbname' => 'bolt'], true];
        yield 'a socket and localhost' => ['mysql://stats@localhost/bolt', ['driver' => 'pdo_mysql', 'unix_socket' => '/run/mysqld/mysqld.sock', 'dbname' => 'bolt'], true];
        yield 'a socket and a remote server' => ['mysql://stats@db.example/bolt', ['driver' => 'pdo_mysql', 'unix_socket' => '/run/mysqld/mysqld.sock', 'dbname' => 'bolt'], false];
        yield 'MySQL and PostgreSQL' => ['postgresql://stats@db.example/bolt', ['driver' => 'pdo_mysql', 'host' => 'db.example', 'dbname' => 'bolt'], false];
        yield 'a driver class of its own' => [$own, ['driverClass' => 'App\\Driver', 'host' => 'db.example', 'dbname' => 'bolt_stats'], false];
    }

    /**
     * @param array<string, mixed> $boltParams
     */
    #[DataProvider('boltConnections')]
    public function testRecognisesBoltsDatabase(string $storage, array $boltParams, bool $same): void
    {
        self::assertSame($same, StorageTarget::fromConfig($storage)->isSameDatabaseAs($boltParams));
    }
}

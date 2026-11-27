<?php

declare(strict_types=1);

namespace test\php\phpunit\TiMMiT\MySQL;

use mysqli;
use mysqli_result;
use TiMMiT\MySQL\DB;
use TiMMiT\MySQL\DBException;
use test\php\phpunit\BaseTestCase;

/**
 * Verifies that disconnect() leaves DB without any connection against a real MySQL-compatible database.
 */
class DBDisconnectIntegrationTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        DB::disconnect(true);
        DB::$fallbackActive = false;
        DB::$writeActive = false;

        parent::tearDown();
    }

    /**
     * Without a write node the primary connection is closed and DB no longer looks connected.
     */
    public function testDisconnectClosesThePrimaryConnection(): void
    {
        DB::loadConfig($this->databaseConfig());
        DB::connect();
        $primaryThread = $this->threadOf(false);

        DB::disconnect();

        $this->assertFalse(DB::ping());
        $this->assertSame([], $this->openThreadsAmong([$primaryThread]));
    }

    /**
     * With a write node both connections are closed, not only the write connection.
     */
    public function testDisconnectClosesBothConnectionsWithAWriteNode(): void
    {
        $primary = $this->databaseConfig();
        $write = $primary;
        $write['connection_role'] = 'write';

        DB::loadConfig($primary, $write);
        DB::connect();
        $this->assertTrue(DB::$writeActive);
        $primaryThread = $this->threadOf(false);
        $writeThread = $this->threadOf(true);

        DB::disconnect();

        $this->assertFalse(DB::ping());
        $this->assertFalse(DB::ping(true));
        $this->assertSame([], $this->openThreadsAmong([$primaryThread, $writeThread]));
    }

    /**
     * A write link left over from an earlier configuration is closed even though writes no longer use it.
     */
    public function testDisconnectClosesAWriteLinkThatIsNoLongerRouted(): void
    {
        $primary = $this->databaseConfig();
        $write = $primary;
        $write['connection_role'] = 'write';

        DB::loadConfig($primary, $write);
        DB::connect();
        $writeThread = $this->threadOf(true);
        DB::loadConfig($primary);
        DB::connect();
        $this->assertFalse(DB::$writeActive);

        DB::disconnect();

        $this->assertFalse(DB::ping(true));
        $this->assertSame([], $this->openThreadsAmong([$writeThread]));
    }

    /**
     * A query after disconnect() reports the missing connection as a DBException.
     */
    public function testQueryAfterDisconnectThrowsDBException(): void
    {
        DB::loadConfig($this->databaseConfig());
        DB::connect();
        DB::disconnect();

        $this->expectException(DBException::class);
        DB::selectQuery('SELECT 1 AS `one`;');
    }

    /**
     * A link the server already closed is dropped as well, so a later query does not fail silently.
     */
    public function testDisconnectDropsALinkTheServerAlreadyClosed(): void
    {
        DB::loadConfig($this->databaseConfig());
        DB::connect();
        $this->killThread($this->threadOf(false));

        DB::disconnect();

        $this->expectException(DBException::class);
        DB::selectQuery('SELECT 1 AS `one`;');
    }

    /**
     * connect() after disconnect() opens a working connection again.
     */
    public function testConnectAfterDisconnectOpensANewConnection(): void
    {
        DB::loadConfig($this->databaseConfig());
        DB::connect();
        DB::disconnect();

        DB::connect();

        $this->assertSame([['one' => '1']], DB::selectQuery('SELECT 1 AS `one`;'));
    }

    /**
     * Return the server thread id of the primary or the write connection.
     */
    private function threadOf(bool $writeNode): int
    {
        $rows = DB::selectQuery('SELECT CONNECTION_ID() AS `id`;', '', $writeNode);

        return (int)$rows[0]['id'];
    }

    /**
     * Let the server close a connection, as it does after wait_timeout or a restart.
     */
    private function killThread(int $thread): void
    {
        $observer = $this->openObserver();
        mysqli_query($observer, 'KILL ' . $thread . ';');
        mysqli_close($observer);

        $this->assertSame([], $this->openThreadsAmong([$thread]));
    }

    /**
     * Open a connection of its own, next to the ones DB holds.
     */
    private function openObserver(): mysqli
    {
        $config = $this->databaseConfig();
        $observer = mysqli_connect(
            (string)$config['host'],
            (string)$config['username'],
            (string)$config['password'],
            (string)$config['database'],
            (int)$config['port']
        );
        $this->assertInstanceOf(mysqli::class, $observer);

        return $observer;
    }

    /**
     * Return the given thread ids that the server still lists, seen from a separate connection.
     *
     * A closed connection can linger in the process list for a moment, so this waits up to one second
     * for the list to settle.
     *
     * @param int[] $threads
     *
     * @return int[]
     */
    private function openThreadsAmong(array $threads): array
    {
        $observer = $this->openObserver();

        $open = $threads;
        for ($attempt = 0; $attempt < 10 && [] !== $open; $attempt++) {
            if ($attempt > 0) {
                usleep(100000);
            }
            $result = mysqli_query(
                $observer,
                'SELECT `ID` FROM `information_schema`.`PROCESSLIST` WHERE `ID` IN (' . implode(',', array_map('intval', $threads)) . ');'
            );
            $this->assertInstanceOf(mysqli_result::class, $result);
            $open = array_map('intval', array_column(mysqli_fetch_all($result, MYSQLI_ASSOC), 'ID'));
        }
        mysqli_close($observer);

        sort($open);

        return $open;
    }

    /**
     * Return CI database settings or skip when no integration database is configured.
     *
     * @return array<string, string|int>
     */
    private function databaseConfig(): array
    {
        $host = getenv('DB_TEST_HOST');
        if (false === $host || '' === $host) {
            $this->markTestSkipped('DB_TEST_HOST is not configured.');
        }

        return [
            'host' => $host,
            'username' => $this->environmentValueOrDefault('DB_TEST_USER', 'db_test'),
            'password' => $this->environmentValueOrDefault('DB_TEST_PASSWORD', 'db_test'),
            'database' => $this->environmentValueOrDefault('DB_TEST_DATABASE', 'db_test'),
            'port' => (int)$this->environmentValueOrDefault('DB_TEST_PORT', '3306'),
        ];
    }

    /**
     * Return an environment value unless it is absent or empty.
     */
    private function environmentValueOrDefault(string $name, string $default): string
    {
        $value = getenv($name);
        if (false === $value || '' === $value) {
            return $default;
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace test\php\phpunit\TiMMiT\MySQL;

use TiMMiT\MySQL\DB;
use test\php\phpunit\BaseTestCase;

/**
 * Verifies that insertQuery() returns the id from the connection that ran the insert.
 */
class DBInsertIdIntegrationTest extends BaseTestCase
{
    /* A real table, because a temporary table is only visible to the connection that created it. */
    private const TABLE = 'db_insert_id_test';

    protected function tearDown(): void
    {
        if (DB::ping()) {
            DB::updateQuery('DROP TABLE IF EXISTS `' . self::TABLE . '`;');
        }
        DB::disconnect(true);
        DB::$fallbackActive = false;
        DB::$writeActive = false;

        parent::tearDown();
    }

    /**
     * Without a write node the insert and its id both come from the primary connection.
     */
    public function testInsertQueryReturnsTheIdOnThePrimaryConnection(): void
    {
        DB::loadConfig($this->databaseConfig());
        DB::connect();

        $this->assertInsertIds();
    }

    /**
     * With a write node the insert runs there, so its id has to be read there as well.
     */
    public function testInsertQueryReturnsTheIdFromTheWriteNode(): void
    {
        $primary = $this->databaseConfig();
        $write = $primary;
        $write['connection_role'] = 'write';

        DB::loadConfig($primary, $write);
        DB::connect();
        $this->assertTrue(DB::$writeActive);

        $this->assertInsertIds();
    }

    /**
     * Assert that two inserts return consecutive auto-increment ids.
     */
    private function assertInsertIds(): void
    {
        DB::updateQuery('DROP TABLE IF EXISTS `' . self::TABLE . '`;');
        DB::updateQuery(
            'CREATE TABLE `' . self::TABLE . '` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . '`value` INT NOT NULL, '
            . 'PRIMARY KEY (`id`)'
            . ');'
        );

        $this->assertSame(1, (int)DB::insertQuery('INSERT INTO `' . self::TABLE . '` (`value`) VALUES (10);'));
        $this->assertSame(2, (int)DB::insertQuery('INSERT INTO `' . self::TABLE . '` (`value`) VALUES (20);'));
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

<?php

declare(strict_types=1);

namespace test\php\phpunit\TiMMiT\MySQL;

use TiMMiT\MySQL\DB;
use test\php\phpunit\BaseTestCase;

/**
 * Verifies affected-row semantics against a real MySQL-compatible database.
 */
class DBAffectedRowsIntegrationTest extends BaseTestCase
{
    private const TABLE = 'db_affected_rows_test';

    protected function tearDown(): void
    {
        DB::disconnect(true);
        DB::$fallbackActive = false;
        DB::$writeActive = false;

        parent::tearDown();
    }

    /**
     * Existing consumers keep changed-row semantics unless they opt in.
     *
     * @dataProvider foundRowsProvider
     */
    public function testAffectedRowsFollowConnectionConfiguration(bool $useFoundRows, int $expectedNoOpRows): void
    {
        DB::loadConfig($this->databaseConfig(), useFoundRows: $useFoundRows);
        DB::connect();

        $this->assertAffectedRowSemantics($expectedNoOpRows);
    }

    /**
     * A separate write connection receives the same found-rows flag.
     */
    public function testFoundRowsApplyToSeparateWriteConnection(): void
    {
        $primary = $this->databaseConfig();
        $write = $primary;
        $write['connection_role'] = 'write';

        DB::loadConfig($primary, $write, useFoundRows: true);
        DB::connect();

        $this->assertTrue(DB::$writeActive);
        $this->assertAffectedRowSemantics(1);
    }

    /**
     * A fallback connection receives the same found-rows flag.
     */
    public function testFoundRowsApplyToFallbackConnection(): void
    {
        $primary = $this->databaseConfig();
        $primary['database'] .= '_missing';

        DB::loadConfig($primary, fallback: $this->databaseConfig(), useFoundRows: true);
        DB::connect();

        $this->assertTrue(DB::$fallbackActive);
        $this->assertAffectedRowSemantics(1);
    }

    /**
     * @return array<string, array{0: bool, 1: int}>
     */
    public static function foundRowsProvider(): array
    {
        return [
            'changed rows (default)' => [false, 0],
            'matched rows (opt-in)' => [true, 1],
        ];
    }

    /**
     * Assert UPDATE and upsert affected-row values on the active write connection.
     */
    private function assertAffectedRowSemantics(int $expectedNoOpRows): void
    {
        DB::updateQuery('DROP TEMPORARY TABLE IF EXISTS `' . self::TABLE . '`;');
        DB::updateQuery(
            'CREATE TEMPORARY TABLE `' . self::TABLE . '` ('
            . '`id` INT UNSIGNED NOT NULL, '
            . '`value` INT NOT NULL, '
            . 'PRIMARY KEY (`id`)'
            . ');'
        );

        DB::insertQuery('INSERT INTO `' . self::TABLE . '` (`id`, `value`) VALUES (1, 10);');

        DB::updateQuery('UPDATE `' . self::TABLE . '` SET `value` = 11 WHERE `id` = 1;');
        $this->assertSame(1, DB::getAffectedRows());

        DB::updateQuery('UPDATE `' . self::TABLE . '` SET `value` = 11 WHERE `id` = 1;');
        $this->assertSame($expectedNoOpRows, DB::getAffectedRows());

        DB::updateQuery('UPDATE `' . self::TABLE . '` SET `value` = 11 WHERE `id` = 999;');
        $this->assertSame(0, DB::getAffectedRows());

        DB::insertQuery(
            'INSERT INTO `' . self::TABLE . '` (`id`, `value`) VALUES (2, 20) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);'
        );
        $this->assertSame(1, DB::getAffectedRows());

        DB::insertQuery(
            'INSERT INTO `' . self::TABLE . '` (`id`, `value`) VALUES (2, 21) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);'
        );
        $this->assertSame(2, DB::getAffectedRows());

        DB::insertQuery(
            'INSERT INTO `' . self::TABLE . '` (`id`, `value`) VALUES (2, 21) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);'
        );
        $this->assertSame($expectedNoOpRows, DB::getAffectedRows());
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

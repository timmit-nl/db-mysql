<?php

declare(strict_types=1);

namespace test\php\phpunit\TiMMiT\MySQL;

use TiMMiT\MySQL\DB;
use test\php\phpunit\BaseTestCase;

/**
 * Tests for the index handling in DB::dbDelta().
 *
 * These cover the query building performed by DB::buildAddIndexQuery(), which decides whether a
 * pre-existing key must be dropped (in the same ALTER statement) before it is re-added. The full
 * dbDelta() flow itself cannot be unit tested here because it requires a live database connection.
 */
class DbDeltaTest extends BaseTestCase
{
    /**
     * A new index (whose name does not exist yet) must be added with a plain ADD and no DROP.
     */
    public function testBuildAddIndexQueryAddsNewIndexWithoutDrop(): void
    {
        $result = $this->invokeStaticPrivateMethod(
            DB::class,
            'buildAddIndexQuery',
            ['my_table', 'KEY `idx_name` (`col_a`)', []]
        );

        $this->assertSame('ALTER TABLE `my_table` ADD KEY `idx_name` (`col_a`)', $result['query']);
        $this->assertSame('', $result['recreatedKey']);
    }

    /**
     * A changed composite key that already exists must be dropped and re-added in one statement.
     */
    public function testBuildAddIndexQueryDropsAndReaddsExistingCompositeKey(): void
    {
        $result = $this->invokeStaticPrivateMethod(
            DB::class,
            'buildAddIndexQuery',
            ['my_table', 'KEY `idx_name` (`col_a`,`col_b`)', ['idx_name' => true]]
        );

        $this->assertSame(
            'ALTER TABLE `my_table` DROP INDEX `idx_name`, ADD KEY `idx_name` (`col_a`,`col_b`)',
            $result['query']
        );
        $this->assertSame('idx_name', $result['recreatedKey']);
    }

    /**
     * An existing UNIQUE key must also be dropped first and reported as recreated.
     */
    public function testBuildAddIndexQueryDropsExistingUniqueKey(): void
    {
        $result = $this->invokeStaticPrivateMethod(
            DB::class,
            'buildAddIndexQuery',
            ['my_table', 'UNIQUE KEY `uniq_name` (`col_a`)', ['uniq_name' => true]]
        );

        $this->assertSame(
            'ALTER TABLE `my_table` DROP INDEX `uniq_name`, ADD UNIQUE KEY `uniq_name` (`col_a`)',
            $result['query']
        );
        $this->assertSame('uniq_name', $result['recreatedKey']);
    }

    /**
     * A PRIMARY KEY has no backtick name and must never trigger a DROP, even when a key named
     * "PRIMARY" is registered as existing.
     */
    public function testBuildAddIndexQueryNeverDropsPrimaryKey(): void
    {
        $result = $this->invokeStaticPrivateMethod(
            DB::class,
            'buildAddIndexQuery',
            ['my_table', 'PRIMARY KEY (`col_a`)', ['PRIMARY' => true]]
        );

        $this->assertSame('ALTER TABLE `my_table` ADD PRIMARY KEY (`col_a`)', $result['query']);
        $this->assertSame('', $result['recreatedKey']);
    }
}

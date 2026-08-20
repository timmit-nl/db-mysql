<?php

declare(strict_types=1);

namespace test\php\phpunit\TiMMiT\MySQL;

use TiMMiT\MySQL\DB;
use test\php\phpunit\BaseTestCase;

/**
 * Tests for the index handling in DB::dbDelta().
 *
 * These cover the query building performed by DB::buildAddIndexQuery(), which decides whether a
 * pre-existing key must be dropped (in the same ALTER statement) before it is re-added, and
 * DB::normalizeIndexDefinition(), which decides whether an existing index already matches the
 * CREATE TABLE definition. The full dbDelta() flow itself cannot be unit tested here because it
 * requires a live database connection.
 */
class DbDeltaTest extends BaseTestCase
{
    /**
     * Semantically identical index definitions must normalize to the same string.
     *
     * @dataProvider indexDefinitionMatchProvider
     *
     * @param string $existing The definition rebuilt from SHOW INDEX.
     * @param string $desired  The definition parsed from CREATE TABLE.
     * @param bool   $expected Whether the two definitions describe the same key.
     */
    public function testNormalizeIndexDefinition(string $existing, string $desired, bool $expected): void
    {
        $normalizedExisting = $this->invokeStaticPrivateMethod(
            DB::class,
            'normalizeIndexDefinition',
            [$existing]
        );
        $normalizedDesired = $this->invokeStaticPrivateMethod(
            DB::class,
            'normalizeIndexDefinition',
            [$desired]
        );

        $this->assertSame($expected, $normalizedExisting === $normalizedDesired);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function indexDefinitionMatchProvider(): array
    {
        return [
            'composite key with spaces after commas' => [
                'KEY `werknemer_dossier_id_volgnummer` (`werknemer_dossier_id`,`volgnummer`)',
                'KEY `werknemer_dossier_id_volgnummer` (`werknemer_dossier_id`, `volgnummer`)',
                true,
            ],
            'INDEX is a synonym of KEY' => [
                'KEY `idx_name` (`col_a`)',
                'INDEX `idx_name` (`col_a`)',
                true,
            ],
            'backticks are optional' => [
                'KEY `idx_name` (`col_a`,`col_b`)',
                'KEY idx_name (col_a, col_b)',
                true,
            ],
            'extra whitespace is ignored' => [
                'KEY `idx_name` (`col_a`,`col_b`)',
                'KEY  `idx_name`  ( `col_a` ,  `col_b` )',
                true,
            ],
            'USING BTREE is the default and is ignored' => [
                'KEY `idx_name` (`col_a`)',
                'KEY `idx_name` (`col_a`) USING BTREE',
                true,
            ],
            'ASC is the default and is ignored' => [
                'KEY `idx_name` (`col_a`,`col_b`)',
                'KEY `idx_name` (`col_a` ASC, `col_b` ASC)',
                true,
            ],
            'comparison is case insensitive' => [
                'KEY `idx_name` (`col_a`)',
                'key `IDX_NAME` (`COL_A`)',
                true,
            ],
            'added column is a real change' => [
                'KEY `idx_name` (`col_a`)',
                'KEY `idx_name` (`col_a`, `col_b`)',
                false,
            ],
            'UNIQUE vs KEY is a real change' => [
                'KEY `idx_name` (`col_a`)',
                'UNIQUE KEY `idx_name` (`col_a`)',
                false,
            ],
            'prefix length is a real change' => [
                'KEY `idx_name` (`col_a`(10))',
                'KEY `idx_name` (`col_a`)',
                false,
            ],
        ];
    }

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
     * The INDEX keyword is a synonym for KEY and must be handled the same way.
     */
    public function testBuildAddIndexQueryDropsExistingIndexKeyword(): void
    {
        $result = $this->invokeStaticPrivateMethod(
            DB::class,
            'buildAddIndexQuery',
            ['my_table', 'INDEX `idx_name` (`col_a`,`col_b`)', ['idx_name' => true]]
        );

        $this->assertSame(
            'ALTER TABLE `my_table` DROP INDEX `idx_name`, ADD INDEX `idx_name` (`col_a`,`col_b`)',
            $result['query']
        );
        $this->assertSame('idx_name', $result['recreatedKey']);
    }

    /**
     * A UNIQUE INDEX definition must also be recognised and dropped before re-adding.
     */
    public function testBuildAddIndexQueryDropsExistingUniqueIndexKeyword(): void
    {
        $result = $this->invokeStaticPrivateMethod(
            DB::class,
            'buildAddIndexQuery',
            ['my_table', 'UNIQUE INDEX `uniq_name` (`col_a`)', ['uniq_name' => true]]
        );

        $this->assertSame(
            'ALTER TABLE `my_table` DROP INDEX `uniq_name`, ADD UNIQUE INDEX `uniq_name` (`col_a`)',
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

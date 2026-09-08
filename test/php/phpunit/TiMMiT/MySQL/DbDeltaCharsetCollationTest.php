<?php

declare(strict_types=1);

namespace test\php\phpunit\TiMMiT\MySQL;

use TiMMiT\MySQL\DB;
use test\php\phpunit\BaseTestCase;

/**
 * Tests for charset and collation detection in DB::dbDelta().
 *
 * Table-level DEFAULT CHARSET= / COLLATE= comparison and column-level COLLATE
 * (no equals) comparison live in private helpers so they can be exercised without
 * a live database. The full applyDbDelta() flow still needs a connection.
 */
class DbDeltaCharsetCollationTest extends BaseTestCase
{
    private function parseTableCharsetAndCollation(string $sql): array
    {
        return $this->invokeStaticPrivateMethod(
            DB::class,
            'parseTableCharsetAndCollation',
            [$sql]
        );
    }

    private function parseColumnCollation(string $columnDef): ?string
    {
        return $this->invokeStaticPrivateMethod(
            DB::class,
            'parseColumnCollation',
            [$columnDef]
        );
    }

    private function columnCollationDiffers(?string $desired, ?string $current): bool
    {
        return $this->invokeStaticPrivateMethod(
            DB::class,
            'columnCollationDiffers',
            [$desired, $current]
        );
    }

    private function extractCreateTableSql(array $createRows): string
    {
        return $this->invokeStaticPrivateMethod(
            DB::class,
            'extractCreateTableSql',
            [$createRows]
        );
    }

    /**
     * @return array{query: string, charsetMessage: string, collationMessage: string}
     */
    private function buildTableCharsetCollationDelta(
        string $table,
        string $desiredCharset,
        string $desiredCollation,
        string $currentCreateSql
    ): array {
        return $this->invokeStaticPrivateMethod(
            DB::class,
            'buildTableCharsetCollationDelta',
            [$table, $desiredCharset, $desiredCollation, $currentCreateSql]
        );
    }

    /**
     * @dataProvider tableCharsetAndCollationParseProvider
     *
     * @param ?string $expectedCharset
     * @param ?string $expectedCollation
     */
    public function testParseTableCharsetAndCollation(
        string $sql,
        ?string $expectedCharset,
        ?string $expectedCollation
    ): void {
        $parsed = $this->parseTableCharsetAndCollation($sql);

        $this->assertSame($expectedCharset, $parsed['charset']);
        $this->assertSame($expectedCollation, $parsed['collation']);
    }

    /**
     * @return array<string, array{string, ?string, ?string}>
     */
    public static function tableCharsetAndCollationParseProvider(): array
    {
        $create = static function (string $suffix, string $columns = '`id` int NOT NULL'): string {
            return "CREATE TABLE `items` (\n  {$columns}\n) ENGINE=InnoDB {$suffix}";
        };

        return [
            'standard SHOW CREATE TABLE suffix' => [
                $create('DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
                'utf8mb4',
                'utf8mb4_unicode_ci',
            ],
            'extra spaces around equals' => [
                $create('DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci'),
                'utf8mb4',
                'utf8mb4_general_ci',
            ],
            'keywords are matched case-insensitively' => [
                $create('default charset=LATIN1 collate=LATIN1_SWEDISH_CI'),
                'LATIN1',
                'LATIN1_SWEDISH_CI',
            ],
            'utf8mb3 table default' => [
                $create('DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci'),
                'utf8mb3',
                'utf8mb3_general_ci',
            ],
            'binary collation' => [
                $create('DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'),
                'utf8mb4',
                'utf8mb4_bin',
            ],
            'charset only is not enough to plan a CONVERT' => [
                $create('DEFAULT CHARSET=utf8mb4'),
                'utf8mb4',
                null,
            ],
            'collation only is not enough to plan a CONVERT' => [
                $create('COLLATE=utf8mb4_unicode_ci'),
                null,
                'utf8mb4_unicode_ci',
            ],
            'empty SQL' => [
                '',
                null,
                null,
            ],
            'CREATE TABLE without table options' => [
                "CREATE TABLE `items` (\n  `id` int NOT NULL\n)",
                null,
                null,
            ],
            'CHARACTER SET without DEFAULT CHARSET= is not parsed' => [
                $create('DEFAULT CHARACTER SET utf8mb4 COLLATE=utf8mb4_unicode_ci'),
                null,
                'utf8mb4_unicode_ci',
            ],
            'table COLLATE without equals is not parsed' => [
                $create('DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci'),
                'utf8mb4',
                null,
            ],
            'column COLLATE without equals is ignored at table level' => [
                $create(
                    'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                    '`name` varchar(255) COLLATE utf8mb4_bin NOT NULL'
                ),
                'utf8mb4',
                'utf8mb4_unicode_ci',
            ],
            'last COLLATE= wins when several are present' => [
                $create(
                    'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                    '`name` varchar(255) COLLATE=utf8mb4_bin NOT NULL'
                ),
                'utf8mb4',
                'utf8mb4_unicode_ci',
            ],
            'COLLATE= before DEFAULT CHARSET still uses the last COLLATE=' => [
                "CREATE TABLE `items` (\n  `name` varchar(255) COLLATE=utf8mb4_bin\n) COLLATE=utf8mb4_general_ci DEFAULT CHARSET=utf8mb4",
                'utf8mb4',
                'utf8mb4_general_ci',
            ],
            'first DEFAULT CHARSET= is used when several are present' => [
                'DEFAULT CHARSET=latin1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                'latin1',
                'utf8mb4_unicode_ci',
            ],
            'CHARSET= without DEFAULT is not parsed' => [
                $create('CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
                null,
                'utf8mb4_unicode_ci',
            ],
        ];
    }

    /**
     * @dataProvider columnCollationParseProvider
     */
    public function testParseColumnCollation(string $columnDef, ?string $expected): void
    {
        $this->assertSame($expected, $this->parseColumnCollation($columnDef));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function columnCollationParseProvider(): array
    {
        return [
            'typical varchar column' => [
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                'utf8mb4_unicode_ci',
            ],
            'collate keyword is case-insensitive' => [
                '`name` varchar(255) collate UTF8MB4_BIN',
                'UTF8MB4_BIN',
            ],
            'extra whitespace before the name' => [
                '`name` varchar(255) COLLATE    utf8mb4_general_ci',
                'utf8mb4_general_ci',
            ],
            'text column' => [
                '`body` text COLLATE utf8mb4_0900_ai_ci',
                'utf8mb4_0900_ai_ci',
            ],
            'integer column has no collation' => [
                '`id` int NOT NULL',
                null,
            ],
            'empty definition' => [
                '',
                null,
            ],
            'COLLATE= is table syntax and is not a column collation' => [
                '`name` varchar(255) COLLATE=utf8mb4_unicode_ci',
                null,
            ],
            'CHARACTER SET without COLLATE is not a collation' => [
                '`name` varchar(255) CHARACTER SET utf8mb4',
                null,
            ],
            'first COLLATE name is used when several appear on one line' => [
                '`name` varchar(255) COLLATE utf8mb4_bin COLLATE utf8mb4_unicode_ci',
                'utf8mb4_bin',
            ],
        ];
    }

    /**
     * @dataProvider columnCollationDiffersProvider
     */
    public function testColumnCollationDiffers(?string $desired, ?string $current, bool $expected): void
    {
        $this->assertSame($expected, $this->columnCollationDiffers($desired, $current));
    }

    /**
     * @return array<string, array{?string, ?string, bool}>
     */
    public static function columnCollationDiffersProvider(): array
    {
        return [
            'identical' => ['utf8mb4_unicode_ci', 'utf8mb4_unicode_ci', false],
            'case-insensitive match' => ['UTF8MB4_UNICODE_CI', 'utf8mb4_unicode_ci', false],
            'different collations' => ['utf8mb4_unicode_ci', 'utf8mb4_general_ci', true],
            'utf8mb4 vs utf8mb3' => ['utf8mb4_general_ci', 'utf8mb3_general_ci', true],
            'desired missing is not a change' => [null, 'utf8mb4_unicode_ci', false],
            'current missing is not a change' => ['utf8mb4_unicode_ci', null, false],
            'both missing is not a change' => [null, null, false],
            'empty string differs from a real collation' => ['', 'utf8mb4_unicode_ci', true],
            'empty vs empty is not a change' => ['', '', false],
        ];
    }

    /**
     * @dataProvider extractCreateTableSqlProvider
     *
     * @param mixed[] $createRows
     */
    public function testExtractCreateTableSql(array $createRows, string $expected): void
    {
        $this->assertSame($expected, $this->extractCreateTableSql($createRows));
    }

    /**
     * @return array<string, array{mixed[], string}>
     */
    public static function extractCreateTableSqlProvider(): array
    {
        $sql = "CREATE TABLE `items` (\n  `id` int NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return [
            'standard SHOW CREATE TABLE row' => [
                [
                    [
                        'Table' => 'items',
                        'Create Table' => $sql,
                    ],
                ],
                $sql,
            ],
            'empty result set' => [
                [],
                '',
            ],
            'row without Create Table key' => [
                [
                    ['Table' => 'items'],
                ],
                '',
            ],
            'Create Table is an empty string' => [
                [
                    ['Create Table' => ''],
                ],
                '',
            ],
            'first row is not an array' => [
                ['not-an-array'],
                '',
            ],
            'later rows are ignored' => [
                [
                    ['Create Table' => 'first'],
                    ['Create Table' => 'second'],
                ],
                'first',
            ],
            'numeric Create Table is cast to string' => [
                [
                    ['Create Table' => 0],
                ],
                '0',
            ],
        ];
    }

    public function testNoDeltaWhenCharsetAndCollationMatch(): void
    {
        $current = $this->showCreateTableSql('utf8mb4', 'utf8mb4_unicode_ci');
        $delta = $this->buildTableCharsetCollationDelta(
            'items',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            $current
        );

        $this->assertSame('', $delta['query']);
        $this->assertSame('', $delta['charsetMessage']);
        $this->assertSame('', $delta['collationMessage']);
    }

    public function testNoDeltaWhenOnlyLetterCaseDiffers(): void
    {
        $current = $this->showCreateTableSql('UTF8MB4', 'UTF8MB4_UNICODE_CI');
        $delta = $this->buildTableCharsetCollationDelta(
            'items',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            $current
        );

        $this->assertSame('', $delta['query']);
        $this->assertSame('', $delta['charsetMessage']);
        $this->assertSame('', $delta['collationMessage']);
    }

    public function testCharsetOnlyChangeEmitsConvertAndCharsetMessage(): void
    {
        $current = $this->showCreateTableSql('latin1', 'utf8mb4_unicode_ci');
        $delta = $this->buildTableCharsetCollationDelta(
            'items',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            $current
        );

        $this->assertSame(
            'ALTER TABLE `items` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $delta['query']
        );
        $this->assertSame('Changed charset to utf8mb4', $delta['charsetMessage']);
        $this->assertSame('', $delta['collationMessage']);
    }

    public function testCollationOnlyChangeEmitsConvertAndCollationMessage(): void
    {
        $current = $this->showCreateTableSql('utf8mb4', 'utf8mb4_general_ci');
        $delta = $this->buildTableCharsetCollationDelta(
            'items',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            $current
        );

        $this->assertSame(
            'ALTER TABLE `items` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $delta['query']
        );
        $this->assertSame('', $delta['charsetMessage']);
        $this->assertSame('Changed collation to utf8mb4_unicode_ci', $delta['collationMessage']);
    }

    public function testBothCharsetAndCollationChange(): void
    {
        $current = $this->showCreateTableSql('latin1', 'latin1_swedish_ci');
        $delta = $this->buildTableCharsetCollationDelta(
            'items',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            $current
        );

        $this->assertSame(
            'ALTER TABLE `items` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $delta['query']
        );
        $this->assertSame('Changed charset to utf8mb4', $delta['charsetMessage']);
        $this->assertSame('Changed collation to utf8mb4_unicode_ci', $delta['collationMessage']);
    }

    public function testUnknownCurrentTableIsTreatedAsAChange(): void
    {
        $delta = $this->buildTableCharsetCollationDelta(
            'items',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            ''
        );

        $this->assertSame(
            'ALTER TABLE `items` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $delta['query']
        );
        $this->assertSame('Changed charset to utf8mb4', $delta['charsetMessage']);
        $this->assertSame('Changed collation to utf8mb4_unicode_ci', $delta['collationMessage']);
    }

    public function testCurrentCharsetWithoutCollationIsACollationChange(): void
    {
        $delta = $this->buildTableCharsetCollationDelta(
            'items',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            'DEFAULT CHARSET=utf8mb4'
        );

        $this->assertSame(
            'ALTER TABLE `items` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $delta['query']
        );
        $this->assertSame('', $delta['charsetMessage']);
        $this->assertSame('Changed collation to utf8mb4_unicode_ci', $delta['collationMessage']);
    }

    public function testCurrentCollationWithoutCharsetIsACharsetChange(): void
    {
        $delta = $this->buildTableCharsetCollationDelta(
            'items',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            'COLLATE=utf8mb4_unicode_ci'
        );

        $this->assertSame(
            'ALTER TABLE `items` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $delta['query']
        );
        $this->assertSame('Changed charset to utf8mb4', $delta['charsetMessage']);
        $this->assertSame('', $delta['collationMessage']);
    }

    public function testConvertQueryKeepsDesiredSpellingAndBackticksTheTable(): void
    {
        $current = $this->showCreateTableSql('latin1', 'latin1_swedish_ci');
        $delta = $this->buildTableCharsetCollationDelta(
            'Order Items',
            'UTF8MB4',
            'UTF8MB4_UNICODE_CI',
            $current
        );

        $this->assertSame(
            'ALTER TABLE `Order Items` CONVERT TO CHARACTER SET UTF8MB4 COLLATE UTF8MB4_UNICODE_CI',
            $delta['query']
        );
        $this->assertSame('Changed charset to UTF8MB4', $delta['charsetMessage']);
        $this->assertSame('Changed collation to UTF8MB4_UNICODE_CI', $delta['collationMessage']);
    }

    public function testColumnCollateInShowCreateTableDoesNotCountAsTableCollation(): void
    {
        $current = <<<'SQL'
CREATE TABLE `items` (
  `name` varchar(255) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        $delta = $this->buildTableCharsetCollationDelta(
            'items',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            $current
        );

        $this->assertSame('', $delta['query']);
        $this->assertSame('', $delta['charsetMessage']);
        $this->assertSame('', $delta['collationMessage']);
    }

    public function testMismatchedColumnCollateEqualsDoesNotOverrideTableDefaultWhenLast(): void
    {
        $current = <<<'SQL'
CREATE TABLE `items` (
  `name` varchar(255) COLLATE=utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        $delta = $this->buildTableCharsetCollationDelta(
            'items',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            $current
        );

        $this->assertSame('', $delta['query'], 'The last COLLATE= is the table default and should match.');
    }

    public function testApplyDbDeltaDoesNotInspectCharsetWhenDesiredCollationIsMissing(): void
    {
        $parsed = $this->parseTableCharsetAndCollation(
            "CREATE TABLE `items` (\n  `id` int NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->assertSame('utf8mb4', $parsed['charset']);
        $this->assertNull($parsed['collation']);
    }

    /**
     * Column type changes stay independent of collation: a matching collation must not
     * be reported as a collation change.
     */
    public function testMatchingColumnCollationIsNotADifference(): void
    {
        $this->assertFalse(
            $this->columnCollationDiffers(
                $this->parseColumnCollation('`name` varchar(255) COLLATE utf8mb4_unicode_ci'),
                'utf8mb4_unicode_ci'
            )
        );
    }

    public function testColumnCollationChangeIsDetectedFromDefinitionAndShowFullColumns(): void
    {
        $desired = $this->parseColumnCollation('`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL');

        $this->assertSame('utf8mb4_unicode_ci', $desired);
        $this->assertTrue($this->columnCollationDiffers($desired, 'utf8mb4_general_ci'));
        $this->assertFalse($this->columnCollationDiffers($desired, null));
    }

    private function showCreateTableSql(string $charset, string $collation): string
    {
        return "CREATE TABLE `items` (\n  `id` int NOT NULL,\n  `name` varchar(255) COLLATE {$collation} DEFAULT NULL\n) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
    }
}

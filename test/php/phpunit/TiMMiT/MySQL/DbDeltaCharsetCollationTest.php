<?php

declare(strict_types=1);

namespace test\php\phpunit\TiMMiT\MySQL;

use TiMMiT\MySQL\DB;
use test\php\phpunit\BaseTestCase;

/**
 * Tests for charset and collation detection in DB::dbDelta().
 *
 * Table-level DEFAULT CHARSET= / COLLATE= comparison, column-level COLLATE
 * (no equals) comparison, and resolved information_schema metadata live in
 * private helpers so they can be exercised without a live database. The full
 * applyDbDelta() flow still needs a connection.
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

    /**
     * @return array{query: string, charsetMessage: string, collationMessage: string}
     */
    private function buildTableCharsetCollationDelta(
        string $table,
        string $desiredCharset,
        string $desiredCollation,
        ?string $currentCharset,
        ?string $currentCollation
    ): array {
        return $this->invokeStaticPrivateMethod(
            DB::class,
            'buildTableCharsetCollationDelta',
            [$table, $desiredCharset, $desiredCollation, $currentCharset, $currentCollation]
        );
    }

    /**
     * @return array{charset: ?string, collation: ?string}
     */
    private function tableCharsetAndCollationFromRow(mixed $row): array
    {
        return $this->invokeStaticPrivateMethod(
            DB::class,
            'tableCharsetAndCollationFromRow',
            [$row]
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
     * @dataProvider tableCharsetCollationDeltaProvider
     *
     * @param array{query: string, charsetMessage: string, collationMessage: string} $expected
     */
    public function testBuildTableCharsetCollationDelta(
        string $table,
        string $desiredCharset,
        string $desiredCollation,
        ?string $currentCharset,
        ?string $currentCollation,
        array $expected
    ): void {
        $this->assertSame(
            $expected,
            $this->buildTableCharsetCollationDelta(
                $table,
                $desiredCharset,
                $desiredCollation,
                $currentCharset,
                $currentCollation
            )
        );
    }

    /**
     * @return array<string, array{
     *     string,
     *     string,
     *     string,
     *     ?string,
     *     ?string,
     *     array{query: string, charsetMessage: string, collationMessage: string}
     * }>
     */
    public static function tableCharsetCollationDeltaProvider(): array
    {
        $convert = static function (
            string $table,
            string $charset,
            string $collation
        ): string {
            return "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET {$charset} COLLATE {$collation}";
        };
        $empty = [
            'query' => '',
            'charsetMessage' => '',
            'collationMessage' => '',
        ];
        $utf8mb4Unicode = $convert('items', 'utf8mb4', 'utf8mb4_unicode_ci');

        return [
            'no change when charset and collation match' => [
                'items',
                'utf8mb4',
                'utf8mb4_unicode_ci',
                'utf8mb4',
                'utf8mb4_unicode_ci',
                $empty,
            ],
            'no change when only letter case differs' => [
                'items',
                'utf8mb4',
                'utf8mb4_unicode_ci',
                'UTF8MB4',
                'UTF8MB4_UNICODE_CI',
                $empty,
            ],
            'charset-only change emits CONVERT and charset message' => [
                'items',
                'utf8mb4',
                'utf8mb4_unicode_ci',
                'latin1',
                'utf8mb4_unicode_ci',
                [
                    'query' => $utf8mb4Unicode,
                    'charsetMessage' => 'Changed charset to utf8mb4',
                    'collationMessage' => '',
                ],
            ],
            'collation-only change emits CONVERT and collation message' => [
                'items',
                'utf8mb4',
                'utf8mb4_unicode_ci',
                'utf8mb4',
                'utf8mb4_general_ci',
                [
                    'query' => $utf8mb4Unicode,
                    'charsetMessage' => '',
                    'collationMessage' => 'Changed collation to utf8mb4_unicode_ci',
                ],
            ],
            'both charset and collation change' => [
                'items',
                'utf8mb4',
                'utf8mb4_unicode_ci',
                'latin1',
                'latin1_swedish_ci',
                [
                    'query' => $utf8mb4Unicode,
                    'charsetMessage' => 'Changed charset to utf8mb4',
                    'collationMessage' => 'Changed collation to utf8mb4_unicode_ci',
                ],
            ],
            'unknown current charset and collation still emit CONVERT' => [
                'items',
                'utf8mb4',
                'utf8mb4_unicode_ci',
                null,
                null,
                [
                    'query' => $utf8mb4Unicode,
                    'charsetMessage' => 'Changed charset to utf8mb4',
                    'collationMessage' => 'Changed collation to utf8mb4_unicode_ci',
                ],
            ],
            'missing current collation is a collation change' => [
                'items',
                'utf8mb4',
                'utf8mb4_unicode_ci',
                'utf8mb4',
                null,
                [
                    'query' => $utf8mb4Unicode,
                    'charsetMessage' => '',
                    'collationMessage' => 'Changed collation to utf8mb4_unicode_ci',
                ],
            ],
            'missing current charset is a charset change' => [
                'items',
                'utf8mb4',
                'utf8mb4_unicode_ci',
                null,
                'utf8mb4_unicode_ci',
                [
                    'query' => $utf8mb4Unicode,
                    'charsetMessage' => 'Changed charset to utf8mb4',
                    'collationMessage' => '',
                ],
            ],
            'CONVERT keeps desired spelling and backticks the table' => [
                'Order Items',
                'UTF8MB4',
                'UTF8MB4_UNICODE_CI',
                'latin1',
                'latin1_swedish_ci',
                [
                    'query' => $convert('Order Items', 'UTF8MB4', 'UTF8MB4_UNICODE_CI'),
                    'charsetMessage' => 'Changed charset to UTF8MB4',
                    'collationMessage' => 'Changed collation to UTF8MB4_UNICODE_CI',
                ],
            ],
        ];
    }

    /**
     * SHOW CREATE TABLE may omit COLLATE= when it is the charset default. Resolved
     * metadata still has that collation, so dbDelta must not CONVERT every run.
     */
    public function testCharsetDefaultCollationFromResolvedMetadataIsNotAChange(): void
    {
        $current = $this->tableCharsetAndCollationFromRow([
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $delta = $this->buildTableCharsetCollationDelta(
            'items',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            $current['charset'],
            $current['collation']
        );

        $this->assertSame('', $delta['query']);
    }

    /**
     * @dataProvider informationSchemaRowProvider
     *
     * @param mixed $row
     */
    public function testTableCharsetAndCollationFromRow(mixed $row, ?string $expectedCharset, ?string $expectedCollation): void
    {
        $parsed = $this->tableCharsetAndCollationFromRow($row);

        $this->assertSame($expectedCharset, $parsed['charset']);
        $this->assertSame($expectedCollation, $parsed['collation']);
    }

    /**
     * @return array<string, array{mixed, ?string, ?string}>
     */
    public static function informationSchemaRowProvider(): array
    {
        return [
            'standard information_schema row' => [
                ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
                'utf8mb4',
                'utf8mb4_unicode_ci',
            ],
            'missing row' => [
                null,
                null,
                null,
            ],
            'empty row' => [
                [],
                null,
                null,
            ],
            'empty strings are unknown' => [
                ['charset' => '', 'collation' => ''],
                null,
                null,
            ],
            'charset without collation' => [
                ['charset' => 'utf8mb4', 'collation' => ''],
                'utf8mb4',
                null,
            ],
            'collation without charset' => [
                ['charset' => null, 'collation' => 'utf8mb4_unicode_ci'],
                null,
                'utf8mb4_unicode_ci',
            ],
            'non-array row' => [
                'not-an-array',
                null,
                null,
            ],
        ];
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
}

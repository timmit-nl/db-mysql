<?php

declare(strict_types=1);

namespace test\php\phpunit\TiMMiT\MySQL;

use ReflectionClass;
use TiMMiT\MySQL\DB;
use test\php\phpunit\BaseTestCase;

class DBAffectedRowsTest extends BaseTestCase
{
    /**
     * The affected-row API must remain public and use the write connection by default.
     */
    public function testGetAffectedRowsApiContract(): void
    {
        $reflection = new ReflectionClass(DB::class);
        $method = $reflection->getMethod('getAffectedRows');
        $parameters = $method->getParameters();

        $this->assertTrue($method->isPublic());
        $this->assertSame('int', (string)$method->getReturnType());
        $this->assertCount(1, $parameters);
        $this->assertSame('writeNode', $parameters[0]->getName());
        $this->assertTrue($parameters[0]->getDefaultValue());
    }
}

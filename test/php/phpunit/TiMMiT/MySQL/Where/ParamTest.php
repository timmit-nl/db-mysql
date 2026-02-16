<?php

namespace test\php\phpunit\TiMMiT\MySQL\Where;

use TiMMiT\MySQL\Where\Param;

use test\php\phpunit\BaseTestCase;

/**
 * PHPUnit class voor de Params
 *
 * De class bestaat uit de volgende tests:
 * - Of de functies die samenhangen met het Param object allemaal een test hebben als ze getest kunnen worden
 * - Of het Param object oude functies is verloren
 * - Of het Param object nieuwe functies heeft gekregen
 */
class ParamTest extends BaseTestCase
{
    /** @var string[] All the base fields available in the Param object */
    private array $baseParamFields = [
        '__construct',
        '__toString',
        'escapeSpecialCharacters',
        'table',
        'is',
        'isNot',
        'greaterOrEqual',
        'greater',
        'lowerOrEqual',
        'lower',
        'inArray',
        'notInArray',
        'inQuery',
        'notInQuery',
        'regexMatch',
        'betweenRange',
        'valueToString',
        'getFieldName',
        'getOperator',
        'getValue'
    ];

    /**
     * Test whether the functions within the Param class have tests in this test Class
     */
    public function testDoFunctionsHaveTests()
    {
        $cannotTest = ['__construct', '__toString', 'valueToString'];
        foreach ($this->baseParamFields as $function) {
            if (!in_array($function, $cannotTest)) {
                $testFunctionName = 'test' . ucfirst($function);
                $this->assertTrue(method_exists($this, $testFunctionName), "Function $function() does not have a test");
            }
        }
    }

    /**
     * Test whether the Param object fields have changed, added or removed
     */
    public function testCheckParamFields()
    {
        $param = new Param('');
        $classInformation = new \ReflectionClass($param);
        $paramFields = $classInformation->getMethods();

        /* Make an array of the 'new' param object field names */
        $currentParamFieldsNames = [];
        foreach ($paramFields as $fieldDetails) {
            foreach ($fieldDetails as $label => $methodName) {
                if ('name' === $label) {
                    $currentParamFieldsNames[] = $methodName;
                }
            }
        }

        /* The base and current fields should be exactly the same */
        $missingInCurrent = array_diff($this->baseParamFields, $currentParamFieldsNames);
        $extraInCurrent = array_diff($currentParamFieldsNames, $this->baseParamFields);

        $this->assertTrue(count($missingInCurrent) === 0, 'Er is een field in het Param object weggehaald. Verwijder de test(s) voor: ' . implode(', ', $missingInCurrent));
        $this->assertTrue(count($extraInCurrent) === 0, 'Er is een field aan het Param object toegevoegd. Maak een test voor: ' . implode(', ', $extraInCurrent));
    }

    /**
     * Test the behavior of the getFieldName method when retrieving the field name.
     */
    public function testGetFieldName()
    {
        $param = new Param('testField');
        $fieldNameResult = $param->getFieldName();

        $this->assertSame($fieldNameResult, 'testField', 'Param object does not correctly return the field name');
    }

    /**
     * Test the behavior of the getValue method when retrieving the value.
     */
    public function testGetValue()
    {
        $param = new Param('');
        $param->notInArray([4, 5, 6]);
        $valueResult = $param->getValue();

        $this->assertSame($valueResult, [4, 5, 6], 'Param object does not correctly return the value');
    }

    /**
     * Test the behavior of the getOperator method when retrieving the operator.
     */
    public function testGetOperator()
    {
        $param = new Param('');
        $param->inQuery('SELECT id FROM users');
        $operatorResult = $param->getOperator();

        $this->assertSame($operatorResult, 'IN QUERY', 'Param object does not correctly return the operator');
    }

    /**
     * Test the default behavior of the escapeSpecialCharacters method.
     */
    public function testEscapeSpecialCharacters()
    {
        $param = new Param('');
        $characterResult = $this->getPrivateProperty($param, 'escapeSpecialCharacters');
        $this->assertSame($characterResult, true, 'The default changed from "true"');
    }

    /**
     * Test the behavior of the escapeSpecialCharacters method method when passing true.
     */
    public function testEscapeSpecialCharactersTrue()
    {
        $param = new Param('');
        $param->escapeSpecialCharacters(true);
        $characterResult = $this->getPrivateProperty($param, 'escapeSpecialCharacters');
        $this->assertSame($characterResult, true, 'Param object does not return true');
    }

    /**
     * Test the behavior of the escapeSpecialCharacters method when passing false.
     */
    public function testEscapeSpecialCharactersFalse()
    {
        $param = new Param('');
        $param->escapeSpecialCharacters(false);
        $characterResult = $this->getPrivateProperty($param, 'escapeSpecialCharacters');
        $this->assertSame($characterResult, false, 'Param object does not return false');
    }

    /**
     * Test the table method with various inputs.
     *
     * @dataProvider tableDataProvider
     */
    public function testTable($tableName, $expectedResult, $expectException = false)
    {
        $param = new Param('');

        if ($expectException) {
            $this->expectException(\TypeError::class);
        }

        $param->table($tableName);

        /* If no exception is expected, assert that the table property is set correctly */
        if (!$expectException) {
            $tableResult = $this->getPrivateProperty($param, 'table');
            $this->assertSame($expectedResult, $tableResult, 'Param object does not correctly set the table property');
        }
    }

    /**
     * Data provider for testTable.
     *
     * @return array
     */
    public static function tableDataProvider()
    {
        return [
            /* Case 1: Valid table name */
            'Valid name' => ['administratie_users', 'administratie_users', false],

            /* Case 2: Empty string as table name */
            'Empty string' => ['', '', false],

            /* Case 3: Null value should throw TypeError */
            'Null value should throw TypeError' => [null, null, true],
        ];
    }

    /**
     * Test the is() method.
     */
    public function testIs()
    {
        $param = new Param('');
        $param->is('testValue');
        $valueResult = $param->getValue();
        $operatorResult = $param->getOperator();

        $this->assertSame($valueResult, 'testValue', 'Param object does not correctly set value for is()');
        $this->assertSame($operatorResult, '=', 'Param object does not correctly set operator = for is()');
    }

    /**
     * Test the isNot() method.
     */
    public function testIsNot()
    {
        $param = new Param('');
        $param->isNot('testValue');
        $valueResult = $param->getValue();
        $operatorResult = $param->getOperator();

        $this->assertSame($valueResult, 'testValue', 'Param object does not correctly set value for isNot()');
        $this->assertSame($operatorResult, '!=', 'Param object does not correctly set operator != for isNot()');
    }

    /**
     * Test the behavior of the greaterOrEqual method when setting a value.
     */
    public function testGreaterOrEqual()
    {
        $param = new Param('');
        $param->greaterOrEqual(5);
        $valueResult = $param->getValue();
        $operatorResult = $param->getOperator();

        $this->assertSame($valueResult, 5, 'Param object does not correctly set value for greaterOrEqual()');
        $this->assertSame($operatorResult, '>=', 'Param object does not correctly set operator >= for greaterOrEqual()');
    }

    /**
     * Test the behavior of the greater method when setting a value.
     */
    public function testGreater()
    {
        $param = new Param('');
        $param->greater(10);
        $valueResult = $param->getValue();
        $operatorResult = $param->getOperator();

        $this->assertSame($valueResult, 10, 'Param object does not correctly set value for greater()');
        $this->assertSame($operatorResult, '>', 'Param object does not correctly set operator > for greater()');
    }

    /**
     * Test the behavior of the lowerOrEqual method when setting a value.
     */
    public function testLowerOrEqual()
    {
        $param = new Param('');
        $param->lowerOrEqual(3);
        $valueResult = $param->getValue();
        $operatorResult = $param->getOperator();

        $this->assertSame($valueResult, 3, 'Param object does not correctly set value for lowerOrEqual()');
        $this->assertSame($operatorResult, '<=', 'Param object does not correctly set operator <= for lowerOrEqual()');
    }

    /**
     * Test the behavior of the lower method when setting a value.
     */
    public function testLower()
    {
        $param = new Param('');
        $param->lower(10);
        $valueResult = $param->getValue();
        $operatorResult = $param->getOperator();

        $this->assertSame($valueResult, 10, 'Param object does not correctly set value for lower()');
        $this->assertSame($operatorResult, '<', 'Param object does not correctly set operator < for lower()');
    }

    /**
     * Test the behavior of the inArray method when setting an array of values.
     */
    public function testInArray()
    {
        $param = new Param('');
        $param->inArray([1, 2, 3]);
        $valueResult = $param->getValue();
        $operatorResult = $param->getOperator();

        $this->assertSame($valueResult, [1, 2, 3], 'Param object does not correctly set value for inArray()');
        $this->assertSame($operatorResult, 'IN', 'Param object does not correctly set operator IN for inArray()');
    }

    /**
     * Test the behavior of the notInArray method when setting an array of values.
     */
    public function testNotInArray()
    {
        $param = new Param('');
        $param->notInArray([4, 5, 6]);
        $valueResult = $param->getValue();
        $operatorResult = $param->getOperator();

        $this->assertSame($valueResult, [4, 5, 6], 'Param object does not correctly set value for notInArray()');
        $this->assertSame($operatorResult, 'NOT IN', 'Param object does not correctly set operator NOT IN for notInArray()');
    }

    /**
     * Test the behavior of the inQuery method when setting a query string.
     */
    public function testInQuery()
    {
        $param = new Param('');
        $param->inQuery('SELECT id FROM users');
        $valueResult = $param->getValue();
        $operatorResult = $param->getOperator();

        $this->assertSame($valueResult, 'SELECT id FROM users', 'Param object does not correctly set value for inQuery()');
        $this->assertSame($operatorResult, 'IN QUERY', 'Param object does not correctly set operator inQuery for inQuery()');
    }

    /**
     * Test the behavior of the notInQuery method when setting a query string.
     */
    public function testNotInQuery()
    {
        $param = new Param('');
        $param->notInQuery('SELECT id FROM users');
        $valueResult = $param->getValue();
        $operatorResult = $param->getOperator();

        $this->assertSame($valueResult, 'SELECT id FROM users', 'Param object does not correctly set value for notInQuery()');
        $this->assertSame($operatorResult, 'NOT IN QUERY', 'Param object does not correctly set operator for notInQuery()');
    }

    /**
     * Test the regexMatch() method.
     */
    public function testRegexMatch()
    {
        $param = new Param('group_id');
        $param->regexMatch('^abc');

        $valueResult = $param->getValue();
        $operatorResult = $param->getOperator();

        $this->assertSame($valueResult, '/^abc/', 'Param object does not correctly set value for regexMatch()');
        $this->assertSame($operatorResult, 'REGEXP', 'Param object does not correctly set operator REGEXP for regexMatch()');
    }

    /**
     * Test the betweenRange() method.
     */
    public function testBetweenRange()
    {
        $param = new Param('group_id');
        $param->betweenRange('a', 'z');

        $valueResult = $param->getValue();
        $value2Result = $this->getPrivateProperty($param, 'value2');
        $operatorResult = $param->getOperator();

        $this->assertSame($valueResult, 'a', 'Param object does not correctly set value for betweenRange()');
        $this->assertSame($value2Result, 'z', 'Param object does not correctly set value2 for betweenRange()');
        $this->assertSame($operatorResult, 'BETWEEN', 'Param object does not correctly set operator BETWEEN for betweenRange()');
    }

    /**
     * Test the valueToString method with various types of values.
     *
     * @dataProvider valueToStringDataProvider
     */
    public function testValueToString($value, $operatorMethod, $expectedResult, $expectedException = null)
    {
        $param = new Param('');

        if ($expectedException) {
            $this->expectExceptionMessage($expectedException);
        }

        /* Set the operator using the provided method */
        $param->$operatorMethod($value);

        /* Invoke the valueToString method with the given value */
        $result = $this->invokePrivateMethod($param, 'valueToString', [$value, true]);

        /* Assert that the result matches the expected result */
        $this->assertSame($expectedResult, $result, "valueToString() does not correctly handle the test case with operator $operatorMethod");
    }

    /**
     * Data provider for testValueToString.
     *
     * @return array
     */
    public static function valueToStringDataProvider()
    {
        return [
            /* Case 0: NULL value with default operator */
            'NULL value with default operator' => [null, 'is', 'NULL'],

            /* Case 1: NULL value with invalid operator (expect exception) */
            'NULL value with invalid operator' => [null, 'lower', '', 'value NULL can only be used with = or !='],

            /* Case 2: String value, cannot be tested due to a required database connection */
            /* 'String value' => ['testString', 'is', "'testString'"], */

            /* Case 3: Array value, cannot be tested due to a required database connection */
            /* 'Array value' => [['value1', 'value2'], 'inArray', "('value1','value2')"], */

            /* Case 4: Boolean value (true) */
            'Boolean value' => [true, 'is', '1'],

            /* Case 5: Numeric value */
            'Numeric value' => [123, 'is', '123'],
        ];
    }
}

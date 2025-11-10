<?php

/**
 * A very simple class for using MySQL.
 *
 * PHP version 8.0+
 *
 * @package TiMMiT/DB-MySQL
 * @author  Tim Schoondergang <tim.schoondergang@TiMMiT.nl>
 */

declare(strict_types=1);

namespace TiMMiT\MySQL\Where;

use Stringable;

use TiMMiT\MySQL\DB;
use TiMMiT\MySQL\DBException;

/**
 * Where Param
 */
class Param implements Stringable
{
    /**
     * fieldname
     *
     * @var string
     */
    private string $field = '';

    /**
     * value
     *
     * @var mixed[]|bool|float|int|string|null
     */
    private mixed $value;

    /**
     * operator
     *
     * @var string
     */
    private string $operator = '=';

    /**
     * cleanOperator
     *
     * @var ?string
     */
    private ?string $cleanOperator = null;

    /**
     * escapeSpecialCharacters
     *
     * @var bool
     */
    private bool $escapeSpecialCharacters = true;

    /**
     * table where the field exists
     *
     * @var string|null
     */
    private ?string $table = null;

    /**
     * constructor
     *
     * @param string $field
     */
    public function __construct(string $field)
    {
        $this->field = $field;
    }

    /**
     * set escapeSpecialCharacters
     *
     * @param bool $escapeSpecialCharacters
     *
     * @return Param
     */
    public function escapeSpecialCharacters(bool $escapeSpecialCharacters = true): Param
    {
        $this->escapeSpecialCharacters = $escapeSpecialCharacters;
        return $this;
    }

    /**
     * set table variable
     *
     * @param string $table
     *
     * @return Param
     */
    public function table(string $table): Param
    {
        $this->table = $table;
        return $this;
    }

    /**
     * is =
     *
     * @param mixed $value
     *
     * @return Param
     */
    public function is($value): Param
    {
        $this->value = $value;
        $this->operator = '=';
        return $this;
    }

    /**
     * isNot !=
     *
     * @param mixed $value
     *
     * @return Param
     */
    public function isNot($value): Param
    {
        $this->value = $value;
        $this->operator = '!=';
        return $this;
    }

    /**
     * greaterOrEqual >=
     *
     * @param mixed $value
     *
     * @return Param
     */
    public function greaterOrEqual($value): Param
    {
        $this->value = $value;
        $this->operator = '>=';
        return $this;
    }

    /**
     * greater >
     *
     * @param mixed $value
     *
     * @return Param
     */
    public function greater($value): Param
    {
        $this->value = $value;
        $this->operator = '>';
        return $this;
    }

    /**
     * lower or equal <=
     *
     * @param mixed $value
     *
     * @return Param
     */
    public function lowerOrEqual($value): Param
    {
        $this->value = $value;
        $this->operator = '<=';
        return $this;
    }

    /**
     * lower <
     *
     * @param mixed $value
     *
     * @return Param
     */
    public function lower($value): Param
    {
        $this->value = $value;
        $this->operator = '<';
        return $this;
    }

    /**
     * in array
     *
     * @param int[]|float[]|string[] $value
     *
     * @return Param
     */
    public function inArray(array $value): Param
    {
        $this->value = $value;
        $this->operator = 'IN';
        return $this;
    }

    /**
     * not in array
     *
     * @param int[]|float[]|string[] $value
     *
     * @return Param
     */
    public function notInArray(array $value): Param
    {
        $this->value = $value;
        $this->operator = 'NOT IN';
        return $this;
    }

    /**
     * in query
     *
     * @param string $value
     *
     * @return Param
     */
    public function inQuery(string $value): Param
    {
        $this->value = $value;
        $this->operator = 'IN QUERY';
        return $this;
    }

    /**
     * not in query
     *
     * @param string $value
     *
     * @return Param
     */
    public function notInQuery(string $value): Param
    {
        $this->value = $value;
        $this->operator = 'NOT IN QUERY';
        return $this;
    }

    /**
     * valueToString
     *
     * @param mixed[]|bool|float|int|string|null $value
     * @param bool $changeOperator
     *
     * @return string
     *
     * @throws DBException Is NULL with other operator then = or !=.
     */
    private function valueToString(array|bool|float|int|string|null $value, bool $changeOperator = false): string
    {
        switch (strtolower(gettype($value))) {
            case 'null':
                switch ($this->operator) {
                    case '=':
                    case 'IS':
                        $operator = 'IS';
                        break;
                    case '!=':
                    case 'IS NOT':
                        $operator = 'IS NOT';
                        break;
                    default:
                        throw new DBException("value NULL can only be used with = or !=", 9910);
                }
                if ($this->operator != $operator && $changeOperator) {
                    $this->cleanOperator = $operator;
                }
                return "NULL";
            case 'string':
                if (is_string($value)) {
                    // if IN or NOT IN, don't escape string because it is SQL
                    switch (substr($this->operator, -5)) {
                        case 'QUERY':
                            $this->cleanOperator = substr($this->operator, 0, -6);
                            return "(" . rtrim($value, ";") . ")";
                        default:
                            $value = DB::clean4DB($value);
                            break;
                    }
                    if (!$this->escapeSpecialCharacters) {
                        switch ($this->operator) {
                            case '=':
                                $operator = 'LIKE';
                                break;
                            case '!=':
                                $operator = 'NOT LIKE';
                                break;
                            default:
                                $operator = $this->operator;
                                break;
                        }
                        if ($this->operator != $operator && $changeOperator) {
                            $this->cleanOperator = $operator;
                        }
                        switch (substr($operator, -4)) {
                            case 'LIKE':
                                $value = str_replace(['#', '\_', '*'], ["_", "_", "%"], $value);
                                break;
                        }
                    }
                    return "'" . $value . "'";
                }
                break;
            case 'array':
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $value[$k] = $this->valueToString($v, false);
                    }
                    return "(" . implode(",", $value) . ")";
                }
                return "(" . implode(",", $value) . ")";
            case 'boolean':
                return (string)intval($value);
            default:
                if (is_scalar($value) || (is_object($value) && ($value instanceof Stringable))) {
                    return (string)$value;
                }
                break;
        }
        return '';
    }

    /**
     * get the name of the field
     *
     * @return string
     */
    public function getFieldName(): string
    {
        return $this->field;
    }

    /**
     * get the contents of the value
     *
     * @return string
     */
    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * get the contents of the value
     *
     * @return mixed[]|bool|float|int|string|null
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @inheritDoc
     *
     * @return string
     */
    public function __toString(): string
    {
        $value = $this->valueToString($this->value, true);

        $returnTxt = "";
        if (!is_null($this->table)) {
            $returnTxt .= "`" . DB::clean4DB($this->table, true) . "`.";
        }
        $returnTxt .= "`" . DB::clean4DB($this->field, true) . "`";
        if (!is_null($this->cleanOperator)) {
            $returnTxt .= " " . DB::clean4DB($this->cleanOperator);
        } else {
            $returnTxt .= " " . DB::clean4DB($this->operator);
        }
        $returnTxt .= " " . $value;
        return $returnTxt;
    }
}

<?php

/**
 * A very simple class for using MySQL.
 *
 * PHP version 7.4+
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
     * @var mixed
     */
    private $value;

    /**
     * operator
     *
     * @var string
     */
    private string $operator = '=';

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
     * @param string|null $operator
     * @param mixed $value
     */
    public function __construct(string $field, ?string $operator = null, $value = null)
    {
        $this->field = $field;
        if (!is_null($operator)) {
            $this->operator = $operator;
            if (!is_null($value)) {
                $this->value = $value;
            }
        }
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
     * @param array $value
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
     * @param array $value
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
     * in sql
     *
     * @param string $value
     *
     * @return Param
     */
    public function inSql(string $value): Param
    {
        $this->value = $value;
        $this->operator = 'IN SQL';
        return $this;
    }

    /**
     * not in sql
     *
     * @param string $value
     *
     * @return Param
     */
    public function notSql(string $value): Param
    {
        $this->value = $value;
        $this->operator = 'NOT IN SQL';
        return $this;
    }

    /**
     * valueToString
     *
     * @param mixed $value
     * @param bool $changeOperator
     *
     * @return string
     *
     * @throws DBException Is NULL with other operator then = or !=.
     */
    private function valueToString($value, bool $changeOperator = false): string
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
                    $this->operator = $operator;
                }
                return "NULL";
            case 'string':
                // if IN or NOT IN, don't escape string because it is SQL
                switch (substr($this->operator, -3)) {
                    case 'SQL':
                        $this->operator = substr($this->operator, 0, -4);
                        return $this->value;
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
                        $this->operator = $operator;
                    }
                    switch (substr($operator, -4)) {
                        case 'LIKE':
                            $value = str_replace(array('#', '\_', '*'), array("_", "_", "%"), $value);
                            break;
                    }
                }
                return "'" . $value . "'";
            case 'array':
                foreach ($value as $k => $v) {
                    $value[$k] = $this->valueToString($v, false);
                }
                return "(" . implode(",", $value) . ")";
            default:
                return (string)$value;
        }
    }

    /**
     * @inheritDoc
     */
    public function __toString(): string
    {
        $value = $this->valueToString($this->value, true);

        $returnTxt = "";
        if (!is_null($this->table)) {
            $returnTxt .= "`" . DB::clean4DB($this->table, true) . "`.";
        }
        $returnTxt .= "`" . DB::clean4DB($this->field, true) . "`";
        $returnTxt .= " " . DB::clean4DB($this->operator);
        $returnTxt .= " " . $value;
        return $returnTxt;
    }
}

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

use TiMMiT\MySQL\DB;

/**
 * Where Param
 */
class Param
{
    private string $field = '';

    private $value;

    private string $operator = '=';

    private bool $escapeSpecialCharacters = true;

    private ?string $table = null;

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

    public function escapeSpecialCharacters(bool $escapeSpecialCharacters = true): Param
    {
        $this->escapeSpecialCharacters = $escapeSpecialCharacters;
        return $this;
    }

    public function table(string $table): Param
    {
        $this->table = $table;
        return $this;
    }

    public function is($value): Param
    {
        $this->value = $value;
        $this->operator = '=';
        return $this;
    }

    public function isNot($value): Param
    {
        $this->value = $value;
        $this->operator = '!=';
        return $this;
    }

    public function greaterOrEqual($value): Param
    {
        $this->value = $value;
        $this->operator = '>=';
        return $this;
    }

    public function greater($value): Param
    {
        $this->value = $value;
        $this->operator = '>';
        return $this;
    }

    public function lowerOrEqual($value): Param
    {
        $this->value = $value;
        $this->operator = '<=';
        return $this;
    }

    public function lower($value): Param
    {
        $this->value = $value;
        $this->operator = '<';
        return $this;
    }

    public function in(array $value): Param
    {
        $this->value = $value;
        $this->operator = 'IN';
        return $this;
    }

    public function notIn(array $value): Param
    {
        $this->value = $value;
        $this->operator = 'NOT IN';
        return $this;
    }

    public function inSql(string $value): Param
    {
        $this->value = $value;
        $this->operator = 'IN SQL';
        return $this;
    }

    public function notSql(string $value): Param
    {
        $this->value = $value;
        $this->operator = 'NOT IN SQL';
        return $this;
    }

    private function valueToString($value, bool $changeOperator = false): string
    {
        switch (strtolower(gettype($value))) {
            case 'null':
                return "NULL";
            case 'string':
                // if IN or NOT IN, don't escape string because it is SQL
                switch (substr($this->operator, -3)) {
                    case 'SQL':
                        $this->operator = substr($this->operator, 0, -4);
                        return $this->value;
                        break;
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

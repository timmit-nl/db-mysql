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
use TiMMiT\MySQL\DBException;

/**
 * Where Collection
 */
class Collection
{
    private array $array = array();

    private string $operator = 'AND';

    public function __construct(string $operator = 'AND', ?array $array = null)
    {
        $this->setOperator($operator);
        if (!is_null($array)) {
            $this->array = $array;
        }
    }

    public function setOperator(string $operator): Collection
    {
        $operator = trim(strtoupper($operator));
        switch ($operator) {
            case 'OR':
            case 'AND':
            case 'NOT':
            case ',':
                $this->operator = $operator;
                break;
            default:
                throw new DBException("Can only use AND,OR,NOT as an operator", 9902);
        }
        return $this;
    }

    public function add(object $item): Collection
    {
        if ($item instanceof Param || $item instanceof Collection) {
            $this->array[] = $item;
        } else {
            throw new DBException("Can only add Param or Collection to collection", 9901);
        }
        return $this;
    }

    public function popLastElementOff(): mixed
    {
        return array_pop($this->array);
    }

    public function popFirstElementOff(): mixed
    {
        return array_shift($this->array);
    }

    public function __toString(): string
    {
        return "(" . implode(" " . $this->operator . " ", $this->array) . ")";
    }
}

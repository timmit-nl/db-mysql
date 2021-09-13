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

use Iterator;
use Countable;
use Stringable;
use TiMMiT\MySQL\DB;
use TiMMiT\MySQL\DBException;

/**
 * Where Collection
 */
class Collection implements Iterator, Countable, Stringable
{
    /**
     * Array of the Collection
     *
     * @var array
     */
    private array $array = array();

    /**
     * position of the Collection
     *
     * @var int
     */
    private int $position = 0;

    /**
     * contains operator of Collection (AND/OR/NOT)
     *
     * @var string
     */
    private string $operator = 'AND';

    /**
     * constructor of class
     *
     * @param string $operator
     * @param array|null $array
     */
    public function __construct(string $operator = 'AND', ?array $array = null)
    {
        $this->setOperator($operator);
        if (!is_null($array)) {
            $this->array = $array;
        }
        $this->position = 0;
    }

    /**
     * set operator of collection
     *
     * @param string $operator
     *
     * @return Collection
     *
     * @throws DBException If operator is not AND,OR,NOT.
     */
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

    /**
     * add item to collection
     *
     * @param object $item
     *
     * @return Collection
     *
     * @throws DBException If $item is not object of type Param or Collection.
     */
    public function add(object $item): Collection
    {
        if ($item instanceof Param || $item instanceof Collection) {
            $this->array[] = $item;
            ++$this->position;
        } else {
            throw new DBException("Can only add Param or Collection to collection", 9901);
        }
        return $this;
    }

    /**
     * @inheritDoc
     *
     * @return int
     */
    public function key(): int
    {
        return $this->position;
    }

    /**
     * @inheritDoc
     *
     * @return Param
     */
    public function current(): Param
    {
        return $this->array[$this->position];
    }

    /**
     * @inheritDoc
     *
     * @return void
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * @inheritDoc
     *
     * @return bool
     */
    public function valid(): bool
    {
        return isset($this->array[$this->position]);
    }

    /**
     * @inheritDoc
     *
     * @return void
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * @inheritDoc
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->array);
    }

    /**
     * pop last element of collection
     *
     * @return mixed
     */
    public function popLastElementOff(): mixed
    {
        $this->rewind();
        return array_pop($this->array);
    }

    /**
     * pop first element of collection
     *
     * @return mixed
     */
    public function popFirstElementOff(): mixed
    {
        $this->rewind();
        return array_shift($this->array);
    }

    /**
     * remove element of collection
     *
     * @param int $position
     *
     * @return mixed
     */
    public function remove(int $position): bool
    {
        if (isset($this->array[$position])) {
            if ($position == $this->position) {
                $this->rewind();
            }
            unset($this->array[$position]);
            return true;
        }
        return false;
    }

    /**
     * @inheritDoc
     *
     * @return string
     */
    public function __toString(): string
    {
        return "(" . implode(" " . $this->operator . " ", $this->array) . ")";
    }
}

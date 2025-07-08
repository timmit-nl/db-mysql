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
 *
 * @implements \Iterator<int, Param|Collection>
 */
class Collection implements Iterator, Countable, Stringable
{
    /**
     * Array of the Collection
     *
     * @var Param[]|Collection[]
     */
    private array $array = array();

    /**
     * position of the Collection
     *
     * @var int
     */
    private int $position = 0;

    /**
     * If empty collection and this is true, then show all instead of nothing
     *
     * @var bool
     */
    private bool $ifEmptyThenShowAll = false;

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
     */
    public function __construct(string $operator = 'AND')
    {
        $this->setOperator($operator);
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
     * ifEmptyThenShowAll
     *
     * @param bool $ifEmptyThenShowAll
     *
     * @return Collection
     */
    public function setIfEmptyThenShowAll(bool $ifEmptyThenShowAll = false): Collection
    {
        $this->ifEmptyThenShowAll = $ifEmptyThenShowAll;
        return $this;
    }

    /**
     * add item to collection
     *
     * @param Param|Collection $item
     *
     * @return Collection
     *
     * @throws DBException If $item is not object of type Param or Collection.
     */
    public function add(Param|Collection $item): Collection
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
     * add Param to collection
     *
     * @param string $field
     *
     * @return Param
     */
    public function addParam(string $field): Param
    {
        $param = new Param($field);

        $this->array[] = &$param;
        $this->next();

        return $param;
    }

    /**
     * add Collection to collection
     *
     * @param string $operator
     *
     * @return Collection
     */
    public function addCollection(string $operator = 'AND'): Collection
    {
        $Collection = new self($operator);

        $this->array[] = &$Collection;
        $this->next();

        return $Collection;
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
     * @return Param|Collection
     */
    public function current(): object
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
     * @return bool
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
        if (($this->ifEmptyThenShowAll == true) && ($this->count() === 0)) {
            return "(1=1)";
        } else {
            return "(" . implode(" " . $this->operator . " ", $this->array) . ")";
        }
    }
}

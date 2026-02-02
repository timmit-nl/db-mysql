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

use Iterator;

/**
 * Where Not Collection
 *
 * @implements \Iterator<int, Param|Collection>
 */
class NotCollection extends Collection
{
    /**
     * @inheritDoc
     *
     * @return string
     */
    public function __toString(): string
    {
        return "NOT (" . parent::__toString() . ")";
    }
}

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

namespace TiMMiT\MySQL\Result;

use Stringable;
use TiMMiT\MySQL\DB;
use TiMMiT\MySQL\DBException;

/**
 * Result DateTime
 */
class DateTime extends \DateTime implements Stringable
{
    public string $defaultFormat = 'Y-m-d H:i:s';

    /**
     * @inheritDoc
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->format($this->defaultFormat);
    }

    /**
     * @inheritDoc
     *
     * @param string $format
     * @param string $datetime
     * @param DateTimeZone|null $timezone
     *
     * @return self|null
     */
    public static function createFromFormat($format, $datetime, ?\DateTimeZone $timezone = null): ?DateTime
    {
        $parent = parent::createFromFormat($format, $datetime);
        if (!$parent) {
            return null;
        }
        //Seting timezone like this and not by format to preserve timezone format
        $static = new static($parent->format('Y-m-d\TH:i:s.u'), $parent->getTimezone());
        return $static;
    }
}

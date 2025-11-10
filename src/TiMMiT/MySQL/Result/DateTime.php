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
final class DateTime extends \DateTime implements Stringable
{
    /**
     * @var string $defaultFormat Y-m-d H:i:s
     */
    public string $defaultFormat = 'Y-m-d H:i:s';

    /**
     * Returns date formatted according to default format.
     *
     * @return string
     */
    public function __toString(): string
    {
        $return = $this->format($this->defaultFormat);
        // check for wrong parsed 0000-00-00 00:00:00
        if ($return === '-0001-11-30 00:00:00') {
            $return = '0000-00-00 00:00:00';
        }
        return $return;
    }

    /**
     * Parse a string into a new DateTime object according to the specified format
     *
     * @param string $format   Format accepted by date().
     * @param string $datetime String representing the time.
     * @param null|\DateTimeZone $timezone
     *
     * @return DateTime|false
     */
    public static function createFromFormat($format, $datetime, ?\DateTimeZone $timezone = null): DateTime|false
    {
        $parent = parent::createFromFormat($format, $datetime);
        if (false === $parent) {
            return false;
        }
        //Seting timezone like this and not by format to preserve timezone format
        $static = new static($parent->format('Y-m-d\TH:i:s.u'), $parent->getTimezone());
        return $static;
    }

    /**
     * Parse a string into a new DateTime object according to the specified format
     *
     * @param string $format   Format accepted by date().
     * @param string $datetime String representing the time.
     * @param null|\DateTimeZone $timezone
     *
     * @return DateTime|null
     */
    public static function createFromFormatNull($format, $datetime, ?\DateTimeZone $timezone = null): ?DateTime
    {
        $dateTime = self::createFromFormat($format, $datetime);
        if (false === $dateTime) {
            return null;
        }
        return $dateTime;
    }
}

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

namespace TiMMiT\MySQL;

use TiMMiT\MySQL\Where\Collection;

/**
 * MySQL DB connection.
 */
class DB
{
    /**
     * The config vars.
     *
     * @var string[]|int[]
     */
    protected static array $primary = [];

    /**
     * The config fallback vars.
     *
     * @var string[]|int[]
     */
    protected static array $fallback = [];

    /**
     * The backup active bool.
     *
     * @var bool
     */
    public static bool $fallbackActive = false;

    /**
     * The config write vars.
     *
     * @var string[]|int[]
     */
    protected static array $write = [];

    /**
     * The write active bool.
     *
     * @var bool
     */
    public static bool $writeActive = false;

    /**
     * Max tries before failure by deadlock
     *
     * @var int
     */
    public static int $max_deadlock_count = 5;

    /**
     * Sleep multiplier with a deadlock
     *
     * @var float
     */
    public static float $sleep_deadlock_multiplier = 1;

    /**
     * Max tries before failure by gone away
     *
     * @var int
     */
    public static int $max_goneaway_count = 5;

    /**
     * Sleep multiplier with a deadlock
     *
     * @var float
     */
    public static float $sleep_goneaway_multiplier = 1.5;

    /**
     * The stats vars.
     *
     * @var int[]
     */
    protected static array $stats = [];

    /**
     * Created DB connection
     *
     * @var \mysqli
     */
    protected static \mysqli $mlink;

    /**
     * Created DBwrite connection
     *
     * @var \mysqli
     */
    protected static \mysqli $mlinkWrite;

    /**
     * The default charset for the connection.
     *
     * @var string
     */
    protected static string $charset = 'utf8mb4';

    /**
     * The mysqliReportingExceptionOn.
     *
     * @var int
     */
    private static int $mysqliReportingExceptionOn = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;

    /**
     * The mysqliReportingExceptionOff.
     *
     * @var int
     */
    private static int $mysqliReportingExceptionOff = MYSQLI_REPORT_OFF;


    /**
     * The wsrepSupport.
     *
     * @var bool
     */
    private static bool $wsrepSupport = false;

    /**
     * setConfigValue
     *
     * @param string $field
     * @param mixed $value
     *
     * @return bool
     */
    public static function setConfigValue(string $field, $value): bool
    {
        /** @phpstan-ignore-next-line */
        if (isset(self::${$field})) {
            self::${$field} = $value; /* @phpstan-ignore-line */
            return true;
        }
        return false;
    }

    /**
     * getConfigValue
     *
     * @param string $field
     *
     * @return mixed
     */
    public static function getConfigValue(string $field)
    {
        /** @phpstan-ignore-next-line */
        if (isset(self::${$field})) {
            return self::${$field}; /* @phpstan-ignore-line */
        }
        return null;
    }

    /**
     * Class constructor.
     *
     * @param string[]|int[] $primary  settings as assoc array
     * @param string[]|int[] $write    settings as assoc array
     * @param string[]|int[] $fallback settings as assoc array
     *
     * @return void
     */
    public function __construct(array $primary, array $write = [], array $fallback = [])
    {
        self::loadConfig($primary, $write, $fallback);
        self::connect();
    }

    /**
     * load the config into the correct properties
     *
     * @param string[]|int[] $primary  settings as assoc array
     * @param string[]|int[] $write    settings as assoc array
     * @param string[]|int[] $fallback settings as assoc array
     *
     * @return void
     */
    public static function loadConfig(array $primary, array $write = [], array $fallback = []): void
    {
        self::$primary = $primary;
        self::$write = $write;
        self::$fallback = $fallback;
    }

    /**
     * Connect to DB
     *
     * @return void
     *
     * @throws DBException If the connection fails completly.
     */
    public static function connect(): void
    {
        self::addToStats(__FUNCTION__);

        mysqli_report(self::$mysqliReportingExceptionOn);

        try {
            $mlink = mysqli_connect((string)self::$primary['host'], (string)self::$primary['username'], (string)self::$primary['password'], (string)self::$primary['database'], (int)self::$primary['port']);
            if (false !== $mlink) {
                self::$mlink = $mlink;
            }
            self::$writeActive = false;

            if (count(self::$write) > 0 && self::$primary !== self::$write && '' !== (self::$write['host'] ?? '')) {
                $mlink = mysqli_connect((string)self::$write['host'], (string)self::$write['username'], (string)self::$write['password'], (string)self::$write['database'], (int)self::$write['port']);
                if (false !== $mlink) {
                    self::$mlinkWrite = $mlink;
                }
                self::$writeActive = true;
            }
        } catch (\mysqli_sql_exception $e) {
            if (count(self::$fallback) > 0 &&  '' !== (self::$fallback['host'] ?? '')) {
                $mlink = mysqli_connect((string)self::$fallback['host'], (string)self::$fallback['username'], (string)self::$fallback['password'], (string)self::$fallback['database'], (int)self::$fallback['port']);
                if (false !== $mlink) {
                    self::$mlink = $mlink;
                }
                self::$fallbackActive = true;
                self::$writeActive = false;
            } else {
                throw new DBException($e->getMessage(), $e->getCode());
            }
        }

        if (self::ping()) {
            self::setCharsetOnConnection(self::$mlink);
            self::setTimeZoneOnConnection(self::$mlink);
        }
        if (self::$writeActive && self::ping(true)) {
            self::setCharsetOnConnection(self::$mlinkWrite);
            self::setTimeZoneOnConnection(self::$mlinkWrite);
            self::$wsrepSupport = DB::checkIfWsrep();
            self::setWsrepSyncWait(true);
        }
    }

    /**
     * setCharsetOnConnection
     *
     * @param \mysqli $mlink
     *
     * @return void
     */
    private static function setCharsetOnConnection(\mysqli $mlink): void
    {
        try {
            mysqli_set_charset($mlink, self::$charset);
        } catch (\mysqli_sql_exception $e) {
        }
    }

    /**
     * Let the connection use the same time zone as PHP.
     *
     * A timestamp column is converted to and from the time zone of the connection, so a server that
     * runs in another one returns times that do not line up with what PHP wrote or compares against.
     * Leaving it unset makes every comparison between a date built in PHP and such a column silently
     * off by the difference between the two.
     *
     * The offset is sent instead of the name, because named zones need the time zone tables of MySQL
     * to be filled and those are empty unless someone imported them.
     *
     * @param \mysqli $mlink
     *
     * @return void
     */
    private static function setTimeZoneOnConnection(\mysqli $mlink): void
    {
        try {
            $offset = (new \DateTime())->format('P');
            mysqli_query($mlink, "SET time_zone = '" . $offset . "'");
        } catch (\mysqli_sql_exception $e) {
        }
    }

    /**
     * Disconnect to DB
     *
     * @param bool $skipPing Do not query existing sockets before closing them.
     *
     * @return void
     */
    public static function disconnect(bool $skipPing = false): void
    {
        try {
            self::addToStats(__FUNCTION__);

            mysqli_report(self::$mysqliReportingExceptionOn);
            if (self::$writeActive && isset(self::$mlinkWrite) && ($skipPing || self::ping(true))) {
                try {
                    mysqli_close(self::$mlinkWrite);
                } catch (\Throwable $e) {
                } finally {
                    unset(self::$mlinkWrite);
                }
            }
            if (isset(self::$mlink) && ($skipPing || self::ping())) {
                try {
                    mysqli_close(self::$mlink);
                } catch (\Throwable $e) {
                } finally {
                    unset(self::$mlink);
                }
            }
        } catch (\Throwable $e) {
        }
        mysqli_report(self::$mysqliReportingExceptionOff);
    }

    /**
     * reconnect to DB
     *
     * @param bool $skipPing Do not query existing sockets before reconnecting.
     *
     * @return void
     */
    public static function reconnect(bool $skipPing = false): void
    {
        self::addToStats(__FUNCTION__);
        self::disconnect($skipPing);
        self::connect();
    }

    /**
     * ping to DB
     *
     * @param bool $writeNode
     *
     * @return bool
     */
    public static function ping(bool $writeNode = false): bool
    {
        self::addToStats(__FUNCTION__);
        if ($writeNode) {
            if (isset(self::$mlinkWrite)) {
                $mlink = self::$mlinkWrite;
            }
        } else {
            if (isset(self::$mlink)) {
                $mlink = self::$mlink;
            }
        }

        /** @phpstan-ignore-next-line */
        if (!isset($mlink) || !is_object($mlink) || !isset($mlink->host_info)) {
            return false;
        }

        $returnValue = true;
        try {
            $result = mysqli_query($mlink, 'SELECT 1;');
            if (false === $result) {
                $errorno = mysqli_errno($mlink);
                if ($errorno !== 0) {
                    $returnValue = false;
                }
            }
        } catch (\mysqli_sql_exception $e) {
            $returnValue =  false;
        } catch (\ErrorException $e) {
            $returnValue =  false;
        } catch (\Throwable $e) {
            $returnValue =  false;
        }
        return $returnValue;
    }

    /**
     * Update Query function
     *
     * @param string $query
     * @param bool $writeQuery
     * @param int|null $resultmode
     * @param int $count
     *
     * @return \mysqli_result|bool
     *
     * @throws DBException As the sql fails completly.
     */
    private static function query(string $query, bool $writeQuery = false, ?int $resultmode = null, int $count = 0)
    {
        if ($writeQuery && self::$writeActive) {
            $mlink = self::$mlinkWrite;
            $write = true;
        } else {
            $mlink = self::$mlink;
            $write = false;
        }
        self::addToStats(__FUNCTION__);
        $result = false;

        try {
            if (is_null($resultmode)) {
                $result = mysqli_query($mlink, $query);
            } else {
                $result = mysqli_query($mlink, $query, $resultmode);
            }
        } catch (\Exception $e) {
            $errorno = mysqli_errno($mlink);
            $error = mysqli_error($mlink);
            switch ($errorno) {
                case 1020:
                case 1213:
                    if ($count < self::$max_deadlock_count) {
                        $count++;
                        usleep((int)($count * round(100000, 1000000) * self::$sleep_deadlock_multiplier));
                        $result = self::query($query, $write, $resultmode, ($count + 1));
                    } else {
                        throw new DBException("Query Failed:<ul><li>errorno=" . $errorno . "</li><li>failure_count=" . $count . " of max=" . self::$max_deadlock_count . " </li><li>error=" . $error . "</li><li>query=" . $query . "</li></ul>", $errorno);
                    }
                    break;
                case 1047:
                case 1156:
                case 1180:
                case 1205:
                case 2006:
                case 4031:
                    if ($count >= self::$max_goneaway_count && $write) {
                        $write = false;
                        $count = 0;
                    }
                    if ($count < self::$max_goneaway_count) {
                        self::disconnect();
                        $count++;
                        usleep((int)($count * round(100000, 1000000) * self::$sleep_goneaway_multiplier));
                        self::reconnect();
                        $result = self::query($query, $write, $resultmode, $count);
                    } else {
                        throw new DBException("Query Failed:<ul><li>errorno=" . $errorno . "</li><li>failure_count=" . $count . " of max=" . self::$max_goneaway_count . " </li><li>error=" . $error . "</li><li>query=" . $query . "</li></ul>", $errorno);
                    }
                    break;
                default:
                    throw new DBException("Query Failed:<ul><li>errorno=" . $errorno . "</li><li>error=" . $error . "</li><li>query= " . $query . "</li></ul>", $errorno);
            }
        }

        return $result;
    }

    /**
     * Update Query function
     *
     * @param string $query
     * @param bool $writeNode
     *
     * @return bool
     */
    public static function updateQuery(string $query, bool $writeNode = true): bool
    {
        self::addToStats(__FUNCTION__);
        return (bool)self::query($query, $writeNode);
    }

    /**
     * Delete Query function
     *
     * @param string $query
     * @param bool $writeNode
     *
     * @return bool
     */
    public static function deleteQuery(string $query, bool $writeNode = true): bool
    {
        self::addToStats(__FUNCTION__);
        return (bool)self::query($query, $writeNode);
    }

    /**
     * execute a query without result function
     *
     * @param string $query
     * @param bool $writeNode
     *
     * @return bool
     */
    public static function executeQuery(string $query, bool $writeNode = true): bool
    {
        self::addToStats(__FUNCTION__);
        return (bool)self::query($query, $writeNode);
    }

    /**
     * Select Query function
     *
     * @param string $query
     * @param string $keyField
     * @param bool $writeNode
     *
     * @return mixed[]
     */
    public static function selectQuery(string $query, string $keyField = '', bool $writeNode = false): array
    {
        return self::selectQueryToStrings($query, $keyField, $writeNode);
    }

    /**
     * Select Query function
     *
     * @param string $query
     * @param string $keyField
     * @param bool $writeNode
     *
     * @return mixed[]
     */
    public static function selectQueryToStrings(string $query, string $keyField = '', bool $writeNode = false): array
    {
        self::addToStats(__FUNCTION__);
        $result = self::query($query, $writeNode);
        $return = [];
        if ($result instanceof \mysqli_result) {
            //while ($row = mysqli_fetch_assoc($result)) {
            while ($row = $result->fetch_assoc()) {
                if ('' !== $keyField && isset($row[$keyField])) {
                    $return[$row[$keyField]] = $row;
                } else {
                    $return[] = $row;
                }
            }
            $result->free();
        }
        return $return;
    }

    /**
     * Select Query function
     *
     * @param string $query
     * @param string $keyField
     * @param bool $writeNode
     *
     * @return mixed[]
     */
    public static function selectQueryToCorrectType(string $query, string $keyField = '', bool $writeNode = false): array
    {
        self::addToStats(__FUNCTION__);
        $return = array();
        $result = self::query($query, $writeNode);
        if ($result instanceof \mysqli_result) {
            $changeFields = array();
            $fields = $result->fetch_fields();
            foreach ($fields as $field) {
                switch ($field->type) {
                    case MYSQLI_TYPE_TINY:
                    case MYSQLI_TYPE_SHORT:
                    case MYSQLI_TYPE_LONG:
                    case MYSQLI_TYPE_INT24:
                        $changeFields[$field->name] = 'int';
                        break;
                    case MYSQLI_TYPE_NEWDECIMAL:
                    case MYSQLI_TYPE_DECIMAL:
                    case MYSQLI_TYPE_FLOAT:
                        $changeFields[$field->name] = 'float';
                        break;
                    case MYSQLI_TYPE_DATETIME:
                    case MYSQLI_TYPE_TIMESTAMP:
                        $changeFields[$field->name] = 'datetime';
                        break;
                    default:
                        $changeFields[$field->name] = 'string'; //(string is default so do nothing)
                        break;
                }
            }
            while ($row = $result->fetch_assoc()) {
                foreach ($changeFields as $field => $type) {
                    if (!is_null($row[$field])) {
                        switch ($changeFields[$field]) {
                            case 'int':
                            case 'float':
                                settype($row[$field], $type);
                                break;
                            case 'datetime':
                                if ($row[$field] == '0000-00-00 00:00:00') {
                                    $row[$field] = null;
                                } else {
                                    $row[$field] = Result\DateTime::createFromFormatNull('Y-m-d H:i:s', (string)$row[$field]);
                                }
                                break;
                        }
                    }
                }
                if ('' !== $keyField && isset($row[$keyField])) {
                    $return[(string)$row[$keyField]] = $row;
                } else {
                    $return[] = $row;
                }
            }
            $result->free();
        }
        return $return;
    }

    /**
     * Insert Query function
     *
     * @param string $query
     * @param bool $writeNode
     *
     * @return int|string
     */
    public static function insertQuery(string $query, bool $writeNode = true)
    {
        self::addToStats(__FUNCTION__);
        self::query($query, $writeNode); //test
        return mysqli_insert_id(self::$mlink);
    }

    /**
     * Check if table exists
     *
     * @param string $table
     *
     * @return bool
     */
    public static function checkTableExist(string $table): bool
    {
        self::addToStats(__FUNCTION__);
        return self::checkForResult("SHOW TABLES LIKE '" . $table . "';");
    }

    /**
     * Check if column exists in table
     *
     * @param string $table
     * @param string $column
     *
     * @return bool
     */
    public static function checkIfColumnExistInTable(string $table, string $column): bool
    {
        self::addToStats(__FUNCTION__);
        return self::checkForResult("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $column . "';");
    }

    /**
     * Check if query gives result
     *
     * @param string $query
     *
     * @return bool
     */
    public static function checkForResult(string $query): bool
    {
        self::addToStats(__FUNCTION__);
        $result = self::query($query);
        if ($result instanceof \mysqli_result && mysqli_num_rows($result) > 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Check if field is Null
     *
     * @param string $table             table where you want to check the field
     * @param string $field             colomn to check
     * @param string $primaryKeyFieldID primary id
     *
     * @return bool
     */
    public static function checkIsNull(string $table, string $field, string $primaryKeyFieldID): bool
    {
        self::addToStats(__FUNCTION__);
        $primaryKeyFieldResult = self::query("SHOW INDEX FROM " . $table);
        if ($primaryKeyFieldResult instanceof \mysqli_result) {
            $primaryKeyFieldRow = mysqli_fetch_array($primaryKeyFieldResult);
            if ($primaryKeyFieldRow !== false && isset($primaryKeyFieldRow['Column_name'])) {
                return self::checkForResult("SELECT " . $field . " FROM " . $table . " WHERE " . $primaryKeyFieldRow['Column_name'] . " = " . $primaryKeyFieldID . " AND " . $field . " IS NULL;");
            }
        }
        return false;
    }

    /**
     * Get all columns of table
     *
     * @param string $table
     * @param bool $full
     *
     * @return mixed[]
     */
    public static function getColumnsOfTable(string $table, bool $full = false): array
    {
        self::addToStats(__FUNCTION__);

        $prefix = "";
        if ($full) {
            $prefix = "FULL";
        }

        return self::selectQuery("SHOW " . $prefix . " COLUMNS FROM `" . $table . "`;");
    }

    /**
     * Get the Collation of a column in a table
     *
     * @param string $table
     * @param string $column
     *
     * @return null|string
     */
    public static function getCollationOfTableColumn(string $table, string $column): ?string
    {
        self::addToStats(__FUNCTION__);

        $columns = self::getColumnsOfTable($table, true);
        foreach ($columns as $columnItem) {
            /** @var string[] */
            $columnItem = $columnItem;
            if ($columnItem['Field'] == $column) {
                if (isset($columnItem['Collation'])) {
                    return $columnItem['Collation'];
                }
                return null;
            }
        }
        return null;
    }

    /**
     * clean variable for DB against SQL injection
     *
     * @param string $value
     * @param bool $key   default false
     *
     * @return string
     */
    public static function clean4DB(string $value, bool $key = false): string
    {
        self::addToStats(__FUNCTION__);
        $value = addcslashes(mysqli_real_escape_string(self::$mlink, $value), "%_");
        if ($key != false) {
            $value = str_replace('\_', "_", $value);
        }
        return $value;
    }

    /**
     * clean variable from DB against slashes against SQL injection
     *
     * @param string $value
     *
     * @return string
     */
    public static function cleanFromDB(string $value): string
    {
        self::addToStats(__FUNCTION__);
        $value = stripcslashes($value);
        return $value;
    }

    /**
     * Make a hashed Id from seed
     *
     * @param string $hash
     * @param int $len
     * @param mixed $seed
     * @param string $add
     *
     * @return string
     */
    public static function makeId(string $hash = '', int $len = 1, mixed $seed = "", string $add = ''): string
    {
        self::addToStats(__FUNCTION__);
        switch ($hash):
            default:
                $hash = 'sha1';
                $length = 32;
                break;
            case 'sha1':
                $length = 31;
                break;
            case 'sha256':
                $length = 50;
                break;
            case 'md5':
                $length = 25;
                break;
        endswitch;
        if (0 === $len) {
            $length = 0;
        } else {
            $length = max($len, $length);
        }
        $hasSeed = (!is_null($seed) && '' !== $seed && false !== $seed && 0 !== $seed && '0' !== $seed && (!is_array($seed) || count($seed) > 0));
        if ($hasSeed) {
            if (!is_string($seed)) {
                $seed = serialize($seed);
            }
        } else {
            $seed = uniqid(rand() . time(), true);
        }
        $id = base_convert(hash($hash, $seed), 16, 36);
        $erbij = $length - strlen($id);
        if ($erbij > 0) {
            if ($add == '') {
                $stap = 10;
                for ($a = 0; $a < $erbij; $a += $stap) {
                    $erbij2 = min(($erbij - $a), $stap);
                    $rand = (string)rand(pow(36, ($erbij2 - 1)), (pow(36, $erbij2) - 1));
                    $id .= base_convert($rand, 10, 36);
                }
            } else {
                for ($a = 0; $a < $erbij; $a++) {
                    $id .= $add;
                }
            }
        }
        return $id;
    }

    /**
     * Get the Charset of connection
     *
     * @return string
     */
    public static function getCharSet(): string
    {
        self::addToStats(__FUNCTION__);
        return mysqli_character_set_name(self::$mlink);
    }

    /**
     * Make where statement from Collection
     *
     * @param Collection $collection paramater
     *
     * @return string
     */
    public static function getWhereFromCollection(Collection $collection): string
    {
        self::addToStats(__FUNCTION__);
        return (string)$collection;
    }

    /**
     * dbDelta on DB
     *
     * @param string|string[] $queries
     * @param bool $execute
     *
     * @return string[]
     */
    public static function dbDelta($queries = '', bool $execute = true): array
    {
        self::addToStats(__FUNCTION__);
        // Separate individual queries into an array
        if (!is_array($queries)) {
            $queries = explode(';', $queries);
            $queries = array_filter($queries);
        }

        /**
         * Filter the dbDelta SQL queries.
         *
         * @param array $queries An array of dbDelta SQL queries.
         *
         * @since 3.3.0
         */
        $cqueries = []; // Creation Queries
        $iqueries = []; // Insertion Queries
        $for_update = [];

        // Create a tablename index for an array ($cqueries) of queries
        foreach ($queries as $qry) {
            if (1 === preg_match("|CREATE TABLE ([^ ]*)|", $qry, $matches)) {
                $cqueries[trim($matches[1], '`')] = $qry;
                $for_update[trim($matches[1], '`')] = 'Created table ' . $matches[1];
            } elseif (1 === preg_match("|CREATE DATABASE ([^ ]*)|", $qry, $matches)) {
                array_unshift($cqueries, $qry);
            } elseif (1 === preg_match("|INSERT INTO ([^ ]*)|", $qry, $matches)) {
                $iqueries[] = $qry;
            } elseif (1 === preg_match("|UPDATE ([^ ]*)|", $qry, $matches)) {
                $iqueries[] = $qry;
            } else {
                // Unrecognized query type
            }
        }

        foreach ($cqueries as $table => $qry) {
            if (!self::checkTableExist((string)$table)) {
                continue;
            }

            // Fetch the table column structure from the database
            $tablefields = self::getColumnsOfTable((string)$table);

            if (count($tablefields) == 0) {
                continue;
            }

            // Clear the field and index arrays.
            $cfields = [];
            $indices = [];

            // Get all of the field names in the query from between the parentheses.
            preg_match("|\((.*)\)|ms", $qry, $match2);
            $qryline = trim($match2[1] ?? '');

            // Separate field lines into an array.
            $flds = explode("\n", $qryline);

            // For every field line specified in the query.
            foreach ($flds as $fld) {
                // Extract the field name.
                preg_match("|^([^ ]*)|", trim($fld), $fvals);
                $fieldname = trim(($fvals[1] ?? ''), '`');

                // Verify the found field name.
                $validfield = true;
                switch (strtolower($fieldname)) {
                    case '':
                    case 'primary':
                    case 'index':
                    case 'fulltext':
                    case 'unique':
                    case 'key':
                        $validfield = false;
                        $indices[] = trim(trim($fld), ", \n");
                        break;
                }
                $fld = trim($fld);

                // If it's a valid field, add it to the field array.
                if ($validfield) {
                    $fld = str_replace("`" . $fieldname . "`", $fieldname, $fld);
                    $cfields[strtolower($fieldname)] = trim($fld, ", \n");
                }
            }

            // For every field in the table.
            foreach ($tablefields as $tablefield) {
                /** @var string[]*/
                $tablefield = $tablefield;
                // If the table field exists in the field array ...
                if (array_key_exists(strtolower($tablefield['Field']), $cfields)) {
                    // Get the field type from the query.
                    preg_match("|" . $tablefield['Field'] . " ([^ ]*( unsigned)*( zerofill)?)|i", $cfields[strtolower($tablefield['Field'])], $matches);
                    //echo "<hr/><pre>\n".print_r(array("|".$tablefield['Field']." ([^ ]*( unsigned)*( zerofill)?)|i", $cfields[strtolower($tablefield['Field'])],$matches), true)."</pre><hr/>";

                    $fieldtype = $matches[1];

                    // Is actual field type different from the field type in query?
                    if ($tablefield['Type'] != $fieldtype) {
                        // Add a query to change the column type
                        $cqueries[] = "ALTER TABLE `{$table}` CHANGE COLUMN `{$tablefield['Field']}` " . $cfields[strtolower($tablefield['Field'])];
                        $for_update[$table . '.' . $tablefield['Field']] = "Changed type of {$table}.{$tablefield['Field']} from {$tablefield['Type']} to {$fieldtype}";
                    }

                    // Get the default value from the array
                    // todo: Remove this?
                    if (1 === preg_match("| DEFAULT '(.*?)'|i", $cfields[strtolower($tablefield['Field'])], $matches)) {
                        $default_value = $matches[1];
                        $currentDefaultValue = trim(($tablefield['Default'] ?? ''), "'");
                        if ($currentDefaultValue !== $default_value) {
                            // Add a query to change the column's default value
                            $cqueries[] = "ALTER TABLE `{$table}` ALTER COLUMN `{$tablefield['Field']}` SET DEFAULT '{$default_value}'";
                            $for_update[$table . '.' . $tablefield['Field']] = "Changed default value of {$table}.{$tablefield['Field']} from {$currentDefaultValue} to {$default_value}";
                        }
                    }

                    // Remove the field from the array (so it's not added).
                    unset($cfields[strtolower($tablefield['Field'])]);
                } else {
                    // This field exists in the table, but not in the creation queries?
                }
            }

            // For every remaining field specified for the table.
            foreach ($cfields as $fieldname => $fielddef) {
                // Push a query line into $cqueries that adds the field to that table.
                $cqueries[] = "ALTER TABLE `{$table}` ADD COLUMN $fielddef";
                $for_update[$table . '.' . $fieldname] = 'Added column ' . $table . '.' . $fieldname;
            }

            // Index stuff goes here. Fetch the table index structure from the database.
            $tableindices = self::selectQuery("SHOW INDEX FROM `{$table}`;");

            // Collect existing index names so changed keys can be dropped before re-adding.
            /** @var array<string, true> $existingIndexNames */
            $existingIndexNames = [];
            foreach ($tableindices as $existingIndex) {
                /** @var (string|int)[] $existingIndex */
                $existingIndexNames[(string)$existingIndex['Key_name']] = true;
            }

            if (count($tableindices) > 0) {
                // Clear the index array.
                $index_ary = [];

                // For every index in the table.
                foreach ($tableindices as $tableindex) {
                    /** @var (string|int)[] */
                    $tableindex = $tableindex;
                    // Add the index to the index data array.
                    $keyname = $tableindex['Key_name'];

                    /** @var string[] */
                    $columns = ['fieldname' => $tableindex['Column_name'], 'subpart' => $tableindex['Sub_part']];
                    $index_ary[$keyname]['columns'][] = $columns;
                    $index_ary[$keyname]['unique'] = ($tableindex['Non_unique'] == 0) ? true : false;
                    $index_ary[$keyname]['index_type'] = $tableindex['Index_type'];
                }

                // For each actual index in the index array.
                foreach ($index_ary as $index_name => $index_data) {
                    // Build a create string to compare to the query.
                    $index_string = '';
                    if ($index_name == 'PRIMARY') {
                        $index_string .= 'PRIMARY ';
                    } elseif ($index_data['unique']) {
                        $index_string .= 'UNIQUE ';
                    }
                    $index_type = (string)$index_data['index_type'];
                    if ('FULLTEXT' === strtoupper($index_type)) {
                        $index_string .= 'FULLTEXT ';
                    }
                    $index_string .= 'KEY ';
                    if ($index_name != 'PRIMARY') {
                        $index_string .= "`" . $index_name . "`";
                    }
                    $index_columns = '';

                    // For each column in the index.
                    foreach ($index_data['columns'] as $column_data) {
                        if ($index_columns != '') {
                            $index_columns .= ',';
                        }

                        // Add the field to the column list string.
                        $index_columns .= "`" . $column_data['fieldname'] . "`";
                        if ($column_data['subpart'] != '') {
                            $index_columns .= '(' . $column_data['subpart'] . ')';
                        }
                    }

                    // The alternative index string doesn't care about subparts
                    $alt_index_columns = preg_replace('/\([^)]*\)/', '', $index_columns);

                    // Add the column list to the index create string.
                    $index_string = trim($index_string);

                    $index_strings = [
                        "$index_string ($index_columns)",
                        "$index_string ($alt_index_columns)",
                    ];

                    unset($index_string);
                    foreach ($index_strings as $index_string) {
                        if (!(false === ($aindex = array_search($index_string, $indices, true)))) {
                            unset($indices[$aindex]);
                            break;
                        }
                    }
                }
            }

            // For every remaining index specified for the table.
            foreach ($indices as $index) {
                // Build the ALTER statement for this index. When a non-primary key with the same
                // name already exists, it is dropped within the same statement so the key is
                // recreated with the new (e.g. composite) definition in a single atomic rebuild,
                // instead of a separate DROP that would leave the table without the index if the
                // following ADD fails.
                $addIndex = self::buildAddIndexQuery((string)$table, $index, $existingIndexNames);
                if ('' !== $addIndex['recreatedKey']) {
                    $for_update[$table . '.' . $addIndex['recreatedKey']] = 'Recreated index ' . $table . ' ' . $addIndex['recreatedKey'];
                }

                // Push the query line into $cqueries that adds the index to that table.
                $cqueries[] = $addIndex['query'];
                $for_update[] = 'Added index ' . $table . ' ' . $index;
            }

            // Remove the original table creation query from processing.
            unset($cqueries[$table], $for_update[$table]);
        }

        $allqueries = array_merge($cqueries, $iqueries);
        if ($execute) {
            foreach ($allqueries as $query) {
                self::query($query, true);
            }
        }
        return $for_update;
    }

    /**
     * Build the ALTER TABLE statement that adds a single index definition.
     *
     * When a non-primary key with the same name already exists in the table, a DROP INDEX
     * clause is prepended within the same statement. This recreates the key with the new
     * definition in a single atomic rebuild instead of failing on a duplicate key name, and
     * avoids leaving the table without the index between a separate DROP and ADD.
     *
     * PRIMARY KEY has no backtick name and is intentionally never dropped here.
     *
     * @param string $table              The table name without surrounding backticks.
     * @param string $index              The index definition as produced by the schema parser.
     * @param array<string, bool> $existingIndexNames Map of existing index names to presence.
     *
     * @return array{query: string, recreatedKey: string} The query, plus the recreated key name (empty when none).
     */
    private static function buildAddIndexQuery(string $table, string $index, array $existingIndexNames): array
    {
        $dropClause = '';
        $recreatedKey = '';
        // Match both the KEY and INDEX keyword (also UNIQUE/FULLTEXT ... INDEX), which MySQL
        // treats as synonyms. PRIMARY KEY has no backtick name and therefore never matches.
        if (1 === preg_match('/(?:KEY|INDEX)\s+`([^`]+)`/i', $index, $keyMatch)) {
            $keyName = $keyMatch[1];
            if (isset($existingIndexNames[$keyName])) {
                $dropClause = "DROP INDEX `{$keyName}`, ";
                $recreatedKey = $keyName;
            }
        }

        return [
            'query' => "ALTER TABLE `{$table}` {$dropClause}ADD {$index}",
            'recreatedKey' => $recreatedKey,
        ];
    }

    /**
     * Export function 2 csv
     *
     * @param string $table       table
     * @param string[] $fields      if empty=* else array of fields
     * @param null|Collection $Collection  Collection object for where
     * @param string|null $file        path to file, null to tempfile
     * @param bool $return      true return csv else return rows of csv
     * @param string $delimiter   delimiter of csv default ;
     * @param string $enclosure   enclosure of csv default "
     * @param string $escape_char escape_char of csv default \
     *
     * @return int|string|null Null on error.
     */
    public static function export2Csv(string $table, array $fields = [], ?Collection $Collection = null, ?string $file = null, bool $return = false, string $delimiter = ';', string $enclosure = '"', string $escape_char = "\\")
    {
        self::addToStats(__FUNCTION__);
        $fieldsTxt = '*';
        if (count($fields) > 0) {
            $fieldsArray = [];
            foreach ($fields as $field) {
                $fieldsArray[] = self::clean4DB($field, true);
            }
            $fieldsTxt = implode(", ", $fieldsArray);
        }

        if (is_null($Collection)) {
            $where = "(1=1)";
        } else {
            $where = self::getWhereFromCollection($Collection);
        }

        $totalResult = DB::selectQuery("SELECT count(*) as `total` FROM " . self::clean4DB($table, true) . " WHERE " . $where . ";");

        $total = 0;
        if (is_array($totalResult[0] ?? null) && is_scalar($totalResult[0]['total'] ?? null)) {
            $total = (int)$totalResult[0]['total'];
        }
        $chunk = 1000;
        $flushEvery = 5000;
        $gcEvery = 50000;

        $query = "SELECT " . $fieldsTxt . " FROM " . self::clean4DB($table, true) . " WHERE " . $where;

        if (is_null($file)) {
            $filename = tempnam(sys_get_temp_dir(), 'CSV');
            $return = true;
        } else {
            $filename = $file;
        }
        if (false === $filename) {
            return null;
        }
        $fp = fopen($filename, 'w');
        if (false === $fp) {
            return null;
        }
        $a = 0;

        while ($a < $total) {
            $query_limit = $query . ' LIMIT ' . $a . ',' . $chunk;

            $result = self::query($query_limit);

            if ($result instanceof \mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    if ($a == 0) {
                        fputcsv($fp, array_keys($row), $delimiter, $enclosure, $escape_char);
                    }
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $v = self::cleanFromDB($v);
                        }
                        $row[$k] = $v;
                    }
                    fputcsv($fp, $row, $delimiter, $enclosure, $escape_char);
                    $a++;

                    if (0 === ($a % $flushEvery)) {
                        fflush($fp);
                    }
                    if (0 === ($a % $gcEvery)) {
                        gc_collect_cycles();
                    }
                }
                $result->free();
            }
        }
        fclose($fp);
        if (true === $return) {
            $content = file_get_contents($filename);
            if (is_string($content)) {
                return $content;
            }
            return null;
        }
        return $a;
    }

    /**
     * start a transaction
     *
     * @param bool $readOnly
     * @param bool $writeNode
     *
     * @return void
     */
    public static function startTransaction(bool $readOnly = false, bool $writeNode = true): void
    {

        if (!$readOnly) {
            self::executeQuery("SET autocommit=0;", $writeNode);
            self::executeQuery("SET unique_checks=0;", $writeNode);
            self::executeQuery("SET foreign_key_checks=0", $writeNode);
            $suffix = "";
        } else {
            $suffix = "READ ONLY";
        }
        self::executeQuery("START TRANSACTION " . $suffix . ";", $writeNode);
    }

    /**
     * commit a transaction
     *
     * @param bool $writeNode
     *
     * @return void
     */
    public static function commitTransaction(bool $writeNode = true): void
    {
        self::executeQuery("COMMIT;", $writeNode);
    }

    /**
     * rollback a transaction
     *
     * @param bool $writeNode
     *
     * @return void
     */
    public static function rollbackTransaction(bool $writeNode = true): void
    {
        self::executeQuery("ROLLBACK;", $writeNode);
    }

    /**
     * stop a transaction
     *
     * @param bool $commit
     * @param bool $readOnly
     * @param bool $writeNode
     *
     * @return void
     */
    public static function endTransaction(bool $commit, bool $readOnly = false, bool $writeNode = true): void
    {
        if ($commit) {
            self::commitTransaction($writeNode);
        } else {
            self::rollbackTransaction($writeNode);
        }
        if (!$readOnly) {
            self::executeQuery("SET autocommit=1;", $writeNode);
            self::executeQuery("SET unique_checks=1;", $writeNode);
            self::executeQuery("SET foreign_key_checks=1;", $writeNode);
        }
    }

    /**
     * Check if Wsrep cluster is active
     *
     * @return bool
     */
    public static function checkIfWsrep(): bool
    {
        self::addToStats(__FUNCTION__);
        return self::checkForResult("SHOW SESSION STATUS LIKE 'wsrep_cluster_status';");
    }

    /**
     * setWsrepSyncWait
     *
     * @param bool $on
     *
     * @return void
     */
    public static function setWsrepSyncWait(bool $on = true): void
    {
        if (self::$wsrepSupport) {
            try {
                if ($on) {
                    mysqli_query(self::$mlink, "SET SESSION wsrep_sync_wait = 1;");
                } else {
                    mysqli_query(self::$mlink, "SET SESSION wsrep_sync_wait = 0;");
                }
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * add item tot stats
     *
     * @param string $queryType Soort Query
     *
     * @return void
     */
    private static function addToStats(string $queryType): void
    {
        if (!isset(self::$stats[$queryType])) {
            self::$stats[$queryType] = 0;
        }
        self::$stats[$queryType]++;
    }

    /**
     * get the stats
     *
     * @return int[] stats
     */
    public static function getStats(): array
    {
        return self::$stats;
    }
}

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

namespace TiMMiT\MySQL;

use TiMMiT\MySQL\Where\Collection;
use TiMMiT\MySQL\Where\Param;

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
    protected static array $config = array();

    /**
     * The config fallback vars.
     *
     * @var string[]|int[]
     */
    protected static $fallback = array();

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
    protected static $write = array();

    /**
     * The write active bool.
     *
     * @var bool
     */
    public static $writeActive = false;

    /**
     * Max tries before failure by deadlock
     *
     * @var int
     */
    public static int $max_deadlock_count = 5;

    /**
     * Sleep multiplier with a deadlock
     *
     * @var int
     */
    public static int $sleep_deadlock_multiplier = 1;

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
    protected static array $stats = array();

    /**
     * Created DB connection
     *
     * @var \mysqli
     */
    protected static $mlink;

    /**
     * Created DBwrite connection
     *
     * @var \mysqli|null
     */
    protected static $mlinkWrite;

    /**
     * The default charset.
     *
     * @var string
     */
    protected static string $charset = "utf8mb4";

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
        if (isset(self::${$field})) {
            self::${$field} = $value;
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
        if (isset(self::${$field})) {
            return self::${$field};
        }
        return null;
    }

    /**
     * Class constructor.
     *
     * @param string[]|int[] $config   settings as assoc array
     * @param string[]|int[] $write    settings as assoc array
     * @param string[]|int[] $fallback settings as assoc array
     *
     * @return void
     */
    public function __construct(array $config, array $write = array(), array $fallback = array())
    {
        if (is_array($config)) {
            self::$config = $config;
        }
        if (is_array($write)) {
            self::$write = $write;
        }
        if (is_array($fallback)) {
            self::$fallback = $fallback;
        }
        self::connect();
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
            self::$mlink = mysqli_connect(self::$config['host'], self::$config['username'], self::$config['password'], self::$config['database'], (int)self::$config['port']);
            self::$writeActive = false;
            if (!empty(self::$write) && self::$config != self::$write && !empty(self::$write['host'])) {
                self::$mlinkWrite = mysqli_connect(self::$write['host'], self::$write['username'], self::$write['password'], self::$write['database'], (int)self::$write['port']);
                self::$writeActive = true;
            }
        } catch (\mysqli_sql_exception $e) {
            if (!empty(self::$fallback) &&  !empty(self::$fallback['host'])) {
                self::$mlink = mysqli_connect(self::$fallback['host'], self::$fallback['username'], self::$fallback['password'], self::$fallback['database'], (int)self::$fallback['port']);

                self::$fallbackActive = true;
                self::$writeActive = false;
            } else {
                throw new DBException($e->getMessage(), $e->getCode());
            }
        }


        if (self::ping()) {
            mysqli_set_charset(self::$mlink, self::$charset);
        }
        if (self::$writeActive && self::ping(true)) {
            mysqli_set_charset(self::$mlinkWrite, self::$charset);
            self::$wsrepSupport = DB::checkIfWsrep();
            self::setWsrepSyncWait(true);
        }
    }

    /**
     * Disconnect to DB
     *
     * @return void
     */
    public static function disconnect(): void
    {
        try {
            self::addToStats(__FUNCTION__);
            if (self::$writeActive && self::ping(true)) {
                mysqli_close(self::$mlinkWrite);
            }
            if (self::ping()) {
                mysqli_close(self::$mlink);
            }
        } catch (\mysqli_sql_exception $e) {
        }
        mysqli_report(self::$mysqliReportingExceptionOff);
    }

    /**
     * reconnect to DB
     *
     * @return void
     */
    public static function reconnect(): void
    {
        self::addToStats(__FUNCTION__);
        self::disconnect();
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
            $mlink = self::$mlinkWrite;
        } else {
            $mlink = self::$mlink;
        }
        if (is_null($mlink)) {
            return false;
        }

        $returnValue = true;
        try {
            $result = mysqli_query($mlink, 'SELECT 1;');
            if (!$result) {
                $errorno = mysqli_errno($mlink);
                if (!empty($errorno)) {
                    $returnValue = false;
                }
            }
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
            $error = mysqli_error(self::$mlink);
            switch ($errorno) {
                case 1213:
                    if ($count < self::$max_deadlock_count) {
                        $count++;
                        usleep((int)($count * round(100000, 1000000) * self::$sleep_deadlock_multiplier));
                        $result = self::query($query, $write, $resultmode, ($count + 1));
                    } else {
                        throw new DBException("Query Failed:<ul><li>errorno=" . $errorno . "</li><li>failure_count=" . $count . " of max=" . self::$max_deadlock_count . " </li><li>error=" . $error . "</li><li>query=" . $query . "</li></ul>", $errorno);
                    }
                    break;
                case 2006:
                case 1047:
                case 1156:
                case 1205:
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
     * @param string $query
     * @param bool $writeNode
     * @return bool
     */
    public static function updateQuery(string $query, bool $writeNode = true)
    {
        self::addToStats(__FUNCTION__);
        return self::query($query, $writeNode);
    }

    /**
     * Delete Query function
     * @param string $query
     * @param bool $writeNode
     * @return bool
     */
    public static function deleteQuery(string $query, bool $writeNode = true)
    {
        self::addToStats(__FUNCTION__);
        return self::query($query, $writeNode);
    }

    /**
     * execute a query without result function
     * @param string $query
     * @param bool $writeNode
     * @return bool
     */
    public static function executeQuery(string $query, bool $writeNode = true)
    {
        self::addToStats(__FUNCTION__);
        return self::query($query, $writeNode);
    }

    /**
     * Select Query function
     *
     * @param string $query
     * @param string $keyField
     * @param bool $writeNode
     *
     * @return array[]
     */
    public static function selectQuery(string $query, string $keyField = '', bool $writeNode = false): array
    {
        self::addToStats(__FUNCTION__);
        $result = self::query($query, $writeNode);
        $return = array();
        if ($result instanceof \mysqli_result) {
            //while ($row = mysqli_fetch_assoc($result)) {
            while ($row = $result->fetch_assoc()) {
                if (!empty($keyField) && isset($row[$keyField])) {
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
        if (mysqli_num_rows($result) > 0) {
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
        $primaryKeyFieldRow = mysqli_fetch_array($primaryKeyFieldResult);
        return self::checkForResult("SELECT " . $field . " FROM " . $table . " WHERE " . $primaryKeyFieldRow["Column_name"] . " = " . $primaryKeyFieldID . " AND " . $field . " IS NULL;");
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
    public static function makeId(string $hash = '', int $len = 1, $seed = "", string $add = ''): string
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
        if (empty($len)) {
            $length = 0;
        } else {
            $length = max($len, $length);
        }
        if (!empty($seed)) {
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
     * Make where statement from parameters
     *
     * @param mixed[]|false $param paramater or parameters as assoc array
     * @param string $table
     *
     * @return string
     */
    public static function getWhereFromParam($param = false, string $table = ''): string
    {
        self::addToStats(__FUNCTION__);
        if (is_object($param) && ($param instanceof Collection)) {
            $where = self::getWhereFromCollection($param);
        } elseif (is_array($param) && !empty($param)) {
            $where = "(";
            $divider_operator = 'AND';
            foreach ($param as $par) {
                if (isset($par['divider_operator'])) {
                    $divider_operator = $par['divider_operator'];
                }
                if (isset($par['param'])) {
                    if (!empty($par['param'])) {
                        if (!isset($par['operator'])) {
                            $par['operator'] = '';
                        }
                        if (is_array($par['param']) && ($par['operator'] != 'IN' || $par['operator'] != 'NOT IN')) {
                            $where .= '' . self::getWhereFromParam($par['param'], $table) . ' ' . self::clean4DB($divider_operator) . " ";
                        } else {
                            if (!isset($par['value'])) {
                                $par['value'] = '';
                            }
                            if (!empty($table)) {
                                $where .= "`" . $table . "`.";
                            }
                            $par['operator'] = strtoupper($par['operator']);
                            switch ($par['operator']) {
                                case 'IN':
                                case 'NOT IN':
                                    $value_txt = '';
                                    if (is_array($par['value'])) {
                                        if (!empty($par['value'])) {
                                            $values = array();
                                            foreach ($par['value'] as $value) {
                                                $value = self::clean4DB((string)$value);
                                                if (isset($par['dont_escape_special_characters']) && $par['dont_escape_special_characters'] == true) {
                                                    $value = str_replace('#', "_", $value);
                                                    $value = str_replace('\_', "_", $value);
                                                    $value = str_replace('*', "%", $value);
                                                }
                                                $values[] = $value;
                                            }
                                            $value_txt = "'" . implode("','", $values) . "'";
                                        }
                                    } else {
                                        $value_txt = $par['value'];
                                    }
                                    $where .= "`" . self::clean4DB($par['param'], true) . "` " . $par['operator'] . " (" . $value_txt . ") " . self::clean4DB($divider_operator) . " ";
                                    break;
                                default:
                                    $value = self::clean4DB((string)$par['value']);
                                    if (isset($par['dont_escape_special_characters']) && $par['dont_escape_special_characters'] == true) {
                                        $value = str_replace('#', "_", $value);
                                        $value = str_replace('\_', "_", $value);
                                        $value = str_replace('*', "%", $value);
                                    }
                                    $where .= "`" . self::clean4DB($par['param'], true) . "` " . self::clean4DB($par['operator']) . " '" . $value . "' " . self::clean4DB($divider_operator) . " ";
                                    break;
                            }
                        }
                    }
                }
            }
            if (isset($par['divider_operator'])) {
                $divider_operator = $par['divider_operator'];
            }
            $where = substr($where, 0, (0 - strlen(" " . self::clean4DB($divider_operator) . " ")));
            $where .= ")";
            if ($where == ")") {
                $where = "";
            }
        } else {
            $where = "(1=1)";
        }
        return $where;
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
        $cqueries = array(); // Creation Queries
        $iqueries = array(); // Insertion Queries
        $for_update = array();

        // Create a tablename index for an array ($cqueries) of queries
        foreach ($queries as $qry) {
            if (preg_match("|CREATE TABLE ([^ ]*)|", $qry, $matches)) {
                $cqueries[trim($matches[1], '`')] = $qry;
                $for_update[trim($matches[1], '`')] = 'Created table ' . $matches[1];
            } elseif (preg_match("|CREATE DATABASE ([^ ]*)|", $qry, $matches)) {
                array_unshift($cqueries, $qry);
            } elseif (preg_match("|INSERT INTO ([^ ]*)|", $qry, $matches)) {
                $iqueries[] = $qry;
            } elseif (preg_match("|UPDATE ([^ ]*)|", $qry, $matches)) {
                $iqueries[] = $qry;
            } else {
                // Unrecognized query type
            }
        }

        foreach ($cqueries as $table => $qry) {
            if (!self::checkTableExist($table)) {
                continue;
            }

            // Fetch the table column structure from the database
            $tablefields = self::selectQuery("DESCRIBE `{$table}`;");

            if (!$tablefields) {
                continue;
            }

            // Clear the field and index arrays.
            $cfields = $indices = array();

            // Get all of the field names in the query from between the parentheses.
            preg_match("|\((.*)\)|ms", $qry, $match2);
            $qryline = trim($match2[1]);

            // Separate field lines into an array.
            $flds = explode("\n", $qryline);

            // For every field line specified in the query.
            foreach ($flds as $fld) {
                // Extract the field name.
                preg_match("|^([^ ]*)|", trim($fld), $fvals);
                $fieldname = trim($fvals[1], '`');

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
                    if (preg_match("| DEFAULT '(.*?)'|i", $cfields[strtolower($tablefield['Field'])], $matches)) {
                        $default_value = $matches[1];
                        if ($tablefield['Default'] != $default_value) {
                            // Add a query to change the column's default value
                            $cqueries[] = "ALTER TABLE `{$table}` ALTER COLUMN `{$tablefield['Field']}` SET DEFAULT '{$default_value}'";
                            $for_update[$table . '.' . $tablefield['Field']] = "Changed default value of {$table}.{$tablefield['Field']} from {$tablefield['Default']} to {$default_value}";
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

            if ($tableindices) {
                // Clear the index array.
                $index_ary = array();

                // For every index in the table.
                foreach ($tableindices as $tableindex) {
                    // Add the index to the index data array.
                    $keyname = $tableindex['Key_name'];
                    $index_ary[$keyname]['columns'][] = array('fieldname' => $tableindex['Column_name'], 'subpart' => $tableindex['Sub_part']);
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
                    if ('FULLTEXT' === strtoupper($index_data['index_type'])) {
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

                    $index_strings = array(
                        "$index_string ($index_columns)",
                        "$index_string ($alt_index_columns)",
                    );

                    foreach ($index_strings as $index_string) {
                        if (!(($aindex = array_search($index_string, $indices)) === false)) {
                            unset($indices[$aindex]);
                            break;
                        }
                    }
                }
            }

            // For every remaining index specified for the table.
            foreach ((array) $indices as $index) {
                // Push a query line into $cqueries that adds the index to that table.
                $cqueries[] = "ALTER TABLE `{$table}` ADD $index";
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
     * clean variable from DB against slashes against SQL injection for export csv
     *
     * @param mixed $value
     * @param string $key
     *
     * @return void
     */
    public static function cleanFromDB4Csv(&$value, string $key): void
    {
        self::addToStats(__FUNCTION__);
        $value = self::cleanFromDB((string)$value);
    }

    /**
     * Export function 2 csv
     *
     * @param string $table       table
     * @param string[] $fields      if empty=* else array of fields
     * @param mixed[] $params      params array for where
     * @param string|bool $file        path to file, false to tempfile
     * @param bool $return      true return csv else return rows of csv
     * @param string $delimiter   delimiter of csv default ;
     * @param string $enclosure   enclosure of csv default "
     * @param string $escape_char escape_char of csv default \
     *
     * @return int|string
     */
    public static function export2Csv(string $table, array $fields = array(), array $params = array(), $file = false, bool $return = false, string $delimiter = ';', string $enclosure = '"', string $escape_char = "\\")
    {
        self::addToStats(__FUNCTION__);
        $fieldsTxt = '*';
        if (!empty($fields) && is_array($fields)) {
            $fieldsArray = array();
            foreach ($fields as $field) {
                $fieldsArray[] = self::clean4DB($field, true);
            }
            $fieldsTxt = implode(", ", $fieldsArray);
        }
        $query = "SELECT " . $fieldsTxt . " FROM " . self::clean4DB($table, true) . " WHERE " . self::getWhereFromParam($params);

        $totalResult = DB::selectQuery("SELECT count(*) as `total` FROM " . self::clean4DB($table, true) . " WHERE " . self::getWhereFromParam($params) . ";");
        $total = $totalResult[0]['total'];
        $chunk = 1000;

        if ($file == false) {
            $filename = tempnam(sys_get_temp_dir(), 'CSV');
            $return = true;
        } else {
            $filename = $file;
        }
        $fp = fopen($filename, 'w');

        $a = 0;

        while ($a < $total) {
            $query_limit = $query . ' LIMIT ' . $a . ',' . $chunk;

            $result = self::query($query_limit);

            if ($result instanceof \mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    if ($a == 0) {
                        fputcsv($fp, array_keys($row), $delimiter, $enclosure, $escape_char);
                    }
                    array_walk($row, array('self', 'cleanFromDB4Csv'));
                    fputcsv($fp, $row, $delimiter, $enclosure, $escape_char);
                    $a++;
                }
                $result->free();
            }
        }
        fclose($fp);
        if ($return == true) {
            return file_get_contents($filename);
        }
        return $a;
    }

    /**
     * start a transaction
     *
     * @param bool $writeNode
     *
     * @return void
     */
    public static function startTransaction(bool $writeNode = true): void
    {
        self::executeQuery("SET autocommit=0;", $writeNode);
        self::executeQuery("SET unique_checks=0;", $writeNode);
        self::executeQuery("SET foreign_key_checks=0", $writeNode);
        self::executeQuery("START TRANSACTION;", $writeNode);
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
     * @param bool $writeNode
     *
     * @return void
     */
    public static function endTransaction(bool $commit, bool $writeNode = true): void
    {
        if ($commit) {
            self::commitTransaction($writeNode);
        } else {
            self::rollbackTransaction($writeNode);
        }
        self::executeQuery("SET autocommit=1;", $writeNode);
        self::executeQuery("SET unique_checks=1;", $writeNode);
        self::executeQuery("SET foreign_key_checks=1;", $writeNode);
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
    private static function addToStats(string $queryType = ''): void
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

<?php

/**
 * A very simple class for using MySQL.
 *
 * PHP version 7.2+
 *
 * @package TiMMiT/DB-MySQL
 * @author  Tim Schoondergang <tim.schoondergang@TiMMiT.nl>
 */
/** */

namespace TiMMiT\MySQL;

/**
 * MySQL DB connection.
 */
class DB
{
    /**
     * The config vars.
     *
     * @var array
     */
    public static $var = array();

    /**
     * The config fallback vars.
     *
     * @var array
     */
    public static $fallback = array();

    /**
     * The backup active boolean.
     *
     * @var boolean
     */
    public static $fallbackActive = false;

    /**
     * The config write vars.
     *
     * @var array
     */
    public static $write = array();

    /**
     * The write active boolean.
     *
     * @var boolean
     */
    public static $writeActive = false;

    /**
     * Max tries before failure by deadlock
     *
     * @var int
     */
    public static $max_deadlock_count = 5;

    /**
     * Sleep multiplier with a deadlock
     *
     * @var int
     */
    public static $sleep_deadlock_multiplier = 1;

    /**
     * Max tries before failure by gone away
     *
     * @var int
     */
    public static $max_goneaway_count = 5;

    /**
     * Sleep multiplier with a deadlock
     *
     * @var float
     */
    public static $sleep_goneaway_multiplier = 1.5;

    /**
     * The stats vars.
     *
     * @var array
     */
    public static $stats = array();

    /**
     * Created DB connection
     * @var \mysqli
     */
    public static $mlink;

    /**
     * Created DBwrite connection
     * @var \mysqli|null
     */
    public static $mlinkWrite;

    /**
     * The default charset.
     *
     * @var string
     */
    public static $charset = "utf8mb4";

    /**
     * Class constructor.
     * @param array $configVar      settings as assoc array
     * @param array $writeVar      settings as assoc array
     * @param array $fallbackVar      settings as assoc array
     * @return void
     */
    function __construct($configVar, $writeVar = array(), $fallbackVar = array())
    {
        if (is_array($configVar)) {
            self::$var = $configVar;
        }
        if (is_array($writeVar)) {
            self::$write = $writeVar;
        }
        if (is_array($fallbackVar)) {
            self::$fallback = $fallbackVar;
        }
        self::connect();
    }

    /**
     * throw Exception
     * @param string $message
     * @param int $code
     * @return boolean
     */
    function throwException($message = null, $code = null)
    {
        throw new \Exception($message, (int)$code);
    }

    /**
     * Connect to DB
     * @return void
     */
    public static function connect()
    {
        self::addToStats(__FUNCTION__);

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            self::$mlink = mysqli_connect(self::$var['host'], self::$var['username'], self::$var['password'], self::$var['database'], (int)self::$var['port']);
            self::$writeActive = false;
            if (!empty(self::$write) && self::$var != self::$write && !empty(self::$write['host'])) {
                self::$mlinkWrite = mysqli_connect(self::$write['host'], self::$write['username'], self::$write['password'], self::$write['database'], (int)self::$write['port']);
                self::$writeActive = true;
            }
        } catch (\mysqli_sql_exception $e) {
            if (!empty(self::$fallback) &&  !empty(self::$fallback['host'])) {
                self::$mlink = mysqli_connect(self::$fallback['host'], self::$fallback['username'], self::$fallback['password'], self::$fallback['database'], (int)self::$fallback['port']);

                self::$fallbackActive = true;
                self::$writeActive = false;
            } else {
                throw new \mysqli_sql_exception($e->getMessage(), $e->getCode());
            }
        }

        mysqli_report(MYSQLI_REPORT_OFF); // back to normal reporting

        if (self::ping()) {
            mysqli_set_charset(self::$mlink, self::$charset);
        }
        if (self::$writeActive && self::ping(true)) {
            mysqli_set_charset(self::$mlinkWrite, self::$charset);
            DB::setWsrepSyncWait(true);
        }
    }

    /**
     * Disconnect to DB
     * @return void
     */
    public static function disconnect()
    {
        self::addToStats(__FUNCTION__);
        if (self::$writeActive && self::ping(true)) {
            mysqli_close(self::$mlinkWrite);
        }
        if (self::ping()) {
            mysqli_close(self::$mlink);
        }
    }

    /**
     * reconnect to DB
     * @return void
     */
    public static function reconnect()
    {
        self::addToStats(__FUNCTION__);
        self::disconnect();
        self::connect();
    }

    /**
     * ping to DB
     * @param boolean $writeNode
     * @return boolean
     */
    public static function ping($writeNode = false)
    {
        self::addToStats(__FUNCTION__);
        if ($writeNode) {
            $link = self::$mlinkWrite;
        } else {
            $link = self::$mlink;
        }
        if (is_null($link) || !@mysqli_ping($link)) {
            return false;
        }
        return true;
    }


    /**
     * Update Query function
     * @param string $query
     * @param boolean $writeQuery
     * @param int $resultmode
     * @param int $count
     * @return \mysqli_result|boolean
     */
    private static function query($query, $writeQuery = false, $resultmode = NULL, $count = 0)
    {
        if ($writeQuery && self::$writeActive) {
            $mlink = self::$mlinkWrite;
            $write = true;
        } else {
            $mlink = self::$mlink;
            $write = false;
        }

        self::addToStats(__FUNCTION__);
        if (is_null($resultmode)) {
            $result = mysqli_query($mlink, $query);
        } else {
            $result = mysqli_query($mlink, $query, $resultmode);
        }
        if (!$result) {
            $errorno = mysqli_errno($mlink);
            $error = mysqli_error(self::$mlink);
            switch ($errorno) {
                case 1213:
                    if ($count < self::$max_deadlock_count) {
                        $count++;
                        usleep((int)($count * round(100000, 1000000) * self::$sleep_deadlock_multiplier));
                        $result = self::query($query, $write, $resultmode, ($count + 1));
                    } else {
                        trigger_error("Query Failed:<ul><li>errorno=" . $errorno . "</li><li>failure_count=" . $count . " of max=" . self::$max_deadlock_count . " </li><li>error=" . $error . "</li><li>query=" . $query . "</li></ul>", E_USER_ERROR);
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
                        trigger_error("Query Failed:<ul><li>errorno=" . $errorno . "</li><li>failure_count=" . $count . " of max=" . self::$max_goneaway_count . " </li><li>error=" . $error . "</li><li>query=" . $query . "</li></ul>", E_USER_ERROR);
                    }
                    break;
                default:
                    trigger_error("Query Failed:<ul><li>errorno=" . $errorno . "</li><li>error=" . $error . "</li><li>query= " . $query . "</li></ul>", E_USER_ERROR);
                    break;
            }
        }
        return $result;
    }

    /**
     * Update Query function
     * @param string $query
     * @param boolean $writeNode
     * @return boolean
     */
    public static function updateQuery($query, $writeNode = true)
    {
        self::addToStats(__FUNCTION__);
        return self::query($query, $writeNode);
    }

    /**
     * Delete Query function
     * @param string $query
     * @param boolean $writeNode
     * @return boolean
     */
    public static function deleteQuery($query, $writeNode = true)
    {
        self::addToStats(__FUNCTION__);
        return self::query($query, $writeNode);
    }

    /**
     * execute a query without result function
     * @param string $query
     * @param boolean $writeNode
     * @return boolean
     */
    public static function executeQuery($query, $writeNode = true)
    {
        self::addToStats(__FUNCTION__);
        return self::query($query, $writeNode);
    }

    /**
     * Select Query function
     * @param string $query
     * @param string $keyField
     * @param boolean $writeNode
     * @return array
     */
    public static function selectQuery($query, $keyField = '', $writeNode = false)
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
     * @param string $query
     * @param boolean $writeNode
     * @return int
     */
    public static function insertQuery($query, $writeNode = true)
    {
        self::addToStats(__FUNCTION__);
        self::query($query, $writeNode);
        return mysqli_insert_id(self::$mlink);
    }

    /**
     * Check if table exists
     * @param string $table
     * @return boolean
     */
    public static function checkTableExist($table)
    {
        self::addToStats(__FUNCTION__);
        return self::checkForResult("SHOW TABLES LIKE '" . $table . "';");
    }

    /**
     * Check if column exists in table
     * @param string $table
     * @param string $column
     * @return boolean
     */
    public static function checkIfColumnExistInTable($table, $column)
    {
        self::addToStats(__FUNCTION__);
        return self::checkForResult("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $column . "';");
    }

    /**
     * Check if query gives result
     * @param string $query
     * @return boolean
     */
    public static function checkForResult($query)
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
     * @param string $table table where you want to check the field
     * @param string $field colomn to check
     * @param string $primaryKeyFieldID primary id
     * @return boolean
     */
    public static function checkIsNull($table, $field, $primaryKeyFieldID)
    {
        self::addToStats(__FUNCTION__);
        $primaryKeyFieldResult = self::query("SHOW INDEX FROM " . $table);
        $primaryKeyFieldRow = mysqli_fetch_array($primaryKeyFieldResult);
        return self::checkForResult("SELECT " . $field . " FROM " . $table . " WHERE " . $primaryKeyFieldRow["Column_name"] . " = " . $primaryKeyFieldID . " AND " . $field . " IS NULL;");
    }


    /**
     * clean variable for DB against SQL injection
     * @param string $value
     * @param boolean $key default false
     * @return string
     */
    public static function clean4DB($value, $key = false)
    {
        self::addToStats(__FUNCTION__);
        if (is_object($value)) {
            trigger_error('is object:' . var_export($value, true));
        }
        $value = addcslashes(mysqli_real_escape_string(self::$mlink, $value), "%_");
        if ($key != false) {
            $value = str_replace('\_', "_", $value);
        }
        return $value;
    }

    /**
     * clean variable from DB against slashes against SQL injection
     * @param string $value
     * @return string
     */
    public static function cleanFromDB($value)
    {
        self::addToStats(__FUNCTION__);
        $value = stripcslashes($value);
        return $value;
    }

    /**
     * Make a hashed Id from seed
     * @param string $hash
     * @param int $len
     * @param mixed $seed
     * @param string $add
     * @return string
     */
    public static function makeId($hash = '', $len = 1, $seed = "", $add = '')
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
     * @return string
     */
    public static function getCharSet()
    {
        self::addToStats(__FUNCTION__);
        return mysqli_character_set_name(self::$mlink);
    }

    /**
     * Make where statement from parameters
     * @param array|false $param      paramater or parameters as assoc array
     * @param string $table
     * @return string
     */
    public static function getWhereFromParam($param = false, $table = '')
    {
        self::addToStats(__FUNCTION__);
        if (is_array($param) && !empty($param)) {
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
                                                $value = self::clean4DB($value);
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
                                    $value = self::clean4DB($par['value']);
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
     * dbDelta on DB
     * @param string $queries
     * @param boolean $execute
     * @return array
     */
    public static function dbDelta($queries = '', $execute = true)
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
         * @since 3.3.0
         *
         * @param array $queries An array of dbDelta SQL queries.
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

        //echo "<hr/><pre>\n".print_r($cqueries, true)."</pre><hr/>";
        //die();

        foreach ($cqueries as $table => $qry) {
            //echo "<hr/><pre>\n";
            if (!self::checkTableExist($table)) {
                continue;
            }
            //echo "</pre><hr/>";
            //die();

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

            // todo: Remove this?
            //echo "<hr/><pre>\n".print_r(strtolower($table), true).":\n".print_r($cqueries, true)."</pre><hr/>";

            // For every field line specified in the query.
            foreach ($flds as $fld) {

                // Extract the field name.
                preg_match("|^([^ ]*)|", trim($fld), $fvals);
                //var_dump($fvals);
                $fieldname = trim($fvals[1], '`');
                //var_dump($fieldname);

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

            //echo "<hr/><pre>\n".print_r($cfields, true)."</pre><hr/>";
            //echo "<hr/><pre>\n".print_r($tablefields, true)."</pre><hr/>";
            //die();

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
                    //echo "{$cfields[strtolower($tablefield['Field'])]}<br>";
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
            //echo "<hr/><pre>\n".print_r($cqueries, true)."</pre><hr/>";
            //die();

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
                        if ($index_columns != '') $index_columns .= ',';

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

                    //echo "<pre>\n";
                    //var_dump($index_string);
                    $index_strings = array(
                        "$index_string ($index_columns)",
                        "$index_string ($alt_index_columns)",
                    );
                    //echo "index_strings:\n";
                    //var_dump($index_strings);
                    //echo "indices:\n";
                    //var_dump($indices);
                    //echo "</pre>";
                    foreach ($index_strings as $index_string) {
                        if (!(($aindex = array_search($index_string, $indices)) === false)) {
                            unset($indices[$aindex]);
                            //break;
                            // todo: Remove this?
                            //echo "<pre style=\"border:1px solid #ccc;margin-top:5px;\">{$table}:<br />Found index:".$index_string."</pre>\n";
                            break;
                        }
                    }
                    // todo: Remove this?
                    //else echo "<pre style=\"border:1px solid #ccc;margin-top:5px;\">{$table}:<br /><b>Did not find index:</b>".$index_string."<br />".print_r($indices, true)."</pre>\n";
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
                // todo: Remove this?
                //echo "<pre style=\"border:1px solid red;margin-top:5px;\">".print_r($query, true)."</pre>\n";
                self::query($query, true);
            }
        }
        //die();
        return $for_update;
    }

    /**
     * clean variable from DB against slashes against SQL injection for export csv
     * @param string $value
     * @param string $key
     * @return void
     */
    public static function cleanFromDB4Csv(&$value, $key)
    {
        self::addToStats(__FUNCTION__);
        $value = self::cleanFromDB($value);
    }

    /**
     * Export function 2 csv
     * @param string $table table
     * @param array $fields if empty=* else array of fields
     * @param array $params params array for where
     * @param string|boolean $file path to file, false to tempfile
     * @param boolean $return true return csv else return rows of csv
     * @param string $delimiter delimiter of csv default ;
     * @param string $enclosure enclosure of csv default "
     * @param string $escape_char escape_char of csv default \
     * @return int|string
     */
    public static function export2Csv($table, $fields = array(), $params = array(), $file = false, $return = false, $delimiter = ';', $enclosure = '"', $escape_char = "\\")
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
     * @param boolean $writeNode
     * @return void
     */
    public static function startTransaction($writeNode = true)
    {
        self::executeQuery("SET autocommit=0;");
        self::executeQuery("SET unique_checks=0;");
        self::executeQuery("SET foreign_key_checks=0");
        self::executeQuery("START TRANSACTION;");
    }

    /**
     * stop a transaction
     * @param boolean $commit
     * @param boolean $writeNode
     * @return void
     */
    public static function endTransaction($commit, $writeNode = true)
    {
        if ($commit) {
            self::executeQuery("COMMIT;");
        } else {
            self::executeQuery("ROLLBACK;");
        }
        self::executeQuery("SET autocommit=1;");
        self::executeQuery("SET unique_checks=1;");
        self::executeQuery("SET foreign_key_checks=1;");
    }

    /**
     * setWsrepSyncWait
     * @param bool $on
     * @return void
     */
    public static function setWsrepSyncWait(bool $on = true): void
    {
        if ($on) {
            mysqli_query(self::$mlink, "SET SESSION wsrep_sync_wait = 1;");
        } else {
            mysqli_query(self::$mlink, "SET SESSION wsrep_sync_wait = 0;");
        }
    }

    /**
     * add item tot stats
     * @param string $queryType Soort Query
     * @return void
     */
    private static function addToStats($queryType = '')
    {
        if (!isset(self::$stats[$queryType])) {
            self::$stats[$queryType] = 0;
        }
        self::$stats[$queryType]++;
    }

    /**
     * get the stats
     * @return array stats
     */
    public static function getStats()
    {
        return self::$stats;
    }
}

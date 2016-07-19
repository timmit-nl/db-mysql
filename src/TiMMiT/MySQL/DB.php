<?php
/**
 * A very simple class for using MySQL.
 * 
 * PHP version 5.3+
 * 
 * @package TiMMiT/DB-MySQL
 * @author  Tim Schoondergang <tim.schoondergang@TiMMiT.nl>
 */
/** */
namespace TiMMiT\MySQL;

/**
 * MySQL DB connection.
 */
	class DB {

    /**
     * The config vars.
     * 
     * @var array
     */			
		public static $var = array();

    /**
     * Max tries before failure by deadlock
     * 
     * @var int
     */
		public static $max_deadlock_count = 5;		

    /**
     * The stats vars.
     * 
     * @var array
     */			
		public static $stats = array();
 		
 		/**
    * Created DB connection
    * @var DB
    */
		public static $mlink;

    /**
     * The default charset.
     * 
     * @var string
     */		
		public static $charset = "utf8";
		
    /**
    * Class constructor.
    * @param array $array      settings as assoc array
    */
		function __construct($array) {
			if (is_array($array)) {
				self::$var = $array;
				self::connect();
			}
		}

    /**
    * static function to run before called function is run
    * @param string $name      
    * @param array $arguments      
    */
    public static function __callStatic($name, $arguments) {
    	self::Add2Stats($name);
    	return forward_static_call_array(array(__CLASS__,'_'.$name),$arguments);
    }


		
    /**
     * throw Exception
     * @param string $message
     * @param string $code
     * @return boolean
     */			
		function throwException($message = null,$code = null) {
    	throw new Exception($message,$code);
		}		

    /**
     * Connect to DB
     * @return void
     */	
		public static function _connect() {
			self::$mlink = mysqli_connect(self::$var["host"], self::$var["username"], self::$var["password"], self::$var["database"], (int)self::$var["port"]) or trigger_error("Connection Failed: kon geen verbinding maken met de database!",E_USER_ERROR);
			if (self::$mlink) {
				mysqli_set_charset(self::$mlink,'utf8'); 
			}
		}

    /**
     * Update Query function
     * @param string $query
     * @return boolean
     */			
		private static function _query($query,$count=0) {
			$result = mysqli_query(self::$mlink,$query);
			if (!$result) {
				$errorno = mysqli_errno(self::$mlink);
				if ($errorno == 1213){
					if($count < self::$max_deadlock_count){
						self::query($query,($count+1));
					} else {
						trigger_error("Query Failed:<ul><li>errorno=" . mysqli_errno(self::$mlink) ."</li><li>failure_count=" . $count ." of max=" . self::$max_deadlock_count ." </li><li>error=" . mysqli_error(self::$mlink) . "</li><li>query=" . $query . "</li></ul>",E_USER_ERROR);
					}
				} else {
					trigger_error("Query Failed:<ul><li>errorno=" . mysqli_errno(self::$mlink) ."</li><li>error=" . mysqli_error(self::$mlink) . "</li><li>query= " . $query . "</li></ul>",E_USER_ERROR);
			 	}
			}
			return $result;

		}

    /**
     * Update Query function
     * @param string $query
     * @return boolean
     */			
		public static function _updateQuery($query) {
			return self::query($query);
		}

    /**
     * Delete Query function
     * @param string $query
     * @return boolean
     */			
		public static function _deleteQuery($query) {
			return self::query($query);
		}		

    /**
     * Select Query function
     * @param string $query
     * @param string $keyField
     * @return array
     */	
		public static function _selectQuery($query,$keyField='') {
			$result = self::query($query);
			$return = array();
			if($result instanceof \mysqli_result){
				//while ($row = mysqli_fetch_assoc($result)) {
				while ($row = $result->fetch_assoc()) {
					if(!empty($keyField) && isset($row[$keyField])){
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
     * @return int
     */	
		public static function _insertQuery($query) {
			self::query($query);
			return mysqli_insert_id(self::$mlink);
		}
		
    /**
     * Check if table exists
     * @param string $table
     * @return boolean
     */			
		public static function _checkTableExist($table) {
			return self::checkForResult("SHOW TABLES LIKE '".$table."';");
		}

    /**
     * Check if column exists in table
     * @param string $table
     * @param string $column
     * @return boolean
     */	
		public static function _checkIfColumnExistInTable($table,$column) {
			return self::checkForResult("SHOW COLUMNS FROM `".$table."` LIKE '".$column."';");
		}

    /**
     * Check if query gives result
     * @param string $query
     * @return boolean
     */			
		public static function _checkForResult($query) {
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
		public static function _checkIsNull($table, $field, $primaryKeyFieldID) {
			$primaryKeyFieldResult = self::query("SHOW INDEX FROM ".$table);
			$primaryKeyFieldRow = mysqli_fetch_array($primaryKeyFieldResult);
			return self::checkForResult("SELECT ".$field." FROM ".$table." WHERE ".$primaryKeyFieldRow["Column_name"]." = ".$primaryKeyFieldID." AND ".$field." IS NULL;");
		}
		
		
    /**
     * Clean variable for DB against SQL injection
     * @param string $value
     * @param boolean $key default false
     * @return string
     */
		public static function _Clean4DB($value,$key=false) {
			$value = addcslashes(mysqli_real_escape_string(self::$mlink,$value), "%_");
			if($key!=false){
				$value = str_replace('\_',"_",$value);
			}
			return $value;
		}

    /**
     * Clean variable from DB against slashes against SQL injection
     * @param string $value
     * @return string
     */
    public static function _CleanFromDB($value) {
			$value = stripcslashes($value);
			return $value;
		}

    /**
     * Make a hashed ID from seed
     * @param string $hash
     * @param int $len
     * @param mix $seed
     * @param string $add
     * @return string
     */
		public static function _MakeID($hash='',$len=1,$seed="",$add=''){
			switch($hash):
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
			if(empty($len)){
				$length = 0;
			} else {
				$length = max($len,$length);
			}
			if(!empty($seed)){
				if(!is_string($seed)){
					$seed = serialize($seed);
				}
			} else {
				$seed = uniqid(rand().time(), true);
			}
			$id = base_convert(hash($hash,$seed),16,36);
			$erbij = $length-strlen($id);
			if($erbij>0){
				if($add==''){
					$stap = 10;
					for($a=0;$a<$erbij;$a+=$stap){
						$erbij2 = min(($erbij-$a),$stap);
						$rand = rand(pow(36,($erbij2-1)),(pow(36,$erbij2)-1));
						$id .= base_convert($rand,10,36);
					}
				} else {
					for($a=0;$a<$erbij;$a++){
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
		public static function _GetCharSet() {
			return mysqli_character_set_name(self::$mlink);
		}
		
    /**
     * Make where statement from parameters
     * @param array $param      paramater or parameters as assoc array
     * @param string $table
     * @return string
     */	
     public static function _GetWhereFromParam($param=1,$table=''){
			if(is_array($param) && count($param)>0){
				$where = "";
				$divider_operator = 'AND';
				foreach($param as $par){
					if(isset($par['divider_operator'])){
						$divider_operator = $par['divider_operator'];
					}
					if(is_array(@$par['param'])){
						//var_dump($par['param']);
						$where .= '('.self::GetWhereFromParam($par['param'],$table).') '.self::Clean4DB($divider_operator)." ";
					} else {
						$value = self::Clean4DB(@$par['value']);
						$value = str_replace('\_',"_",$value);
						$value = str_replace('*',"%",$value);
						if(!empty($table)){
							$where .= "`".$table."`.";
						}
						$where .= "`".self::Clean4DB(@$par['param'],1)."` ".self::Clean4DB(@$par['operator'])." '".$value."' ".self::Clean4DB($divider_operator)." ";
					}
				}
				if(isset($par['divider_operator'])){
					$divider_operator = $par['divider_operator'];
				}
				$where = substr($where, 0, (0-strlen(" ".self::Clean4DB($divider_operator)." ")));					
			} else {
				$where = "1=1";
			}
			return $where;
		}


		public static function _dbDelta( $queries = '', $execute = true ) {
    	// Separate individual queries into an array
    	if ( !is_array($queries) ) {
        $queries = explode( ';', $queries );
        $queries = array_filter( $queries );
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
        if ( preg_match( "|CREATE TABLE ([^ ]*)|", $qry, $matches ) ) {
            $cqueries[ trim( $matches[1], '`' ) ] = $qry;
            $for_update[trim( $matches[1], '`' )] = 'Created table '.$matches[1];
        } elseif ( preg_match( "|CREATE DATABASE ([^ ]*)|", $qry, $matches ) ) {
            array_unshift( $cqueries, $qry );
        } elseif ( preg_match( "|INSERT INTO ([^ ]*)|", $qry, $matches ) ) {
            $iqueries[] = $qry;
        } elseif ( preg_match( "|UPDATE ([^ ]*)|", $qry, $matches ) ) {
            $iqueries[] = $qry;
        } else {
            // Unrecognized query type
        }
    	}

      //echo "<hr/><pre>\n".print_r($cqueries, true)."</pre><hr/>";
			//die();

	    foreach ( $cqueries as $table => $qry ) { 
	    	//echo "<hr/><pre>\n";
	    	if(!self::checkTableExist($table)){
	    		continue;
	    	}
     		//echo "</pre><hr/>";
				//die();	    	
	    	
        // Fetch the table column structure from the database
        $tablefields = self::selectQuery("DESCRIBE `{$table}`;");

        if(!$tablefields){
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
            $fieldname = trim( $fvals[1], '`' );
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
            	$fld = str_replace("`".$fieldname."`",$fieldname,$fld);
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
                preg_match("|".$tablefield['Field']." ([^ ]*( unsigned)*( zerofill)?)|i", $cfields[strtolower($tablefield['Field'])], $matches);
								//echo "<hr/><pre>\n".print_r(array("|".$tablefield['Field']." ([^ ]*( unsigned)*( zerofill)?)|i", $cfields[strtolower($tablefield['Field'])],$matches), true)."</pre><hr/>";

                $fieldtype = $matches[1];
								
                // Is actual field type different from the field type in query?
                if ($tablefield['Type'] != $fieldtype) {
                    // Add a query to change the column type
                    $cqueries[] = "ALTER TABLE `{$table}` CHANGE COLUMN `{$tablefield['Field']}` " . $cfields[strtolower($tablefield['Field'])];
                    $for_update[$table.'.'.$tablefield['Field']] = "Changed type of {$table}.{$tablefield['Field']} from {$tablefield['Type']} to {$fieldtype}";
                }
 
                // Get the default value from the array
                    // todo: Remove this?
                    //echo "{$cfields[strtolower($tablefield['Field'])]}<br>";
                if (preg_match("| DEFAULT '(.*?)'|i", $cfields[strtolower($tablefield['Field'])], $matches)) {
                    $default_value = $matches[1];
                    if ($tablefield['Default'] != $default_value) {
                        // Add a query to change the column's default value
                        $cqueries[] = "ALTER TABLE `{$table}` ALTER COLUMN `{$tablefield['Field']}` SET DEFAULT '{$default_value}'";
                        $for_update[$table.'.'.$tablefield['Field']] = "Changed default value of {$table}.{$tablefield['Field']} from {$tablefield['Default']} to {$default_value}";
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
            $for_update[$table.'.'.$fieldname] = 'Added column '.$table.'.'.$fieldname;
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
                $index_ary[$keyname]['unique'] = ($tableindex['Non_unique'] == 0)?true:false;
                $index_ary[$keyname]['index_type'] = $tableindex['Index_type'];
            }
 
            // For each actual index in the index array.
            foreach ($index_ary as $index_name => $index_data) {
 
                // Build a create string to compare to the query.
                $index_string = '';
                if ($index_name == 'PRIMARY') {
                    $index_string .= 'PRIMARY ';
                } elseif ( $index_data['unique'] ) {
                    $index_string .= 'UNIQUE ';
                }
                if ( 'FULLTEXT' === strtoupper( $index_data['index_type'] ) ) {
                    $index_string .= 'FULLTEXT ';
                }
                $index_string .= 'KEY ';
                if ($index_name != 'PRIMARY') {
                    $index_string .= "`".$index_name."`";
                }
                $index_columns = '';
 
                // For each column in the index.
                foreach ($index_data['columns'] as $column_data) {
                    if ($index_columns != '') $index_columns .= ',';
 
                    // Add the field to the column list string.
                    $index_columns .= "`".$column_data['fieldname']."`";
                    if ($column_data['subpart'] != '') {
                        $index_columns .= '('.$column_data['subpart'].')';
                    }
                }
 
                // The alternative index string doesn't care about subparts
                $alt_index_columns = preg_replace( '/\([^)]*\)/', '', $index_columns );
 
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
                foreach ( $index_strings as $index_string ) {
                    if ( ! ( ( $aindex = array_search( $index_string, $indices ) ) === false ) ) {
                        unset( $indices[ $aindex ] );
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
        foreach ( (array) $indices as $index ) {
            // Push a query line into $cqueries that adds the index to that table.
            $cqueries[] = "ALTER TABLE `{$table}` ADD $index";
            $for_update[] = 'Added index ' . $table . ' ' . $index;
        }
 
        // Remove the original table creation query from processing.
        unset( $cqueries[ $table ], $for_update[ $table ] );
    	}
 
    $allqueries = array_merge($cqueries, $iqueries);
    if ($execute) {
        foreach ($allqueries as $query) {
            // todo: Remove this?
            //echo "<pre style=\"border:1px solid red;margin-top:5px;\">".print_r($query, true)."</pre>\n";
            self::query($query);
        }
    }
 		//die();
    return $for_update;
}



		
    /**
     * add item tot stats
     * @param string $queryType Soort Query
     * @return void
     */	
		private static function Add2Stats($queryType=''){
			if(!isset(self::$stats[$queryType])){
				self::$stats[$queryType] = 0;
			}
			self::$stats[$queryType]++;
		}
		
    /**
     * get the stats
     * @return array stats
     */	
		public static function GetStats(){
			return self::$stats;
		}
		
	}
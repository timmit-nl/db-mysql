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
			$result = self::selectQuery($query);
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
			$primaryKeyFieldResult = self::selectQuery("SHOW INDEX FROM ".$table);
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
     * @param string|array $param      paramater or parameters as assoc array
     * @param string $operator
     * @param string $value
     * @param string $divider_operator
     * @return string
     */	
     public static function _GetWhereFromParam($param=1,$operator='=',$value=1,$divider_operator='AND'){
			if(is_array($param)){
				$where = "";
				if(count($param)>0){
					foreach($param as $par){
						if(isset($par['divider_operator'])){
							$divider_operator = $par['divider_operator'];
						}
						if(is_array(@$par['param'])){
							//var_dump($par['param']);
							$where .= '('.DB::GetWhereFromParam($par['param']).') '.DB::Clean4DB($divider_operator)." ";
						} else {
							$value = DB::Clean4DB(@$par['value']);
							$value = str_replace('\_',"_",$value);
							$value = str_replace('*',"%",$value);
						
							$where .= "`".str_replace('\_',"_",DB::Clean4DB(@$par['param']))."` ".DB::Clean4DB(@$par['operator'])." '".$value."' ".DB::Clean4DB($divider_operator)." ";
						}
					}
					if(isset($par['divider_operator'])){
						$divider_operator = $par['divider_operator'];
					}
					$where = substr($where, 0, (0-strlen(" ".DB::Clean4DB($divider_operator)." ")));					
				} else {
					$where = "1=1";
				}
			} else {
				if($param==1){
					$where = "1".DB::Clean4DB($operator).DB::Clean4DB($value);
				} else {
					$value = DB::Clean4DB($value);
					$value = str_replace('\_',"_",$value);
					$value = str_replace('*',"%",$value);
					$where = "`".str_replace('\_',"_",DB::Clean4DB($param))."` ".DB::Clean4DB($operator)." '".DB::Clean4DB($value)."'";
				}
			}
			return $where;
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
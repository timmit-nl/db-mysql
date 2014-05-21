<?php
	////////////////////////////////////////////////////////////////////////////
	// DB Class Versie 2.2 (09-08-2010)																				
	// gebaseerd op de DB class van Ferenc Franke (25-07-2007)								
	// (c)  TimmiT.nl																													
	// Geschreven door: Tim Schoondergang																			
	////////////////////////////////////////////////////////////////////////////

	class DB {
		public static $var = array();
		public static $mlink;
		public static $charset = "utf8";
		
		function __construct($array) {
			if (is_array($array)) {
				self::$var = $array;
				self::connect();
			}
		}
		
		function __destruct() {
			mysqli_close(self::$mlink);
		}		
		
		function throwException($message = null,$code = null) {
    	throw new Exception($message,$code);
		}		

		public static function connect() {
			self::$mlink = mysqli_connect(self::$var["host"], self::$var["username"], self::$var["password"], self::$var["database"], (int)self::$var["port"]) or trigger_error("Connection Failed: kon geen verbinding maken met de database!",E_USER_ERROR);
			if (self::$mlink) {
				//$select_db = mysqli_select_db(self::$mlink,$this->var["database"]) or trigger_error("Connection Failed: kon de database niet selecteren!"); 
				mysqli_set_charset(self::$mlink,'utf8'); 
			}
		}
		
		public static function updateQuery($query) {
			//klkl();
			$result = mysqli_query(self::$mlink,$query) or trigger_error("Query Failed:<ul><li>errorno=" . mysqli_errno(self::$mlink) ."</li><li>error=" . mysqli_error(self::$mlink) . "</li><li>query= " . $query . "</li></ul>",E_USER_ERROR);
			//$result = mysqli_query(self::$mlink,$query) or trigger_error("Query Failed:<ul><li>errorno=" . mysqli_errno(self::$mlink) ."</li><li>error=" . mysqli_error(self::$mlink) . "</li><li>query= " . $query . "</li></ul>");
		}
		
		public static function deleteQuery($query) {
			$result = mysqli_query(self::$mlink,$query) or trigger_error("Query Failed:<ul><li>errorno=" . mysqli_errno(self::$mlink) ."</li><li>error=" . mysqli_error(self::$mlink) . "</li><li>query= " . $query . "</li></ul>",E_USER_ERROR);
		}		

		public static function selectQuery($query) {
			$result = mysqli_query(self::$mlink,$query) or trigger_error("Query Failed:<ul><li>errorno=" . mysqli_errno(self::$mlink) ."</li><li>error=" . mysqli_error(self::$mlink) . "</li><li>query= " . $query . "</li></ul>",E_USER_ERROR);
			$return = array();
			while ($row = mysqli_fetch_assoc($result)) {
				$return[] = $row;
			}
			return $return;
		}		

		public static function insertQuery($query) {
			mysqli_query(self::$mlink,$query) or trigger_error("Query Failed:<ul><li>errorno=" . mysqli_errno(self::$mlink) ."</li><li>error=" . mysqli_error(self::$mlink) . "</li><li>query= " . $query . "</li></ul>",E_USER_ERROR);
			return mysqli_insert_id(self::$mlink);
		}
		
		public static function checkTableExist($table) {
			if(mysqli_num_rows(mysqli_query(self::$mlink,"SHOW TABLES LIKE '".$table."'"))) {
			 	return true;
			} else {
				return false;	
			}
		}
		
		public static function checkForResult($query) {
			$result = mysqli_query(self::$mlink,$query) or trigger_error("Query Failed:<ul><li>errorno=" . mysqli_errno(self::$mlink) ."</li><li>error=" . mysqli_error(self::$mlink) . "</li><li>query= " . $query . "</li></ul>",E_USER_ERROR);
			if (mysqli_num_rows(self::$mlink,$result) > 0) {
				return true;
			} else {
				return false;
			}
		}
		
		public static function checkIsNull($table, $field, $primaryKeyFieldID) {
			// var $table = De tabel waaruit je een veld wilt controleren
			// var $field = De kolom waarin je een waarde wilt controleren
			// var $primaryKeyFieldID is de ID van de regel waarin de kolom staat.
			$primaryKeyFieldResult = mysqli_query(self::$mlink,"SHOW INDEX FROM ".$table);
			$primaryKeyFieldRow = mysqli_fetch_array($primaryKeyFieldResult);
			if(mysqli_num_rows(mysqli_query(self::$mlink,"SELECT ".$field." FROM ".$table." WHERE ".$primaryKeyFieldRow["Column_name"]." = ".$primaryKeyFieldID." AND ".$field." IS NULL"))) {
			 	return true;
			} else {
				return false;	
			}
		}
		
		
		// SQL injection disabelen
		public static function Clean4DB($var) {
			//if(get_magic_quotes_gpc()){
			//	$var = stripslashes($var);
			//}
			return addcslashes(mysqli_real_escape_string(self::$mlink,$var), "%_");;
		}

		// SQL injection disabelen
		public static function CleanFromDB($var) {
			//if(get_magic_quotes_gpc()){
			//	$var = stripslashes($var);
			//}
			return stripcslashes($var);
		}

		// Make ID
		public static function MakeID() {
			return md5(uniqid(rand().time(), true));
		}
		
		public static function GetCharSet() {
			return mysqli_character_set_name(self::$mlink);
		}
		
		// Make Where from param
		public static function GetWhereFromParam($param=1,$operator='=',$value=1,$divider_operator='AND'){
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
					$where = "`".str_replace('\_',"_",DB::Clean4DB($param))."`".DB::Clean4DB($operator)."'".DB::Clean4DB($value)."'";
				}
			}
			return $where;
		}		
		
		
	}
?>
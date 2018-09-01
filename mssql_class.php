<?php 

require_once "mssql_log_class.php";

class MSSQL {

	private $DBhost;
	private $DBuser;
	private $DBpass;
	private $DBname;
	public $pdo;
	private $query;
	private $connected = false;
	private $log;
	private $queryParameters;
	public $rowCount = 0;
	public $columnCount = 0;
	public $queryCount = 0;

	static protected $db_table = "";
	static protected $db_columns = [];

	public $errors = [];

	public function __construct() {
		$config = parse_ini_file('credentials.ini'); 

		$this->DBhost = $config["host"];
		$this->DBuser = $config["username"];
		$this->DBpass = $config["password"];
		$this->DBname = $config["database"];

		$this->log = new Log();
		$this->connect();
	}

	protected function connect() {
		try {
			$this->pdo = new PDO("sqlsrv:Server=" . $this->DBhost . ";Database=" . $this->DBname, $this->DBuser, $this->DBpass);
			$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$this->connected = true;
		} catch (PDOException $e) {
			echo $this->ExceptionLog($e->getMessage());
			die();
		}
	}

	public function closeConnection() {
		$this->pdo = null;
	}

	protected function init($query, $queryParameters = '') {
		if (!$this->connected) {
			$this->connect();
		}
		try {
			$this->queryParameters = $queryParameters;
			$this->query = $this->pdo->prepare($this->buildParams($query, $this->queryParameters));
			if ($this->query === false) {
				echo $this->ExceptionLog(implode(',', $this->pdo->errorInfo()));
				die();
			}

			if (!empty($this->queryParameters)) {
				if (array_key_exists(0, $queryParameters)) {
					$parametersType = true;
					array_unshift($this->queryParameters, "");
					unset($this->queryParameters[0]);
				} else {
					$parametersType = false;
				}
				foreach ($this->queryParameters as $column => $value) {
					$this->query->bindParam($parametersType ? intval($column) : ":" . $column, $this->queryParameters[$column]);
				}
			}
			$this->succes = $this->query->execute();
			$this->queryCount++;
		} catch (PDOException $e) {
			echo $this->ExceptionLog($e->getMessage(), $query);
			die();
		}
		$this->queryParameters = array();
	}

	private function buildParams($query, $params = array()) {
		if (!empty($params)) {
			$array_parameter_found = false;
			foreach ($params as $parameter_key => $parameter) {
				if (is_array($parameter)){
					$array_parameter_found = true;
					$in = "";
					foreach ($parameter as $key => $value){
						$name_placeholder = $parameter_key."_".$key;
						// concatenates params as named placeholders
					    	$in .= ":".$name_placeholder.", ";
						// adds each single parameter to $params
						$params[$name_placeholder] = $value;
					}
					$in = rtrim($in, ", ");
					$query = preg_replace("/:".$parameter_key."/", $in, $query);
					// removes array form $params
					unset($params[$parameter_key]);
				}
			}
			// updates $this->params if $params and $query have changed
			if ($array_parameter_found) $this->queryParameters = $params;
		}
		return $query;
	}

	public function query($query, $params = null, $fetchMode = PDO::FETCH_ASSOC, $class = "") {
		$query = trim($query);
		$rawStatement = explode(" ", $query);
		$this->init($query, $params);
		$statement = strtolower($rawStatement[0]);
		if ($statement === 'select' || $statement === 'show') {
			if (!empty($class)) {
				return $this->query->fetchAll($fetchMode, $class);
			} else {
				return $this->query->fetchAll($fetchMode);
			}
		} elseif ($statement === 'insert' || $statement === 'update' || $statement === 'delete') {
			return $this->query->rowCount();
		} else {
			return null;
		}
	}

	public function lastInsertId() {
		return $this->pdo->lastInsertId();
	}

	public function column($query, $params = null) {
		$this->init($query, $params);
		$resultColumn = $this->query->fetchAll(PDO::FETCH_COLUMN);
		$this->rowCount = $this->query->rowCount();
		$this->columnCount = $this->query->columnCount();
		$this->query->closeCursor();
		return $resultColumn;
	}

	public function row ($query, $params = null, $fetchMode = PDO::FETCH_ASSOC) {
		$this->init($query, $params);
		$resultRow = $this->query->fetch($fetchMode);
		$this->rowCount = $this->query->rowCount();
		$this->columnCount = $this->query->columnCount();
		$this->query->closeCursor();
		return $resultRow;
	}

	public function single($query, $params = null) {
		$this->init($query, $params);
		return $this->query->fetchColumn();
	}

	public function ExceptionLog($message, $sql = '') {
		$exception = "There is some unhandled Exceptions. <br>";
		$exception .= $message;
		$exception .= "<br> Find them in the log file";

		if (!empty($sql)) {
			$message .= "\r\nRaw SQL: " . $sql;
		}

		$this->log->write($message, $this->DBhost . md5($this->DBpass));
		header("HTTP/1.1 500 Internal Server Error");
		header("Status: 500 Internal Server Error");
		return $exception;
	}
}
<?php

	/**
	 * PostgresqlDatabase.class.php
	 *
	 * Copyright 2015- Samuli J�rvel�
	 * Released under GPL License.
	 *
	 * License: http://www.kloudspeaker.com/license.php
	 */

	class PostgresqlDatabase {
		private $host;
		private $user;
		private $pw;
		private $database;
		private $tablePrefix;
		
		private $db = NULL;
		
		public static function createFromConf($conf) {
			if (!isset($conf["user"]) or !isset($conf["password"])) throw new ServiceException("INVALID_CONFIGURATION", "No PostgreSQL db information defined");
			
			if (isset($conf["host"])) $host = $conf["host"];
			else $host = "localhost";
			
			if (isset($conf["database"])) $database = $conf["database"];
			else $database = "kloudspeaker";

			if (isset($conf["table_prefix"])) $tablePrefix = $conf["table_prefix"];
			else $tablePrefix = "";
			
			$db = new PostgresqlDatabase($host, $conf["user"], $conf["password"], $database, $tablePrefix);
			$db->connect();
			if (isset($conf["charset"])) $db->setCharset($conf["charset"]);
			return $db;
		}
		
		public function __construct($host, $user, $pw, $database, $tablePrefix) {
			Logging::logDebug("Postgresql DB: ".$user."@".$host.":".$database."(".$tablePrefix.")");
			$this->host = $host;
			$this->user = $user;
			$this->pw = $pw;
			$this->database = $database;
			$this->tablePrefix = $tablePrefix;
		}
		
		public function type() {
			return "postgresql";
		}

		public function host() {
			return $this->host;
		}
		
		public function user() {
			return $this->user;
		}

		public function password() {
			return $this->pw;
		}

		public function database() {
			return $this->database;
		}

		public function tablePrefix() {
			return $this->tablePrefix;
		}
		
		public function isConnected() {
			return $this->db != NULL;
		}
		
		public function connect($selectDb = TRUE) {
			$parameters = array('host' => $this->host, 'dbname' => $this->database, 'user' => $this->user, 'password' => $this->pw, 'connect_timeout' => 5);
			$parts = array();
			foreach ($parameters as $name => $value) {
				$parts[] = $name . "='" . addcslashes((string) $value, "\\'") . "'";
			}
			try {
				$db = @pg_connect(implode(' ', $parts), PGSQL_CONNECT_FORCE_NEW);
			} catch (Exception $e) {
				throw new ServiceException("INVALID_CONFIGURATION", "Could not connect to database (host=".$this->host.", user=".$this->user.")");
			}
			if (!$db) throw new ServiceException("INVALID_CONFIGURATION", "Could not connect to database (host=".$this->host.", user=".$this->user.")");
			$this->db = $db;
		}
		
		public function setCharset($charset) {
			try {
				$result = @pg_set_client_encoding($this->db, $charset);
			} catch (Exception $e) {
				throw new ServiceException("INVALID_CONFIGURATION", "Could not set PostgreSQL client encoding");
			}
			if ($result !== 0) throw new ServiceException("INVALID_CONFIGURATION", "Could not set PostgreSQL client encoding");
		}
		
		public function databaseExists() {
			return $this->db !== NULL and pg_dbname($this->db) === $this->database;
		}

		public function selectDb() {
			if (!$this->databaseExists()) $this->connect();
		}

		public function table($name) {
			return $this->tablePrefix.$name;
		}
		
		public function update($query) {
			$result = $this->query($query);
			$affected = $result->affected();
			$result->free();
			return $affected;
		}

		public function query($query) {
			if (Logging::isDebug()) Logging::logDebug("DB: ".$query);
			
			try {
				$result = @pg_query($this->db, $query);
			} catch (Exception $e) {
				throw new ServiceException("INVALID_CONFIGURATION", "Error executing query (".$query."): ".pg_last_error($this->db));
			}
			if (!$result)
				throw new ServiceException("INVALID_CONFIGURATION", "Error executing query (".$query."): ".pg_last_error($this->db));
			return new PostgresqlResult($this->db, $result);
		}

		public function queries($sql) {
			$result = $this->query($sql);
			$result->free();
			return TRUE;
		}
		
		public function startTransaction() {
			if ($this->isTransaction()) return;
			$this->query("START TRANSACTION;")->free();
		}

		public function commit() {
			if (!$this->isTransaction()) return;
			if (pg_transaction_status($this->db) === PGSQL_TRANSACTION_INERROR)
				throw new ServiceException("INVALID_CONFIGURATION", "Cannot commit a failed PostgreSQL transaction; rollback is required");
			$this->query("COMMIT;")->free();
		}

		public function rollback() {
			if (!$this->isTransaction()) return;
			$this->query("ROLLBACK;")->free();
		}

		public function isTransaction() {
			if ($this->db === NULL) return FALSE;
			$status = pg_transaction_status($this->db);
			return $status === PGSQL_TRANSACTION_INTRANS or $status === PGSQL_TRANSACTION_INERROR or $status === PGSQL_TRANSACTION_ACTIVE;
		}
		
		public function string($s, $quote = FALSE) {
			if ($s === NULL) return 'NULL';
			if ($quote) return pg_escape_literal($this->db, $s);
			return pg_escape_string($this->db, $s);
		}
		
		public function arrayString($a, $quote = FALSE) {
			$parts = array();
			foreach ($a as $value) {
				$parts[] = $this->string($value, $quote);
			}
			return implode(',', $parts);
		}
		
		public function lastId() {
			return $this->query('SELECT lastval() AS id')->firstValue('id');
		}
	}
	
	class PostgresqlResult {
		private $db;
		private $result;
		
		public function __construct($db, $result) {
			$this->db = $db;
			$this->result = $result;
		}
		
		public function count() {
			return pg_num_rows($this->result);
		}

		public function affected() {
			return pg_affected_rows($this->result);
		}
				
		public function rows() {
			$list = array();
			while ($row = pg_fetch_assoc($this->result)) {
				$list[] = $row;
			}
			$this->free();
			return $list;
		}

		public function values($column) {
			$list = array();
			while ($row = pg_fetch_assoc($this->result)) {
				$list[] = $row[$column];
			}
			$this->free();
			return $list;
		}

		public function valueMap($keyCol, $valueCol = NULL, $valueCol2 = NULL) {
			$list = array();
			while ($row = pg_fetch_assoc($this->result)) {
				if ($valueCol === NULL) {
					$list[$row[$keyCol]] = $row;
				} else if ($valueCol2 !== NULL) {
					$list[$row[$keyCol]] = array($valueCol => $row[$valueCol], $valueCol2 => $row[$valueCol2]);
				} else {
					$list[$row[$keyCol]] = $row[$valueCol];
				}
			}
			$this->free();
			return $list;
		}

		public function listMap($keyCol) {
			$list = array();
			while ($row = pg_fetch_assoc($this->result)) {
				$key = $row[$keyCol];
				if (!isset($list[$key])) $list[$key] = array();
				$list[$key][] = $row;
			}
			$this->free();
			return $list;
		}
		
		public function firstRow() {
			$ret = pg_fetch_assoc($this->result);
			$this->free();
			return $ret === FALSE ? NULL : $ret;
		}

		public function firstValue($column) {
			$row = $this->firstRow();
			return $row === NULL ? NULL : $row[$column];
		}

		public function value($row = 0, $field = 0) {
			if ($row < 0 or $row >= pg_num_rows($this->result)) {
				$this->free();
				return NULL;
			}
			$ret = pg_fetch_result($this->result, $row, $field);
			$this->free();
			return $ret;
		}
		
		public function free() {
			if ($this->result === NULL or $this->result === TRUE or $this->result === FALSE) return;
			pg_free_result($this->result);
			$this->result = NULL;
		}
	}
?>
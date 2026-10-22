<?php

use PHPUnit\Framework\TestCase;

abstract class Kloudspeaker_TestCase extends TestCase {
	static private $CONFIGURATION = array(
		"debug" => FALSE,
		"timezone" => "Europe/Helsinki"
	);
	
	private $pdo;
    protected $responseHandler = null;
    protected $mailer = null;
    protected $sessionId = null;

	protected function setUp(): void {
		parent::setUp();
		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec(file_get_contents(__DIR__ . '/../db/sqlite/sql/install/create_tables.sql'));
		$this->pdo->exec(file_get_contents(__DIR__ . '/../db/sqlite/sql/install/params.sql'));
		$fixture = simplexml_load_file(__DIR__ . '/db.xml');
		if ($fixture === false) {
			throw new RuntimeException('Could not load database test fixture');
		}
		foreach ($fixture->table as $table) {
			$name = (string) $table['name'];
			if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
				throw new RuntimeException('Invalid fixture table name');
			}
			$this->pdo->exec('DELETE FROM "' . $name . '"');
			$columns = array();
			foreach ($table->column as $column) {
				$columnName = (string) $column;
				if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $columnName)) {
					throw new RuntimeException('Invalid fixture column name');
				}
				$columns[] = '"' . $columnName . '"';
			}
			foreach ($table->row as $row) {
				$values = array();
				foreach ($row->children() as $value) {
					$values[] = $value->getName() === 'null' ? null : (string) $value;
				}
				if (!$columns || count($columns) !== count($values)) {
					throw new RuntimeException('Invalid fixture row');
				}
				$statement = $this->pdo->prepare('INSERT INTO "' . $name . '" (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')');
				$statement->execute($values);
			}
		}
	}

	final public function getConnection(): PDO {
		return $this->pdo;
    }
    
    protected function getBackend($extraSettings = array()) {
    	Logging::initialize(self::$CONFIGURATION, "Test");
		$this->responseHandler = new TestResponseHandler();
		$db = PDODatabase::createFromObj($this->pdo, "sqlite");
		$settings = new Settings(array_merge(self::$CONFIGURATION, $extraSettings));
		$backend = new KloudspeakerBackend($settings, $db, $this->responseHandler);
		//$backend->processRequest(new Request());
		return $backend;
    }
    
    protected function processRequest($method, $url, $params = array(), $data = NULL, $extraSettings = array()) {
	    $b = $this->getBackend($extraSettings);
	    if ($this->mailer !== NULL) {
	    	$property = new ReflectionProperty(ServiceEnvironment::class, 'mailer');
	    	$property->setValue($b->env(), $this->mailer);
	    }
	    $parts = strlen($url) > 0 ? explode("/", $url) : array();
	    $r = new Request($this->sessionId, strtolower($method), $url, "test-ip", $parts, $params, $data);
	    $b->processRequest($r);
	    
	    return $this->responseHandler->lastSuccess;
    }
    
	protected function assertEqualArrayValues($a1, $a2) {
		foreach(array_keys($a1) as $k) {
			$this->assertTrue(isset($a2[$k]));
			$v1 = $a1[$k];
			$this->assertEquals($v1, $a2[$k]);
		}
	}
}

class TestResponseHandler {
	public $lastSuccess = FALSE;
	public $lastErrorType = FALSE;
	public $lastFail = FALSE;
	
	public function addListener($l) {}
	
	public $lastDownload = FALSE;

	public function download($filename, $type, $mobile, $stream, $size = NULL, $range = NULL) {
		$this->lastDownload = array('name' => $filename, 'type' => $type, 'content' => is_resource($stream) ? stream_get_contents($stream) : $stream);
	}

	public $lastSentFile = FALSE;

	public function sendFile($file, $name, $type, $mobile, $size = NULL) {
		$this->lastSentFile = array('name' => $name, 'type' => $type, 'content' => file_get_contents($file));
	}

	public function send($filename, $type, $stream, $size = NULL) {
		//TODO
	}
	
	public function html($html) {
		//TODO
	}
	
	public function success($data) {
		$this->lastSuccess = $data;
	}

	public function fail($code, $error, $details = NULL, $data = NULL) {
		$this->lastFail = array($code, $error);
	}

	public function error($type, $details, $data = NULL) {
		$this->lastErrorType = $type;
	}
}
?>
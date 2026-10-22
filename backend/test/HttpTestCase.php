<?php

require_once "TestCase.php";

/**
 * Runs the real r.php entry point with an isolated SQLite file database and the global error handler that
 * production uses (every diagnostic becomes an exception). By default it starts the PHP built-in server on
 * loopback. With KLOUDSPEAKER_HTTP_BASE_URL and KLOUDSPEAKER_HTTP_SHARED_DIR set (the Compose "web" profile) it
 * instead talks to an external Apache that shares that directory and reads its settings from current/settings.json.
 */
abstract class Kloudspeaker_HttpTestCase extends \PHPUnit\Framework\TestCase {
	protected $baseDir;
	protected $host = '127.0.0.1';
	protected $port;
	protected $baseUrl;
	private $server;
	private $pipes = array();

	protected function startServer(array $settings): PDO {
		$external = getenv('KLOUDSPEAKER_HTTP_BASE_URL');
		if ($external) {
			// the web server runs as another user: everything created here and by it must stay accessible to both
			umask(0);
			$this->baseDir = rtrim(getenv('KLOUDSPEAKER_HTTP_SHARED_DIR'), '/') . '/current';
			if (is_dir($this->baseDir)) {
				$this->removeTree($this->baseDir);
			}
		} else {
			$this->baseDir = sys_get_temp_dir() . '/kloudspeaker-http-' . bin2hex(random_bytes(4));
		}
		mkdir($this->baseDir, 0777);
		$dbFile = $this->baseDir . '/db.sqlite';
		$pdo = new PDO('sqlite:' . $dbFile);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec(file_get_contents(__DIR__ . '/../db/sqlite/sql/install/create_tables.sql'));
		$pdo->exec(file_get_contents(__DIR__ . '/../db/sqlite/sql/install/params.sql'));
		foreach (array_keys($settings['plugins'] ?? array()) as $plugin) {
			$sql = __DIR__ . '/../plugin/' . $plugin . '/sqlite/install.sql';
			if (is_file($sql)) {
				$pdo->exec(file_get_contents($sql));
			}
		}

		$settings['db'] = array('type' => 'pdo', 'str' => 'sqlite:' . $dbFile, 'user' => '', 'password' => '');
		$settings += array('debug' => FALSE, 'timezone' => 'Europe/Helsinki', 'host_public_address' => 'http://127.0.0.1');
		file_put_contents($this->baseDir . '/settings.json', json_encode($settings));

		if ($external) {
			chmod($dbFile, 0666);	// SQLite creates it 0644; the web server user must be able to write
			$parts = parse_url($external);
			$this->host = $parts['host'];
			$this->port = $parts['port'] ?? 80;
			$this->baseUrl = rtrim($external, '/');
			return $pdo;
		}

		$probe = stream_socket_server('tcp://127.0.0.1:0');
		$this->port = (int) substr(strrchr(stream_socket_get_name($probe, FALSE), ':'), 1);
		fclose($probe);

		$command = array(PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $this->port, __DIR__ . '/http/router.php');
		$this->baseUrl = 'http://127.0.0.1:' . $this->port;
		$descriptors = array(array('pipe', 'r'), array('file', $this->baseDir . '/server.log', 'a'), array('file', $this->baseDir . '/server.log', 'a'));
		$environment = array('KLOUDSPEAKER_HTTP_TEST_CONFIG' => $this->baseDir . '/settings.json', 'PATH' => getenv('PATH'));
		$this->server = proc_open($command, $descriptors, $this->pipes, dirname(__DIR__), $environment);
		for ($i = 0; $i < 100; $i++) {
			$socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
			if ($socket) {
				fclose($socket);
				return $pdo;
			}
			usleep(50000);
		}
		throw new RuntimeException('Test server did not start: ' . @file_get_contents($this->baseDir . '/server.log'));
	}

	protected function stopServer(): void {
		if ($this->server) {
			proc_terminate($this->server);
			proc_close($this->server);
			$this->server = NULL;
		}
		if ($this->baseDir && is_dir($this->baseDir)) {
			$this->removeTree($this->baseDir);
		}
	}

	protected function removeTree(string $dir): void {
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
			$f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
		}
		rmdir($dir);
	}

	/** Returns array('status' => int, 'headers' => string[], 'body' => string). */
	protected function http(string $method, string $path, array $headers = array(), ?string $body = NULL): array {
		if ($body !== NULL && !preg_grep('/^Content-Type:/i', $headers)) {
			$headers[] = 'Content-Type: application/octet-stream';
		}
		$context = stream_context_create(array('http' => array('method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'ignore_errors' => TRUE, 'timeout' => 20)));
		$responseBody = file_get_contents($this->baseUrl . '/r.php/' . $path, FALSE, $context);
		$responseHeaders = $http_response_header ?? array();
		$status = isset($responseHeaders[0]) && preg_match('#\s(\d{3})\s#', $responseHeaders[0], $m) ? (int) $m[1] : 0;
		return array('status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody === FALSE ? '' : $responseBody);
	}

	protected function json(string $method, string $path, $data = NULL, ?string $session = NULL): array {
		$url = $path . ($session ? ((strpos($path, '?') === FALSE ? '?' : '&') . 'session=' . $session) : '');
		$response = $this->http($method, $url, array('Content-Type: application/json'), $data === NULL ? NULL : json_encode($data));
		$response['json'] = json_decode($response['body'], TRUE);
		return $response;
	}

	protected function createUser(PDO $pdo, int $id, string $name, string $password, string $type = 'a'): void {
		$hasher = new Kloudspeaker_PasswordHash(new Settings(array()));
		$hash = $hasher->createHash($password);
		$pdo->exec("INSERT INTO user (id, name, email, is_group, user_type) VALUES ($id, '$name', '$name@example.test', 0, '$type')");
		$pdo->exec("INSERT INTO user_auth (user_id, type, salt, hash, hint) VALUES ($id, 'pw', '" . $hash['salt'] . "', '" . $hash['hash'] . "', '')");
	}

	protected function login(string $name, string $password): string {
		$response = $this->json('POST', 'session/authenticate', array('username' => $name, 'password' => base64_encode($password)));
		$this->assertSame(200, $response['status'], $response['body']);
		return $response['json']['result']['session_id'];
	}
}

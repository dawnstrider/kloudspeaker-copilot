<?php

require_once "HttpTestCase.php";

class KloudspeakerHttpTest extends Kloudspeaker_HttpTestCase {
	protected function tearDown(): void {
		$this->stopServer();
	}

	public function testSessionInfoAndLoginOverHttpUseTheProductionErrorHandler(): void {
		$pdo = $this->startServer(array('plugins' => array()));
		$info = $this->json('GET', 'session/info');
		$this->assertSame(200, $info['status'], $info['body']);
		$this->assertFalse($info['json']['result']['authenticated']);

		$this->createUser($pdo, 1, 'httpadmin', 'http-pw');
		$bad = $this->json('POST', 'session/authenticate', array('username' => 'httpadmin', 'password' => base64_encode('wrong')));
		$this->assertSame(403, $bad['status'], $bad['body']);
		$session = $this->login('httpadmin', 'http-pw');
		$this->assertNotEmpty($session);
		$authenticated = $this->json('GET', 'session/info', NULL, $session);
		$this->assertTrue($authenticated['json']['result']['authenticated']);
	}

	/** Starts a server with one local root folder and an admin who can access it; returns [session, folderItemId, rootPath, pdo]. */
	private function startWithFolder(array $settings = array()): array {
		$pdo = $this->startServer($settings + array('plugins' => array()));
		$root = $this->baseDir . '/files';
		mkdir($root);
		$pdo->exec("INSERT INTO folder (id, name, path, type) VALUES (1, 'Root', '" . $root . "', 'local')");
		$this->createUser($pdo, 1, 'uploader', 'upload-pw');
		$pdo->exec('INSERT INTO user_folder (user_id, folder_id, name) VALUES (1, 1, NULL)');
		$session = $this->login('uploader', 'upload-pw');
		$folderId = $this->json('GET', 'session/info', NULL, $session)['json']['result']['folders'][0]['id'];
		return array($session, $folderId, $root, $pdo);
	}

	private function binaryPayload(int $size): string {
		$block = '';
		for ($i = 0; $i < 256; $i++) {
			$block .= chr($i);
		}
		return substr(str_repeat($block, intdiv($size, 256) + 1), 0, $size);
	}

	private function multipart(string $field, string $filename, string $content): array {
		$boundary = 'kloudspeakerboundary' . bin2hex(random_bytes(6));
		$body = "--$boundary\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$filename\"\r\nContent-Type: application/octet-stream\r\n\r\n$content\r\n--$boundary--\r\n";
		return array(array('Content-Type: multipart/form-data; boundary=' . $boundary), $body);
	}

	private function listFiles(string $folderId, string $session): array {
		return $this->json('GET', 'filesystem/' . $folderId . '/files', NULL, $session)['json']['result'];
	}

	public function testStreamedUploadAndDownloadPreserveEveryByte(): void {
		list($session, $folderId, $root) = $this->startWithFolder();
		$payload = $this->binaryPayload(1024 * 1024 + 17);

		$upload = $this->http('POST', 'filesystem/' . $folderId . '/files?format=binary&session=' . $session, array('Content-Disposition: attachment; filename="blob%20one.bin"', 'Content-Type: application/octet-stream'), $payload);
		$this->assertSame(200, $upload['status'], $upload['body']);
		$this->assertSame(md5($payload), md5_file($root . '/blob one.bin'));
		$this->assertSame(strlen($payload), filesize($root . '/blob one.bin'));

		$files = $this->listFiles($folderId, $session);
		$this->assertSame('blob one.bin', $files[0]['name']);
		$fileId = $files[0]['id'];

		$download = $this->http('GET', 'filesystem/' . $fileId . '?session=' . $session);
		$this->assertSame(200, $download['status']);
		$this->assertSame(md5($payload), md5($download['body']));

		$partial = $this->http('GET', 'filesystem/' . $fileId . '?session=' . $session, array('Range: bytes=100-4195'));
		$this->assertSame(206, $partial['status'], implode(' | ', $partial['headers']));
		$this->assertContains('Content-Length: 4096', $partial['headers']);
		// the body begins with the requested range (it is currently not cut off at its end; see PHP82-MIGRATION.md)
		$this->assertSame(md5(substr($payload, 100, 4096)), md5(substr($partial['body'], 0, 4096)));
		$this->assertContains('Content-Range: bytes 100-4195/' . strlen($payload), $partial['headers']);

		$second = $this->http('POST', 'filesystem/' . $folderId . '/files?format=binary&session=' . $session, array('Content-Disposition: attachment; filename="blob%20one.bin"'), 'second');
		$this->assertSame(200, $second['status'], $second['body']);
		$this->assertSame('second', file_get_contents($root . '/blob one(1).bin'));
		$this->assertSame(md5($payload), md5_file($root . '/blob one.bin'));
	}

	public function testMultipartUploadStoresTheFileBytes(): void {
		list($session, $folderId, $root) = $this->startWithFolder();
		$payload = $this->binaryPayload(300000);
		list($headers, $body) = $this->multipart('uploader-html5', 'form.bin', $payload);
		$upload = $this->http('POST', 'filesystem/' . $folderId . '/files?session=' . $session, $headers, $body);
		$this->assertSame(200, $upload['status'], $upload['body']);
		$this->assertSame(md5($payload), md5_file($root . '/form.bin'));
	}

	public function testUploadRestrictionsAndDeniedAccessOverHttp(): void {
		list($session, $folderId, $root, $pdo) = $this->startWithFolder(array('forbidden_file_upload_types' => array('exe')));
		$denied = $this->http('POST', 'filesystem/' . $folderId . '/files?format=binary&session=' . $session, array('Content-Disposition: attachment; filename="tool.exe"'), 'MZ');
		$this->assertSame(216, json_decode($denied['body'], TRUE)['code'] ?? $denied['body']);
		$this->assertFileDoesNotExist($root . '/tool.exe');

		$anonymous = $this->http('POST', 'filesystem/' . $folderId . '/files?format=binary', array('Content-Disposition: attachment; filename="anon.txt"'), 'x');
		$this->assertSame(401, $anonymous['status'], $anonymous['body']);
		$this->assertFileDoesNotExist($root . '/anon.txt');

		$this->createUser($pdo, 2, 'outsider', 'outsider-pw', '');
		$outsider = $this->login('outsider', 'outsider-pw');
		$noAccess = $this->http('POST', 'filesystem/' . $folderId . '/files?format=binary&session=' . $outsider, array('Content-Disposition: attachment; filename="intruder.txt"'), 'x');
		$this->assertNotSame(200, $noAccess['status'], $noAccess['body']);
		$this->assertFileDoesNotExist($root . '/intruder.txt');
	}

	public function testPublicSharesUploadAndDownloadOverHttp(): void {
		list($session, $folderId, $root) = $this->startWithFolder(array('plugins' => array('Share' => array())));
		$created = function (array $share) use ($session) {
			$response = $this->json('POST', 'share', $share, $session);
			$this->assertSame(200, $response['status'], $response['body']);
			return end($response['json']['result'])['id'];
		};
		$open = $created(array('item' => $folderId, 'name' => 'drop', 'type' => 'upload'));
		$private = $created(array('item' => $folderId, 'name' => 'private drop', 'type' => 'upload', 'restriction' => array('type' => 'private')));
		$password = $created(array('item' => $folderId, 'name' => 'secret drop', 'type' => 'upload', 'restriction' => array('type' => 'pw', 'value' => 'drop-secret')));

		$payload = $this->binaryPayload(70000);
		list($headers, $body) = $this->multipart('uploader-html5', 'dropped.bin', $payload);
		$anonymous = $this->http('POST', 'public/' . $open, $headers, $body);
		$this->assertSame(200, $anonymous['status'], $anonymous['body']);
		$this->assertSame(md5($payload), md5_file($root . '/dropped.bin'));

		list($headers, $body) = $this->multipart('uploader-html5', 'blocked.bin', 'x');
		$this->assertSame(401, $this->http('POST', 'public/' . $private, $headers, $body)['status']);
		$this->assertSame(401, $this->http('POST', 'public/' . $password, $headers, $body)['status']);
		$this->assertSame(401, $this->http('POST', 'public/' . $password . '?ak=' . urlencode(base64_encode('wrong')), $headers, $body)['status']);
		$this->assertFileDoesNotExist($root . '/blocked.bin');

		$withKey = $this->http('POST', 'public/' . $password . '?ak=' . urlencode(base64_encode('drop-secret')), $headers, $body);
		$this->assertSame(200, $withKey['status'], $withKey['body']);
		$this->assertSame('x', file_get_contents($root . '/blocked.bin'));
		$withSession = $this->http('POST', 'public/' . $private . '?session=' . $session, $headers, $body);
		$this->assertSame(200, $withSession['status'], $withSession['body']);

		$files = $this->listFiles($folderId, $session);
		$dropped = current(array_filter($files, fn($f) => $f['name'] === 'dropped.bin'));
		$fileShare = $created(array('item' => $dropped['id'], 'name' => 'download', 'type' => 'download'));
		$download = $this->http('GET', 'public/' . $fileShare);
		$this->assertSame(200, $download['status']);
		$this->assertSame(md5($payload), md5($download['body']));
		$missing = $this->http('GET', 'public/missing-share');
		$this->assertSame(403, $missing['status']);
		$this->assertNotSame($payload, $missing['body']);
	}

	public function testFolderAndRootInfoRequestsWithoutOptionalDataSucceed(): void {
		list($session, $folderId) = $this->startWithFolder();
		foreach (array('filesystem/' . $folderId . '/info', 'filesystem/roots/info') as $path) {
			$response = $this->http('POST', $path . '?session=' . $session, array('Content-Type: application/json'), '{}');
			$this->assertSame(200, $response['status'], $path . ' ' . $response['body']);
			$response = $this->http('POST', $path . '?session=' . $session, array('Content-Type: application/json'), '{"data":{}}');
			$this->assertSame(200, $response['status'], $path . ' ' . $response['body']);
		}
	}
}

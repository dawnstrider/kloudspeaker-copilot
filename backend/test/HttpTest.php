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
		$this->assertContains('Content-Range: bytes 100-4195/' . strlen($payload), $partial['headers']);
		$this->assertSame(4096, strlen($partial['body']));
		$this->assertSame(md5(substr($payload, 100, 4096)), md5($partial['body']));

		$tail = $this->http('GET', 'filesystem/' . $fileId . '?session=' . $session, array('Range: bytes=' . (strlen($payload) - 1000) . '-'));
		$this->assertSame(206, $tail['status']);
		$this->assertSame(md5(substr($payload, -1000)), md5($tail['body']));

		$single = $this->http('GET', 'filesystem/' . $fileId . '?session=' . $session, array('Range: bytes=7-7'));
		$this->assertSame($payload[7], $single['body']);

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

	public function testPlainFileViewsAreLoggedAsEventsButRangedViewsAreNot(): void {
		list($session, $folderId, $root) = $this->startWithFolder(array('plugins' => array('EventLogging' => array(), 'FileViewerEditor' => array())));
		$payload = $this->binaryPayload(5000);
		$this->http('POST', 'filesystem/' . $folderId . '/files?format=binary&session=' . $session, array('Content-Disposition: attachment; filename="seen.bin"'), $payload);
		$fileId = $this->listFiles($folderId, $session)[0]['id'];
		$count = fn() => $this->json('POST', 'eventlog/query', array('type' => 'filesystem/view'), $session)['json']['result']['total'];
		$this->assertSame(0, $count());

		$view = $this->http('GET', 'filesystem/' . $fileId . '/view?session=' . $session);
		$this->assertSame(200, $view['status']);
		$this->assertSame(md5($payload), md5($view['body']));
		$this->assertSame(1, $count());

		$ranged = $this->http('GET', 'view/' . $fileId . '/content?session=' . $session, array('Range: bytes=10-19'));
		$this->assertSame(206, $ranged['status']);
		$this->assertSame(substr($payload, 10, 10), $ranged['body']);
		$this->assertSame(1, $count());
	}

	private function cookies(array $response): array {
		$cookies = array();
		foreach ($response['headers'] as $header) {
			if (stripos($header, 'Set-Cookie:') === 0) {
				$pair = explode(';', trim(substr($header, 11)), 2);
				list($name, $value) = explode('=', $pair[0], 2);
				$cookies[$name] = array('value' => urldecode($value), 'attributes' => $pair[1] ?? '');
			}
		}
		return $cookies;
	}

	private function authenticated(array $headers = array(), string $query = ''): bool {
		$response = $this->http('GET', 'session/info' . $query, $headers);
		$this->assertSame(200, $response['status'], $response['body']);
		return json_decode($response['body'], TRUE)['result']['authenticated'];
	}

	public function testSessionAndRememberMeCookiesAuthenticateAndAreClearedOnLogout(): void {
		$pdo = $this->startServer(array('plugins' => array()));
		$this->createUser($pdo, 1, 'cookieuser', 'cookie-pw');
		$credentials = array('username' => 'cookieuser', 'password' => base64_encode('cookie-pw'), 'remember' => '1');
		$login = $this->json('POST', 'session/authenticate', $credentials);
		$this->assertSame(200, $login['status'], $login['body']);
		$sessionId = $login['json']['result']['session_id'];
		$cookies = $this->cookies($login);
		$this->assertSame($sessionId, $cookies['kloudspeaker_app_session']['value']);
		$this->assertArrayHasKey('kloudspeaker_app_login', $cookies);
		$this->assertStringContainsString('path=/', $cookies['kloudspeaker_app_session']['attributes']);

		$this->assertFalse($this->authenticated());
		$this->assertTrue($this->authenticated(array('Cookie: kloudspeaker_app_session=' . $sessionId)));
		$this->assertFalse($this->authenticated(array('Cookie: kloudspeaker_app_session=unknown-session')));

		$loginCookie = $cookies['kloudspeaker_app_login']['value'];
		$this->assertTrue($this->authenticated(array('Cookie: kloudspeaker_app_login=' . urlencode($loginCookie))));
		$tampered = substr($loginCookie, 0, -1) . (substr($loginCookie, -1) === 'a' ? 'b' : 'a');
		$this->assertFalse($this->authenticated(array('Cookie: kloudspeaker_app_login=' . urlencode($tampered))));
		$this->assertFalse($this->authenticated(array('Cookie: kloudspeaker_app_login=garbage')));

		$logout = $this->json('POST', 'session/logout', array(), $sessionId);
		$this->assertSame(200, $logout['status'], $logout['body']);
		$this->assertFalse($this->authenticated(array('Cookie: kloudspeaker_app_session=' . $sessionId)));
		$this->assertFalse($this->authenticated(array(), '?session=' . $sessionId));
	}

	public function testInterruptedUploadsAndDownloadsLeaveNoPartialFileAndTheServerKeepsWorking(): void {
		list($session, $folderId, $root) = $this->startWithFolder();
		$payload = $this->binaryPayload(3 * 1024 * 1024);

		// upload: promise 3 MiB, send 1000 bytes, then drop the connection
		$socket = fsockopen($this->host, $this->port, $errno, $errstr, 5);
		$this->assertNotFalse($socket);
		fwrite($socket, "POST /r.php/filesystem/$folderId/files?format=binary&session=$session HTTP/1.1\r\nHost: {$this->host}\r\nContent-Disposition: attachment; filename=\"partial.bin\"\r\nContent-Type: application/octet-stream\r\nContent-Length: " . strlen($payload) . "\r\nConnection: close\r\n\r\n" . substr($payload, 0, 1000));
		fclose($socket);
		for ($i = 0; $i < 60 && array_diff(scandir($root), array('.', '..')); $i++) {
			usleep(50000);	// a web server may still be finishing the request
		}
		$this->assertSame(array(), array_values(array_diff(scandir($root), array('.', '..'))), 'an aborted upload must not leave a file behind');

		// the server still serves a complete upload and download afterwards
		$ok = $this->http('POST', 'filesystem/' . $folderId . '/files?format=binary&session=' . $session, array('Content-Disposition: attachment; filename="whole.bin"'), $payload);
		$this->assertSame(200, $ok['status'], $ok['body']);
		$fileId = $this->listFiles($folderId, $session)[0]['id'];

		// download: read the headers and 1000 body bytes, then drop the connection
		$socket = fsockopen($this->host, $this->port, $errno, $errstr, 5);
		fwrite($socket, "GET /r.php/filesystem/$fileId?session=$session HTTP/1.1\r\nHost: {$this->host}\r\nConnection: close\r\n\r\n");
		$received = '';
		while (strlen($received) < 3000 && !feof($socket)) {
			$received .= fread($socket, 4096);
		}
		fclose($socket);
		$this->assertStringContainsString('200 OK', $received);

		$again = $this->http('GET', 'filesystem/' . $fileId . '?session=' . $session);
		$this->assertSame(md5($payload), md5($again['body']));
		$this->assertSame(md5($payload), md5_file($root . '/whole.bin'));
		$this->assertSame(array('whole.bin'), array_values(array_diff(scandir($root), array('.', '..'))));
	}

	/** Reads a file the application manages through HTTP (the web server may own it with private permissions). */
	private function managedFile(string $session, string $folderId, string $path): ?string {
		$segments = explode('/', $path);
		$name = array_pop($segments);
		foreach ($segments as $segment) {
			$matches = array_filter($this->json('GET', 'filesystem/' . $folderId . '/folders', NULL, $session)['json']['result'], fn($f) => $f['name'] === $segment);
			if (!$matches) {
				return NULL;
			}
			$folderId = current($matches)['id'];
		}
		$matches = array_filter($this->listFiles($folderId, $session), fn($f) => $f['name'] === $name);
		return $matches ? $this->http('GET', 'filesystem/' . current($matches)['id'] . '?session=' . $session)['body'] : NULL;
	}

	private function zipEntries(string $bytes): array {
		$path = $this->baseDir . '/check-' . bin2hex(random_bytes(3)) . '.zip';
		file_put_contents($path, $bytes);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path) === TRUE, 'not a valid zip');
		$entries = array();
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if (substr($name, -1) !== '/') {
				$entries[$name] = md5($zip->getFromIndex($i));
			}
		}
		$zip->close();
		unlink($path);
		ksort($entries);
		return $entries;
	}

	public function testArchiveDownloadCompressAndExtractRoundTripOverHttp(): void {
		list($session, $folderId, $root) = $this->startWithFolder(array('plugins' => array('Archiver' => array())));
		$binary = $this->binaryPayload(200000);
		mkdir($root . '/album/nested', 0777, TRUE);
		file_put_contents($root . '/album/cover.bin', $binary);
		file_put_contents($root . '/album/nested/notes.txt', "caf\xc3\xa9\n");
		file_put_contents($root . '/loose.txt', 'loose');
		$folders = $this->json('GET', 'filesystem/' . $folderId . '/folders', NULL, $session)['json']['result'];
		$albumId = $folders[0]['id'];
		$looseId = current(array_filter($this->listFiles($folderId, $session), fn($f) => $f['name'] === 'loose.txt'))['id'];
		$expected = array('album/cover.bin' => md5($binary), 'album/nested/notes.txt' => md5("caf\xc3\xa9\n"));

		// one-step download of a folder
		$direct = $this->http('GET', 'archiver/download?item=' . $albumId . '&session=' . $session);
		$this->assertSame(200, $direct['status'], substr($direct['body'], 0, 200));
		$this->assertSame($expected, $this->zipEntries($direct['body']));

		// two-step download of several items
		$prepared = $this->json('POST', 'archiver/download', array('items' => array($albumId, $looseId)), $session);
		$this->assertSame(200, $prepared['status'], $prepared['body']);
		$multi = $this->http('GET', 'archiver/download/' . $prepared['json']['result']['id'] . '?session=' . $session);
		$this->assertSame($expected + array('loose.txt' => md5('loose')), $this->zipEntries($multi['body']) + array());
		$again = $this->http('GET', 'archiver/download/' . $prepared['json']['result']['id'] . '?session=' . $session);
		$this->assertNotSame(200, $again['status'], 'a prepared archive can be fetched only once');

		// compress into the managed folder, then extract it next to the archive
		$compress = $this->json('POST', 'archiver/compress', array('items' => array($albumId), 'folder' => $folderId, 'name' => 'packed'), $session);
		$this->assertSame(200, $compress['status'], $compress['body']);
		$this->assertSame($expected, $this->zipEntries($this->managedFile($session, $folderId, 'packed.zip')));
		$duplicate = $this->json('POST', 'archiver/compress', array('items' => array($albumId), 'folder' => $folderId, 'name' => 'packed'), $session);
		$this->assertNotSame(200, $duplicate['status']);

		$zipId = current(array_filter($this->listFiles($folderId, $session), fn($f) => $f['name'] === 'packed.zip'))['id'];
		$extract = $this->json('POST', 'archiver/extract', array('item' => $zipId), $session);
		$this->assertSame(200, $extract['status'], $extract['body']);
		$this->assertSame(md5($binary), md5($this->managedFile($session, $folderId, 'packed_zip/album/cover.bin')));
		$this->assertSame("caf\xc3\xa9\n", $this->managedFile($session, $folderId, 'packed_zip/album/nested/notes.txt'));
	}

	public function testThumbnailsAreGeneratedOverHttpForSupportedImages(): void {
		list($session, $folderId, $root) = $this->startWithFolder(array('enable_thumbnails' => TRUE));
		$image = imagecreatetruecolor(800, 400);
		imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
		ob_start();
		imagepng($image);
		$png = ob_get_clean();
		imagedestroy($image);
		file_put_contents($root . '/photo.png', $png);
		file_put_contents($root . '/plain.txt', 'text');
		$files = $this->listFiles($folderId, $session);
		$photoId = current(array_filter($files, fn($f) => $f['name'] === 'photo.png'))['id'];
		$textId = current(array_filter($files, fn($f) => $f['name'] === 'plain.txt'))['id'];

		$thumbnail = $this->http('GET', 'filesystem/' . $photoId . '/thumbnail?mw=100&mh=100&session=' . $session);
		$this->assertSame(200, $thumbnail['status'], substr($thumbnail['body'], 0, 200));
		$size = getimagesizefromstring($thumbnail['body']);
		$this->assertNotFalse($size, 'the thumbnail is an image');
		$this->assertLessThanOrEqual(100, max($size[0], $size[1]));
		$this->assertSame(2, intdiv(max($size[0], $size[1]), min($size[0], $size[1])));

		$unsupported = $this->http('GET', 'filesystem/' . $textId . '/thumbnail?session=' . $session);
		$this->assertNotSame(200, $unsupported['status']);
		$anonymous = $this->http('GET', 'filesystem/' . $photoId . '/thumbnail');
		$this->assertSame(401, $anonymous['status']);
	}

	private function pdfText(string $bytes): string {
		$path = $this->baseDir . '/text-' . bin2hex(random_bytes(3)) . '.pdf';
		file_put_contents($path, $bytes);
		$text = (string) shell_exec('pdftotext ' . escapeshellarg($path) . ' - 2>/dev/null');
		unlink($path);
		return $text;
	}

	public function testWatermarkedPdfDownloadOverHttpMarksOnlyPdfsForNonExcludedUsers(): void {
		require_once 'vendor/fpdf/fpdf.php';
		$watermarking = array('watermark_text' => 'Downloaded by %username%', 'types' => array('pdf' => 'PDFMarker'), 'exclude_users' => array(2));
		list($session, $folderId, $root, $pdo) = $this->startWithFolder(array('plugins' => array('Watermarking' => $watermarking)));
		$this->createUser($pdo, 2, 'exempt', 'exempt-pw');
		$pdo->exec('INSERT INTO user_folder (user_id, folder_id, name) VALUES (2, 1, NULL)');
		$exemptSession = $this->login('exempt', 'exempt-pw');

		$pdf = new FPDF();
		foreach (array(1, 2) as $page) {
			$pdf->AddPage();
			$pdf->SetFont('Helvetica', '', 14);
			$pdf->Text(20, 30, 'SOURCE PAGE ' . $page);
		}
		$pdf->Output('F', $root . '/report.pdf');
		file_put_contents($root . '/notes.txt', 'plain notes');
		$files = $this->listFiles($folderId, $session);
		$pdfId = current(array_filter($files, fn($f) => $f['name'] === 'report.pdf'))['id'];
		$txtId = current(array_filter($files, fn($f) => $f['name'] === 'notes.txt'))['id'];

		$marked = $this->http('GET', 'filesystem/' . $pdfId . '?session=' . $session);
		$this->assertSame(200, $marked['status'], substr($marked['body'], 0, 300));
		$this->assertStringStartsWith('%PDF', $marked['body']);
		$this->assertNotSame(md5_file($root . '/report.pdf'), md5($marked['body']));
		$text = $this->pdfText($marked['body']);
		$this->assertStringContainsString('SOURCE PAGE 1', $text);
		$this->assertStringContainsString('SOURCE PAGE 2', $text);
		$this->assertStringContainsString('Downloaded by uploader', $text);

		$exempt = $this->http('GET', 'filesystem/' . $pdfId . '?session=' . $exemptSession);
		$this->assertSame(md5_file($root . '/report.pdf'), md5($exempt['body']), 'excluded users receive the original bytes');

		$plain = $this->http('GET', 'filesystem/' . $txtId . '?session=' . $session);
		$this->assertSame('plain notes', $plain['body']);
		$this->assertSame(array('report.pdf', 'notes.txt'), array_values(array_intersect(array('report.pdf', 'notes.txt'), array_diff(scandir($root), array('.', '..')))));
		$this->assertSame(array(), glob(sys_get_temp_dir() . '/Kloudspeaker*'), 'temporary watermarked files are removed');
	}

	private function requireExternalServer(): void {
		if (!getenv('KLOUDSPEAKER_HTTP_BASE_URL')) {
			$this->markTestSkipped('Needs the Apache of the Compose "web" profile');
		}
	}

	public function testShippedHtaccessBlocksDirectAccessToTheIncludeDirectoryOnApache24(): void {
		$this->requireExternalServer();
		$this->startServer(array('plugins' => array()));
		$blocked = $this->http('GET', '../include/Settings.class.php');
		$this->assertSame(403, $blocked['status'], 'the 2.2-style "deny from all" must still work on Apache 2.4');
		$this->assertNotSame(403, $this->http('GET', 'session/info')['status'], 'the API itself stays reachable');
		$open = file_get_contents($this->baseUrl . '/db/DBConnectionFactory.class.php', FALSE, stream_context_create(array('http' => array('ignore_errors' => TRUE))));
		$this->assertStringNotContainsString('<?php', (string) $open, 'source is never served as text');
	}

	public function testClientAddressIsTakenFromForwardingHeadersOnApache(): void {
		$this->requireExternalServer();
		$pdo = $this->startServer(array('plugins' => array()));
		$this->createUser($pdo, 1, 'proxied', 'proxy-pw');
		$direct = $this->login('proxied', 'proxy-pw');
		$response = $this->http('POST', 'session/authenticate', array('Content-Type: application/json', 'X-Forwarded-For: 203.0.113.9'), json_encode(array('username' => 'proxied', 'password' => base64_encode('proxy-pw'))));
		$this->assertSame(200, $response['status'], $response['body']);
		$addresses = $pdo->query('SELECT ip FROM session ORDER BY time, id')->fetchAll(PDO::FETCH_COLUMN);
		$this->assertCount(2, $addresses);
		$this->assertDoesNotMatchRegularExpression('/203\.0\.113\.9/', $addresses[0]);
		$this->assertMatchesRegularExpression('/^203\.0\.113\.9 via \d+\.\d+\.\d+\.\d+$/', $addresses[1]);
	}

	public function testUserAndGroupDetailsRespondForGroupsThatHaveNoLoginRecord(): void {
		list($session, $folderId, $root, $pdo) = $this->startWithFolder();
		$pdo->exec("INSERT INTO user (id, name, email, is_group, user_type) VALUES (50, 'staff', NULL, 1, NULL)");
		$group = $this->json('GET', 'configuration/users/50', NULL, $session);
		$this->assertSame(200, $group['status'], $group['body']);
		$this->assertSame('staff', $group['json']['result']['name']);
		$this->assertNull($group['json']['result']['auth']);
		$user = $this->json('GET', 'configuration/users/1', NULL, $session);
		$this->assertSame(200, $user['status'], $user['body']);
		$this->assertSame('pw', $user['json']['result']['auth']);
	}

	/**
	 * Names are UTF-8 for clients. On disk they are exactly the same bytes unless convert_filenames is enabled
	 * (the default is off), in which case the disk uses ISO-8859-1.
	 */
	private function nonAsciiNameRoundTrip(bool $convertFilenames): void {
		$settings = array('plugins' => array('Archiver' => array()));
		if ($convertFilenames) {
			$settings['convert_filenames'] = TRUE;
		}
		list($session, $folderId, $root) = $this->startWithFolder($settings);
		$utf8 = array('file' => "\xc3\x9cbersicht \xc3\xa4\xc3\xb6\xc3\x9f.txt", 'dir' => "Ge\xc3\xa4ndert", 'inner' => "na\xc3\xafve.txt", 'uploaded' => "Gr\xc3\xbc\xc3\x9fe.txt", 'created' => "\xc3\x84rger");
		$onDisk = array_map(fn($name) => $convertFilenames ? mb_convert_encoding($name, 'ISO-8859-1', 'UTF-8') : $name, $utf8);

		mkdir($root . '/' . $onDisk['dir']);
		file_put_contents($root . '/' . $onDisk['file'], 'file content');
		file_put_contents($root . '/' . $onDisk['dir'] . '/' . $onDisk['inner'], 'inside');

		$raw = $this->json('GET', 'filesystem/' . $folderId . '/files', NULL, $session);
		$this->assertSame(200, $raw['status'], $raw['body']);
		$files = $raw['json']['result'];
		$this->assertSame(array($utf8['file']), array_column($files, 'name'), 'clients always get UTF-8 names');
		$this->assertSame('file content', $this->http('GET', 'filesystem/' . $files[0]['id'] . '?session=' . $session)['body']);
		$folders = $this->json('GET', 'filesystem/' . $folderId . '/folders', NULL, $session)['json']['result'];
		$this->assertSame(array($utf8['dir']), array_column($folders, 'name'));
		$inner = $this->json('GET', 'filesystem/' . $folders[0]['id'] . '/files', NULL, $session)['json']['result'];
		$this->assertSame(array($utf8['inner']), array_column($inner, 'name'));

		$upload = $this->http('POST', 'filesystem/' . $folderId . '/files?format=binary&session=' . $session, array('Content-Disposition: attachment; filename="' . rawurlencode($utf8['uploaded']) . '"'), 'uploaded');
		$this->assertSame(200, $upload['status'], $upload['body']);
		$this->assertSame('uploaded', file_get_contents($root . '/' . $onDisk['uploaded']));

		$created = $this->json('POST', 'filesystem/' . $folderId . '/folders', array('name' => $utf8['created']), $session);
		$this->assertSame(200, $created['status'], $created['body']);
		$this->assertDirectoryExists($root . '/' . $onDisk['created']);

		$zip = $this->http('GET', 'archiver/download?item=' . $folders[0]['id'] . '&session=' . $session);
		$entries = $this->zipEntries($zip['body']);
		if (!$convertFilenames) {
			$this->assertSame(array($utf8['dir'] . '/' . $utf8['inner'] => md5('inside')), $entries, 'archive entries carry UTF-8 names');
		} else {
			// known limitation (see PHP82-MIGRATION.md): with convert_filenames the folder part of an archive entry
			// comes out double-encoded; the file name and content are right
			$this->assertCount(1, $entries);
			$this->assertStringEndsWith('/' . $utf8['inner'], (string) key($entries));
			$this->assertSame(md5('inside'), current($entries));
		}
	}

	public function testNonAsciiNamesAreUtf8OnDiskByDefault(): void {
		$this->nonAsciiNameRoundTrip(FALSE);
	}

	public function testNonAsciiNamesAreIso88591OnDiskWhenFilenameConversionIsEnabled(): void {
		$this->nonAsciiNameRoundTrip(TRUE);
	}
}

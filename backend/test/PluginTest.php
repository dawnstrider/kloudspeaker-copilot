<?php

require_once "TestCase.php";
require_once "CaptureMailSender.class.php";

class KloudspeakerPluginTest extends Kloudspeaker_TestCase {
	public function testLostPasswordGeneratesPasswordsWithoutDiagnostics(): void {
		require_once 'plugin/PluginBase.class.php';
		require_once 'plugin/LostPassword/LostPasswordServices.class.php';
		$services = (new ReflectionClass(LostPasswordServices::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(LostPasswordServices::class, 'createNewPassword');
		for ($i = 0; $i < 50; $i++) {
			$this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{10}$/', $method->invoke($services));
		}
	}

	public function testRegistrationEventsKeepTheirEmailWithoutDynamicProperties(): void {
		require_once 'plugin/PluginBase.class.php';
		require_once 'plugin/Registration/Registration.plugin.class.php';
		$event = RegistrationEvent::registered('fixture-user', 'user@example.test', 7, 'fixture-key');
		$this->assertSame(array('id' => NULL, 'name' => 'fixture-user', 'email' => 'user@example.test'), $event->user());
		$this->assertSame('email=user@example.test;registration_id=7;registration_key=fixture-key;', $event->details());
		$this->assertSame('email=user@example.test;', RegistrationEvent::userCreated(3, 'fixture-user', 'user@example.test')->details());
	}

	public function testClassesAssignedInternalStateAreDeclared(): void {
		require_once 'plugin/PluginBase.class.php';
		require_once 'plugin/TrashBin/TrashBin.plugin.class.php';
		require_once 'install/KloudspeakerInstallProcessor.class.php';
		foreach (array(
			array(TrashBin::class, 'trashBinManager'),
			array(KloudspeakerInstallProcessor::class, 'passwordHash'),
		) as $case) {
			$this->assertTrue(property_exists($case[0], $case[1]), $case[0] . '::$' . $case[1]);
		}
	}

	public function testAllBundledPluginsRegisterAndReportSessionInfo(): void {
		$plugins = array();
		foreach (array('Archiver', 'Comment', 'EventLogging', 'FileViewerEditor', 'ItemCollection', 'ItemDetails', 'LostPassword', 'Notificator', 'Registration', 'SendViaEmail', 'Share', 'Watermarking') as $id) {
			$plugins[$id] = array();
		}
		$info = $this->processRequest('GET', 'session/info', array(), NULL, array('plugins' => $plugins, 'host_public_address' => 'http://kloudspeaker.test'));
		$this->assertFalse($info['authenticated']);
	}

	private function lostPasswordSettings(array $pluginSettings = array()): array {
		return array(
			'plugins' => array('LostPassword' => $pluginSettings),
			'host_public_address' => 'http://kloudspeaker.test'
		);
	}

	private function createUser(int $id, string $name, string $email, string $password, string $hint = ''): void {
		$hasher = new Kloudspeaker_PasswordHash(new Settings(array()));
		$hash = $hasher->createHash($password);
		$this->getConnection()->exec("INSERT INTO user (id, name, email, is_group, user_type) VALUES ($id, '$name', '$email', 0, 'a')");
		$this->getConnection()->exec("INSERT INTO user_auth (user_id, type, salt, hash, hint) VALUES ($id, 'pw', '" . $hash['salt'] . "', '" . $hash['hash'] . "', '$hint')");
	}

	public function testLostPasswordRejectsUnknownAndMalformedRequests(): void {
		$this->mailer = new Kloudspeaker_CaptureMailer();
		$this->processRequest('POST', 'lostpassword', array(), array('email' => 'nobody@example.test'), $this->lostPasswordSettings());
		$this->assertSame(array(301, 'NO_SUCH_USER'), $this->responseHandler->lastFail);
		$this->assertSame(array(), $this->mailer->sent);
		foreach (array(array(), array('email' => 'x@example.test', 'hint' => TRUE)) as $data) {
			try {
				$this->processRequest('POST', 'lostpassword', array(), $data, $this->lostPasswordSettings());
				$this->fail('Malformed or disallowed requests must be rejected');
			} catch (ServiceException $e) {
				$this->assertSame('INVALID_REQUEST', $e->type());
			}
		}
		$this->assertSame(array(), $this->mailer->sent);
	}

	public function testLostPasswordResetChangesThePasswordAndMailsIt(): void {
		$this->mailer = new Kloudspeaker_CaptureMailer();
		$this->createUser(5, 'resetuser', 'reset@example.test', 'old-password');
		$before = $this->getConnection()->query('SELECT hash FROM user_auth WHERE user_id = 5')->fetchColumn();
		$this->processRequest('POST', 'lostpassword', array(), array('email' => 'reset@example.test'), $this->lostPasswordSettings());
		$this->assertSame(array(), $this->responseHandler->lastSuccess);
		$this->assertCount(1, $this->mailer->sent);
		$mail = $this->mailer->sent[0];
		$this->assertSame('reset@example.test', $mail['to'][0]['email']);
		$this->assertSame(1, preg_match('/(?<![a-zA-Z0-9])[a-zA-Z0-9]{10}(?![a-zA-Z0-9])/', $mail['message'], $m));
		$row = $this->getConnection()->query('SELECT hash, salt FROM user_auth WHERE user_id = 5')->fetch(PDO::FETCH_ASSOC);
		$this->assertNotSame($before, $row['hash']);
		$hasher = new Kloudspeaker_PasswordHash(new Settings(array()));
		$this->assertFalse($hasher->isEqual('old-password', $row['hash'], $row['salt']));
		$this->assertTrue($hasher->isEqual($m[0], $row['hash'], $row['salt']));
	}

	public function testLostPasswordHintIsSentOnlyWhenEnabled(): void {
		$this->mailer = new Kloudspeaker_CaptureMailer();
		$this->createUser(6, 'hintuser', 'hint@example.test', 'secret', 'fixture-hint');
		$this->processRequest('POST', 'lostpassword', array(), array('email' => 'hint@example.test', 'hint' => TRUE), $this->lostPasswordSettings(array('enable_hint' => TRUE)));
		$this->assertCount(1, $this->mailer->sent);
		$this->assertStringContainsString('fixture-hint', $this->mailer->sent[0]['message']);
	}

	private function registrationSettings(array $pluginSettings = array()): array {
		$this->getConnection()->exec(file_get_contents(__DIR__ . '/../plugin/Registration/sqlite/install.sql'));
		return array(
			'plugins' => array('Registration' => $pluginSettings),
			'host_public_address' => 'http://kloudspeaker.test'
		);
	}

	private function registrationData(): array {
		return array('name' => 'newuser', 'email' => 'new@example.test', 'password' => base64_encode('new-password'), 'hint' => 'fixture-hint');
	}

	public function testRegistrationWithoutApprovalCreatesAUserWhoCanAuthenticate(): void {
		$this->mailer = new Kloudspeaker_CaptureMailer();
		$settings = $this->registrationSettings(array('require_approval' => FALSE));
		$this->processRequest('POST', 'registration/create', array(), $this->registrationData(), $settings);
		$this->assertCount(1, $this->mailer->sent);
		$this->assertSame('new@example.test', $this->mailer->sent[0]['to'][0]['email']);
		$key = $this->getConnection()->query('SELECT "key" FROM registration')->fetchColumn();
		$this->assertStringContainsString($key, $this->mailer->sent[0]['message']);

		$result = $this->processRequest('POST', 'registration/confirm', array(), array('email' => 'new@example.test', 'key' => $key), $settings);
		$this->assertSame(array('require_approval' => FALSE), $result);
		$this->assertSame(0, (int) $this->getConnection()->query('SELECT COUNT(*) FROM registration')->fetchColumn());
		$user = $this->getConnection()->query("SELECT id, email FROM user WHERE name = 'newuser'")->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('new@example.test', $user['email']);
		$auth = $this->getConnection()->query('SELECT hash, salt, hint FROM user_auth WHERE user_id = ' . (int) $user['id'])->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('fixture-hint', trim($auth['hint']));
		$this->assertTrue((new Kloudspeaker_PasswordHash(new Settings(array())))->isEqual('new-password', $auth['hash'], $auth['salt']));

		$session = $this->processRequest('POST', 'session/authenticate', array(), array('username' => 'newuser', 'password' => base64_encode('new-password')), $settings);
		$this->assertTrue($session['authenticated']);
	}

	public function testRegistrationRejectsMalformedAndWrongKeyRequests(): void {
		$this->mailer = new Kloudspeaker_CaptureMailer();
		$settings = $this->registrationSettings();
		$this->processRequest('POST', 'registration/create', array(), $this->registrationData(), $settings);
		$expect = function (string $type, callable $call) {
			try {
				$call();
				$this->fail('Expected ' . $type);
			} catch (ServiceException $e) {
				$this->assertSame($type, $e->type(), $e->details());
			}
		};
		$expect('INVALID_REQUEST', fn() => $this->processRequest('POST', 'registration/create', array(), array('name' => 'x'), $settings));
		$expect('REQUEST_FAILED', fn() => $this->processRequest('POST', 'registration/confirm', array(), array('email' => 'new@example.test', 'key' => 'wrong'), $settings));
		$this->assertSame(1, (int) $this->getConnection()->query('SELECT COUNT(*) FROM registration')->fetchColumn());
		$this->assertSame(0, (int) $this->getConnection()->query('SELECT COUNT(*) FROM user')->fetchColumn());
	}

	private function login(string $name, string $password, array $settings): void {
		$this->sessionId = NULL;
		$session = $this->processRequest('POST', 'session/authenticate', array(), array('username' => $name, 'password' => base64_encode($password)), $settings);
		$this->assertTrue($session['authenticated']);
		$this->sessionId = $session['session_id'];
	}

	public function testRegistrationManagementIsDeniedToOrdinaryUsersAndAllowedToAdmins(): void {
		$this->mailer = new Kloudspeaker_CaptureMailer();
		$settings = $this->registrationSettings();
		$this->processRequest('POST', 'registration/create', array(), $this->registrationData(), $settings);
		$this->createUser(20, 'ordinary', 'ordinary@example.test', 'ordinary-pw');
		$this->getConnection()->exec("UPDATE user SET user_type = NULL WHERE id = 20");
		$this->createUser(21, 'admin', 'admin@example.test', 'admin-pw');
		$this->login('ordinary', 'ordinary-pw', $settings);
		foreach (array(array('GET', 'registration/list'), array('GET', 'registration/approve/1'), array('POST', 'registration/approve/1')) as $call) {
			try {
				$this->processRequest($call[0], $call[1], array(), array(), $settings);
				$this->fail('Ordinary users must not manage registrations: ' . $call[1]);
			} catch (ServiceException $e) {
				$this->assertSame('INSUFFICIENT_PERMISSIONS', $e->type(), $call[1]);
			}
		}
		$this->assertSame(1, (int) $this->getConnection()->query('SELECT COUNT(*) FROM registration')->fetchColumn());

		$this->login('admin', 'admin-pw', $settings);
		$list = $this->processRequest('GET', 'registration/list', array(), NULL, $settings);
		$this->assertCount(1, $list);
		$this->assertSame('newuser', $list[0]['name']);
		$approved = $this->processRequest('POST', 'registration/approve/' . $list[0]['id'], array(), array(), $settings);
		$this->assertSame('newuser', $approved['name']);
		$this->assertSame(1, (int) $this->getConnection()->query("SELECT COUNT(*) FROM user WHERE name = 'newuser'")->fetchColumn());
		$this->assertSame(0, (int) $this->getConnection()->query('SELECT COUNT(*) FROM registration')->fetchColumn());
	}

	private function shareSettings(): array {
		$this->getConnection()->exec(file_get_contents(__DIR__ . '/../plugin/Share/sqlite/install.sql'));
		return array(
			'plugins' => array('Share' => array()),
			'host_public_address' => 'http://kloudspeaker.test'
		);
	}

	public function testShareCreationWithOptionalFieldsOmitted(): void {
		$settings = $this->shareSettings();
		$root = sys_get_temp_dir() . '/kloudspeaker-share-' . bin2hex(random_bytes(4));
		mkdir($root);
		file_put_contents($root . '/shared.txt', 'payload');
		try {
			$this->getConnection()->exec("INSERT INTO folder (id, name, path, type) VALUES (1, 'Root', '" . $root . "', 'local')");
			$this->createUser(30, 'sharer', 'sharer@example.test', 'sharer-pw');
			$this->getConnection()->exec("INSERT INTO user_folder (user_id, folder_id, name) VALUES (30, 1, NULL)");
			$this->login('sharer', 'sharer-pw', $settings);
			$session = $this->processRequest('GET', 'session/info', array(), NULL, $settings);
			$this->assertNotEmpty($session['folders']);
			$itemId = $session['folders'][0]['id'];
			$shares = $this->processRequest('POST', 'share', array(), array('item' => $itemId, 'name' => 'fixture share', 'type' => 'upload'), $settings);
			$this->assertCount(1, $shares);
			$shareId = $shares[0]['id'];
			$this->assertSame('fixture share', $shares[0]['name']);

			$this->processRequest('PUT', 'share/' . $shareId, array(), array('name' => 'renamed share', 'type' => 'upload'), $settings);
			$listed = $this->processRequest('GET', 'share/items/' . $itemId, array(), NULL, $settings);
			$this->assertSame('renamed share', $listed['shares'][0]['name']);

			$this->sessionId = NULL;
			$info = $this->processRequest('GET', 'public/' . $shareId . '/info', array(), NULL, $settings);
			$this->assertIsArray($info);

			$this->login('sharer', 'sharer-pw', $settings);
			$this->processRequest('DELETE', 'share/' . $shareId, array(), NULL, $settings);
			$this->assertSame(0, (int) $this->getConnection()->query('SELECT COUNT(*) FROM share')->fetchColumn());
		} finally {
			unlink($root . '/shared.txt');
			rmdir($root);
		}
	}

	private function notificatorSettings(): array {
		$this->getConnection()->exec(file_get_contents(__DIR__ . '/../plugin/Notificator/sqlite/install.sql'));
		return array(
			'plugins' => array('Notificator' => array()),
			'host_public_address' => 'http://kloudspeaker.test'
		);
	}

	public function testNotificatorAdminServicesManageNotifications(): void {
		$settings = $this->notificatorSettings();
		$this->createUser(40, 'notifadmin', 'notifadmin@example.test', 'notif-pw');
		$this->createUser(41, 'watcher', 'watcher@example.test', 'watcher-pw');
		$this->createUser(42, 'ordinary', 'ordinary@example.test', 'ordinary-pw');
		$this->getConnection()->exec('UPDATE user SET user_type = NULL WHERE id = 42');

		foreach (array(NULL, 'login') as $mode) {
			if ($mode === 'login') {
				$this->login('ordinary', 'ordinary-pw', $settings);
			}
			try {
				$this->processRequest('GET', 'notificator/list', array(), NULL, $settings);
				$this->fail('Only administrators may manage notifications');
			} catch (ServiceException $e) {
				$this->assertSame('UNAUTHORIZED', $e->type());
			}
		}

		$this->login('notifadmin', 'notif-pw', $settings);
		try {
			$this->processRequest('POST', 'notificator/list', array(), array(), $settings);
			$this->fail('A notification needs a name');
		} catch (ServiceException $e) {
			$this->assertSame('INVALID_REQUEST', $e->type());
		}

		$this->processRequest('POST', 'notificator/list', array(), array('name' => 'fixture notification'), $settings);
		$list = $this->processRequest('GET', 'notificator/list', array(), NULL, $settings);
		$this->assertCount(1, $list);
		$id = $list[0]['id'];

		$this->processRequest('PUT', 'notificator/list/' . $id, array(), array('name' => 'renamed'), $settings);
		$this->processRequest('PUT', 'notificator/list/' . $id, array(), array('message_title' => 'title', 'message' => 'body'), $settings);
		$this->processRequest('PUT', 'notificator/list/' . $id, array(), array('events' => array('filesystem/upload')), $settings);
		$this->processRequest('PUT', 'notificator/list/' . $id, array(), array('users' => array(40)), $settings);
		$this->processRequest('PUT', 'notificator/list/' . $id, array(), array('recipients' => array(41)), $settings);

		$notification = $this->processRequest('GET', 'notificator/list/' . $id, array(), NULL, $settings);
		$this->assertSame('renamed', $notification['name']);
		$this->assertSame('body', $notification['message']);
		$this->assertSame('filesystem/upload', $notification['events'][0]['type']);
		$this->assertSame('notifadmin', $notification['users'][0]['name']);
		$this->assertEquals(array(41), $notification['recipients']);

		$eventId = $notification['events'][0]['id'];
		$this->processRequest('PUT', 'notificator/list/' . $id . '/events/' . $eventId . '/filters', array(), array('new' => array(array('type' => 'name', 'value' => '*.txt'))), $settings);
		$notification = $this->processRequest('GET', 'notificator/list/' . $id, array(), NULL, $settings);
		$this->assertSame('*.txt', $notification['events'][0]['filters'][0]['value']);

		$this->processRequest('DELETE', 'notificator/list/' . $id . '/recipients', array(), array('ids' => array(41)), $settings);
		$this->processRequest('DELETE', 'notificator/list/' . $id, array(), NULL, $settings);
		$this->assertSame(0, (int) $this->getConnection()->query('SELECT COUNT(*) FROM notificator_notification')->fetchColumn());
	}
}

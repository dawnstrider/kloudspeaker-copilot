<?php

require_once "TestCase.php";
require_once 'install/KloudspeakerInstallProcessor.class.php';
require_once 'install/pdo/PDOInstallUtil.class.php';
require_once 'db/pdo/DatabaseUtil.class.php';
require_once 'install/pdo/PDOInstaller.class.php';
require_once 'update/UpdateController.class.php';
require_once 'update/pdo/PDOUpdater.class.php';

class KloudspeakerInstallerTest extends \PHPUnit\Framework\TestCase {
	private $file;

	protected function setUp(): void {
		$this->file = sys_get_temp_dir() . '/kloudspeaker-install-' . bin2hex(random_bytes(4)) . '.sqlite';
	}

	protected function tearDown(): void {
		if (is_file($this->file)) {
			unlink($this->file);
		}
	}

	private function plugins(): array {
		$plugins = array();
		foreach (array('Archiver', 'Comment', 'EventLogging', 'FileViewerEditor', 'ItemCollection', 'ItemDetails', 'LostPassword', 'Notificator', 'Registration', 'SendViaEmail', 'Share', 'TrashBin', 'Watermarking') as $id) {
			$plugins[$id] = array();
		}
		return $plugins;
	}

	public function testFreshPdoSqliteInstallCreatesSchemaPluginsAndAdminWhoCanLogIn(): void {
		$settings = array('debug' => FALSE, 'timezone' => 'Europe/Helsinki', 'plugins' => $this->plugins(), 'host_public_address' => 'http://kloudspeaker.test');
		$db = PDODatabase::createFromConf(array('str' => 'sqlite:' . $this->file, 'user' => '', 'password' => ''));
		$util = new PDOInstallUtil($db);
		$dbUtil = new DatabaseUtil($db);

		try {
			$dbUtil->installedVersion();
			$this->fail('An empty database has no parameter table');
		} catch (ServiceException $e) {
			$this->assertSame('INVALID_CONFIGURATION', $e->type());
		}

		$util->checkPermissions();
		$db->startTransaction();
		$util->execCreateTables();
		$util->execInsertParams();
		$processor = new KloudspeakerInstallProcessor('install', 'pdo', $settings);
		$processor->createEnvironment($db);
		$processor->installPlugins($util);
		$processor->createAdminUser('installadmin', 'install-pw');
		$db->commit();

		$this->assertSame('2_7_28', $dbUtil->installedVersion());
		$this->assertSame($dbUtil->installedVersion(), $dbUtil->currentVersion());
		foreach (array('Comment' => '1_0', 'Notificator' => '1_1', 'Registration' => '1_2', 'Share' => '1_5') as $id => $version) {
			$this->assertSame($version, $dbUtil->pluginInstalledVersion($id), $id);
		}

		$handler = new TestResponseHandler();
		$backend = new KloudspeakerBackend(new Settings($settings), $db, $handler);
		$backend->processRequest(new Request(NULL, 'post', 'session/authenticate', 'test-ip', array('session', 'authenticate'), array(), array('username' => 'installadmin', 'password' => base64_encode('install-pw'))));
		$this->assertTrue($handler->lastSuccess['authenticated']);
		$this->assertSame('a', strtolower($handler->lastSuccess['user_type']));
	}

	public function testUpdaterInstallsAPluginThatIsMissingFromTheDatabase(): void {
		$settings = array('debug' => FALSE, 'timezone' => 'Europe/Helsinki', 'plugins' => array('Comment' => array(), 'Notificator' => array()), 'host_public_address' => 'http://kloudspeaker.test', 'db' => array('str' => 'sqlite:' . $this->file, 'user' => '', 'password' => ''));
		$installer = new PDOInstaller($settings, 'update');
		$installer->init();
		$util = $installer->util();
		$util->execCreateTables();
		$util->execInsertParams();
		$installer->processor()->createEnvironment($installer->db());
		$installer->processor()->installPlugins($util);
		$installer->db()->update("DELETE FROM parameter WHERE name = 'plugin_Comment_version'");
		$installer->db()->update('DROP TABLE comment');
		$this->assertNull($installer->pluginInstalledVersion('Comment'));

		$controller = new UpdateController($installer);
		$update = new ReflectionMethod(UpdateController::class, 'updatePlugin');
		$plugins = $installer->processor()->plugins()->getPlugins();
		$this->assertSame('Plugin Comment installed', $update->invoke($controller, 'Comment', $plugins['Comment']));
		$this->assertSame('1_0', $installer->pluginInstalledVersion('Comment'));
		$this->assertNull($update->invoke($controller, 'Notificator', $plugins['Notificator']));
		$this->assertStringNotContainsString('require installation', $controller->updateSummary());
		$this->assertStringNotContainsString('require update:', $controller->updateSummary());
	}

	public function testPreflightAcceptsTheCurrentRuntimeAndReportsEachMissingRequirement(): void {
		$this->assertSame(array(), Kloudspeaker_RuntimePreflight::check('pdo'));
		$this->assertSame(array(), Kloudspeaker_RuntimePreflight::check('sqlite3'));
		$this->assertSame(array(), Kloudspeaker_RuntimePreflight::check(NULL));

		$titles = function (array $problems) {
			return array_column($problems, 'title');
		};
		$none = fn($extension) => FALSE;
		$this->assertSame(
			array('Unsupported PHP version', 'Multibyte String (mbstring) extension not detected', 'JSON extension not detected', 'MySQL Improved (mysqli) extension not detected'),
			$titles(Kloudspeaker_RuntimePreflight::check('mysql', 70400, $none))
		);
		$onlyMbstring = fn($extension) => $extension === 'mbstring';
		$this->assertSame(array('JSON extension not detected'), $titles(Kloudspeaker_RuntimePreflight::check('unknown-type', 80200, $onlyMbstring)));
		$this->assertSame(array('JSON extension not detected', 'PostgreSQL extension not detected'), $titles(Kloudspeaker_RuntimePreflight::check('PostgreSQL', 80200, $onlyMbstring)));
	}

	public function testPreflightShowsTheInstallerErrorPageForTheFirstProblem(): void {
		$processor = new class {
			public $error;
			public $page;
			public function setError($title, $details = NULL) { $this->error = array($title, $details); }
			public function showPage($page) { $this->page = $page; }
		};
		Kloudspeaker_RuntimePreflight::assertUsable($processor, 'pdo');
		$this->assertNull($processor->page);
		Kloudspeaker_RuntimePreflight::assertUsable($processor, 'no-such-driver');
		$this->assertNull($processor->page);

		$problems = Kloudspeaker_RuntimePreflight::check('mysql', 70400, fn($extension) => FALSE);
		Kloudspeaker_RuntimePreflight::assertUsable($processor, 'mysql', $problems);
		$this->assertSame('Unsupported PHP version', $processor->error[0]);
		$this->assertSame('install_error', $processor->page);
	}

	/** Builds a database in the 2_6_7 state: the shipped schema, but with the old item_id table and version. */
	private function createLegacySqlite(): PDO {
		$pdo = new PDO('sqlite:' . $this->file);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec(file_get_contents(__DIR__ . '/../db/sqlite/sql/install/create_tables.sql'));
		$pdo->exec(file_get_contents(__DIR__ . '/../db/sqlite/sql/install/params.sql'));
		$pdo->exec('DROP TABLE item_id');
		$pdo->exec('CREATE TABLE item_id (id char(13) NOT NULL, path char(255) NOT NULL, PRIMARY KEY (id))');
		$pdo->exec("UPDATE parameter SET value = '2_6_7' WHERE name = 'version'");
		return $pdo;
	}

	public function testSqliteUpgradeFrom267To2728KeepsEveryItemId(): void {
		$legacy = $this->createLegacySqlite();
		$rows = array(array('4f1c2a9b8d7e6', '1:'), array('9a8b7c6d5e4f3', '1:docs/'), array('0123456789abc', '1:docs/readme.txt'), array('c0ffee0000000', '2:zip/a/b/'));
		foreach ($rows as $row) {
			$legacy->prepare('INSERT INTO item_id (id, path) VALUES (?, ?)')->execute($row);
		}
		$legacy = NULL;

		$settings = array('debug' => FALSE, 'timezone' => 'Europe/Helsinki', 'plugins' => array(), 'host_public_address' => 'http://kloudspeaker.test', 'db' => array('str' => 'sqlite:' . $this->file, 'user' => '', 'password' => ''));
		$updater = new PDOUpdater($settings);
		$updater->init();
		$this->assertSame('2_6_7', $updater->installedVersion());
		$this->assertSame('2_7_28', $updater->currentVersion());
		$this->assertFalse($updater->isCurrentVersionInstalled());
		$updater->processor()->createEnvironment($updater->db());

		$controller = new UpdateController($updater);
		$method = new ReflectionMethod(UpdateController::class, 'updateSystem');
		$this->assertSame('Kloudspeaker updated to 2.7.28', $method->invoke($controller));

		$this->assertSame('2_7_28', $updater->installedVersion());
		$this->assertTrue($updater->isCurrentVersionInstalled());
		$upgraded = new PDO('sqlite:' . $this->file);
		$stored = $upgraded->query('SELECT id, path, level FROM item_id ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
		$expected = array(
			array('id' => '0123456789abc', 'path' => '1:docs/readme.txt', 'level' => 2),
			array('id' => '4f1c2a9b8d7e6', 'path' => '1:', 'level' => 1),
			array('id' => '9a8b7c6d5e4f3', 'path' => '1:docs/', 'level' => 1),
			array('id' => 'c0ffee0000000', 'path' => '2:zip/a/b/', 'level' => 3),
		);
		// same rule as ItemIdProvider::getLevel(): path delimiters before the last character, plus one
		$this->assertEquals($expected, $stored);
		$this->assertNotFalse($upgraded->query("SELECT 1 FROM sqlite_master WHERE name = 'i_item_path_level'")->fetchColumn());
	}

	public function testSqliteUpgradeRollsBackAndCanBeRetriedWhenAStepFails(): void {
		$legacy = $this->createLegacySqlite();
		$legacy->exec("UPDATE parameter SET value = '2_7_14' WHERE name = 'version'");
		$legacy->exec('DROP TABLE item_id');	// the 2_7_14 to 2_7_28 step cannot find its source table
		$legacy = NULL;

		$settings = array('debug' => FALSE, 'timezone' => 'Europe/Helsinki', 'plugins' => array(), 'host_public_address' => 'http://kloudspeaker.test', 'db' => array('str' => 'sqlite:' . $this->file, 'user' => '', 'password' => ''));
		$updater = new PDOUpdater($settings);
		$updater->init();
		$updater->processor()->createEnvironment($updater->db());
		$method = new ReflectionMethod(UpdateController::class, 'updateSystem');
		$controller = new UpdateController($updater);

		$updater->db()->startTransaction();
		try {
			$method->invoke($controller);
			$this->fail('A failing update step must raise a service error');
		} catch (ServiceException $e) {
			$updater->db()->rollback();
		}
		$this->assertSame('2_7_14', $updater->installedVersion());

		$repair = new PDO('sqlite:' . $this->file);
		$repair->exec('CREATE TABLE item_id (id char(13) NOT NULL, path char(255) NOT NULL, level smallint NOT NULL, PRIMARY KEY (id))');
		$repair->exec("INSERT INTO item_id (id, path, level) VALUES ('4f1c2a9b8d7e6', '1:docs/', 1)");
		$repair = NULL;
		$updater->db()->startTransaction();
		$this->assertSame('Kloudspeaker updated to 2.7.28', $method->invoke($controller));
		$updater->db()->commit();
		$this->assertSame('2_7_28', $updater->installedVersion());
		$this->assertSame('1:docs/', $updater->db()->query("SELECT path FROM item_id WHERE id = '4f1c2a9b8d7e6'")->value(0));
	}

	/** Core schema at the current version, with the named plugins registered but not installed. */
	private function updaterWithCoreSchema(array $plugins): array {
		$pdo = new PDO('sqlite:' . $this->file);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec(file_get_contents(__DIR__ . '/../db/sqlite/sql/install/create_tables.sql'));
		$pdo->exec(file_get_contents(__DIR__ . '/../db/sqlite/sql/install/params.sql'));
		$settings = array('debug' => FALSE, 'timezone' => 'Europe/Helsinki', 'plugins' => array_fill_keys($plugins, array()), 'host_public_address' => 'http://kloudspeaker.test', 'db' => array('str' => 'sqlite:' . $this->file, 'user' => '', 'password' => ''));
		$updater = new PDOUpdater($settings);
		$updater->init();
		$updater->processor()->createEnvironment($updater->db());
		return array($pdo, $updater, new UpdateController($updater), new ReflectionMethod(UpdateController::class, 'updatePlugin'));
	}

	private function upgradePlugin(string $id, PDO $pdo, PDOUpdater $updater, UpdateController $controller, ReflectionMethod $update): string {
		$plugins = $updater->processor()->plugins()->getPlugins();
		$updater->db()->startTransaction();
		$result = $update->invoke($controller, $id, $plugins[$id]);
		$updater->db()->commit();
		$this->assertSame($plugins[$id]->version(), $updater->pluginInstalledVersion($id));
		return $result;
	}

	public function testNotificatorUpgradeFrom10KeepsNotificationsAndAddsFilters(): void {
		list($pdo, $updater, $controller, $update) = $this->updaterWithCoreSchema(array('Notificator'));
		$pdo->exec("INSERT INTO parameter (name, value) VALUES ('plugin_Notificator_version', '1_0')");
		$pdo->exec("CREATE TABLE notificator_notification (id INTEGER PRIMARY KEY, name varchar(255) NOT NULL, message_title varchar(255) NOT NULL DEFAULT '', message varchar(512) NOT NULL DEFAULT '')");
		$pdo->exec("CREATE TABLE notificator_notification_event (notification_id int(11) NOT NULL, event_type varchar(128) NOT NULL)");
		$pdo->exec("INSERT INTO notificator_notification (id, name, message_title, message) VALUES (1, 'uploads', 'Upload', 'A file arrived')");
		$pdo->exec("INSERT INTO notificator_notification_event (notification_id, event_type) VALUES (1, 'filesystem/upload'), (1, 'filesystem/delete')");

		$this->assertSame('Plugin Notificator updated to 1.1', $this->upgradePlugin('Notificator', $pdo, $updater, $controller, $update));
		$events = $pdo->query('SELECT id, notification_id, event_type FROM notificator_notification_event ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
		$this->assertSame(array('filesystem/upload', 'filesystem/delete'), array_column($events, 'event_type'));
		$this->assertNotNull($events[0]['id']);
		$pdo->exec("INSERT INTO notificator_notification_event_filter (notification_event_id, type, value) VALUES (" . $events[0]['id'] . ", 'item_name', '*.txt')");
		$this->assertSame('uploads', $pdo->query('SELECT name FROM notificator_notification')->fetchColumn());
	}

	public function testRegistrationUpgradeFrom10KeepsPendingRegistrations(): void {
		list($pdo, $updater, $controller, $update) = $this->updaterWithCoreSchema(array('Registration'));
		$pdo->exec("INSERT INTO parameter (name, value) VALUES ('plugin_Registration_version', '1_0')");
		$pdo->exec("CREATE TABLE pending_registrations (id INTEGER PRIMARY KEY, name varchar(255) NOT NULL, password varchar(128) NOT NULL, email varchar(128) NOT NULL, key char(64) NOT NULL, time bigint(11) NOT NULL)");
		$pdo->exec("INSERT INTO pending_registrations (id, name, password, email, key, time) VALUES (7, 'pending', 'c2VjcmV0', 'pending@example.test', 'abc123', 20240101120000)");

		$this->assertSame('Plugin Registration updated to 1.2', $this->upgradePlugin('Registration', $pdo, $updater, $controller, $update));
		$row = $pdo->query('SELECT id, name, password, email, key, time, confirmed, password_hint FROM registration')->fetch(PDO::FETCH_ASSOC);
		$this->assertEquals(array('id' => 7, 'name' => 'pending', 'password' => 'c2VjcmV0', 'email' => 'pending@example.test', 'key' => 'abc123', 'time' => 20240101120000, 'confirmed' => NULL, 'password_hint' => NULL), $row);
		$this->assertFalse($pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'pending_registrations'")->fetchColumn());
	}

	public function testShareUpgradeFrom11To15KeepsShares(): void {
		list($pdo, $updater, $controller, $update) = $this->updaterWithCoreSchema(array('Share'));
		$pdo->exec("INSERT INTO parameter (name, value) VALUES ('plugin_Share_version', '1_1')");
		$pdo->exec("CREATE TABLE share (id char(32) PRIMARY KEY, item_id char(255) NOT NULL, name varchar(255) NOT NULL, user_id INTEGER NOT NULL, expiration bigint(11) NULL, created bigint(11) NOT NULL, active TINYINT(1) NOT NULL)");
		$pdo->exec("INSERT INTO share (id, item_id, name, user_id, expiration, created, active) VALUES ('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'item1', 'first', 5, NULL, 20240101000000, 1), ('BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', 'item2', 'second', 6, 20991231235959, 20240102000000, 0)");

		$this->assertSame('Plugin Share updated to 1.5', $this->upgradePlugin('Share', $pdo, $updater, $controller, $update));
		$rows = $pdo->query('SELECT id, item_id, type, restriction, name, user_id, expiration, created, quick, active FROM share ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
		$this->assertCount(2, $rows);
		$this->assertEquals(array('id' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'item_id' => 'item1', 'type' => NULL, 'restriction' => NULL, 'name' => 'first', 'user_id' => 5, 'expiration' => NULL, 'created' => 20240101000000, 'quick' => 1, 'active' => 1), $rows[0]);
		$this->assertEquals(array('id' => 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', 'item_id' => 'item2', 'type' => NULL, 'restriction' => NULL, 'name' => 'second', 'user_id' => 6, 'expiration' => 20991231235959, 'created' => 20240102000000, 'quick' => 1, 'active' => 0), $rows[1]);
		$this->assertNotFalse($pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'share_auth'")->fetchColumn());
	}
}

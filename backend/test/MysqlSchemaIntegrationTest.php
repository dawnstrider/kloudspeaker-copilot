<?php

use PHPUnit\Framework\TestCase;

require_once "TestCase.php";
require_once 'install/KloudspeakerInstallProcessor.class.php';
require_once 'install/mysql/MySQLInstaller.class.php';
require_once 'update/mysql/MySQLUpdater.class.php';
require_once 'update/UpdateController.class.php';

/**
 * Full application schema checks against the isolated MariaDB service (database profile): fresh installation
 * through the installer building blocks, login through the real backend, and a 2_6_7 to 2_7_28 upgrade.
 * Every test uses its own table prefix and drops its tables afterwards.
 */
class MysqlSchemaIntegrationTest extends TestCase {
	private $prefix;
	private $mysqli;

	protected function setUp(): void {
		$this->assertSame('1', getenv('KLOUDSPEAKER_DB_INTEGRATION'), 'Use the isolated database-tests Compose service');
		$this->prefix = 't' . bin2hex(random_bytes(3)) . '_';
		mysqli_report(MYSQLI_REPORT_OFF);
		$this->mysqli = new mysqli('mariadb', 'migration_test', 'fixture-password', 'kloudspeaker_test');
	}

	protected function tearDown(): void {
		$this->mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
		$tables = $this->mysqli->query("SHOW TABLES LIKE '" . $this->prefix . "%'");
		foreach ($tables->fetch_all() as $row) {
			$this->mysqli->query('DROP TABLE `' . $row[0] . '`');
		}
		$this->mysqli->close();
	}

	private function settings(array $plugins = array()): array {
		return array(
			'debug' => FALSE, 'timezone' => 'Europe/Helsinki', 'plugins' => $plugins, 'host_public_address' => 'http://kloudspeaker.test',
			'db' => array('type' => 'mysql', 'host' => 'mariadb', 'user' => 'migration_test', 'password' => 'fixture-password', 'database' => 'kloudspeaker_test', 'table_prefix' => $this->prefix, 'charset' => 'utf8mb4'),
		);
	}

	private function plugins(): array {
		$plugins = array();
		foreach (array('Archiver', 'Comment', 'EventLogging', 'FileViewerEditor', 'ItemCollection', 'ItemDetails', 'LostPassword', 'Notificator', 'Registration', 'SendViaEmail', 'Share', 'TrashBin', 'Watermarking') as $id) {
			$plugins[$id] = array();
		}
		return $plugins;
	}

	private function tableNames(): array {
		$names = array();
		foreach ($this->mysqli->query("SHOW TABLES LIKE '" . $this->prefix . "%'")->fetch_all() as $row) {
			$names[] = substr($row[0], strlen($this->prefix));
		}
		sort($names);
		return $names;
	}

	public function testFreshInstallCreatesTheFullSchemaAndAnAdminWhoCanLogIn(): void {
		$settings = $this->settings($this->plugins());
		$installer = new MySQLInstaller($settings, 'install');
		$installer->init();
		$installer->db()->selectDb();
		$util = $installer->util();
		$util->checkPermissions();
		$installer->db()->startTransaction();
		$util->execCreateTables();
		$util->execInsertParams();
		$installer->processor()->createEnvironment($installer->db());
		$installer->processor()->installPlugins($util);
		$installer->processor()->createAdminUser('mysqladmin', 'mysql-pw');
		$installer->db()->commit();

		$this->assertSame('2_7_28', $installer->installedVersion());
		$this->assertTrue($installer->isCurrentVersionInstalled());
		$tables = $this->tableNames();
		foreach (array('user', 'user_auth', 'folder', 'item_id', 'permission', 'session', 'parameter', 'comment', 'share', 'registration', 'event_log', 'notificator_notification', 'itemcollection') as $table) {
			$this->assertContains($table, $tables);
		}
		foreach (array('Comment' => '1_0', 'Notificator' => '1_1', 'Registration' => '1_2', 'Share' => '1_5') as $id => $version) {
			$this->assertSame($version, $installer->pluginInstalledVersion($id), $id);
		}

		$database = MySQLIDatabase::createFromConf($settings['db']);
		$handler = new TestResponseHandler();
		$backend = new KloudspeakerBackend(new Settings($settings), $database, $handler);
		$backend->processRequest(new Request(NULL, 'post', 'session/authenticate', 'test-ip', array('session', 'authenticate'), array(), array('username' => 'mysqladmin', 'password' => base64_encode('mysql-pw'))));
		$this->assertTrue($handler->lastSuccess['authenticated']);
		$this->assertSame('a', strtolower($handler->lastSuccess['user_type']));
	}

	public function testUpgradeFrom267To2728KeepsEveryItemId(): void {
		$settings = $this->settings();
		$updater = new MySQLUpdater($settings);
		$updater->init();
		$updater->db()->selectDb();
		$updater->util()->execCreateTables();
		$updater->util()->execInsertParams();

		// turn the shipped 2_7_28 schema into the 2_6_7 one: item_id without level and with the old id width
		$table = $this->prefix . 'item_id';
		foreach (array("ALTER TABLE `$table` DROP INDEX i_item_level", "ALTER TABLE `$table` DROP INDEX i_item_path_level", "ALTER TABLE `$table` DROP COLUMN `level`", "ALTER TABLE `$table` MODIFY COLUMN `id` char(13) NOT NULL", "UPDATE `{$this->prefix}parameter` SET `value` = '2_6_7' WHERE `name` = 'version'") as $sql) {
			$this->assertTrue($this->mysqli->query($sql) !== FALSE, $this->mysqli->error);
		}
		$rows = array(array('4f1c2a9b8d7e6', '1:'), array('9a8b7c6d5e4f3', '1:docs/'), array('0123456789abc', '1:docs/readme.txt'), array('c0ffee0000000', '2:zip/a/b/'));
		foreach ($rows as $row) {
			$this->assertTrue($this->mysqli->query("INSERT INTO `$table` (id, path) VALUES ('" . $row[0] . "', '" . $row[1] . "')") !== FALSE, $this->mysqli->error);
		}

		$this->assertSame('2_6_7', $updater->installedVersion());
		$updater->processor()->createEnvironment($updater->db());
		$controller = new UpdateController($updater);
		$method = new ReflectionMethod(UpdateController::class, 'updateSystem');
		$updater->db()->startTransaction();
		$this->assertSame('Kloudspeaker updated to 2.7.28', $method->invoke($controller));
		$updater->db()->commit();

		$this->assertSame('2_7_28', $updater->installedVersion());
		$stored = $this->mysqli->query("SELECT id, path, level FROM `$table` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
		$this->assertEquals(array(
			array('id' => '0123456789abc', 'path' => '1:docs/readme.txt', 'level' => 2),
			array('id' => '4f1c2a9b8d7e6', 'path' => '1:', 'level' => 1),
			array('id' => '9a8b7c6d5e4f3', 'path' => '1:docs/', 'level' => 1),
			array('id' => 'c0ffee0000000', 'path' => '2:zip/a/b/', 'level' => 3),
		), $stored);
		$width = $this->mysqli->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'kloudspeaker_test' AND TABLE_NAME = '$table' AND COLUMN_NAME = 'id'")->fetch_row()[0];
		$this->assertSame(32, (int) $width);
	}

	/** Core schema at the current version with the named plugins registered but not installed. */
	private function updaterWithCoreSchema(array $plugins): array {
		$updater = new MySQLUpdater($this->settings(array_fill_keys($plugins, array())));
		$updater->init();
		$updater->db()->selectDb();
		$updater->util()->execCreateTables();
		$updater->util()->execInsertParams();
		$updater->processor()->createEnvironment($updater->db());
		return array($updater, new UpdateController($updater), new ReflectionMethod(UpdateController::class, 'updatePlugin'));
	}

	private function sql(string $sql): void {
		$this->assertTrue($this->mysqli->query(str_replace('{P}', $this->prefix, $sql)) !== FALSE, $this->mysqli->error);
	}

	private function upgradePlugin(string $id, $updater, $controller, $update): string {
		$plugins = $updater->processor()->plugins()->getPlugins();
		$updater->db()->startTransaction();
		$result = $update->invoke($controller, $id, $plugins[$id]);
		$updater->db()->commit();
		$this->assertSame($plugins[$id]->version(), $updater->pluginInstalledVersion($id));
		return $result;
	}

	public function testNotificatorUpgradeFrom10KeepsNotificationsAndGivesEventsIds(): void {
		list($updater, $controller, $update) = $this->updaterWithCoreSchema(array('Notificator'));
		$this->sql("INSERT INTO `{P}parameter` (name, value) VALUES ('plugin_Notificator_version', '1_0')");
		$this->sql("CREATE TABLE `{P}notificator_notification` (id int(11) NOT NULL auto_increment, name varchar(255) NOT NULL, message_title varchar(255) NOT NULL DEFAULT '', message varchar(512) NOT NULL DEFAULT '', PRIMARY KEY (id))");
		$this->sql("CREATE TABLE `{P}notificator_notification_event` (notification_id int(11) NOT NULL, event_type varchar(128) NOT NULL, PRIMARY KEY (notification_id, event_type))");
		$this->sql("INSERT INTO `{P}notificator_notification` (id, name) VALUES (1, 'uploads')");
		$this->sql("INSERT INTO `{P}notificator_notification_event` (notification_id, event_type) VALUES (1, 'filesystem/upload'), (1, 'filesystem/delete')");

		$this->assertSame('Plugin Notificator updated to 1.1', $this->upgradePlugin('Notificator', $updater, $controller, $update));
		$events = $this->mysqli->query("SELECT id, notification_id, event_type FROM `{$this->prefix}notificator_notification_event` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
		$this->assertEquals(array(1, 2), array_column($events, 'id'));
		$this->assertEqualsCanonicalizing(array('filesystem/upload', 'filesystem/delete'), array_column($events, 'event_type'));
		$this->sql("INSERT INTO `{P}notificator_notification_event_filter` (notification_event_id, type, value) VALUES (1, 'item_name', '*.txt')");
	}

	public function testRegistrationUpgradeFrom10KeepsPendingRegistrations(): void {
		list($updater, $controller, $update) = $this->updaterWithCoreSchema(array('Registration'));
		$this->sql("INSERT INTO `{P}parameter` (name, value) VALUES ('plugin_Registration_version', '1_0')");
		$this->sql("CREATE TABLE `{P}pending_registrations` (id int(11) NOT NULL auto_increment, name varchar(255) NOT NULL, password varchar(128) NOT NULL, email varchar(128) NOT NULL, `key` char(64) NOT NULL, time bigint(11) NOT NULL, PRIMARY KEY (id))");
		$this->sql("INSERT INTO `{P}pending_registrations` (id, name, password, email, `key`, time) VALUES (7, 'pending', 'c2VjcmV0', 'pending@example.test', 'abc123', 20240101120000)");

		$this->assertSame('Plugin Registration updated to 1.2', $this->upgradePlugin('Registration', $updater, $controller, $update));
		$row = $this->mysqli->query("SELECT id, name, password, email, `key`, time, confirmed, password_hint FROM `{$this->prefix}registration`")->fetch_assoc();
		$this->assertEquals(array('id' => 7, 'name' => 'pending', 'password' => 'c2VjcmV0', 'email' => 'pending@example.test', 'key' => 'abc123', 'time' => 20240101120000, 'confirmed' => NULL, 'password_hint' => NULL), $row);
		$this->assertSame(0, $this->mysqli->query("SHOW TABLES LIKE '{$this->prefix}pending_registrations'")->num_rows);
	}

	public function testShareUpgradeFrom10To15KeepsShares(): void {
		list($updater, $controller, $update) = $this->updaterWithCoreSchema(array('Share'));
		$this->sql("INSERT INTO `{P}parameter` (name, value) VALUES ('plugin_Share_version', '1_0')");
		$this->sql("CREATE TABLE `{P}share` (id char(32) NOT NULL, item_id char(255) NOT NULL, name varchar(255) NOT NULL, user_id int(11) NOT NULL, created bigint(11) NOT NULL, active TINYINT(1) NOT NULL, PRIMARY KEY (id))");
		$this->sql("INSERT INTO `{P}share` (id, item_id, name, user_id, created, active) VALUES ('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'item1', 'first', 5, 20240101000000, 1), ('BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', 'item2', 'second', 6, 20240102000000, 0)");

		$this->assertSame('Plugin Share updated to 1.5', $this->upgradePlugin('Share', $updater, $controller, $update));
		$rows = $this->mysqli->query("SELECT id, item_id, type, restriction, name, user_id, expiration, created, quick, active FROM `{$this->prefix}share` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
		$this->assertCount(2, $rows);
		$this->assertEquals(array('id' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'item_id' => 'item1', 'type' => NULL, 'restriction' => NULL, 'name' => 'first', 'user_id' => 5, 'expiration' => NULL, 'created' => 20240101000000, 'quick' => 0, 'active' => 1), $rows[0]);
		$this->assertEquals(0, $rows[1]['active']);
		$this->assertSame(1, $this->mysqli->query("SHOW TABLES LIKE '{$this->prefix}share_auth'")->num_rows);
	}

	/**
	 * Characterization, not a guarantee: MySQL commits DDL implicitly, so rolling back a failed update step
	 * cannot undo the columns it already added. The version stays at the old value and a plain retry then
	 * fails; the supported recovery is restoring the backup taken before updating.
	 */
	public function testAFailedMysqlUpgradeStepIsNotAtomicSoARestoreFromBackupIsRequired(): void {
		list($updater, $controller, $update) = $this->updaterWithCoreSchema(array());
		$table = $this->prefix . 'item_id';
		$statements = array(
			"ALTER TABLE `$table` DROP INDEX i_item_level",
			"ALTER TABLE `$table` DROP INDEX i_item_path_level",
			"ALTER TABLE `$table` DROP COLUMN `level`",
			"ALTER TABLE `$table` MODIFY COLUMN `id` char(13) NOT NULL",
			"UPDATE `{$this->prefix}parameter` SET `value` = '2_6_7' WHERE `name` = 'version'",
			"ALTER TABLE `$table` DROP INDEX `path`",
			"ALTER TABLE `$table` DROP COLUMN `path`",	// the step's UPDATE statement needs this column
		);
		foreach ($statements as $statement) {
			$this->assertTrue($this->mysqli->query($statement) !== FALSE, $this->mysqli->error);
		}

		$method = new ReflectionMethod(UpdateController::class, 'updateSystem');
		$updater->db()->startTransaction();
		try {
			$method->invoke($controller);
			$this->fail('The step must fail');
		} catch (ServiceException $e) {
			$updater->db()->rollback();
		}
		$this->assertSame('2_6_7', $updater->installedVersion());
		$columns = array_column($this->mysqli->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC), 'Field');
		$this->assertContains('level', $columns, 'the ALTER that ran before the failure stays applied');
	}
}

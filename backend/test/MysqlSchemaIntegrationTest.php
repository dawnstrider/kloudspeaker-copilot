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
}

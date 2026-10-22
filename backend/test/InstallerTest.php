<?php

require_once "TestCase.php";
require_once 'install/KloudspeakerInstallProcessor.class.php';
require_once 'install/pdo/PDOInstallUtil.class.php';
require_once 'db/pdo/DatabaseUtil.class.php';
require_once 'install/pdo/PDOInstaller.class.php';
require_once 'update/UpdateController.class.php';

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
}

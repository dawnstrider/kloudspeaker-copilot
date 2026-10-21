<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once 'db/mysql/MySQLIDatabase.class.php';

class DatabaseIntegrationTest extends TestCase {
    private $originalReporting;

    protected function setUp(): void {
        parent::setUp();
        $this->assertSame('1', getenv('KLOUDSPEAKER_DB_INTEGRATION'), 'Use the isolated database-tests Compose service');
        $this->originalReporting = (new mysqli_driver())->report_mode;
    }

    protected function tearDown(): void {
        mysqli_report($this->originalReporting);
        parent::tearDown();
    }

    public static function adapters(): array {
        return array('mysqli' => array('mysqli'), 'pdo_mysql' => array('pdo_mysql'));
    }

    private function connectAdapter($driver, $database = 'kloudspeaker_test', $password = 'fixture-password') {
        if ($driver === 'mysqli') {
            return MySQLIDatabase::createFromConf(array(
                'host' => 'mariadb', 'user' => 'migration_test', 'password' => $password,
                'database' => $database, 'charset' => 'utf8mb4',
            ));
        }
        return PDODatabase::createFromConf(array(
            'str' => 'mysql:host=mariadb;dbname=' . $database . ';charset=utf8mb4',
            'user' => 'migration_test', 'password' => $password,
        ));
    }

    private function createFixture($driver) {
        $database = $this->connectAdapter($driver);
        $database->queries('CREATE TEMPORARY TABLE migration_contract (id INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY, label VARCHAR(255) NULL, amount DECIMAL(20,4) NULL) ENGINE=InnoDB');
        return $database;
    }

    #[DataProvider('adapters')]
    public function testUnicodeEscapingNullsAndResultTypes($driver): void {
        $database = $this->createFixture($driver);
        $label = "caf\xc3\xa9 'quoted' \\ path \xf0\x9f\x98\x80";
        $this->assertSame(1, $database->update('INSERT INTO migration_contract (label, amount) VALUES (' . $database->string($label, true) . ', 1234567890123456.7890)'));
        $this->assertSame('1', (string) $database->lastId());
        $row = $database->query('SELECT id, label, amount FROM migration_contract')->firstRow();
        $this->assertSame($label, $row['label']);
        $this->assertSame('1234567890123456.7890', $row['amount']);
        if ($driver === 'mysqli') {
            $this->assertSame('1', $row['id']);
        } else {
            $this->assertSame(1, $row['id']);
        }
        $this->assertSame(1, $database->update('INSERT INTO migration_contract (label) VALUES (' . $database->string(null, true) . ')'));
        $this->assertNull($database->query('SELECT label FROM migration_contract WHERE id=2')->firstValue('label'));
    }

    #[DataProvider('adapters')]
    public function testFailedQueryAllowsRollback($driver): void {
        $database = $this->createFixture($driver);
        $database->startTransaction();
        $database->update("INSERT INTO migration_contract (label) VALUES ('rolled back')");
        try {
            $database->query('SELECT * FROM missing_migration_table');
            $this->fail('A missing table must produce a service error');
        } catch (ServiceException $exception) {
            $this->assertSame('INVALID_CONFIGURATION', $exception->type());
            $this->assertTrue($database->isTransaction());
        } finally {
            $database->rollback();
        }
        $this->assertFalse($database->isTransaction());
        $this->assertSame('0', (string) $database->query('SELECT COUNT(*) AS total FROM migration_contract')->firstValue('total'));
    }

    #[DataProvider('adapters')]
    public function testCommitAndEmptyResults($driver): void {
        $database = $this->createFixture($driver);
        $this->assertSame(0, $database->query('SELECT * FROM migration_contract')->count());
        $this->assertSame(array(), $database->query('SELECT * FROM migration_contract')->rows());
        $this->assertNull($database->query('SELECT label FROM migration_contract')->firstValue('label'));
        $this->assertNull($database->query('SELECT label FROM migration_contract')->value());
        $database->startTransaction();
        $database->startTransaction();
        $this->assertTrue($database->isTransaction());
        $database->update("INSERT INTO migration_contract (label) VALUES ('committed')");
        $database->commit();
        $this->assertFalse($database->isTransaction());
        $this->assertSame('committed', $database->query('SELECT label FROM migration_contract')->firstValue('label'));
    }

    #[DataProvider('adapters')]
    public function testMissingDatabaseProducesServiceError($driver): void {
        $this->expectException(ServiceException::class);
        $this->connectAdapter($driver, 'missing_migration_database');
    }

    #[DataProvider('adapters')]
    public function testConnectionErrorsDoNotExposePasswords($driver): void {
        try {
            $this->connectAdapter($driver, 'kloudspeaker_test', 'invalid-fixture-password');
            $this->fail('Invalid credentials must not connect');
        } catch (ServiceException $exception) {
            $this->assertSame('INVALID_CONFIGURATION', $exception->type());
            $this->assertStringNotContainsString('invalid-fixture-password', $exception->details());
        }
    }

    #[DataProvider('adapters')]
    public function testMultiQueryFailuresRemainControlled($driver): void {
        $database = $this->createFixture($driver);
        try {
            $database->queries("INSERT INTO migration_contract (label) VALUES ('first'); SELECT * FROM missing_migration_table");
            $this->fail('Multi-query failures must produce a service error');
        } catch (ServiceException $exception) {
            $this->assertSame('INVALID_CONFIGURATION', $exception->type());
        }
        $this->assertSame('first', $database->query('SELECT label FROM migration_contract')->firstValue('label'));
    }

    public function testMysqlReportingIsExplicit(): void {
        mysqli_report(MYSQLI_REPORT_OFF);
        $database = $this->connectAdapter('mysqli');
        $this->assertTrue($database->isConnected());
        $this->assertSame(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, (new mysqli_driver())->report_mode);
    }

    public function testMysqlDatabaseSelectionFailureIsControlled(): void {
        $database = new MySQLIDatabase('mariadb', 'migration_test', 'fixture-password', 'missing_migration_database', '', null, null);
        $database->connect(false);
        $this->assertFalse($database->databaseExists());
        $this->expectException(ServiceException::class);
        $database->selectDb();
    }
}
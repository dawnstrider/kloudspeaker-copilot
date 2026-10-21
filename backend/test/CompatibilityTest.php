<?php

error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function compatibilityCheck($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

chdir(dirname(__DIR__));
set_include_path(getcwd() . PATH_SEPARATOR . getcwd() . '/include' . PATH_SEPARATOR . get_include_path());
require_once 'include/Request.class.php';
require_once 'include/KloudspeakerBackend.class.php';

$request = new Request(null, Request::METHOD_POST, '', 'local', array(), array(), array('value' => null));
compatibilityCheck($request->hasData('value'), 'Present null request values must remain distinguishable from absent keys');
compatibilityCheck(!$request->hasData('missing'), 'Missing request keys must remain absent');
compatibilityCheck($request->data['value'] === null, 'Request data must remain publicly accessible');
$emptyRequest = new Request(null, Request::METHOD_GET, '', 'local', array(), array(), null);
compatibilityCheck(!$emptyRequest->hasData(), 'Requests without a body must remain empty');

$coreFields = array(
    'KloudspeakerBackend' => array('settings', 'session', 'configuration'),
    'ServiceEnvironment' => array('cookies', 'features', 'plugins', 'resources', 'passwordHash', 'commands'),
    'FilesystemController' => array('forbiddenUploadTypes', 'ignoredItems'),
    'MultiFileEvent' => array('info'),
    'Kloudspeaker_PasswordHash' => array('hasher'),
);
foreach ($coreFields as $className => $fields) {
    $reflection = new ReflectionClass($className);
    $instance = $reflection->newInstanceWithoutConstructor();
    foreach ($fields as $field) {
        compatibilityCheck($reflection->hasProperty($field), $className . ' must declare ' . $field);
        compatibilityCheck($reflection->getProperty($field)->isPublic(), $className . ' must preserve public access to ' . $field);
        $instance->$field = null;
    }
}

require_once 'include/services/SessionServices.class.php';
require_once 'include/services/FilesystemServices.class.php';

$settings = new class {
    public function setting($name) {
        return null;
    }
};
$passwordHasher = new Kloudspeaker_PasswordHash($settings);
compatibilityCheck($passwordHasher->hasher instanceof PasswordHash, 'The password wrapper must retain its existing hasher');
$event = MultiFileEvent::download(array());
compatibilityCheck($event->items() === array() && $event->info() === null, 'Multi-file event construction must preserve its payload');

function compatibilityInvalidRequest($serviceClass, $path, $data) {
    $request = new Request(null, Request::METHOD_POST, implode('/', $path), 'local', $path, array(), $data);
    $service = new $serviceClass(null, $request, 'compatibility', $path);
    try {
        $service->processPost();
    } catch (ServiceException $exception) {
        compatibilityCheck($exception->type() === 'INVALID_REQUEST', 'Malformed requests must return INVALID_REQUEST');
        return;
    }
    throw new RuntimeException('Malformed request was not rejected by ' . $serviceClass);
}

foreach (array(null, array(), 1, false) as $invalidCredential) {
    compatibilityInvalidRequest('SessionServices', array('authenticate'), array('username' => 'test', 'password' => $invalidCredential));
    compatibilityInvalidRequest('SessionServices', array('authenticate'), array('username' => $invalidCredential, 'password' => 'dGVzdA=='));
}
compatibilityInvalidRequest('SessionServices', array('authenticate'), array('username' => 'test'));
foreach (array(null, 'item', 1, false, array(), array(null), array(array())) as $invalidItems) {
    compatibilityInvalidRequest('FilesystemServices', array('items'), array('action' => 'delete', 'items' => $invalidItems));
}
compatibilityInvalidRequest('FilesystemServices', array('items', 'extra'), array('action' => 'delete', 'items' => array()));

require_once 'include/Logging.class.php';
require_once 'db/sqlite/SQLite3Database.class.php';
$sqlite = KloudspeakerSQLite3Database::createFromConf(array('file' => ':memory:'));
$sqlite->queries('CREATE TABLE compatibility_items (id INTEGER PRIMARY KEY, label TEXT NULL)');
compatibilityCheck($sqlite->isConnected(), 'Native SQLite must connect');
compatibilityCheck($sqlite->query('SELECT label FROM compatibility_items')->firstValue('label') === null, 'Empty native SQLite results must return null');
$sqlite->startTransaction();
$sqlite->startTransaction();
compatibilityCheck($sqlite->update("INSERT INTO compatibility_items (label) VALUES ('temporary')") === 1, 'Native SQLite must report affected rows');
compatibilityCheck($sqlite->lastId() === 1, 'Native SQLite must report the inserted ID');
try {
    $sqlite->query('SELECT * FROM missing_compatibility_table');
    throw new RuntimeException('Native SQLite query failures must produce service errors');
} catch (ServiceException $exception) {
    compatibilityCheck($exception->type() === 'INVALID_CONFIGURATION', 'Native SQLite query errors must retain their service type');
}
$sqlite->rollback();
compatibilityCheck(!$sqlite->isTransaction(), 'Native SQLite rollback must clear transaction state');
compatibilityCheck($sqlite->query('SELECT COUNT(*) AS total FROM compatibility_items')->firstValue('total') === 0, 'Native SQLite rollback must undo changes');
try {
    $sqlite->queries('SELECT * FROM missing_compatibility_table');
    throw new RuntimeException('Native SQLite batch failures must produce service errors');
} catch (ServiceException $exception) {
    compatibilityCheck($exception->type() === 'INVALID_CONFIGURATION', 'Native SQLite batch errors must retain their service type');
}
$label = "caf\xc3\xa9 'quoted'";
$sqlite->startTransaction();
$sqlite->update('INSERT INTO compatibility_items (label) VALUES (' . $sqlite->string($label, true) . ')');
$sqlite->update('INSERT INTO compatibility_items (label) VALUES (' . $sqlite->string(null, true) . ')');
$sqlite->commit();
$sqliteResult = $sqlite->query('SELECT label FROM compatibility_items ORDER BY id');
compatibilityCheck($sqliteResult->count() === 2, 'Native SQLite must count rows');
$sqliteRows = $sqliteResult->rows();
compatibilityCheck($sqliteRows[0]['label'] === $label && $sqliteRows[1]['label'] === null, 'Native SQLite count must not consume rows or alter values');
$sqliteResult->free();
compatibilityCheck($sqlite->query('SELECT label FROM compatibility_items WHERE id=-1')->value() === null, 'Empty native SQLite positional results must return null');

require_once 'include/Settings.class.php';
require_once 'db/DBConnectionFactory.class.php';
$legacyFile = tempnam(sys_get_temp_dir(), 'kloudspeaker-sqlite2-');
$legacyContents = "legacy-database-fixture\x00unchanged";
file_put_contents($legacyFile, $legacyContents);
try {
    $legacySettings = new Settings(array('db' => array('type' => 'sqlite', 'file' => $legacyFile)));
    try {
        (new DBConnectionFactory())->createConnection($legacySettings);
        throw new RuntimeException('SQLite2 configuration must be rejected before opening the file');
    } catch (ServiceException $exception) {
        compatibilityCheck($exception->type() === 'INVALID_CONFIGURATION', 'SQLite2 must produce an actionable configuration error');
        compatibilityCheck(strpos($exception->details(), 'SQLite2') !== false && strpos($exception->details(), 'SQLite3') !== false, 'SQLite2 errors must identify the unsupported format and migration target');
    }
    compatibilityCheck(file_get_contents($legacyFile) === $legacyContents, 'Rejecting SQLite2 must not modify the database file');
} finally {
    unlink($legacyFile);
}

echo "PASS: PHP compatibility checks\n";
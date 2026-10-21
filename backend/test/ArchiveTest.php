<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once 'plugin/Archiver/KloudspeakerCompressor.class.php';
require_once 'plugin/Archiver/zip/KloudspeakerZipArchive.class.php';
require_once 'plugin/Archiver/zip/KloudspeakerZipRaw.class.php';
require_once 'plugin/Archiver/zip/KloudspeakerZipNative.class.php';

class ArchiveTest extends TestCase {
    private $directory;
    private $writers = array();
    private $archives = array();

    protected function setUp(): void {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/kloudspeaker-archive-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void {
        $this->writers = array();
        foreach ($this->archives as $path) {
            if (is_file($path)) unlink($path);
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public static function writers(): array {
        return array('ziparchive' => array(KloudspeakerZipArchive::class), 'raw' => array(KloudspeakerZipRaw::class), 'command' => array(KloudspeakerZipNative::class));
    }

    private function writer($class) {
        $writer = new $class(null);
        $this->writers[] = $writer;
        $this->archives[] = $writer->filename();
        return $writer;
    }

    public function testCommandWriterHonorsNamesWithoutChangingWorkingDirectory(): void {
        $writer = $this->writer(KloudspeakerZipNative::class);
        $path = $this->directory . '/source.bin';
        file_put_contents($path, "command\x00fixture");
        $cwd = getcwd();
        $writer->add('requested/alias.bin', $path);
        $writer->finish();
        $this->assertSame($cwd, getcwd());
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($writer->filename(), ZipArchive::CHECKCONS));
        try {
            $this->assertSame("command\x00fixture", $reader->getFromName('requested/alias.bin'));
        } finally {
            $reader->close();
        }
    }

    public function testCommandWriterEmptyHelpersTerminateAndDirectoryNamesArePreserved(): void {
        $writer = $this->writer(KloudspeakerZipNative::class);
        $this->assertNull($writer->common_path(array()));
        $folder = $this->directory . '/directory';
        mkdir($folder);
        mkdir($folder . '/empty');
        file_put_contents($folder . '/data.txt', 'command directory fixture');
        $writer->add('renamed', $folder);
        $writer->finish();
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($writer->filename(), ZipArchive::CHECKCONS));
        $this->assertSame('command directory fixture', $reader->getFromName('renamed/data.txt'));
        $this->assertNotFalse($reader->locateName('renamed/empty/'));
        $reader->close();
    }

    public function testCommandArgumentsAreNotExpandedByAShell(): void {
        $writer = $this->writer(KloudspeakerZipNative::class);
        $path = $this->directory . '/source.txt';
        file_put_contents($path, 'literal argument fixture');
        $name = '$(touch injected); literal.txt';
        $writer->add($name, $path);
        $writer->finish();
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($writer->filename(), ZipArchive::CHECKCONS));
        $this->assertSame('literal argument fixture', $reader->getFromName($name));
        $reader->close();
    }

    public function testCommandFailuresRemovePartialOutputAndWorkspace(): void {
        $before = glob(sys_get_temp_dir() . '/Kloudspeaker-zip-work-*');
        $writer = new class(null) extends KloudspeakerZipNative {
            protected function runCommand($arguments, $directory) {
                foreach ($arguments as $argument) {
                    if (str_ends_with($argument, '/output.zip')) file_put_contents($argument, 'partial archive');
                }
                return 12;
            }
        };
        $this->writers[] = $writer;
        $this->archives[] = $writer->filename();
        $path = $this->directory . '/source.txt';
        file_put_contents($path, 'fixture');
        $writer->add('data.txt', $path);
        $cwd = getcwd();
        $this->assertServiceFailure(fn() => $writer->finish());
        $this->assertSame($cwd, getcwd());
        $this->assertFileDoesNotExist($writer->filename());
        $this->assertSame($before, glob(sys_get_temp_dir() . '/Kloudspeaker-zip-work-*'));
    }

    public function testMissingCommandProducesConfigurationError(): void {
        $path = getenv('PATH');
        putenv('PATH=' . $this->directory . '/no-tools');
        try {
            $this->assertServiceFailure(fn() => new KloudspeakerZipNative(null), 'INVALID_CONFIGURATION');
        } finally {
            if ($path === false) putenv('PATH');
            else putenv('PATH=' . $path);
        }
    }

    public function testCommandWriterFallsBackToSevenZip(): void {
        $tools = $this->directory . '/tools';
        mkdir($tools);
        symlink('/usr/bin/7z', $tools . '/7z');
        $path = getenv('PATH');
        putenv('PATH=' . $tools);
        try {
            $writer = $this->writer(KloudspeakerZipNative::class);
        } finally {
            if ($path === false) putenv('PATH');
            else putenv('PATH=' . $path);
        }
        $source = $this->directory . '/source.txt';
        file_put_contents($source, '7z fixture');
        $writer->add('renamed/source.txt', $source);
        $writer->finish();
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($writer->filename(), ZipArchive::CHECKCONS));
        $this->assertSame('7z fixture', $reader->getFromName('renamed/source.txt'));
        $reader->close();
    }

    public function testCommandWriterSevenZipPreservesDirectoriesAndEmptyFolders(): void {
        $tools = $this->directory . '/seven-tools';
        mkdir($tools);
        symlink('/usr/bin/7z', $tools . '/7z');
        $path = getenv('PATH');
        putenv('PATH=' . $tools);
        try {
            $writer = $this->writer(KloudspeakerZipNative::class);
        } finally {
            if ($path === false) putenv('PATH');
            else putenv('PATH=' . $path);
        }
        $folder = $this->directory . '/source-folder';
        mkdir($folder);
        mkdir($folder . '/empty');
        file_put_contents($folder . '/data.txt', 'seven directory bytes');
        $writer->add('requested-folder', $folder);
        $writer->finish();
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($writer->filename(), ZipArchive::CHECKCONS));
        $this->assertSame('seven directory bytes', $reader->getFromName('requested-folder/data.txt'));
        $this->assertNotFalse($reader->locateName('requested-folder/empty/'));
        $reader->close();
    }

    public function testCommandWriterBatchesPreserveLiteralNamesAndContents(): void {
        $writer = $this->writer(KloudspeakerZipNative::class);
        $path = $this->directory . '/batch-source.txt';
        file_put_contents($path, 'batch fixture');
        for ($entry = 0; $entry < 205; $entry++) $writer->add('nested/item ' . $entry . '[literal].txt', $path);
        $writer->finish();
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($writer->filename(), ZipArchive::CHECKCONS));
        $this->assertSame(205, $reader->numFiles);
        foreach (array(0, 99, 100, 204) as $entry) $this->assertSame('batch fixture', $reader->getFromName('nested/item ' . $entry . '[literal].txt'));
        $reader->close();
    }

    private function extractionFixture($entries) {
        $path = $this->directory . '/extract-source.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            if (str_ends_with($name, '/')) $zip->addEmptyDir($name);
            else $zip->addFromString($name, $contents);
        }
        $this->assertTrue($zip->close());
        return $path;
    }

    public function testExtractionPreservesBinaryUnicodeAndEmptyDirectories(): void {
        $name = "nested/caf\xc3\xa9.bin";
        $source = $this->extractionFixture(array($name => "extract\x00\xff", 'empty/' => ''));
        $checksum = hash_file('sha256', $source);
        $target = $this->directory . '/extracted';
        mkdir($target);
        $this->manager('ziparchive')->extract($source, $target);
        $this->assertSame("extract\x00\xff", file_get_contents($target . '/' . $name));
        $this->assertDirectoryExists($target . '/empty');
        $this->assertSame($checksum, hash_file('sha256', $source));
    }

    public function testCorruptExtractionPreservesExistingDestination(): void {
        $source = $this->directory . '/corrupt.zip';
        file_put_contents($source, 'invalid ZIP fixture');
        $target = $this->directory . '/existing';
        mkdir($target);
        file_put_contents($target . '/keep.txt', 'existing bytes');
        $this->assertServiceFailure(fn() => $this->manager('ziparchive')->extract($source, $target));
        $this->assertSame('existing bytes', file_get_contents($target . '/keep.txt'));
    }

    public function testExtractionRejectsTraversalBeforeWritingAnyEntries(): void {
        foreach (array('../outside.txt', 'nested/../../outside.txt', 'nested\\..\\outside.txt', '/absolute.txt', 'C:/absolute.txt') as $name) {
            $source = $this->extractionFixture(array('safe.txt' => 'must not be written', $name => 'unsafe entry'));
            $target = $this->directory . '/traversal-target';
            if (!is_dir($target)) mkdir($target);
            $this->assertServiceFailure(fn() => $this->manager('ziparchive')->extract($source, $target), 'INVALID_REQUEST');
            $this->assertFileDoesNotExist($target . '/safe.txt');
            $this->assertFileDoesNotExist($this->directory . '/outside.txt');
        }
    }

    public function testExtractionRejectsSymlinkEntries(): void {
        $source = $this->extractionFixture(array('link' => '../outside.txt'));
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($source));
        $this->assertTrue($zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16));
        $this->assertTrue($zip->close());
        $target = $this->directory . '/link-target';
        mkdir($target);
        $this->assertServiceFailure(fn() => $this->manager('ziparchive')->extract($source, $target), 'INVALID_REQUEST');
        $this->assertFileDoesNotExist($target . '/link');
    }

    public function testExtractionMergesByDefaultAndReplacesOnlyWhenRequested(): void {
        $source = $this->extractionFixture(array('new.txt' => 'new bytes'));
        $target = $this->directory . '/merge';
        mkdir($target);
        file_put_contents($target . '/keep.txt', 'original bytes');
        $manager = $this->manager('ziparchive');
        $manager->extract($source, $target);
        $this->assertSame('original bytes', file_get_contents($target . '/keep.txt'));
        $this->assertSame('new bytes', file_get_contents($target . '/new.txt'));
        $manager->extract($source, $target, true);
        $this->assertFileDoesNotExist($target . '/keep.txt');
        $this->assertSame('new bytes', file_get_contents($target . '/new.txt'));
        $this->assertSame(array(), glob($this->directory . '/.Kloudspeaker-extract-*'));
    }

    public function testExtractionMergePreservesExistingPrivatePermissionBits(): void {
        $source = $this->extractionFixture(array('new.txt' => 'new bytes'));
        $target = $this->directory . '/private-target';
        mkdir($target, 0700);
        mkdir($target . '/private', 0700);
        file_put_contents($target . '/private/keep.txt', 'private bytes');
        chmod($target . '/private/keep.txt', 0600);
        $this->manager('ziparchive')->extract($source, $target);
        clearstatcache();
        $this->assertSame(0700, fileperms($target) & 0777);
        $this->assertSame(0700, fileperms($target . '/private') & 0777);
        $this->assertSame(0600, fileperms($target . '/private/keep.txt') & 0777);
        $this->assertSame('private bytes', file_get_contents($target . '/private/keep.txt'));
    }

    public function testExtractionLimitsRejectBeforePublishing(): void {
        $source = $this->extractionFixture(array('first.txt' => '12345', 'second.txt' => '67890'));
        foreach (array(array('max_extract_entries' => 1), array('max_extract_bytes' => 9)) as $settings) {
            $target = $this->directory . '/limited';
            $this->assertServiceFailure(fn() => $this->manager('ziparchive', false, $settings)->extract($source, $target), 'INVALID_REQUEST');
            $this->assertDirectoryDoesNotExist($target);
            $this->assertSame(array(), glob($this->directory . '/.Kloudspeaker-extract-*'));
        }
    }

    public function testExtractionRejectsEncryptedEntriesAndPathTypeConflicts(): void {
        $source = $this->extractionFixture(array('encrypted.txt' => 'fixture secret'));
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($source));
        $this->assertTrue($zip->setEncryptionName('encrypted.txt', ZipArchive::EM_AES_256, 'fixture-password'));
        $this->assertTrue($zip->close());
        $target = $this->directory . '/rejected';
        $this->assertServiceFailure(fn() => $this->manager('ziparchive')->extract($source, $target), 'INVALID_REQUEST');
        $this->assertDirectoryDoesNotExist($target);
        $source = $this->extractionFixture(array('parent' => 'file, not directory', 'parent/child.txt' => 'child'));
        $this->assertServiceFailure(fn() => $this->manager('ziparchive')->extract($source, $target), 'INVALID_REQUEST');
        $this->assertDirectoryDoesNotExist($target);
    }

    public function testExtractionRejectsExistingDestinationSymlinks(): void {
        $source = $this->extractionFixture(array('safe.txt' => 'fixture'));
        $outside = $this->directory . '/outside-directory';
        mkdir($outside);
        $target = $this->directory . '/linked-directory';
        symlink($outside, $target);
        $this->assertServiceFailure(fn() => $this->manager('ziparchive')->extract($source, $target), 'INVALID_REQUEST');
        $this->assertSame(array('.', '..'), scandir($outside));
        unlink($target);
        mkdir($target);
        symlink($outside, $target . '/child');
        $this->assertServiceFailure(fn() => $this->manager('ziparchive')->extract($source, $target), 'INVALID_REQUEST');
        $this->assertSame(array('.', '..'), scandir($outside));
        $this->assertSame(array(), glob($this->directory . '/.Kloudspeaker-extract-*'));
    }

    public function testExtractionCrcFailureLeavesExistingDestinationUntouched(): void {
        $payload = 'UNIQUE_CRC_PAYLOAD_123456789';
        $source = $this->extractionFixture(array('payload.txt' => $payload));
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($source));
        $this->assertTrue($zip->setCompressionName('payload.txt', ZipArchive::CM_STORE));
        $this->assertTrue($zip->close());
        $bytes = file_get_contents($source);
        $this->assertStringContainsString($payload, $bytes);
        file_put_contents($source, str_replace($payload, str_repeat('X', strlen($payload)), $bytes));
        $target = $this->directory . '/crc-target';
        mkdir($target);
        file_put_contents($target . '/keep.txt', 'original');
        $this->assertServiceFailure(fn() => $this->manager('ziparchive')->extract($source, $target, true));
        $this->assertSame('original', file_get_contents($target . '/keep.txt'));
        $this->assertFileDoesNotExist($target . '/payload.txt');
        $this->assertSame(array(), glob($this->directory . '/.Kloudspeaker-extract-*'));
    }

    private function extractionService($source, $options = array()) {
        require_once 'plugin/Archiver/ArchiverServices.class.php';
        require_once 'plugin/Archiver/ArchiveManager.class.php';
        $parentPath = $this->directory . '/chosen';
        mkdir($parentPath);
        $targetPath = $parentPath . '/' . str_replace('.', '_', basename($source));
        if (!empty($options['existing'])) {
            mkdir($targetPath);
            file_put_contents($targetPath . '/keep.txt', 'existing service bytes');
        }
        $target = new class($targetPath) {
            public $deleted = false;
            private $path;
            public function __construct($path) { $this->path = $path; }
            public function internalPath() { return $this->path; }
            public function exists() { return is_dir($this->path); }
            public function delete() { $this->deleted = true; rmdir($this->path); }
        };
        $parent = new class($target) {
            private $target;
            public function __construct($target) { $this->target = $target; }
            public function folderWithName($name) { return $this->target; }
        };
        $archive = new class($source) {
            private $path;
            public function __construct($path) { $this->path = $path; }
            public function internalPath() { return $this->path; }
            public function parent() { throw new RuntimeException('Explicit destination folder was ignored'); }
        };
        $environment = new class($archive, $parent, $target, $options) {
            public $manager;
            public $success = false;
            public $eventsSent = 0;
            public $rights = array();
            private $archive;
            private $parent;
            private $target;
            private $options;
            public function __construct($archive, $parent, $target, $options) { $this->archive = $archive; $this->parent = $parent; $this->target = $target; $this->options = $options; }
            public function filesystem() { return $this; }
            public function item($id) { return $id === 'archive' ? $this->archive : $this->parent; }
            public function assertRights($subject, $permission, $action) {
                $this->rights[] = array($subject, $permission);
                if ($subject === $this->target && !empty($this->options['denyReplacement'])) throw new ServiceException('FIXTURE_PERMISSION_DENIED');
            }
            public function validateAction($action, $target) {}
            public function triggerActionInterceptor($action, $target) { return !empty($this->options['intercept']); }
            public function createItem($target) { mkdir($target->internalPath()); }
            public function plugins() { return $this; }
            public function getPlugin($id) { return $this; }
            public function getArchiveManager() { return $this->manager; }
            public function events() { return $this; }
            public function onEvent($event) { $this->eventsSent++; }
            public function response() { return $this; }
            public function success($data) { $this->success = true; }
        };
        $environment->manager = new ArchiveManager($environment, 'ziparchive', array());
        $request = new Request(null, Request::METHOD_POST, 'archiver/extract', 'local', array('extract'), array(), array('item' => 'archive', 'folder' => 'chosen', 'overwrite' => !empty($options['overwrite'])));
        $service = new ArchiverServices($environment, $request, 'archiver', array('extract'));
        return array($service, $environment, $target);
    }

    public function testExtractionServiceUsesSelectedFolderAndPublishesAfterValidation(): void {
        $source = $this->extractionFixture(array('new.txt' => 'service bytes'));
        [$service, $environment, $target] = $this->extractionService($source);
        $service->processPost();
        $this->assertSame('service bytes', file_get_contents($target->internalPath() . '/new.txt'));
        $this->assertTrue($environment->success);
        $this->assertSame(1, $environment->eventsSent);
        $this->assertFalse($target->deleted);
    }

    public function testExtractionServicePreservesExistingTargetWhenSourceIsInvalid(): void {
        $source = $this->directory . '/corrupt.zip';
        file_put_contents($source, 'invalid archive');
        [$service, $environment, $target] = $this->extractionService($source, array('existing' => true, 'overwrite' => true));
        $this->assertServiceFailure(fn() => $service->processPost());
        $this->assertSame('existing service bytes', file_get_contents($target->internalPath() . '/keep.txt'));
        $this->assertFalse($target->deleted);
        $this->assertFalse($environment->success);
    }

    public function testExtractionServiceRemovesOnlyNewFolderOnFailure(): void {
        $source = $this->directory . '/corrupt.zip';
        file_put_contents($source, 'invalid archive');
        [$service, $environment, $target] = $this->extractionService($source);
        $this->assertServiceFailure(fn() => $service->processPost());
        $this->assertDirectoryDoesNotExist($target->internalPath());
        $this->assertTrue($target->deleted);
        $this->assertSame(0, $environment->eventsSent);
    }

    public function testExtractionServiceChecksReplacementRights(): void {
        $source = $this->extractionFixture(array('new.txt' => 'new bytes'));
        [$service, $environment, $target] = $this->extractionService($source, array('existing' => true, 'overwrite' => true, 'denyReplacement' => true));
        $this->assertServiceFailure(fn() => $service->processPost(), 'FIXTURE_PERMISSION_DENIED');
        $this->assertSame('existing service bytes', file_get_contents($target->internalPath() . '/keep.txt'));
        $this->assertFalse($target->deleted);
        $this->assertFalse($environment->success);
    }

    public function testExtractionServiceHonorsActionInterceptors(): void {
        $source = $this->extractionFixture(array('new.txt' => 'new bytes'));
        [$service, $environment, $target] = $this->extractionService($source, array('intercept' => true));
        $service->processPost();
        $this->assertDirectoryDoesNotExist($target->internalPath());
        $this->assertFalse($environment->success);
        $this->assertSame(0, $environment->eventsSent);
    }

    public function testExtractionServiceRejectsMalformedOverwriteFlags(): void {
        require_once 'plugin/Archiver/ArchiverServices.class.php';
        foreach (array(array(), 'false', 'yes') as $overwrite) {
            $request = new Request(null, Request::METHOD_POST, 'archiver/extract', 'local', array('extract'), array(), array('item' => 'fixture', 'overwrite' => $overwrite));
            $environment = new class {
                public function plugins() { return $this; }
                public function getPlugin($id) { return $this; }
                public function getArchiveManager() { return $this; }
                public function isActionEnabled($action) { return true; }
            };
            $service = new ArchiverServices($environment, $request, 'archiver', array('extract'));
            $this->assertServiceFailure(fn() => $service->processPost(), 'INVALID_REQUEST');
        }
    }

    public function testExtractionMergeConflictsAreControlledAndPreserveDestination(): void {
        $cases = array(
            array(array('parent/child.txt' => 'new'), false),
            array(array('parent/deeper/child.txt' => 'new'), false),
            array(array('parent' => 'new'), true),
            array(array('parent/' => ''), false),
        );
        foreach ($cases as $index => $case) {
            $source = $this->extractionFixture($case[0]);
            $target = $this->directory . '/merge-conflict-' . $index;
            mkdir($target);
            $original = $target . '/parent';
            if ($case[1]) {
                mkdir($original);
                $original .= '/keep.txt';
            }
            file_put_contents($original, 'preserved original');
            $this->assertServiceFailure(fn() => $this->manager('ziparchive')->extract($source, $target));
            $this->assertSame('preserved original', file_get_contents($original));
            $this->assertSame(array(), glob($this->directory . '/.Kloudspeaker-extract-*'));
        }
    }

    #[DataProvider('writers')]
    public function testBinaryUnicodeAndStreamRoundTrip($class): void {
        $writer = $this->writer($class);
        $path = $this->directory . '/source.bin';
        $bytes = "fixture\x00\x01\xff" . str_repeat('binary', 100);
        $name = "nested/caf\xc3\xa9.bin";
        file_put_contents($path, $bytes);
        $writer->add($name, $path, strlen($bytes));
        $writer->finish();
        $stream = $writer->stream();
        $this->assertIsResource($stream);
        try {
            $this->assertSame(file_get_contents($writer->filename()), stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($writer->filename(), ZipArchive::CHECKCONS));
        try {
            $this->assertSame(1, $reader->numFiles);
            $this->assertSame($name, $reader->getNameIndex(0));
            $this->assertSame($bytes, $reader->getFromName($name));
        } finally {
            $reader->close();
        }
    }

    #[DataProvider('writers')]
    public function testLargerFilesRoundTripThroughStreamingReaders($class): void {
        $path = $this->directory . '/larger.bin';
        $source = fopen($path, 'wb');
        $chunk = random_bytes(65536);
        try {
            for ($part = 0; $part < 256; $part++) fwrite($source, $chunk);
        } finally {
            fclose($source);
        }
        $expected = hash_file('sha256', $path);
        $writer = $this->writer($class);
        $writer->add('larger.bin', $path, 16 * 1024 * 1024);
        $writer->finish();
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($writer->filename(), ZipArchive::CHECKCONS));
        $stream = $reader->getStream('larger.bin');
        $this->assertIsResource($stream);
        try {
            $checksum = hash_init('sha256');
            $this->assertSame(16 * 1024 * 1024, hash_update_stream($checksum, $stream));
            $this->assertSame($expected, hash_final($checksum));
        } finally {
            fclose($stream);
            $reader->close();
        }
    }

    #[DataProvider('writers')]
    public function testMissingSourcesFailWithoutSilentSuccess($class): void {
        $writer = $this->writer($class);
        try {
            $writer->add('missing.txt', $this->directory . '/missing.txt');
            $this->fail('Missing sources must produce a controlled service error');
        } catch (ServiceException $exception) {
            $this->assertSame('REQUEST_FAILED', $exception->type());
        }
    }

    private function assertServiceFailure($callback, $type = 'REQUEST_FAILED'): void {
        try {
            $callback();
            $this->fail('Expected a controlled archive failure');
        } catch (ServiceException $exception) {
            $this->assertSame($type, $exception->type());
        }
    }

    #[DataProvider('writers')]
    public function testEmptyArchiveIsValid($class): void {
        $writer = $this->writer($class);
        $writer->finish();
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($writer->filename(), ZipArchive::CHECKCONS));
        $this->assertSame(0, $reader->numFiles);
        $reader->close();
    }

    #[DataProvider('writers')]
    public function testEmptyFilesAndDirectoriesAreRetained($class): void {
        $writer = $this->writer($class);
        $path = $this->directory . '/empty.txt';
        file_put_contents($path, '');
        $writer->add('empty.txt', $path);
        $writer->addEmptyDir('nested\\empty');
        $writer->finish();
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($writer->filename(), ZipArchive::CHECKCONS));
        $this->assertSame(2, $reader->numFiles);
        $this->assertSame('', $reader->getFromName('empty.txt'));
        $this->assertNotFalse($reader->locateName('nested/empty/'));
        $reader->close();
    }

    #[DataProvider('writers')]
    public function testUnfinishedArchivesCannotBeRead($class): void {
        $writer = $this->writer($class);
        $this->assertServiceFailure(fn() => $writer->stream());
    }

    #[DataProvider('writers')]
    public function testFinishIsIdempotentAndLateWritesAreRejected($class): void {
        $writer = $this->writer($class);
        $path = $this->directory . '/data.txt';
        file_put_contents($path, 'fixture');
        $writer->add('data.txt', $path);
        $writer->finish();
        $checksum = hash_file('sha256', $writer->filename());
        $writer->finish();
        $this->assertSame($checksum, hash_file('sha256', $writer->filename()));
        $this->assertServiceFailure(fn() => $writer->add('late.txt', $path));
        $this->assertSame($checksum, hash_file('sha256', $writer->filename()));
    }

    #[DataProvider('writers')]
    public function testCleanupRemovesUnfinishedOutputAndIsIdempotent($class): void {
        $writer = $this->writer($class);
        $writer->cleanup();
        $writer->cleanup();
        $this->assertFileDoesNotExist($writer->filename());
        $this->assertServiceFailure(fn() => $writer->finish());
        $this->assertServiceFailure(fn() => $writer->stream());
    }

    #[DataProvider('writers')]
    public function testCleanupAfterFinishCannotReportDeletedArchiveAsReady($class): void {
        $writer = $this->writer($class);
        $path = $this->directory . '/cleanup.txt';
        file_put_contents($path, 'fixture');
        $writer->add('cleanup.txt', $path);
        $writer->finish();
        $writer->cleanup();
        $this->assertFileDoesNotExist($writer->filename());
        $this->assertServiceFailure(fn() => $writer->finish());
        $this->assertServiceFailure(fn() => $writer->add('late.txt', $path));
        $this->assertServiceFailure(fn() => $writer->stream());
    }

    #[DataProvider('writers')]
    public function testUnsafeEntryNamesAreRejectedWithoutPartialArchives($class): void {
        $path = $this->directory . '/data.txt';
        file_put_contents($path, 'fixture');
        foreach (array('../outside.txt', '/absolute.txt', 'C:\\absolute.txt', 'safe/../outside.txt', '.', "bad\0name") as $name) {
            $writer = $this->writer($class);
            $this->assertServiceFailure(fn() => $writer->add($name, $path), 'INVALID_REQUEST');
            $this->assertFileDoesNotExist($writer->filename());
        }
    }

    #[DataProvider('writers')]
    public function testDuplicateEntriesFailRatherThanOverwrite($class): void {
        $writer = $this->writer($class);
        $path = $this->directory . '/data.txt';
        file_put_contents($path, 'fixture');
        $writer->add('same.txt', $path);
        $this->assertServiceFailure(fn() => $writer->add('same.txt', $path), 'INVALID_REQUEST');
        $this->assertFileDoesNotExist($writer->filename());
    }

    #[DataProvider('writers')]
    public function testEntrySeparatorsAreNormalized($class): void {
        $writer = $this->writer($class);
        $path = $this->directory . '/data.txt';
        file_put_contents($path, 'fixture');
        $writer->add('nested\\folder//data.txt', $path);
        $writer->finish();
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($writer->filename(), ZipArchive::CHECKCONS));
        $this->assertSame('fixture', $reader->getFromName('nested/folder/data.txt'));
        $reader->close();
    }

    public function testRawDirectoryRecursionRetainsNamesBytesAndEmptyFolders(): void {
        $writer = $this->writer(KloudspeakerZipRaw::class);
        $folder = $this->directory . '/folder';
        mkdir($folder);
        mkdir($folder . '/empty');
        file_put_contents($folder . '/data.bin', "data\x00\xff");
        $writer->add('root', $folder);
        $writer->finish();
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($writer->filename(), ZipArchive::CHECKCONS));
        $this->assertSame("data\x00\xff", $reader->getFromName('root/data.bin'));
        $this->assertNotFalse($reader->locateName('root/empty/'));
        $reader->close();
    }

    public function testNativeDelayedSourceFailureRemovesPartialArchive(): void {
        $writer = $this->writer(KloudspeakerZipArchive::class);
        $path = $this->directory . '/data.txt';
        file_put_contents($path, 'fixture');
        $writer->add('data.txt', $path);
        unlink($path);
        $this->assertServiceFailure(fn() => $writer->finish());
        $this->assertFileDoesNotExist($writer->filename());
    }

    public static function managers(): array {
        return array('ziparchive' => array('ziparchive'), 'raw' => array('raw'), 'command' => array('native'));
    }

    private function item($failAfterAdd = false) {
        $path = $this->directory . '/item.bin';
        file_put_contents($path, "manager\x00fixture");
        return new class($path, $failAfterAdd) {
            public $writer;
            private $path;
            private $failAfterAdd;
            public function __construct($path, $failAfterAdd) { $this->path = $path; $this->failAfterAdd = $failAfterAdd; }
            public function name() { return 'fixture'; }
            public function addTo($writer) {
                $this->writer = $writer;
                $writer->add('item.bin', $this->path);
                if ($this->failAfterAdd) throw new RuntimeException('fixture item failure');
            }
        };
    }

    private function manager($mode, $deny = false, $settings = array()) {
        require_once 'plugin/Archiver/ArchiveManager.class.php';
        $environment = new class($deny) {
            private $deny;
            public function __construct($deny) { $this->deny = $deny; }
            public function filesystem() { return $this; }
            public function assertRights($items, $permission, $action) {
                if ($this->deny) throw new ServiceException('FIXTURE_PERMISSION_DENIED');
            }
        };
        return new ArchiveManager($environment, $mode, $settings);
    }

    #[DataProvider('managers')]
    public function testManagerReturnsCallerOwnedArchive($mode): void {
        $item = $this->item();
        $path = $this->manager($mode)->compress(array($item));
        $this->archives[] = $path;
        $this->assertFileExists($path);
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($path, ZipArchive::CHECKCONS));
        $this->assertSame("manager\x00fixture", $reader->getFromName('item.bin'));
        $reader->close();
    }

    #[DataProvider('managers')]
    public function testManagerCopiesAtomicallyAndRemovesTemporaryArchive($mode): void {
        $item = $this->item();
        $target = $this->directory . '/destination.zip';
        file_put_contents($target, 'previous destination');
        $this->assertNull($this->manager($mode)->compress($item, $target));
        $this->assertFileDoesNotExist($item->writer->filename());
        $reader = new ZipArchive();
        $this->assertTrue($reader->open($target, ZipArchive::CHECKCONS));
        $this->assertSame("manager\x00fixture", $reader->getFromName('item.bin'));
        $reader->close();
        $this->assertSame(array(), glob($this->directory . '/Kloudspeaker-archive-copy-*'));
    }

    #[DataProvider('managers')]
    public function testManagerDestinationFailureRemovesTemporaryArchive($mode): void {
        $item = $this->item();
        $this->assertServiceFailure(fn() => $this->manager($mode)->compress($item, $this->directory . '/missing/destination.zip'));
        $this->assertFileDoesNotExist($item->writer->filename());
    }

    #[DataProvider('managers')]
    public function testManagerItemFailureCleansEvenRetainedWriterReferences($mode): void {
        $item = $this->item(true);
        try {
            $this->manager($mode)->compress($item);
            $this->fail('Item errors must propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('fixture item failure', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($item->writer->filename());
    }

    #[DataProvider('managers')]
    public function testManagerPermissionErrorsArePreserved($mode): void {
        $this->assertServiceFailure(fn() => $this->manager($mode, true)->compress($this->item()), 'FIXTURE_PERMISSION_DENIED');
    }

    public static function downloads(): array {
        return array(
            'native response closes' => array('ziparchive', true, false),
            'native service closes' => array('ziparchive', false, false),
            'native response fails' => array('ziparchive', false, true),
            'raw response closes' => array('raw', true, false),
            'raw service closes' => array('raw', false, false),
            'raw response fails' => array('raw', false, true),
            'command response closes' => array('native', true, false),
            'command service closes' => array('native', false, false),
            'command response fails' => array('native', false, true),
        );
    }

    #[DataProvider('downloads')]
    public function testDownloadHandoffClosesStreamsAndRemovesArchives($mode, $responseCloses, $responseFails): void {
        require_once 'include/ResponseHandler.class.php';
        require_once 'plugin/Archiver/ArchiverServices.class.php';
        require_once 'plugin/Archiver/ArchiveManager.class.php';
        $item = $this->item();
        $output = new class($responseCloses, $responseFails) {
            public $handle;
            public $bytes;
            public $filename;
            private $closes;
            private $fails;
            public function __construct($closes, $fails) { $this->closes = $closes; $this->fails = $fails; }
            public function downloadBinary($filename, $type, $mobile, $stream, $size, $range) {
                $this->handle = $stream;
                $this->filename = $filename;
                if ($this->fails) throw new RuntimeException('fixture response failure');
                $this->bytes = stream_get_contents($stream);
                if ($this->closes) fclose($stream);
            }
        };
        $environment = new class($item, $output) {
            public $manager;
            private $item;
            private $response;
            public function __construct($item, $output) { $this->item = $item; $this->response = new ResponseHandler($output); }
            public function filesystem() { return $this; }
            public function item($id) { return $this->item; }
            public function assertRights($items, $permission, $action) {}
            public function plugins() { return $this; }
            public function getPlugin($id) { return $this; }
            public function getArchiveManager() { return $this->manager; }
            public function response() { return $this->response; }
        };
        $environment->manager = new ArchiveManager($environment, $mode, array());
        $request = new Request(null, Request::METHOD_GET, 'archiver/download', 'local', array('download'), array('item' => 'fixture'), null);
        $service = new ArchiverServices($environment, $request, 'archiver', array('download'));
        try {
            $service->processGet();
            $this->assertFalse($responseFails, 'Response exceptions must propagate');
        } catch (RuntimeException $exception) {
            $this->assertTrue($responseFails);
            $this->assertSame('fixture response failure', $exception->getMessage());
        }
        $this->assertFalse(is_resource($output->handle));
        $this->assertFileDoesNotExist($item->writer->filename());
        if (!$responseFails) {
            $this->assertSame('fixture.zip', $output->filename);
            $path = $this->directory . '/download.zip';
            file_put_contents($path, $output->bytes);
            $reader = new ZipArchive();
            $this->assertTrue($reader->open($path, ZipArchive::CHECKCONS));
            $this->assertSame("manager\x00fixture", $reader->getFromName('item.bin'));
            $reader->close();
        }
    }

    public function testPluginSetupRegistersTheDeclaredManager(): void {
        require_once 'plugin/PluginBase.class.php';
        require_once 'plugin/Archiver/Archiver.plugin.class.php';
        $environment = new class {
            public $service;
            public $context;
            public function addService($path, $controller, $directory) { $this->service = array($path, $controller); }
            public function filesystem() { return $this; }
            public function registerItemContextPlugin($id, $plugin) { $this->context = array($id, $plugin); }
        };
        $plugin = new Archiver($environment, 'Archiver', array('compressor' => 'raw'));
        $plugin->setup();
        $this->assertInstanceOf(ArchiveManager::class, $plugin->getArchiveManager());
        $this->assertSame(array('archiver', 'ArchiverServices'), $environment->service);
        $this->assertSame(array('plugin-archiver', $plugin), $environment->context);
        $this->assertSame(array('actions' => array('compress' => true, 'download' => true, 'extract' => true)), $plugin->getSessionInfo());
    }

    public function testRawFallbackWorksWithZipExtensionDisabled(): void {
        $code = '$root = ' . var_export(dirname(__DIR__), true) . '; $directory = ' . var_export($this->directory, true) . ';' . <<<'PHP'
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
if (class_exists('ZipArchive')) throw new RuntimeException('ZIP extension must be disabled for this check');
require $root . '/plugin/Archiver/KloudspeakerCompressor.class.php';
require $root . '/plugin/Archiver/zip/KloudspeakerZipRaw.class.php';
$source = $directory . '/extension-free.bin';
$target = $directory . '/extension-free.zip';
file_put_contents($source, "fallback\x00bytes");
$writer = new KloudspeakerZipRaw(null);
try {
    $writer->add('fixture.bin', $source);
    $writer->finish();
    copy($writer->filename(), $target);
    $reader = new PharData($target);
    if ($reader['fixture.bin']->getContent() !== "fallback\x00bytes") throw new RuntimeException('Fallback bytes changed');
    unset($reader);
    echo 'PASS';
} finally {
    $writer->cleanup();
}
PHP;
        $process = proc_open(array(PHP_BINARY, '-n', '-r', $code), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('PASS', $output);
    }

    public function testZipDependencyMatchesPinnedUpstreamSources(): void {
        $vendorDirectory = dirname(__DIR__) . '/vendor/';
        $manifest = json_decode(file_get_contents($vendorDirectory . 'dependencies.json'), true, 512, JSON_THROW_ON_ERROR);
        $packages = array_column($manifest['packages'], null, 'name');
        $package = $packages['maennchen/zipstream-php'];
        $this->assertFalse($package['modified']);
        $this->assertSame('3.1.2', $package['source_version']);
        foreach ($package['runtime_files'] as $path => $checksum) {
            $this->assertSame($checksum, hash_file('sha256', $vendorDirectory . $path));
        }
        $this->assertSame($vendorDirectory . 'zipstream/src/ZipStream.php', (new ReflectionClass(\ZipStream\ZipStream::class))->getFileName());
    }
}
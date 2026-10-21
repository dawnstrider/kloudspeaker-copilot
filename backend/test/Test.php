<?php

require_once "TestCase.php";

class KloudspeakerTest extends Kloudspeaker_TestCase {
    public function testNoSession() {
        $this->assertEqualArrayValues(array("authenticated" => FALSE), $this->processRequest("GET", "session/info"));
    }
    
    public function testAuthenticate() {
    	try {
	        $this->processRequest("POST", "session/authenticate", array(), array("username" => "wrong", "password" => "wrong"));
	        $this->assertTrue(FALSE);	//should never get here
		} catch (ServiceException $e) {
			$this->assertEquals("AUTHENTICATION_FAILED", $e->type());
		}
    }

	public function testDatabaseChangesAreConfinedToTheCurrentTest(): void {
		$this->assertSame(0, (int) $this->getConnection()->query('SELECT COUNT(*) FROM user')->fetchColumn());
		$this->getConnection()->exec("INSERT INTO user (name, is_group) VALUES ('isolated-test-user', 0)");
		$this->assertSame(1, (int) $this->getConnection()->query('SELECT COUNT(*) FROM user')->fetchColumn());
	}

	public function testDatabaseFixtureStartsEmpty(): void {
		$this->assertSame(0, (int) $this->getConnection()->query('SELECT COUNT(*) FROM user')->fetchColumn());
	}

	public function testPasswordCreationAndVerificationUnderEAll(): void {
		$hasher = new Kloudspeaker_PasswordHash(new Settings(array('server_hash_salt' => 'fixture-server-salt')));
		foreach (array('', 'password', "caf\xc3\xa9-password", str_repeat('long-password-', 10)) as $password) {
			$created = $hasher->createHash($password, 'fixture-');
			$this->assertStringStartsWith('fixture-', $created['salt']);
			$this->assertTrue($hasher->isEqual($password, $created['hash'], $created['salt']));
			$this->assertFalse($hasher->isEqual('wrong-' . $password, $created['hash'], $created['salt']));
		}
	}

	public function testHistoricalPasswordHashFormatsRemainUsable(): void {
		$hasher = new Kloudspeaker_PasswordHash(new Settings(array('server_hash_salt' => 'fixture-server-salt')));
		$otherServer = new Kloudspeaker_PasswordHash(new Settings(array('server_hash_salt' => 'other-server-salt')));
		$hashes = array(
			'$P$B12345678ceEu5A5zz3adOPi1KUqIt/',
			'$H$B12345678ceEu5A5zz3adOPi1KUqIt/',
			'$2a$08$abcdefghijklmnopqrstuuBhImhcoey7PQbj8IFI/z3fRHcfca5UC',
			'_J9..abcdYMpTkp/b/4c',
		);
		foreach ($hashes as $hash) {
			$this->assertTrue($hasher->isEqual('historical-password', $hash, 'fixture-user-salt'));
			$this->assertFalse($hasher->isEqual('wrong-password', $hash, 'fixture-user-salt'));
			$this->assertFalse($hasher->isEqual('historical-password', $hash, 'wrong-user-salt'));
			$this->assertFalse($otherServer->isEqual('historical-password', $hash, 'fixture-user-salt'));
		}
	}

	public function testPasswordRandomSourceIgnoresLegacyDeviceFileAccess(): void {
		foreach (array('no_dev_urandom', 'no_udev_random') as $setting) {
			$hasher = new Kloudspeaker_PasswordHash(new Settings(array($setting => true)));
			$this->assertInstanceOf(Kloudspeaker_PasswordHashGenerator::class, $hasher->hasher);
			$this->assertSame(16, strlen($hasher->hasher->get_random_bytes(16)));
			$created = $hasher->createHash('fixture-password');
			$this->assertStringStartsWith('$2a$08$', $created['hash']);
			$this->assertTrue($hasher->isEqual('fixture-password', $created['hash'], $created['salt']));
		}
	}

	public function testPasswordCreationFailsClosedOnEntropyFailure(): void {
		$hasher = new Kloudspeaker_PasswordHash(new Settings(array()));
		$hasher->hasher = new class(8, false) extends PasswordHash {
			public function get_random_bytes($count) {
				throw new Random\RandomException('fixture entropy failure');
			}
		};
		try {
			$hasher->createHash('fixture-password');
			$this->fail('Entropy failures must not create password hashes');
		} catch (ServiceException $exception) {
			$this->assertSame('REQUEST_FAILED', $exception->type());
		}
	}

	public function testMalformedPasswordHashesAreRejectedWithoutDiagnostics(): void {
		$hasher = new Kloudspeaker_PasswordHash(new Settings(array()));
		foreach (array(null, array(), '', '*', '$P$', '$P$B1234') as $hash) {
			$this->assertFalse($hasher->isEqual('fixture-password', $hash, 'fixture-salt'));
		}
	}

	public function testPasswordDependencyMatchesPinnedUpstreamSource(): void {
		$vendorDirectory = dirname(__DIR__) . '/vendor/';
		$manifest = json_decode(file_get_contents($vendorDirectory . 'dependencies.json'), true, 512, JSON_THROW_ON_ERROR);
		$packages = array_column($manifest['packages'], null, 'name');
		$package = $packages['openwall/phpass'];
		$this->assertSame('openwall/phpass', $package['name']);
		$this->assertFalse($package['modified']);
		foreach ($package['runtime_files'] as $path => $checksum) {
			$this->assertSame($checksum, hash_file('sha256', $vendorDirectory . $path));
		}
		$this->assertSame($vendorDirectory . 'phpass/src/PasswordHash.php', (new ReflectionClass(PasswordHash::class))->getFileName());
	}

	public function testDefaultCharsetConversionPreservesLatin1Semantics(): void {
		$latin1 = "caf\xe9.txt";
		$utf8 = "caf\xc3\xa9.txt";
		$this->assertSame($utf8, Util::convertCharset($latin1));
		$this->assertSame($latin1, Util::convertCharset($utf8, null, false));
		$this->assertSame("\xc2\x80", Util::convertCharset("\x80"));
		$this->assertSame('?', Util::convertCharset("\xe2\x82\xac", null, false));
		$this->assertSame('?(', Util::convertCharset("\xc3\x28", null, false));
		$this->assertSame('', Util::convertCharset(null));
		$this->assertSame('', Util::convertCharset(null, null, false));
		$this->assertSame('42', Util::convertCharset(42));
	}

	public function testCharsetConversionRestoresTheSubstitutionSetting(): void {
		$original = mb_substitute_character();
		mb_substitute_character(0xfffd);
		try {
			$this->assertSame('?', Util::convertCharset("\xe2\x82\xac", null, false));
			$this->assertSame(0xfffd, mb_substitute_character());
		} finally {
			mb_substitute_character($original);
		}
	}

	public function testExplicitCharsetConversionAndNestedValues(): void {
		$this->assertSame("\xe2\x82\xac", Util::convertCharset("\x80", 'Windows-1252'));
		$this->assertSame("\x80", Util::convertCharset("\xe2\x82\xac", 'Windows-1252', false));
		$this->assertSame(array('nested' => array("caf\xc3\xa9.txt")), Util::convertArrayCharset(array('nested' => array("caf\xe9.txt"))));
	}

	public function testPdoAdapterSelectsAnExplicitExceptionMode(): void {
		$this->getConnection()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
		PDODatabase::createFromObj($this->getConnection(), 'sqlite');
		$this->assertSame(PDO::ERRMODE_EXCEPTION, $this->getConnection()->getAttribute(PDO::ATTR_ERRMODE));
	}

	public function testPdoQueryFailurePreservesTheServiceErrorAndRollbackContract(): void {
		$database = PDODatabase::createFromObj($this->getConnection(), 'sqlite');
		$database->startTransaction();
		$this->assertSame(1, $database->update("INSERT INTO user (name, is_group) VALUES ('rollback-test-user', 0)"));
		try {
			$database->query('SELECT * FROM missing_compatibility_table');
			$this->fail('Missing tables must produce a controlled service error');
		} catch (ServiceException $exception) {
			$this->assertSame('INVALID_CONFIGURATION', $exception->type());
			$this->assertTrue($database->isTransaction());
		} finally {
			$database->rollback();
		}
		$this->assertFalse($database->isTransaction());
		$this->assertSame(0, (int) $this->getConnection()->query('SELECT COUNT(*) FROM user')->fetchColumn());
	}

	public function testPdoPasswordGetterUsesTheConfiguredValue(): void {
		$database = new PDODatabase('sqlite::memory:', '', 'fixture-password', '');
		$this->assertSame('fixture-password', $database->password());
	}

	public function testImageRenderingPreservesFractionalAlphaTruncation(): void {
		require_once 'include/ImageGenerator.class.php';
		$generator = new ImageGenerator(null);
		$font = dirname(__DIR__) . '/plugin/Watermarking/markers/PDFMarker/Arial.ttf';
		$target = $generator->createText('PHP 8.2', array(221, 71), $font, 20, array(0, 0, 0), 50, 0);
		$image = null;
		try {
			$image = imagecreatefrompng($target);
			$this->assertInstanceOf(GdImage::class, $image);
			$this->assertSame(221, imagesx($image));
			$this->assertSame(71, imagesy($image));
			$minimumAlpha = 127;
			for ($pixelY = 0; $pixelY < imagesy($image); $pixelY++) {
				for ($pixelX = 0; $pixelX < imagesx($image); $pixelX++) {
					$alpha = (imagecolorat($image, $pixelX, $pixelY) >> 24) & 127;
					$minimumAlpha = min($minimumAlpha, $alpha);
				}
			}
			$this->assertSame(63, $minimumAlpha);
		} finally {
			if ($image instanceof GdImage) {
				imagedestroy($image);
			}
			if (is_file($target)) {
				unlink($target);
			}
		}
	}
}

?>
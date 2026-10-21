<?php
require_once "vendor/phpass/src/PasswordHash.php";
/**
 * PasswordHash.class.php
 *
 * Copyright 2015- Samuli Järvelä
 * Released under GPL License.
 *
 * License: http://www.kloudspeaker.com/license.php
 */

class Kloudspeaker_PasswordHash {
	private static $hash_cost_log2 = 8;
	private static $hash_portable = FALSE;

	private $serverSalt;
	public $hasher;

	public function __construct($settings) {
		$this->serverSalt = $settings->setting("server_hash_salt");
		$this->hasher = new Kloudspeaker_PasswordHashGenerator(self::$hash_cost_log2, self::$hash_portable);
	}

	public function createHash($pw, $saltPrefix = '') {
		$salt = uniqid($saltPrefix, TRUE);
		try {
			$hash = $this->hasher->HashPassword($this->serverSalt . $pw . $salt);
		} catch (Exception $e) {
			throw new ServiceException("REQUEST_FAILED");
		}
		if (strlen($hash) < 20) {
			throw new ServiceException("REQUEST_FAILED");
		}

		return array("salt" => $salt, "hash" => $hash);
	}

	public function isEqual($pw, $hash, $salt) {
		if (!is_string($hash) or strlen($hash) < 13) {
			return FALSE;
		}
		$ret = $this->hasher->CheckPassword($this->serverSalt . $pw . $salt, $hash);
		return ($ret === TRUE or $ret == 1);
	}
}

class Kloudspeaker_PasswordHashGenerator extends PasswordHash {
	public function get_random_bytes($count) {
		return random_bytes($count);
	}
}
?>
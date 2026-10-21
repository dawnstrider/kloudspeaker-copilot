<?php

/**
 * KloudspeakerCompressor.class.php
 *
 * Copyright 2015- Samuli Järvelä
 * Released under GPL License.
 *
 * License: http://www.kloudspeaker.com/license.php
 */

interface KloudspeakerCompressor {
	function acceptFolders();

	function add($name, $path, $size = 0);

	function addEmptyDir($name);

	function finish();

	function stream();

	function filename();
}

abstract class KloudspeakerZipCompressor implements KloudspeakerCompressor {
	protected $env;
	protected $name;
	protected $finished = FALSE;
	protected $failed = FALSE;
	private $entries = array();

	public function __construct($env) {
		$this->env = $env;
		$this->name = tempnam(sys_get_temp_dir(), 'Kloudspeaker-archive-');
		if ($this->name === FALSE) throw new ServiceException("REQUEST_FAILED", "Could not create temporary archive");
	}

	protected function ensureWritable() {
		if ($this->finished or $this->failed) throw new ServiceException("REQUEST_FAILED", "Archive is not writable");
	}

	protected function entryName($name) {
		$this->ensureWritable();
		if (!is_string($name) or $name === '' or strpos($name, "\0") !== FALSE) $this->fail("Invalid archive entry name", "INVALID_REQUEST");
		$name = str_replace('\\', '/', $name);
		if ($name[0] === '/' or preg_match('/^[a-z]:/i', $name)) $this->fail("Archive entries must use relative paths", "INVALID_REQUEST");
		$parts = array();
		foreach (explode('/', $name) as $part) {
			if ($part === '.' or $part === '..') $this->fail("Archive entries must not traverse directories", "INVALID_REQUEST");
			if ($part !== '') $parts[] = $part;
		}
		if (!$parts) $this->fail("Invalid archive entry name", "INVALID_REQUEST");
		return implode('/', $parts);
	}

	protected function reserveName($name) {
		$name = $this->entryName($name);
		if (isset($this->entries[$name])) $this->fail("Duplicate archive entry name", "INVALID_REQUEST");
		$this->entries[$name] = TRUE;
		return $name;
	}

	protected function fail($message, $type = "REQUEST_FAILED") {
		$this->cleanup();
		throw new ServiceException($type, $message);
	}

	public function stream() {
		if (!$this->finished or !is_file($this->name)) throw new ServiceException("REQUEST_FAILED", "Archive is not ready for reading");
		try {
			$handle = @fopen($this->name, 'rb');
		} catch (Exception $exception) {
			throw new ServiceException("REQUEST_FAILED", "Could not open archive for reading");
		}
		if ($handle === FALSE) throw new ServiceException("REQUEST_FAILED", "Could not open archive for reading");
		return $handle;
	}

	public function filename() {
		return $this->name;
	}

	protected function abortWriter() {}

	public function cleanup() {
		$this->failed = TRUE;
		$this->finished = FALSE;
		try {
			$this->abortWriter();
		} catch (Throwable $exception) {
		} finally {
			if (is_string($this->name) and is_file($this->name)) unlink($this->name);
		}
	}

	public function __destruct() {
		if (!$this->finished) {
			try {
				$this->cleanup();
			} catch (Throwable $exception) {
			}
		}
	}
}
?>
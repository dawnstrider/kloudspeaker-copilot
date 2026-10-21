<?php

/**
 * KloudspeakerZipArchive.class.php
 *
 * Copyright 2015- Samuli Järvelä
 * Released under GPL License.
 *
 * License: http://www.kloudspeaker.com/license.php
 */

class KloudspeakerZipArchive extends KloudspeakerZipCompressor {
	private $zip = NULL;

	function __construct($env) {
		if (!class_exists('ZipArchive')) {
			throw new ServiceException("INVALID_CONFIGURATION", "ZipArchive lib not installed");
		}

		parent::__construct($env);
		$this->zip = new ZipArchive();
		if ($this->zip->open($this->name, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
			$this->fail("Could not create archive");
		}
	}

	public function acceptFolders() {
		return FALSE;
	}

	public function add($name, $path, $size = 0) {
		$this->ensureWritable();
		if (!is_string($path) or !is_file($path) or !is_readable($path)) {
			$this->fail("Archive source is not readable");
		}
		$name = $this->reserveName($name);
		try {
			$added = @$this->zip->addFile($path, $name);
		} catch (Exception $exception) {
			$this->fail("Could not add file to archive");
		}
		if (!$added) $this->fail("Could not add file to archive");
	}

	public function addEmptyDir($name) {
		$name = $this->reserveName($name);
		if (!$this->zip->addEmptyDir($name)) $this->fail("Could not add directory to archive");
	}

	public function finish() {
		if ($this->finished) return;
		$this->ensureWritable();
		try {
			$empty = $this->zip->numFiles === 0;
			if (!@$this->zip->close()) $this->fail("Could not finalize archive");
			$this->zip = NULL;
			if ($empty) {
				require_once __DIR__ . '/KloudspeakerZipRaw.class.php';
				$fallback = new KloudspeakerZipRaw($this->env);
				try {
					$fallback->finish();
					if (!copy($fallback->filename(), $this->name)) $this->fail("Could not create empty archive");
				} finally {
					$fallback->cleanup();
				}
			}
			if (!is_file($this->name)) $this->fail("Archive output is missing");
			$this->finished = TRUE;
		} catch (ServiceException $exception) {
			$this->cleanup();
			throw $exception;
		} catch (Exception $exception) {
			$this->fail("Could not finalize archive");
		}
	}

	protected function abortWriter() {
		if ($this->zip !== NULL) {
			$zip = $this->zip;
			$this->zip = NULL;
			@$zip->unchangeAll();
			@$zip->close();
		}
	}
}
?>
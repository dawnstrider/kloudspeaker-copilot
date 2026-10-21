<?php

/**
 * KloudspeakerZipRaw.class.php
 *
 * Copyright 2015- Samuli Järvelä
 * Released under GPL License.
 *
 * License: http://www.kloudspeaker.com/license.php
 */

spl_autoload_register(function ($class) {
	$prefix = 'ZipStream\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
	$file = dirname(__DIR__, 3) . '/vendor/zipstream/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) require_once $file;
});

class KloudspeakerZipRaw extends KloudspeakerZipCompressor {
	private $zip = NULL;
	private $output = NULL;

	function __construct($env) {
		if (PHP_INT_SIZE !== 8 or !extension_loaded('mbstring') or !extension_loaded('zlib')) {
			throw new ServiceException("INVALID_CONFIGURATION", "Raw ZIP creation requires 64-bit PHP, mbstring and zlib");
		}
		parent::__construct($env);
		$this->output = fopen($this->name, 'w+b');
		if ($this->output === FALSE) $this->fail("Could not open archive output");
		$this->zip = new \ZipStream\ZipStream(outputStream: $this->output, sendHttpHeaders: FALSE, defaultEnableZeroHeader: FALSE);
	}

	public function acceptFolders() {
		return false;
	}

	public function add($name, $path, $size = 0) {
		$this->ensureWritable();
		if (!is_string($path) or (!is_file($path) and !is_dir($path)) or !is_readable($path)) {
			$this->fail("Archive source is not readable");
		}
		if (is_link($path)) $this->fail("Archive source must not be a symbolic link");
		$name = $this->entryName($name);
		try {
			if (is_file($path)) {
				$this->zip->addFileFromPath($this->reserveName($name), $path);
			} else {
				$this->addEmptyDir($name);
				foreach (scandir($path) as $entry) {
					if (in_array($entry, array('.', '..', '.DS_Store', '_notes', 'Thumbs.db'), TRUE)) continue;
					$this->add($name . '/' . $entry, $path . DIRECTORY_SEPARATOR . $entry);
				}
			}
		} catch (ServiceException $exception) {
			throw $exception;
		} catch (Exception $exception) {
			$this->fail("Could not add file to archive");
		}
	}

	public function addEmptyDir($name) {
		$name = $this->reserveName($name);
		try {
			$this->zip->addDirectory($name);
		} catch (Exception $exception) {
			$this->fail("Could not add directory to archive");
		}
	}

	public function finish() {
		if ($this->finished) return;
		$this->ensureWritable();
		try {
			$size = $this->zip->finish();
			if (!fflush($this->output) or ftell($this->output) !== $size) $this->fail("Archive output is incomplete");
			fclose($this->output);
			$this->output = NULL;
			$this->zip = NULL;
			$this->finished = TRUE;
		} catch (Exception $exception) {
			$this->fail("Could not finalize archive");
		}
	}

	protected function abortWriter() {
		$this->zip = NULL;
		if (is_resource($this->output)) fclose($this->output);
		$this->output = NULL;
	}
}
?>
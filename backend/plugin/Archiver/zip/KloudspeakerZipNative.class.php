<?php

/**
 * KloudspeakerZipNative.class.php
 *
 * Copyright 2015- Samuli Järvelä
 * Released under GPL License.
 *
 * License: http://www.kloudspeaker.com/license.php
 */

class KloudspeakerZipNative extends KloudspeakerZipCompressor {
	private $cmd;
	private $files = array();
	private $workspace = NULL;

	private static function command() {
		$extensions = PHP_OS_FAMILY === 'Windows' ? array('.exe', '.com', '') : array('');
		foreach (array('zip', '7z') as $name) {
			foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
				if ($directory === '') continue;
				foreach ($extensions as $extension) {
					$file = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name . $extension;
					if (is_file($file) and is_executable($file)) return array('path' => $file, 'name' => $name);
				}
			}
		}
		return NULL;
	}

	public function __construct($env) {
		$this->cmd = self::command();
		if ($this->cmd === NULL or !function_exists('proc_open')) throw new ServiceException("INVALID_CONFIGURATION", "Command ZIP creation requires zip or 7z and proc_open");
		parent::__construct($env);
	}

	public function acceptFolders() {
		return TRUE;
	}

	private function prepareWorkspace() {
		if ($this->workspace !== NULL) return;
		$this->workspace = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Kloudspeaker-zip-work-' . bin2hex(random_bytes(16));
		if (!mkdir($this->workspace, 0700) or !mkdir($this->workspace . '/input', 0700)) $this->fail("Could not create private archive workspace");
	}

	private function stage($name, $path, $schedule) {
		$this->ensureWritable();
		if (!is_string($path) or (!is_file($path) and !is_dir($path)) or !is_readable($path) or is_link($path)) $this->fail("Archive source is not readable or is a symbolic link");
		$name = $this->reserveName($name);
		$this->prepareWorkspace();
		$target = $this->workspace . '/input/' . $name;
		if (is_dir($path)) {
			if (!is_dir($target) and !mkdir($target, 0700, TRUE)) $this->fail("Could not stage archive directory");
			if ($schedule) $this->files[] = './' . $name . '/';
			foreach (scandir($path) as $entry) {
				if ($entry === '.' or $entry === '..') continue;
				$this->stage($name . '/' . $entry, $path . DIRECTORY_SEPARATOR . $entry, FALSE);
			}
		} else {
			if (!is_dir(dirname($target)) and !mkdir(dirname($target), 0700, TRUE)) $this->fail("Could not stage archive directory");
			if (!copy($path, $target)) $this->fail("Could not stage archive file");
			if ($schedule) $this->files[] = './' . $name;
		}
	}

	public function add($name, $path, $size = 0) {
		try {
			$this->stage($name, $path, TRUE);
		} catch (ServiceException $exception) {
			throw $exception;
		} catch (Exception $exception) {
			$this->fail("Could not stage archive source");
		}
	}

	public function addEmptyDir($name) {
		$name = $this->reserveName($name);
		$this->prepareWorkspace();
		$target = $this->workspace . '/input/' . $name;
		if (!is_dir($target) and !mkdir($target, 0700, TRUE)) $this->fail("Could not stage empty archive directory");
		$this->files[] = './' . $name . '/';
	}

	protected function runCommand($arguments, $directory) {
		$descriptors = array(0 => array('pipe', 'r'), 1 => array('file', $this->workspace . '/stdout.log', 'ab'), 2 => array('file', $this->workspace . '/stderr.log', 'ab'));
		$process = proc_open($arguments, $descriptors, $pipes, $directory, NULL, array('bypass_shell' => TRUE));
		if (!is_resource($process)) return -1;
		fclose($pipes[0]);
		return proc_close($process);
	}

	public function finish() {
		if ($this->finished) return TRUE;
		$this->ensureWritable();
		try {
			if (!$this->files) {
				require_once __DIR__ . '/KloudspeakerZipRaw.class.php';
				$empty = new KloudspeakerZipRaw($this->env);
				try {
					$empty->finish();
					if (!copy($empty->filename(), $this->name)) $this->fail("Could not create empty archive");
				} finally {
					$empty->cleanup();
				}
			} else {
				$output = $this->workspace . '/output.zip';
				$arguments = $this->cmd['name'] === 'zip'
					? array($this->cmd['path'], '-q', '-r', $output, '--')
					: array($this->cmd['path'], 'a', '-tzip', '-bso0', '-bsp0', $output, '--');
				$batches = $this->cmd['name'] === 'zip' ? array_chunk($this->files, 100) : array(array('.'));
				foreach ($batches as $batch) {
					if ($this->runCommand(array_merge($arguments, $batch), $this->workspace . '/input') !== 0) $this->fail("External ZIP command failed");
				}
				if (!is_file($output) or !chmod($output, 0600) or !rename($output, $this->name)) $this->fail("Could not finalize command archive");
				$this->abortWriter();
			}
			$this->finished = TRUE;
			return TRUE;
		} catch (ServiceException $exception) {
			$this->cleanup();
			throw $exception;
		} catch (Exception $exception) {
			$this->fail("External ZIP command failed");
		}
	}

	protected function abortWriter() {
		if ($this->workspace === NULL or !is_dir($this->workspace)) return;
		$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->workspace, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($entries as $entry) {
			if ($entry->isDir() and !$entry->isLink()) rmdir($entry->getPathname());
			else unlink($entry->getPathname());
		}
		rmdir($this->workspace);
		$this->workspace = NULL;
	}

	public function common_path($dirList) {
		if (!is_array($dirList) or !$dirList) return NULL;
		$dirList = array_values(array_unique($dirList));
		while (count($dirList) !== 1) {
			$parents = array_values(array_unique(array_map('dirname', $dirList)));
			if ($parents === $dirList) return NULL;
			$dirList = $parents;
		}
		return $dirList[0];
	}

	public function relative_path($from, $to) {
		$from = explode(DIRECTORY_SEPARATOR, $from);
		$to = explode(DIRECTORY_SEPARATOR, $to);
		foreach ($from as $depth => $directory) {
			if (!isset($to[$depth]) or $directory !== $to[$depth]) break;
			unset($to[$depth], $from[$depth]);
		}
		for ($depth = 0; $depth < count($from) - 1; $depth++) array_unshift($to, '..');
		return implode(DIRECTORY_SEPARATOR, $to);
	}
}
?>
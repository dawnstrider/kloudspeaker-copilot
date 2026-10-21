<?php

/**
 * ArchiveManager.class.php
 *
 * Copyright 2015- Samuli Järvelä
 * Released under GPL License.
 *
 * License: http://www.kloudspeaker.com/license.php
 */

class ArchiveManager {
	private $env;
	private $compressor;
	private $settings;

	function __construct($env, $compressor, $settings) {
		$this->env = $env;
		$this->compressor = $compressor;
		$this->settings = $settings;
	}

	/*public function storeArchive($items) {
	$id = uniqid();
	$zip = $this->createArchive($items);
	$this->env->session()->param("archive_".$id, $zip->filename());
	return $id;
	}*/

	public function getActions() {
		return array("compress", "download", "extract");
	}

	public function isActionEnabled($action) {
		if (!in_array($action, $this->getActions())) {
			return FALSE;
		}

		return $this->getSetting("enable_" . $action, TRUE);
	}

	private function createArchive($items) {
		$c = $this->getCompressor();
		try {
			if (is_array($items)) {
				$this->env->filesystem()->assertRights($items, FilesystemController::PERMISSION_LEVEL_READ, "add to package");
				foreach ($items as $item) {
					$item->addTo($c);
				}
			} else {
				$item = $items;
				$this->env->filesystem()->assertRights($item, FilesystemController::PERMISSION_LEVEL_READ, "add to package");
				$item->addTo($c);
			}
			if ($c->finish() === FALSE) throw new ServiceException("REQUEST_FAILED", "Could not finalize archive");
			return $c;
		} catch (Throwable $exception) {
			$this->cleanupArchive($c);
			throw $exception;
		}
	}

	private function cleanupArchive($archive) {
		if (method_exists($archive, 'cleanup')) $archive->cleanup();
		else if (is_file($archive->filename())) unlink($archive->filename());
	}

	public function extract($archive, $to, $replace = FALSE) {
		if (!class_exists('ZipArchive')) throw new ServiceException("INVALID_CONFIGURATION", "ZIP extraction requires ZipArchive");
		if (!is_string($archive) or !is_file($archive) or !is_readable($archive) or !is_string($to) or $to === '') {
			throw new ServiceException("INVALID_REQUEST", "Extraction requires a readable ZIP and destination directory");
		}
		$to = rtrim($to, '/\\');
		if ($to === '' or is_link($to) or (file_exists($to) and !is_dir($to)) or !is_dir(dirname($to)) or !is_writable(dirname($to))) {
			throw new ServiceException("INVALID_REQUEST", "Invalid extraction destination");
		}
		if ($replace and is_dir($to) and strpos(realpath($archive), realpath($to) . DIRECTORY_SEPARATOR) === 0) {
			throw new ServiceException("INVALID_REQUEST", "Extraction must not replace its source archive");
		}
		$zip = new ZipArchive();
		$opened = FALSE;
		$workspace = NULL;
		$backup = NULL;
		$published = FALSE;
		try {
			if (@$zip->open($archive, ZipArchive::CHECKCONS) !== TRUE) throw new ServiceException("REQUEST_FAILED", "Could not open ZIP archive");
			$opened = TRUE;
			$entryLimit = $this->getSetting('max_extract_entries', 10000);
			$byteLimit = $this->getSetting('max_extract_bytes', 1073741824);
			if (!is_int($entryLimit) or !is_int($byteLimit) or $entryLimit < 1 or $byteLimit < 1) throw new ServiceException("INVALID_CONFIGURATION", "Extraction limits must be positive integers");
			if ($zip->numFiles > $entryLimit) throw new ServiceException("INVALID_REQUEST", "ZIP exceeds the extraction entry limit");
			$entries = array();
			$total = 0;
			for ($index = 0; $index < $zip->numFiles; $index++) {
				$stat = $zip->statIndex($index);
				if ($stat === FALSE) throw new ServiceException("REQUEST_FAILED", "Could not inspect ZIP entry");
				if (!is_int($stat['size']) or $stat['size'] < 0) throw new ServiceException("INVALID_REQUEST", "Invalid ZIP entry size");
				$name = $this->extractionName($stat['name']);
				$key = PHP_OS_FAMILY === 'Windows' ? strtolower($name) : $name;
				if (isset($entries[$key])) throw new ServiceException("INVALID_REQUEST", "Duplicate ZIP entry name");
				$isDirectory = substr(str_replace('\\', '/', $stat['name']), -1) === '/';
				if (!$zip->getExternalAttributesIndex($index, $system, $attributes)) throw new ServiceException("REQUEST_FAILED", "Could not inspect ZIP entry attributes");
				if ($system === ZipArchive::OPSYS_UNIX) {
					$type = ($attributes >> 16) & 0170000;
					if ($type !== 0 and $type !== 0100000 and $type !== 0040000) throw new ServiceException("INVALID_REQUEST", "ZIP links and special entries are not supported");
					if (($type === 0040000 and !$isDirectory) or ($type === 0100000 and $isDirectory)) throw new ServiceException("INVALID_REQUEST", "ZIP entry type does not match its name");
				}
				if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) throw new ServiceException("INVALID_REQUEST", "Encrypted ZIP entries are not supported");
				$total += $stat['size'];
				if ($total > $byteLimit) throw new ServiceException("INVALID_REQUEST", "ZIP exceeds the extraction byte limit");
				$entries[$key] = array('name' => $name, 'source' => $stat['name'], 'directory' => $isDirectory, 'size' => $stat['size'], 'crc' => $stat['crc']);
			}
			foreach ($entries as $entry) {
				$parent = dirname($entry['name']);
				while ($parent !== '.' and $parent !== '') {
					$key = PHP_OS_FAMILY === 'Windows' ? strtolower($parent) : $parent;
					if (isset($entries[$key]) and !$entries[$key]['directory']) throw new ServiceException("INVALID_REQUEST", "ZIP file and directory names conflict");
					$parent = dirname($parent);
				}
			}
			$workspace = dirname($to) . DIRECTORY_SEPARATOR . '.Kloudspeaker-extract-' . bin2hex(random_bytes(16));
			if (!mkdir($workspace, 0700) or !mkdir($workspace . '/new', 0700)) throw new ServiceException("REQUEST_FAILED", "Could not create extraction workspace");
			if (!$replace and is_dir($to)) $this->copyExtractionDirectory($to, $workspace . '/new');
			$written = 0;
			foreach ($entries as $entry) {
				$destination = $workspace . '/new/' . $entry['name'];
				if ($entry['directory']) {
					$this->createExtractionDirectory($destination);
					continue;
				}
				if (is_dir($destination)) throw new ServiceException("REQUEST_FAILED", "Extraction file conflicts with an existing directory");
				$this->createExtractionDirectory(dirname($destination));
				$input = $zip->getStream($entry['source']);
				if ($input === FALSE) throw new ServiceException("REQUEST_FAILED", "Could not read ZIP entry");
				$output = NULL;
				try {
					$output = fopen($destination, 'wb');
					if ($output === FALSE) throw new ServiceException("REQUEST_FAILED", "Could not write extracted entry");
					$checksum = hash_init('crc32b');
					$size = 0;
					while (!feof($input)) {
						$chunk = fread($input, 65536);
						if ($chunk === FALSE or ($chunk === '' and !feof($input))) throw new ServiceException("REQUEST_FAILED", "Could not read ZIP entry");
						$size += strlen($chunk);
						$written += strlen($chunk);
						if ($size > $entry['size'] or $written > $byteLimit) throw new ServiceException("INVALID_REQUEST", "ZIP entry exceeds extraction limits");
						hash_update($checksum, $chunk);
						if (fwrite($output, $chunk) !== strlen($chunk)) throw new ServiceException("REQUEST_FAILED", "Incomplete extracted entry write");
					}
					if ($size !== $entry['size'] or hash_final($checksum) !== sprintf('%08x', $entry['crc'])) throw new ServiceException("REQUEST_FAILED", "ZIP entry checksum or size mismatch");
				} finally {
					fclose($input);
					if (is_resource($output)) fclose($output);
				}
			}
			$zip->close();
			$opened = FALSE;
			$mode = is_dir($to) ? (fileperms($to) & 0777) : 0755;
			if (!chmod($workspace . '/new', $mode)) throw new ServiceException("REQUEST_FAILED", "Could not set extraction directory permissions");
			if (is_dir($to)) {
				$backup = $workspace . '/previous';
				if (!rename($to, $backup)) throw new ServiceException("REQUEST_FAILED", "Could not preserve extraction destination");
			}
			if (!rename($workspace . '/new', $to)) throw new ServiceException("REQUEST_FAILED", "Could not publish extracted files");
			$published = TRUE;
		} catch (ServiceException $exception) {
			throw $exception;
		} catch (Exception $exception) {
			throw new ServiceException("REQUEST_FAILED", "Could not extract ZIP archive");
		} finally {
			if ($opened) $zip->close();
			$retain = FALSE;
			if ($backup !== NULL and !$published and is_dir($backup)) {
				if (file_exists($to) or !rename($backup, $to)) $retain = TRUE;
			}
			if ($workspace !== NULL and !$retain and is_dir($workspace)) $this->removeExtractionDirectory($workspace);
		}
	}

	private function extractionName($name) {
		if (!is_string($name) or $name === '' or strpos($name, "\0") !== FALSE) throw new ServiceException("INVALID_REQUEST", "Invalid ZIP entry name");
		$name = str_replace('\\', '/', $name);
		if ($name[0] === '/' or strpos($name, ':') !== FALSE) throw new ServiceException("INVALID_REQUEST", "ZIP entry must be relative");
		$parts = array();
		foreach (explode('/', $name) as $part) {
			if ($part === '.' or $part === '..') throw new ServiceException("INVALID_REQUEST", "ZIP entry must not traverse directories");
			if ($part !== '') $parts[] = $part;
		}
		if (!$parts) throw new ServiceException("INVALID_REQUEST", "Invalid ZIP entry name");
		return implode('/', $parts);
	}

	private function createExtractionDirectory($directory) {
		if (is_dir($directory)) return;
		if (file_exists($directory) or is_link($directory) or dirname($directory) === $directory) throw new ServiceException("REQUEST_FAILED", "Extraction directory conflicts with an existing file");
		$this->createExtractionDirectory(dirname($directory));
		if (!mkdir($directory, 0755)) throw new ServiceException("REQUEST_FAILED", "Could not create extracted directory");
	}

	private function copyExtractionDirectory($source, $destination) {
		foreach (new DirectoryIterator($source) as $entry) {
			if ($entry->isDot()) continue;
			if ($entry->isLink()) throw new ServiceException("INVALID_REQUEST", "Extraction destination must not contain symbolic links");
			$target = $destination . DIRECTORY_SEPARATOR . $entry->getFilename();
			if ($entry->isDir()) {
				if (!mkdir($target, 0755)) throw new ServiceException("REQUEST_FAILED", "Could not stage existing directory");
				$this->copyExtractionDirectory($entry->getPathname(), $target);
			} else if (!$entry->isFile() or !copy($entry->getPathname(), $target)) {
				throw new ServiceException("REQUEST_FAILED", "Could not stage existing file");
			}
			if (!chmod($target, $entry->getPerms() & 0777)) throw new ServiceException("REQUEST_FAILED", "Could not preserve existing extraction permissions");
		}
	}

	private function removeExtractionDirectory($directory) {
		$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($entries as $entry) {
			if ($entry->isDir() and !$entry->isLink()) rmdir($entry->getPathname());
			else unlink($entry->getPathname());
		}
		rmdir($directory);
	}

	public function compress($items, $to = NULL) {
		$a = $this->createArchive($items);
		if ($to === NULL) return $a->filename();
		$temporary = NULL;
		try {
			if (!is_string($to) or $to === '' or is_dir($to) or !is_dir(dirname($to)) or !is_writable(dirname($to))) {
				throw new ServiceException("REQUEST_FAILED", "Archive destination is not writable");
			}
			$temporary = tempnam(dirname($to), 'Kloudspeaker-archive-copy-');
			if ($temporary === FALSE or !copy($a->filename(), $temporary) or !rename($temporary, $to)) {
				throw new ServiceException("REQUEST_FAILED", "Could not save archive");
			}
		} catch (ServiceException $exception) {
			throw $exception;
		} catch (Exception $exception) {
			throw new ServiceException("REQUEST_FAILED", "Could not save archive");
		} finally {
			if (is_string($temporary) and is_file($temporary)) unlink($temporary);
			$this->cleanupArchive($a);
		}
	}

	private function getCompressor() {
		require_once 'KloudspeakerCompressor.class.php';

		if ($this->compressor == NULL || strcasecmp($this->compressor, "ziparchive") === 0) {
			require_once 'zip/KloudspeakerZipArchive.class.php';
			return new KloudspeakerZipArchive($this->env);
		} else if (strcasecmp($this->compressor, "native") === 0) {
			require_once 'zip/KloudspeakerZipNative.class.php';
			return new KloudspeakerZipNative($this->env);
		} else if (strcasecmp($this->compressor, "raw") === 0) {
			require_once 'zip/KloudspeakerZipRaw.class.php';
			return new KloudspeakerZipRaw($this->env);
		}

		throw new ServiceException("INVALID_CONFIGURATION", "Unsupported compressor configured: " . $this->compressor);
	}

	public function getSetting($name, $default = NULL) {
		if (!$this->settings or !isset($this->settings[$name])) {
			return $default;
		}

		return $this->settings[$name];
	}

	public function __toString() {
		return "ArchiverManager";
	}
}
?>
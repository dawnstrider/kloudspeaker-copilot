<?php

/**
 * Builds a deterministic release archive: sorted entries, fixed timestamps and permissions, so building the same
 * tree twice yields byte-identical output. Writes kloudspeaker-<version>.zip and its SHA256SUMS manifest.
 *
 * Usage: php tools/build-release.php <output-directory>
 * Excluded: VCS/CI metadata, tests and test tooling, the local backend/configuration.php (it holds site
 * secrets; backend/example/ ships the templates), the migration working notes and editor files.
 */

$root = dirname(__DIR__);
$out = $argv[1] ?? NULL;
if ($out === NULL || !is_dir($out) || !is_writable($out)) {
	fwrite(STDERR, "Usage: php tools/build-release.php <writable output directory>\n");
	exit(2);
}

$excludedPrefixes = array('.git/', '.github/', 'tools/', 'backend/test/', 'backend/configuration.php', 'dist/', 'PHP82-MIGRATION.md');
$excludedNames = array('.gitignore', '.gitattributes', 'Thumbs.db', '.DS_Store');
$skipVendorDev = array('/test/', '/tests/', '/.github/', '/.git/');

include $root . '/backend/include/Version.info.php';
if (!isset($VERSION)) {
	fwrite(STDERR, "Could not read the application version\n");
	exit(2);
}

$files = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS));
foreach ($iterator as $file) {
	if (!$file->isFile() || $file->isLink()) {
		continue;
	}
	$relative = substr($file->getPathname(), strlen($root) + 1);
	if (in_array($file->getFilename(), $excludedNames, TRUE)) {
		continue;
	}
	foreach ($excludedPrefixes as $prefix) {
		if (strpos($relative, $prefix) === 0) {
			continue 2;
		}
	}
	if (strpos($relative, 'backend/vendor/') === 0) {
		foreach ($skipVendorDev as $fragment) {
			if (strpos('/' . $relative, $fragment) !== FALSE) {
				continue 2;
			}
		}
	}
	$files[] = $relative;
}
sort($files, SORT_STRING);
if (count($files) === 0) {
	fwrite(STDERR, "Nothing to package\n");
	exit(1);
}

$name = 'kloudspeaker-' . $VERSION . '.zip';
$target = rtrim($out, '/') . '/' . $name;
$temporary = $target . '.tmp';
if (is_file($temporary)) {
	unlink($temporary);
}

$zip = new ZipArchive();
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== TRUE) {
	fwrite(STDERR, "Could not create the archive\n");
	exit(1);
}
$fixedTime = gmmktime(0, 0, 0, 1, 1, 2020);
foreach ($files as $relative) {
	$entry = 'kloudspeaker/' . $relative;
	$zip->addFile($root . '/' . $relative, $entry);
	$zip->setMtimeName($entry, $fixedTime);
	$zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, 0100644 << 16);
	$zip->setCompressionName($entry, ZipArchive::CM_DEFLATE, 9);
}
if (!$zip->close()) {
	fwrite(STDERR, "Could not finish the archive\n");
	exit(1);
}
rename($temporary, $target);

$manifest = '';
foreach ($files as $relative) {
	$manifest .= hash_file('sha256', $root . '/' . $relative) . '  kloudspeaker/' . $relative . "\n";
}
file_put_contents(rtrim($out, '/') . '/SHA256SUMS', $manifest . hash_file('sha256', $target) . '  ' . $name . "\n");

echo $name . ' ' . count($files) . " files sha256=" . hash_file('sha256', $target) . "\n";

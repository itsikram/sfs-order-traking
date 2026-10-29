<?php
/**
 * Builds sfs-order-tracking-<version>.zip (plugin files only: no tests, bin or git files) into the project's dist/.
 * Usage: php bin/build-zip.php
 *
 * @package sfs-order-tracking
 */

if ('cli' !== PHP_SAPI) {
	exit;
}

$root = dirname(__DIR__);
preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents($root . '/sfs-order-tracking.php'), $match);
$version = $match[1] ?? '0.0.0';
$dist = dirname($root, 3) . '/dist';
if (!is_dir($dist)) {
	mkdir($dist, 0775, true);
}
$target = $dist . '/sfs-order-tracking-' . $version . '.zip';
@unlink($target);

$zip = new ZipArchive();
if (true !== $zip->open($target, ZipArchive::CREATE)) {
	fwrite(STDERR, "Cannot write {$target}\n");
	exit(1);
}
$count = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
	$relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
	if (preg_match('#^(\.git|tests|bin)(/|$)|^\.git(ignore|attributes)$#', $relative)) {
		continue;
	}
	$zip->addFile($file->getPathname(), 'sfs-order-tracking/' . $relative);
	$count++;
}
$zip->close();
echo "{$target}: {$count} files\n";

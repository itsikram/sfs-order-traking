<?php
/**
 * Test runner: php tests/run.php [unit|integration|all] [name filter]
 *
 * Unit tests cover the pure classes in src/Core without WordPress. Integration tests load the local WordPress
 * (wp-load.php), create clearly marked test orders, capture mail instead of sending it and delete everything they
 * created before exiting. Any PHP notice, warning or deprecation raised by this plugin's code fails the test.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

if ('cli' !== PHP_SAPI) {
	http_response_code(404);
	exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
// Only this plugin's own code must be warning-free; WordPress core and the theme are not under test here.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
	if (0 === strpos(str_replace('\\', '/', $file), str_replace('\\', '/', dirname(__DIR__)))) {
		throw new ErrorException($message, 0, $severity, $file, $line);
	}
	$GLOBALS['sfs_tests_foreign_notices'] = ($GLOBALS['sfs_tests_foreign_notices'] ?? 0) + 1;
	return true;
});

$sfs_tests_suite = $argv[1] ?? 'unit';
$sfs_tests_filter = $argv[2] ?? '';

require dirname(__DIR__) . '/src/autoload.php';
require __DIR__ . '/lib/TestCase.php';

$sfs_tests_files = array();
if ('unit' === $sfs_tests_suite || 'all' === $sfs_tests_suite) {
	$sfs_tests_files = array_merge($sfs_tests_files, glob(__DIR__ . '/unit/*Test.php') ?: array());
}
if ('integration' === $sfs_tests_suite || 'all' === $sfs_tests_suite) {
	require __DIR__ . '/lib/wordpress.php';
	$sfs_tests_files = array_merge($sfs_tests_files, glob(__DIR__ . '/integration/*Test.php') ?: array());
}

$sfs_tests_before = get_declared_classes();
foreach ($sfs_tests_files as $sfs_tests_file) {
	require $sfs_tests_file;
}
$sfs_tests_classes = array_filter(array_diff(get_declared_classes(), $sfs_tests_before), static function ($class) {
	return is_subclass_of($class, 'SFS\\OrderTracking\\Tests\\TestCase') && !(new ReflectionClass($class))->isAbstract();
});

$sfs_tests_passed = 0;
$sfs_tests_failed = array();
$sfs_tests_assertions = 0;
$sfs_tests_started = microtime(true);
foreach ($sfs_tests_classes as $sfs_tests_class) {
	foreach (get_class_methods($sfs_tests_class) as $sfs_tests_method) {
		if (0 !== strpos($sfs_tests_method, 'test') || ('' !== $sfs_tests_filter && false === stripos($sfs_tests_class . '::' . $sfs_tests_method, $sfs_tests_filter))) {
			continue;
		}
		$sfs_tests_case = new $sfs_tests_class();
		$sfs_tests_name = substr((string) strrchr($sfs_tests_class, '\\'), 1) . '::' . $sfs_tests_method;
		try {
			$sfs_tests_case->setUp();
			try {
				$sfs_tests_case->$sfs_tests_method();
			} finally {
				$sfs_tests_case->tearDown();
			}
			$sfs_tests_passed++;
			echo '.';
		} catch (Throwable $sfs_tests_error) {
			$sfs_tests_failed[] = $sfs_tests_name . "\n   " . get_class($sfs_tests_error) . ': ' . $sfs_tests_error->getMessage() . "\n   at " . $sfs_tests_error->getFile() . ':' . $sfs_tests_error->getLine();
			echo 'F';
		}
		$sfs_tests_assertions += $sfs_tests_case->assertions;
	}
}

echo "\n\n";
foreach ($sfs_tests_failed as $sfs_tests_i => $sfs_tests_failure) {
	echo ($sfs_tests_i + 1) . ') ' . $sfs_tests_failure . "\n\n";
}
printf("%s: %d passed, %d failed, %d assertions, %.2fs", strtoupper($sfs_tests_suite), $sfs_tests_passed, count($sfs_tests_failed), $sfs_tests_assertions, microtime(true) - $sfs_tests_started);
echo !empty($GLOBALS['sfs_tests_foreign_notices']) ? ' (' . (int) $GLOBALS['sfs_tests_foreign_notices'] . " notices from WordPress/theme code ignored)\n" : "\n";

if (function_exists('sfs_tracking_tests_cleanup')) {
	sfs_tracking_tests_cleanup();
}
exit($sfs_tests_failed ? 1 : 0);

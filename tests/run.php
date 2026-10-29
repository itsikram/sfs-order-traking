<?php
/**
 * Test runner: php tests/run.php [unit|integration|all] [name filter]
 *
 * Unit tests cover the pure classes in src/Core without WordPress. Integration tests load the local WordPress
 * (wp-load.php), create clearly marked test orders, capture mail instead of sending it and delete everything they
 * created before exiting. Every PHP notice, warning or deprecation is a failure.
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
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

$suite = $argv[1] ?? 'unit';
$filter = $argv[2] ?? '';

require dirname(__DIR__) . '/src/autoload.php';
require __DIR__ . '/lib/TestCase.php';

$files = array();
if ('unit' === $suite || 'all' === $suite) {
	$files = array_merge($files, glob(__DIR__ . '/unit/*Test.php') ?: array());
}
if ('integration' === $suite || 'all' === $suite) {
	require __DIR__ . '/lib/wordpress.php';
	$files = array_merge($files, glob(__DIR__ . '/integration/*Test.php') ?: array());
}

$before = get_declared_classes();
foreach ($files as $file) {
	require $file;
}
$classes = array_filter(array_diff(get_declared_classes(), $before), static function ($class) {
	return is_subclass_of($class, 'SFS\\OrderTracking\\Tests\\TestCase') && !(new ReflectionClass($class))->isAbstract();
});

$passed = 0;
$failed = array();
$assertions = 0;
$started = microtime(true);
foreach ($classes as $class) {
	foreach (get_class_methods($class) as $method) {
		if (0 !== strpos($method, 'test') || ('' !== $filter && false === stripos($class . '::' . $method, $filter))) {
			continue;
		}
		$test = new $class();
		$name = substr((string) strrchr($class, '\\'), 1) . '::' . $method;
		try {
			$test->setUp();
			try {
				$test->$method();
			} finally {
				$test->tearDown();
			}
			$passed++;
			echo '.';
		} catch (Throwable $e) {
			$failed[] = $name . "\n   " . get_class($e) . ': ' . $e->getMessage() . "\n   at " . $e->getFile() . ':' . $e->getLine();
			echo 'F';
		}
		$assertions += $test->assertions;
	}
}

if (function_exists('sfs_tracking_tests_cleanup')) {
	sfs_tracking_tests_cleanup();
}

echo "\n\n";
foreach ($failed as $i => $failure) {
	echo ($i + 1) . ') ' . $failure . "\n\n";
}
printf("%s: %d passed, %d failed, %d assertions, %.2fs\n", strtoupper($suite), $passed, count($failed), $assertions, microtime(true) - $started);
exit($failed ? 1 : 0);

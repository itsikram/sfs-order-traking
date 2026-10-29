<?php
/**
 * Class autoloader for SFS\OrderTracking\*, shared by the plugin and the test runner.
 *
 * @package sfs-order-tracking
 */

spl_autoload_register(
	static function ($class) {
		$prefix = 'SFS\\OrderTracking\\';
		if (0 !== strncmp($class, $prefix, strlen($prefix))) {
			return;
		}
		$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
);

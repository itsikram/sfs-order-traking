<?php
/**
 * MySQL named locks, so two overlapping syncs never write the same order at once.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

final class Lock {
	public static function acquire(string $name, int $timeout = 10): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::name($name), $timeout));
	}

	public static function release(string $name): void {
		global $wpdb;
		$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::name($name)));
	}

	/**
	 * Lock names are server-wide, so they carry the database and table prefix (max 64 characters).
	 */
	private static function name(string $name): string {
		global $wpdb;
		return substr(md5(DB_NAME . '|' . $wpdb->prefix) . ':sfs_tracking_' . $name, 0, 64);
	}
}

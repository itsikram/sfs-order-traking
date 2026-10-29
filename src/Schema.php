<?php
/**
 * Database tables. Both are written by the live site, so they are registered with the Site Migration tool as
 * live-owned (filter wudt_live_owned_tables, names without the table prefix): a push pulls them from live first
 * and never overwrites them.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

final class Schema {
	public const VERSION = '1';
	public const OPTION = 'sfs_tracking_db_version';
	public const TABLES = array('sfs_tracking_inbox', 'sfs_tracking_log');

	public static function inbox(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfs_tracking_inbox';
	}

	public static function log(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfs_tracking_log';
	}

	/**
	 * @param mixed $tables
	 */
	public static function live_owned_tables($tables): array {
		return array_values(array_unique(array_merge((array) $tables, self::TABLES)));
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$inbox = self::inbox();
		$log = self::log();
		dbDelta("CREATE TABLE {$inbox} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  tracking_number varchar(40) NOT NULL DEFAULT '',
  carrier varchar(10) NOT NULL DEFAULT '',
  b2_order_number varchar(12) NOT NULL DEFAULT '',
  item_status varchar(40) NOT NULL DEFAULT '',
  state varchar(20) NOT NULL DEFAULT 'unmatched',
  confirmed tinyint(1) NOT NULL DEFAULT 0,
  order_number varchar(32) NOT NULL DEFAULT '',
  record longtext NOT NULL,
  candidates longtext NULL,
  record_hash char(40) NOT NULL DEFAULT '',
  message varchar(255) NOT NULL DEFAULT '',
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY item_tn (item_id,tracking_number),
  KEY state (state),
  KEY b2_order_number (b2_order_number)
) {$charset};");
		dbDelta("CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  type varchar(20) NOT NULL DEFAULT 'run',
  run_id varchar(64) NOT NULL DEFAULT '',
  level varchar(10) NOT NULL DEFAULT 'info',
  message varchar(255) NOT NULL DEFAULT '',
  context longtext NULL,
  PRIMARY KEY  (id),
  KEY type_created (type,created_at)
) {$charset};");
		update_option(self::OPTION, self::VERSION, true);
	}

	/**
	 * Creates missing tables. The version option alone is not trusted: a push copies options to live but leaves
	 * tables the live site does not have yet out of the push.
	 */
	public static function ensure(): void {
		static $done = false;
		if ($done) {
			return;
		}
		$done = true;
		global $wpdb;
		$inbox = self::inbox();
		$log = self::log();
		$present = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'sfs_tracking_') . '%'));
		if (self::VERSION !== get_option(self::OPTION) || !in_array($inbox, $present, true) || !in_array($log, $present, true)) {
			self::install();
		}
	}

	public static function drop(): void {
		global $wpdb;
		foreach (array(self::inbox(), self::log()) as $table) {
			$wpdb->query("DROP TABLE IF EXISTS `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		delete_option(self::OPTION);
	}
}

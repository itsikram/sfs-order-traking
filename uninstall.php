<?php
/**
 * Removes the plugin's options, cron job and tables. Tracking stored on orders (_sfs_* meta) is kept unless
 * "Also delete tracking data stored on orders" was turned on in the settings. The theme's own tracking fields
 * (_tracking_carrier, _tracking_number) are never removed.
 *
 * @package sfs-order-tracking
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

require __DIR__ . '/src/autoload.php';

global $wpdb;

$sfs_delete_meta = !empty(SFS\OrderTracking\Settings::all()['delete_on_uninstall']);

wp_clear_scheduled_hook(SFS\OrderTracking\Health::CRON);
SFS\OrderTracking\Schema::drop();
delete_option(SFS\OrderTracking\Settings::OPTION);
delete_option(SFS\OrderTracking\Health::MAIL_OPTION);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sfs\\_trk\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sfs\\_trk\\_%' OR option_name IN ('_transient_sfs_tracking_pruned', '_transient_timeout_sfs_tracking_pruned')"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ($sfs_delete_meta) {
	$sfs_keys = array(
		SFS\OrderTracking\Orders::META_SHIPMENTS,
		SFS\OrderTracking\Orders::META_DUE,
		SFS\OrderTracking\Orders::META_TN,
		SFS\OrderTracking\Orders::META_ITEM,
		SFS\OrderTracking\Orders::META_B2_ORDER,
		SFS\OrderTracking\Orders::META_ITEMS,
		SFS\OrderTracking\Orders::META_EXCLUDED,
		SFS\OrderTracking\Orders::META_ACTIVITY,
		SFS\OrderTracking\Orders::META_AUTO_SHIPPED,
	);
	foreach ($sfs_keys as $sfs_key) {
		delete_post_meta_by_key($sfs_key);
	}
}

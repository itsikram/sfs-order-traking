<?php
/**
 * Integration test bootstrap: loads the local WordPress with a throwaway extension key, captures mail instead of
 * sending it (the theme's email log is unhooked too, so no log rows or retries), and records the database
 * high-water marks so sfs_tracking_tests_cleanup() can remove everything the tests created.
 *
 * Test orders are titled "SFS TRACKING TEST ORDER", carry meta _sfs_test_order and use @example.invalid emails.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

if ('cli' !== PHP_SAPI) {
	exit;
}

define('SFS_TRACKING_KEY_ID', 'sfs_testkey');
define('SFS_TRACKING_SECRET', str_repeat('7e', 32));
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/storefrontsignonline.com/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.250';
// A query string keeps the page cache (which serves stored pages before WordPress loads) out of the way.
$_SERVER['QUERY_STRING'] = 'sfs_tracking_tests=1';

require dirname(__DIR__, 5) . '/wp-load.php';

if (!class_exists('SFS\\OrderTracking\\Plugin')) {
	fwrite(STDERR, "Activate the SFS Order Tracking plugin locally before running integration tests.\n");
	exit(1);
}

// The Diagnostics Toolkit's error logger hooks in at init and saves what it sees at shutdown; keep test noise out
// of the owner's error log by going back to the runner's handler.
restore_error_handler();

global $wpdb;
$GLOBALS['sfs_test_mail'] = array();
remove_filter('wp_mail', 'wholesale_email_log_start', 9999);
add_filter('pre_wp_mail', static function ($return, $atts) {
	$GLOBALS['sfs_test_mail'][] = $atts;
	return true;
}, 1, 2);

$GLOBALS['sfs_test_marks'] = array(
	'log' => (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . SFS\OrderTracking\Schema::log()),
	'inbox' => (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . SFS\OrderTracking\Schema::inbox()),
	'email_log' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wholesale_email_log"),
	'settings' => get_option(SFS\OrderTracking\Settings::OPTION, null),
	'health_mail' => get_option(SFS\OrderTracking\Health::MAIL_OPTION, null),
);

/**
 * Sends the status emails the theme queued during a test, the way its shutdown hook would, then clears the queue.
 */
function sfs_test_flush_status_emails(): void {
	foreach ((array) ($GLOBALS['wholesale_status_emails'] ?? array()) as $post_id => $status) {
		if (empty($GLOBALS['wholesale_skip_status_email'])) {
			wholesale_send_status_email($post_id, $status);
		}
	}
	$GLOBALS['wholesale_status_emails'] = array();
}

function sfs_tracking_tests_cleanup(): void {
	global $wpdb;
	sfs_test_flush_status_emails();
	$marks = $GLOBALS['sfs_test_marks'];
	$ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sfs_test_order'");
	foreach ($ids as $id) {
		wp_delete_post((int) $id, true);
	}
	$wpdb->query($wpdb->prepare('DELETE FROM ' . SFS\OrderTracking\Schema::log() . ' WHERE id > %d', $marks['log']));
	$wpdb->query($wpdb->prepare('DELETE FROM ' . SFS\OrderTracking\Schema::inbox() . ' WHERE id > %d', $marks['inbox']));
	$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sfs\\_trk\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sfs\\_trk\\_%' OR option_name IN ('_transient_sfs_tracking_pruned', '_transient_timeout_sfs_tracking_pruned')");
	foreach (array('settings' => SFS\OrderTracking\Settings::OPTION, 'health_mail' => SFS\OrderTracking\Health::MAIL_OPTION) as $mark => $option) {
		if (null === $marks[$mark]) {
			delete_option($option);
		} else {
			update_option($option, $marks[$mark], false);
		}
	}
	wp_cache_flush();

	$left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_sfs_test_order'")
		+ (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = 'SFS TRACKING TEST ORDER'")
		+ (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SFS\OrderTracking\Schema::log() . ' WHERE id > %d', $marks['log']))
		+ (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SFS\OrderTracking\Schema::inbox() . ' WHERE id > %d', $marks['inbox']))
		+ (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE '%example.invalid%'");
	$left += count(array_filter((array) get_option('wudt_error_log_entries', array()), static function ($entry) {
		return false !== strpos((string) ($entry['file'] ?? ''), 'sfs-order-tracking');
	}));
	$email_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wholesale_email_log") - $marks['email_log'];
	echo "\nCleanup check: {$left} test rows left, {$email_rows} new email-log rows, " . count($GLOBALS['sfs_test_mail']) . " emails captured (none sent).\n";
	if ($left || $email_rows) {
		exit(2);
	}
}

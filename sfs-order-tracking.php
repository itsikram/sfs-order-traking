<?php
/**
 * Plugin Name: SFS Order Tracking
 * Description: Attaches supplier shipments and live UPS/FedEx status, sent by the SFS Tracking Sync Chrome extension, to orders, and shows customers live tracking without naming the supplier.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Storefront Sign Online
 * License: Proprietary
 * Text Domain: sfs-order-tracking
 *
 * @package sfs-order-tracking
 */

if (!defined('ABSPATH')) {
	exit;
}

define('SFS_TRACKING_VERSION', '1.0.0');
define('SFS_TRACKING_FILE', __FILE__);

require __DIR__ . '/src/autoload.php';

register_activation_hook(__FILE__, array('SFS\\OrderTracking\\Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('SFS\\OrderTracking\\Plugin', 'deactivate'));

SFS\OrderTracking\Plugin::boot();

<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

final class Plugin {
	public static function boot(): void {
		add_filter('wudt_live_owned_tables', array(Schema::class, 'live_owned_tables'));
		add_action('rest_api_init', array(Rest::class, 'register'));
		add_filter('rest_post_dispatch', array(Rest::class, 'no_store'), 10, 3);
		PublicView::hooks();
		Emails::hooks();
		Health::hooks();
		OrderHooks::hooks();
		if (is_admin()) {
			Admin::hooks();
			add_action('admin_init', array(Schema::class, 'ensure'));
		}
	}

	public static function activate(): void {
		Schema::install();
		Health::schedule();
	}

	public static function deactivate(): void {
		Health::unschedule();
	}
}

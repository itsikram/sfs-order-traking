<?php
/**
 * Plugin settings (option sfs_tracking_settings). Nothing here is critical: a local → live push overwrites options,
 * so the keys live in wp-config.php, and dry-run can be pinned there too with SFS_TRACKING_DRY_RUN.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

use SFS\OrderTracking\Core\CheckScheduler;
use SFS\OrderTracking\Core\LocationPolicy;

final class Settings {
	public const OPTION = 'sfs_tracking_settings';

	/** B2Sign statuses meaning the supplier has started or finished the item (seen: printing, shipped, completed). */
	public const DEFAULT_PRODUCTION = array('printing', 'printed', 'in_production', 'production', 'processing', 'cutting', 'laminating', 'finishing', 'packing', 'packaging', 'quality_check', 'ready_to_ship', 'shipped', 'completed');

	/** Ranges for the numeric settings: key => [min, max, default]. */
	private const NUMBERS = array(
		'interval_min' => array(5, 60, 10),
		'lookback_days' => array(7, 120, 45),
		'heartbeat_hours' => array(1, 168, 24),
		'max_checks_per_run' => array(1, 20, 8),
		'check_pre_transit' => array(30, 720, 180),
		'check_in_transit' => array(15, 360, 60),
		'check_out_for_delivery' => array(5, 120, 20),
		'check_exception' => array(15, 360, 60),
		'check_unknown' => array(30, 720, 120),
	);

	/** Owner decisions of 2026-10-29 are the defaults. */
	public static function defaults(): array {
		$defaults = array(
			'dry_run' => true,
			'auto_ship' => true,
			'email_extra_packages' => false,
			'location_mode' => 'destination',
			'email_delivered' => false,
			'email_issue' => false,
			'staff_exception_email' => true,
			'staff_health_email' => true,
			'staff_email' => '',
			// Owner request 2026-10-29: move Pending review orders to In production from the supplier status.
			'auto_production' => true,
			'production_statuses' => '',
			'auto_apply_fuzzy' => false,
			'delete_on_uninstall' => false,
		);
		foreach (self::NUMBERS as $key => $range) {
			$defaults[$key] = $range[2];
		}
		return $defaults;
	}

	public static function all(): array {
		static $cache = null;
		$saved = get_option(self::OPTION, array());
		if (null === $cache || $cache[0] !== $saved) {
			$cache = array($saved, self::sanitize(is_array($saved) ? $saved : array()));
		}
		return $cache[1];
	}

	/**
	 * @return mixed
	 */
	public static function get(string $key) {
		return self::all()[$key] ?? null;
	}

	public static function dry_run(): bool {
		if (self::dry_run_locked()) {
			return (bool) constant('SFS_TRACKING_DRY_RUN');
		}
		return (bool) self::get('dry_run');
	}

	/** True when wp-config.php pins dry-run, so a push cannot flip it. */
	public static function dry_run_locked(): bool {
		return defined('SFS_TRACKING_DRY_RUN');
	}

	/**
	 * Minutes between carrier checks per status, for CheckScheduler.
	 *
	 * @return array<string,int>
	 */
	public static function check_minutes(): array {
		$all = self::all();
		return array(
			'pre_transit' => $all['check_pre_transit'],
			'in_transit' => $all['check_in_transit'],
			'out_for_delivery' => $all['check_out_for_delivery'],
			'exception' => $all['check_exception'],
			'returned' => $all['check_unknown'],
			'unknown' => $all['check_unknown'],
		) + CheckScheduler::DEFAULT_MINUTES;
	}

	/**
	 * B2Sign item statuses that mean "in production" (only used when auto_production is on). An empty setting uses
	 * DEFAULT_PRODUCTION. Shipped items count too: shipped without a tracking number yet still means made.
	 *
	 * @return string[]
	 */
	public static function production_statuses(): array {
		$saved = array_values(array_filter(array_map('trim', explode(',', (string) self::get('production_statuses')))));
		return $saved ?: self::DEFAULT_PRODUCTION;
	}

	public static function staff_email(): string {
		$email = (string) self::get('staff_email');
		return is_email($email) ? $email : (string) get_option('admin_email');
	}

	public static function save(array $input): void {
		update_option(self::OPTION, self::sanitize($input), false);
	}

	public static function sanitize(array $input): array {
		$defaults = self::defaults();
		$out = array();
		foreach ($defaults as $key => $default) {
			$value = $input[$key] ?? $default;
			if (is_bool($default)) {
				$out[$key] = in_array($value, array(true, 1, '1', 'on', 'yes'), true);
			} elseif (isset(self::NUMBERS[$key])) {
				list($min, $max) = self::NUMBERS[$key];
				$out[$key] = is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
			} else {
				$out[$key] = is_scalar($value) ? (string) $value : (string) $default;
			}
		}
		if (!in_array($out['location_mode'], LocationPolicy::MODES, true)) {
			$out['location_mode'] = $defaults['location_mode'];
		}
		$out['staff_email'] = is_email($out['staff_email']) ? sanitize_email($out['staff_email']) : '';
		$statuses = array_filter(array_map(static function ($status) {
			$status = strtolower(trim($status));
			return preg_match('/^[a-z][a-z0-9_]{0,39}$/', $status) ? $status : '';
		}, explode(',', $out['production_statuses'])));
		$out['production_statuses'] = implode(',', array_unique($statuses));
		return $out;
	}
}

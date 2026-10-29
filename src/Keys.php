<?php
/**
 * The extension's key pair lives in wp-config.php (SFS_TRACKING_KEY_ID, SFS_TRACKING_SECRET), never in options,
 * because a local → live push overwrites options. The admin page only says whether they are set.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

final class Keys {
	public static function key_id(): string {
		return defined('SFS_TRACKING_KEY_ID') ? (string) constant('SFS_TRACKING_KEY_ID') : '';
	}

	public static function secret(): string {
		return defined('SFS_TRACKING_SECRET') ? (string) constant('SFS_TRACKING_SECRET') : '';
	}

	public static function configured(): bool {
		return 1 === preg_match('/^[A-Za-z0-9_-]{4,64}$/', self::key_id()) && strlen(self::secret()) >= 32;
	}

	/**
	 * A new key pair, shown once on the admin page for the owner to paste into wp-config.php.
	 *
	 * @return array{key_id:string,secret:string}
	 */
	public static function generate(): array {
		return array('key_id' => 'sfs_' . bin2hex(random_bytes(4)), 'secret' => bin2hex(random_bytes(32)));
	}

	/**
	 * @param array{key_id:string,secret:string} $key
	 */
	public static function define_lines(array $key): string {
		return "define('SFS_TRACKING_KEY_ID', '" . $key['key_id'] . "');\ndefine('SFS_TRACKING_SECRET', '" . $key['secret'] . "');";
	}

	/**
	 * Secret for customer tracking-page tokens, derived from the site's own salts so customer pages work before the
	 * extension key is set up and differ between local and live.
	 */
	public static function token_secret(): string {
		return hash_hmac('sha256', 'sfs-tracking-token-v1', wp_salt('auth'));
	}
}

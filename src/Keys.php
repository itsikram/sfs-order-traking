<?php
/**
 * The extension's key pair: SFS_TRACKING_KEY_ID / SFS_TRACKING_SECRET in wp-config.php when defined, else the pair
 * saved on Orders → Tracking sync. That option is named wudt_… on purpose: the site migration tool keeps each site's
 * own wudt_ options when it replaces the options table, so a local → live push never copies the local key to live
 * (or wipes live's). The secret is never shown again after saving.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

final class Keys {
	public const OPTION = 'wudt_sfs_tracking_key';

	public static function key_id(): string {
		return self::in_config() ? (string) constant('SFS_TRACKING_KEY_ID') : (string) (self::stored()['key_id'] ?? '');
	}

	public static function secret(): string {
		return self::in_config() ? (string) constant('SFS_TRACKING_SECRET') : (string) (self::stored()['secret'] ?? '');
	}

	public static function configured(): bool {
		return self::valid(self::key_id(), self::secret());
	}

	/** Both wp-config.php constants are defined; they win over a key saved on the page. */
	public static function in_config(): bool {
		return defined('SFS_TRACKING_KEY_ID') && defined('SFS_TRACKING_SECRET');
	}

	public static function valid(string $key_id, string $secret): bool {
		return 1 === preg_match('/^[A-Za-z0-9_-]{4,64}$/', $key_id) && 1 === preg_match('/^[A-Za-z0-9_-]{32,128}$/', $secret);
	}

	/**
	 * Saves the pair from the admin page. An empty secret keeps the saved one (changing only the key ID).
	 *
	 * @return string '' when saved, else why not.
	 */
	public static function save(string $key_id, string $secret): string {
		$key_id = trim($key_id);
		$secret = '' === trim($secret) ? (string) (self::stored()['secret'] ?? '') : trim($secret);
		if (!self::valid($key_id, $secret)) {
			return 'Use a key ID of 4–64 letters, numbers, _ or - and a secret of at least 32 of those characters (Generate makes both).';
		}
		update_option(self::OPTION, array('key_id' => $key_id, 'secret' => $secret), false);
		return '';
	}

	public static function forget(): void {
		delete_option(self::OPTION);
	}

	/** @return array{key_id?:string,secret?:string} */
	private static function stored(): array {
		$value = get_option(self::OPTION, array());
		return is_array($value) ? $value : array();
	}

	/**
	 * A new random key pair.
	 *
	 * @return array{key_id:string,secret:string}
	 */
	public static function generate(): array {
		return array('key_id' => 'sfs_' . bin2hex(random_bytes(4)), 'secret' => bin2hex(random_bytes(32)));
	}


	/**
	 * Secret for customer tracking-page tokens, derived from the site's own salts so customer pages work before the
	 * extension key is set up and differ between local and live.
	 */
	public static function token_secret(): string {
		return hash_hmac('sha256', 'sfs-tracking-token-v1', wp_salt('auth'));
	}
}

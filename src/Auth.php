<?php
/**
 * HMAC authentication for the extension's REST calls: X-SFS-Key, X-SFS-Timestamp, X-SFS-Nonce, X-SFS-Signature
 * (not the Authorization header, which some Apache setups strip). Rejects clock skew over 5 minutes, reused nonces
 * (kept 10 minutes) and bad signatures, and locks an IP out for 15 minutes after 10 failures.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

use SFS\OrderTracking\Core\RecordValidator;
use SFS\OrderTracking\Core\RequestSigner;

final class Auth {
	public const FAIL_LIMIT = 10;
	public const FAIL_WINDOW = 900;
	public const NONCE_TTL = 600;

	/**
	 * permission_callback for the signed routes.
	 *
	 * @return true|\WP_Error
	 */
	public static function signed(\WP_REST_Request $request) {
		$ip = self::ip();
		if (self::failures($ip) >= self::FAIL_LIMIT) {
			return new \WP_Error('sfs_locked', 'Too many failed attempts from this address. Try again in 15 minutes.', array('status' => 429));
		}
		if (!Keys::configured()) {
			return new \WP_Error('sfs_not_configured', 'Tracking sync is not connected on this site yet: save a key on Orders → Tracking sync.', array('status' => 503));
		}
		$body = (string) $request->get_body();
		if (strlen($body) > RecordValidator::MAX_BODY) {
			return new \WP_Error('sfs_too_large', 'The request body is larger than 1 MB.', array('status' => 413));
		}
		$headers = array(
			'key' => (string) $request->get_header('x_sfs_key'),
			'timestamp' => (string) $request->get_header('x_sfs_timestamp'),
			'nonce' => (string) $request->get_header('x_sfs_nonce'),
			'signature' => (string) $request->get_header('x_sfs_signature'),
		);
		$now = time();
		$error = RequestSigner::verify($headers, $request->get_method(), $request->get_route(), $body, Keys::key_id(), Keys::secret(), $now);
		if ('' !== $error) {
			// A wrong clock is not an attack: answering with the clock message beats locking the owner out.
			if ('clock_skew' !== $error) {
				self::fail($ip);
			}
			return new \WP_Error('sfs_' . $error, self::message($error), array('status' => 401, 'server_time' => $now));
		}
		if (!self::claim_nonce($headers['nonce'])) {
			self::fail($ip);
			return new \WP_Error('sfs_replay', 'This request was already used.', array('status' => 401, 'server_time' => $now));
		}
		return true;
	}

	public static function ip(): string {
		return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
	}

	private static function failures(string $ip): int {
		return (int) get_transient('sfs_trk_fail_' . md5($ip));
	}

	private static function fail(string $ip): void {
		$key = 'sfs_trk_fail_' . md5($ip);
		set_transient($key, (int) get_transient($key) + 1, self::FAIL_WINDOW);
	}

	/**
	 * Remembers a nonce for 10 minutes; false when it was seen before. Atomic under a named lock.
	 */
	private static function claim_nonce(string $nonce): bool {
		$key = 'sfs_trk_n_' . md5($nonce);
		if (!Lock::acquire('nonce', 5)) {
			return false;
		}
		try {
			if (false !== get_transient($key)) {
				return false;
			}
			set_transient($key, 1, self::NONCE_TTL);
			return true;
		} finally {
			Lock::release('nonce');
		}
	}

	private static function message(string $error): string {
		$messages = array(
			'missing_headers' => 'The request is not signed.',
			'malformed' => 'The request signature headers are malformed.',
			'bad_key' => 'Unknown key. Check the key ID in the extension options.',
			'clock_skew' => 'This computer\'s clock is more than 5 minutes off. Fix the clock and try again.',
			'bad_signature' => 'The signature does not match. Check the secret in the extension options.',
		);
		return $messages[$error] ?? 'The request could not be verified.';
	}
}

<?php
/**
 * HMAC request signatures shared with the extension (src/lib/site-client.js).
 *
 * Signed string: "timestamp\nnonce\nMETHOD\n/sfs-tracking/v1/<route>\nsha256hex(body)". The route is signed,
 * not the URL, so a site in a subdirectory and both /wp-json/ and ?rest_route= URLs verify the same way.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class RequestSigner {
	public const MAX_SKEW = 300;

	public static function canonical(string $timestamp, string $nonce, string $method, string $route, string $body): string {
		return $timestamp . "\n" . $nonce . "\n" . strtoupper($method) . "\n" . self::route($route) . "\n" . hash('sha256', $body);
	}

	/**
	 * "/sfs-tracking/v1/sync/" and "sfs-tracking/v1/sync" both become "/sfs-tracking/v1/sync".
	 */
	public static function route(string $route): string {
		return '/' . trim($route, '/');
	}

	public static function sign(string $secret, string $canonical): string {
		return hash_hmac('sha256', $canonical, $secret);
	}

	public static function valid_nonce(string $nonce): bool {
		return 1 === preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce);
	}

	/**
	 * Checks a signed request. Returns '' when it is valid, otherwise why not:
	 * missing_headers, malformed, bad_key, clock_skew or bad_signature.
	 *
	 * @param array{key?:string,timestamp?:string,nonce?:string,signature?:string} $headers
	 */
	public static function verify(array $headers, string $method, string $route, string $body, string $key_id, string $secret, int $now): string {
		$key = (string) ($headers['key'] ?? '');
		$timestamp = (string) ($headers['timestamp'] ?? '');
		$nonce = (string) ($headers['nonce'] ?? '');
		$signature = strtolower((string) ($headers['signature'] ?? ''));
		if ('' === $key || '' === $timestamp || '' === $nonce || '' === $signature) {
			return 'missing_headers';
		}
		if (!self::valid_nonce($nonce) || !preg_match('/^[0-9a-f]{64}$/', $signature) || !preg_match('/^[0-9]{9,11}$/', $timestamp)) {
			return 'malformed';
		}
		if ('' === $key_id || '' === $secret || !hash_equals($key_id, $key)) {
			return 'bad_key';
		}
		if (abs($now - (int) $timestamp) > self::MAX_SKEW) {
			return 'clock_skew';
		}
		$expected = self::sign($secret, self::canonical($timestamp, $nonce, $method, $route, $body));
		return hash_equals($expected, $signature) ? '' : 'bad_signature';
	}
}

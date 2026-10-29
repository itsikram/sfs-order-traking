<?php
/**
 * Short-lived tokens that let a customer's open tracking page poll POST /track for one order.
 *
 * Token: base64url("v1|ORDERNUMBER|expires") . "." . hex HMAC-SHA256 of that payload.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class TokenSigner {
	public static function issue(string $secret, string $order_number, int $expires): string {
		$payload = 'v1|' . $order_number . '|' . $expires;
		return self::encode($payload) . '.' . hash_hmac('sha256', $payload, $secret);
	}

	/**
	 * @return array{ok:bool,error:string,order:string,expires:int} error is 'invalid' or 'expired'.
	 */
	public static function verify(string $secret, string $token, int $now): array {
		$result = array('ok' => false, 'error' => 'invalid', 'order' => '', 'expires' => 0);
		if ('' === $secret || strlen($token) > 400 || !preg_match('/^([A-Za-z0-9_-]{4,200})\.([0-9a-f]{64})$/', $token, $match)) {
			return $result;
		}
		$payload = self::decode($match[1]);
		if (null === $payload || !hash_equals(hash_hmac('sha256', $payload, $secret), $match[2])) {
			return $result;
		}
		$parts = explode('|', $payload);
		if (3 !== count($parts) || 'v1' !== $parts[0] || !preg_match('/^[A-Z0-9]{4,32}$/', $parts[1]) || !preg_match('/^[0-9]{9,11}$/', $parts[2])) {
			return $result;
		}
		$result['order'] = $parts[1];
		$result['expires'] = (int) $parts[2];
		if ($result['expires'] < $now) {
			$result['error'] = 'expired';
			return $result;
		}
		$result['ok'] = true;
		$result['error'] = '';
		return $result;
	}

	private static function encode(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private static function decode(string $value): ?string {
		$value = strtr($value, '-_', '+/');
		$value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
		$decoded = base64_decode($value, true);
		return false === $decoded ? null : $decoded;
	}
}

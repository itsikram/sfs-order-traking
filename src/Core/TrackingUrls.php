<?php
/**
 * Tracking number formats and the carrier links the plugin accepts or builds.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class TrackingUrls {
	/** Hosts a tracking link may point at, per carrier. */
	private const HOSTS = array(
		'ups' => array('www.ups.com', 'wwwapps.ups.com'),
		'fedex' => array('www.fedex.com'),
	);

	private const PATTERNS = array(
		'ups' => '/^1Z[0-9A-Z]{16}$/',
		'fedex' => '/^(?:[0-9]{12}|[0-9]{15}|[0-9]{20}|[0-9]{22})$/',
		'usps' => '/^(?:9[0-9]{15,25}|[A-Z]{2}[0-9]{9}US)$/',
	);

	public const NAMES = array('ups' => 'UPS', 'fedex' => 'FedEx', 'usps' => 'USPS');

	/** Carriers the extension can read status pages for. */
	public static function checkable(string $carrier): bool {
		return isset(self::HOSTS[$carrier]);
	}

	public static function normalize_number(string $number): string {
		return strtoupper((string) preg_replace('/[\s-]+/', '', $number));
	}

	public static function valid_number(string $carrier, string $number): bool {
		return isset(self::PATTERNS[$carrier]) && 1 === preg_match(self::PATTERNS[$carrier], $number);
	}

	/**
	 * Carrier for a tracking number typed without one, or '' when the format is not recognised.
	 */
	public static function guess_carrier(string $number): string {
		foreach (self::PATTERNS as $carrier => $pattern) {
			if (preg_match($pattern, $number)) {
				return $carrier;
			}
		}
		return '';
	}

	/**
	 * True for an https link on the carrier's own tracking host that names this tracking number.
	 */
	public static function allowed(string $carrier, string $number, string $url): bool {
		if (!isset(self::HOSTS[$carrier]) || '' === $number || '' === $url || strlen($url) > 500) {
			return false;
		}
		$parts = parse_url($url);
		if (!is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? '')) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
			return false;
		}
		if (!in_array(strtolower((string) ($parts['host'] ?? '')), self::HOSTS[$carrier], true)) {
			return false;
		}
		$haystack = strtoupper(rawurldecode(($parts['path'] ?? '') . '?' . ($parts['query'] ?? '')));
		return false !== strpos($haystack, strtoupper($number));
	}

	/**
	 * The status page the extension opens for a carrier check.
	 */
	public static function check_url(string $carrier, string $number): string {
		switch ($carrier) {
			case 'ups':
				return 'https://www.ups.com/track?track=yes&trackNums=' . rawurlencode($number) . '&loc=en_us&requester=ST/trackdetails';
			case 'fedex':
				return 'https://www.fedex.com/fedextrack/?trknbr=' . rawurlencode($number);
		}
		return '';
	}

	/**
	 * Public tracking link for customers when the theme's wholesale_tracking_url() is not available.
	 */
	public static function public_url(string $carrier, string $number): string {
		if ('usps' === $carrier) {
			return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . rawurlencode($number);
		}
		return self::check_url($carrier, $number);
	}
}

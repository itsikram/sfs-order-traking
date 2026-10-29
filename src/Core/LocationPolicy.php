<?php
/**
 * Which scan locations customers may see (owner decision 2026-10-29: destination side only).
 *
 * Origin and hub scans name the factory's region, which conflicts with our "made in WA/CA" copy, so in
 * "destination" mode only last-mile scans show a city: local/destination facility, out for delivery, delivery
 * attempts and delivered, plus any non-origin scan in the ship-to city itself.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class LocationPolicy {
	public const MODES = array('destination', 'hide', 'all');

	private const LAST_MILE = array(
		'at local fedex facility',
		'at destination sort facility',
		'destination scan',
		'arrived at destination',
		'out for delivery',
		'out for delivery today',
		'loaded on delivery vehicle',
		'on fedex vehicle for delivery',
		'on vehicle for delivery',
		'delivery attempted',
		'delivery attempt',
		'attempted delivery',
		'customer not available or business closed',
		'business closed',
		'delivered',
		'delivered to ups access point',
		'delivered to ups access point location',
	);

	private const ORIGIN = array(
		'label created',
		'shipper created a label, ups has not received the package yet',
		'shipment information sent to fedex',
		'we have your package',
		'picked up',
		'pickup scan',
		'origin scan',
		'at fedex origin facility',
		'left fedex origin facility',
	);

	/**
	 * @param array{status_text?:string,detail_text?:string,location_text?:string} $event
	 * @param string|null $kind Status meaning of the scan (CarrierTextNormalizer::event_kind()).
	 * @param array{city?:string,state?:string} $destination Ship-to city and state of the order.
	 */
	public static function visible(string $mode, array $event, ?string $kind, array $destination): bool {
		$location = trim((string) ($event['location_text'] ?? ''));
		if ('' === $location || 'hide' === $mode) {
			return false;
		}
		if ('all' === $mode) {
			return true;
		}
		if (in_array($kind, array('out_for_delivery', 'delivered'), true)) {
			return true;
		}
		$status = Text::phrase((string) ($event['status_text'] ?? ''));
		$detail = Text::phrase((string) ($event['detail_text'] ?? ''));
		if (in_array($status, self::LAST_MILE, true) || in_array($detail, self::LAST_MILE, true)) {
			return true;
		}
		if ('pre_transit' === $kind || in_array($status, self::ORIGIN, true) || in_array($detail, self::ORIGIN, true)) {
			return false;
		}
		$place = self::place($location);
		$city = Text::phrase((string) ($destination['city'] ?? ''));
		$state = strtolower(trim((string) ($destination['state'] ?? '')));
		return null !== $place && '' !== $city && '' !== $state && $place['city'] === $city && $place['state'] === $state;
	}

	/**
	 * "ANYTOWN, PA", "Anytown, PA, United States", "Lakeview, LA US" → array('city' => 'anytown', 'state' => 'pa').
	 *
	 * @return array{city:string,state:string}|null
	 */
	public static function place(string $text): ?array {
		if (!preg_match('/^\s*([^,]+),\s*([A-Za-z]{2})\b/', $text, $m)) {
			return null;
		}
		return array('city' => Text::phrase($m[1]), 'state' => strtolower($m[2]));
	}
}

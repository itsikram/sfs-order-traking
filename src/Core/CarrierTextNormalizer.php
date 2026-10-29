<?php
/**
 * Maps the wording on UPS and FedEx tracking pages to one shipment status.
 *
 * Whole phrases only (compared after Text::phrase()), never loose substrings, so "Delivery exception" can never be
 * read as "Delivered". The table is the server's: wording fixes need no extension update. Seeded with the strings
 * seen on 2026-10-29 and the carriers' usual scan wording; anything else is kept raw and listed on the Tracking
 * sync page as new carrier wording.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class CarrierTextNormalizer {
	public const STATUSES = array('pre_transit', 'in_transit', 'out_for_delivery', 'delivered', 'exception', 'returned', 'cancelled', 'unknown');

	/** What customers read for each status. */
	public const LABELS = array(
		'pre_transit' => 'Label created',
		'in_transit' => 'In transit',
		'out_for_delivery' => 'Out for delivery',
		'delivered' => 'Delivered',
		'exception' => 'Delivery issue',
		'returned' => 'Returning to sender',
		'cancelled' => 'Cancelled',
		'unknown' => 'Status unavailable',
	);

	/** Badge tone per status (theme classes order-badge--*). */
	public const TONES = array(
		'pre_transit' => 'info',
		'in_transit' => 'info',
		'out_for_delivery' => 'info',
		'delivered' => 'success',
		'exception' => 'warn',
		'returned' => 'warn',
		'cancelled' => 'muted',
		'unknown' => 'muted',
	);

	private const PHRASES = array(
		// Label printed, carrier does not have the package yet.
		'label created' => 'pre_transit',
		'shipper created a label, ups has not received the package yet' => 'pre_transit',
		'order processed: ready for ups' => 'pre_transit',
		'shipment information sent to fedex' => 'pre_transit',
		'label created - shipment information sent to fedex' => 'pre_transit',
		'shipment information sent to ups' => 'pre_transit',
		// Moving through the network.
		'we have your package' => 'in_transit',
		'on the way' => 'in_transit',
		'in transit' => 'in_transit',
		'picked up' => 'in_transit',
		'pickup scan' => 'in_transit',
		'origin scan' => 'in_transit',
		'arrival scan' => 'in_transit',
		'departure scan' => 'in_transit',
		'destination scan' => 'in_transit',
		'arrived at facility' => 'in_transit',
		'departed from facility' => 'in_transit',
		'departed facility' => 'in_transit',
		'processing at ups facility' => 'in_transit',
		'your package is on the way' => 'in_transit',
		'arrived at fedex location' => 'in_transit',
		'departed fedex location' => 'in_transit',
		'arrived at fedex hub' => 'in_transit',
		'departed fedex hub' => 'in_transit',
		'at fedex origin facility' => 'in_transit',
		'left fedex origin facility' => 'in_transit',
		'at local fedex facility' => 'in_transit',
		'at destination sort facility' => 'in_transit',
		'in transit to destination' => 'in_transit',
		// Last mile.
		'out for delivery' => 'out_for_delivery',
		'out for delivery today' => 'out_for_delivery',
		'loaded on delivery vehicle' => 'out_for_delivery',
		'on fedex vehicle for delivery' => 'out_for_delivery',
		'on vehicle for delivery' => 'out_for_delivery',
		// Done.
		'delivered' => 'delivered',
		'delivered to ups access point' => 'delivered',
		'delivered to ups access point location' => 'delivered',
		// Something needs attention.
		'exception' => 'exception',
		'delivery exception' => 'exception',
		'shipment exception' => 'exception',
		'delay' => 'exception',
		'delayed' => 'exception',
		'delivery attempted' => 'exception',
		'delivery attempt' => 'exception',
		'attempted delivery' => 'exception',
		'customer not available or business closed' => 'exception',
		'business closed' => 'exception',
		'incorrect address' => 'exception',
		'address correction required' => 'exception',
		'action required' => 'exception',
		'clearance delay' => 'exception',
		'weather delay' => 'exception',
		'severe weather delay' => 'exception',
		'hold at location' => 'exception',
		'held at location' => 'exception',
		// Going back.
		'returned to sender' => 'returned',
		'returned to shipper' => 'returned',
		'return to sender' => 'returned',
		'returning to sender' => 'returned',
		'returning package to shipper' => 'returned',
		'shipment returned to shipper' => 'returned',
		// Never shipping.
		'shipment cancelled' => 'cancelled',
		'shipment canceled' => 'cancelled',
		'shipment cancelled by sender' => 'cancelled',
		'shipment canceled by sender' => 'cancelled',
		'voided information received' => 'cancelled',
		'label voided' => 'cancelled',
	);

	/** Progress-bar step labels (UPS 5 steps, FedEx 4 steps). */
	private const STEPS = array(
		'label created' => 'pre_transit',
		'we have your package' => 'in_transit',
		'on the way' => 'in_transit',
		'out for delivery' => 'out_for_delivery',
		'delivery' => 'delivered',
		'delivered' => 'delivered',
	);

	private const ALARMS = array('exception', 'returned', 'cancelled');

	public static function label(string $status): string {
		return self::LABELS[$status] ?? self::LABELS['unknown'];
	}

	public static function tone(string $status): string {
		return self::TONES[$status] ?? 'muted';
	}

	/**
	 * Status of one carrier phrase, or null when the table does not know it.
	 */
	public static function phrase_status(string $text): ?string {
		$phrase = Text::phrase($text);
		return '' === $phrase ? null : (self::PHRASES[$phrase] ?? null);
	}

	public static function step_status(string $label): ?string {
		$phrase = Text::phrase($label);
		return '' === $phrase ? null : (self::STEPS[$phrase] ?? self::PHRASES[$phrase] ?? null);
	}

	/**
	 * Status meaning of one scan: its status text, else its detail text.
	 *
	 * @param array{status_text?:string,detail_text?:string} $event
	 */
	public static function event_kind(array $event): ?string {
		return self::phrase_status((string) ($event['status_text'] ?? '')) ?? self::phrase_status((string) ($event['detail_text'] ?? ''));
	}

	/**
	 * Decides a shipment's status from one validated carrier reading.
	 *
	 * Order: a delivered fact; an alarm (exception, return, cancel) in the latest scan or the headline; then the
	 * headline, the active progress step and the latest scan. Unknown wording keeps the previous status.
	 *
	 * @param array $result Validated CarrierResult (events newest first).
	 * @return array{status:string,source:string,matched:string,unknown:string[]}
	 */
	public static function normalize(array $result, string $previous = 'unknown'): array {
		$facts = (array) ($result['facts'] ?? array());
		$delivered_fact = (string) ($result['delivered_text'] ?? '');
		if ('' === $delivered_fact && isset($facts['DELIVERED'])) {
			$delivered_fact = (string) $facts['DELIVERED'];
		}
		if ('' !== trim($delivered_fact)) {
			return self::decision('delivered', 'fact', $delivered_fact, array());
		}

		$events = (array) ($result['events'] ?? array());
		$latest = $events[0] ?? null;
		$headline = (string) ($result['headline'] ?? '');
		$step = self::active_step((array) ($result['stepper'] ?? array()));

		$sources = array();
		if ($latest) {
			$sources[] = array('event', (string) $latest['status_text'], self::phrase_status((string) $latest['status_text']));
			if ('' !== (string) ($latest['detail_text'] ?? '')) {
				$sources[] = array('event_detail', (string) $latest['detail_text'], self::phrase_status((string) $latest['detail_text']));
			}
		}
		if ('' !== $headline) {
			$sources[] = array('headline', $headline, self::phrase_status($headline) ?? self::step_status($headline));
		}
		if ('' !== $step) {
			$sources[] = array('step', $step, self::step_status($step));
		}

		foreach ($sources as $source) {
			if (in_array($source[2], self::ALARMS, true)) {
				return self::decision($source[2], $source[0], $source[1], array());
			}
		}
		foreach (array('headline', 'step', 'event', 'event_detail') as $wanted) {
			foreach ($sources as $source) {
				if ($wanted === $source[0] && null !== $source[2]) {
					return self::decision($source[2], $source[0], $source[1], self::unknown_wording($result, $step));
				}
			}
		}
		$previous = in_array($previous, self::STATUSES, true) ? $previous : 'unknown';
		return self::decision($previous, 'previous', '', self::unknown_wording($result, $step));
	}

	/**
	 * Label of the active progress step, or ''.
	 *
	 * @param array<int,array{label?:string,active?:bool}> $stepper
	 */
	public static function active_step(array $stepper): string {
		$label = '';
		foreach ($stepper as $step) {
			if (!empty($step['active'])) {
				$label = (string) ($step['label'] ?? '');
			}
		}
		return $label;
	}

	/**
	 * Status texts in a reading that the table does not know (detail lines are free text and not listed).
	 *
	 * @return string[]
	 */
	public static function unknown_wording(array $result, string $step = ''): array {
		$texts = array();
		$headline = (string) ($result['headline'] ?? '');
		if ('' !== $headline && null === self::phrase_status($headline) && null === self::step_status($headline)) {
			$texts[] = $headline;
		}
		if ('' !== $step && null === self::step_status($step)) {
			$texts[] = $step;
		}
		foreach ((array) ($result['events'] ?? array()) as $event) {
			$status = (string) ($event['status_text'] ?? '');
			if ('' !== $status && null === self::phrase_status($status)) {
				$texts[] = $status;
			}
		}
		$unique = array();
		foreach ($texts as $text) {
			$unique[Text::phrase($text)] = $text;
		}
		return array_slice(array_values($unique), 0, 10);
	}

	private static function decision(string $status, string $source, string $matched, array $unknown): array {
		return array('status' => $status, 'source' => $source, 'matched' => $matched, 'unknown' => $unknown);
	}
}

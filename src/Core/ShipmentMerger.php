<?php
/**
 * The shipments stored on an order (meta _sfs_shipments): one entry per package, keyed "carrier:tracking".
 * Every change here is idempotent: the same B2Sign record or carrier reading twice changes nothing the second time.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class ShipmentMerger {
	public const MAX_EVENTS = 100;

	/** Fields that change on every check and do not count as a change of the shipment itself. */
	private const VOLATILE = array('updated_at', 'last_checked_at', 'last_attempt_at', 'next_check_at', 'priority_until');

	public static function key(string $carrier, string $number): string {
		return $carrier . ':' . $number;
	}

	/**
	 * A new shipment with every field present.
	 */
	public static function blank(string $carrier, string $number, string $source, int $now): array {
		return array(
			'carrier' => $carrier,
			'tracking_number' => $number,
			'source' => $source,
			'tracking_url' => '',
			'b2' => array(),
			'status' => 'unknown',
			'status_source' => '',
			'eta' => null,
			'delivered_at' => null,
			'last_event' => null,
			'events' => array(),
			'last_checked_at' => 0,
			'last_attempt_at' => 0,
			'next_check_at' => $now,
			'priority_until' => 0,
			'check_errors' => 0,
			'last_error' => '',
			'parser_version' => '',
			'unknown_wording' => array(),
			'notified' => array(),
			'created_at' => $now,
			'updated_at' => $now,
		);
	}

	/**
	 * Adds or refreshes the packages of one B2Sign item. Carrier readings are left alone, except that a B2Sign
	 * "delivered" date marks a package delivered when no carrier page said so yet, and B2Sign's estimate fills in
	 * until a carrier gives one.
	 *
	 * @param array<string,array> $shipments
	 * @param array $record Validated ShipmentRecord.
	 * @return array{shipments:array<string,array>,changed:bool,added:string[]}
	 */
	public static function apply_b2_record(array $shipments, array $record, int $now): array {
		$added = array();
		$changed = false;
		foreach ($record['packages'] as $package) {
			$key = self::key($package['carrier'], $package['tn']);
			$is_new = !isset($shipments[$key]);
			$shipment = $is_new ? self::blank($package['carrier'], $package['tn'], 'b2sign', $now) : $shipments[$key];
			$before = $is_new ? null : self::material($shipment);
			$shipment['source'] = 'b2sign';
			if ('' !== $package['url']) {
				$shipment['tracking_url'] = $package['url'];
			}
			$shipment['b2'] = array(
				'order_number' => $record['order_number'],
				'item_id' => $record['item_id'],
				'jobid' => $record['jobid'],
				'item_status' => $record['status'],
				'service' => $record['service'],
				'is_reprint' => $record['is_reprint'],
				'arriving_at' => $record['arriving_at'],
				'scheduled' => $package['scheduled'],
				'delivered' => $package['delivered'],
			);
			$shipment = self::apply_b2_dates($shipment, $now);
			if ($is_new || self::material($shipment) !== $before) {
				$shipment['updated_at'] = $now;
				$changed = true;
			}
			if ($is_new) {
				$added[] = $key;
			}
			$shipments[$key] = $shipment;
		}
		return array('shipments' => $shipments, 'changed' => $changed, 'added' => $added);
	}

	/**
	 * Merges one carrier reading (CarrierReading::build()). A reading not newer than the last one is ignored.
	 *
	 * @param int|null $next Next check time, null to stop checking.
	 * @return array{shipment:array,changed:bool,from:string,to:string}
	 */
	public static function apply_reading(array $shipment, array $reading, int $checked_at, int $now, ?int $next): array {
		$from = (string) $shipment['status'];
		if ($checked_at <= self::last_touch($shipment)) {
			return array('shipment' => $shipment, 'changed' => false, 'from' => $from, 'to' => $from);
		}
		$before = self::material($shipment);
		$shipment['status'] = $reading['status'];
		$shipment['status_source'] = $reading['status_source'];
		if ('delivered' === $reading['status']) {
			if (null !== $reading['delivered_at'] && (null !== $reading['delivered_at']['local'] || null === $shipment['delivered_at'])) {
				$shipment['delivered_at'] = $reading['delivered_at'];
			}
			$shipment['eta'] = null;
		} elseif (null !== $reading['eta']) {
			$shipment['eta'] = $reading['eta'];
		}
		if ($reading['events']) {
			$shipment['events'] = self::merge_events($shipment['events'], $reading['events']);
		}
		$shipment['last_event'] = $shipment['events'][0] ?? null;
		$shipment['unknown_wording'] = $reading['unknown'];
		$shipment['parser_version'] = $reading['parser_version'];
		$shipment['check_errors'] = 0;
		$shipment['last_error'] = '';
		$shipment['last_checked_at'] = $checked_at;
		$shipment['last_attempt_at'] = $checked_at;
		$shipment['next_check_at'] = null === $next ? 0 : $next;
		$shipment['priority_until'] = 0;
		if (self::material($shipment) !== $before) {
			$shipment['updated_at'] = $now;
		}
		return array('shipment' => $shipment, 'changed' => true, 'from' => $from, 'to' => (string) $shipment['status']);
	}

	/**
	 * Records a failed reading. A blocked page is the carrier's doing, not the package's, so it does not count toward
	 * the error limit. "Updated N ago" keeps showing the last successful reading.
	 *
	 * @return array{shipment:array,changed:bool}
	 */
	public static function apply_error(array $shipment, string $code, string $message, int $checked_at, ?int $next): array {
		if ($checked_at <= self::last_touch($shipment)) {
			return array('shipment' => $shipment, 'changed' => false);
		}
		$shipment['last_attempt_at'] = $checked_at;
		$shipment['last_error'] = $code . ('' === $message ? '' : ': ' . $message);
		if ('blocked' !== $code) {
			$shipment['check_errors'] = (int) $shipment['check_errors'] + 1;
		}
		$shipment['next_check_at'] = null === $next ? 0 : $next;
		$shipment['priority_until'] = 0;
		return array('shipment' => $shipment, 'changed' => true);
	}

	/**
	 * Scans newest first, without duplicates, at most MAX_EVENTS. A reading in a different time basis replaces the
	 * list instead of mixing two clocks.
	 */
	public static function merge_events(array $old, array $new): array {
		if (!$new) {
			return $old;
		}
		if ($old && ($old[0]['basis'] ?? '') !== ($new[0]['basis'] ?? '')) {
			return array_slice($new, 0, self::MAX_EVENTS);
		}
		$seen = array();
		$merged = array();
		foreach (array_merge($new, $old) as $event) {
			$key = self::event_key($event);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$merged[] = $event;
		}
		usort($merged, static function ($a, $b) {
			$a_local = (string) ($a['local'] ?? '');
			$b_local = (string) ($b['local'] ?? '');
			if ('' === $a_local || '' === $b_local) {
				return ('' === $a_local) <=> ('' === $b_local);
			}
			return strcmp($b_local, $a_local);
		});
		return array_slice($merged, 0, self::MAX_EVENTS);
	}

	/**
	 * The shipment without the fields that change on every check, for change detection.
	 */
	public static function material(array $shipment): array {
		foreach (self::VOLATILE as $field) {
			unset($shipment[$field]);
		}
		return $shipment;
	}

	/**
	 * Totals across an order's packages.
	 *
	 * @return array{total:int,delivered:int,all_delivered:bool,issue:bool,next_eta:string}
	 */
	public static function summary(array $shipments): array {
		$delivered = 0;
		$issue = false;
		$etas = array();
		foreach ($shipments as $shipment) {
			if ('delivered' === $shipment['status']) {
				$delivered++;
			} elseif (!empty($shipment['eta']['date'])) {
				$etas[] = (string) $shipment['eta']['date'];
			}
			if (in_array($shipment['status'], array('exception', 'returned'), true)) {
				$issue = true;
			}
		}
		sort($etas);
		$total = count($shipments);
		return array(
			'total' => $total,
			'delivered' => $delivered,
			'all_delivered' => $total > 0 && $delivered === $total,
			'issue' => $issue,
			'next_eta' => $etas[0] ?? '',
		);
	}

	private static function last_touch(array $shipment): int {
		return max((int) ($shipment['last_checked_at'] ?? 0), (int) ($shipment['last_attempt_at'] ?? 0));
	}

	private static function event_key(array $event): string {
		$when = (string) ($event['local'] ?? '');
		if ('' === $when) {
			$when = $event['date_text'] . ' ' . $event['time_text'];
		}
		return Text::phrase($when) . '|' . Text::phrase((string) $event['status_text']) . '|' . Text::phrase((string) $event['location_text']);
	}

	private static function apply_b2_dates(array $shipment, int $now): array {
		$b2 = $shipment['b2'];
		if ('' !== (string) $b2['delivered'] && 'delivered' !== $shipment['status']) {
			$shipment['status'] = 'delivered';
			$shipment['status_source'] = 'b2sign';
			$shipment['delivered_at'] = array(
				'local' => B2DateParser::loose((string) $b2['delivered'], $now, 'past'),
				'text' => (string) $b2['delivered'],
				'source' => 'b2sign',
			);
			$shipment['eta'] = null;
			$shipment['next_check_at'] = 0;
		}
		$eta_source = (string) ($shipment['eta']['source'] ?? '');
		if ('delivered' !== $shipment['status'] && 'carrier' !== $eta_source && 'transit' !== $eta_source) {
			$eta = null;
			$scheduled = '' === (string) $b2['scheduled'] ? null : B2DateParser::machine((string) $b2['scheduled']);
			$arriving = null === $scheduled && '' !== (string) $b2['arriving_at'] ? B2DateParser::loose((string) $b2['arriving_at'], $now, 'future') : null;
			if (null !== $scheduled) {
				$eta = array('date' => substr($scheduled, 0, 10), 'window' => '', 'source' => 'b2sign', 'text' => (string) $b2['scheduled']);
			} elseif (null !== $arriving) {
				$eta = array('date' => substr($arriving, 0, 10), 'window' => '', 'source' => 'b2sign', 'text' => (string) $b2['arriving_at']);
			}
			$shipment['eta'] = $eta;
		}
		return $shipment;
	}
}

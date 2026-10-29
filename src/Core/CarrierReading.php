<?php
/**
 * Turns one validated CarrierResult into what a shipment stores: status, estimate, delivery time and scans.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class CarrierReading {
	/** Our own events (and scans whose page showed browser time) are shown in site time. */
	public const SITE_ZONE = 'America/Los_Angeles';

	/**
	 * @param array $result Validated CarrierResult with ok = true (events newest first).
	 * @return array{status:string,status_source:string,unknown:string[],eta:?array,delivered_at:?array,events:array,parser_version:string}
	 */
	public static function build(array $result, string $previous_status, int $now): array {
		$decision = CarrierTextNormalizer::normalize($result, $previous_status);
		$events = self::events($result);
		$facts = (array) ($result['facts'] ?? array());

		$eta = null;
		$estimated = (string) ($facts['ESTIMATED DELIVERY DATE'] ?? '');
		$transit = (string) ($facts['STANDARD TRANSIT'] ?? '');
		$eta_text = (string) ($result['eta_text'] ?? '');
		if ('' !== $estimated && null !== ($date = CarrierDateParser::date($estimated))) {
			$between = (string) ($facts['ESTIMATED BETWEEN'] ?? '');
			$eta = array('date' => $date, 'window' => CarrierDateParser::window($between), 'source' => 'carrier', 'text' => trim($estimated . ' ' . $between));
		} elseif ('' !== $eta_text && null !== ($parsed = CarrierDateParser::eta($eta_text, $now, self::SITE_ZONE))) {
			$eta = array('date' => $parsed['date'], 'window' => $parsed['window'], 'source' => 'carrier', 'text' => $eta_text);
		} elseif ('' !== $transit && null !== ($date = CarrierDateParser::date($transit))) {
			$eta = array('date' => $date, 'window' => CarrierDateParser::window($transit), 'source' => 'transit', 'text' => $transit);
		}

		$delivered = null;
		if ('delivered' === $decision['status']) {
			$text = (string) ($result['delivered_text'] ?? '');
			if ('' === $text) {
				$text = (string) ($facts['DELIVERED'] ?? '');
			}
			$local = '' === $text ? null : CarrierDateParser::date_time($text);
			if (null === $local) {
				foreach ($events as $event) {
					if ('delivered' === $event['kind']) {
						$local = $event['local'];
						break;
					}
				}
			}
			$delivered = array('local' => $local, 'text' => $text, 'source' => 'carrier');
		}

		return array(
			'status' => $decision['status'],
			'status_source' => $decision['source'],
			'unknown' => $decision['unknown'],
			'eta' => $eta,
			'delivered_at' => $delivered,
			'events' => $events,
			'parser_version' => (string) ($result['parser_version'] ?? ''),
		);
	}

	/**
	 * Stored scans, newest first. Scans the page showed in the browser's zone (FedEx's mobile list) are converted to
	 * site time, because the computer running the extension is not in the shipment's zone.
	 *
	 * @return array<int,array>
	 */
	public static function events(array $result): array {
		$basis = (string) ($result['time_basis'] ?? 'event_local');
		$zone = 'browser' === $basis ? (string) ($result['browser_tz'] ?? '') : '';
		$events = array();
		foreach ((array) ($result['events'] ?? array()) as $event) {
			$local = CarrierDateParser::event((string) $event['date_text'], (string) $event['time_text']);
			$utc = null;
			$stored_basis = 'browser' === $basis ? 'browser' : 'event_local';
			if ('browser' === $basis && null !== $local && strlen($local) > 10 && '' !== $zone) {
				$utc = CarrierDateParser::to_utc($local, $zone);
				if (null !== $utc) {
					$local = CarrierDateParser::local($utc, self::SITE_ZONE);
					$stored_basis = 'site';
				}
			}
			$events[] = array(
				'local' => $local,
				'utc' => $utc,
				'basis' => $stored_basis,
				'date_text' => (string) $event['date_text'],
				'time_text' => (string) $event['time_text'],
				'status_text' => (string) $event['status_text'],
				'detail_text' => (string) $event['detail_text'],
				'location_text' => (string) $event['location_text'],
				'kind' => CarrierTextNormalizer::event_kind($event),
			);
		}
		return $events;
	}
}

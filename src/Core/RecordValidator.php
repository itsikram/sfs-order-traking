<?php
/**
 * Validates and cleans the body of POST /sfs-tracking/v1/sync. One bad record or carrier result is rejected on its
 * own; it never fails the batch.
 *
 * ShipmentRecord (one B2Sign item): order_number, order_id, item_id, jobid, jobname, item_title, quantity, status,
 * status_time, updated_at, purchased, arriving_at, due_date, service, is_reprint,
 * ship_to {fullname, company, city, state, zipcode}, packages [{carrier, tn, url, scheduled, delivered}].
 *
 * CarrierResult: carrier, tracking_number, checked_at, ok, headline, stepper [{label, active}], eta_text,
 * delivered_text, service_text, facts {LABEL: value}, events [{date_text, time_text, status_text, detail_text,
 * location_text}] (newest first), source, parser_version, time_basis, browser_tz, page_kind, error {code, message}.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class RecordValidator {
	public const MAX_BODY = 1048576;
	public const MAX_RECORDS = 300;
	public const MAX_RESULTS = 100;
	public const MAX_PACKAGES = 20;
	public const MAX_EVENTS = 150;
	public const ERROR_CODES = array('blocked', 'not_found', 'timeout', 'parse_failed', 'mismatch');

	/**
	 * @param mixed $data Decoded JSON body.
	 * @return array{ok:bool,error:string,client:array{version:string,env:string},run_id:string,full:bool,records:array,results:array,rejected:array}
	 */
	public static function sync_body($data, int $now): array {
		$out = array('ok' => false, 'error' => '', 'client' => array('version' => '', 'env' => ''), 'run_id' => '', 'full' => false, 'records' => array(), 'results' => array(), 'rejected' => array());
		if (!is_array($data) || ($data && self::is_list($data))) {
			$out['error'] = 'The body must be a JSON object.';
			return $out;
		}
		$records = $data['records'] ?? array();
		$results = $data['carrier_results'] ?? array();
		if (!is_array($records) || !is_array($results) || !self::is_list($records) || !self::is_list($results)) {
			$out['error'] = 'records and carrier_results must be arrays.';
			return $out;
		}
		if (count($records) > self::MAX_RECORDS) {
			$out['error'] = 'Too many records (max ' . self::MAX_RECORDS . ').';
			return $out;
		}
		if (count($results) > self::MAX_RESULTS) {
			$out['error'] = 'Too many carrier results (max ' . self::MAX_RESULTS . ').';
			return $out;
		}

		$client = is_array($data['client'] ?? null) ? $data['client'] : array();
		$version = Text::line($client['version'] ?? '', 20);
		$out['client'] = array(
			'version' => preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) ? $version : '',
			'env' => in_array($client['env'] ?? '', array('live', 'local'), true) ? (string) $client['env'] : '',
		);
		$run_id = is_string($data['run_id'] ?? null) ? $data['run_id'] : '';
		$out['run_id'] = preg_match('/^[A-Za-z0-9_-]{8,64}$/', $run_id) ? $run_id : '';
		$out['full'] = true === ($data['full'] ?? false);

		$seen = array();
		foreach ($records as $index => $raw) {
			list($record, $error) = self::record($raw);
			if (null === $record) {
				$out['rejected'][] = array('type' => 'record', 'index' => $index, 'item_id' => is_array($raw) ? (int) ($raw['item_id'] ?? 0) : 0, 'message' => $error);
				continue;
			}
			$seen[$record['item_id']] = $record;
		}
		$out['records'] = array_values($seen);

		foreach ($results as $index => $raw) {
			list($result, $error) = self::carrier_result($raw, $now);
			if (null === $result) {
				$out['rejected'][] = array('type' => 'carrier_result', 'index' => $index, 'tracking_number' => is_array($raw) ? Text::line($raw['tracking_number'] ?? '', 40) : '', 'message' => $error);
				continue;
			}
			$out['results'][] = $result;
		}
		$out['ok'] = true;
		return $out;
	}

	/**
	 * @param mixed $raw
	 * @return array{0:array|null,1:string}
	 */
	public static function record($raw): array {
		if (!is_array($raw)) {
			return array(null, 'not an object');
		}
		$order_number = strtoupper(Text::line($raw['order_number'] ?? '', 12));
		if (!OrderNumberExtractor::is_b2_number($order_number)) {
			return array(null, 'bad order_number');
		}
		$item_id = self::positive_int($raw['item_id'] ?? null);
		if (!$item_id) {
			return array(null, 'bad item_id');
		}
		$status = strtolower(Text::line($raw['status'] ?? '', 40));
		if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $status)) {
			return array(null, 'bad status');
		}
		$jobid = strtolower(Text::line($raw['jobid'] ?? '', 20));
		if ('' !== $jobid && !preg_match('/^[a-z][0-9]{7}-[0-9]{1,3}$/', $jobid)) {
			$jobid = '';
		}
		$ship_to = is_array($raw['ship_to'] ?? null) ? $raw['ship_to'] : array();

		$packages = array();
		$raw_packages = is_array($raw['packages'] ?? null) ? $raw['packages'] : array();
		foreach (array_slice($raw_packages, 0, self::MAX_PACKAGES) as $raw_package) {
			$package = self::package($raw_package);
			if (null !== $package) {
				$packages[self::package_key($package)] = $package;
			}
		}

		return array(
			array(
				'order_number' => $order_number,
				'order_id' => self::positive_int($raw['order_id'] ?? null),
				'item_id' => $item_id,
				'jobid' => $jobid,
				'jobname' => Text::line($raw['jobname'] ?? '', 200),
				'item_title' => Text::line($raw['item_title'] ?? '', 200),
				'quantity' => max(0, min(100000, (int) (is_numeric($raw['quantity'] ?? null) ? $raw['quantity'] : 0))),
				'status' => $status,
				'status_time' => Text::line($raw['status_time'] ?? '', 40),
				'updated_at' => Text::line($raw['updated_at'] ?? '', 40),
				'purchased' => Text::line($raw['purchased'] ?? '', 40),
				'arriving_at' => Text::line($raw['arriving_at'] ?? '', 40),
				'due_date' => Text::line($raw['due_date'] ?? '', 40),
				'service' => Text::line($raw['service'] ?? '', 80),
				'is_reprint' => true === ($raw['is_reprint'] ?? false),
				'ship_to' => array(
					'fullname' => Text::line($ship_to['fullname'] ?? '', 120),
					'company' => Text::line($ship_to['company'] ?? '', 120),
					'city' => Text::line($ship_to['city'] ?? '', 80),
					'state' => Text::line($ship_to['state'] ?? '', 40),
					'zipcode' => Text::line($ship_to['zipcode'] ?? '', 12),
				),
				'packages' => array_values($packages),
			),
			'',
		);
	}

	/**
	 * @param mixed $raw
	 */
	public static function package($raw): ?array {
		if (!is_array($raw)) {
			return null;
		}
		$carrier = strtolower(Text::line($raw['carrier'] ?? '', 10));
		$number = TrackingUrls::normalize_number(Text::line($raw['tn'] ?? '', 40));
		if (!TrackingUrls::valid_number($carrier, $number)) {
			return null;
		}
		$url = Text::line($raw['url'] ?? '', 500);
		return array(
			'carrier' => $carrier,
			'tn' => $number,
			// A link that is not the carrier's own page for this number is dropped; the plugin builds its own.
			'url' => TrackingUrls::allowed($carrier, $number, $url) ? $url : '',
			'scheduled' => Text::line($raw['scheduled'] ?? '', 40),
			'delivered' => Text::line($raw['delivered'] ?? '', 40),
		);
	}

	/**
	 * @param mixed $raw
	 * @return array{0:array|null,1:string}
	 */
	public static function carrier_result($raw, int $now): array {
		if (!is_array($raw)) {
			return array(null, 'not an object');
		}
		$carrier = strtolower(Text::line($raw['carrier'] ?? '', 10));
		if (!TrackingUrls::checkable($carrier)) {
			return array(null, 'bad carrier');
		}
		$number = TrackingUrls::normalize_number(Text::line($raw['tracking_number'] ?? '', 40));
		if (!TrackingUrls::valid_number($carrier, $number)) {
			return array(null, 'bad tracking_number');
		}
		$checked_at = self::time($raw['checked_at'] ?? null);
		if (null === $checked_at || $checked_at < $now - 7 * 86400 || $checked_at > $now + 600) {
			return array(null, 'bad checked_at');
		}
		$ok = true === ($raw['ok'] ?? false);
		$error = null;
		if (!$ok) {
			$raw_error = is_array($raw['error'] ?? null) ? $raw['error'] : array();
			$code = Text::line($raw_error['code'] ?? '', 20);
			if (!in_array($code, self::ERROR_CODES, true)) {
				return array(null, 'failed result without a known error code');
			}
			$error = array('code' => $code, 'message' => Text::line($raw_error['message'] ?? '', 300));
		}

		$stepper = array();
		foreach (array_slice(is_array($raw['stepper'] ?? null) ? $raw['stepper'] : array(), 0, 10) as $step) {
			if (is_array($step) && '' !== ($label = Text::line($step['label'] ?? '', 60))) {
				$stepper[] = array('label' => $label, 'active' => true === ($step['active'] ?? false));
			}
		}

		$facts = array();
		foreach (array_slice(is_array($raw['facts'] ?? null) ? $raw['facts'] : array(), 0, 20, true) as $label => $value) {
			$label = strtoupper(Text::line((string) $label, 40));
			if (preg_match('/^[A-Z0-9][A-Z0-9 \/&().\'-]{0,39}$/', $label) && '' !== ($value = Text::line($value, 120))) {
				$facts[$label] = $value;
			}
		}

		$events = array();
		foreach (array_slice(is_array($raw['events'] ?? null) ? $raw['events'] : array(), 0, self::MAX_EVENTS) as $event) {
			if (!is_array($event) || '' === ($status = Text::line($event['status_text'] ?? '', 200))) {
				continue;
			}
			$events[] = array(
				'date_text' => Text::line($event['date_text'] ?? '', 40),
				'time_text' => Text::line($event['time_text'] ?? '', 20),
				'status_text' => $status,
				'detail_text' => Text::line($event['detail_text'] ?? '', 300),
				'location_text' => Text::line($event['location_text'] ?? '', 120),
			);
		}

		$basis = in_array($raw['time_basis'] ?? '', array('event_local', 'browser', 'unknown'), true) ? (string) $raw['time_basis'] : 'unknown';
		$zone = Text::line($raw['browser_tz'] ?? '', 64);
		if ('' !== $zone && !in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
			$zone = '';
		}
		$parser = Text::line($raw['parser_version'] ?? '', 20);

		return array(
			array(
				'carrier' => $carrier,
				'tracking_number' => $number,
				'checked_at' => min($checked_at, $now),
				'ok' => $ok,
				'error' => $error,
				'headline' => Text::line($raw['headline'] ?? '', 200),
				'stepper' => $stepper,
				'eta_text' => Text::line($raw['eta_text'] ?? '', 300),
				'delivered_text' => Text::line($raw['delivered_text'] ?? '', 200),
				'service_text' => Text::line($raw['service_text'] ?? '', 100),
				'facts' => $facts,
				'events' => $events,
				'source' => in_array($raw['source'] ?? '', array('dom', 'json'), true) ? (string) $raw['source'] : 'dom',
				'parser_version' => preg_match('/^[A-Za-z0-9._-]{1,20}$/', $parser) ? $parser : '',
				'time_basis' => $basis,
				'browser_tz' => $zone,
				'page_kind' => Text::line($raw['page_kind'] ?? '', 20),
			),
			'',
		);
	}

	private static function package_key(array $package): string {
		return $package['carrier'] . ':' . $package['tn'];
	}

	/**
	 * @param mixed $value
	 */
	private static function positive_int($value): int {
		if (is_int($value)) {
			return $value > 0 ? $value : 0;
		}
		if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value)) {
			return (int) $value;
		}
		return 0;
	}

	/**
	 * Unix seconds from an integer or an ISO 8601 string.
	 *
	 * @param mixed $value
	 */
	private static function time($value): ?int {
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(?::[0-9]{2}(?:\.[0-9]+)?)?(?:Z|[+-][0-9]{2}:[0-9]{2})$/', $value)) {
			try {
				return (new \DateTimeImmutable($value))->getTimestamp();
			} catch (\Exception $e) {
				return null;
			}
		}
		return null;
	}

	private static function is_list(array $value): bool {
		$i = 0;
		foreach ($value as $key => $unused) {
			if ($key !== $i++) {
				return false;
			}
		}
		return true;
	}
}

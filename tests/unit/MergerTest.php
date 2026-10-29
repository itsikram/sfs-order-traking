<?php
declare(strict_types=1);

namespace SFS\OrderTracking\Tests\Unit;

use SFS\OrderTracking\Core\CarrierReading;
use SFS\OrderTracking\Core\RecordValidator;
use SFS\OrderTracking\Core\ShipmentMerger as M;
use SFS\OrderTracking\Tests\TestCase;

final class MergerTest extends TestCase {
	private const UPS = '1Z999AA10123456784';
	private const FEDEX = '123456789012';

	private static function now(): int {
		return (new \DateTimeImmutable('2026-10-29 10:00', new \DateTimeZone('America/Los_Angeles')))->getTimestamp();
	}

	public static function record(array $overrides = array(), ?array $packages = null): array {
		$raw = array_merge(array(
			'order_number' => 'B2612345',
			'item_id' => 501,
			'jobid' => 'b2612345-01',
			'jobname' => 'SN6LTHF5IA',
			'item_title' => '13oz Vinyl Banner',
			'quantity' => 1,
			'status' => 'shipped',
			'status_time' => 'Oct 27',
			'arriving_at' => 'Fri, Oct 30',
			'service' => 'UPS Ground',
			'is_reprint' => false,
			'ship_to' => array('fullname' => 'Test Person', 'company' => '', 'city' => 'Lakeview', 'state' => 'LA', 'zipcode' => '70001'),
			'packages' => $packages ?? array(array('carrier' => 'ups', 'tn' => self::UPS, 'url' => 'https://wwwapps.ups.com/WebTracking/track?track=yes&trackNums=' . self::UPS . '&loc=en_US', 'scheduled' => '2026-10-30 17:30:00')),
		), $overrides);
		list($record) = RecordValidator::record($raw);
		return $record;
	}

	public static function reading(string $status_text, string $checked = '', array $extra = array()): array {
		return array_merge(array(
			'carrier' => 'ups',
			'tracking_number' => self::UPS,
			'headline' => $status_text,
			'stepper' => array(),
			'eta_text' => '',
			'delivered_text' => '',
			'facts' => array(),
			'events' => array(
				array('date_text' => '10/28/2026', 'time_text' => '8:28 P.M.', 'status_text' => $status_text, 'detail_text' => 'Arrived at Facility', 'location_text' => 'Anytown, PA, United States'),
				array('date_text' => '10/27/2026', 'time_text' => '11:50 A.M.', 'status_text' => 'Label Created', 'detail_text' => '', 'location_text' => 'United States'),
			),
			'parser_version' => 'ups-1',
			'time_basis' => 'event_local',
			'browser_tz' => '',
		), $extra);
	}

	public function test_new_b2sign_package_and_idempotent_replay(): void {
		$now = self::now();
		$first = M::apply_b2_record(array(), self::record(), $now);
		$key = 'ups:' . self::UPS;
		$this->assertTrue($first['changed']);
		$this->assertSame(array($key), $first['added']);
		$shipment = $first['shipments'][$key];
		$this->assertSame('b2sign', $shipment['source']);
		$this->assertSame('unknown', $shipment['status']);
		$this->assertSame($now, $shipment['next_check_at'], 'checked on the next run');
		$this->assertSame(array('date' => '2026-10-30', 'window' => '', 'source' => 'b2sign', 'text' => '2026-10-30 17:30:00'), $shipment['eta']);
		$this->assertSame('B2612345', $shipment['b2']['order_number']);
		$this->assertSame(501, $shipment['b2']['item_id']);
		$this->assertStringContains('wwwapps.ups.com', $shipment['tracking_url']);

		$again = M::apply_b2_record($first['shipments'], self::record(), $now + 600);
		$this->assertFalse($again['changed'], 'same record, no change');
		$this->assertSame(array(), $again['added']);
		$this->assertSame($first['shipments'], $again['shipments'], 'not even updated_at moves');
	}

	public function test_reprint_adds_a_second_package_and_keeps_the_first_primary(): void {
		$now = self::now();
		$one = M::apply_b2_record(array(), self::record(), $now)['shipments'];
		$reprint = self::record(array('item_id' => 777, 'jobid' => 'b2619999-01', 'order_number' => 'B2619999', 'is_reprint' => true), array(array('carrier' => 'fedex', 'tn' => self::FEDEX, 'url' => 'https://evil.example/' . self::FEDEX)));
		$two = M::apply_b2_record($one, $reprint, $now + 60);
		$this->assertSame(array('fedex:' . self::FEDEX), $two['added']);
		$this->assertSame(array('ups:' . self::UPS, 'fedex:' . self::FEDEX), array_keys($two['shipments']));
		$this->assertSame('', $two['shipments']['fedex:' . self::FEDEX]['tracking_url'], 'a foreign link is never stored');
		$this->assertTrue($two['shipments']['fedex:' . self::FEDEX]['b2']['is_reprint']);
	}

	public function test_b2sign_delivered_date_stops_checks(): void {
		$now = self::now();
		$record = self::record(array(), array(array('carrier' => 'fedex', 'tn' => self::FEDEX, 'delivered' => 'Oct 26')));
		$shipment = M::apply_b2_record(array(), $record, $now)['shipments']['fedex:' . self::FEDEX];
		$this->assertSame('delivered', $shipment['status']);
		$this->assertSame('b2sign', $shipment['status_source']);
		$this->assertSame(array('local' => '2026-10-26', 'text' => 'Oct 26', 'source' => 'b2sign'), $shipment['delivered_at']);
		$this->assertNull($shipment['eta']);
		$this->assertSame(0, $shipment['next_check_at']);
	}

	public function test_carrier_estimate_is_not_replaced_by_b2sign(): void {
		$now = self::now();
		$key = 'ups:' . self::UPS;
		$shipments = M::apply_b2_record(array(), self::record(), $now)['shipments'];
		$shipments[$key]['eta'] = array('date' => '2026-10-31', 'window' => 'by end of day', 'source' => 'carrier', 'text' => 'x');
		$after = M::apply_b2_record($shipments, self::record(array('arriving_at' => 'Mon, Nov 2')), $now + 60);
		$this->assertSame('2026-10-31', $after['shipments'][$key]['eta']['date']);
	}

	public function test_carrier_reading_is_merged_once(): void {
		$now = self::now();
		$key = 'ups:' . self::UPS;
		$shipment = M::apply_b2_record(array(), self::record(), $now)['shipments'][$key];
		$reading = CarrierReading::build(self::reading('We Have Your Package'), $shipment['status'], $now);
		$merged = M::apply_reading($shipment, $reading, $now + 30, $now + 40, $now + 3600);
		$this->assertTrue($merged['changed']);
		$this->assertSame('unknown', $merged['from']);
		$this->assertSame('in_transit', $merged['to']);
		$s = $merged['shipment'];
		$this->assertSame($now + 30, $s['last_checked_at'], 'freshness comes from the real check time');
		$this->assertSame($now + 3600, $s['next_check_at']);
		$this->assertCount(2, $s['events']);
		$this->assertSame('2026-10-28 20:28', $s['last_event']['local']);
		$this->assertSame('in_transit', $s['last_event']['kind']);

		$replay = M::apply_reading($s, $reading, $now + 30, $now + 99, $now + 7200);
		$this->assertFalse($replay['changed'], 'same check again changes nothing');
		$this->assertSame($s, $replay['shipment']);
		$older = M::apply_reading($s, $reading, $now + 10, $now + 99, $now + 7200);
		$this->assertFalse($older['changed'], 'an older reading never overwrites a newer one');
	}

	public function test_delivered_reading_clears_estimate_and_stops(): void {
		$now = self::now();
		$shipment = M::apply_b2_record(array(), self::record(), $now)['shipments']['ups:' . self::UPS];
		$reading = CarrierReading::build(self::reading('Delivered', '', array('delivered_text' => '10/30/2026 at 2:15 P.M.')), 'in_transit', $now);
		$merged = M::apply_reading($shipment, $reading, $now + 30, $now + 30, null);
		$this->assertSame('delivered', $merged['to']);
		$this->assertNull($merged['shipment']['eta']);
		$this->assertSame('2026-10-30 14:15', $merged['shipment']['delivered_at']['local']);
		$this->assertSame(0, $merged['shipment']['next_check_at']);
	}

	public function test_events_dedupe_sort_cap_and_basis_switch(): void {
		$old = array(
			array('local' => '2026-10-27 11:50', 'basis' => 'event_local', 'date_text' => '10/27/2026', 'time_text' => '11:50 A.M.', 'status_text' => 'Label Created', 'detail_text' => '', 'location_text' => 'United States', 'kind' => 'pre_transit'),
		);
		$new = array(
			array('local' => '2026-10-28 20:28', 'basis' => 'event_local', 'date_text' => '10/28/2026', 'time_text' => '8:28 P.M.', 'status_text' => 'We Have Your Package', 'detail_text' => '', 'location_text' => 'Anytown, PA', 'kind' => 'in_transit'),
			array('local' => '2026-10-27 11:50', 'basis' => 'event_local', 'date_text' => '10/27/2026', 'time_text' => '11:50 A.M.', 'status_text' => 'LABEL CREATED', 'detail_text' => '', 'location_text' => 'united states', 'kind' => 'pre_transit'),
		);
		$merged = M::merge_events($old, $new);
		$this->assertCount(2, $merged, 'case differences are the same scan');
		$this->assertSame('2026-10-28 20:28', $merged[0]['local']);

		$many = array();
		for ($i = 0; $i < 120; $i++) {
			$many[] = array('local' => sprintf('2026-10-%02d %02d:00', 1 + intdiv($i, 24), $i % 24), 'basis' => 'event_local', 'date_text' => 'd' . $i, 'time_text' => '', 'status_text' => 'In transit', 'detail_text' => '', 'location_text' => 'X' . $i, 'kind' => 'in_transit');
		}
		$capped = M::merge_events(array(), array_reverse($many));
		$this->assertCount(M::MAX_EVENTS, $capped);
		$this->assertSame('2026-10-05 23:00', $capped[0]['local'], 'newest kept');

		$site = array(array('local' => '2026-10-28 15:54', 'basis' => 'site', 'date_text' => 'Thursday, 10/29/2026', 'time_text' => '4:54 AM', 'status_text' => 'Arrived at FedEx location', 'detail_text' => '', 'location_text' => 'X', 'kind' => 'in_transit'));
		$this->assertSame($site, M::merge_events($old, $site), 'a different clock replaces the list');
		$this->assertSame($old, M::merge_events($old, array()));
	}

	public function test_errors_count_except_blocks(): void {
		$now = self::now();
		$shipment = M::apply_b2_record(array(), self::record(), $now)['shipments']['ups:' . self::UPS];
		$shipment['priority_until'] = $now + 900;
		$blocked = M::apply_error($shipment, 'blocked', 'Access Denied', $now + 10, $now + 3600);
		$this->assertTrue($blocked['changed']);
		$this->assertSame(0, $blocked['shipment']['check_errors']);
		$this->assertSame(0, $blocked['shipment']['last_checked_at'], 'a failed check is not fresh data');
		$this->assertSame(0, $blocked['shipment']['priority_until']);
		$timeout = M::apply_error($blocked['shipment'], 'timeout', '', $now + 20, $now + 900);
		$this->assertSame(1, $timeout['shipment']['check_errors']);
		$this->assertSame('timeout', $timeout['shipment']['last_error']);
		$this->assertFalse(M::apply_error($timeout['shipment'], 'timeout', '', $now + 20, $now + 900)['changed'], 'replay');
		$ok = M::apply_reading($timeout['shipment'], CarrierReading::build(self::reading('On the Way'), 'unknown', $now), $now + 30, $now + 30, $now + 3600);
		$this->assertSame(0, $ok['shipment']['check_errors'], 'a good reading resets the count');
		$this->assertSame('', $ok['shipment']['last_error']);
	}

	public function test_summary(): void {
		$a = M::blank('ups', self::UPS, 'b2sign', 1);
		$a['status'] = 'delivered';
		$b = M::blank('fedex', self::FEDEX, 'b2sign', 1);
		$b['status'] = 'in_transit';
		$b['eta'] = array('date' => '2026-10-30');
		$c = M::blank('fedex', '123456789013', 'manual', 1);
		$c['status'] = 'exception';
		$c['eta'] = array('date' => '2026-10-29');
		$summary = M::summary(array($a, $b, $c));
		$this->assertSame(array('total' => 3, 'delivered' => 1, 'all_delivered' => false, 'issue' => true, 'next_eta' => '2026-10-29'), $summary);
		$this->assertTrue(M::summary(array($a))['all_delivered']);
		$this->assertFalse(M::summary(array())['all_delivered']);
	}
}

<?php
declare(strict_types=1);

namespace SFS\OrderTracking\Tests\Unit;

use SFS\OrderTracking\Core\CarrierReading;
use SFS\OrderTracking\Tests\TestCase;

/**
 * Readings shaped like what the extension parses from the saved UPS/FedEx snapshots.
 */
final class ReadingTest extends TestCase {
	private static function now(): int {
		return (new \DateTimeImmutable('2026-10-29 10:00', new \DateTimeZone('America/Los_Angeles')))->getTimestamp();
	}

	public function test_ups_we_have_your_package(): void {
		$reading = CarrierReading::build(array(
			'carrier' => 'ups',
			'headline' => 'We Have Your Package',
			'stepper' => array(array('label' => 'Label Created', 'active' => false), array('label' => 'We Have Your Package', 'active' => true)),
			'eta_text' => 'Your package is still on the way and we are actively working to get you a new delivery date.',
			'events' => array(
				array('date_text' => '10/28/2026', 'time_text' => '8:28 P.M.', 'status_text' => 'We Have Your Package', 'detail_text' => 'Arrived at Facility', 'location_text' => 'Anytown, PA, United States'),
				array('date_text' => '10/28/2026', 'time_text' => '2:07 P.M.', 'status_text' => 'Label Created', 'detail_text' => 'Shipper created a label, UPS has not received the package yet.', 'location_text' => 'United States'),
			),
			'parser_version' => 'ups-1',
			'time_basis' => 'event_local',
		), 'unknown', self::now());
		$this->assertSame('in_transit', $reading['status']);
		$this->assertNull($reading['eta'], 'no date in the update text');
		$this->assertNull($reading['delivered_at']);
		$this->assertSame('2026-10-28 20:28', $reading['events'][0]['local']);
		$this->assertSame('event_local', $reading['events'][0]['basis']);
		$this->assertNull($reading['events'][0]['utc'], 'scan zone unknown');
		$this->assertSame('pre_transit', $reading['events'][1]['kind']);
		$this->assertSame('ups-1', $reading['parser_version']);
	}

	public function test_fedex_in_transit_uses_the_estimate_facts(): void {
		$reading = CarrierReading::build(array(
			'carrier' => 'fedex',
			'headline' => 'On the way',
			'eta_text' => 'Friday Between 11:00 AM - 3:00 PM',
			'facts' => array('ESTIMATED DELIVERY DATE' => '10/30/2026', 'ESTIMATED BETWEEN' => '11:00am-3:00pm', 'SHIP DATE' => '10/28/2026'),
			'events' => array(
				array('date_text' => 'Wednesday, 10/28/26', 'time_text' => '6:54 PM', 'status_text' => 'Arrived at FedEx location', 'detail_text' => '', 'location_text' => 'SPRINGFIELD, PA'),
				array('date_text' => 'Wednesday, 10/28/26', 'time_text' => '12:00 AM', 'status_text' => 'Picked up', 'detail_text' => '', 'location_text' => 'ANYTOWN, PA'),
				array('date_text' => 'Tuesday, 10/27/26', 'time_text' => '2:17 PM', 'status_text' => 'Shipment information sent to FedEx', 'detail_text' => '', 'location_text' => ''),
			),
			'time_basis' => 'event_local',
		), 'unknown', self::now());
		$this->assertSame('in_transit', $reading['status']);
		$this->assertSame(array('date' => '2026-10-30', 'window' => '11:00 AM – 3:00 PM', 'source' => 'carrier', 'text' => '10/30/2026 11:00am-3:00pm'), $reading['eta']);
		$this->assertSame('2026-10-28 18:54', $reading['events'][0]['local']);
	}

	public function test_fedex_delivered(): void {
		$reading = CarrierReading::build(array(
			'carrier' => 'fedex',
			'headline' => 'Delivered',
			'delivered_text' => 'Monday 10/26/2026 at 5:48 pm',
			'facts' => array('STANDARD TRANSIT' => '10/26/2026', 'DELIVERED' => '10/26/2026 at 5:48 pm'),
			'events' => array(array('date_text' => 'Monday, 10/26/26', 'time_text' => '5:48 PM', 'status_text' => 'Delivered', 'detail_text' => 'Left at front door. Signature Service not requested.', 'location_text' => 'Lakeview, LA')),
			'time_basis' => 'event_local',
		), 'out_for_delivery', self::now());
		$this->assertSame('delivered', $reading['status']);
		$this->assertSame(array('local' => '2026-10-26 17:48', 'text' => 'Monday 10/26/2026 at 5:48 pm', 'source' => 'carrier'), $reading['delivered_at']);
		$this->assertSame('transit', $reading['eta']['source'], 'kept only for reference; merging clears it');
	}

	public function test_delivered_time_falls_back_to_the_delivered_scan(): void {
		$reading = CarrierReading::build(array(
			'carrier' => 'ups',
			'headline' => 'Delivered',
			'events' => array(array('date_text' => '10/30/2026', 'time_text' => '1:05 P.M.', 'status_text' => 'Delivered', 'detail_text' => 'DELIVERED', 'location_text' => 'Lakeview, LA, US')),
			'time_basis' => 'event_local',
		), 'out_for_delivery', self::now());
		$this->assertSame('2026-10-30 13:05', $reading['delivered_at']['local']);
	}

	public function test_browser_time_is_converted_to_site_time(): void {
		$reading = CarrierReading::build(array(
			'carrier' => 'fedex',
			'events' => array(array('date_text' => 'Thursday, 10/29/2026', 'time_text' => '4:54 AM', 'status_text' => 'Arrived at FedEx location', 'detail_text' => '', 'location_text' => 'X, PA')),
			'time_basis' => 'browser',
			'browser_tz' => 'Asia/Dhaka',
		), 'unknown', self::now());
		$event = $reading['events'][0];
		$this->assertSame('site', $event['basis']);
		$this->assertSame('2026-10-28 15:54', $event['local']);
		$this->assertSame(strtotime('2026-10-28 22:54:00 UTC'), $event['utc']);

		$unknown_zone = CarrierReading::build(array('carrier' => 'fedex', 'events' => array(array('date_text' => '10/29/2026', 'time_text' => '4:54 AM', 'status_text' => 'Picked up', 'detail_text' => '', 'location_text' => '')), 'time_basis' => 'browser', 'browser_tz' => ''), 'unknown', self::now());
		$this->assertSame('browser', $unknown_zone['events'][0]['basis'], 'left as shown when the zone is missing');
	}

	public function test_transit_estimate_when_nothing_better(): void {
		$reading = CarrierReading::build(array('carrier' => 'fedex', 'facts' => array('STANDARD TRANSIT' => '10/31/2026 by 8:00 pm'), 'events' => array(), 'time_basis' => 'event_local'), 'in_transit', self::now());
		$this->assertSame(array('date' => '2026-10-31', 'window' => 'by 8:00 PM', 'source' => 'transit', 'text' => '10/31/2026 by 8:00 pm'), $reading['eta']);
		$this->assertSame('in_transit', $reading['status'], 'no wording at all keeps the previous status');
	}
}

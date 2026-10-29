<?php
declare(strict_types=1);

namespace SFS\OrderTracking\Tests\Unit;

use SFS\OrderTracking\Core\CarrierTextNormalizer as N;
use SFS\OrderTracking\Tests\TestCase;

final class NormalizerTest extends TestCase {
	private static function result(array $overrides = array()): array {
		return array_merge(array(
			'carrier' => 'ups',
			'headline' => '',
			'stepper' => array(),
			'delivered_text' => '',
			'facts' => array(),
			'events' => array(),
		), $overrides);
	}

	private static function event(string $status, string $detail = '', string $location = ''): array {
		return array('date_text' => '10/28/2026', 'time_text' => '8:28 P.M.', 'status_text' => $status, 'detail_text' => $detail, 'location_text' => $location);
	}

	public function test_every_string_seen_on_2026_10_29(): void {
		$seen = array(
			'Label Created' => 'pre_transit',
			'Shipment information sent to FedEx' => 'pre_transit',
			'Shipper created a label, UPS has not received the package yet.' => 'pre_transit',
			'We Have Your Package' => 'in_transit',
			'Picked up' => 'in_transit',
			'On the Way' => 'in_transit',
			'On the way' => 'in_transit',
			'Arrived at Facility' => 'in_transit',
			'Arrived at FedEx location' => 'in_transit',
			'Departed FedEx location' => 'in_transit',
			'At local FedEx facility' => 'in_transit',
			'Out for Delivery' => 'out_for_delivery',
			'On FedEx vehicle for delivery' => 'out_for_delivery',
			'Delivered' => 'delivered',
		);
		foreach ($seen as $text => $status) {
			$this->assertSame($status, N::phrase_status($text), $text);
		}
	}

	public function test_whole_phrases_only(): void {
		$this->assertSame('exception', N::phrase_status('Delivery exception'), 'never read as delivered');
		$this->assertSame('delivered', N::phrase_status('DELIVERED'));
		$this->assertSame('delivered', N::phrase_status('  Delivered. '));
		$this->assertSame('delivered', N::phrase_status('Delivered to UPS Access Point™'));
		$this->assertSame('returned', N::phrase_status('Returning package to shipper'));
		$this->assertSame('cancelled', N::phrase_status('Shipment cancelled by sender'));
		$this->assertSame('exception', N::phrase_status('Customer not available or business closed'));
		$this->assertNull(N::phrase_status('Delivered on time, almost'));
		$this->assertNull(N::phrase_status('Left at front door. Signature Service not requested.'));
		$this->assertNull(N::phrase_status('Delivery'), 'only as a progress step');
		$this->assertSame('delivered', N::step_status('Delivery'));
		$this->assertNull(N::phrase_status(''));
		$this->assertSame('Delivery issue', N::label('exception'));
		$this->assertSame('Status unavailable', N::label('nonsense'));
		$this->assertSame('success', N::tone('delivered'));
	}

	public function test_ups_reading_uses_headline(): void {
		$decision = N::normalize(self::result(array(
			'headline' => 'We Have Your Package',
			'stepper' => array(array('label' => 'Label Created', 'active' => false), array('label' => 'We Have Your Package', 'active' => true), array('label' => 'On the Way', 'active' => false)),
			'events' => array(self::event('We Have Your Package', 'Arrived at Facility', 'Anytown, PA, United States'), self::event('Label Created', 'Shipper created a label, UPS has not received the package yet.', 'United States')),
		)));
		$this->assertSame('in_transit', $decision['status']);
		$this->assertSame('headline', $decision['source']);
		$this->assertSame(array(), $decision['unknown']);
	}

	public function test_delivered_fact_wins(): void {
		$this->assertSame('delivered', N::normalize(self::result(array('delivered_text' => '10/26/2026 at 5:48 pm', 'headline' => 'On the way')))['status']);
		$decision = N::normalize(self::result(array('facts' => array('DELIVERED' => '10/26/2026 at 5:48 pm'), 'events' => array(self::event('On FedEx vehicle for delivery')))));
		$this->assertSame('delivered', $decision['status']);
		$this->assertSame('fact', $decision['source']);
	}

	public function test_alarms_beat_the_headline(): void {
		$decision = N::normalize(self::result(array('headline' => 'On the Way', 'events' => array(self::event('Delivery exception', 'Customer not available or business closed')))));
		$this->assertSame('exception', $decision['status']);
		$this->assertSame('event', $decision['source']);
		$this->assertSame('exception', N::normalize(self::result(array('headline' => 'Exception', 'events' => array(self::event('On the way')))))['status']);
		$this->assertSame('returned', N::normalize(self::result(array('events' => array(self::event('Returning package to shipper')))))['status']);
		$this->assertSame('exception', N::normalize(self::result(array('events' => array(self::event('Unrecognised scan', 'Delivery attempted')))))['status'], 'an alarm in the detail line counts');
	}

	public function test_stepper_and_events_fill_in(): void {
		$step = N::normalize(self::result(array('stepper' => array(array('label' => 'Label Created', 'active' => false), array('label' => 'On the Way', 'active' => true)))));
		$this->assertSame('in_transit', $step['status']);
		$this->assertSame('step', $step['source']);

		$fedex = N::normalize(self::result(array('carrier' => 'fedex', 'events' => array(self::event('Arrived at FedEx location', '', 'SPRINGFIELD, PA'), self::event('Picked up'), self::event('Shipment information sent to FedEx')))));
		$this->assertSame('in_transit', $fedex['status']);
		$this->assertSame('event', $fedex['source']);

		$detail = N::normalize(self::result(array('events' => array(self::event('Odd milestone', 'Arrived at Facility')))));
		$this->assertSame('in_transit', $detail['status']);
		$this->assertSame('event_detail', $detail['source']);
		$this->assertSame(array('Odd milestone'), $detail['unknown']);
	}

	public function test_unknown_wording_keeps_previous_status(): void {
		$decision = N::normalize(self::result(array('headline' => 'Preparing to ship', 'events' => array(self::event('Mystery scan')))), 'in_transit');
		$this->assertSame('in_transit', $decision['status']);
		$this->assertSame('previous', $decision['source']);
		$this->assertSame(array('Preparing to ship', 'Mystery scan'), $decision['unknown']);
		$this->assertSame('unknown', N::normalize(self::result(), 'not-a-status')['status']);
		$this->assertSame('', N::active_step(array(array('label' => 'A', 'active' => false))));
	}
}

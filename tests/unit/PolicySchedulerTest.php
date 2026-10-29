<?php
declare(strict_types=1);

namespace SFS\OrderTracking\Tests\Unit;

use SFS\OrderTracking\Core\CheckScheduler as S;
use SFS\OrderTracking\Core\LocationPolicy as L;
use SFS\OrderTracking\Tests\TestCase;

final class PolicySchedulerTest extends TestCase {
	private static function event(string $status, string $location, string $detail = ''): array {
		return array('status_text' => $status, 'detail_text' => $detail, 'location_text' => $location);
	}

	public function test_destination_side_locations_only(): void {
		$to = array('city' => 'Lakeview', 'state' => 'LA');
		$this->assertFalse(L::visible('destination', self::event('Picked up', 'ANYTOWN, PA'), 'in_transit', $to), 'origin');
		$this->assertFalse(L::visible('destination', self::event('Arrived at FedEx location', 'SPRINGFIELD, PA'), 'in_transit', $to), 'hub');
		$this->assertFalse(L::visible('destination', self::event('We Have Your Package', 'Anytown, PA, United States', 'Arrived at Facility'), 'in_transit', $to));
		$this->assertTrue(L::visible('destination', self::event('At local FedEx facility', 'Lakeview, LA'), 'in_transit', $to));
		$this->assertTrue(L::visible('destination', self::event('On FedEx vehicle for delivery', 'Lakeview, LA'), 'out_for_delivery', $to));
		$this->assertTrue(L::visible('destination', self::event('Delivered', 'Lakeview, LA', 'Left at front door.'), 'delivered', $to));
		$this->assertTrue(L::visible('destination', self::event('Delivery exception', 'Lakeview, LA', 'Delivery attempted'), 'exception', $to), 'attempts are last mile');
		$this->assertTrue(L::visible('destination', self::event('Arrived at FedEx location', 'LAKEVIEW, LA'), 'in_transit', $to), 'a scan in the ship-to city');
		$this->assertFalse(L::visible('destination', self::event('Picked up', 'LAKEVIEW, LA'), 'in_transit', $to), 'an origin scan stays hidden even in the ship-to city');
		$this->assertFalse(L::visible('destination', self::event('Arrived at FedEx location', 'LAKEVIEW, MS'), 'in_transit', $to), 'same city name, other state');
		$this->assertFalse(L::visible('destination', self::event('Arrived at FedEx location', 'LAKEVIEW, LA'), 'in_transit', array()), 'no destination known');
		$this->assertFalse(L::visible('destination', self::event('Delivered', ''), 'delivered', $to), 'nothing to show');
		$this->assertFalse(L::visible('hide', self::event('Delivered', 'Lakeview, LA'), 'delivered', $to));
		$this->assertTrue(L::visible('all', self::event('Picked up', 'ANYTOWN, PA'), 'in_transit', $to));
		$this->assertSame(array('city' => 'anytown', 'state' => 'pa'), L::place('Anytown, PA, United States'));
		$this->assertSame(array('city' => 'lakeview', 'state' => 'la'), L::place('Lakeview, LA US'));
		$this->assertNull(L::place('United States'));
	}

	public function test_intervals_and_jitter(): void {
		$half = static function () {
			return 0.5;
		};
		$this->assertSame(1000 + 3600, S::next('in_transit', 1000, array(), $half));
		$this->assertSame(1000 + 10800, S::next('pre_transit', 1000, array(), $half));
		$this->assertSame(1000 + 1200, S::next('out_for_delivery', 1000, array(), $half));
		$this->assertSame(1000 + 3600, S::next('exception', 1000, array(), $half));
		$this->assertSame(1000 + 7200, S::next('unknown', 1000, array(), $half));
		$this->assertSame(1000 + 7200, S::next('nonsense', 1000, array(), $half));
		$this->assertNull(S::next('delivered', 1000));
		$this->assertNull(S::next('cancelled', 1000));
		$this->assertSame(1000 + 1080, S::next('out_for_delivery', 1000, array(), static function () {
			return 0.0;
		}), '-10%');
		$this->assertSame(1000 + 1320, S::next('out_for_delivery', 1000, array(), static function () {
			return 1.0;
		}), '+10%');
		$this->assertSame(1000 + 1800, S::next('in_transit', 1000, array('in_transit' => 30), $half), 'setting override');
		$this->assertSame(1000 + 300, S::next('in_transit', 1000, array('in_transit' => 1), $half), 'never under 5 minutes');
		for ($i = 0; $i < 50; $i++) {
			$this->assertBetween(1000 + 3240, 1000 + 3960, (int) S::next('in_transit', 1000));
		}
	}

	public function test_error_backoff(): void {
		$half = static function () {
			return 0.5;
		};
		$this->assertSame(900, S::after_error(1, 'in_transit', 0, array(), $half));
		$this->assertSame(1800, S::after_error(2, 'in_transit', 0, array(), $half));
		$this->assertSame(3600, S::after_error(3, 'in_transit', 0, array(), $half));
		$this->assertSame(3600, S::after_error(9, 'in_transit', 0, array(), $half), 'capped at the status interval');
		$this->assertSame(1200, S::after_error(2, 'out_for_delivery', 0, array(), $half));
		$this->assertSame(10800, S::after_error(9, 'pre_transit', 0, array(), $half));
	}

	public function test_stop_rules(): void {
		$now = 100 * 86400;
		$base = array('carrier' => 'ups', 'status' => 'in_transit', 'check_errors' => 0, 'created_at' => $now - 86400);
		$this->assertSame('', S::stop_reason($base, $now));
		$this->assertSame('delivered', S::stop_reason(array('status' => 'delivered') + $base, $now));
		$this->assertSame('cancelled', S::stop_reason(array('status' => 'cancelled') + $base, $now));
		$this->assertSame('errors', S::stop_reason(array('check_errors' => 10) + $base, $now));
		$this->assertSame('', S::stop_reason(array('check_errors' => 9) + $base, $now));
		$this->assertSame('age', S::stop_reason(array('created_at' => $now - 46 * 86400) + $base, $now));
		$this->assertSame('', S::stop_reason(array('created_at' => $now - 44 * 86400) + $base, $now));
		$this->assertSame('carrier', S::stop_reason(array('carrier' => 'usps') + $base, $now));
		$this->assertSame('carrier', S::stop_reason(array('carrier' => 'freight') + $base, $now));
	}

	public function test_selection_priority_then_most_overdue(): void {
		$now = 50000;
		$ship = static function (string $id, array $fields) use ($now) {
			return array('id' => $id, 'shipment' => $fields + array('carrier' => 'fedex', 'status' => 'in_transit', 'check_errors' => 0, 'created_at' => $now - 3600, 'last_checked_at' => $now - 7200, 'priority_until' => 0, 'next_check_at' => $now - 10));
		};
		$items = array(
			$ship('late', array('next_check_at' => $now - 5000)),
			$ship('later', array('next_check_at' => $now - 9000)),
			$ship('future', array('next_check_at' => $now + 60)),
			$ship('vip', array('next_check_at' => $now + 3000, 'priority_until' => $now + 600)),
			$ship('done', array('status' => 'delivered', 'priority_until' => $now + 600)),
			$ship('new', array('next_check_at' => $now - 100, 'last_checked_at' => 0)),
			$ship('expired-priority', array('next_check_at' => $now + 900, 'priority_until' => $now - 1)),
		);
		$picked = S::select($items, $now, 8);
		$this->assertSame(array('vip', 'later', 'late', 'new'), array_column($picked, 'id'));
		$this->assertSame(array('priority', 'due', 'due', 'new'), array_column($picked, 'reason'));
		$this->assertSame(array('vip', 'later'), array_column(S::select($items, $now, 2), 'id'), 'at most max');
		$this->assertSame(array(), S::select($items, $now, 0));
	}
}

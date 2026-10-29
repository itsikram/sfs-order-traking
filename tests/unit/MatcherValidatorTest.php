<?php
declare(strict_types=1);

namespace SFS\OrderTracking\Tests\Unit;

use SFS\OrderTracking\Core\Matcher;
use SFS\OrderTracking\Core\RecordValidator as V;
use SFS\OrderTracking\Tests\TestCase;

final class MatcherValidatorTest extends TestCase {
	private static function candidate(int $id, array $ship = array(), int $created = 0): array {
		return array(
			'order_id' => $id,
			'order_number' => 'ORDER' . str_pad((string) $id, 5, '0', STR_PAD_LEFT),
			'created' => $created,
			'ship' => array_merge(array('fname' => 'Ana', 'lname' => 'Rivera', 'company' => 'Ana Cafe', 'city' => 'Renton', 'state' => 'WA', 'zip' => '98056'), $ship),
			'bill' => array(),
			'titles' => array('Vinyl Banners'),
		);
	}

	private static function record(array $ship_to = array(), string $title = '13oz Vinyl Banner'): array {
		return array(
			'item_title' => $title,
			'ship_to' => array_merge(array('fullname' => 'Ana Rivera', 'company' => 'Ana Cafe LLC', 'city' => 'Renton', 'state' => 'WA', 'zipcode' => '98056-1234'), $ship_to),
		);
	}

	public function test_scores(): void {
		$purchase = 1000000;
		$full = Matcher::score(self::record(), self::candidate(1, array(), $purchase - 86400), $purchase);
		$this->assertSame(100, $full['score']);
		$this->assertSame(array('zip' => 30, 'name' => 30, 'first' => 10, 'place' => 10, 'item' => 10, 'date' => 10), $full['parts']);

		$this->assertSame(0, Matcher::score(self::record(array('zipcode' => '98057')), self::candidate(1, array(), $purchase), $purchase)['score'], 'ZIP is required');
		$company = Matcher::score(self::record(array('fullname' => 'Bob Smith')), self::candidate(1, array(), $purchase), $purchase);
		$this->assertSame(90, $company['score'], 'company instead of last name');
		$late = Matcher::score(self::record(), self::candidate(1, array(), $purchase - 5 * 86400), $purchase);
		$this->assertSame(95, $late['score']);
		$old = Matcher::score(self::record(), self::candidate(1, array(), $purchase - 30 * 86400), $purchase);
		$this->assertSame(90, $old['score']);
		$other_item = Matcher::score(self::record(array(), 'Aluminum Sign'), self::candidate(1, array(), $purchase), $purchase);
		$this->assertSame(0, $other_item['parts']['item']);

		$bill_only = self::candidate(2, array('zip' => '10001'));
		$bill_only['bill'] = array('fname' => 'Ana', 'lname' => 'Rivera', 'company' => '', 'city' => 'Renton', 'state' => 'WA', 'zip' => '98056');
		$this->assertSame(30, Matcher::score(self::record(), $bill_only, $purchase)['parts']['zip'], 'billing ZIP counts too');
	}

	public function test_rank_and_confidence(): void {
		$purchase = 1000000;
		$ranked = Matcher::rank(self::record(), array(
			self::candidate(3, array('lname' => 'Other', 'fname' => 'Zed', 'company' => 'Elsewhere', 'city' => 'Kent'), $purchase - 40 * 86400),
			self::candidate(1, array(), $purchase),
			self::candidate(2, array('zip' => '11111'), $purchase),
		), $purchase);
		$this->assertSame(array(1, 3), array_column($ranked, 'order_id'));
		$this->assertSame(array(100, 40), array_column($ranked, 'score'));
		$this->assertTrue(Matcher::confident($ranked));
		$this->assertFalse(Matcher::confident(array(array('score' => 95), array('score' => 60))));
		$this->assertFalse(Matcher::confident(array(array('score' => 85))));
		$this->assertFalse(Matcher::confident(array()));
	}

	public function test_sync_body_shape_and_limits(): void {
		$this->assertFalse(V::sync_body('text', 0)['ok']);
		$this->assertFalse(V::sync_body(array(1, 2), 0)['ok'], 'a list is not an object');
		$this->assertTrue(V::sync_body(array(), 0)['ok'], 'an empty object is fine');
		$this->assertFalse(V::sync_body(array('records' => array('a' => 1)), 0)['ok']);
		$this->assertFalse(V::sync_body(array('records' => array_fill(0, 301, array())), 0)['ok']);
		$this->assertFalse(V::sync_body(array('carrier_results' => array_fill(0, 101, array())), 0)['ok']);
		$body = V::sync_body(array('client' => array('version' => '1.0.0', 'env' => 'production'), 'run_id' => 'bad id!', 'full' => 'yes'), 0);
		$this->assertSame(array('version' => '1.0.0', 'env' => ''), $body['client']);
		$this->assertSame('', $body['run_id']);
		$this->assertFalse($body['full']);
	}

	public function test_records_are_cleaned_and_bad_ones_rejected_alone(): void {
		$ups = '1Z999AA10123456784';
		$good = array(
			'order_number' => 'b2612345',
			'item_id' => '501',
			'jobid' => 'B2612345-01',
			'jobname' => "SN6LTHF5IA\t\x07 rush",
			'status' => 'Shipped',
			'quantity' => '2',
			'ship_to' => array('fullname' => 'Ana Rivera', 'zipcode' => '98056', 'street_address' => '1 Main St', 'telephone' => '555'),
			'packages' => array(
				array('carrier' => 'UPS', 'tn' => '1z999aa10123456784', 'url' => 'https://wwwapps.ups.com/WebTracking/track?trackNums=' . $ups),
				array('carrier' => 'ups', 'tn' => $ups, 'url' => 'https://www.ups.com/track?trackNums=' . $ups),
				array('carrier' => 'fedex', 'tn' => 'not-a-number'),
				array('carrier' => 'fedex', 'tn' => '123456789012', 'url' => 'javascript:alert(1)//123456789012'),
			),
			'prices' => array('total' => 99),
		);
		$body = V::sync_body(array('records' => array($good, array('order_number' => 'B2612345'), $good + array('item_title' => 'second copy wins'))), 0);
		$this->assertTrue($body['ok']);
		$this->assertCount(1, $body['records'], 'duplicate item ids collapse');
		$this->assertCount(1, $body['rejected']);
		$this->assertSame('bad item_id', $body['rejected'][0]['message']);
		$record = $body['records'][0];
		$this->assertSame('B2612345', $record['order_number']);
		$this->assertSame(501, $record['item_id']);
		$this->assertSame('b2612345-01', $record['jobid']);
		$this->assertSame('shipped', $record['status']);
		$this->assertSame('SN6LTHF5IA rush', $record['jobname']);
		$this->assertSame(2, $record['quantity']);
		$this->assertSame('second copy wins', $record['item_title']);
		$this->assertSame(array('fullname', 'company', 'city', 'state', 'zipcode'), array_keys($record['ship_to']), 'street and phone are never kept');
		$this->assertCount(2, $record['packages'], 'duplicate and invalid packages dropped');
		$this->assertSame('', $record['packages'][1]['url'], 'a non-carrier link is dropped');
		$this->assertSame($ups, $record['packages'][0]['tn']);
		$this->assertTrue(!isset($record['prices']));

		$this->assertSame('bad status', V::record(array('order_number' => 'B2612345', 'item_id' => 5, 'status' => 'DROP TABLE'))[1]);
		$this->assertSame('bad order_number', V::record(array('order_number' => 'SN6LTHF5IA', 'item_id' => 5, 'status' => 'shipped'))[1]);
		$this->assertSame('bad item_id', V::record(array('order_number' => 'B2612345', 'item_id' => -5, 'status' => 'shipped'))[1]);
		$this->assertSame('awaiting_artwork', V::record(array('order_number' => 'B2612345', 'item_id' => 5, 'status' => 'awaiting_artwork'))[0]['status'], 'unknown statuses pass through raw');
	}

	public function test_carrier_results(): void {
		$now = 1793234000;
		$base = array('carrier' => 'fedex', 'tracking_number' => '123456789012', 'checked_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 60), 'ok' => true, 'events' => array(), 'time_basis' => 'event_local');
		list($ok) = V::carrier_result($base, $now);
		$this->assertSame($now - 60, $ok['checked_at']);
		$this->assertNull($ok['error']);

		$this->assertSame('bad carrier', V::carrier_result(array('carrier' => 'usps') + $base, $now)[1]);
		$this->assertSame('bad tracking_number', V::carrier_result(array('tracking_number' => '1Z999AA10123456784') + $base, $now)[1]);
		$this->assertSame('bad checked_at', V::carrier_result(array('checked_at' => $now - 8 * 86400) + $base, $now)[1]);
		$this->assertSame('bad checked_at', V::carrier_result(array('checked_at' => 'yesterday') + $base, $now)[1]);
		$this->assertSame($now, V::carrier_result(array('checked_at' => $now + 120) + $base, $now)[0]['checked_at'], 'a slightly fast clock is clamped');
		$this->assertSame('failed result without a known error code', V::carrier_result(array('ok' => false) + $base, $now)[1]);
		$failed = V::carrier_result(array('ok' => false, 'error' => array('code' => 'blocked', 'message' => 'Access Denied')) + $base, $now)[0];
		$this->assertSame(array('code' => 'blocked', 'message' => 'Access Denied'), $failed['error']);

		$events = array();
		for ($i = 0; $i < 160; $i++) {
			$events[] = array('date_text' => 'Monday, 10/26/26', 'time_text' => '5:48 PM', 'status_text' => 0 === $i % 2 ? 'Delivered' : '', 'location_text' => 'X');
		}
		$rich = V::carrier_result(array(
			'events' => $events,
			'facts' => array('delivered' => '10/26/2026 at 5:48 pm', 'bad<label>' => 'x', 'WEIGHT' => array('nested')),
			'browser_tz' => 'Mars/Olympus',
			'time_basis' => 'sometimes',
			'stepper' => array(array('label' => 'On the way', 'active' => true), 'junk', array('label' => '')),
			'parser_version' => 'fedex 1!',
		) + $base, $now)[0];
		$this->assertCount(75, $rich['events'], 'capped at 150, empty statuses dropped');
		$this->assertSame(array('DELIVERED' => '10/26/2026 at 5:48 pm'), $rich['facts']);
		$this->assertSame('', $rich['browser_tz']);
		$this->assertSame('unknown', $rich['time_basis']);
		$this->assertSame(array(array('label' => 'On the way', 'active' => true)), $rich['stepper']);
		$this->assertSame('', $rich['parser_version']);
		$this->assertSame('Asia/Dhaka', V::carrier_result(array('browser_tz' => 'Asia/Dhaka') + $base, $now)[0]['browser_tz']);
	}
}

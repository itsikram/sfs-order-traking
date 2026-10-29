<?php
declare(strict_types=1);

namespace SFS\OrderTracking\Tests\Integration;

require_once __DIR__ . '/IntegrationCase.php';

use SFS\OrderTracking\Core\TokenSigner;
use SFS\OrderTracking\Effects;
use SFS\OrderTracking\Keys;
use SFS\OrderTracking\Log;
use SFS\OrderTracking\Orders;
use SFS\OrderTracking\PublicView;

final class CarrierTrackTest extends IntegrationCase {
	/**
	 * A linked, already shipped order with one UPS package.
	 *
	 * @return array{0:array,1:array,2:string}
	 */
	private function shipped_order(array $order_options = array()): array {
		$order = $this->order($order_options);
		$record = $this->record();
		add_post_meta($order['id'], Orders::META_B2_ORDER, $record['order_number']);
		$this->sync(array($record));
		sfs_test_flush_status_emails();
		$GLOBALS['sfs_test_mail'] = array();
		return array($order, $record, $record['packages'][0]['tn']);
	}

	public function test_status_pill_shows_the_delivery_stage(): void {
		list($order, , $tn) = $this->shipped_order();
		$shipped = array('label' => 'Shipped', 'tone' => 'success');
		$this->assertSame('Shipped', PublicView::badge_item($shipped, 'completed', $order['id'])['label'], 'not read yet');
		$this->sync(array(), array($this->carrier_result('ups', $tn)));
		$this->assertSame('In transit', PublicView::badge_item($shipped, 'completed', $order['id'])['label']);
		$delivered = $this->carrier_result('ups', $tn, array('headline' => 'Delivered', 'stepper' => array(array('label' => 'Delivered', 'active' => true)), 'checked_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 5)));
		$this->sync(array(), array($delivered));
		$pill = PublicView::badge_item($shipped, 'completed', $order['id']);
		$this->assertSame(array('Delivered', 'success'), array($pill['label'], $pill['tone']));
		$this->assertStringContains('Delivered', wholesale_order_status_badge('completed', $order['id']));
		$this->assertStringContains('Shipped', wholesale_order_status_badge('completed'), 'without an order id: the stored status');

		$two = $this->order(array('items' => array('Banner', 'Flag')));
		$first = $this->record();
		$second = $this->record(array('order_number' => $first['order_number'], 'status' => 'printing', 'packages' => array()));
		add_post_meta($two['id'], Orders::META_B2_ORDER, $first['order_number']);
		$this->sync(array($first, $second));
		clean_post_cache($two['id']);
		$this->assertSame('processing', get_post_status($two['id']));
		$this->assertSame('Partly shipped', PublicView::badge_item(array('label' => 'In production', 'tone' => 'info'), 'processing', $two['id'])['label']);
	}

	public function test_a_moving_package_marks_the_order_shipped(): void {
		$order = $this->order();
		$tn = self::ups();
		Orders::save_shipments($order['id'], \SFS\OrderTracking\OrderHooks::with_manual(array(), 'ups', $tn, true));

		$this->settings(array('dry_run' => true));
		$data = $this->sync(array(), array($this->carrier_result('ups', $tn)));
		$this->assertStringContains('Would mark Shipped', $data['carrier_outcomes'][0]['message']);
		clean_post_cache($order['id']);
		$this->assertSame('processing', get_post_status($order['id']));

		$this->settings(array('dry_run' => false));
		$data = $this->sync(array(), array($this->carrier_result('ups', $tn)));
		$this->assertStringContains('Did: mark Shipped', $data['carrier_outcomes'][0]['message']);
		$this->assertStringContains('mark In transit', $data['carrier_outcomes'][0]['message']);
		clean_post_cache($order['id']);
		$this->assertSame('in-transit', get_post_status($order['id']), 'staff-typed tracking number, carrier has it: Shipped, then In transit');
		sfs_test_flush_status_emails();
		$mails = $this->mails_to($order['email']);
		$this->assertCount(1, $mails, 'one shipped email');
		$this->assertStringContains('has shipped', $mails[0]['subject']);

		wp_update_post(array('ID' => $order['id'], 'post_status' => 'processing'));
		sfs_test_flush_status_emails();
		$GLOBALS['sfs_test_mail'] = array();
		$delivered = $this->carrier_result('ups', $tn, array('headline' => 'Delivered', 'stepper' => array(array('label' => 'Delivered', 'active' => true)), 'checked_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 5)));
		$this->sync(array(), array($delivered));
		clean_post_cache($order['id']);
		$this->assertSame('processing', get_post_status($order['id']), 'never twice: staff moved it back');

		$waiting = $this->order();
		$item = $this->record(array('status' => 'printing', 'packages' => array()));
		add_post_meta($waiting['id'], Orders::META_B2_ORDER, $item['order_number']);
		$this->sync(array($item));
		$other = self::ups();
		Orders::save_shipments($waiting['id'], \SFS\OrderTracking\OrderHooks::with_manual(Orders::shipments($waiting['id']), 'ups', $other, true));
		$this->sync(array(), array($this->carrier_result('ups', $other)));
		clean_post_cache($waiting['id']);
		$this->assertSame('processing', get_post_status($waiting['id']), 'a linked supplier item is still printing');
	}

	public function test_order_status_follows_the_supplier_and_the_carrier(): void {
		$this->settings(array('dry_run' => false));
		$this->assertSame('finishing', Effects::b2_stage('Trimming'));
		$this->assertSame('ready-for-pickup', Effects::b2_stage('ready_for_pickup'));
		$this->assertSame('processing', Effects::b2_stage('Awaiting Staff Approval'));
		$this->assertSame('action', Effects::b2_stage('Awaiting Customer Approval'));
		$this->assertSame('action', Effects::b2_stage('on_hold'));
		$this->assertSame('shipped', Effects::b2_stage('Completed'));
		$this->assertSame('refund', Effects::b2_stage('Awaiting Refund'));

		// Supplier stages: forward only, and never while the supplier waits on us.
		$order = $this->order(array('status' => 'pending'));
		$item = $this->record(array('status' => 'Awaiting Customer Approval', 'packages' => array()));
		add_post_meta($order['id'], Orders::META_B2_ORDER, $item['order_number']);
		$this->sync(array($item));
		clean_post_cache($order['id']);
		$this->assertSame('pending', get_post_status($order['id']), 'the supplier waits on us: no change');
		$this->sync(array(array('status' => 'printing') + $item));
		clean_post_cache($order['id']);
		$this->assertSame('processing', get_post_status($order['id']));
		$this->sync(array(array('status' => 'trimming') + $item));
		clean_post_cache($order['id']);
		$this->assertSame('finishing', get_post_status($order['id']));
		$this->sync(array(array('status' => 'printing') + $item));
		clean_post_cache($order['id']);
		$this->assertSame('finishing', get_post_status($order['id']), 'never backwards');
		sfs_test_flush_status_emails();
		$GLOBALS['sfs_test_mail'] = array();

		// Carrier stages on a shipped order, one shipped email in all.
		list($shipped, , $tn) = $this->shipped_order();
		$reading = function (string $headline, int $age) use ($tn): array {
			return $this->carrier_result('ups', $tn, array('headline' => $headline, 'stepper' => array(array('label' => $headline, 'active' => true)), 'checked_at' => gmdate('Y-m-d\TH:i:s\Z', time() - $age)));
		};
		$this->sync(array(), array($reading('On the Way', 50)));
		clean_post_cache($shipped['id']);
		$this->assertSame('in-transit', get_post_status($shipped['id']));
		$this->sync(array(), array($reading('Out for Delivery Today', 40)));
		clean_post_cache($shipped['id']);
		$this->assertSame('out-for-delivery', get_post_status($shipped['id']));
		$this->sync(array(), array($reading('Delivered', 30)));
		clean_post_cache($shipped['id']);
		$this->assertSame('delivered', get_post_status($shipped['id']));
		$this->assertStringContains('Delivered', wholesale_order_status_badge('delivered', $shipped['id']));
		sfs_test_flush_status_emails();
		$this->assertCount(0, $this->mails_to($shipped['email']), 'the shipped email went out when it shipped; stages send none');

		// Staff changed it by hand: the sync leaves it until the packages show something new.
		wp_update_post(array('ID' => $shipped['id'], 'post_status' => 'completed'));
		$this->sync();
		clean_post_cache($shipped['id']);
		$this->assertSame('completed', get_post_status($shipped['id']), 'staff choice kept');
		sfs_test_flush_status_emails();

		// Straight from In production to Delivered: one delivered email with the tracking number.
		$late = $this->order();
		$late_tn = self::ups();
		Orders::save_shipments($late['id'], \SFS\OrderTracking\OrderHooks::with_manual(array(), 'ups', $late_tn, true));
		$GLOBALS['sfs_test_mail'] = array();
		$this->sync(array(), array($this->carrier_result('ups', $late_tn, array('headline' => 'Delivered', 'stepper' => array(array('label' => 'Delivered', 'active' => true))))));
		clean_post_cache($late['id']);
		$this->assertSame('delivered', get_post_status($late['id']));
		sfs_test_flush_status_emails();
		$mails = $this->mails_to($late['email']);
		$this->assertCount(1, $mails);
		$this->assertStringContains('was delivered', $mails[0]['subject']);
		$this->assertStringContains($late_tn, $mails[0]['message']);
	}

	public function test_carrier_reading_merges_once_and_schedules(): void {
		list($order, , $tn) = $this->shipped_order();
		$key = 'ups:' . $tn;
		$result = $this->carrier_result('ups', $tn);
		$data = $this->sync(array(), array($result));
		$this->assertSame('updated', $data['carrier_outcomes'][0]['outcome']);
		$this->assertSame('in_transit', $data['carrier_outcomes'][0]['status']);
		$shipment = Orders::shipments($order['id'])[$key];
		$this->assertSame('in_transit', $shipment['status']);
		$this->assertCount(2, $shipment['events']);
		$this->assertSame(strtotime($result['checked_at']), $shipment['last_checked_at']);
		$this->assertBetween(time() + 3240 - 5, time() + 3960 + 5, (int) $shipment['next_check_at']);

		$before = $this->snapshot($order['id']);
		$again = $this->sync(array(), array($result));
		$this->assertSame('unchanged', $again['carrier_outcomes'][0]['outcome']);
		$this->assertSame($before, $this->snapshot($order['id']), 'the same reading twice writes nothing');

		$discarded = $this->sync(array(), array($this->carrier_result('ups', self::ups())));
		$this->assertSame('discarded', $discarded['carrier_outcomes'][0]['outcome'], 'a number no order holds');
	}

	public function test_unknown_wording_keeps_status_and_is_listed(): void {
		list($order, , $tn) = $this->shipped_order();
		$this->sync(array(), array($this->carrier_result('ups', $tn)));
		$odd = $this->carrier_result('ups', $tn, array(
			'checked_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 10),
			'headline' => 'Zebra Crossing Scan',
			'stepper' => array(),
			'events' => array(array('date_text' => '10/29/2026', 'time_text' => '9:00 A.M.', 'status_text' => 'Zebra Crossing Scan', 'detail_text' => '', 'location_text' => '')),
		));
		$data = $this->sync(array(), array($odd));
		$this->assertSame('in_transit', $data['carrier_outcomes'][0]['status']);
		$texts = array_column(Log::wording_list(), 'text');
		$this->assertContains('Zebra Crossing Scan', $texts);
	}

	public function test_exception_alerts_staff_once_and_blocks_do_not_count(): void {
		list($order, , $tn) = $this->shipped_order();
		$issue = $this->carrier_result('ups', $tn, array(
			'headline' => 'Exception',
			'events' => array(array('date_text' => '10/29/2026', 'time_text' => '9:00 A.M.', 'status_text' => 'Delivery Attempted', 'detail_text' => 'The receiver was not available', 'location_text' => 'Lakeview, LA, US')),
		));
		$this->sync(array(), array($issue));
		$staff = array_values(array_filter($GLOBALS['sfs_test_mail'], static function ($mail) {
			return false !== strpos((string) $mail['subject'], '[Tracking] Delivery issue');
		}));
		$this->assertCount(1, $staff, 'staff told once');
		$this->assertSame(array(), $this->mails_to($order['email']), 'customer issue email is off by default');

		$blocked = array('ok' => false, 'error' => array('code' => 'blocked', 'message' => 'Access Denied'), 'checked_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 5), 'headline' => '', 'events' => array());
		$this->sync(array(), array($blocked + $this->carrier_result('ups', $tn)));
		$shipment = Orders::shipments($order['id'])['ups:' . $tn];
		$this->assertSame(0, $shipment['check_errors'], 'blocked pages are not the package\'s fault');
		$this->assertSame('exception', $shipment['status'], 'a failed check keeps the last known status');
		$this->assertStringContains('blocked', $shipment['last_error']);
	}

	public function test_checks_priority_first_and_capped(): void {
		$this->settings(array('dry_run' => false, 'max_checks_per_run' => 2));
		list($a, , $tn_a) = $this->shipped_order();
		list($b, , $tn_b) = $this->shipped_order();
		list($c, , $tn_c) = $this->shipped_order();
		$shipments = Orders::shipments($c['id']);
		$shipments['ups:' . $tn_c]['priority_until'] = time() + 600;
		Orders::save_shipments($c['id'], $shipments);
		$checks = $this->sync()['checks'];
		$this->assertCount(2, $checks);
		$this->assertSame($tn_c, $checks[0]['tracking_number']);
		$this->assertSame('priority', $checks[0]['reason']);
		$this->assertStringContains('https://www.ups.com/track?', $checks[0]['url']);

		$this->settings(array('dry_run' => true, 'max_checks_per_run' => 8));
		$shipments = Orders::shipments($a['id']);
		$shipments['ups:' . $tn_a]['priority_until'] = time() + 600;
		Orders::save_shipments($a['id'], $shipments);
		$checks = $this->sync()['checks'];
		// Only this test's packages: real local orders can have priority checks due too.
		$mine = array_values(array_intersect(array_column($checks, 'tracking_number'), array($tn_a, $tn_b, $tn_c)));
		$first_two = array_slice($mine, 0, 2);
		sort($first_two);
		$expected = array($tn_a, $tn_c);
		sort($expected);
		$this->assertSame($expected, $first_two, 'dry run still hands out checks for linked packages, priority first');
		unset($b, $tn_b);
	}

	public function test_track_endpoint_whitelist_token_etag_and_priority(): void {
		list($order, $record, $tn) = $this->shipped_order();
		$this->sync(array(), array($this->carrier_result('ups', $tn, array('checked_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600)))));

		$token = TokenSigner::issue(Keys::token_secret(), $order['number'], time() + 600);
		$request = new \WP_REST_Request('POST', '/sfs-tracking/v1/track');
		$request->set_param('t', $token);
		$response = rest_do_request($request);
		$this->assertSame(200, $response->get_status());
		$data = $response->get_data();
		$this->assertSame(array('order', 'shipments', 'server_time', 'expires_at'), array_keys($data));
		$this->assertSame(array('number', 'delivered', 'packages', 'delivered_packages', 'note'), array_keys($data['order']));
		$this->assertSame(array('index', 'count', 'carrier', 'carrier_name', 'tracking_number', 'status', 'status_label', 'tone', 'eta', 'delivered_at', 'last_event', 'events', 'last_checked_at', 'tracking_url'), array_keys($data['shipments'][0]));
		$this->assertSame(array('when', 'description', 'note', 'location', 'kind'), array_keys($data['shipments'][0]['events'][0]));
		$json = (string) wp_json_encode($data);
		foreach (array($record['order_number'], $record['jobid'], 'Test job', 'b2sign', 'wwwapps', 'Origintown', 'factory', 'Mctest') as $secret) {
			$this->assertStringNotContains($secret, $json, 'no supplier or private data');
		}
		$this->assertSame('Arrived at Facility', $data['shipments'][0]['events'][0]['description']);
		$this->assertSame('', $data['shipments'][0]['events'][0]['location'], 'origin city hidden (destination side only)');

		$shipment = Orders::shipments($order['id'])['ups:' . $tn];
		$this->assertTrue((int) $shipment['priority_until'] > time(), 'a stale package a customer is viewing is checked first');
		$this->assertSame(0, (int) get_post_meta($order['id'], Orders::META_DUE, true), 'due index points at it');

		$etag = $response->get_headers()['ETag'];
		$conditional = new \WP_REST_Request('POST', '/sfs-tracking/v1/track');
		$conditional->set_param('t', $token);
		$conditional->set_header('If-None-Match', $etag);
		$this->assertSame(304, rest_do_request($conditional)->get_status());

		$expired = new \WP_REST_Request('POST', '/sfs-tracking/v1/track');
		$expired->set_param('t', TokenSigner::issue(Keys::token_secret(), $order['number'], time() - 1));
		$response = rest_do_request($expired);
		$this->assertSame(401, $response->get_status());
		$this->assertSame('sfs_token_expired', $response->get_data()['code']);

		$forged = new \WP_REST_Request('POST', '/sfs-tracking/v1/track');
		$forged->set_param('t', TokenSigner::issue('guessed-secret', $order['number'], time() + 600));
		$this->assertSame('sfs_token_invalid', rest_do_request($forged)->get_data()['code']);

		set_transient('sfs_trk_rate_' . md5('127.0.0.250'), array('start' => time(), 'n' => PublicView::RATE_LIMIT), 600);
		$this->assertSame(429, rest_do_request($request)->get_status(), 'rate limited per IP');
		delete_transient('sfs_trk_rate_' . md5('127.0.0.250'));
	}

	public function test_customer_view_progress_and_destination_locations(): void {
		list($order, , $tn) = $this->shipped_order();
		$delivered = $this->carrier_result('ups', $tn, array(
			'headline' => 'Delivered',
			'delivered_text' => '10/30/2026, 2:15 P.M.',
			'events' => array(
				array('date_text' => '10/30/2026', 'time_text' => '2:15 P.M.', 'status_text' => 'Delivered', 'detail_text' => 'DELIVERED', 'location_text' => 'LAKEVIEW, LA, US'),
				array('date_text' => '10/30/2026', 'time_text' => '7:02 A.M.', 'status_text' => 'Out for Delivery', 'detail_text' => 'Out For Delivery Today', 'location_text' => 'Hubville, LA, US'),
				array('date_text' => '10/28/2026', 'time_text' => '8:28 P.M.', 'status_text' => 'We Have Your Package', 'detail_text' => 'Arrived at Facility', 'location_text' => 'Origintown, PA, United States'),
			),
		));
		$this->sync(array(), array($delivered));
		$payload = PublicView::payload($order['id']);
		$events = $payload['shipments'][0]['events'];
		$this->assertSame('Lakeview, LA', $events[0]['location']);
		$this->assertSame('Hubville, LA', $events[1]['location'], 'last-mile scan');
		$this->assertSame('', $events[2]['location'], 'origin hidden');
		$this->assertSame('Fri, Oct 30 at 2:15 PM', $payload['shipments'][0]['delivered_at']);
		$this->assertTrue($payload['order']['delivered']);

		$progress = PublicView::progress(array('steps' => array(1 => 'Order received', 2 => 'In production', 3 => 'Shipped'), 'step' => 3, 'dates' => array(), 'notes' => array()), $order['id']);
		$this->assertSame('Delivered', $progress['steps'][4]);
		$this->assertSame(4, $progress['step']);
		$this->assertTrue(isset($progress['dates'][4]));

		$html = PublicView::shipments_html('', $order['id'], 'track');
		$this->assertStringContains('data-sfs-shipments', $html);
		$this->assertStringContains('data-token="', $html);
		$this->assertStringContains('Track on UPS', $html);
		$this->assertStringNotContains('Origintown', $html);
		$this->assertStringNotContains('b2sign', strtolower($html));
		$this->assertSame('', PublicView::shipments_html('', 999999999, 'track'), 'no order, no card');
		$this->assertStringContains('Delivered', PublicView::my_orders_summary('', $order['id']));
	}
}

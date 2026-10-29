<?php
declare(strict_types=1);

namespace SFS\OrderTracking\Tests\Integration;

use SFS\OrderTracking\Core\RequestSigner;
use SFS\OrderTracking\Settings;
use SFS\OrderTracking\Tests\TestCase;

/**
 * Helpers for tests against the local WordPress: test orders, B2Sign records, signed REST calls, settings.
 */
abstract class IntegrationCase extends TestCase {
	/** @var int */
	protected static $sequence = 0;

	public function setUp(): void {
		$GLOBALS['sfs_test_mail'] = array();
		$GLOBALS['wholesale_status_emails'] = array();
		unset($GLOBALS['wholesale_skip_status_email']);
		$this->settings(array('dry_run' => false));
	}

	protected function settings(array $changes): void {
		Settings::save(array_merge(Settings::defaults(), $changes));
	}

	/**
	 * A clearly marked test order. Defaults: processing, one line item, ship to Lakeview LA 70001.
	 */
	protected function order(array $options = array()): array {
		$number = 'ZZT' . strtoupper(substr(md5(uniqid('', true)), 0, 7));
		$options += array(
			'status' => 'processing',
			'items' => array('13oz Vinyl Banner'),
			'fname' => 'Testy',
			'lname' => 'Mctest',
			'company' => 'Test Shop',
			'city' => 'Lakeview',
			'state' => 'LA',
			'zip' => '70001',
			'created' => time() - 3 * DAY_IN_SECONDS,
		);
		$email = 'sfs-test-' . strtolower($number) . '@example.invalid';
		$items = array();
		foreach ($options['items'] as $title) {
			$items[] = array('product_title' => $title, 'product_quantity' => 1, 'product_subtotal' => 10);
		}
		$address = static function (string $prefix) use ($options, $email) {
			return array(
				$prefix . 'fname' => $options['fname'],
				$prefix . 'lname' => $options['lname'],
				$prefix . 'company' => $options['company'],
				$prefix . 'address' => '1 Test Way',
				$prefix . 'city' => $options['city'],
				$prefix . 'state' => $options['state'],
				$prefix . 'zip' => $options['zip'],
				$prefix . 'email' => $email,
			);
		};
		$id = wp_insert_post(array(
			'post_type' => 'order',
			'post_title' => 'SFS TRACKING TEST ORDER',
			'post_status' => $options['status'],
			'post_date_gmt' => gmdate('Y-m-d H:i:s', $options['created']),
			'post_date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $options['created'])),
			'meta_input' => array(
				'_sfs_test_order' => 1,
				'order_id' => 'order_' . $number,
				'product_json' => wp_json_encode($items),
				'billing_address' => wp_json_encode($address('billing_')),
				'shipping_address' => wp_json_encode($address('shipping_')),
				'product_cost' => wp_json_encode(array('grand_total' => 10)),
			),
		), true);
		$this->assertTrue(is_int($id) && $id > 0, 'test order created');
		// Creation is not a status change, but be explicit: no queued email from setup.
		$GLOBALS['wholesale_status_emails'] = array();
		return array('id' => $id, 'number' => $number, 'email' => $email);
	}

	protected static function b2_number(): string {
		return 'Z' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
	}

	protected static function ups(): string {
		return '1ZTEST' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
	}

	protected static function fedex(): string {
		return '99' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
	}

	/**
	 * A raw ShipmentRecord as the extension sends it (shipped, one UPS package by default).
	 */
	protected function record(array $overrides = array()): array {
		self::$sequence++;
		$b2 = $overrides['order_number'] ?? self::b2_number();
		$tn = self::ups();
		return array_merge(array(
			'order_number' => $b2,
			'item_id' => 990000000 + random_int(1, 8999999) + self::$sequence,
			'jobid' => strtolower($b2) . '-01',
			'jobname' => 'Test job',
			'item_title' => '13oz Vinyl Banner',
			'quantity' => 1,
			'status' => 'shipped',
			'status_time' => 'Oct 27',
			'purchased' => gmdate('m/d/Y H:i:s', time() - 2 * DAY_IN_SECONDS),
			'arriving_at' => '',
			'service' => 'UPS Ground',
			'is_reprint' => false,
			'ship_to' => array('fullname' => 'Testy Mctest', 'company' => 'Test Shop', 'city' => 'Lakeview', 'state' => 'LA', 'zipcode' => '70001'),
			'packages' => array(array('carrier' => 'ups', 'tn' => $tn, 'url' => 'https://wwwapps.ups.com/WebTracking/track?track=yes&trackNums=' . $tn . '&loc=en_US', 'scheduled' => gmdate('Y-m-d', time() + 2 * DAY_IN_SECONDS) . ' 17:30:00')),
		), $overrides);
	}

	protected function carrier_result(string $carrier, string $number, array $overrides = array()): array {
		return array_merge(array(
			'carrier' => $carrier,
			'tracking_number' => $number,
			'checked_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 30),
			'ok' => true,
			'headline' => 'We Have Your Package',
			'stepper' => array(array('label' => 'Label Created', 'active' => false), array('label' => 'We Have Your Package', 'active' => true)),
			'eta_text' => '',
			'events' => array(
				array('date_text' => '10/28/2026', 'time_text' => '8:28 P.M.', 'status_text' => 'We Have Your Package', 'detail_text' => 'Arrived at Facility', 'location_text' => 'Origintown, PA, United States'),
				array('date_text' => '10/28/2026', 'time_text' => '2:07 P.M.', 'status_text' => 'Label Created', 'detail_text' => 'Shipper created a label, UPS has not received the package yet.', 'location_text' => 'United States'),
			),
			'source' => 'dom',
			'parser_version' => 'test-1',
			'time_basis' => 'event_local',
		), $overrides);
	}

	/**
	 * Signs and dispatches a request in-process. Returns array(status, data, response).
	 *
	 * @param array|string $body
	 */
	protected function signed(string $method, string $route, $body = '', array $override_headers = array()): array {
		$body = is_array($body) ? (string) wp_json_encode($body) : $body;
		$timestamp = (string) time();
		$nonce = bin2hex(random_bytes(16));
		$signature = RequestSigner::sign(SFS_TRACKING_SECRET, RequestSigner::canonical($timestamp, $nonce, $method, $route, $body));
		$request = new \WP_REST_Request($method, $route);
		$request->set_body($body);
		$request->set_header('content-type', 'application/json');
		foreach (array_merge(array('x-sfs-key' => SFS_TRACKING_KEY_ID, 'x-sfs-timestamp' => $timestamp, 'x-sfs-nonce' => $nonce, 'x-sfs-signature' => $signature), $override_headers) as $name => $value) {
			$request->set_header($name, $value);
		}
		$response = rest_do_request($request);
		return array($response->get_status(), $response->get_data(), $response, $request);
	}

	protected function sync(array $records = array(), array $results = array()): array {
		list($status, $data) = $this->signed('POST', '/sfs-tracking/v1/sync', array(
			'client' => array('version' => '1.0.0', 'env' => 'local'),
			'run_id' => 'test_' . bin2hex(random_bytes(6)),
			'records' => $records,
			'carrier_results' => $results,
		));
		$this->assertSame(200, $status, 'sync status: ' . wp_json_encode($data));
		return $data;
	}

	/**
	 * Everything stored about an order (meta + status), to prove a call wrote nothing.
	 */
	protected function snapshot(int $order_id): array {
		clean_post_cache($order_id);
		$meta = get_post_meta($order_id);
		ksort($meta);
		return array('status' => get_post_status($order_id), 'meta' => $meta);
	}

	protected function mails_to(string $email): array {
		return array_values(array_filter($GLOBALS['sfs_test_mail'], static function ($mail) use ($email) {
			return in_array($email, (array) $mail['to'], true) || $email === $mail['to'];
		}));
	}
}

<?php
declare(strict_types=1);

namespace SFS\OrderTracking\Tests\Integration;

require_once __DIR__ . '/IntegrationCase.php';

use SFS\OrderTracking\Inbox;
use SFS\OrderTracking\Lock;
use SFS\OrderTracking\Orders;
use SFS\OrderTracking\SyncService;

final class AuthSyncTest extends IntegrationCase {
	public function test_signatures_skew_replay_size_and_lockout(): void {
		list($status, $data) = $this->signed('GET', '/sfs-tracking/v1/ping');
		$this->assertSame(200, $status);
		$this->assertTrue($data['ok']);
		$this->assertSame(SFS_TRACKING_VERSION, $data['plugin_version']);

		list($status, $data) = $this->signed('GET', '/sfs-tracking/v1/ping', '', array('x-sfs-signature' => str_repeat('0', 64)));
		$this->assertSame(401, $status);
		$this->assertSame('sfs_bad_signature', $data['code']);

		list($status, $data) = $this->signed('GET', '/sfs-tracking/v1/ping', '', array('x-sfs-timestamp' => (string) (time() - 400)));
		$this->assertSame(401, $status, 'a timestamp change also breaks the signature, but skew is checked first');
		$this->assertSame('sfs_clock_skew', $data['code']);
		$this->assertTrue(isset($data['data']['server_time']), 'skew errors tell the extension the server time');

		list($status, $data) = $this->signed('GET', '/sfs-tracking/v1/ping', '', array('x-sfs-key' => 'sfs_other'));
		$this->assertSame('sfs_bad_key', $data['code']);

		list($status, $data) = $this->signed('POST', '/sfs-tracking/v1/sync', '', array('x-sfs-signature' => ''));
		$this->assertSame('sfs_missing_headers', $data['code']);

		// Replaying the exact same signed request.
		list($status, , , $request) = $this->signed('GET', '/sfs-tracking/v1/ping');
		$this->assertSame(200, $status);
		$replay = rest_do_request($request);
		$this->assertSame(401, $replay->get_status());
		$this->assertSame('sfs_replay', $replay->get_data()['code']);

		list($status, $data) = $this->signed('POST', '/sfs-tracking/v1/sync', '{"pad":"' . str_repeat('x', 1048576) . '"}');
		$this->assertSame(413, $status);

		// Ten failures lock this address out for 15 minutes, even with a valid signature afterwards.
		delete_transient('sfs_trk_fail_' . md5('127.0.0.250'));
		for ($i = 0; $i < 10; $i++) {
			$this->signed('GET', '/sfs-tracking/v1/ping', '', array('x-sfs-signature' => str_repeat('a', 64)));
		}
		list($status, $data) = $this->signed('GET', '/sfs-tracking/v1/ping');
		$this->assertSame(429, $status);
		$this->assertSame('sfs_locked', $data['code']);
		delete_transient('sfs_trk_fail_' . md5('127.0.0.250'));
	}

	public function test_bad_bodies_are_rejected_cleanly(): void {
		list($status, $data) = $this->signed('POST', '/sfs-tracking/v1/sync', '{not json');
		$this->assertSame(400, $status, 'WordPress rejects broken JSON before authentication');
		$this->assertSame('rest_invalid_json', $data['code']);
		list($status, $data) = $this->signed('POST', '/sfs-tracking/v1/sync', '{not json', array('content-type' => 'text/plain'));
		$this->assertSame(400, $status);
		$this->assertSame('sfs_bad_json', $data['code']);
		list($status, $data) = $this->signed('POST', '/sfs-tracking/v1/sync', array('records' => array_fill(0, 301, array())));
		$this->assertSame(400, $status);
		$this->assertSame('sfs_bad_request', $data['code']);
	}

	public function test_a_held_lock_answers_busy(): void {
		global $wpdb;
		// MySQL locks are re-entrant per connection, so a second connection plays the other sync.
		$other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
		$name = substr(md5(DB_NAME . '|' . $wpdb->prefix) . ':sfs_tracking_sync', 0, 64);
		$this->assertSame('1', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 1)', $name)));
		$wait = static function () {
			return 1;
		};
		add_filter('sfs_tracking_lock_wait', $wait);
		try {
			$result = SyncService::link_order(1);
			$this->assertTrue(is_wp_error($result), 'second writer must wait');
			$this->assertSame('sfs_busy', $result->get_error_code());
			list($status, $data) = $this->signed('POST', '/sfs-tracking/v1/sync', array('records' => array()));
			$this->assertSame(409, $status);
			$this->assertSame('sfs_busy', $data['code']);
		} finally {
			remove_filter('sfs_tracking_lock_wait', $wait);
			$other->query($other->prepare('SELECT RELEASE_LOCK(%s)', $name));
			$other->close();
		}
		$this->assertSame(200, $this->signed('POST', '/sfs-tracking/v1/sync', array('records' => array()))[0], 'free again');
	}

	public function test_dry_run_writes_nothing_but_answers_what_it_would_do(): void {
		$this->settings(array('dry_run' => true));
		$order = $this->order();
		$record = $this->record();
		add_post_meta($order['id'], Orders::META_B2_ORDER, $record['order_number']);
		$before = $this->snapshot($order['id']);
		$unmatched = $this->record(array("ship_to" => array("fullname" => "Nobody Known", "company" => "", "city" => "Nowhere", "state" => "ZZ", "zipcode" => "00004")));

		$data = $this->sync(array($record, $unmatched));
		$this->assertTrue($data['dry_run']);
		$this->assertSame('matched', $data['results'][0]['outcome']);
		$this->assertSame($order['number'], $data['results'][0]['order_number']);
		$this->assertStringContains('Dry run', $data['results'][0]['message']);
		$this->assertStringContains('Would mark Shipped', $data['results'][0]['message']);
		$this->assertSame('unmatched', $data['results'][1]['outcome']);
		$this->assertSame($before, $this->snapshot($order['id']), 'order untouched');
		$this->assertSame(array(), $GLOBALS['sfs_test_mail']);
		$this->assertSame(array(), $GLOBALS['wholesale_status_emails'] ?? array());
		$this->assertSame('unmatched', Inbox::item_rows((int) $unmatched['item_id'])[0]['state'], 'the review queue preview is written');
		$this->assertSame(array(), Inbox::item_rows((int) $record['item_id']), 'matched items are not');
	}

	public function test_exact_match_ships_once_and_replay_changes_nothing(): void {
		$order = $this->order();
		$record = $this->record();
		add_post_meta($order['id'], Orders::META_B2_ORDER, $record['order_number']);
		$tn = $record['packages'][0]['tn'];

		$data = $this->sync(array($record));
		$this->assertSame('matched', $data['results'][0]['outcome']);
		$this->assertStringContains('Did: mark Shipped', $data['results'][0]['message']);
		clean_post_cache($order['id']);
		$this->assertSame('completed', get_post_status($order['id']));
		$this->assertSame('ups', get_post_meta($order['id'], '_tracking_carrier', true), 'primary tracking pair for the theme');
		$this->assertSame($tn, get_post_meta($order['id'], '_tracking_number', true));
		$this->assertSame(array('ups:' . $tn), array_keys(Orders::shipments($order['id'])));
		$this->assertSame(array('ups:' . $tn), get_post_meta($order['id'], Orders::META_TN));
		$this->assertSame(array((string) $record['item_id']), get_post_meta($order['id'], Orders::META_ITEM));
		$this->assertTrue((int) get_post_meta($order['id'], Orders::META_DUE, true) > 0, 'due index set');
		$this->assertTrue((bool) get_post_meta($order['id'], Orders::META_AUTO_SHIPPED, true));

		sfs_test_flush_status_emails();
		$mails = $this->mails_to($order['email']);
		$this->assertCount(1, $mails, 'exactly one shipped email');
		$this->assertStringContains('has shipped', $mails[0]['subject']);
		$this->assertStringContains($tn, $mails[0]['message']);
		$this->assertStringNotContains($record['order_number'], $mails[0]['message'], 'no supplier order number in customer email');

		$before = $this->snapshot($order['id']);
		$again = $this->sync(array($record));
		$this->assertSame('unchanged', $again['results'][0]['outcome']);
		$this->assertSame($before, $this->snapshot($order['id']), 'replay writes nothing');
		sfs_test_flush_status_emails();
		$this->assertCount(1, $this->mails_to($order['email']), 'and sends nothing');
	}

	public function test_partial_shipment_keeps_the_status(): void {
		$order = $this->order(array('items' => array('Banner A', 'Banner B')));
		$record = $this->record();
		add_post_meta($order['id'], Orders::META_B2_ORDER, $record['order_number']);
		$data = $this->sync(array($record));
		$this->assertSame('matched', $data['results'][0]['outcome']);
		clean_post_cache($order['id']);
		$this->assertSame('processing', get_post_status($order['id']));
		sfs_test_flush_status_emails();
		$this->assertSame(array(), $this->mails_to($order['email']));
		$payload = \SFS\OrderTracking\PublicView::payload($order['id']);
		$this->assertSame('Partially shipped (1 of 2 items)', $payload['order']['note']);

		$second = $this->record(array('order_number' => $record['order_number'], 'jobid' => strtolower($record['order_number']) . '-02', 'item_title' => 'Banner B'));
		$this->sync(array($second));
		clean_post_cache($order['id']);
		$this->assertSame('completed', get_post_status($order['id']), 'second item ships the order');
		sfs_test_flush_status_emails();
		$mails = $this->mails_to($order['email']);
		$this->assertCount(1, $mails);
		$this->assertStringContains($record['packages'][0]['tn'], $mails[0]['message'], 'every package in the shipped email');
		$this->assertStringContains($second['packages'][0]['tn'], $mails[0]['message']);
	}

	public function test_completed_orders_reprints_cancelled_refunded_and_unknown_statuses(): void {
		$done = $this->order(array('status' => 'completed'));
		$first = $this->record();
		add_post_meta($done['id'], Orders::META_B2_ORDER, $first['order_number']);
		$this->sync(array($first));
		$reprint = $this->record(array('order_number' => $first['order_number'], 'jobid' => strtolower($first['order_number']) . '-02', 'is_reprint' => true, 'packages' => array(array('carrier' => 'fedex', 'tn' => self::fedex()))));
		$this->sync(array($reprint));
		$this->assertCount(2, Orders::shipments($done['id']), 'a reprint adds a second package');
		$this->assertSame($first['packages'][0]['tn'], get_post_meta($done['id'], '_tracking_number', true), 'the first stays primary');
		sfs_test_flush_status_emails();
		$this->assertSame(array(), $this->mails_to($done['email']), 'no status email for an order already shipped');

		$cancelled = $this->order(array('status' => 'cancelled'));
		$late = $this->record();
		add_post_meta($cancelled['id'], Orders::META_B2_ORDER, $late['order_number']);
		$data = $this->sync(array($late));
		$this->assertStringContains('the order is cancelled but the item shipped', $data['results'][0]['message']);
		clean_post_cache($cancelled['id']);
		$this->assertSame('cancelled', get_post_status($cancelled['id']), 'never auto-changed');

		$refund_order = $this->order();
		$refunded = $this->record(array('status' => 'refunded', 'packages' => array()));
		add_post_meta($refund_order['id'], Orders::META_B2_ORDER, $refunded['order_number']);
		$data = $this->sync(array($refunded));
		$this->assertStringContains('the supplier refunded this item', $data['results'][0]['message']);
		clean_post_cache($refund_order['id']);
		$this->assertSame('processing', get_post_status($refund_order['id']));
		$this->assertSame('refunded', Orders::items($refund_order['id'])[(int) $refunded['item_id']]['status']);

		$odd_order = $this->order();
		$odd = $this->record(array('status' => 'awaiting_artwork', 'packages' => array()));
		add_post_meta($odd_order['id'], Orders::META_B2_ORDER, $odd['order_number']);
		$this->sync(array($odd));
		clean_post_cache($odd_order['id']);
		$this->assertSame('processing', get_post_status($odd_order['id']));
		$this->assertSame('awaiting_artwork', Orders::items($odd_order['id'])[(int) $odd['item_id']]['status'], 'stored raw');
		$this->assertSame(array(), Orders::shipments($odd_order['id']));
	}

	public function test_ambiguous_job_name_fuzzy_and_ignored(): void {
		$a = $this->order();
		$b = $this->order();
		$shared = $this->record();
		add_post_meta($a['id'], Orders::META_B2_ORDER, $shared['order_number']);
		add_post_meta($b['id'], Orders::META_B2_ORDER, $shared['order_number']);
		$data = $this->sync(array($shared));
		$this->assertSame('needs_review', $data['results'][0]['outcome']);
		$row = Inbox::item_rows((int) $shared['item_id'])[0];
		$this->assertSame('needs_review', $row['state']);
		$this->assertCount(2, json_decode((string) $row['candidates'], true));
		$this->assertSame(array(), Orders::shipments($a['id']), 'nothing linked');

		$job = $this->order();
		$named = $this->record(array('jobname' => 'Rush #' . strtolower($job['number']) . ' banner'));
		$this->assertSame('matched', $this->sync(array($named))['results'][0]['outcome'], 'our order number in the job name');
		$this->assertCount(1, Orders::shipments($job['id']));

		$fuzzy_order = $this->order(array('fname' => 'Fuzzy', 'lname' => 'Matcherson', 'company' => 'Fuzzy Signs', 'zip' => '70999', 'city' => 'Fuzzton'));
		$fuzzy = $this->record(array('ship_to' => array('fullname' => 'Fuzzy Matcherson', 'company' => 'Fuzzy Signs LLC', 'city' => 'Fuzzton', 'state' => 'LA', 'zipcode' => '70999')));
		$data = $this->sync(array($fuzzy));
		$this->assertSame('needs_review', $data['results'][0]['outcome'], 'fuzzy is never applied by default');
		$candidates = json_decode((string) Inbox::item_rows((int) $fuzzy['item_id'])[0]['candidates'], true);
		$this->assertSame($fuzzy_order['number'], $candidates[0]['order_number']);
		$this->assertGreaterOrEqual(90, (int) $candidates[0]['score']);

		$this->settings(array('dry_run' => false, 'auto_apply_fuzzy' => true));
		$this->assertSame('matched', $this->sync(array($fuzzy))['results'][0]['outcome'], 'auto-apply only when switched on');
		$this->assertCount(1, Orders::shipments($fuzzy_order['id']));

		$stranger = $this->record(array('ship_to' => array('fullname' => 'Nobody', 'company' => '', 'city' => 'Nowhere', 'state' => 'ZZ', 'zipcode' => '00001')));
		$this->assertSame('unmatched', $this->sync(array($stranger))['results'][0]['outcome']);
		Inbox::set_state((int) $stranger['item_id'], 'ignored');
		$this->assertSame('ignored', $this->sync(array($stranger))['results'][0]['outcome'], 'not ours is remembered');
	}

	public function test_typing_the_b2sign_number_later_links_from_the_review_queue(): void {
		$order = $this->order();
		$record = $this->record(array('ship_to' => array('fullname' => 'Someone Else', 'company' => '', 'city' => 'X', 'state' => 'ZZ', 'zipcode' => '00002')));
		$this->assertSame('unmatched', $this->sync(array($record))['results'][0]['outcome']);
		Orders::set_b2_numbers($order['id'], array($record['order_number']));
		$data = $this->sync();
		$this->assertCount(1, $data['results'], 'relinked from the stored record, no resend needed');
		$this->assertTrue($data['results'][0]['from_inbox']);
		$this->assertSame('matched', $data['results'][0]['outcome']);
		$this->assertSame('linked', Inbox::item_rows((int) $record['item_id'])[0]['state']);
		$this->assertCount(0, $this->sync()['results'], 'linked rows are not relinked again');

		$confirm_order = $this->order();
		$loose = $this->record(array('ship_to' => array('fullname' => 'Other Person', 'company' => '', 'city' => 'Y', 'state' => 'ZZ', 'zipcode' => '00003')));
		$this->sync(array($loose));
		$this->settings(array('dry_run' => true));
		Inbox::confirm((int) $loose['item_id'], $confirm_order['number']);
		$preview = SyncService::link_order($confirm_order['id'], array((int) $loose['item_id']));
		$this->assertSame('matched', $preview[0]['outcome']);
		$this->assertStringContains('Dry run', $preview[0]['message']);
		$this->assertSame(array(), Orders::shipments($confirm_order['id']), 'a confirmation during dry run waits');
		$this->settings(array('dry_run' => false));
		$data = $this->sync();
		$this->assertSame('matched', $data['results'][0]['outcome']);
		$this->assertCount(1, Orders::shipments($confirm_order['id']));
	}

	public function test_in_production_before_any_tracking_number_then_shipped(): void {
		$order = $this->order(array('status' => 'pending'));
		$record = $this->record(array('status' => 'awaiting_artwork', 'packages' => array()));
		add_post_meta($order['id'], Orders::META_B2_ORDER, $record['order_number']);
		$this->assertSame('matched', $this->sync(array($record))['results'][0]['outcome']);
		clean_post_cache($order['id']);
		$this->assertSame('pending', get_post_status($order['id']), 'artwork not approved yet: still Pending review');

		$record['status'] = 'printing';
		$this->sync(array($record));
		clean_post_cache($order['id']);
		$this->assertSame('processing', get_post_status($order['id']), 'In production with no tracking number');
		$this->assertSame(array(), Orders::shipments($order['id']));
		$this->assertSame('printing', Orders::items($order['id'])[(int) $record['item_id']]['status']);

		$this->settings(array('dry_run' => true));
		$other = $this->order(array('status' => 'pending'));
		$dry = $this->record(array('status' => 'printing', 'packages' => array()));
		add_post_meta($other['id'], Orders::META_B2_ORDER, $dry['order_number']);
		$this->assertStringContains('In production', $this->sync(array($dry))['results'][0]['message']);
		clean_post_cache($other['id']);
		$this->assertSame('pending', get_post_status($other['id']), 'dry run only says what it would do');
		$this->settings(array('dry_run' => false));

		$shipped = $this->record(array('order_number' => $record['order_number'], 'item_id' => $record['item_id']));
		$this->sync(array($shipped));
		clean_post_cache($order['id']);
		$this->assertSame('completed', get_post_status($order['id']), 'Shipped once the tracking number shows up');
		$this->assertCount(1, Orders::shipments($order['id']));
	}

	public function test_typed_b2_number_without_a_stored_record_is_requested_from_the_extension(): void {
		$order = $this->order();
		$record = $this->record();
		add_post_meta($order['id'], Orders::META_B2_ORDER, $record['order_number']);
		$this->assertContains($record['order_number'], $this->sync()['want_b2'], 'typed but never received');
		$data = $this->sync(array($record));
		$this->assertSame('matched', $data['results'][0]['outcome']);
		$this->assertNotContains($record['order_number'], $data['want_b2'], 'linked: no longer wanted');

		$queued = $this->record(array('ship_to' => array('fullname' => 'Queue Person', 'company' => '', 'city' => 'Z', 'state' => 'ZZ', 'zipcode' => '00007')));
		$this->sync(array($queued));
		$late = $this->order();
		add_post_meta($late['id'], Orders::META_B2_ORDER, $queued['order_number']);
		$this->assertNotContains($queued['order_number'], Orders::wanted_b2_numbers(), 'stored on the site: linked from the queue, no resend needed');
		$cancelled = $this->order(array('status' => 'cancelled'));
		add_post_meta($cancelled['id'], Orders::META_B2_ORDER, self::b2_number());
		$this->assertNotContains(Orders::b2_numbers($cancelled['id'])[0], Orders::wanted_b2_numbers(), 'cancelled orders are not chased');
	}

	public function test_review_queue_bulk_actions(): void {
		$order = $this->order(array('fname' => 'Bulky', 'lname' => 'Bulkerson', 'company' => 'Bulk Signs', 'zip' => '70888', 'city' => 'Bulkton'));
		$suggested = $this->record(array('ship_to' => array('fullname' => 'Bulky Bulkerson', 'company' => 'Bulk Signs LLC', 'city' => 'Bulkton', 'state' => 'LA', 'zipcode' => '70888')));
		$stranger = $this->record(array('ship_to' => array('fullname' => 'Nobody Bulk', 'company' => '', 'city' => 'Nowhere', 'state' => 'ZZ', 'zipcode' => '00006')));
		$this->sync(array($suggested, $stranger));
		$suggested_row = (int) Inbox::item_rows((int) $suggested['item_id'])[0]['id'];
		$stranger_row = (int) Inbox::item_rows((int) $stranger['item_id'])[0]['id'];
		$bulk = static function (string $action, array $ids): \WP_REST_Response {
			$request = new \WP_REST_Request('POST', '/sfs-tracking/v1/admin/inbox/bulk');
			$request->set_param('action', $action);
			$request->set_param('ids', $ids);
			return rest_do_request($request);
		};

		$this->assertTrue(in_array($bulk('ignore', array($stranger_row))->get_status(), array(401, 403), true), 'staff only');
		$this->assertSame('unmatched', Inbox::item_rows((int) $stranger['item_id'])[0]['state']);

		wp_set_current_user(get_users(array('role' => 'administrator', 'number' => 1))[0]->ID);
		$response = $bulk('confirm', array($suggested_row, $stranger_row, $suggested_row, 999999999));
		$data = $response->get_data();
		$this->assertSame(200, $response->get_status(), wp_json_encode($data));
		$this->assertSame(1, $data['done']);
		$this->assertSame(1, $data['skipped'], 'no suggestion is skipped, a missing id is ignored');
		$this->assertStringContains('no suggested order', $data['message']);
		$this->assertSame('linked', Inbox::item_rows((int) $suggested['item_id'])[0]['state']);
		$this->assertCount(1, Orders::shipments($order['id']));

		$this->assertSame(1, $bulk('ignore', array($stranger_row))->get_data()['done']);
		$this->assertSame('ignored', Inbox::item_rows((int) $stranger['item_id'])[0]['state']);
		$this->assertSame(1, $bulk('reopen', array($stranger_row))->get_data()['done']);
		$this->assertSame('unmatched', Inbox::item_rows((int) $stranger['item_id'])[0]['state']);
		$this->assertSame(400, $bulk('purge', array($stranger_row))->get_status(), 'unknown actions are refused');

		$data = $bulk('delete', array($suggested_row, $stranger_row))->get_data();
		$this->assertSame(1, $data['done']);
		$this->assertStringContains('already linked', $data['message'], 'linked items are never deleted');
		$this->assertSame(array(), Inbox::item_rows((int) $stranger['item_id']));
		$this->assertSame('linked', Inbox::item_rows((int) $suggested['item_id'])[0]['state']);
		$this->assertCount(1, Orders::shipments($order['id']));
		wp_set_current_user(0);
		$this->assertSame('unmatched', $this->sync(array($stranger))['results'][0]['outcome'], 'a deleted item comes back if B2Sign sends it again');
	}

	public function test_staff_removal_sticks(): void {
		$order = $this->order(array('items' => array('A', 'B')));
		$record = $this->record(array('ship_to' => array('fullname' => 'Removal Case', 'company' => '', 'city' => 'Q', 'state' => 'ZZ', 'zipcode' => '00005')));
		add_post_meta($order['id'], Orders::META_B2_ORDER, $record['order_number']);
		$this->sync(array($record));
		$key = 'ups:' . $record['packages'][0]['tn'];
		$user = get_users(array('role' => 'administrator', 'number' => 1))[0];
		wp_set_current_user($user->ID);
		$request = new \WP_REST_Request('DELETE', '/sfs-tracking/v1/admin/shipment');
		$request->set_param('order_id', $order['id']);
		$request->set_param('key', $key);
		$response = rest_do_request($request);
		wp_set_current_user(0);
		$this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
		$this->assertSame(array(), Orders::shipments($order['id']));
		$this->assertSame(array((int) $record['item_id']), Orders::excluded($order['id']));
		$this->assertSame('unmatched', $this->sync(array($record))['results'][0]['outcome'], 'never linked back to this order');
		$this->assertSame(array(), Orders::shipments($order['id']));
	}
}

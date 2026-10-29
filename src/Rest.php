<?php
/**
 * REST routes under sfs-tracking/v1. Extension routes are HMAC-signed (Auth::signed); /track is public with a
 * signed token; admin routes use the logged-in cookie + X-WP-Nonce and per-order capability checks.
 * Every response from this namespace is no-store.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

use SFS\OrderTracking\Core\TrackingUrls;

final class Rest {
	public const NS = 'sfs-tracking/v1';

	public static function register(): void {
		register_rest_route(self::NS, '/ping', array(
			'methods' => 'GET',
			'callback' => array(self::class, 'ping'),
			'permission_callback' => array(Auth::class, 'signed'),
		));
		register_rest_route(self::NS, '/sync', array(
			'methods' => 'POST',
			'callback' => array(self::class, 'sync'),
			'permission_callback' => array(Auth::class, 'signed'),
		));
		register_rest_route(self::NS, '/track', array(
			'methods' => 'POST',
			'callback' => array(PublicView::class, 'rest_track'),
			'permission_callback' => '__return_true',
			'args' => array('t' => array('type' => 'string', 'required' => true, 'maxLength' => 400)),
		));

		$order_arg = array('order_id' => array('type' => 'integer', 'required' => true, 'minimum' => 1));
		register_rest_route(self::NS, '/admin/check-now', array(
			'methods' => 'POST',
			'callback' => array(self::class, 'check_now'),
			'permission_callback' => array(self::class, 'can_edit_order'),
			'args' => $order_arg + array('key' => array('type' => 'string', 'default' => '')),
		));
		register_rest_route(self::NS, '/admin/shipment', array(
			array(
				'methods' => 'POST',
				'callback' => array(self::class, 'add_shipment'),
				'permission_callback' => array(self::class, 'can_edit_order'),
				'args' => $order_arg + array('carrier' => array('type' => 'string', 'default' => ''), 'tracking_number' => array('type' => 'string', 'required' => true)),
			),
			array(
				'methods' => 'DELETE',
				'callback' => array(self::class, 'remove_shipment'),
				'permission_callback' => array(self::class, 'can_edit_order'),
				'args' => $order_arg + array('key' => array('type' => 'string', 'required' => true)),
			),
		));
		register_rest_route(self::NS, '/admin/link-now', array(
			'methods' => 'POST',
			'callback' => array(self::class, 'link_now'),
			'permission_callback' => array(self::class, 'can_edit_order'),
			'args' => $order_arg,
		));
		register_rest_route(self::NS, '/admin/inbox/bulk', array(
			'methods' => 'POST',
			'callback' => array(self::class, 'inbox_bulk'),
			'permission_callback' => array(self::class, 'can_review'),
			'args' => array(
				'action' => array('type' => 'string', 'required' => true, 'enum' => array('confirm', 'ignore', 'reopen')),
				'ids' => array('type' => 'array', 'required' => true, 'items' => array('type' => 'integer', 'minimum' => 1), 'maxItems' => 100),
			),
		));
		register_rest_route(self::NS, '/admin/inbox/(?P<action>confirm|ignore|reopen)', array(
			'methods' => 'POST',
			'callback' => array(self::class, 'inbox_action'),
			'permission_callback' => array(self::class, 'can_review'),
			'args' => array('id' => array('type' => 'integer', 'required' => true, 'minimum' => 1), 'order_number' => array('type' => 'string', 'default' => '')),
		));
	}

	/**
	 * @param \WP_REST_Response|\WP_HTTP_Response|mixed $response
	 */
	public static function no_store($response, $server, \WP_REST_Request $request) {
		if ($response instanceof \WP_HTTP_Response && 0 === strpos($request->get_route(), '/' . self::NS . '/')) {
			$response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
			$response->header('Pragma', 'no-cache');
			$response->header('Expires', 'Wed, 11 Jan 1984 05:00:00 GMT');
			$response->header('X-Robots-Tag', 'noindex, nofollow');
		}
		return $response;
	}

	public static function ping(): array {
		return array('ok' => true, 'site' => home_url('/'), 'plugin_version' => SFS_TRACKING_VERSION, 'dry_run' => Settings::dry_run(), 'server_time' => time());
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function sync(\WP_REST_Request $request) {
		$data = json_decode($request->get_body(), true);
		if (JSON_ERROR_NONE !== json_last_error()) {
			return new \WP_Error('sfs_bad_json', 'The body is not valid JSON.', array('status' => 400));
		}
		return SyncService::handle($data);
	}

	/**
	 * @return bool|\WP_Error
	 */
	public static function can_edit_order(\WP_REST_Request $request) {
		$order_id = (int) $request->get_param('order_id');
		if ($order_id <= 0 || 'order' !== get_post_type($order_id)) {
			return new \WP_Error('sfs_no_order', 'Order not found.', array('status' => 404));
		}
		return current_user_can('edit_post', $order_id);
	}

	public static function can_review(): bool {
		return current_user_can('edit_others_posts');
	}

	public static function check_now(\WP_REST_Request $request): array {
		$order_id = (int) $request->get_param('order_id');
		$key = (string) $request->get_param('key');
		$shipments = Orders::shipments($order_id);
		$until = time() + 1800;
		$count = 0;
		foreach ($shipments as $shipment_key => $shipment) {
			if (('' === $key || $key === $shipment_key) && TrackingUrls::checkable((string) $shipment['carrier']) && 'delivered' !== $shipment['status']) {
				$shipments[$shipment_key]['priority_until'] = $until;
				$count++;
			}
		}
		Orders::save_shipments($order_id, $shipments);
		return array('ok' => true, 'queued' => $count, 'message' => $count ? 'Queued: the extension reads it first on its next run (within 10 minutes while Chrome is open).' : 'Nothing to check.');
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function add_shipment(\WP_REST_Request $request) {
		$order_id = (int) $request->get_param('order_id');
		$number = TrackingUrls::normalize_number((string) $request->get_param('tracking_number'));
		$carrier = strtolower((string) $request->get_param('carrier'));
		if ('' === $carrier) {
			$carrier = TrackingUrls::guess_carrier($number);
		}
		if (!OrderHooks::valid_manual($carrier, $number)) {
			return new \WP_Error('sfs_bad_tracking', 'Enter a valid tracking number for the chosen carrier.', array('status' => 400));
		}
		$shipments = OrderHooks::with_manual(Orders::shipments($order_id), $carrier, $number, false);
		Orders::save_shipments($order_id, $shipments);
		Orders::add_activity($order_id, 'Shipment added by staff: ' . (TrackingUrls::NAMES[$carrier] ?? $carrier) . ' ' . Log::mask($number));
		return array('ok' => true, 'message' => 'Shipment added.');
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function remove_shipment(\WP_REST_Request $request) {
		$order_id = (int) $request->get_param('order_id');
		$key = (string) $request->get_param('key');
		$shipments = Orders::shipments($order_id);
		if (!isset($shipments[$key])) {
			return new \WP_Error('sfs_no_shipment', 'That shipment is not on this order.', array('status' => 404));
		}
		$removed = $shipments[$key];
		unset($shipments[$key]);
		$item_id = (int) ($removed['b2']['item_id'] ?? 0);
		if ($item_id) {
			$left = array_filter($shipments, static function ($shipment) use ($item_id) {
				return (int) ($shipment['b2']['item_id'] ?? 0) === $item_id;
			});
			if (!$left) {
				// The sync would add it back: remember that this supplier item is not part of this order.
				Orders::exclude_item($order_id, $item_id);
				Orders::unlink_item($order_id, $item_id);
			}
		}
		Orders::save_shipments($order_id, $shipments);
		if (!$shipments && $removed['tracking_number'] === get_post_meta($order_id, '_tracking_number', true)) {
			update_post_meta($order_id, '_tracking_number', '');
			update_post_meta($order_id, '_tracking_carrier', '');
		}
		Orders::add_activity($order_id, 'Shipment removed by staff: ' . (TrackingUrls::NAMES[$removed['carrier']] ?? $removed['carrier']) . ' ' . Log::mask((string) $removed['tracking_number']));
		return array('ok' => true, 'message' => 'Shipment removed.' . ($item_id ? ' The supplier item will not be linked to this order again.' : ''));
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function link_now(\WP_REST_Request $request) {
		$results = SyncService::link_order((int) $request->get_param('order_id'));
		if (is_wp_error($results)) {
			return $results;
		}
		$messages = array_map(static function ($result) {
			return ucfirst(str_replace('_', ' ', $result['outcome'])) . ': ' . $result['message'];
		}, $results);
		return array('ok' => true, 'results' => $results, 'message' => $messages ? implode(' ', $messages) : 'No supplier items with these B2Sign order numbers have synced yet. They link on the next sync.');
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function inbox_action(\WP_REST_Request $request) {
		$row = Inbox::get((int) $request->get_param('id'));
		if (!$row) {
			return new \WP_Error('sfs_no_row', 'That review item no longer exists.', array('status' => 404));
		}
		$done = self::apply_inbox($row, (string) $request->get_param('action'), (string) $request->get_param('order_number'));
		return $done['ok'] ? $done : new \WP_Error('sfs_inbox_action', $done['message'], array('status' => $done['status']));
	}

	/**
	 * Bulk actions in the review queue: confirm each item's top suggested order, mark not ours, or reopen.
	 *
	 * @return array{ok:bool,done:int,skipped:int,message:string,details:string[]}
	 */
	public static function inbox_bulk(\WP_REST_Request $request): array {
		$action = (string) $request->get_param('action');
		$done = 0;
		$details = array();
		foreach (array_slice(array_unique(array_map('intval', (array) $request->get_param('ids'))), 0, 100) as $id) {
			$row = Inbox::get($id);
			if (!$row) {
				continue;
			}
			$label = ($row['record']['jobid'] ?? '') ?: ($row['record']['order_number'] ?? '#' . $id);
			if ('confirm' === $action) {
				$top = $row['candidates'][0]['order_number'] ?? '';
				if ('' === $top) {
					$details[] = $label . ': no suggested order, skipped';
					continue;
				}
				$result = self::apply_inbox($row, 'confirm', (string) $top);
			} else {
				$result = self::apply_inbox($row, $action, '');
			}
			if ($result['ok']) {
				$done++;
			} else {
				$details[] = $label . ': ' . $result['message'];
			}
		}
		$words = array('confirm' => 'confirmed', 'ignore' => 'marked not ours', 'reopen' => 'reopened');
		$message = sprintf('%d item(s) %s.', $done, $words[$action] ?? $action) . ($details ? ' Skipped: ' . implode('; ', $details) . '.' : '');
		return array('ok' => true, 'done' => $done, 'skipped' => count($details), 'message' => $message, 'details' => $details);
	}

	/**
	 * One review-queue action on one item.
	 *
	 * @return array{ok:bool,message:string,status:int}
	 */
	private static function apply_inbox(array $row, string $action, string $order_number): array {
		$item_id = (int) $row['item_id'];
		if ('ignore' === $action) {
			Inbox::set_state($item_id, 'ignored');
			Log::add('action', 'Supplier item marked not ours', array('item' => $item_id));
			return array('ok' => true, 'message' => 'Marked not ours. It will not be matched again.', 'status' => 200);
		}
		if ('reopen' === $action) {
			Inbox::set_state($item_id, 'unmatched');
			return array('ok' => true, 'message' => 'Back in the review queue.', 'status' => 200);
		}
		$number = (string) preg_replace('/^#?(order_)?/i', '', trim($order_number));
		$order_id = Orders::find_by_number(strtoupper($number));
		if (!$order_id) {
			return array('ok' => false, 'message' => 'No single order has that number.', 'status' => 404);
		}
		if (!current_user_can('edit_post', $order_id)) {
			return array('ok' => false, 'message' => 'You cannot edit that order.', 'status' => 403);
		}
		$number = Orders::number($order_id);
		Inbox::confirm($item_id, $number);
		Log::add('action', 'Staff confirmed supplier item for order #' . $number, array('order' => $number, 'item' => $item_id));
		$results = SyncService::link_order($order_id, array($item_id));
		$message = is_wp_error($results) || !$results ? 'Confirmed. It links on the next sync.' : $results[0]['message'];
		return array('ok' => true, 'message' => 'Confirmed for order #' . $number . '. ' . $message, 'status' => 200);
	}
}

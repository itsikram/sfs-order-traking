<?php
/**
 * Order lookups and the tracking meta on order posts. Orders are matched by order number, never by post ID
 * (database copies renumber posts). All meta here lives on order posts, which a push replaces from live with
 * their meta, so it survives pushes.
 *
 * Meta: _sfs_shipments (shipments keyed "carrier:tracking", first = primary), _sfs_check_due (earliest due check,
 * 0 while a fresh reading is wanted), _sfs_tn (one row per "carrier:tracking", for lookups), _sfs_b2_item (one row
 * per linked B2Sign item id), _sfs_b2_order (one row per B2Sign order number staff typed on the order),
 * _sfs_b2_items (staff-only list of linked B2Sign items), _sfs_b2_excluded (items staff removed from this order),
 * _sfs_activity (staff-only notes), _sfs_auto_shipped (time the sync marked the order Shipped).
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

use SFS\OrderTracking\Core\CheckScheduler;
use SFS\OrderTracking\Core\OrderNumberExtractor;
use SFS\OrderTracking\Core\ShipmentMerger;

final class Orders {
	public const META_SHIPMENTS = '_sfs_shipments';
	public const META_DUE = '_sfs_check_due';
	public const META_TN = '_sfs_tn';
	public const META_ITEM = '_sfs_b2_item';
	public const META_B2_ORDER = '_sfs_b2_order';
	public const META_ITEMS = '_sfs_b2_items';
	public const META_EXCLUDED = '_sfs_b2_excluded';
	public const META_ACTIVITY = '_sfs_activity';
	public const META_AUTO_SHIPPED = '_sfs_auto_shipped';

	/** Statuses the theme defines; posts in any other state (trash, drafts) are never touched. */
	public const STATUSES = array('pending', 'processing', 'finishing', 'ready-for-pickup', 'on-hold', 'on_hold', 'completed', 'in-transit', 'out-for-delivery', 'delivered', 'delivery-issue', 'returned', 'cancelled', 'refunded', 'failed');

	/**
	 * Post IDs of orders with a meta row of this value.
	 *
	 * @return int[]
	 */
	public static function ids_by_meta(string $key, string $value): array {
		global $wpdb;
		$statuses = implode(',', array_fill(0, count(self::STATUSES), '%s'));
		$sql = $wpdb->prepare(
			"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = 'order' AND p.post_status IN ({$statuses}) ORDER BY pm.post_id",
			array_merge(array($key, $value), self::STATUSES)
		); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map('intval', (array) $wpdb->get_col($sql)); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Orders with this customer-facing number (current "order_" form or a legacy id).
	 *
	 * @return int[]
	 */
	public static function ids_by_number(string $number): array {
		global $wpdb;
		$values = OrderNumberExtractor::order_id_values($number);
		$sql = $wpdb->prepare(
			"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = 'order_id' AND pm.meta_value IN (" . implode(',', array_fill(0, count($values), '%s')) . ") AND p.post_type = 'order' AND p.post_status IN (" . implode(',', array_fill(0, count(self::STATUSES), '%s')) . ") ORDER BY pm.post_id",
			array_merge($values, self::STATUSES)
		); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map('intval', (array) $wpdb->get_col($sql)); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function find_by_number(string $number): int {
		$ids = self::ids_by_number($number);
		return 1 === count($ids) ? $ids[0] : 0;
	}

	/** @return int[] */
	public static function by_b2_order(string $b2_number): array {
		return self::ids_by_meta(self::META_B2_ORDER, $b2_number);
	}

	/** @return int[] */
	public static function by_item(int $item_id): array {
		return self::ids_by_meta(self::META_ITEM, (string) $item_id);
	}

	/** @return int[] */
	public static function by_tracking(string $carrier, string $number): array {
		return self::ids_by_meta(self::META_TN, $carrier . ':' . $number);
	}

	public static function number(int $order_id): string {
		if (function_exists('wholesale_order_number')) {
			return (string) wholesale_order_number($order_id);
		}
		$order_id_meta = (string) get_post_meta($order_id, 'order_id', true);
		return strtoupper((string) preg_replace('/^order_/i', '', $order_id_meta ?: (string) $order_id));
	}

	/**
	 * @return array<string,array>
	 */
	public static function shipments(int $order_id): array {
		$shipments = get_post_meta($order_id, self::META_SHIPMENTS, true);
		return is_array($shipments) ? $shipments : array();
	}

	/**
	 * Saves shipments and keeps the lookup rows, the due index and the theme's primary tracking fields in step.
	 * Writes nothing when nothing changed.
	 *
	 * @param array<string,array> $shipments First entry is the primary package.
	 */
	public static function save_shipments(int $order_id, array $shipments): bool {
		$changed = false;
		if (self::shipments($order_id) !== $shipments) {
			if ($shipments) {
				update_post_meta($order_id, self::META_SHIPMENTS, wp_slash($shipments));
			} else {
				delete_post_meta($order_id, self::META_SHIPMENTS);
			}
			$changed = true;
		}

		$want = array_keys($shipments);
		$have = array_map('strval', (array) get_post_meta($order_id, self::META_TN));
		foreach (array_diff($want, $have) as $key) {
			add_post_meta($order_id, self::META_TN, $key);
		}
		foreach (array_diff($have, $want) as $key) {
			delete_post_meta($order_id, self::META_TN, $key);
		}

		$now = time();
		$due = null;
		foreach ($shipments as $shipment) {
			$at = CheckScheduler::due_at($shipment, $now);
			if (null !== $at && (null === $due || $at < $due)) {
				$due = $at;
			}
		}
		if (null === $due) {
			delete_post_meta($order_id, self::META_DUE);
		} elseif ((string) $due !== (string) get_post_meta($order_id, self::META_DUE, true)) {
			update_post_meta($order_id, self::META_DUE, $due);
		}

		// The theme's emails, My Orders, status box and quick edit read one tracking pair: keep it on the primary.
		$primary = $shipments ? reset($shipments) : null;
		if ($primary) {
			if ($primary['carrier'] !== get_post_meta($order_id, '_tracking_carrier', true)) {
				update_post_meta($order_id, '_tracking_carrier', $primary['carrier']);
			}
			if ($primary['tracking_number'] !== get_post_meta($order_id, '_tracking_number', true)) {
				update_post_meta($order_id, '_tracking_number', $primary['tracking_number']);
			}
		}
		return $changed;
	}

	/** @return string[] */
	public static function b2_numbers(int $order_id): array {
		return array_values(array_map('strval', (array) get_post_meta($order_id, self::META_B2_ORDER)));
	}

	/**
	 * B2Sign order numbers of the supplier items linked to this order that staff did not type (auto-detected from the
	 * ship-to address, our order number in the job name, or a review-queue confirmation). Display only.
	 *
	 * @return string[]
	 */
	public static function detected_b2_numbers(int $order_id): array {
		$typed = array_map('strtoupper', self::b2_numbers($order_id));
		$found = array();
		foreach (self::items($order_id) as $item) {
			$number = strtoupper((string) ($item['b2_order'] ?? ''));
			if ('' !== $number && !in_array($number, $typed, true)) {
				$found[$number] = true;
			}
		}
		return array_keys($found);
	}

	/**
	 * B2Sign order numbers staff typed on open or shipped orders that the site has no supplier record for yet: never
	 * sent, deleted from the review queue, or acknowledged before the number was typed. The sync answer asks the
	 * extension to send these items again right away, so typing the number is enough.
	 *
	 * @return string[]
	 */
	public static function wanted_b2_numbers(int $limit = 100): array {
		global $wpdb;
		$statuses = array_merge(Effects::OPEN, Effects::SHIPPED);
		$in = implode(',', array_fill(0, count($statuses), '%s'));
		$rows = (array) $wpdb->get_results($wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND p.post_type = 'order' AND p.post_status IN ({$in}) ORDER BY p.post_date DESC LIMIT %d",
			array_merge(array(self::META_B2_ORDER), $statuses, array($limit))
		), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		$wanted = array();
		foreach ($rows as $row) {
			if (!in_array($row['meta_value'], array_column(self::items((int) $row['post_id']), 'b2_order'), true)) {
				$wanted[(string) $row['meta_value']] = true;
			}
		}
		return $wanted ? array_values(array_diff(array_keys($wanted), Inbox::stored_b2_numbers(array_keys($wanted)))) : array();
	}

	/**
	 * @param string[] $numbers Validated B2Sign order numbers.
	 * @return bool True when the list changed.
	 */
	public static function set_b2_numbers(int $order_id, array $numbers): bool {
		$numbers = array_values(array_unique(array_filter($numbers, array(OrderNumberExtractor::class, 'is_b2_number'))));
		$have = self::b2_numbers($order_id);
		foreach (array_diff($numbers, $have) as $number) {
			add_post_meta($order_id, self::META_B2_ORDER, $number);
		}
		foreach (array_diff($have, $numbers) as $number) {
			delete_post_meta($order_id, self::META_B2_ORDER, $number);
			self::forget_b2_order($order_id, $number);
		}
		return array_diff($numbers, $have) || array_diff($have, $numbers);
	}

	/**
	 * A B2Sign order number staff removed from this order (typed on the wrong one): unlinks its supplier items and
	 * their packages and reopens them, so the next sync links them to the order that has the number. Without this
	 * the old link kept the item here and the right order only got "Several orders match" in the review queue.
	 * Items staff confirmed for this order in the review queue stay.
	 *
	 * @return int Items unlinked.
	 */
	public static function forget_b2_order(int $order_id, string $b2_number): int {
		$number = self::number($order_id);
		$forget = array();
		foreach (self::items($order_id) as $item_id => $item) {
			if (0 === strcasecmp((string) $item['b2_order'], $b2_number) && Inbox::confirmed_order((int) $item_id) !== $number) {
				$forget[] = (int) $item_id;
			}
		}
		if (!$forget) {
			return 0;
		}

		$dropped = self::detach_items($order_id, $forget);
		foreach ($forget as $item_id) {
			// Still on another order, or confirmed for one: its review-queue row already says where it belongs.
			if (!self::by_item($item_id) && '' === Inbox::confirmed_order($item_id) && !Inbox::is_ignored($item_id)) {
				Inbox::set_state($item_id, 'unmatched');
			}
		}
		self::add_activity($order_id, 'B2Sign ' . strtoupper($b2_number) . ' removed: ' . count($forget) . (1 === count($forget) ? ' supplier item' : ' supplier items') . ' and ' . $dropped . (1 === $dropped ? ' package' : ' packages') . ' unlinked from this order');
		return count($forget);
	}

	/**
	 * Takes supplier items off an order: their packages (a box shared with other items linked here stays, without
	 * them), their links and their staff notes. When nothing from the supplier is left on the order, the marks those
	 * items left (auto Shipped, auto stage) are cleared, so the right supplier order can still ship it automatically.
	 * The order status itself is never changed here: staff check it.
	 *
	 * @param int[] $item_ids
	 * @return int Packages removed from the order.
	 */
	public static function detach_items(int $order_id, array $item_ids): int {
		$item_ids = array_map('intval', $item_ids);
		$shipments = self::shipments($order_id);
		$dropped = array();
		foreach ($shipments as $key => $shipment) {
			$ids = ShipmentMerger::item_ids($shipment);
			if (!array_intersect($ids, $item_ids)) {
				continue;
			}
			$left = array_values(array_diff($ids, $item_ids));
			if (!$left) {
				$dropped[] = $shipment;
				unset($shipments[$key]);
				continue;
			}
			$shipments[$key]['b2']['item_ids'] = $left;
			$shipments[$key]['b2']['item_id'] = $left[0];
		}
		$primary = (string) get_post_meta($order_id, '_tracking_number', true);
		self::save_shipments($order_id, $shipments);
		if (!$shipments && in_array($primary, array_column($dropped, 'tracking_number'), true)) {
			update_post_meta($order_id, '_tracking_number', '');
			update_post_meta($order_id, '_tracking_carrier', '');
		}
		foreach ($item_ids as $item_id) {
			self::unlink_item($order_id, $item_id);
		}
		if (!$shipments && !self::items($order_id)) {
			delete_post_meta($order_id, self::META_AUTO_SHIPPED);
			delete_post_meta($order_id, Effects::META_STAGE);
		}
		return count($dropped);
	}

	/**
	 * Linked B2Sign items (staff only): item_id => {item_id, b2_order, jobid, title, quantity, status, is_reprint, packages, updated}.
	 */
	public static function items(int $order_id): array {
		$items = get_post_meta($order_id, self::META_ITEMS, true);
		return is_array($items) ? $items : array();
	}

	public static function save_items(int $order_id, array $items): bool {
		if (self::items($order_id) === $items) {
			return false;
		}
		if ($items) {
			update_post_meta($order_id, self::META_ITEMS, wp_slash($items));
		} else {
			delete_post_meta($order_id, self::META_ITEMS);
		}
		return true;
	}

	public static function link_item(int $order_id, int $item_id): bool {
		if (in_array((string) $item_id, array_map('strval', (array) get_post_meta($order_id, self::META_ITEM)), true)) {
			return false;
		}
		add_post_meta($order_id, self::META_ITEM, (string) $item_id);
		return true;
	}

	public static function unlink_item(int $order_id, int $item_id): void {
		delete_post_meta($order_id, self::META_ITEM, (string) $item_id);
		$items = self::items($order_id);
		unset($items[$item_id]);
		self::save_items($order_id, $items);
	}

	/** @return int[] Items staff removed from this order; a sync never links them here again. */
	public static function excluded(int $order_id): array {
		$excluded = get_post_meta($order_id, self::META_EXCLUDED, true);
		return is_array($excluded) ? array_map('intval', $excluded) : array();
	}

	public static function exclude_item(int $order_id, int $item_id): void {
		$excluded = self::excluded($order_id);
		if (!in_array($item_id, $excluded, true)) {
			$excluded[] = $item_id;
			update_post_meta($order_id, self::META_EXCLUDED, $excluded);
		}
	}

	/**
	 * Number of line items in our order (product_json).
	 */
	public static function line_count(int $order_id): int {
		return count(self::decode(get_post_meta($order_id, 'product_json', true)));
	}

	/**
	 * Ship-to city, state and ZIP: the shipping address, or billing when the order has none.
	 *
	 * @return array{city:string,state:string,zip:string}
	 */
	public static function destination(int $order_id): array {
		$shipping = self::decode(get_post_meta($order_id, 'shipping_address', true));
		$prefix = isset($shipping['shipping_fname']) || isset($shipping['shipping_city']) ? 'shipping_' : 'billing_';
		if ('billing_' === $prefix) {
			$shipping = self::decode(get_post_meta($order_id, 'billing_address', true));
		}
		return array(
			'city' => (string) ($shipping[$prefix . 'city'] ?? ''),
			'state' => (string) ($shipping[$prefix . 'state'] ?? ''),
			'zip' => (string) ($shipping[$prefix . 'zip'] ?? ''),
		);
	}

	/**
	 * Orders a B2Sign item could belong to: open or shipped orders placed 21 days before to 1 day after the
	 * B2Sign purchase. ZIP and names are compared by Matcher.
	 */
	public static function candidates(int $purchase_time): array {
		$ids = get_posts(array(
			'post_type' => 'order',
			'post_status' => array_merge(Effects::OPEN, Effects::SHIPPED),
			'date_query' => array(array(
				'column' => 'post_date_gmt',
				'after' => gmdate('Y-m-d H:i:s', $purchase_time - 21 * DAY_IN_SECONDS),
				'before' => gmdate('Y-m-d H:i:s', $purchase_time + DAY_IN_SECONDS),
				'inclusive' => true,
			)),
			'posts_per_page' => 200,
			'fields' => 'ids',
			'no_found_rows' => true,
			'orderby' => 'date',
			'order' => 'DESC',
		));
		return array_map(array(self::class, 'candidate'), array_map('intval', $ids));
	}

	/**
	 * One order as Matcher compares it: ship-to and billing names and places, line titles, placed time.
	 *
	 * @return array{order_id:int,order_number:string,created:int,ship:array,bill:array,titles:string[]}
	 */
	public static function candidate(int $order_id): array {
		$shipping = self::decode(get_post_meta($order_id, 'shipping_address', true));
		$billing = self::decode(get_post_meta($order_id, 'billing_address', true));
		$titles = array();
		foreach (self::decode(get_post_meta($order_id, 'product_json', true)) as $item) {
			if (is_array($item) && !empty($item['product_title'])) {
				$titles[] = (string) $item['product_title'];
			}
		}
		return array(
			'order_id' => $order_id,
			'order_number' => self::number($order_id),
			'created' => (int) get_post_time('U', true, $order_id),
			'ship' => self::address($shipping, 'shipping_'),
			'bill' => self::address($billing, 'billing_'),
			'titles' => $titles,
		);
	}

	/**
	 * Orders with a carrier check due now (or a fresh reading wanted), soonest first.
	 *
	 * @return array<int,array<string,array>> order_id => shipments
	 */
	public static function due(int $now, int $limit = 100): array {
		global $wpdb;
		$ids = (array) $wpdb->get_col($wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND CAST(pm.meta_value AS UNSIGNED) <= %d AND p.post_type = 'order' AND p.post_status NOT IN ('trash','auto-draft')
			ORDER BY CAST(pm.meta_value AS UNSIGNED) ASC LIMIT %d",
			self::META_DUE,
			$now,
			$limit
		)); // phpcs:ignore WordPress.DB.PreparedSQL
		$due = array();
		foreach ($ids as $id) {
			$due[(int) $id] = self::shipments((int) $id);
		}
		return $due;
	}

	/**
	 * Orders that still have packages being checked (for the health page).
	 *
	 * @return array<int,array<string,array>>
	 */
	public static function active(int $limit = 300): array {
		global $wpdb;
		$ids = (array) $wpdb->get_col($wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND p.post_type = 'order' AND p.post_status NOT IN ('trash','auto-draft') ORDER BY pm.post_id DESC LIMIT %d",
			self::META_DUE,
			$limit
		)); // phpcs:ignore WordPress.DB.PreparedSQL
		$active = array();
		foreach ($ids as $id) {
			$active[(int) $id] = self::shipments((int) $id);
		}
		return $active;
	}

	/**
	 * Staff-only note shown in the order's Activity box ("Tracking synced from supplier").
	 */
	public static function add_activity(int $order_id, string $text): void {
		$activity = get_post_meta($order_id, self::META_ACTIVITY, true);
		$activity = is_array($activity) ? $activity : array();
		$activity[] = array('time' => time(), 'text' => mb_substr($text, 0, 200));
		update_post_meta($order_id, self::META_ACTIVITY, wp_slash(array_slice($activity, -50)));
	}

	/**
	 * @return array<int,array{time:int,text:string}>
	 */
	public static function activity(int $order_id): array {
		$activity = get_post_meta($order_id, self::META_ACTIVITY, true);
		return is_array($activity) ? $activity : array();
	}

	/**
	 * @param mixed $value
	 */
	public static function decode($value): array {
		if (function_exists('wholesale_decode_order_meta_array')) {
			return (array) wholesale_decode_order_meta_array($value);
		}
		if (is_array($value)) {
			return $value;
		}
		$decoded = is_string($value) ? json_decode($value, true) : null;
		return is_array($decoded) ? $decoded : array();
	}

	private static function address(array $data, string $prefix): array {
		if (!isset($data[$prefix . 'fname']) && !isset($data[$prefix . 'zip'])) {
			return array();
		}
		$address = array();
		foreach (array('fname', 'lname', 'company', 'city', 'state', 'zip') as $field) {
			$address[$field] = (string) ($data[$prefix . $field] ?? '');
		}
		return $address;
	}
}

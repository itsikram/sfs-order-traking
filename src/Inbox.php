<?php
/**
 * B2Sign items that are not (yet) linked to one of our orders: the review queue. One row per package
 * (tracking_number '' for an item without packages). Rows keep the cleaned ShipmentRecord, so an item links
 * as soon as staff type its B2Sign order number on an order, without waiting for the extension.
 *
 * States: unmatched, needs_review, ignored (staff said "not ours"; sticky), linked (confirmed = staff chose the order).
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

final class Inbox {
	public const OPEN = array('unmatched', 'needs_review');

	/**
	 * Rows of one B2Sign item.
	 */
	public static function item_rows(int $item_id): array {
		global $wpdb;
		Schema::ensure();
		$table = Schema::inbox();
		return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` WHERE item_id = %d ORDER BY id", $item_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function is_ignored(int $item_id): bool {
		foreach (self::item_rows($item_id) as $row) {
			if ('ignored' === $row['state']) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Order number staff confirmed for this item in the review queue, or ''.
	 */
	public static function confirmed_order(int $item_id): string {
		foreach (self::item_rows($item_id) as $row) {
			if ('linked' === $row['state'] && (int) $row['confirmed'] && '' !== $row['order_number']) {
				return (string) $row['order_number'];
			}
		}
		return '';
	}

	/**
	 * Saves an unmatched or needs-review item. Writes only when something changed; "ignored" and staff-confirmed
	 * rows are never overwritten by a sync.
	 *
	 * @param array $record Validated ShipmentRecord.
	 * @return bool True when a row was written.
	 */
	public static function put(array $record, string $state, array $candidates, string $message): bool {
		global $wpdb;
		$table = Schema::inbox();
		$now = gmdate('Y-m-d H:i:s');
		$existing = array();
		foreach (self::item_rows((int) $record['item_id']) as $row) {
			$existing[$row['tracking_number']] = $row;
		}
		$numbers = $record['packages'] ? array_column($record['packages'], 'tn') : array('');
		$carriers = $record['packages'] ? array_column($record['packages'], 'carrier', 'tn') : array();
		$json = (string) wp_json_encode($record);
		$hash = sha1($json);
		$candidates_json = $candidates ? (string) wp_json_encode($candidates) : null;
		$wrote = false;
		foreach ($numbers as $number) {
			$row = $existing[$number] ?? null;
			if ($row && ('ignored' === $row['state'] || ('linked' === $row['state'] && (int) $row['confirmed']))) {
				continue;
			}
			$data = array(
				'carrier' => (string) ($carriers[$number] ?? ''),
				'b2_order_number' => $record['order_number'],
				'item_status' => $record['status'],
				'state' => $state,
				'confirmed' => 0,
				'order_number' => '',
				'record' => $json,
				'candidates' => $candidates_json,
				'record_hash' => $hash,
				'message' => mb_substr($message, 0, 255),
			);
			if ($row) {
				$same = $row['record_hash'] === $hash && $row['state'] === $state && (string) $row['candidates'] === (string) $candidates_json && $row['message'] === $data['message'] && '' === $row['order_number'];
				if ($same) {
					continue;
				}
				$wpdb->update($table, $data + array('last_seen' => $now, 'updated_at' => $now), array('id' => (int) $row['id']));
			} else {
				$wpdb->insert($table, $data + array('item_id' => (int) $record['item_id'], 'tracking_number' => $number, 'first_seen' => $now, 'last_seen' => $now, 'updated_at' => $now));
			}
			$wrote = true;
		}
		// Packages B2Sign no longer lists for this item (or the placeholder row once packages exist).
		foreach ($existing as $number => $row) {
			if (!in_array((string) $number, $numbers, true) && in_array($row['state'], self::OPEN, true)) {
				$wpdb->delete($table, array('id' => (int) $row['id']));
				$wrote = true;
			}
		}
		return $wrote;
	}

	/**
	 * Marks an item's rows linked to an order (after the sync attached it).
	 */
	public static function mark_linked(int $item_id, string $order_number): void {
		global $wpdb;
		$table = Schema::inbox();
		foreach (self::item_rows($item_id) as $row) {
			if ('linked' === $row['state'] && $row['order_number'] === $order_number) {
				continue;
			}
			if ('ignored' === $row['state']) {
				continue;
			}
			$wpdb->update($table, array('state' => 'linked', 'order_number' => $order_number, 'message' => 'Linked to order #' . $order_number, 'updated_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $row['id']));
		}
	}

	/**
	 * Staff confirmed the order for an item: every row of the item is linked to it and stays so.
	 */
	public static function confirm(int $item_id, string $order_number): void {
		global $wpdb;
		$wpdb->update(Schema::inbox(), array('state' => 'linked', 'confirmed' => 1, 'order_number' => $order_number, 'message' => 'Confirmed by staff for order #' . $order_number, 'updated_at' => gmdate('Y-m-d H:i:s')), array('item_id' => $item_id));
	}

	public static function set_state(int $item_id, string $state): void {
		global $wpdb;
		$wpdb->update(Schema::inbox(), array('state' => $state, 'confirmed' => 0, 'order_number' => '', 'message' => 'ignored' === $state ? 'Marked not ours by staff' : 'Reopened by staff', 'updated_at' => gmdate('Y-m-d H:i:s')), array('item_id' => $item_id));
	}

	/**
	 * Which of these B2Sign order numbers have at least one stored row (any state).
	 *
	 * @param string[] $b2_numbers
	 * @return string[]
	 */
	public static function stored_b2_numbers(array $b2_numbers): array {
		global $wpdb;
		if (!$b2_numbers) {
			return array();
		}
		Schema::ensure();
		$table = Schema::inbox();
		$in = implode(',', array_fill(0, count($b2_numbers), '%s'));
		return array_map('strval', (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT b2_order_number FROM `{$table}` WHERE b2_order_number IN ({$in})", array_values($b2_numbers)))); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Staff deleted an item from the review queue or the ignored list. Linked rows stay (orders depend on them).
	 * If B2Sign still lists the item, the extension's daily full sync adds it back as a new open row.
	 *
	 * @return int Rows removed.
	 */
	public static function delete_item(int $item_id): int {
		global $wpdb;
		$table = Schema::inbox();
		return (int) $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE item_id = %d AND state IN ('unmatched','needs_review','ignored')", $item_id)); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function get(int $id): ?array {
		global $wpdb;
		Schema::ensure();
		$table = Schema::inbox();
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::decode($row) : null;
	}

	/**
	 * Review queue: open rows, needs-review first, newest first.
	 *
	 * @param string[] $states
	 */
	public static function rows(array $states = self::OPEN, int $limit = 200): array {
		global $wpdb;
		Schema::ensure();
		$table = Schema::inbox();
		$in = implode(',', array_fill(0, count($states), '%s'));
		$sql = $wpdb->prepare("SELECT * FROM `{$table}` WHERE state IN ({$in}) ORDER BY state = 'needs_review' DESC, last_seen DESC, id DESC LIMIT %d", array_merge($states, array($limit))); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map(array(self::class, 'decode'), (array) $wpdb->get_results($sql, ARRAY_A)); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Items that can now be linked without the extension resending them: open items whose B2Sign order number staff
	 * have since typed on exactly one order (typed on several, they stay in review), and items staff confirmed
	 * while dry-run was on. One record per item.
	 *
	 * @return array<int,array> item_id => record
	 */
	public static function relinkable(int $limit = 50): array {
		global $wpdb;
		Schema::ensure();
		$table = Schema::inbox();
		$rows = (array) $wpdb->get_results($wpdb->prepare(
			"SELECT i.item_id, i.record FROM `{$table}` i
			WHERE (i.state IN ('unmatched','needs_review') AND i.b2_order_number IN (
					SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.meta_key = %s GROUP BY pm.meta_value HAVING COUNT(DISTINCT pm.post_id) = 1))
			   OR (i.state = 'linked' AND i.confirmed = 1 AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm2 WHERE pm2.meta_key = %s AND pm2.meta_value = CAST(i.item_id AS CHAR)))
			ORDER BY i.id LIMIT %d",
			Orders::META_B2_ORDER,
			Orders::META_ITEM,
			$limit
		), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		$records = array();
		foreach ($rows as $row) {
			$record = json_decode((string) $row['record'], true);
			if (is_array($record)) {
				$records[(int) $row['item_id']] = $record;
			}
		}
		return $records;
	}

	/**
	 * Stored records (not ignored) of items with these B2Sign order numbers or ids, one per item.
	 *
	 * @param string[] $b2_numbers
	 * @param int[] $item_ids
	 * @return array<int,array> item_id => record
	 */
	public static function records_for(array $b2_numbers, array $item_ids = array()): array {
		global $wpdb;
		Schema::ensure();
		$table = Schema::inbox();
		$where = array();
		$args = array();
		if ($b2_numbers) {
			$where[] = 'b2_order_number IN (' . implode(',', array_fill(0, count($b2_numbers), '%s')) . ')';
			$args = array_merge($args, array_values($b2_numbers));
		}
		if ($item_ids) {
			$where[] = 'item_id IN (' . implode(',', array_fill(0, count($item_ids), '%d')) . ')';
			$args = array_merge($args, array_map('intval', $item_ids));
		}
		if (!$where) {
			return array();
		}
		$sql = $wpdb->prepare("SELECT item_id, record FROM `{$table}` WHERE state <> 'ignored' AND (" . implode(' OR ', $where) . ') ORDER BY id LIMIT 100', $args); // phpcs:ignore WordPress.DB.PreparedSQL
		$records = array();
		foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) { // phpcs:ignore WordPress.DB.PreparedSQL
			$record = json_decode((string) $row['record'], true);
			if (is_array($record)) {
				$records[(int) $row['item_id']] = $record;
			}
		}
		return $records;
	}

	/**
	 * @return array<string,int> state => rows
	 */
	public static function counts(): array {
		global $wpdb;
		Schema::ensure();
		$table = Schema::inbox();
		$counts = array_fill_keys(array('unmatched', 'needs_review', 'ignored', 'linked'), 0);
		foreach ((array) $wpdb->get_results("SELECT state, COUNT(DISTINCT item_id) AS n FROM `{$table}` GROUP BY state", ARRAY_A) as $row) { // phpcs:ignore WordPress.DB.PreparedSQL
			$counts[$row['state']] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Forget rows not seen for 120 days, except open ones.
	 */
	public static function prune(): void {
		global $wpdb;
		$table = Schema::inbox();
		$wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE state IN ('linked','ignored') AND last_seen < %s", gmdate('Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS))); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function decode(array $row): array {
		$row['record'] = json_decode((string) $row['record'], true) ?: array();
		$row['candidates'] = $row['candidates'] ? (json_decode((string) $row['candidates'], true) ?: array()) : array();
		return $row;
	}
}

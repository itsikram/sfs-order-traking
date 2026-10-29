<?php
/**
 * Sync log: a ring buffer of the last 500 runs and actions in a live-owned table. Entries hold counts, our order
 * numbers and masked tracking numbers only — never names or addresses.
 *
 * Types: run (one per /sync call), action (something changed on an order), flag (needs staff attention),
 * wording (carrier text the status table does not know), health (staff alert sent).
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

final class Log {
	public const KEEP = 500;

	public static function add(string $type, string $message, array $context = array(), string $run_id = '', string $level = 'info'): void {
		global $wpdb;
		Schema::ensure();
		$table = Schema::log();
		$wpdb->insert($table, array(
			'created_at' => gmdate('Y-m-d H:i:s'),
			'type' => substr($type, 0, 20),
			'run_id' => substr($run_id, 0, 64),
			'level' => substr($level, 0, 10),
			'message' => mb_substr($message, 0, 255),
			'context' => $context ? (string) wp_json_encode($context) : null,
		));
		$cut = (int) $wpdb->insert_id - self::KEEP;
		if ($cut > 0) {
			$wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE id <= %d", $cut)); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * Newest first.
	 *
	 * @param string[] $types
	 */
	public static function recent(int $limit = 50, array $types = array()): array {
		global $wpdb;
		Schema::ensure();
		$table = Schema::log();
		$where = '';
		if ($types) {
			$where = 'WHERE type IN (' . implode(',', array_fill(0, count($types), '%s')) . ')';
			$where = $wpdb->prepare($where, $types); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` {$where} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ($rows as &$row) {
			$row['context'] = $row['context'] ? (array) json_decode((string) $row['context'], true) : array();
			$row['time'] = (int) strtotime($row['created_at'] . ' UTC');
		}
		return $rows;
	}

	/**
	 * The latest /sync call: the extension's heartbeat.
	 */
	public static function last_run(): ?array {
		$rows = self::recent(1, array('run'));
		return $rows[0] ?? null;
	}

	/**
	 * Whether this carrier wording was already logged in the last day (one entry per text per day).
	 */
	public static function wording_seen(string $carrier, string $text): bool {
		global $wpdb;
		Schema::ensure();
		$table = Schema::log();
		$hash = md5($carrier . '|' . mb_strtolower($text));
		return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE type = 'wording' AND run_id = %s AND created_at > %s LIMIT 1", $hash, gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS))); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function wording(string $carrier, string $text): void {
		if (!self::wording_seen($carrier, $text)) {
			self::add('wording', mb_substr($text, 0, 200), array('carrier' => $carrier), md5($carrier . '|' . mb_strtolower($text)));
		}
	}

	/**
	 * Unknown carrier wording seen recently, most frequent first.
	 *
	 * @return array<int,array{carrier:string,text:string,count:int,last:int}>
	 */
	public static function wording_list(): array {
		$list = array();
		foreach (self::recent(self::KEEP, array('wording')) as $row) {
			$key = $row['run_id'];
			if (!isset($list[$key])) {
				$list[$key] = array('carrier' => (string) ($row['context']['carrier'] ?? ''), 'text' => $row['message'], 'count' => 0, 'last' => $row['time']);
			}
			$list[$key]['count']++;
		}
		usort($list, static function ($a, $b) {
			return $b['count'] <=> $a['count'] ?: $b['last'] <=> $a['last'];
		});
		return $list;
	}

	public static function mask(string $tracking_number): string {
		return strlen($tracking_number) > 4 ? '…' . substr($tracking_number, -4) : $tracking_number;
	}
}

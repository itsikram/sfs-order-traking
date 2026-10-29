<?php
/**
 * When each package's carrier page should be read again.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class CheckScheduler {
	/** Minutes between checks per status (settings can override). */
	public const DEFAULT_MINUTES = array(
		'pre_transit' => 180,
		'in_transit' => 60,
		'out_for_delivery' => 20,
		'exception' => 60,
		'returned' => 120,
		'unknown' => 120,
	);
	public const MAX_AGE_DAYS = 45;
	public const MAX_ERRORS = 10;
	public const PRIORITY_SECONDS = 1800;

	/**
	 * Next check time with ±10% jitter, or null when this status needs no more checks.
	 *
	 * @param array<string,int> $minutes Overrides of DEFAULT_MINUTES.
	 * @param callable|null $rand Returns a float in [0, 1); tests pass a fixed value.
	 */
	public static function next(string $status, int $now, array $minutes = array(), ?callable $rand = null): ?int {
		if (in_array($status, array('delivered', 'cancelled'), true)) {
			return null;
		}
		$minutes = array_merge(self::DEFAULT_MINUTES, array_filter($minutes, 'is_int'));
		$base = 60 * max(5, (int) ($minutes[$status] ?? $minutes['unknown']));
		return $now + (int) round($base * (0.9 + 0.2 * self::random($rand)));
	}

	/**
	 * Retry after a failed reading: 15, 30, 60 minutes…, never longer than the status interval (and at least 15 min).
	 */
	public static function after_error(int $errors, string $status, int $now, array $minutes = array(), ?callable $rand = null): int {
		$minutes = array_merge(self::DEFAULT_MINUTES, array_filter($minutes, 'is_int'));
		$cap = 60 * max(15, (int) ($minutes[$status] ?? $minutes['unknown']));
		$wait = min($cap, 900 * (2 ** max(0, min(6, $errors - 1))));
		return $now + (int) round($wait * (0.9 + 0.2 * self::random($rand)));
	}

	/**
	 * Why checks stopped for a shipment, or '' while they continue.
	 */
	public static function stop_reason(array $shipment, int $now, int $max_age_days = self::MAX_AGE_DAYS): string {
		$status = (string) ($shipment['status'] ?? 'unknown');
		if ('delivered' === $status || 'cancelled' === $status) {
			return $status;
		}
		if (!TrackingUrls::checkable((string) ($shipment['carrier'] ?? ''))) {
			return 'carrier';
		}
		if ((int) ($shipment['check_errors'] ?? 0) >= self::MAX_ERRORS) {
			return 'errors';
		}
		$created = (int) ($shipment['created_at'] ?? 0);
		if ($created > 0 && $now - $created > $max_age_days * 86400) {
			return 'age';
		}
		return '';
	}

	/**
	 * Sort key for due checks: 0 while a customer or staff asked for a fresh reading, else next_check_at;
	 * null when checks stopped.
	 */
	public static function due_at(array $shipment, int $now, int $max_age_days = self::MAX_AGE_DAYS): ?int {
		if ('' !== self::stop_reason($shipment, $now, $max_age_days)) {
			return null;
		}
		if ((int) ($shipment['priority_until'] ?? 0) > $now) {
			return 0;
		}
		return (int) ($shipment['next_check_at'] ?? 0);
	}

	/**
	 * Picks the checks for one extension run: priority first, then the most overdue.
	 *
	 * @param array<int,array{shipment:array}> $items Each item carries its shipment plus any context the caller needs.
	 * @return array<int,array> The chosen items, each with 'reason' (priority|new|due) added.
	 */
	public static function select(array $items, int $now, int $max, int $max_age_days = self::MAX_AGE_DAYS): array {
		$due = array();
		foreach ($items as $item) {
			$at = self::due_at($item['shipment'], $now, $max_age_days);
			if (null === $at || $at > $now) {
				continue;
			}
			$item['reason'] = 0 === $at ? 'priority' : (0 === (int) ($item['shipment']['last_checked_at'] ?? 0) ? 'new' : 'due');
			$item['due'] = $at;
			$due[] = $item;
		}
		usort($due, static function ($a, $b) {
			return $a['due'] <=> $b['due'];
		});
		return array_slice($due, 0, max(0, $max));
	}

	private static function random(?callable $rand): float {
		$value = null === $rand ? mt_rand() / mt_getrandmax() : (float) $rand();
		return max(0.0, min(1.0, $value));
	}
}

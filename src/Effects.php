<?php
/**
 * What a sync may do to an order besides storing tracking: mark it Shipped (owner decision: yes, after a dry run),
 * mark it In production (on since 2026-10-29), and the optional extra-package email. Status changes go through wp_update_post(), so
 * the theme's status log and its single shipped email run exactly as when staff change the status.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

final class Effects {
	public const OPEN = array('pending', 'processing', 'on-hold', 'on_hold');
	public const CLOSED = array('cancelled', 'refunded', 'failed');
	/** Carrier statuses meaning the package left the shipper. */
	public const MOVING = array('in_transit', 'out_for_delivery', 'delivered');

	/**
	 * Effects due after a B2Sign item was linked or refreshed.
	 *
	 * @param array<string,array> $shipments The order's shipments after merging.
	 * @param array<int,array> $items The order's linked B2Sign items after merging.
	 * @param string[] $added Shipment keys new in this sync.
	 * @return string[] ship | production | extra_package_email
	 */
	public static function plan(int $order_id, string $status, array $shipments, array $items, array $added): array {
		$plans = array();
		$progress = self::progress($order_id, $shipments, $items);
		if (
			Settings::get('auto_ship')
			&& in_array($status, self::OPEN, true)
			&& $progress['all_shipped']
			&& !get_post_meta($order_id, Orders::META_AUTO_SHIPPED, true)
		) {
			$plans[] = 'ship';
		}
		if (
			Settings::get('auto_production')
			&& 'pending' === $status
			&& !in_array('ship', $plans, true)
			&& array_intersect(array_column($items, 'status'), Settings::production_statuses())
		) {
			$plans[] = 'production';
		}
		if ('completed' === $status && $added && Settings::get('email_extra_packages')) {
			$plans[] = 'extra_package_email';
		}
		return $plans;
	}

	/**
	 * Effects of a carrier reading: an order still Pending review / In production whose package is moving or
	 * delivered is marked Shipped. Covers tracking numbers staff typed (no supplier item) and supplier items that
	 * were never marked shipped. Orders with linked supplier items still waiting are left for the item rule.
	 *
	 * @param array<string,array> $shipments
	 * @return string[]
	 */
	public static function carrier_plan(int $order_id, string $status, array $shipments): array {
		if (!Settings::get('auto_ship') || !in_array($status, self::OPEN, true) || get_post_meta($order_id, Orders::META_AUTO_SHIPPED, true)) {
			return array();
		}
		$moving = array_filter($shipments, static function ($shipment) {
			return in_array($shipment['status'] ?? '', self::MOVING, true);
		});
		if (!$moving) {
			return array();
		}
		$items = Orders::items($order_id);
		if ($items && !self::progress($order_id, $shipments, $items)['all_shipped']) {
			return array();
		}
		return array('ship_carrier');
	}

	/**
	 * How much of the order has shipped. Every line of our order needs a linked B2Sign item that shipped with at
	 * least one package; refunded B2Sign items do not count either way.
	 *
	 * @return array{lines:int,shipped:int,all_shipped:bool,partial:bool}
	 */
	public static function progress(int $order_id, array $shipments, array $items): array {
		$with_packages = array();
		$b2_boxes = array();
		foreach ($shipments as $shipment) {
			foreach (Core\ShipmentMerger::item_ids($shipment) as $item_id) {
				$with_packages[$item_id] = true;
			}
			if (!empty($shipment['b2']['order_number'])) {
				$b2_boxes[(string) $shipment['b2']['order_number']] = true;
			}
		}
		$active = 0;
		$shipped = 0;
		foreach ($items as $item) {
			if ('refunded' === ($item['status'] ?? '')) {
				continue;
			}
			$active++;
			// Its own package, or (one box for several items, stored before item_ids) B2Sign lists a package for it and
			// a box of the same B2Sign order is on this order.
			$boxed = isset($with_packages[(int) $item['item_id']])
				|| ((int) ($item['packages'] ?? 0) > 0 && isset($b2_boxes[(string) ($item['b2_order'] ?? '')]));
			if (in_array($item['status'] ?? '', array('shipped', 'completed', 'delivered'), true) && $boxed) {
				$shipped++;
			}
		}
		$lines = max(1, Orders::line_count($order_id));
		$all = $active > 0 && $shipped === $active && $shipped >= $lines;
		return array(
			'lines' => $lines,
			'shipped' => $shipped,
			'all_shipped' => $all,
			// Some linked supplier items have not shipped yet.
			'partial' => $shipped > 0 && $shipped < $active,
			// Every linked item shipped, but the order has more lines (not linked yet, or combined by the supplier).
			'short' => $active > 0 && $shipped === $active && !$all,
		);
	}

	/**
	 * Applies planned effects. Tracking meta is already saved, so the theme's shipped email includes every package.
	 *
	 * @param string[] $plans
	 * @param array<string,array> $shipments
	 */
	public static function apply(int $order_id, array $plans, array $shipments, array $added, string $run_id): void {
		$number = Orders::number($order_id);
		if (in_array('ship', $plans, true)) {
			update_post_meta($order_id, Orders::META_AUTO_SHIPPED, time());
			wp_update_post(array('ID' => $order_id, 'post_status' => 'completed'));
			Orders::add_activity($order_id, 'Marked Shipped automatically: every item has shipped.');
			Log::add('action', 'Marked order #' . $number . ' Shipped', array('order' => $number), $run_id);
		}
		if (in_array('ship_carrier', $plans, true)) {
			update_post_meta($order_id, Orders::META_AUTO_SHIPPED, time());
			wp_update_post(array('ID' => $order_id, 'post_status' => 'completed'));
			Orders::add_activity($order_id, 'Marked Shipped automatically: the carrier has the package.');
			Log::add('action', 'Marked order #' . $number . ' Shipped (carrier reading)', array('order' => $number), $run_id);
		}
		if (in_array('production', $plans, true)) {
			wp_update_post(array('ID' => $order_id, 'post_status' => 'processing'));
			Orders::add_activity($order_id, 'Marked In production automatically from the supplier status.');
			Log::add('action', 'Marked order #' . $number . ' In production', array('order' => $number), $run_id);
		}
		if (in_array('extra_package_email', $plans, true)) {
			foreach ($added as $key) {
				if (isset($shipments[$key]) && Emails::extra_package($order_id, $shipments[$key], array_search($key, array_keys($shipments), true) + 1, count($shipments))) {
					Log::add('action', 'Emailed another package for order #' . $number, array('order' => $number, 'tracking' => Log::mask($shipments[$key]['tracking_number'])), $run_id);
				}
			}
		}
	}

	/**
	 * Human description of planned effects, for dry-run answers and logs.
	 *
	 * @param string[] $plans
	 */
	public static function describe(array $plans): string {
		$words = array(
			'ship' => 'mark Shipped and send the shipped email',
			'ship_carrier' => 'mark Shipped (the carrier has the package) and send the shipped email',
			'production' => 'mark In production',
			'extra_package_email' => 'email the customer about the extra package',
		);
		$parts = array();
		foreach ($plans as $plan) {
			$parts[] = $words[$plan] ?? $plan;
		}
		return implode('; ', $parts);
	}
}

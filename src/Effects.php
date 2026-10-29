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
	/** Not shipped yet. "finishing" and "ready-for-pickup" mirror the B2Sign stages Trimming and Ready for Pickup. */
	public const OPEN = array('pending', 'processing', 'finishing', 'ready-for-pickup', 'on-hold', 'on_hold');
	/** Shipped, and the carrier stages after it (theme wholesale_order_shipped_statuses()). */
	public const SHIPPED = array('completed', 'in-transit', 'out-for-delivery', 'delivered', 'delivery-issue', 'returned');
	public const CLOSED = array('cancelled', 'refunded', 'failed');
	/** Carrier statuses meaning the package left the shipper. */
	public const MOVING = array('in_transit', 'out_for_delivery', 'delivered');
	/** The stage the sync last set from tracking; a different status means staff changed it by hand. */
	public const META_STAGE = '_sfs_auto_stage';
	/** Open stages in production order: the sync only moves an order forward along these. */
	private const PRODUCTION_RANK = array('pending' => 0, 'processing' => 1, 'finishing' => 2, 'ready-for-pickup' => 3);
	private const LABELS = array(
		'finishing' => 'Finishing',
		'ready-for-pickup' => 'Ready for pickup',
		'in-transit' => 'In transit',
		'out-for-delivery' => 'Out for delivery',
		'delivered' => 'Delivered',
		'delivery-issue' => 'Delivery issue',
		'returned' => 'Returned to sender',
	);

	/**
	 * Where a B2Sign item status puts the order. B2Sign's guide: Awaiting Artwork / On Hold / Awaiting Customer
	 * Approval (action needed by us), Awaiting Staff Approval / Printing / Trimming, Ready for Pickup / Shipped /
	 * Completed, Reprint / Awaiting Refund / Refunded. Matched on words, so keys ("ready_for_pickup") and labels work.
	 *
	 * @return string action | processing | finishing | ready-for-pickup | shipped | refund | unknown | ''
	 */
	public static function b2_stage(string $status): string {
		$words = ' ' . strtolower(trim((string) preg_replace('/[^a-z]+/i', ' ', $status))) . ' ';
		if ('  ' === $words) {
			return '';
		}
		$has = static function (array $needles) use ($words): bool {
			foreach ($needles as $needle) {
				if (false !== strpos($words, $needle)) {
					return true;
				}
			}
			return false;
		};
		if ($has(array('refund'))) {
			return 'refund';
		}
		if ($has(array('artwork', 'customer', ' hold '))) {
			return 'action';
		}
		if ($has(array('pickup', 'pick up'))) {
			return 'ready-for-pickup';
		}
		if ($has(array('trim', 'finish', 'cutting', 'laminat', 'pack', 'quality', 'ready to ship'))) {
			return 'finishing';
		}
		if ($has(array('ship', 'deliver', 'complete'))) {
			return 'shipped';
		}
		if ($has(array('print', 'staff', 'production', 'processing'))) {
			return 'processing';
		}
		return in_array(trim($words), Settings::production_statuses(), true) ? 'processing' : 'unknown';
	}

	/**
	 * The open stage the linked B2Sign items put the order in: the least advanced item that has not shipped.
	 * Empty while an item waits on us (artwork, approval, hold) or nothing is known.
	 *
	 * @param array<int,array> $items
	 */
	public static function production_target(array $items): string {
		$target = '';
		foreach ($items as $item) {
			$stage = self::b2_stage((string) ($item['status'] ?? ''));
			if ('action' === $stage) {
				return '';
			}
			if (!isset(self::PRODUCTION_RANK[$stage])) {
				continue;
			}
			if ('' === $target || self::PRODUCTION_RANK[$stage] < self::PRODUCTION_RANK[$target]) {
				$target = $stage;
			}
		}
		return $target;
	}

	/**
	 * The shipped-family status the packages put the order in.
	 *
	 * @param array<string,array> $shipments
	 */
	public static function delivery_stage(array $shipments): string {
		if (!$shipments) {
			return '';
		}
		$statuses = array_column($shipments, 'status');
		if (Core\ShipmentMerger::summary($shipments)['all_delivered']) {
			return 'delivered';
		}
		foreach (array('returned' => 'returned', 'exception' => 'delivery-issue', 'out_for_delivery' => 'out-for-delivery', 'in_transit' => 'in-transit', 'delivered' => 'in-transit') as $carrier => $stage) {
			if (in_array($carrier, $statuses, true)) {
				return $stage;
			}
		}
		return 'completed';
	}

	/**
	 * Moves a shipped order to the stage its packages show. Never back to plain Shipped, and not when staff changed
	 * the status by hand since the sync last set it (until the packages show a new stage).
	 *
	 * @param array<string,array> $shipments
	 * @return string[]
	 */
	public static function stage_plan(int $order_id, string $status, array $shipments): array {
		if (!Settings::get('auto_ship') || !in_array($status, self::SHIPPED, true) || !self::theme_has_stages()) {
			return array();
		}
		$stage = self::delivery_stage($shipments);
		if ('' === $stage || 'completed' === $stage || $stage === $status || $stage === get_post_meta($order_id, self::META_STAGE, true)) {
			return array();
		}
		return array('stage:' . $stage);
	}

	/** The theme registers the stage statuses; without them an order must never be given one. */
	public static function theme_has_stages(): bool {
		return function_exists('wholesale_order_shipped_statuses');
	}

	/**
	 * Effects due after a B2Sign item was linked or refreshed.
	 *
	 * @param array<string,array> $shipments The order's shipments after merging.
	 * @param array<int,array> $items The order's linked B2Sign items after merging.
	 * @param string[] $added Shipment keys new in this sync.
	 * @return string[] ship | production | stage:<status> | extra_package_email
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
		$target = self::production_target($items);
		if (
			Settings::get('auto_production')
			&& !$plans
			&& '' !== $target
			&& isset(self::PRODUCTION_RANK[$status])
			&& self::PRODUCTION_RANK[$target] > self::PRODUCTION_RANK[$status]
		) {
			$plans[] = 'processing' === $target ? 'production' : (self::theme_has_stages() ? 'stage:' . $target : ('pending' === $status ? 'production' : ''));
			$plans = array_values(array_filter($plans));
		}
		if (in_array($status, self::SHIPPED, true) && $added && Settings::get('email_extra_packages')) {
			$plans[] = 'extra_package_email';
		}
		return array_merge($plans, self::stage_plan($order_id, $plans && 'ship' === $plans[0] ? 'completed' : $status, $shipments));
	}

	/**
	 * Effects of a carrier reading: an order still Pending review / In production whose package is moving or
	 * delivered is marked Shipped. Covers tracking numbers staff typed (no supplier item) and supplier items that
	 * were never marked shipped. Orders with linked supplier items still waiting are left for the item rule.
	 * A shipped order then follows its packages (In transit, Out for delivery, Delivered, ...).
	 *
	 * @param array<string,array> $shipments
	 * @return string[]
	 */
	public static function carrier_plan(int $order_id, string $status, array $shipments): array {
		if (!self::ships_from_carrier($order_id, $status, $shipments)) {
			return self::stage_plan($order_id, $status, $shipments);
		}
		return array_merge(array('ship_carrier'), self::stage_plan($order_id, 'completed', $shipments));
	}

	private static function ships_from_carrier(int $order_id, string $status, array $shipments): bool {
		if (!Settings::get('auto_ship') || !in_array($status, self::OPEN, true) || get_post_meta($order_id, Orders::META_AUTO_SHIPPED, true)) {
			return false;
		}
		$moving = array_filter($shipments, static function ($shipment) {
			return in_array($shipment['status'] ?? '', self::MOVING, true);
		});
		if (!$moving) {
			return false;
		}
		$items = Orders::items($order_id);
		return !$items || self::progress($order_id, $shipments, $items)['all_shipped'];
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
			$stage = self::b2_stage((string) ($item['status'] ?? ''));
			if ('refund' === $stage) {
				continue;
			}
			$active++;
			// Its own package, or (one box for several items, stored before item_ids) B2Sign lists a package for it and
			// a box of the same B2Sign order is on this order.
			$boxed = isset($with_packages[(int) $item['item_id']])
				|| ((int) ($item['packages'] ?? 0) > 0 && isset($b2_boxes[(string) ($item['b2_order'] ?? '')]));
			if ('shipped' === $stage && $boxed) {
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
		foreach ($plans as $plan) {
			if (0 !== strpos($plan, 'stage:')) {
				continue;
			}
			$stage = substr($plan, 6);
			$label = self::LABELS[$stage] ?? $stage;
			$shipped = in_array($stage, self::SHIPPED, true);
			if ($shipped) {
				update_post_meta($order_id, self::META_STAGE, $stage);
			}
			wp_update_post(array('ID' => $order_id, 'post_status' => $stage));
			Orders::add_activity($order_id, 'Marked ' . $label . ' automatically from the ' . ($shipped ? 'carrier tracking.' : 'supplier status.'));
			Log::add('action', 'Marked order #' . $number . ' ' . $label, array('order' => $number), $run_id);
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
			$stage = 0 === strpos($plan, 'stage:') ? substr($plan, 6) : '';
			$parts[] = $words[$plan] ?? ('' !== $stage ? 'mark ' . (self::LABELS[$stage] ?? $stage) : $plan);
		}
		return implode('; ', $parts);
	}
}

<?php
/**
 * Staff edits on the order screen and quick edit.
 *
 * - The B2Sign order number field (Shipments box) is the owner's exact-match link (decision 2026-10-29).
 * - When staff type a tracking number in the theme's status box or quick edit, it becomes a "manual" shipment
 *   (first, so it stays the primary tracking number) and gets carrier checks like the synced ones.
 * - Staff-only sync notes appear in the theme's Activity box.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

use SFS\OrderTracking\Core\OrderNumberExtractor;
use SFS\OrderTracking\Core\ShipmentMerger;
use SFS\OrderTracking\Core\TrackingUrls;

final class OrderHooks {
	public const NONCE = 'sfs_tracking_meta_nonce';
	public const MANUAL_CARRIERS = array('ups', 'fedex', 'usps', 'freight');

	public static function hooks(): void {
		// After the theme's handler (priority 5) has saved _tracking_carrier / _tracking_number.
		add_action('save_post_order', array(self::class, 'save'), 20, 1);
		add_filter('wholesale_order_activity_events', array(self::class, 'activity'), 10, 2);
	}

	public static function save(int $post_id): void {
		if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || !current_user_can('edit_post', $post_id)) {
			return;
		}
		$is_quick_edit = wp_doing_ajax() && 'inline-save' === ($_POST['action'] ?? '') && isset($_POST['_inline_edit']); // phpcs:ignore WordPress.Security.NonceVerification
		$theme_form = isset($_POST['wholesale_fulfillment_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wholesale_fulfillment_nonce'])), 'wholesale_fulfillment');
		$our_form = isset($_POST[self::NONCE]) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::NONCE);
		if ($is_quick_edit) {
			// The theme's handler already checked this request's quick-edit nonce.
			$theme_form = check_ajax_referer('inlineeditnonce', '_inline_edit', false);
		}

		if ($our_form && isset($_POST['sfs_b2_orders'])) {
			$numbers = OrderNumberExtractor::b2_numbers(sanitize_text_field(wp_unslash($_POST['sfs_b2_orders'])));
			if (Orders::set_b2_numbers($post_id, $numbers)) {
				Orders::add_activity($post_id, $numbers ? 'B2Sign order number set: ' . implode(', ', $numbers) : 'B2Sign order number cleared');
			}
		}

		if ($theme_form && (isset($_POST['wholesale_tracking_number']) || isset($_POST['wholesale_tracking_carrier']))) {
			$number = TrackingUrls::normalize_number((string) get_post_meta($post_id, '_tracking_number', true));
			$carrier = (string) get_post_meta($post_id, '_tracking_carrier', true);
			if ('' === $carrier) {
				$carrier = TrackingUrls::guess_carrier($number);
			}
			$shipments = Orders::shipments($post_id);
			if ('' !== $number && self::valid_manual($carrier, $number) && array_key_first($shipments) !== ShipmentMerger::key($carrier, $number)) {
				$known = isset($shipments[ShipmentMerger::key($carrier, $number)]);
				Orders::save_shipments($post_id, self::with_manual($shipments, $carrier, $number, true));
				if (!$known) {
					Orders::add_activity($post_id, 'Tracking number added by staff: ' . (TrackingUrls::NAMES[$carrier] ?? ucfirst($carrier)) . ' ' . Log::mask($number));
				}
			}
		}
	}

	public static function valid_manual(string $carrier, string $number): bool {
		if (!in_array($carrier, self::MANUAL_CARRIERS, true)) {
			return false;
		}
		if ('freight' === $carrier) {
			return 1 === preg_match('/^[A-Z0-9]{4,40}$/', $number);
		}
		return TrackingUrls::valid_number($carrier, $number);
	}

	/**
	 * Adds a staff-typed package (or finds it) and optionally moves it first so it stays the primary number.
	 *
	 * @param array<string,array> $shipments
	 * @return array<string,array>
	 */
	public static function with_manual(array $shipments, string $carrier, string $number, bool $first): array {
		$key = ShipmentMerger::key($carrier, $number);
		$shipment = $shipments[$key] ?? ShipmentMerger::blank($carrier, $number, 'manual', time());
		if (!TrackingUrls::checkable($carrier)) {
			$shipment['next_check_at'] = 0;
		}
		unset($shipments[$key]);
		return $first ? array($key => $shipment) + $shipments : $shipments + array($key => $shipment);
	}

	/**
	 * Sync notes in the theme's Activity box (filter added in inc/admin-orders.php).
	 *
	 * @param mixed $events
	 * @param mixed $post
	 */
	public static function activity($events, $post): array {
		$events = is_array($events) ? $events : array();
		if (!$post instanceof \WP_Post) {
			return $events;
		}
		foreach (Orders::activity($post->ID) as $entry) {
			$events[] = array('time' => (int) $entry['time'], 'text' => (string) $entry['text']);
		}
		return $events;
	}
}

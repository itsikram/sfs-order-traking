<?php
/**
 * Tracking emails. The shipped email itself stays the theme's (sent once when an order turns Shipped); this class
 * only lists every package in it. Delivered / delivery-issue customer emails are off by default (owner decision
 * 2026-10-29) and go once per package per state; staff are told about delivery issues. Customer copy never names
 * the supplier.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

use SFS\OrderTracking\Core\CarrierTextNormalizer;
use SFS\OrderTracking\Core\TrackingUrls;

final class Emails {
	public static function hooks(): void {
		add_filter('wholesale_status_email_tracking_html', array(self::class, 'shipped_packages_html'), 10, 3);
	}

	/**
	 * Every package in the theme's shipped email, instead of only the primary tracking number.
	 */
	public static function shipped_packages_html(string $html, int $post_id, string $status): string {
		if (!in_array($status, Effects::SHIPPED, true)) {
			return $html;
		}
		$shipments = Orders::shipments($post_id);
		if (!$shipments) {
			return $html;
		}
		$count = count($shipments);
		$rows = '';
		$i = 0;
		foreach ($shipments as $shipment) {
			$i++;
			$url = PublicView::tracking_url($shipment['carrier'], $shipment['tracking_number']);
			$name = TrackingUrls::NAMES[$shipment['carrier']] ?? strtoupper((string) $shipment['carrier']);
			$label = $count > 1 ? 'Package ' . $i . ' of ' . $count . ': ' : '';
			$number = $url ? '<a href="' . esc_url($url) . '" style="color:#1287b5;">' . esc_html($shipment['tracking_number']) . '</a>' : esc_html($shipment['tracking_number']);
			$rows .= '<li style="margin:4px 0;">' . esc_html($label . $name) . ' ' . $number . '</li>';
		}
		$track = function_exists('wholesale_track_order_url') ? wholesale_track_order_url($post_id) : home_url('/track-order/');
		return '<div style="margin:16px 0 0;padding:14px 16px;background:#eef7fc;border-radius:8px;font-size:15px;color:#0d2e4d;">'
			. '<strong>' . esc_html(1 === $count ? 'Tracking number:' : 'Tracking numbers:') . '</strong>'
			. '<ul style="margin:6px 0 0;padding-left:20px;">' . $rows . '</ul>'
			. '<p style="margin:10px 0 0;font-size:14px;"><a href="' . esc_url($track) . '" style="color:#1287b5;">Follow live delivery status on our site</a></p>'
			. '</div>';
	}

	public static function customer_delivered(int $order_id, array $shipment, int $position, int $count): bool {
		$number = Orders::number($order_id);
		$when = PublicView::delivered_label($shipment);
		$body = '<p style="margin:0;">' . esc_html(self::package_words($shipment, $position, $count) . ' was delivered' . ($when ? ' ' . $when : '') . '.') . '</p>';
		return self::send_customer($order_id, sprintf('Your order #%s was delivered', $number), $body, $shipment);
	}

	public static function customer_issue(int $order_id, array $shipment, int $position, int $count): bool {
		$number = Orders::number($order_id);
		$latest = (string) ($shipment['last_event']['status_text'] ?? '');
		$carrier = TrackingUrls::NAMES[$shipment['carrier']] ?? 'The carrier';
		$text = $carrier . ' reported a delivery issue with ' . lcfirst(self::package_words($shipment, $position, $count)) . ($latest ? ': "' . $latest . '"' : '') . '. We are keeping an eye on it and will contact you if anything is needed from you.';
		return self::send_customer($order_id, sprintf('Delivery update for order #%s', $number), '<p style="margin:0;">' . esc_html($text) . '</p>', $shipment);
	}

	public static function extra_package(int $order_id, array $shipment, int $position, int $count): bool {
		$number = Orders::number($order_id);
		$text = 'Another package from your order is on its way (' . lcfirst(self::package_words($shipment, $position, $count)) . ').';
		return self::send_customer($order_id, sprintf('Another package for order #%s has shipped', $number), '<p style="margin:0;">' . esc_html($text) . '</p>', $shipment);
	}

	/**
	 * Plain-text alert to staff when a package hits a delivery problem.
	 */
	public static function staff_exception(int $order_id, array $shipment): bool {
		$number = Orders::number($order_id);
		$carrier = TrackingUrls::NAMES[$shipment['carrier']] ?? strtoupper((string) $shipment['carrier']);
		$event = (array) ($shipment['last_event'] ?? array());
		$lines = array(
			$carrier . ' ' . $shipment['tracking_number'] . ' on order #' . $number . ' now shows: ' . CarrierTextNormalizer::label((string) $shipment['status']) . '.',
			'Latest scan: ' . trim(($event['status_text'] ?? '') . ' ' . ($event['detail_text'] ?? '')) . ' ' . trim(($event['date_text'] ?? '') . ' ' . ($event['time_text'] ?? '')),
		);
		if (!empty($shipment['b2']['order_number'])) {
			$lines[] = 'Supplier order: ' . $shipment['b2']['order_number'] . (empty($shipment['b2']['jobid']) ? '' : ' (' . $shipment['b2']['jobid'] . ')');
		}
		$lines[] = 'Order: ' . admin_url('post.php?post=' . $order_id . '&action=edit');
		$lines[] = 'Carrier page: ' . PublicView::tracking_url($shipment['carrier'], $shipment['tracking_number']);
		return self::staff('[Tracking] Delivery issue on order #' . $number, implode("\n", $lines));
	}

	public static function staff(string $subject, string $text): bool {
		return (bool) wp_mail(Settings::staff_email(), $subject, $text . "\n\n— Storefront Sign Online tracking sync", array('Content-Type: text/plain; charset=UTF-8'));
	}

	private static function send_customer(int $order_id, string $subject, string $body, array $shipment): bool {
		if (!function_exists('wholesale_send_order_email')) {
			return false;
		}
		$url = PublicView::tracking_url($shipment['carrier'], $shipment['tracking_number']);
		$track = function_exists('wholesale_track_order_url') ? wholesale_track_order_url($order_id) : home_url('/track-order/');
		$body .= '<p style="margin:16px 0 0;"><a href="' . esc_url($track) . '" style="color:#1287b5;">See live tracking</a>'
			. ($url ? ' &middot; <a href="' . esc_url($url) . '" style="color:#1287b5;">Track on ' . esc_html(TrackingUrls::NAMES[$shipment['carrier']] ?? 'the carrier site') . '</a>' : '') . '</p>';
		$sent = (bool) wholesale_send_order_email($order_id, $subject, $body);
		if ($sent) {
			Orders::add_activity($order_id, 'Customer emailed: ' . $subject);
		}
		return $sent;
	}

	private static function package_words(array $shipment, int $position, int $count): string {
		$carrier = TrackingUrls::NAMES[$shipment['carrier']] ?? strtoupper((string) $shipment['carrier']);
		return ($count > 1 ? 'Package ' . $position . ' of ' . $count : 'Your package') . ' (' . $carrier . ' ' . $shipment['tracking_number'] . ')';
	}
}

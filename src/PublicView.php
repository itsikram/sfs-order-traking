<?php
/**
 * What customers see: the Shipments card on the tracking page, the order page and My Orders, the 4th progress step
 * "Delivered", and POST /track for live updates.
 *
 * Customer output is built only from the whitelist in payload(): never B2Sign data (order numbers, job names,
 * factory, ship-from, prices) and only destination-side scan locations (owner decision 2026-10-29).
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

use SFS\OrderTracking\Core\CarrierTextNormalizer;
use SFS\OrderTracking\Core\CheckScheduler;
use SFS\OrderTracking\Core\LocationPolicy;
use SFS\OrderTracking\Core\ShipmentMerger;
use SFS\OrderTracking\Core\TokenSigner;
use SFS\OrderTracking\Core\TrackingUrls;

final class PublicView {
	public const TOKEN_TTL = 7200;
	public const STALE_SECONDS = 900;
	public const RATE_LIMIT = 120;
	public const RATE_WINDOW = 600;
	public const MAX_EVENTS = 30;

	public static function hooks(): void {
		add_filter('wholesale_order_shipments_html', array(self::class, 'shipments_html'), 10, 3);
		add_filter('wholesale_order_progress', array(self::class, 'progress'), 10, 2);
		add_filter('wholesale_my_orders_shipment_summary', array(self::class, 'my_orders_summary'), 10, 2);
		add_filter('wholesale_order_status_badge_item', array(self::class, 'badge_item'), 10, 3);
		add_action('template_redirect', array(self::class, 'no_cache_pages'), 1);
		add_action('wp_enqueue_scripts', array(self::class, 'styles'), 20);
	}

	/**
	 * Customer-facing carrier link: the theme's builder, else the plugin's.
	 */
	public static function tracking_url(string $carrier, string $number): string {
		if (function_exists('wholesale_tracking_url')) {
			$url = (string) wholesale_tracking_url($carrier, $number);
			if ('' !== $url) {
				return $url;
			}
		}
		return TrackingUrls::public_url($carrier, $number);
	}

	/**
	 * Everything a customer may see about an order's shipments. Tests assert these exact keys.
	 */
	public static function payload(int $order_id): array {
		$shipments = Orders::shipments($order_id);
		$summary = ShipmentMerger::summary($shipments);
		$destination = Orders::destination($order_id);
		$mode = (string) Settings::get('location_mode');
		$count = count($shipments);
		$list = array();
		$i = 0;
		foreach ($shipments as $shipment) {
			$list[] = self::shipment($shipment, ++$i, $count, $destination, $mode);
		}
		return array(
			'order' => array(
				'number' => Orders::number($order_id),
				'delivered' => $summary['all_delivered'],
				'packages' => $count,
				'delivered_packages' => $summary['delivered'],
				'note' => self::note($order_id, $shipments, $summary),
			),
			'shipments' => $list,
		);
	}

	/**
	 * The Shipments card (filter wholesale_order_shipments_html). The theme calls it on the tracking page only after
	 * a successful number + email lookup, and on the order page only for its owner or staff, so both get a token.
	 *
	 * @param mixed $html
	 * @param mixed $order_id
	 * @param mixed $context track | order
	 */
	public static function shipments_html($html, $order_id, $context = 'track'): string {
		$order_id = (int) $order_id;
		if ('' !== (string) $html || $order_id <= 0 || 'order' !== get_post_type($order_id) || !Orders::shipments($order_id)) {
			return (string) $html;
		}
		$payload = self::payload($order_id);
		$now = time();
		$token = TokenSigner::issue(Keys::token_secret(), $payload['order']['number'], $now + self::TOKEN_TTL);
		$endpoint = rest_url('sfs-tracking/v1/track');
		$etag = self::etag($payload);
		$heading = 'order' === $context ? 'h2' : 'h3';
		self::enqueue_script();
		ob_start();
		include dirname(__DIR__) . '/templates/shipments.php';
		return (string) ob_get_clean();
	}

	/**
	 * Adds the 4th progress step "Delivered" with its date or the estimate.
	 *
	 * @param mixed $progress {steps: array<int,string>, step: int, dates: array<int,int>, notes: array<int,string>}
	 * @param mixed $order_id
	 */
	public static function progress($progress, $order_id): array {
		$progress = is_array($progress) ? $progress : array();
		$shipments = Orders::shipments((int) $order_id);
		if (!$shipments || empty($progress['steps']) || (int) ($progress['step'] ?? 0) <= 0) {
			return $progress;
		}
		$progress['steps'][4] = 'Delivered';
		$summary = ShipmentMerger::summary($shipments);
		if ($summary['all_delivered'] && (int) $progress['step'] >= 3) {
			$progress['step'] = 4;
			$latest = '';
			foreach ($shipments as $shipment) {
				$latest = max($latest, (string) ($shipment['delivered_at']['local'] ?? ''));
			}
			if ('' !== $latest) {
				$progress['dates'][4] = (new \DateTimeImmutable($latest, wp_timezone()))->getTimestamp();
			}
		} elseif (3 === (int) $progress['step'] && '' !== $summary['next_eta']) {
			$progress['notes'][4] = 'Est. ' . self::date_label($summary['next_eta'], 'M j');
		}
		return $progress;
	}

	/**
	 * Status badge and estimate for one row of My Orders.
	 *
	 * @param mixed $html
	 * @param mixed $order_id
	 */
	public static function my_orders_summary($html, $order_id): string {
		$shipments = Orders::shipments((int) $order_id);
		if (!$shipments) {
			return (string) $html;
		}
		$summary = ShipmentMerger::summary($shipments);
		$status = $summary['all_delivered'] ? 'delivered' : self::headline_status($shipments);
		$label = self::status_label($status, $shipments);
		// A shipped order's status pill already says the stage and how many packages arrived: only the estimate here.
		if (in_array((string) get_post_status((int) $order_id), Effects::SHIPPED, true)) {
			return !$summary['all_delivered'] && '' !== $summary['next_eta'] ? '<span class="sfs-mini"><span class="sfs-mini__text">' . esc_html('Est. delivery ' . self::date_label($summary['next_eta'], 'D, M j')) . '</span></span>' : '';
		}
		$text = '';
		if ($summary['all_delivered']) {
			$text = $summary['total'] > 1 ? 'All ' . $summary['total'] . ' packages delivered' : '';
		} elseif ($summary['delivered'] > 0) {
			$text = $summary['delivered'] . ' of ' . $summary['total'] . ' packages delivered';
		}
		if (!$summary['all_delivered'] && '' !== $summary['next_eta']) {
			$text .= ('' === $text ? '' : ' · ') . 'Est. delivery ' . self::date_label($summary['next_eta'], 'D, M j');
		}
		return '<span class="sfs-mini"><span class="order-badge order-badge--' . esc_attr(CarrierTextNormalizer::tone($status)) . '">' . esc_html($label) . '</span>'
			. ('' === $text ? '' : ' <span class="sfs-mini__text">' . esc_html($text) . '</span>') . '</span>';
	}

	/**
	 * POST /sfs-tracking/v1/track {t}: one order's shipments for an open tracking page. Rate limited per IP,
	 * ETag/304 aware, never cached. Asks the extension for fresh readings of stale packages a customer is viewing.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_track(\WP_REST_Request $request) {
		if (self::rate_limited(Auth::ip())) {
			return new \WP_Error('sfs_rate_limited', 'Too many requests. Try again in a few minutes.', array('status' => 429));
		}
		$now = time();
		$check = TokenSigner::verify(Keys::token_secret(), (string) $request->get_param('t'), $now);
		if (!$check['ok']) {
			$expired = 'expired' === $check['error'];
			return new \WP_Error($expired ? 'sfs_token_expired' : 'sfs_token_invalid', $expired ? 'This page stopped updating. Refresh to keep updating.' : 'This tracking link is not valid.', array('status' => 401));
		}
		$order_id = Orders::find_by_number($check['order']);
		if (!$order_id || !Orders::shipments($order_id)) {
			return new \WP_Error('sfs_not_found', 'No tracking information for this order.', array('status' => 404));
		}
		self::request_fresh_readings($order_id, $now);
		$payload = self::payload($order_id);
		$etag = self::etag($payload);
		$sent = trim((string) $request->get_header('if_none_match'));
		if ('' !== $sent && hash_equals($etag, $sent)) {
			$response = new \WP_REST_Response(null, 304);
		} else {
			$response = new \WP_REST_Response($payload + array('server_time' => $now, 'expires_at' => $check['expires']), 200);
		}
		$response->header('ETag', $etag);
		return $response;
	}

	/**
	 * Tracking, order and My Orders pages are never cached by the page cache or a browser.
	 */
	public static function no_cache_pages(): void {
		if (!self::is_tracking_page()) {
			return;
		}
		if (!defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true);
		}
		if (!headers_sent()) {
			nocache_headers();
			header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
		}
	}

	public static function styles(): void {
		if (!self::is_tracking_page()) {
			return;
		}
		$path = '/assets/css/tracking.css';
		wp_enqueue_style('sfs-tracking', plugins_url($path, SFS_TRACKING_FILE), wp_style_is('wholesale-shop', 'registered') ? array('wholesale-shop') : array(), (string) filemtime(dirname(__DIR__) . $path));
	}

	/**
	 * "Updated 12 min ago" from the last real carrier reading; never a made-up freshness.
	 * Mirrors updatedText() in assets/js/tracking.js.
	 *
	 * @param array $shipment Customer payload of one package.
	 */
	public static function updated_text(array $shipment, int $now): string {
		$checked = (int) ($shipment['last_checked_at'] ?? 0);
		if (!TrackingUrls::checkable((string) $shipment['carrier'])) {
			return '';
		}
		if (!$checked) {
			return 'delivered' === $shipment['status'] ? '' : 'Waiting for the first ' . $shipment['carrier_name'] . ' scan';
		}
		$age = max(0, $now - $checked);
		if ($age < 60) {
			return 'Updated just now';
		}
		if ($age < HOUR_IN_SECONDS) {
			return 'Updated ' . (int) floor($age / 60) . ' min ago';
		}
		if ($age < DAY_IN_SECONDS) {
			$hours = (int) floor($age / HOUR_IN_SECONDS);
			return 'Updated ' . $hours . ($hours > 1 ? ' hours ago' : ' hour ago');
		}
		return 'Updated ' . wp_date('M j', $checked);
	}

	public static function delivered_label(array $shipment): string {
		$local = (string) ($shipment['delivered_at']['local'] ?? '');
		if ('' === $local) {
			return '';
		}
		return strlen($local) > 10 ? self::date_label($local, 'D, M j \a\t g:i A', 'Y-m-d H:i') : self::date_label($local, 'D, M j');
	}

	public static function date_label(string $value, string $format, string $from = 'Y-m-d'): string {
		$date = \DateTimeImmutable::createFromFormat('!' . $from, $value, new \DateTimeZone('UTC'));
		return $date ? $date->format($format) : $value;
	}

	private static function is_tracking_page(): bool {
		return is_page('track-order') || is_page_template('page-track-order.php') || is_singular('order') || is_page('my-orders') || is_page_template('my_orders.php');
	}

	private static function enqueue_script(): void {
		$path = '/assets/js/tracking.js';
		wp_enqueue_script('sfs-tracking', plugins_url($path, SFS_TRACKING_FILE), array(), (string) filemtime(dirname(__DIR__) . $path), array('in_footer' => true, 'strategy' => 'defer'));
	}

	private static function shipment(array $shipment, int $index, int $count, array $destination, string $mode): array {
		$carrier = (string) $shipment['carrier'];
		$name = TrackingUrls::NAMES[$carrier] ?? ('freight' === $carrier ? 'Freight' : strtoupper($carrier));
		$status = (string) $shipment['status'];
		$events = array();
		foreach (array_slice((array) $shipment['events'], 0, self::MAX_EVENTS) as $event) {
			$events[] = self::event($carrier, $event, $mode, $destination);
		}
		$waiting = 'unknown' === $status && 0 === (int) $shipment['last_checked_at'] && TrackingUrls::checkable($carrier);
		$url = TrackingUrls::checkable($carrier) || 'usps' === $carrier ? self::tracking_url($carrier, (string) $shipment['tracking_number']) : '';
		return array(
			'index' => $index,
			'count' => $count,
			'carrier' => $carrier,
			'carrier_name' => $name,
			'tracking_number' => (string) $shipment['tracking_number'],
			'status' => $waiting ? 'shipped' : $status,
			'status_label' => $waiting ? 'Shipped' : CarrierTextNormalizer::label($status),
			'tone' => $waiting ? 'info' : CarrierTextNormalizer::tone($status),
			'eta' => self::eta($shipment, $name),
			'delivered_at' => 'delivered' === $status ? (self::delivered_label($shipment) ?: null) : null,
			'last_event' => $events[0] ?? null,
			'events' => $events,
			'last_checked_at' => (int) $shipment['last_checked_at'] ?: null,
			'tracking_url' => $url,
		);
	}

	private static function event(string $carrier, array $event, string $mode, array $destination): array {
		$kind = isset($event['kind']) && is_string($event['kind']) ? $event['kind'] : null;
		$detail = (string) ($event['detail_text'] ?? '');
		// UPS repeats a milestone ("We Have Your Package") over the real scan text, which is in the detail.
		$description = 'ups' === $carrier && '' !== $detail ? $detail : (string) $event['status_text'];
		$note = 'ups' === $carrier || $detail === $description ? '' : $detail;
		return array(
			'when' => self::when($event),
			'description' => $description,
			'note' => $note,
			'location' => LocationPolicy::visible($mode, $event, $kind, $destination) ? self::tidy_location((string) $event['location_text']) : '',
			'kind' => (string) $kind,
		);
	}

	private static function when(array $event): string {
		$local = (string) ($event['local'] ?? '');
		if ('' === $local) {
			return trim($event['date_text'] . ' ' . $event['time_text']);
		}
		$label = strlen($local) > 10 ? self::date_label($local, 'M j, g:i A', 'Y-m-d H:i') : self::date_label($local, 'M j');
		return 'site' === ($event['basis'] ?? '') ? $label . ' PT' : $label;
	}

	private static function eta(array $shipment, string $carrier_name): ?array {
		if (!in_array($shipment['status'], array('pre_transit', 'in_transit', 'out_for_delivery', 'exception', 'unknown'), true) || empty($shipment['eta']['date'])) {
			return null;
		}
		$eta = $shipment['eta'];
		$sources = array('carrier' => 'Estimated by ' . $carrier_name, 'transit' => 'Standard transit time');
		return array(
			'date' => (string) $eta['date'],
			'label' => self::date_label((string) $eta['date'], 'D, M j'),
			'window' => (string) ($eta['window'] ?? ''),
			'source' => $sources[$eta['source'] ?? ''] ?? 'Estimated',
		);
	}

	/**
	 * "Anytown, PA, United States" → "Anytown, PA"; "LAKEVIEW, LA US" → "Lakeview, LA".
	 */
	private static function tidy_location(string $text): string {
		if (preg_match('/^\s*([^,]+),\s*([A-Za-z]{2})\b/', $text, $m)) {
			$city = trim($m[1]);
			if ($city === strtoupper($city)) {
				$city = ucwords(strtolower($city));
			}
			return $city . ', ' . strtoupper($m[2]);
		}
		return trim((string) preg_replace('/,?\s*(United States|US|USA)$/i', '', $text));
	}

	private static function note(int $order_id, array $shipments, array $summary): string {
		$status = (string) get_post_status($order_id);
		if (!in_array($status, Effects::SHIPPED, true)) {
			$progress = Effects::progress($order_id, $shipments, Orders::items($order_id));
			// Not every line is accounted for yet; once every package is delivered the note would only mislead.
			if ($progress['partial'] || ($progress['short'] && !$summary['all_delivered'])) {
				return sprintf('Partially shipped (%d of %d items)', $progress['shipped'], $progress['lines']);
			}
			return '';
		}
		if ($summary['total'] > 1 && $summary['delivered'] > 0 && !$summary['all_delivered']) {
			return sprintf('%d of %d packages delivered', $summary['delivered'], $summary['total']);
		}
		return '';
	}

	/**
	 * One order's status pill (theme filter wholesale_order_status_badge_item): a Shipped order shows how far its
	 * packages got (In transit, Out for delivery, Delivered, Delivery issue); an order still in production with
	 * some products already shipped shows "Partly shipped". The stored order status never changes here.
	 *
	 * @param mixed $item {label, tone, ...}
	 * @param mixed $status
	 * @param mixed $order_id
	 */
	public static function badge_item($item, $status, $order_id): array {
		$item = is_array($item) ? $item : array();
		$order_id = (int) $order_id;
		$shipments = Orders::shipments($order_id);
		if (!$shipments) {
			return $item;
		}
		if (in_array($status, Effects::SHIPPED, true)) {
			$summary = ShipmentMerger::summary($shipments);
			$stage = $summary['all_delivered'] ? 'delivered' : self::headline_status($shipments);
			// Plain Shipped (automatic stages off, or the theme without them) still shows the carrier stage.
			if ('completed' === $status && in_array($stage, array('in_transit', 'out_for_delivery', 'delivered', 'exception', 'returned'), true)) {
				$item['label'] = CarrierTextNormalizer::label($stage);
				$item['tone'] = CarrierTextNormalizer::tone($stage);
			}
			if ('delivered' !== $status && !$summary['all_delivered'] && $summary['total'] > 1 && $summary['delivered'] > 0) {
				$item['label'] = ($item['label'] ?? '') . ' · ' . $summary['delivered'] . '/' . $summary['total'] . ' delivered';
			}
			return $item;
		}
		if (in_array($status, Effects::OPEN, true) && Effects::progress($order_id, $shipments, Orders::items($order_id))['partial']) {
			$item['label'] = 'Partly shipped';
		}
		return $item;
	}

	private static function headline_status(array $shipments): string {
		$rank = array('exception' => 6, 'returned' => 5, 'out_for_delivery' => 4, 'in_transit' => 3, 'pre_transit' => 2, 'unknown' => 1, 'cancelled' => 0, 'delivered' => -1);
		$best = 'unknown';
		foreach ($shipments as $shipment) {
			if (($rank[$shipment['status']] ?? 0) > ($rank[$best] ?? 0)) {
				$best = (string) $shipment['status'];
			}
		}
		return $best;
	}

	private static function status_label(string $status, array $shipments): string {
		if ('unknown' === $status) {
			foreach ($shipments as $shipment) {
				if (0 === (int) $shipment['last_checked_at']) {
					return 'Shipped';
				}
			}
		}
		return CarrierTextNormalizer::label($status);
	}

	/**
	 * Packages a customer is looking at that have not been read for 15 minutes go first in the next extension run.
	 */
	private static function request_fresh_readings(int $order_id, int $now): void {
		$shipments = Orders::shipments($order_id);
		$changed = false;
		foreach ($shipments as $key => $shipment) {
			if ('' !== CheckScheduler::stop_reason($shipment, $now) || (int) $shipment['priority_until'] > $now) {
				continue;
			}
			if ((int) $shipment['last_checked_at'] < $now - self::STALE_SECONDS) {
				$shipments[$key]['priority_until'] = $now + CheckScheduler::PRIORITY_SECONDS;
				$changed = true;
			}
		}
		if ($changed) {
			Orders::save_shipments($order_id, $shipments);
		}
	}

	private static function etag(array $payload): string {
		return '"' . sha1((string) wp_json_encode($payload)) . '"';
	}

	/**
	 * Fixed 10-minute window per IP (a sliding expiry would never reset for a page that keeps polling).
	 */
	private static function rate_limited(string $ip): bool {
		$key = 'sfs_trk_rate_' . md5($ip);
		$now = time();
		$bucket = get_transient($key);
		if (!is_array($bucket) || (int) ($bucket['start'] ?? 0) <= $now - self::RATE_WINDOW) {
			$bucket = array('start' => $now, 'n' => 0);
		}
		if ((int) $bucket['n'] >= self::RATE_LIMIT) {
			return true;
		}
		$bucket['n'] = (int) $bucket['n'] + 1;
		set_transient($key, $bucket, max(1, (int) $bucket['start'] + self::RATE_WINDOW - $now));
		return false;
	}
}

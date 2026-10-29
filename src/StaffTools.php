<?php
/**
 * Staff box on the front-end order page (theme action wholesale_order_staff_tools): the B2Sign order number and a
 * tracking number, saved without opening wp-admin. Only staff who can edit the order see it; the REST route checks
 * the same capability again.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

use SFS\OrderTracking\Core\OrderNumberExtractor;
use SFS\OrderTracking\Core\TrackingUrls;

final class StaffTools {
	public static function hooks(): void {
		add_action('wholesale_order_staff_tools', array(self::class, 'render'));
	}

	/**
	 * @param mixed $order_id
	 */
	public static function render($order_id): void {
		$order_id = (int) $order_id;
		if ($order_id <= 0 || 'order' !== get_post_type($order_id) || !current_user_can('edit_post', $order_id)) {
			return;
		}
		$path = '/assets/js/staff-tools.js';
		wp_enqueue_script('sfs-tracking-staff', plugins_url($path, SFS_TRACKING_FILE), array(), (string) filemtime(dirname(__DIR__) . $path), array('in_footer' => true, 'strategy' => 'defer'));
		wp_localize_script('sfs-tracking-staff', 'sfsTrackingStaff', array('root' => esc_url_raw(rest_url('sfs-tracking/v1/admin/')), 'nonce' => wp_create_nonce('wp_rest')));
		$b2 = implode(', ', Orders::b2_numbers($order_id));
		$carrier = (string) get_post_meta($order_id, '_tracking_carrier', true);
		$id = 'sfs-staff-' . $order_id;
		?>
		<form class="sfs-staff checkout-card" data-sfs-staff data-order="<?php echo esc_attr((string) $order_id); ?>">
			<h2 class="sfs-staff__title">Tracking <span>(staff only)</span></h2>
			<div class="sfs-staff__fields">
				<p>
					<label for="<?php echo esc_attr($id); ?>-b2">B2Sign order number</label>
					<input type="text" id="<?php echo esc_attr($id); ?>-b2" name="b2_orders" value="<?php echo esc_attr($b2); ?>" placeholder="A9000001" autocomplete="off" spellcheck="false">
				</p>
				<p>
					<label for="<?php echo esc_attr($id); ?>-carrier">Carrier</label>
					<select id="<?php echo esc_attr($id); ?>-carrier" name="carrier">
						<option value="">Detect</option>
						<?php foreach (OrderHooks::MANUAL_CARRIERS as $code) : ?>
							<option value="<?php echo esc_attr($code); ?>"<?php selected($carrier, $code); ?>><?php echo esc_html(TrackingUrls::NAMES[$code] ?? ucfirst($code)); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="<?php echo esc_attr($id); ?>-tn">Add tracking number</label>
					<input type="text" id="<?php echo esc_attr($id); ?>-tn" name="tracking_number" value="" placeholder="1Z… or 12-digit FedEx" autocomplete="off" spellcheck="false">
				</p>
			</div>
			<p class="sfs-staff__help">The B2Sign number alone is enough: tracking numbers and status come from B2Sign and the carrier. Add a tracking number only for packages B2Sign does not list.</p>
			<div class="sfs-staff__actions">
				<button type="submit" class="btn btn-primary">Save</button>
				<span class="sfs-staff__msg" data-sfs-staff-msg role="status" aria-live="polite"></span>
			</div>
		</form>
		<?php
	}

	/**
	 * POST /admin/order-tracking {order_id, b2_orders, carrier, tracking_number}.
	 *
	 * @return array|\WP_Error
	 */
	public static function save(\WP_REST_Request $request) {
		$order_id = (int) $request->get_param('order_id');
		$raw = sanitize_text_field((string) $request->get_param('b2_orders'));
		$numbers = OrderNumberExtractor::b2_numbers($raw);
		if ('' !== trim($raw) && !$numbers) {
			return new \WP_Error('sfs_bad_b2', 'Enter the B2Sign order number like A9000001.', array('status' => 400));
		}
		$number = TrackingUrls::normalize_number((string) $request->get_param('tracking_number'));
		$carrier = strtolower(sanitize_key((string) $request->get_param('carrier')));
		if ('' !== $number) {
			$carrier = '' === $carrier ? TrackingUrls::guess_carrier($number) : $carrier;
			if (!OrderHooks::valid_manual($carrier, $number)) {
				return new \WP_Error('sfs_bad_tracking', 'Enter a valid tracking number for the chosen carrier.', array('status' => 400));
			}
		}

		$notes = array();
		if (Orders::set_b2_numbers($order_id, $numbers)) {
			Orders::add_activity($order_id, $numbers ? 'B2Sign order number set: ' . implode(', ', $numbers) : 'B2Sign order number cleared');
			$notes[] = $numbers ? 'B2Sign order number saved.' : 'B2Sign order number removed.';
		}
		if ('' !== $number) {
			$shipments = Orders::shipments($order_id);
			$key = Core\ShipmentMerger::key($carrier, $number);
			$known = isset($shipments[$key]);
			$shipments = OrderHooks::with_manual($shipments, $carrier, $number, true);
			if (TrackingUrls::checkable($carrier)) {
				// Read first on the extension's next run.
				$shipments[$key]['priority_until'] = time() + 1800;
			}
			Orders::save_shipments($order_id, $shipments);
			if (!$known) {
				Orders::add_activity($order_id, 'Tracking number added by staff: ' . (TrackingUrls::NAMES[$carrier] ?? ucfirst($carrier)) . ' ' . Log::mask($number));
			}
			$notes[] = TrackingUrls::checkable($carrier) ? 'Tracking number saved; the extension reads it on its next run.' : 'Tracking number saved.';
		}
		if ($numbers) {
			$results = SyncService::link_order($order_id);
			if (is_wp_error($results)) {
				$notes[] = $results->get_error_message();
			} elseif ($results) {
				foreach ($results as $result) {
					$notes[] = $result['message'];
				}
			} else {
				$notes[] = 'Its supplier items link on the next sync (within 10 minutes while Chrome is open).';
			}
		}
		return array('ok' => true, 'message' => $notes ? implode(' ', $notes) : 'Nothing changed.');
	}
}

<?php
/**
 * Shipments card for customers: one block per package. assets/js/tracking.js renders the same markup from
 * POST /track while the page is open, so keep the two in step.
 *
 * Variables from PublicView::shipments_html(): $payload, $token, $endpoint, $etag, $heading, $now, $context, $order_id.
 *
 * @package sfs-order-tracking
 */

if (!defined('ABSPATH')) {
	exit;
}

$sfs_order = $payload['order'];
$sfs_title_id = 'sfs-shipments-' . strtolower($sfs_order['number']);
$sfs_refresh = 'order' === $context ? get_permalink($order_id) : (function_exists('wholesale_track_order_url') ? wholesale_track_order_url($order_id) : home_url('/track-order/'));
?>
<section class="sfs-shipments<?php echo 'order' === $context ? ' checkout-card sfs-shipments--card' : ''; ?>" aria-labelledby="<?php echo esc_attr($sfs_title_id); ?>" data-sfs-shipments data-endpoint="<?php echo esc_url($endpoint); ?>" data-token="<?php echo esc_attr($token); ?>" data-etag="<?php echo esc_attr($etag); ?>" data-server-time="<?php echo esc_attr((string) $now); ?>" data-expires="<?php echo esc_attr((string) ($now + \SFS\OrderTracking\PublicView::TOKEN_TTL)); ?>">
	<div class="sfs-shipments__head">
		<<?php echo esc_html($heading); ?> id="<?php echo esc_attr($sfs_title_id); ?>" class="sfs-shipments__title"><?php echo esc_html(1 === count($payload['shipments']) ? 'Shipment' : 'Shipments'); ?></<?php echo esc_html($heading); ?>>
		<p class="sfs-shipments__note" data-sfs-note<?php echo '' === $sfs_order['note'] ? ' hidden' : ''; ?>><?php echo esc_html($sfs_order['note']); ?></p>
	</div>
	<p class="sfs-visually-hidden" aria-live="polite" data-sfs-live></p>
	<ol class="sfs-shipments__list" data-sfs-list>
		<?php foreach ($payload['shipments'] as $sfs_shipment) : ?>
			<li class="sfs-package sfs-package--<?php echo esc_attr($sfs_shipment['status']); ?>" data-key="<?php echo esc_attr($sfs_shipment['carrier'] . ':' . $sfs_shipment['tracking_number']); ?>" data-status="<?php echo esc_attr($sfs_shipment['status']); ?>" data-status-label="<?php echo esc_attr($sfs_shipment['status_label']); ?>">
				<div class="sfs-package__head">
					<p class="sfs-package__name"><?php echo esc_html(($sfs_shipment['count'] > 1 ? 'Package ' . $sfs_shipment['index'] . ' of ' . $sfs_shipment['count'] . ' · ' : '') . $sfs_shipment['carrier_name']); ?></p>
					<span class="order-badge order-badge--<?php echo esc_attr($sfs_shipment['tone']); ?>"><?php echo esc_html($sfs_shipment['status_label']); ?></span>
				</div>
				<p class="sfs-package__number">
					<span class="sfs-package__label">Tracking number</span>
					<code><?php echo esc_html($sfs_shipment['tracking_number']); ?></code>
					<button type="button" class="sfs-copy" data-sfs-copy="<?php echo esc_attr($sfs_shipment['tracking_number']); ?>" aria-label="Copy tracking number <?php echo esc_attr($sfs_shipment['tracking_number']); ?>">Copy</button>
				</p>
				<?php if ($sfs_shipment['delivered_at']) : ?>
					<p class="sfs-package__eta"><strong>Delivered</strong> <?php echo esc_html($sfs_shipment['delivered_at']); ?></p>
				<?php elseif ($sfs_shipment['eta']) : ?>
					<p class="sfs-package__eta"><strong>Estimated delivery</strong> <?php echo esc_html($sfs_shipment['eta']['label'] . ('' !== $sfs_shipment['eta']['window'] ? ', ' . $sfs_shipment['eta']['window'] : '')); ?> <small>(<?php echo esc_html($sfs_shipment['eta']['source']); ?>)</small></p>
				<?php endif; ?>
				<?php if ($sfs_shipment['last_event']) : ?>
					<p class="sfs-package__latest"><span class="sfs-package__label">Latest</span> <?php echo esc_html(implode(' · ', array_filter(array($sfs_shipment['last_event']['description'], $sfs_shipment['last_event']['location'], $sfs_shipment['last_event']['when'])))); ?></p>
				<?php endif; ?>
				<?php if (count($sfs_shipment['events']) > 1) : ?>
					<details class="sfs-package__history">
						<summary>Tracking history (<?php echo esc_html((string) count($sfs_shipment['events'])); ?>)</summary>
						<ol class="sfs-events">
							<?php foreach ($sfs_shipment['events'] as $sfs_event) : ?>
								<li class="sfs-event sfs-event--<?php echo esc_attr('' !== $sfs_event['kind'] ? $sfs_event['kind'] : 'scan'); ?>">
									<span class="sfs-event__when"><?php echo esc_html($sfs_event['when']); ?></span>
									<span class="sfs-event__what"><?php echo esc_html($sfs_event['description']); ?><?php if ('' !== $sfs_event['note']) : ?> <span class="sfs-event__note"><?php echo esc_html($sfs_event['note']); ?></span><?php endif; ?></span>
									<?php if ('' !== $sfs_event['location']) : ?>
										<span class="sfs-event__where"><?php echo esc_html($sfs_event['location']); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ol>
					</details>
				<?php endif; ?>
				<div class="sfs-package__foot">
					<p class="sfs-package__updated" data-sfs-checked="<?php echo esc_attr((string) ($sfs_shipment['last_checked_at'] ?? '')); ?>" data-carrier-name="<?php echo esc_attr($sfs_shipment['carrier_name']); ?>" data-checkable="<?php echo in_array($sfs_shipment['carrier'], array('ups', 'fedex'), true) ? '1' : '0'; ?>"><?php echo esc_html(\SFS\OrderTracking\PublicView::updated_text($sfs_shipment, $now)); ?></p>
					<?php if ('' !== $sfs_shipment['tracking_url']) : ?>
						<a class="sfs-package__carrier" href="<?php echo esc_url($sfs_shipment['tracking_url']); ?>" target="_blank" rel="noopener">Track on <?php echo esc_html($sfs_shipment['carrier_name']); ?> <span aria-hidden="true">&rarr;</span><span class="sfs-visually-hidden"> (opens in a new tab)</span></a>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>
	<p class="sfs-shipments__expired" data-sfs-expired hidden>This page stopped updating. <a href="<?php echo esc_url($sfs_refresh); ?>">Refresh to keep updating</a>.</p>
</section>

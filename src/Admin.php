<?php
/**
 * Staff screens: Orders → Tracking sync (status, review queue, carrier health, new carrier wording, log, settings,
 * key generator), the Shipments box on the order screen (B2Sign order number field, packages, check now, add,
 * remove) and the Shipment column in the order list.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

use SFS\OrderTracking\Core\CarrierTextNormalizer;
use SFS\OrderTracking\Core\CheckScheduler;
use SFS\OrderTracking\Core\TrackingUrls;

final class Admin {
	public const PAGE = 'sfs-tracking';

	/** @var array{key_id:string,secret:string}|null A key generated in this request, shown once. */
	private static $generated = null;
	/** @var string */
	private static $saved = '';

	public static function hooks(): void {
		add_action('admin_menu', array(self::class, 'menu'));
		add_action('add_meta_boxes_order', array(self::class, 'meta_box'));
		add_filter('manage_order_posts_columns', array(self::class, 'columns'), 30);
		add_action('manage_order_posts_custom_column', array(self::class, 'column'), 10, 2);
		add_action('quick_edit_custom_box', array(self::class, 'quick_edit'), 10, 2);
		add_action('admin_enqueue_scripts', array(self::class, 'assets'));
	}

	public static function menu(): void {
		$hook = add_submenu_page('edit.php?post_type=order', 'Tracking sync', 'Tracking sync', 'edit_others_posts', self::PAGE, array(self::class, 'page'));
		if ($hook) {
			add_action('load-' . $hook, array(self::class, 'handle_post'));
		}
	}

	public static function assets(string $hook): void {
		$screen = get_current_screen();
		$ours = $screen && (in_array($screen->id, array('order', 'edit-order'), true) || 'order_page_' . self::PAGE === $screen->id);
		if (!$ours) {
			return;
		}
		$base = dirname(__DIR__);
		wp_enqueue_style('sfs-tracking-admin', plugins_url('/assets/css/admin.css', SFS_TRACKING_FILE), array(), (string) filemtime($base . '/assets/css/admin.css'));
		wp_enqueue_script('sfs-tracking-admin', plugins_url('/assets/js/admin.js', SFS_TRACKING_FILE), 'edit-order' === $screen->id ? array('inline-edit-post') : array(), (string) filemtime($base . '/assets/js/admin.js'), array('in_footer' => true));
		wp_add_inline_script('sfs-tracking-admin', 'window.sfsTrackingAdmin = ' . wp_json_encode(array('root' => esc_url_raw(rest_url(Rest::NS . '/admin/')), 'nonce' => wp_create_nonce('wp_rest'))) . ';', 'before');
	}

	/**
	 * Settings and key generation are POSTs to the page itself, so a generated key can be shown once without
	 * storing it anywhere.
	 */
	public static function handle_post(): void {
		if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || !current_user_can('manage_options')) {
			return;
		}
		if (isset($_POST['sfs_generate_key'])) {
			check_admin_referer('sfs_tracking_generate_key');
			self::$generated = Keys::generate();
			Log::add('action', 'New extension key generated (not stored; shown once)', array('user' => get_current_user_id()));
		} elseif (isset($_POST['sfs_settings'])) {
			check_admin_referer('sfs_tracking_settings');
			$input = is_array($_POST['sfs_settings']) ? wp_unslash($_POST['sfs_settings']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			Settings::save(array_map(static function ($value) {
				return is_scalar($value) ? sanitize_text_field((string) $value) : '';
			}, $input));
			self::$saved = 'Settings saved.';
		}
	}

	public static function page(): void {
		Schema::ensure();
		$dry = Settings::dry_run();
		$last = Log::last_run();
		$counts = Inbox::counts();
		$active = Orders::active();
		$packages = 0;
		foreach ($active as $shipments) {
			foreach ($shipments as $shipment) {
				if ('' === CheckScheduler::stop_reason($shipment, time())) {
					$packages++;
				}
			}
		}
		?>
		<div class="wrap sfs-admin">
			<h1 class="wp-heading-inline">Tracking sync</h1>
			<span class="sfs-pill sfs-pill--<?php echo $dry ? 'dry' : 'live'; ?>"><?php echo $dry ? 'Dry run: nothing is saved to orders' : 'Live: syncs update orders'; ?></span>
			<?php if (self::$saved) : ?>
				<div class="notice notice-success"><p><?php echo esc_html(self::$saved); ?></p></div>
			<?php endif; ?>
			<p class="sfs-admin__intro">The SFS Tracking Sync Chrome extension reads B2Sign orders and UPS/FedEx tracking pages in the owner's Chrome and sends them here. Customers see live status on the tracking page; the supplier is never shown to them.</p>

			<h2>Status</h2>
			<table class="widefat striped sfs-table sfs-table--facts">
				<tbody>
					<tr><th scope="row">Extension key</th><td><?php echo Keys::configured() ? 'Set in wp-config.php (key ID ' . esc_html(Keys::key_id()) . ')' : '<strong>Not set.</strong> Generate a key below.'; ?></td></tr>
					<tr><th scope="row">Last sync</th><td>
						<?php if ($last) : ?>
							<?php echo esc_html(human_time_diff($last['time']) . ' ago · ' . $last['message']); ?>
							<?php $client = (array) ($last['context']['client'] ?? array()); ?>
							<?php if (!empty($client['version'])) : ?><br><span class="description"><?php echo esc_html('Extension ' . $client['version'] . ' · ' . ($client['env'] ?: 'unknown') . ' profile'); ?></span><?php endif; ?>
						<?php else : ?>
							Never. Load the extension in Chrome and click Sync now.
						<?php endif; ?>
					</td></tr>
					<tr><th scope="row">Packages being checked</th><td><?php echo esc_html((string) $packages); ?></td></tr>
					<tr><th scope="row">Review queue</th><td><?php echo esc_html(sprintf('%d need review · %d unmatched · %d marked not ours · %d linked', $counts['needs_review'], $counts['unmatched'], $counts['ignored'], $counts['linked'])); ?></td></tr>
					<tr><th scope="row">Mode</th><td><?php echo $dry ? 'Dry run: the extension\'s syncs are previewed in the log and the review queue, and nothing changes on orders.' : 'Live: tracking is saved to orders' . (Settings::get('auto_ship') ? ', and an order is marked Shipped (one shipped email) when every item has shipped.' : '.'); ?><?php echo Settings::dry_run_locked() ? ' <em>(Pinned by SFS_TRACKING_DRY_RUN in wp-config.php.)</em>' : ''; ?></td></tr>
				</tbody>
			</table>

			<?php self::review_queue(); ?>
			<?php self::carrier_health(); ?>
			<?php self::log_table(); ?>
			<?php if (current_user_can('manage_options')) : ?>
				<?php self::settings_form(); ?>
				<?php self::key_section(); ?>
			<?php endif; ?>
			<p class="sfs-admin__msg" aria-live="polite" data-sfs-msg></p>
		</div>
		<?php
	}

	private static function review_queue(): void {
		$rows = Inbox::rows();
		$items = array();
		foreach ($rows as $row) {
			$items[(int) $row['item_id']] = $items[(int) $row['item_id']] ?? $row;
			$items[(int) $row['item_id']]['numbers'][] = $row['tracking_number'];
		}
		?>
		<h2 id="review">Review queue</h2>
		<p class="description">Supplier items that could not be matched with certainty. Typing the B2Sign order number on our order links them automatically; here you can confirm a suggested order or mark an item as not ours (other clients, stock).</p>
		<?php if (!$items) : ?>
			<p>Nothing to review.</p>
		<?php else : ?>
			<div class="sfs-bulk" data-sfs-bulk>
				<label class="screen-reader-text" for="sfs-bulk-action">Bulk action</label>
				<select id="sfs-bulk-action">
					<option value="">Bulk actions</option>
					<option value="confirm">Confirm suggested order</option>
					<option value="ignore">Mark not ours</option>
				</select>
				<button type="button" class="button" data-sfs-bulk-apply>Apply</button>
				<span class="description" data-sfs-bulk-count>0 selected</span>
			</div>
			<table class="widefat striped sfs-table" data-sfs-bulk-table>
				<thead><tr><td class="check-column"><label class="screen-reader-text" for="sfs-select-all">Select all</label><input type="checkbox" id="sfs-select-all" data-sfs-select-all></td><th>Supplier item</th><th>Ship to</th><th>Packages</th><th>Suggested orders</th><th>Action</th></tr></thead>
				<tbody>
					<?php foreach ($items as $row) :
						$record = $row['record'];
						$to = (array) ($record['ship_to'] ?? array());
						?>
						<tr data-sfs-row="<?php echo esc_attr((string) $row['id']); ?>">
							<th scope="row" class="check-column"><label class="screen-reader-text" for="sfs-row-<?php echo esc_attr((string) $row['id']); ?>">Select <?php echo esc_attr($record['jobid'] ?? ''); ?></label><input type="checkbox" id="sfs-row-<?php echo esc_attr((string) $row['id']); ?>" value="<?php echo esc_attr((string) $row['id']); ?>" data-sfs-select<?php echo $row['candidates'] ? '' : ' data-no-suggestion="1"'; ?>></th>
							<td>
								<strong><?php echo esc_html(($record['order_number'] ?? '') . ' · ' . ($record['jobid'] ?? '')); ?></strong>
								<span class="sfs-state sfs-state--<?php echo esc_attr($row['state']); ?>"><?php echo esc_html('needs_review' === $row['state'] ? 'Needs review' : 'Unmatched'); ?></span><br>
								<?php echo esc_html(($record['item_title'] ?? '') . ' × ' . ($record['quantity'] ?? 1)); ?><br>
								<span class="description"><?php echo esc_html('Job name: ' . ($record['jobname'] ?? '') . ' · Status: ' . ($record['status'] ?? '') . ' · Purchased ' . ($record['purchased'] ?? '')); ?></span>
							</td>
							<td><?php echo esc_html(trim(($to['fullname'] ?? '') . ' ' . (!empty($to['company']) ? '(' . $to['company'] . ')' : ''))); ?><br><?php echo esc_html(trim(($to['city'] ?? '') . ', ' . ($to['state'] ?? '') . ' ' . ($to['zipcode'] ?? ''), ', ')); ?></td>
							<td><?php echo esc_html(implode(', ', array_map(static function ($package) {
								return (TrackingUrls::NAMES[$package['carrier']] ?? $package['carrier']) . ' ' . $package['tn'];
							}, (array) ($record['packages'] ?? array()))) ?: '—'); ?></td>
							<td>
								<?php foreach ((array) $row['candidates'] as $candidate) : ?>
									<button type="button" class="button button-small" data-sfs-action="inbox/confirm" data-id="<?php echo esc_attr((string) $row['id']); ?>" data-order-number="<?php echo esc_attr($candidate['order_number']); ?>">Confirm #<?php echo esc_html($candidate['order_number']); ?></button>
									<span class="description"><?php echo esc_html(isset($candidate['reason']) ? $candidate['reason'] : 'score ' . $candidate['score']); ?></span><br>
								<?php endforeach; ?>
								<?php echo $row['candidates'] ? '' : '<span class="description">' . esc_html($row['message']) . '</span>'; ?>
							</td>
							<td class="sfs-actions">
								<label class="screen-reader-text" for="sfs-confirm-<?php echo esc_attr((string) $row['id']); ?>">Our order number</label>
								<input type="text" id="sfs-confirm-<?php echo esc_attr((string) $row['id']); ?>" class="small-text sfs-order-input" placeholder="SN6LTHF5IA" autocomplete="off">
								<button type="button" class="button button-small" data-sfs-action="inbox/confirm" data-id="<?php echo esc_attr((string) $row['id']); ?>" data-order-from="#sfs-confirm-<?php echo esc_attr((string) $row['id']); ?>">Confirm</button>
								<button type="button" class="button-link sfs-danger" data-sfs-action="inbox/ignore" data-id="<?php echo esc_attr((string) $row['id']); ?>">Not ours</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
		$ignored = Inbox::rows(array('ignored'), 100);
		if ($ignored) :
			?>
			<details class="sfs-details">
				<summary><?php echo esc_html(count($ignored) . ' package(s) marked not ours'); ?></summary>
				<div class="sfs-bulk" data-sfs-bulk>
					<input type="hidden" value="reopen" data-sfs-bulk-fixed>
					<button type="button" class="button" data-sfs-bulk-apply>Reopen selected</button>
					<span class="description" data-sfs-bulk-count>0 selected</span>
				</div>
				<ul data-sfs-bulk-table>
					<?php foreach ($ignored as $row) : ?>
						<li><label><input type="checkbox" value="<?php echo esc_attr((string) $row['id']); ?>" data-sfs-select> <?php echo esc_html(($row['record']['order_number'] ?? '') . ' · ' . ($row['record']['jobid'] ?? '') . ' · ' . ($row['record']['item_title'] ?? '')); ?></label>
							<button type="button" class="button-link" data-sfs-action="inbox/reopen" data-id="<?php echo esc_attr((string) $row['id']); ?>">Reopen</button></li>
					<?php endforeach; ?>
				</ul>
			</details>
			<?php
		endif;
	}

	private static function carrier_health(): void {
		$streaks = Health::carrier_streaks();
		$wording = Log::wording_list();
		?>
		<h2>Carrier pages</h2>
		<table class="widefat striped sfs-table sfs-table--facts">
			<tbody>
				<?php foreach (array('ups' => 'UPS', 'fedex' => 'FedEx') as $carrier => $name) :
					$streak = $streaks[$carrier] ?? array('count' => 0, 'codes' => array());
					?>
					<tr><th scope="row"><?php echo esc_html($name); ?></th><td><?php echo $streak['count'] ? esc_html($streak['count'] . ' failed reading(s) in a row: ' . implode(', ', array_unique($streak['codes']))) : 'Last readings OK (or none yet).'; ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<h3>New carrier wording</h3>
		<?php if (!$wording) : ?>
			<p class="description">None. Every status text seen recently is in the status table.</p>
		<?php else : ?>
			<p class="description">Status texts the plugin did not recognise; those packages kept their previous status. Add them to the table in <code>src/Core/CarrierTextNormalizer.php</code>.</p>
			<table class="widefat striped sfs-table">
				<thead><tr><th>Carrier</th><th>Text</th><th>Seen</th><th>Last seen</th></tr></thead>
				<tbody>
					<?php foreach ($wording as $entry) : ?>
						<tr><td><?php echo esc_html(strtoupper($entry['carrier'])); ?></td><td><?php echo esc_html($entry['text']); ?></td><td><?php echo esc_html((string) $entry['count']); ?></td><td><?php echo esc_html(human_time_diff($entry['last']) . ' ago'); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function log_table(): void {
		$rows = Log::recent(60);
		?>
		<h2>Recent runs and actions</h2>
		<table class="widefat striped sfs-table">
			<thead><tr><th>When</th><th>Type</th><th>What</th></tr></thead>
			<tbody>
				<?php foreach ($rows as $row) : ?>
					<tr class="sfs-log sfs-log--<?php echo esc_attr($row['level']); ?>">
						<td><?php echo esc_html(wp_date('M j, g:i a', $row['time'])); ?></td>
						<td><?php echo esc_html($row['type']); ?></td>
						<td><?php echo esc_html($row['message']); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if (!$rows) : ?>
					<tr><td colspan="3">Nothing yet.</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private static function settings_form(): void {
		$s = Settings::all();
		$checkbox = static function (string $key, string $label, string $help = '') use ($s) {
			printf(
				'<label class="sfs-check"><input type="hidden" name="sfs_settings[%1$s]" value="0"><input type="checkbox" name="sfs_settings[%1$s]" value="1" %2$s> %3$s</label>%4$s',
				esc_attr($key),
				checked(!empty($s[$key]), true, false),
				esc_html($label),
				'' === $help ? '' : '<span class="description">' . esc_html($help) . '</span>'
			);
		};
		$number = static function (string $key, string $label, int $min, int $max) use ($s) {
			printf('<label class="sfs-number">%3$s <input type="number" name="sfs_settings[%1$s]" value="%2$d" min="%4$d" max="%5$d" class="small-text"></label>', esc_attr($key), (int) $s[$key], esc_html($label), $min, $max);
		};
		?>
		<h2 id="settings">Settings</h2>
		<form method="post" class="sfs-settings">
			<?php wp_nonce_field('sfs_tracking_settings'); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Mode</th><td>
					<?php $checkbox('dry_run', 'Dry run', 'Syncs only preview what they would do. Keep this on until a dry run on live looks right.'); ?>
					<?php echo Settings::dry_run_locked() ? '<p class="description">SFS_TRACKING_DRY_RUN in wp-config.php overrides this setting.</p>' : ''; ?>
				</td></tr>
				<tr><th scope="row">Order status</th><td>
					<?php $checkbox('auto_ship', 'Mark the order Shipped when every item has shipped', 'Sends the existing shipped email once. Never for cancelled, refunded or failed orders.'); ?><br>
					<?php $checkbox('auto_production', 'Mark In production from the supplier status', 'Needs the supplier statuses below.'); ?>
					<p><label>Supplier statuses meaning "in production" <input type="text" name="sfs_settings[production_statuses]" value="<?php echo esc_attr($s['production_statuses']); ?>" class="regular-text" placeholder="e.g. in_production, printing"></label></p>
				</td></tr>
				<tr><th scope="row">Customer emails</th><td>
					<?php $checkbox('email_delivered', 'Email when a package is delivered'); ?><br>
					<?php $checkbox('email_issue', 'Email when a package has a delivery issue'); ?><br>
					<?php $checkbox('email_extra_packages', 'Email when another package ships for an order already marked Shipped'); ?>
				</td></tr>
				<tr><th scope="row">Staff alerts</th><td>
					<?php $checkbox('staff_exception_email', 'Email staff about delivery issues'); ?><br>
					<?php $checkbox('staff_health_email', 'Email staff when the sync stops or carrier pages fail'); ?>
					<p><label>Staff email <input type="email" name="sfs_settings[staff_email]" value="<?php echo esc_attr($s['staff_email']); ?>" class="regular-text" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>"></label></p>
					<p><?php $number('heartbeat_hours', 'Alert when no sync for (hours)', 1, 168); ?></p>
				</td></tr>
				<tr><th scope="row">Scan locations shown to customers</th><td>
					<select name="sfs_settings[location_mode]">
						<?php foreach (array('destination' => 'Destination side only (last-mile scans)', 'hide' => 'Hide all', 'all' => 'Show all') as $value => $label) : ?>
							<option value="<?php echo esc_attr($value); ?>" <?php selected($s['location_mode'], $value); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th scope="row">Matching</th><td>
					<?php $checkbox('auto_apply_fuzzy', 'Auto-apply confident fuzzy matches', 'Only a single candidate scoring 90+ with the runner-up under 60. Off: every fuzzy match waits in the review queue.'); ?>
				</td></tr>
				<tr><th scope="row">Schedule</th><td>
					<p><?php $number('interval_min', 'Extension sync every (minutes)', 5, 60); ?> <?php $number('lookback_days', 'B2Sign orders from the last (days)', 7, 120); ?> <?php $number('max_checks_per_run', 'Carrier checks per run', 1, 20); ?></p>
					<p><?php $number('check_pre_transit', 'Label created (min)', 30, 720); ?> <?php $number('check_in_transit', 'In transit', 15, 360); ?> <?php $number('check_out_for_delivery', 'Out for delivery', 5, 120); ?> <?php $number('check_exception', 'Delivery issue', 15, 360); ?> <?php $number('check_unknown', 'Unknown', 30, 720); ?></p>
				</td></tr>
				<tr><th scope="row">Uninstall</th><td><?php $checkbox('delete_on_uninstall', 'Also delete tracking data stored on orders when the plugin is deleted'); ?></td></tr>
			</table>
			<?php submit_button('Save settings'); ?>
		</form>
		<?php
	}

	private static function key_section(): void {
		?>
		<h2 id="key">Extension key</h2>
		<?php if (self::$generated) : ?>
			<div class="notice notice-warning inline"><p><strong>Copy these two lines now; they are not stored and will not be shown again.</strong> Paste them into <code>wp-config.php</code> above “That's all, stop editing!”, then paste the key ID and secret into the extension's Options.</p></div>
			<textarea class="large-text code sfs-key" rows="3" readonly onclick="this.select()"><?php echo esc_textarea(Keys::define_lines(self::$generated)); ?></textarea>
		<?php endif; ?>
		<p><?php echo Keys::configured() ? 'A key is set in wp-config.php. Generating a new one does not change it until you replace the two lines.' : 'No key is set yet.'; ?></p>
		<form method="post">
			<?php wp_nonce_field('sfs_tracking_generate_key'); ?>
			<button type="submit" class="button" name="sfs_generate_key" value="1">Generate key</button>
		</form>
		<?php
	}

	public static function meta_box(): void {
		add_meta_box('sfs-tracking-shipments', 'Shipments', array(self::class, 'render_box'), 'order', 'normal', 'high');
	}

	public static function render_box(\WP_Post $post): void {
		$order_id = (int) $post->ID;
		$shipments = Orders::shipments($order_id);
		$items = Orders::items($order_id);
		$now = time();
		wp_nonce_field(OrderHooks::NONCE, OrderHooks::NONCE);
		?>
		<div class="sfs-box" data-order="<?php echo esc_attr((string) $order_id); ?>">
			<p class="sfs-box__b2">
				<label for="sfs_b2_orders"><strong>B2Sign order number(s)</strong></label>
				<input type="text" id="sfs_b2_orders" name="sfs_b2_orders" value="<?php echo esc_attr(implode(', ', Orders::b2_numbers($order_id))); ?>" placeholder="e.g. B2612345" autocomplete="off" spellcheck="false">
				<button type="button" class="button" data-sfs-action="link-now" data-order="<?php echo esc_attr((string) $order_id); ?>">Link now</button>
				<span class="description">Type the supplier's order number when you place the supplier order, then Update order. Tracking links on the next sync (within about 10 minutes), or press Link now after saving.</span>
			</p>

			<?php if (!$shipments) : ?>
				<p class="sfs-box__empty">No shipments yet.</p>
			<?php else : ?>
				<table class="widefat striped sfs-table sfs-shipments-admin">
					<thead><tr><th>Package</th><th>Status</th><th>Delivery</th><th>Checked</th><th><span class="screen-reader-text">Actions</span></th></tr></thead>
					<tbody>
						<?php $i = 0; foreach ($shipments as $key => $shipment) : $i++; ?>
							<tr>
								<td>
									<strong><?php echo esc_html((count($shipments) > 1 ? $i . '. ' : '') . (TrackingUrls::NAMES[$shipment['carrier']] ?? ucfirst((string) $shipment['carrier']))); ?></strong>
									<?php $url = PublicView::tracking_url((string) $shipment['carrier'], (string) $shipment['tracking_number']); ?>
									<?php if ($url && 'freight' !== $shipment['carrier']) : ?>
										<a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($shipment['tracking_number']); ?></a>
									<?php else : ?>
										<?php echo esc_html($shipment['tracking_number']); ?>
									<?php endif; ?>
									<br><span class="description"><?php echo esc_html('b2sign' === $shipment['source'] ? 'Supplier ' . ($shipment['b2']['jobid'] ?? '') . (empty($shipment['b2']['service']) ? '' : ' · ' . $shipment['b2']['service']) : 'Added by staff'); ?></span>
								</td>
								<td>
									<?php $waiting = 'unknown' === $shipment['status'] && !(int) $shipment['last_checked_at']; ?>
									<span class="order-badge order-badge--<?php echo esc_attr($waiting ? 'info' : CarrierTextNormalizer::tone((string) $shipment['status'])); ?>"><?php echo esc_html($waiting ? 'Waiting for first check' : CarrierTextNormalizer::label((string) $shipment['status'])); ?></span>
									<?php if (!empty($shipment['last_event'])) : ?>
										<br><span class="description"><?php echo esc_html(trim($shipment['last_event']['status_text'] . ' · ' . $shipment['last_event']['location_text'], ' ·')); ?></span>
									<?php endif; ?>
									<?php if ('' !== (string) $shipment['last_error']) : ?>
										<br><span class="sfs-error"><?php echo esc_html('Last check: ' . $shipment['last_error'] . ' (' . (int) $shipment['check_errors'] . ' failed)'); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html(self::delivery_text($shipment)); ?></td>
								<td><?php echo esc_html(self::checked_text($shipment, $now)); ?></td>
								<td class="sfs-actions">
									<?php if (TrackingUrls::checkable((string) $shipment['carrier']) && 'delivered' !== $shipment['status']) : ?>
										<button type="button" class="button button-small" data-sfs-action="check-now" data-order="<?php echo esc_attr((string) $order_id); ?>" data-key="<?php echo esc_attr($key); ?>">Check now</button>
									<?php endif; ?>
									<button type="button" class="button-link sfs-danger" data-sfs-action="shipment" data-method="DELETE" data-order="<?php echo esc_attr((string) $order_id); ?>" data-key="<?php echo esc_attr($key); ?>" data-confirm="Remove this package from the order?">Remove</button>
								</td>
							</tr>
							<?php if (count((array) $shipment['events']) > 0) : ?>
								<tr class="sfs-events-row"><td colspan="5">
									<details><summary><?php echo esc_html('Scan history (' . count((array) $shipment['events']) . ')'); ?></summary>
										<ol class="sfs-admin-events">
											<?php foreach ((array) $shipment['events'] as $event) : ?>
												<li><?php echo esc_html(trim($event['date_text'] . ' ' . $event['time_text']) . ' — ' . $event['status_text'] . ('' !== $event['detail_text'] ? ' (' . $event['detail_text'] . ')' : '') . ('' !== $event['location_text'] ? ' — ' . $event['location_text'] : '')); ?></li>
											<?php endforeach; ?>
										</ol>
									</details>
								</td></tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">Customers see these packages on the tracking page with destination-side scan locations only<?php echo 'destination' === Settings::get('location_mode') ? '' : ' (setting: ' . esc_html((string) Settings::get('location_mode')) . ')'; ?>. The first package is the tracking number in the status box and emails.</p>
			<?php endif; ?>

			<div class="sfs-box__add">
				<label for="sfs-add-carrier" class="screen-reader-text">Carrier</label>
				<select id="sfs-add-carrier">
					<?php foreach (array('' => 'Carrier…', 'ups' => 'UPS', 'fedex' => 'FedEx', 'usps' => 'USPS', 'freight' => 'Freight / other') as $value => $label) : ?>
						<option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="sfs-add-number" class="screen-reader-text">Tracking number</label>
				<input type="text" id="sfs-add-number" placeholder="Tracking number" autocomplete="off" spellcheck="false">
				<button type="button" class="button" data-sfs-action="shipment" data-method="POST" data-order="<?php echo esc_attr((string) $order_id); ?>">Add package</button>
			</div>

			<?php if ($items) : ?>
				<h4>Linked supplier items <span class="description">(staff only)</span></h4>
				<ul class="sfs-items">
					<?php foreach ($items as $item) : ?>
						<li><?php echo esc_html($item['b2_order'] . ' · ' . $item['jobid'] . ' · ' . $item['title'] . ' × ' . $item['quantity'] . ' · ' . $item['status'] . ($item['is_reprint'] ? ' · reprint' : '')); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="sfs-box__msg" aria-live="polite" data-sfs-msg></p>
		</div>
		<?php
	}

	/**
	 * @param mixed $columns
	 */
	public static function columns($columns): array {
		$columns = (array) $columns;
		$updated = array();
		foreach ($columns as $key => $label) {
			$updated[$key] = $label;
			if ('delivery_date' === $key) {
				$updated['sfs_shipment'] = 'Shipment';
			}
		}
		if (!isset($updated['sfs_shipment'])) {
			$updated['sfs_shipment'] = 'Shipment';
		}
		return $updated;
	}

	/**
	 * @param mixed $column
	 * @param mixed $post_id
	 */
	public static function column($column, $post_id): void {
		if ('sfs_shipment' !== $column) {
			return;
		}
		$numbers = Orders::b2_numbers((int) $post_id);
		// Read by Quick Edit to fill the B2Sign order number field.
		printf('<span class="hidden sfs-quick-data" data-b2="%s"></span>', esc_attr(implode(', ', $numbers)));
		if ($numbers) {
			printf('<span class="sfs-b2-numbers">%s</span><br>', esc_html('B2Sign ' . implode(', ', $numbers)));
		}
		$shipments = Orders::shipments((int) $post_id);
		if (!$shipments) {
			echo $numbers ? '<span class="description">No tracking yet</span>' : '&mdash;';
			return;
		}
		$first = reset($shipments);
		printf(
			'<span class="order-badge order-badge--%s">%s</span><br><span class="description">%s</span>',
			esc_attr(CarrierTextNormalizer::tone((string) $first['status'])),
			esc_html(CarrierTextNormalizer::label((string) $first['status'])),
			esc_html(trim((TrackingUrls::NAMES[$first['carrier']] ?? ucfirst((string) $first['carrier'])) . ' · ' . self::delivery_text($first) . (count($shipments) > 1 ? ' · +' . (count($shipments) - 1) . ' more' : '') . ' · ' . self::checked_text($first, time()), ' ·'))
		);
	}

	/**
	 * B2Sign order number field in the order list's Quick Edit (filled by assets/js/admin.js).
	 *
	 * @param mixed $column
	 * @param mixed $post_type
	 */
	public static function quick_edit($column, $post_type): void {
		if ('order' !== $post_type || 'sfs_shipment' !== $column) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right sfs-quick-edit">
			<div class="inline-edit-col">
				<label>
					<span class="title">B2Sign</span>
					<span class="input-text-wrap"><input type="text" name="sfs_b2_orders" value="" placeholder="e.g. B2612345" autocomplete="off" spellcheck="false"></span>
				</label>
				<p class="description">Separate several with commas. Tracking links right after saving.</p>
			</div>
		</fieldset>
		<?php
	}

	private static function delivery_text(array $shipment): string {
		if ('delivered' === $shipment['status']) {
			$label = PublicView::delivered_label($shipment);
			return 'Delivered' . ('' !== $label ? ' ' . $label : '');
		}
		if (!empty($shipment['eta']['date'])) {
			return 'Est. ' . PublicView::date_label((string) $shipment['eta']['date'], 'D, M j') . (empty($shipment['eta']['window']) ? '' : ', ' . $shipment['eta']['window']);
		}
		return '';
	}

	private static function checked_text(array $shipment, int $now): string {
		$reason = CheckScheduler::stop_reason($shipment, $now);
		$checked = (int) $shipment['last_checked_at'];
		$text = $checked ? human_time_diff($checked, $now) . ' ago' : 'Not yet';
		if ('' !== $reason) {
			$labels = array('delivered' => '', 'cancelled' => ' · stopped', 'carrier' => ' · no carrier checks', 'errors' => ' · stopped after repeated failures', 'age' => ' · stopped after 45 days');
			return $text . ($labels[$reason] ?? '');
		}
		if ((int) $shipment['priority_until'] > $now) {
			return $text . ' · next run';
		}
		$next = (int) $shipment['next_check_at'];
		return $text . ($next > $now ? ' · next in ' . human_time_diff($now, $next) : ' · due');
	}
}

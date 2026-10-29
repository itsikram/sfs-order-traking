<?php
/**
 * Staff screens: Orders → Tracking sync (summary, review queue, extension key; settings, carrier health and the log
 * folded away), the Shipments box on the order screen (B2Sign order number field, packages, check now, add,
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

	/** @var string */
	private static $saved = '';
	/** @var string */
	private static $error = '';

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
	 * Settings and the extension key are POSTs to the page itself.
	 */
	public static function handle_post(): void {
		if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || !current_user_can('manage_options')) {
			return;
		}
		if (isset($_POST['sfs_key_id'])) {
			check_admin_referer('sfs_tracking_key');
			if (isset($_POST['sfs_forget_key'])) {
				Keys::forget();
				self::$saved = 'Key removed. The extension cannot sync until a new key is saved here and in its Options.';
				Log::add('action', 'Extension key removed', array('user' => get_current_user_id()));
				return;
			}
			$error = Keys::save(sanitize_text_field(wp_unslash((string) $_POST['sfs_key_id'])), sanitize_text_field(wp_unslash((string) ($_POST['sfs_secret'] ?? ''))));
			if ('' !== $error) {
				self::$error = $error;
				return;
			}
			Auth::clear_failures();
			delete_transient(Auth::REJECT);
			self::$saved = 'Key saved. Enter the same key ID and secret in the extension Options, then click Sync now.';
			Log::add('action', 'Extension key saved (key ID ' . Keys::key_id() . ')', array('user' => get_current_user_id()));
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
		$packages = 0;
		foreach (Orders::active() as $shipments) {
			foreach ($shipments as $shipment) {
				if ('' === CheckScheduler::stop_reason($shipment, time())) {
					$packages++;
				}
			}
		}
		$stale = $last && (int) $last['time'] < time() - (int) Settings::get('heartbeat_hours') * HOUR_IN_SECONDS;
		$reject = Auth::last_reject();
		if (!Keys::configured()) {
			$connection = array('warn', 'Not connected', 'Save a key below, then enter it in the extension.');
		} elseif ($reject && (!$last || (int) $reject['time'] > (int) $last['time'])) {
			$connection = array('bad', 'Refused ' . human_time_diff((int) $reject['time']) . ' ago', self::reject_text($reject));
		} elseif (!$last) {
			$connection = array('warn', 'Waiting for the extension', 'No sync yet. Open the extension and click Sync now.');
		} else {
			$connection = array($stale ? 'warn' : 'ok', 'Last sync ' . human_time_diff((int) $last['time']) . ' ago', $stale ? 'Check that Chrome is open and signed in to B2Sign.' : 'Syncing normally.');
		}
		$review = (int) $counts['needs_review'] + (int) $counts['unmatched'];
		?>
		<div class="wrap sfs-admin">
			<h1 class="wp-heading-inline">Tracking sync</h1>
			<span class="sfs-pill sfs-pill--<?php echo $dry ? 'dry' : 'live'; ?>"><?php echo $dry ? 'Dry run: orders are not changed' : 'Live: orders update automatically'; ?></span>
			<?php if (self::$saved) : ?>
				<div class="notice notice-success"><p><?php echo esc_html(self::$saved); ?></p></div>
			<?php endif; ?>
			<?php if (self::$error) : ?>
				<div class="notice notice-error"><p><?php echo esc_html(self::$error); ?></p></div>
			<?php endif; ?>

			<div class="sfs-tiles">
				<div class="sfs-tile sfs-tile--<?php echo esc_attr($connection[0]); ?>">
					<span class="sfs-tile__label">Extension</span>
					<strong><?php echo esc_html($connection[1]); ?></strong>
					<span><?php echo esc_html($connection[2]); ?></span>
				</div>
				<a class="sfs-tile sfs-tile--<?php echo $review ? 'warn' : 'ok'; ?>" href="#review">
					<span class="sfs-tile__label">To review</span>
					<strong><?php echo esc_html($review ? sprintf('%d supplier item%s', $review, 1 === $review ? '' : 's') : 'Nothing'); ?></strong>
					<span><?php echo esc_html($review ? 'Confirm the order or mark not ours.' : 'Every supplier item is linked.'); ?></span>
				</a>
				<div class="sfs-tile">
					<span class="sfs-tile__label">Packages tracked</span>
					<strong><?php echo esc_html((string) $packages); ?></strong>
					<span><?php echo esc_html((int) $counts['linked'] . ' supplier items linked to orders'); ?></span>
				</div>
			</div>

			<?php self::review_queue(); ?>
			<?php if (current_user_can('manage_options')) : ?>
				<?php self::key_section(); ?>
			<?php endif; ?>

			<details class="sfs-fold">
				<summary>Recent activity</summary>
				<?php self::log_table(); ?>
			</details>
			<details class="sfs-fold">
				<summary>Carrier pages</summary>
				<?php self::carrier_health(); ?>
			</details>
			<?php if (current_user_can('manage_options')) : ?>
				<details class="sfs-fold" id="settings"<?php echo self::$saved && !isset($_POST['sfs_key_id']) ? ' open' : ''; // phpcs:ignore WordPress.Security.NonceVerification ?>>
					<summary>Settings</summary>
					<?php self::settings_form(); ?>
				</details>
			<?php endif; ?>
			<p class="sfs-admin__msg" aria-live="polite" data-sfs-msg></p>
		</div>
		<?php
	}

	/**
	 * Why the site refused the extension, in words the owner can act on.
	 *
	 * @param array{code:string,key_id:string} $reject
	 */
	private static function reject_text(array $reject): string {
		switch ($reject['code']) {
			case 'bad_key':
				return sprintf('The extension sent key ID "%s", but the key ID saved on this site is "%s". Put the same key ID and secret in both places.', '' === $reject['key_id'] ? '(empty)' : $reject['key_id'], Keys::key_id());
			case 'bad_signature':
				return 'The key ID matches but the secret does not. Paste the same secret in the extension Options and here.';
			case 'clock_skew':
				return 'The clock on the computer running Chrome is more than 5 minutes off. Fix the Windows clock, then click Sync now.';
			case 'missing_headers':
			case 'malformed':
				return 'The extension did not send a key. Enter the key ID and secret in its Options.';
			default:
				return 'The request could not be verified. Click Sync now in the extension again.';
		}
	}

	private static function review_queue(): void {
		$rows = Inbox::rows();
		$items = array();
		foreach ($rows as $row) {
			$items[(int) $row['item_id']] = $items[(int) $row['item_id']] ?? $row;
			$items[(int) $row['item_id']]['numbers'][] = $row['tracking_number'];
		}
		$ignored = Inbox::rows(array('ignored'), 100);
		if (!$items && !$ignored) {
			echo '<span id="review"></span>';
			return;
		}
		?>
		<h2 id="review">Review queue</h2>
		<?php if (!$items) : ?>
			<p class="description">Nothing to review.</p>
		<?php else : ?>
			<p class="description">Supplier items we could not match for sure. Typing the B2Sign order number on our order links them automatically; or confirm a suggested order here, or mark an item not ours.</p>
			<div class="sfs-bulk" data-sfs-bulk>
				<label class="screen-reader-text" for="sfs-bulk-action">Bulk action</label>
				<select id="sfs-bulk-action">
					<option value="">Bulk actions</option>
					<option value="confirm">Confirm suggested order</option>
					<option value="ignore">Mark not ours</option>
					<option value="delete">Delete</option>
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
		if ($ignored) :
			?>
			<details class="sfs-details">
				<summary><?php echo esc_html(count($ignored) . ' package(s) marked not ours'); ?></summary>
				<div class="sfs-bulk" data-sfs-bulk>
					<label class="screen-reader-text" for="sfs-bulk-ignored">Bulk action</label>
					<select id="sfs-bulk-ignored">
						<option value="">Bulk actions</option>
						<option value="reopen">Reopen</option>
						<option value="delete">Delete</option>
					</select>
					<button type="button" class="button" data-sfs-bulk-apply>Apply</button>
					<span class="description" data-sfs-bulk-count>0 selected</span>
				</div>
				<ul data-sfs-bulk-table>
					<li><label><input type="checkbox" data-sfs-select-all> <strong>Select all</strong></label></li>
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
		<form method="post" class="sfs-settings">
			<?php wp_nonce_field('sfs_tracking_settings'); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Mode</th><td>
					<?php $checkbox('dry_run', 'Dry run', 'Syncs only preview what they would do. Keep this on until a dry run on live looks right.'); ?>
					<?php echo Settings::dry_run_locked() ? '<p class="description">SFS_TRACKING_DRY_RUN in wp-config.php overrides this setting.</p>' : ''; ?>
				</td></tr>
				<tr><th scope="row">Order status</th><td>
					<?php $checkbox('auto_ship', 'Mark the order Shipped when every item has shipped', 'Sends the existing shipped email once. Never for cancelled, refunded or failed orders.'); ?><br>
					<?php $checkbox('auto_production', 'Mark In production from the supplier status', 'Moves a Pending review order to In production (the customer gets the in-production email) once B2Sign starts the item. Works before there is a tracking number.'); ?>
					<p><label>Supplier statuses meaning "in production" <input type="text" name="sfs_settings[production_statuses]" value="<?php echo esc_attr($s['production_statuses']); ?>" class="regular-text" placeholder="<?php echo esc_attr(implode(', ', Settings::DEFAULT_PRODUCTION)); ?>"></label> <span class="description">Leave empty to use the list shown.</span></p>
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
		$generated = Keys::generate();
		?>
		<section class="sfs-card" id="key" aria-labelledby="sfs-key-title">
			<h2 id="sfs-key-title">Connect the extension</h2>
			<?php if (Keys::in_config()) : ?>
				<p>This site uses the key in <code>wp-config.php</code> (key ID <code><?php echo esc_html(Keys::key_id()); ?></code>). To manage the key here instead, remove the two <code>SFS_TRACKING_…</code> lines from <code>wp-config.php</code>.</p>
			<?php else : ?>
				<p class="description">Use the same key ID and secret here and in the extension Options (pick Local or Live there to match this site). Local and live each keep their own key; a push does not copy it.</p>
				<form method="post" class="sfs-key-form" data-sfs-key-form>
					<?php wp_nonce_field('sfs_tracking_key'); ?>
					<p>
						<label for="sfs-key-id">Key ID</label>
						<span class="sfs-copy-field">
							<input type="text" id="sfs-key-id" name="sfs_key_id" class="regular-text code" value="<?php echo esc_attr(Keys::key_id()); ?>" autocomplete="off" spellcheck="false" required pattern="[A-Za-z0-9_\-]{4,64}">
							<button type="button" class="button" data-sfs-copy="#sfs-key-id">Copy</button>
						</span>
					</p>
					<p>
						<label for="sfs-secret">Secret</label>
						<span class="sfs-copy-field">
							<input type="password" id="sfs-secret" name="sfs_secret" class="regular-text code" value="" autocomplete="new-password" spellcheck="false" pattern="[A-Za-z0-9_\-]{32,128}" placeholder="<?php echo esc_attr(Keys::configured() ? 'Saved (hidden). Leave empty to keep it.' : 'At least 32 characters'); ?>"<?php echo Keys::configured() ? '' : ' required'; ?>>
							<button type="button" class="button" data-sfs-copy="#sfs-secret">Copy</button>
						</span>
					</p>
					<p class="sfs-key-form__actions">
						<button type="button" class="button" data-sfs-generate data-key-id="<?php echo esc_attr($generated['key_id']); ?>" data-secret="<?php echo esc_attr($generated['secret']); ?>">Generate new key</button>
						<button type="submit" class="button button-primary">Save key</button>
						<?php if (Keys::configured()) : ?>
							<button type="submit" class="button-link sfs-danger" name="sfs_forget_key" value="1" formnovalidate data-sfs-confirm="Remove the key? The extension stops syncing with this site until a new key is saved.">Remove key</button>
						<?php endif; ?>
					</p>
					<p class="sfs-key-form__note" data-sfs-key-note hidden><strong>Not active yet: click Save key.</strong> Then copy both values into the extension Options (Local profile) and click Save there. The secret is not shown again after saving.</p>
				</form>
			<?php endif; ?>
		</section>
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

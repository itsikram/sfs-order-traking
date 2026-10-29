<?php
/**
 * Staff alerts: no sync for a day on a weekday while orders are in production, carrier pages blocked or failing
 * to parse 3 times in a row, packages not checked for 24 hours, items waiting for review, and flags (refunded
 * supplier items, shipments for cancelled orders). Shown as admin notices; emailed hourly at most once per change
 * of the alert set (repeated daily while it lasts) when staff health emails are on.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

use SFS\OrderTracking\Core\CheckScheduler;

final class Health {
	public const CRON = 'sfs_tracking_health_check';
	public const MAIL_OPTION = 'sfs_tracking_health_mail';

	public static function hooks(): void {
		add_action('admin_notices', array(self::class, 'notices'));
		add_action(self::CRON, array(self::class, 'cron'));
	}

	public static function schedule(): void {
		if (!wp_next_scheduled(self::CRON)) {
			wp_schedule_event(time() + 600, 'hourly', self::CRON);
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook(self::CRON);
	}

	/**
	 * Current problems, keyed so a change in the set can be detected.
	 *
	 * @return array<string,array{level:string,text:string,email:bool}>
	 */
	public static function problems(?int $now = null): array {
		$now = $now ?? time();
		$problems = array();

		if (!Keys::configured()) {
			$problems['keys'] = array('level' => 'warning', 'text' => 'Tracking sync is not connected yet: generate a key on Orders → Tracking sync and paste it into wp-config.php.', 'email' => false);
		}

		$last = Log::last_run();
		$hours = (int) Settings::get('heartbeat_hours');
		$silent = !$last || (int) $last['time'] < $now - $hours * HOUR_IN_SECONDS;
		if (Keys::configured() && $silent && self::weekday($now) && self::in_production() > 0) {
			$problems['heartbeat'] = array(
				'level' => 'error',
				'text' => $last
					? sprintf('No tracking sync for %s. Open Chrome on the tracking computer (SFS Tracking Sync extension) and check that it is signed in to B2Sign.', human_time_diff((int) $last['time'], $now))
					: 'The tracking extension has never synced with this site.',
				'email' => true,
			);
		}

		foreach (self::carrier_streaks() as $carrier => $streak) {
			if ($streak['count'] >= 3) {
				$problems['carrier_' . $carrier] = array(
					'level' => 'error',
					'text' => sprintf('%s status pages failed %d times in a row (%s). Customers still see the last known status; the parser or the carrier page may need attention.', strtoupper($carrier), $streak['count'], implode(', ', array_unique($streak['codes']))),
					'email' => true,
				);
			}
		}

		$stale = self::stale_packages($now);
		if ($stale > 0 && !isset($problems['heartbeat'])) {
			$problems['stale'] = array('level' => 'warning', 'text' => sprintf('%d package(s) have not been checked with the carrier for 24 hours.', $stale), 'email' => true);
		}

		$counts = Inbox::counts();
		if ($counts['needs_review'] > 0) {
			$problems['review'] = array('level' => 'warning', 'text' => sprintf('%d supplier item(s) need review in Orders → Tracking sync.', $counts['needs_review']), 'email' => false);
		}

		$flags = array_filter(Log::recent(100, array('flag')), static function ($row) use ($now) {
			return $row['time'] > $now - 7 * DAY_IN_SECONDS;
		});
		if ($flags) {
			$first = reset($flags);
			$problems['flags'] = array('level' => 'warning', 'text' => sprintf('%d tracking flag(s) this week, latest: %s', count($flags), $first['message']), 'email' => false);
		}
		return $problems;
	}

	public static function notices(): void {
		if (!current_user_can('edit_others_posts')) {
			return;
		}
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || !in_array($screen->id, array('dashboard', 'edit-order', 'order', 'order_page_sfs-tracking'), true)) {
			return;
		}
		$problems = get_transient('sfs_trk_health');
		if (!is_array($problems) || 'order_page_sfs-tracking' === $screen->id) {
			$problems = self::problems();
			set_transient('sfs_trk_health', $problems, 5 * MINUTE_IN_SECONDS);
		}
		if ('order_page_sfs-tracking' !== $screen->id) {
			unset($problems['flags']);
		}
		foreach ($problems as $problem) {
			printf(
				'<div class="notice notice-%s"><p><strong>Tracking sync:</strong> %s %s</p></div>',
				esc_attr('error' === $problem['level'] ? 'error' : 'warning'),
				esc_html($problem['text']),
				'order_page_sfs-tracking' === $screen->id ? '' : '<a href="' . esc_url(admin_url('edit.php?post_type=order&page=sfs-tracking')) . '">Open Tracking sync</a>'
			);
		}
	}

	/**
	 * Hourly: email staff when the set of alerts changes (and again daily while it lasts).
	 */
	public static function cron(): void {
		if (!Settings::get('staff_health_email')) {
			return;
		}
		$problems = array_filter(self::problems(), static function ($problem) {
			return $problem['email'];
		});
		$state = get_option(self::MAIL_OPTION, array());
		$hash = $problems ? md5(implode('|', array_keys($problems))) : '';
		$sent_at = (int) ($state['time'] ?? 0);
		if ('' === $hash) {
			if (!empty($state['hash'])) {
				update_option(self::MAIL_OPTION, array('hash' => '', 'time' => time()), false);
			}
			return;
		}
		if (($state['hash'] ?? '') === $hash && $sent_at > time() - DAY_IN_SECONDS) {
			return;
		}
		$lines = array_map(static function ($problem) {
			return '- ' . $problem['text'];
		}, $problems);
		$lines[] = '';
		$lines[] = 'Details: ' . admin_url('edit.php?post_type=order&page=sfs-tracking');
		if (Emails::staff('[Tracking] Sync needs attention', implode("\n", $lines))) {
			update_option(self::MAIL_OPTION, array('hash' => $hash, 'time' => time()), false);
			Log::add('health', 'Emailed staff: ' . implode(', ', array_keys($problems)));
		}
	}

	/**
	 * Consecutive failed carrier readings per carrier, newest runs first, until a successful one.
	 *
	 * @return array<string,array{count:int,codes:string[]}>
	 */
	public static function carrier_streaks(): array {
		$streaks = array();
		$closed = array();
		foreach (Log::recent(200, array('run')) as $run) {
			foreach ((array) ($run['context']['carriers'] ?? array()) as $carrier => $codes) {
				if (isset($closed[$carrier])) {
					continue;
				}
				$streaks[$carrier] = $streaks[$carrier] ?? array('count' => 0, 'codes' => array());
				foreach (array_reverse((array) $codes) as $code) {
					if ('ok' === $code || 'not_found' === $code || 'mismatch' === $code) {
						$closed[$carrier] = true;
						break;
					}
					$streaks[$carrier]['count']++;
					$streaks[$carrier]['codes'][] = (string) $code;
				}
			}
		}
		return $streaks;
	}

	public static function stale_packages(int $now): int {
		$stale = 0;
		foreach (Orders::active() as $shipments) {
			foreach ($shipments as $shipment) {
				if ('' !== CheckScheduler::stop_reason($shipment, $now)) {
					continue;
				}
				$since = (int) ($shipment['last_checked_at'] ?: $shipment['created_at']);
				if ($since < $now - DAY_IN_SECONDS) {
					$stale++;
				}
			}
		}
		return $stale;
	}

	private static function weekday(int $now): bool {
		$day = (int) wp_date('N', $now);
		return $day <= 5;
	}

	private static function in_production(): int {
		$counts = wp_count_posts('order');
		return (int) ($counts->processing ?? 0);
	}
}

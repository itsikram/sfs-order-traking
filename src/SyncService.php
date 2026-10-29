<?php
/**
 * Processes one POST /sync call from the extension: links B2Sign items to orders, merges carrier readings, and
 * answers with the carrier checks due next. Runs under a lock, is idempotent (the same payload twice writes
 * nothing and sends nothing the second time), and in dry-run writes nothing except the run log and the
 * review-queue preview while still answering exactly what it would do.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking;

use SFS\OrderTracking\Core\B2DateParser;
use SFS\OrderTracking\Core\CarrierReading;
use SFS\OrderTracking\Core\CheckScheduler;
use SFS\OrderTracking\Core\Matcher;
use SFS\OrderTracking\Core\OrderNumberExtractor;
use SFS\OrderTracking\Core\RecordValidator;
use SFS\OrderTracking\Core\ShipmentMerger;
use SFS\OrderTracking\Core\TrackingUrls;

final class SyncService {
	/** @var bool */
	private $dry;
	/** @var int */
	private $now;
	/** @var string */
	private $run_id;
	/** @var array<string,int> */
	private $counts = array('matched' => 0, 'updated' => 0, 'unchanged' => 0, 'needs_review' => 0, 'unmatched' => 0, 'ignored' => 0, 'error' => 0);
	/** @var array<string,string[]> carrier => outcome codes in order */
	private $carriers = array();
	/** @var array<int,bool> */
	private $seen_items = array();

	/**
	 * Seconds to wait for another sync to finish before answering "busy".
	 */
	public static function lock_wait(): int {
		return max(1, (int) apply_filters('sfs_tracking_lock_wait', 15));
	}

	public function __construct(bool $dry, int $now, string $run_id) {
		$this->dry = $dry;
		$this->now = $now;
		$this->run_id = $run_id;
	}

	/**
	 * @param mixed $data Decoded request body.
	 * @return array|\WP_Error
	 */
	public static function handle($data) {
		$now = time();
		$body = RecordValidator::sync_body($data, $now);
		if (!$body['ok']) {
			return new \WP_Error('sfs_bad_request', $body['error'], array('status' => 400));
		}
		Schema::ensure();
		if (!Lock::acquire('sync', self::lock_wait())) {
			return new \WP_Error('sfs_busy', 'Another sync is still running. Try again in a minute.', array('status' => 409));
		}
		try {
			$run = new self(Settings::dry_run(), $now, '' !== $body['run_id'] ? $body['run_id'] : 'srv_' . wp_generate_password(12, false));
			$results = array();
			foreach ($body['records'] as $record) {
				$results[] = $run->record($record);
			}
			foreach ($run->relink() as $result) {
				$results[] = $result;
			}
			$carrier_outcomes = array();
			foreach ($body['rejected'] as $rejected) {
				$run->counts['error']++;
				if ('record' === $rejected['type']) {
					$results[] = array('item_id' => $rejected['item_id'], 'outcome' => 'error', 'order_number' => '', 'message' => 'Rejected: ' . $rejected['message']);
				} else {
					$carrier_outcomes[] = array('tracking_number' => $rejected['tracking_number'], 'outcome' => 'error', 'status' => '', 'message' => 'Rejected: ' . $rejected['message']);
				}
			}
			foreach ($body['results'] as $result) {
				$carrier_outcomes[] = $run->carrier($result);
			}
			$checks = $run->checks();
			$run->finish($body['client'], count($body['records']), count($body['results']), count($checks));
			if (!get_transient('sfs_tracking_pruned')) {
				Inbox::prune();
				set_transient('sfs_tracking_pruned', 1, DAY_IN_SECONDS);
			}
			return array(
				'results' => $results,
				'carrier_outcomes' => $carrier_outcomes,
				'checks' => $checks,
				'server_time' => $now,
				'dry_run' => $run->dry,
				'settings' => array(
					'interval_min' => (int) Settings::get('interval_min'),
					'lookback_days' => (int) Settings::get('lookback_days'),
					'dry_run' => $run->dry,
					'max_checks_per_run' => (int) Settings::get('max_checks_per_run'),
				),
			);
		} finally {
			Lock::release('sync');
		}
	}

	/**
	 * "Link now" on the order screen and review-queue confirmations: processes the stored records of this order's
	 * B2Sign order numbers (and the given items) right away, with the same rules, lock and dry-run as a sync.
	 *
	 * @param int[] $item_ids
	 * @return array|\WP_Error
	 */
	public static function link_order(int $order_id, array $item_ids = array()) {
		Schema::ensure();
		if (!Lock::acquire('sync', self::lock_wait())) {
			return new \WP_Error('sfs_busy', 'A sync is running. Try again in a minute.', array('status' => 409));
		}
		try {
			$run = new self(Settings::dry_run(), time(), 'staff_' . wp_generate_password(10, false));
			$results = array();
			foreach (Inbox::records_for(Orders::b2_numbers($order_id), $item_ids) as $stored) {
				list($record) = RecordValidator::record($stored);
				if (null !== $record) {
					$results[] = $run->record($record);
				}
			}
			if ($results) {
				$run->finish(array('version' => '', 'env' => 'staff'), count($results), 0, 0);
			}
			return $results;
		} finally {
			Lock::release('sync');
		}
	}

	/**
	 * Links one B2Sign item, or queues it for review.
	 *
	 * Exact signals: a staff confirmation in the review queue; the item already linked to an order; its B2Sign
	 * order number typed on an order; our order number in its job name. One order → link. Several → review.
	 * Otherwise fuzzy candidates go to review (applied only with "auto-apply confident fuzzy matches").
	 */
	public function record(array $record): array {
		$item_id = (int) $record['item_id'];
		$this->seen_items[$item_id] = true;
		if (Inbox::is_ignored($item_id)) {
			$this->counts['ignored']++;
			return array('item_id' => $item_id, 'outcome' => 'ignored', 'order_number' => '', 'message' => 'Marked not ours by staff.');
		}

		$confirmed = Inbox::confirmed_order($item_id);
		if ('' !== $confirmed) {
			$order_id = Orders::find_by_number($confirmed);
			if ($order_id) {
				return $this->link($order_id, $record, 'confirmed by staff');
			}
		}

		$reasons = array();
		foreach (Orders::by_item($item_id) as $order_id) {
			$reasons[$order_id] = 'already linked';
		}
		foreach (Orders::by_b2_order($record['order_number']) as $order_id) {
			$reasons[$order_id] = $reasons[$order_id] ?? 'B2Sign order number on the order';
		}
		foreach (OrderNumberExtractor::from_text($record['jobname']) as $token) {
			foreach (Orders::ids_by_number($token) as $order_id) {
				$reasons[$order_id] = $reasons[$order_id] ?? 'order number in the job name';
			}
		}
		foreach (array_keys($reasons) as $order_id) {
			if (in_array($item_id, Orders::excluded($order_id), true)) {
				unset($reasons[$order_id]);
			}
		}

		if (1 === count($reasons)) {
			return $this->link((int) key($reasons), $record, (string) current($reasons));
		}
		if (count($reasons) > 1) {
			$candidates = array();
			foreach ($reasons as $order_id => $why) {
				$candidates[] = array('order_id' => $order_id, 'order_number' => Orders::number($order_id), 'score' => 100, 'parts' => array(), 'reason' => $why);
			}
			return $this->queue($record, 'needs_review', $candidates, 'Several orders match: #' . implode(', #', array_column($candidates, 'order_number')) . '. Confirm the right one.');
		}

		$purchase = $this->purchase_time($record);
		$ranked = array_values(array_filter(Matcher::rank($record, Orders::candidates($purchase), $purchase), static function ($candidate) use ($item_id) {
			return !in_array($item_id, Orders::excluded((int) $candidate['order_id']), true);
		}));
		if ($ranked && Settings::get('auto_apply_fuzzy') && Matcher::confident($ranked)) {
			return $this->link((int) $ranked[0]['order_id'], $record, 'confident fuzzy match (score ' . $ranked[0]['score'] . ')');
		}
		if ($ranked) {
			return $this->queue($record, 'needs_review', $ranked, 'Possible match. Confirm the order or mark it not ours.');
		}
		return $this->queue($record, 'unmatched', array(), 'No matching order. Type the B2Sign order number on our order, or mark it not ours.');
	}

	/**
	 * Items in the review queue whose B2Sign order number staff typed on an order since, and items staff confirmed
	 * while dry-run was on.
	 */
	public function relink(): array {
		$results = array();
		foreach (Inbox::relinkable() as $item_id => $stored) {
			if (isset($this->seen_items[$item_id])) {
				continue;
			}
			list($record) = RecordValidator::record($stored);
			if (null === $record) {
				continue;
			}
			$result = $this->record($record);
			$result['from_inbox'] = true;
			$results[] = $result;
		}
		return $results;
	}

	/**
	 * Merges one carrier reading into the package it belongs to. Readings for a number no order holds are discarded.
	 */
	public function carrier(array $result): array {
		$carrier = $result['carrier'];
		$number = $result['tracking_number'];
		$this->carriers[$carrier][] = $result['ok'] ? 'ok' : (string) $result['error']['code'];
		$out = array('tracking_number' => $number, 'outcome' => 'discarded', 'status' => '', 'message' => 'Not a package on any order.');
		$key = ShipmentMerger::key($carrier, $number);
		$minutes = Settings::check_minutes();

		foreach (Orders::by_tracking($carrier, $number) as $order_id) {
			$shipments = Orders::shipments($order_id);
			if (!isset($shipments[$key])) {
				continue;
			}
			$shipment = $shipments[$key];
			if ($result['ok']) {
				$reading = CarrierReading::build($result, (string) $shipment['status'], $this->now);
				$next = CheckScheduler::next($reading['status'], $this->now, $minutes);
				$applied = ShipmentMerger::apply_reading($shipment, $reading, $result['checked_at'], $this->now, $next);
				foreach ($reading['unknown'] as $text) {
					Log::wording($carrier, $text);
				}
			} else {
				$code = (string) $result['error']['code'];
				$next = 'blocked' === $code
					? CheckScheduler::next((string) $shipment['status'], $this->now, $minutes)
					: CheckScheduler::after_error((int) $shipment['check_errors'] + 1, (string) $shipment['status'], $this->now, $minutes);
				$applied = ShipmentMerger::apply_error($shipment, $code, (string) $result['error']['message'], $result['checked_at'], $next);
				$applied['from'] = $applied['to'] = (string) $shipment['status'];
			}

			$out['status'] = (string) $applied['shipment']['status'];
			if (!$applied['changed']) {
				$out['outcome'] = 'unchanged';
				$out['message'] = 'Already have this reading.';
				continue;
			}
			$out['outcome'] = $result['ok'] ? 'updated' : 'error';
			$out['message'] = $result['ok'] ? ($applied['from'] !== $applied['to'] ? 'Status ' . $applied['from'] . ' → ' . $applied['to'] . '.' : 'Status unchanged.') : 'Check failed: ' . $result['error']['code'] . '.';
			if ($this->dry) {
				$out['message'] = 'Dry run — nothing saved. ' . $out['message'];
				continue;
			}

			$number_ours = Orders::number($order_id);
			$shipment = $this->notify($order_id, $key, $applied['shipment'], $applied['from'], $applied['to'], $shipments);
			$shipments[$key] = $shipment;
			Orders::save_shipments($order_id, $shipments);
			if ($applied['from'] !== $applied['to']) {
				Log::add('action', 'Order #' . $number_ours . ' package ' . Log::mask($number) . ': ' . $applied['from'] . ' → ' . $applied['to'], array('order' => $number_ours, 'tracking' => Log::mask($number), 'from' => $applied['from'], 'to' => $applied['to']), $this->run_id);
			}
			if (!$result['ok'] && (int) $shipment['check_errors'] === CheckScheduler::MAX_ERRORS) {
				Log::add('flag', 'Order #' . $number_ours . ' package ' . Log::mask($number) . ': checks stopped after ' . CheckScheduler::MAX_ERRORS . ' failed readings', array('order' => $number_ours, 'tracking' => Log::mask($number)), $this->run_id, 'warning');
			}
		}
		return $out;
	}

	/**
	 * Carrier checks for the extension's next run: fresh readings asked for first, then the most overdue.
	 */
	public function checks(): array {
		$items = array();
		$idle = array();
		foreach (Orders::due($this->now, 200) as $order_id => $shipments) {
			$active = false;
			foreach ($shipments as $key => $shipment) {
				if (null !== CheckScheduler::due_at($shipment, $this->now)) {
					$active = true;
				}
				$items[] = array('shipment' => $shipment, 'order_id' => $order_id, 'key' => $key);
			}
			if (!$active) {
				$idle[$order_id] = $shipments;
			}
		}
		// Orders whose packages all stopped keep no due index (writes nothing else).
		if (!$this->dry) {
			foreach ($idle as $order_id => $shipments) {
				Orders::save_shipments($order_id, $shipments);
			}
		}
		$checks = array();
		foreach (CheckScheduler::select($items, $this->now, (int) Settings::get('max_checks_per_run')) as $picked) {
			$shipment = $picked['shipment'];
			$id = $shipment['carrier'] . ':' . $shipment['tracking_number'];
			if (isset($checks[$id])) {
				continue;
			}
			$checks[$id] = array(
				'carrier' => $shipment['carrier'],
				'tracking_number' => $shipment['tracking_number'],
				'url' => TrackingUrls::check_url($shipment['carrier'], $shipment['tracking_number']),
				'reason' => $picked['reason'],
			);
		}
		return array_values($checks);
	}

	private function finish(array $client, int $records, int $results, int $checks): void {
		Log::add(
			'run',
			sprintf('%s: %d records, %d carrier results, %d checks due', $this->dry ? 'Dry-run sync' : 'Sync', $records, $results, $checks),
			array('client' => $client, 'dry_run' => $this->dry, 'counts' => $this->counts, 'carriers' => $this->carriers, 'checks' => $checks),
			$this->run_id
		);
	}

	private function link(int $order_id, array $record, string $reason): array {
		$item_id = (int) $record['item_id'];
		$number = Orders::number($order_id);
		$status = (string) get_post_status($order_id);
		$merge = ShipmentMerger::apply_b2_record(Orders::shipments($order_id), $record, $this->now);
		$items = Orders::items($order_id);
		$entry = array(
			'item_id' => $item_id,
			'b2_order' => $record['order_number'],
			'jobid' => $record['jobid'],
			'title' => $record['item_title'],
			'quantity' => $record['quantity'],
			'status' => $record['status'],
			'is_reprint' => $record['is_reprint'],
			'packages' => count($record['packages']),
			'updated' => $record['updated_at'],
		);
		$items_changed = ($items[$item_id] ?? null) !== $entry;
		$items[$item_id] = $entry;
		$is_new_link = !in_array($order_id, Orders::by_item($item_id), true);
		$plans = Effects::plan($order_id, $status, $merge['shipments'], $items, $merge['added']);

		$flags = array();
		if ('refunded' === $record['status']) {
			$flags[] = 'the supplier refunded this item';
		}
		if (in_array($status, Effects::CLOSED, true) && $record['packages']) {
			$flags[] = 'the order is ' . $status . ' but the item shipped';
		}

		$outcome = $is_new_link ? 'matched' : ($merge['changed'] || $items_changed ? 'updated' : 'unchanged');
		$this->counts[$outcome]++;
		$message = ucfirst($reason) . '.';
		if ($merge['added']) {
			$message .= ' ' . count($merge['added']) . (1 === count($merge['added']) ? ' new package.' : ' new packages.');
		}
		if ($plans) {
			$message .= ' ' . ($this->dry ? 'Would ' : 'Did: ') . Effects::describe($plans) . '.';
		}
		if ($flags) {
			$message .= ' Check: ' . implode('; ', $flags) . '.';
		}
		$result = array('item_id' => $item_id, 'outcome' => $outcome, 'order_number' => $number, 'message' => $message);
		if ($this->dry) {
			if ('unchanged' !== $outcome) {
				$result['message'] = 'Dry run — nothing saved. ' . $message;
			}
			return $result;
		}

		if ($merge['changed']) {
			Orders::save_shipments($order_id, $merge['shipments']);
		}
		if ($items_changed) {
			Orders::save_items($order_id, $items);
		}
		if ($is_new_link) {
			Orders::link_item($order_id, $item_id);
		}
		if ($merge['added']) {
			Orders::add_activity($order_id, 'Tracking synced from supplier: ' . count($merge['added']) . (1 === count($merge['added']) ? ' package.' : ' packages.'));
		}
		Inbox::mark_linked($item_id, $number);
		if ($is_new_link || $merge['added']) {
			Log::add('action', 'Linked supplier item to order #' . $number, array('order' => $number, 'item' => $item_id, 'packages' => count($merge['added']), 'reason' => $reason), $this->run_id);
		}
		if ($flags && 'unchanged' !== $outcome) {
			Log::add('flag', 'Order #' . $number . ': ' . implode('; ', $flags), array('order' => $number, 'item' => $item_id), $this->run_id, 'warning');
		}
		if ($plans) {
			Effects::apply($order_id, $plans, $merge['shipments'], $merge['added'], $this->run_id);
		}
		return $result;
	}

	private function queue(array $record, string $state, array $candidates, string $message): array {
		Inbox::put($record, $state, $candidates, $message);
		$this->counts[$state]++;
		return array('item_id' => (int) $record['item_id'], 'outcome' => $state, 'order_number' => '', 'message' => $message);
	}

	/**
	 * Staff alert and the optional customer emails, once per package per state.
	 */
	private function notify(int $order_id, string $key, array $shipment, string $from, string $to, array $shipments): array {
		if ($from === $to) {
			return $shipment;
		}
		$notified = (array) ($shipment['notified'] ?? array());
		$position = (int) array_search($key, array_keys($shipments), true) + 1;
		$count = count($shipments);
		if (in_array($to, array('exception', 'returned'), true)) {
			if (Settings::get('staff_exception_email') && empty($notified['staff_' . $to]) && Emails::staff_exception($order_id, $shipment)) {
				$notified['staff_' . $to] = $this->now;
			}
			if (Settings::get('email_issue') && empty($notified['customer_' . $to]) && Emails::customer_issue($order_id, $shipment, $position, $count)) {
				$notified['customer_' . $to] = $this->now;
			}
		}
		if ('delivered' === $to && Settings::get('email_delivered') && empty($notified['customer_delivered']) && Emails::customer_delivered($order_id, $shipment, $position, $count)) {
			$notified['customer_delivered'] = $this->now;
		}
		$shipment['notified'] = $notified;
		return $shipment;
	}

	private function purchase_time(array $record): int {
		$local = '' === $record['purchased'] ? null : B2DateParser::machine($record['purchased']);
		$time = null === $local ? null : B2DateParser::to_utc($local);
		return $time ?? $this->now;
	}
}

<?php
/**
 * B2Sign date strings. B2Sign's server clock is America/Los_Angeles (props.time matched PDT on 2026-10-29),
 * whatever time zone the computer running the extension uses.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class B2DateParser {
	public const ZONE = 'America/Los_Angeles';

	private const MONTHS = array(
		'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
		'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
	);

	/**
	 * Machine forms: "10/28/2026 15:49:31" (purchased_string, updated_at) or "2026-10-30 17:30:00" (package scheduled).
	 *
	 * @return string|null "Y-m-d H:i:s" in B2Sign's local time.
	 */
	public static function machine(string $text): ?string {
		$text = trim($text);
		if (preg_match('/^([0-9]{1,2})\/([0-9]{1,2})\/([0-9]{4})(?:\s+([0-9]{1,2}):([0-9]{2})(?::([0-9]{2}))?)?$/', $text, $m)) {
			return self::compose((int) $m[3], (int) $m[1], (int) $m[2], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));
		}
		if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:[ T]([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?)?$/', $text, $m)) {
			return self::compose((int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));
		}
		return null;
	}

	/**
	 * Display forms: "Today, Oct 28", "Yesterday", "Oct 27", "Fri, Oct 30", "Oct 9 9:30pm", "Today, Oct 28 3:49pm",
	 * "May 15 2024 12:40pm". A date without a year takes the current Los Angeles year; for past dates ($kind 'past')
	 * a result more than 2 days ahead means last year (New Year), for estimates ($kind 'future') a result more than
	 * 30 days back means next year.
	 *
	 * @return string|null "Y-m-d" or "Y-m-d H:i:s" in B2Sign's local time.
	 */
	public static function loose(string $text, int $now, string $kind = 'past'): ?string {
		$text = trim((string) preg_replace('/\s+/', ' ', $text));
		if ('' === $text) {
			return null;
		}
		$today = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(self::ZONE));

		$hour = null;
		$minute = 0;
		if (preg_match('/^(.*?)[ ,]+([0-9]{1,2}):([0-9]{2}) ?([ap])\.?m\.?$/i', $text, $m)) {
			$text = trim($m[1], ' ,');
			$hour = ((int) $m[2]) % 12 + ('p' === strtolower($m[4]) ? 12 : 0);
			$minute = (int) $m[3];
		}

		$relative = null;
		if (preg_match('/^(today|yesterday)\b,? ?(.*)$/i', $text, $m)) {
			$relative = 'today' === strtolower($m[1]) ? $today : $today->modify('-1 day');
			$text = trim($m[2]);
		}

		if ('' === $text) {
			if (null === $relative) {
				return null;
			}
			$year = (int) $relative->format('Y');
			$month = (int) $relative->format('n');
			$day = (int) $relative->format('j');
		} else {
			$text = (string) preg_replace('/^(?:mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?,? /i', '', $text);
			if (!preg_match('/^([A-Za-z]{3,9})\.? ([0-9]{1,2})(?:,? ([0-9]{4}))?$/', $text, $m)) {
				return null;
			}
			$month = self::MONTHS[strtolower(substr($m[1], 0, 3))] ?? 0;
			$day = (int) $m[2];
			if (!$month) {
				return null;
			}
			if (!empty($m[3])) {
				$year = (int) $m[3];
			} else {
				$year = (int) $today->format('Y');
				$candidate = self::compose($year, $month, $day, 0, 0, 0);
				if (null === $candidate) {
					return null;
				}
				$at = new \DateTimeImmutable($candidate, new \DateTimeZone(self::ZONE));
				if ('future' === $kind && $at < $today->modify('-30 days')->setTime(0, 0)) {
					$year++;
				} elseif ('future' !== $kind && $at > $today->modify('+2 days')) {
					$year--;
				}
			}
		}

		if (null === $hour) {
			return self::compose($year, $month, $day, null, 0, 0);
		}
		return self::compose($year, $month, $day, $hour, $minute, 0);
	}

	/**
	 * Unix time of a B2Sign local "Y-m-d[ H:i[:s]]".
	 */
	public static function to_utc(string $local): ?int {
		try {
			return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
		} catch (\Exception $e) {
			return null;
		}
	}

	private static function compose(int $year, int $month, int $day, ?int $hour, int $minute, int $second): ?string {
		if (!checkdate($month, $day, $year) || $year < 2000 || $year > 2100) {
			return null;
		}
		if (null === $hour) {
			return sprintf('%04d-%02d-%02d', $year, $month, $day);
		}
		if ($hour > 23 || $minute > 59 || $second > 59) {
			return null;
		}
		return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
	}
}

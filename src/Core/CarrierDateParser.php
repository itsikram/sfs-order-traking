<?php
/**
 * Dates and times as UPS and FedEx print them on their tracking pages.
 *
 * Carrier scan times are local to the scan ("Local Time" / "Local Scan Time"), so they are kept as local strings;
 * a Unix time is only computed when the zone is actually known.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class CarrierDateParser {
	private const MONTHS = array(
		'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
		'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
	);

	private const WEEKDAYS = array('sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6);

	/**
	 * "11:50 A.M.", "8:28 P.M.", "3:44 PM", "5:48 pm", "12:00 AM", "15:44" → "H:i".
	 */
	public static function clock(string $text): ?string {
		$text = trim($text);
		if (preg_match('/^([0-9]{1,2}):([0-9]{2})\s*([AaPp])\.?\s*[Mm]\.?$/', $text, $m)) {
			$hour = (int) $m[1];
			$minute = (int) $m[2];
			if ($hour < 1 || $hour > 12 || $minute > 59) {
				return null;
			}
			return sprintf('%02d:%02d', $hour % 12 + ('p' === strtolower($m[3]) ? 12 : 0), $minute);
		}
		if (preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $text, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
			return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
		}
		return null;
	}

	/**
	 * "10/27/2026", "10/22/26", "Thursday, 10/22/26", "Thursday, 10/29/2026" → "Y-m-d".
	 */
	public static function date(string $text): ?string {
		if (!preg_match('/(?:^|[\s,])([0-9]{1,2})\/([0-9]{1,2})\/([0-9]{4}|[0-9]{2})(?![0-9])/', ' ' . trim($text), $m)) {
			return null;
		}
		$year = (int) $m[3];
		if ($year < 100) {
			$year += 2000;
		}
		$month = (int) $m[1];
		$day = (int) $m[2];
		return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
	}

	/**
	 * A scan's date and time cells → "Y-m-d H:i", or "Y-m-d" when the time is missing.
	 */
	public static function event(string $date_text, string $time_text): ?string {
		$date = self::date($date_text);
		if (null === $date) {
			return null;
		}
		$clock = '' === trim($time_text) ? null : self::clock($time_text);
		return null === $clock ? $date : $date . ' ' . $clock;
	}

	/**
	 * One string holding both: FedEx "10/26/2026 at 5:48 pm", "Monday 10/26/2026 at 5:48 pm", UPS "10/28/2026, 8:28 P.M.".
	 */
	public static function date_time(string $text): ?string {
		if (!preg_match('/([0-9]{1,2}\/[0-9]{1,2}\/(?:[0-9]{4}|[0-9]{2}))(?![0-9])(?:\s*(?:,|at)?\s*([0-9]{1,2}:[0-9]{2}\s*[AaPp]\.?\s*[Mm]\.?))?/', $text, $m)) {
			return null;
		}
		$date = self::date($m[1]);
		if (null === $date) {
			return null;
		}
		$clock = empty($m[2]) ? null : self::clock($m[2]);
		return null === $clock ? $date : $date . ' ' . $clock;
	}

	/**
	 * Unix time of a local "Y-m-d[ H:i]" in a known zone.
	 */
	public static function to_utc(string $local, string $zone): ?int {
		if ('' === $zone || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}(?: [0-9]{2}:[0-9]{2})?$/', $local)) {
			return null;
		}
		try {
			return (new \DateTimeImmutable($local, new \DateTimeZone($zone)))->getTimestamp();
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Local "Y-m-d H:i" of a Unix time in a zone.
	 */
	public static function local(int $time, string $zone): string {
		return (new \DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d H:i');
	}

	/**
	 * Delivery window wording, tidied: "11:00am-3:00pm" → "11:00 AM – 3:00 PM", "by End of Day" → "by end of day".
	 */
	public static function window(string $text): string {
		$text = trim((string) preg_replace('/\s+/', ' ', $text));
		if ('' === $text) {
			return '';
		}
		if (preg_match('/([0-9]{1,2}:[0-9]{2}\s*[AaPp]\.?\s*[Mm]\.?)\s*(?:-|–|to)\s*([0-9]{1,2}:[0-9]{2}\s*[AaPp]\.?\s*[Mm]\.?)/', $text, $m)) {
			$from = self::clock($m[1]);
			$to = self::clock($m[2]);
			if (null !== $from && null !== $to) {
				return self::twelve($from) . ' – ' . self::twelve($to);
			}
		}
		if (preg_match('/\bby\s+end\s+of\s+day\b/i', $text)) {
			return 'by end of day';
		}
		if (preg_match('/\bby\s+([0-9]{1,2}:[0-9]{2}\s*[AaPp]\.?\s*[Mm]\.?)/', $text, $m) && null !== ($by = self::clock($m[1]))) {
			return 'by ' . self::twelve($by);
		}
		return '';
	}

	/**
	 * A delivery estimate in free text: "Friday, 10/30/2026 by End of Day", "10/30/2026", "Estimated delivery Friday,
	 * October 30 by 7:00 P.M.", "Friday Between 11:00 AM - 3:00 PM", "Today by 7:00 P.M.", "Tomorrow".
	 * Relative words and bare weekdays count from today in $zone.
	 *
	 * @return array{date:string,window:string}|null
	 */
	public static function eta(string $text, int $now, string $zone): ?array {
		$text = trim((string) preg_replace('/\s+/', ' ', $text));
		if ('' === $text) {
			return null;
		}
		$today = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($zone))->setTime(0, 0);
		$date = self::date($text);
		if (null === $date && preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.? ([0-9]{1,2})(?:,? ([0-9]{4}))?\b/i', $text, $m)) {
			$month = self::MONTHS[strtolower($m[1])];
			$day = (int) $m[2];
			$year = empty($m[3]) ? (int) $today->format('Y') : (int) $m[3];
			if (checkdate($month, $day, $year)) {
				$candidate = $today->setDate($year, $month, $day);
				if (empty($m[3]) && $candidate < $today->modify('-30 days')) {
					$candidate = $candidate->modify('+1 year');
				}
				$date = $candidate->format('Y-m-d');
			}
		}
		if (null === $date && preg_match('/\btoday\b/i', $text)) {
			$date = $today->format('Y-m-d');
		}
		if (null === $date && preg_match('/\btomorrow\b/i', $text)) {
			$date = $today->modify('+1 day')->format('Y-m-d');
		}
		if (null === $date && preg_match('/\b(sun|mon|tue|wed|thu|fri|sat)(?:day|s|sday|nesday|rs|rsday|urday)?\b/i', $text, $m)) {
			$ahead = (self::WEEKDAYS[strtolower($m[1])] - (int) $today->format('w') + 7) % 7;
			$date = $today->modify('+' . $ahead . ' days')->format('Y-m-d');
		}
		if (null === $date) {
			return null;
		}
		return array('date' => $date, 'window' => self::window($text));
	}

	private static function twelve(string $clock): string {
		list($hour, $minute) = array_map('intval', explode(':', $clock));
		return sprintf('%d:%02d %s', 0 === $hour % 12 ? 12 : $hour % 12, $minute, $hour >= 12 ? 'PM' : 'AM');
	}
}

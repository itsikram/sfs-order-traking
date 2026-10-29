<?php
/**
 * Scores how likely a B2Sign item belongs to one of our orders when no order number links them.
 * Fuzzy scores only fill the review queue; they are applied automatically only when the owner turns on
 * "auto-apply confident fuzzy matches" (single candidate ≥ 90, runner-up < 60).
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class Matcher {
	/** Below this a candidate is not worth showing. */
	public const REVIEW_MIN = 40;
	public const CONFIDENT = 90;
	public const RUNNER_UP_MAX = 60;

	private const COMPANY_SUFFIXES = array('inc', 'llc', 'ltd', 'co', 'corp', 'corporation', 'company', 'the');

	/**
	 * Score out of 100: ZIP (required) 30, last name or company 30, first name 10, city and state 10,
	 * item title 10, purchase within 3 days of our order 10 (within 7 days: 5).
	 *
	 * @param array $record Validated ShipmentRecord.
	 * @param array $candidate {order_id, order_number, created, ship:{fname,lname,company,city,state,zip}, bill:{...}, titles:string[]}
	 * @return array{score:int,parts:array<string,int>}
	 */
	public static function score(array $record, array $candidate, int $purchase_time): array {
		$parts = array('zip' => 0, 'name' => 0, 'first' => 0, 'place' => 0, 'item' => 0, 'date' => 0);
		$to = (array) $record['ship_to'];
		$zip = self::zip((string) ($to['zipcode'] ?? ''));
		$addresses = array_values(array_filter(array((array) ($candidate['ship'] ?? array()), (array) ($candidate['bill'] ?? array()))));

		$matched = null;
		foreach ($addresses as $address) {
			if ('' !== $zip && self::zip((string) ($address['zip'] ?? '')) === $zip) {
				$matched = $address;
				break;
			}
		}
		if (null === $matched) {
			return array('score' => 0, 'parts' => $parts);
		}
		$parts['zip'] = 30;

		$name = self::name_parts((string) ($to['fullname'] ?? ''));
		$company = self::company((string) ($to['company'] ?? ''));
		foreach ($addresses as $address) {
			if ('' !== $name['last'] && $name['last'] === Text::phrase((string) ($address['lname'] ?? ''))) {
				$parts['name'] = 30;
			} elseif (0 === $parts['name'] && self::same_company($company, self::company((string) ($address['company'] ?? '')))) {
				$parts['name'] = 30;
			}
			if ('' !== $name['first'] && $name['first'] === Text::phrase((string) ($address['fname'] ?? ''))) {
				$parts['first'] = 10;
			}
		}

		$city = Text::phrase((string) ($to['city'] ?? ''));
		$state = strtolower(trim((string) ($to['state'] ?? '')));
		if ('' !== $city && $city === Text::phrase((string) ($matched['city'] ?? '')) && '' !== $state && $state === strtolower(trim((string) ($matched['state'] ?? '')))) {
			$parts['place'] = 10;
		}

		if (self::titles_match((string) ($record['item_title'] ?? ''), (array) ($candidate['titles'] ?? array()))) {
			$parts['item'] = 10;
		}

		$days = ($purchase_time - (int) ($candidate['created'] ?? 0)) / 86400;
		if ($days >= -1 && $days <= 3) {
			$parts['date'] = 10;
		} elseif ($days >= -1 && $days <= 7) {
			$parts['date'] = 5;
		}

		return array('score' => array_sum($parts), 'parts' => $parts);
	}

	/**
	 * The best candidates (score ≥ REVIEW_MIN), highest first.
	 *
	 * @return array<int,array{order_id:int,order_number:string,score:int,parts:array}>
	 */
	public static function rank(array $record, array $candidates, int $purchase_time, int $limit = 3): array {
		$ranked = array();
		foreach ($candidates as $candidate) {
			$scored = self::score($record, $candidate, $purchase_time);
			if ($scored['score'] >= self::REVIEW_MIN) {
				$ranked[] = array(
					'order_id' => (int) $candidate['order_id'],
					'order_number' => (string) $candidate['order_number'],
					'score' => $scored['score'],
					'parts' => $scored['parts'],
				);
			}
		}
		usort($ranked, static function ($a, $b) {
			return $b['score'] <=> $a['score'] ?: $a['order_id'] <=> $b['order_id'];
		});
		return array_slice($ranked, 0, $limit);
	}

	/**
	 * One clear winner: ≥ 90 with the runner-up below 60.
	 */
	public static function confident(array $ranked): bool {
		$first = (int) ($ranked[0]['score'] ?? 0);
		$second = (int) ($ranked[1]['score'] ?? 0);
		return $first >= self::CONFIDENT && $second < self::RUNNER_UP_MAX;
	}

	private static function zip(string $zip): string {
		return preg_match('/^\s*([0-9]{5})/', $zip, $m) ? $m[1] : '';
	}

	/**
	 * @return array{first:string,last:string}
	 */
	private static function name_parts(string $name): array {
		$words = array_values(array_filter(explode(' ', Text::phrase((string) preg_replace('/[^\p{L}\p{N}\' -]+/u', ' ', $name)))));
		return array('first' => $words[0] ?? '', 'last' => count($words) > 1 ? (string) end($words) : '');
	}

	private static function company(string $company): string {
		$words = array_filter(explode(' ', Text::phrase((string) preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $company))), static function ($word) {
			return '' !== $word && !in_array($word, self::COMPANY_SUFFIXES, true);
		});
		return implode(' ', $words);
	}

	private static function same_company(string $a, string $b): bool {
		if ('' === $a || '' === $b) {
			return false;
		}
		if ($a === $b) {
			return true;
		}
		return min(strlen($a), strlen($b)) >= 5 && (false !== strpos($a, $b) || false !== strpos($b, $a));
	}

	private static function titles_match(string $title, array $titles): bool {
		$words = self::stems(Text::words($title));
		if (!$words) {
			return false;
		}
		foreach ($titles as $ours) {
			if (array_intersect($words, self::stems(Text::words((string) $ours)))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string[] $words
	 * @return string[]
	 */
	private static function stems(array $words): array {
		$stems = array();
		foreach ($words as $word) {
			$word = (string) preg_replace('/(?<=[a-z]{3})s$/', '', $word);
			if (strlen($word) >= 4 && !ctype_digit($word)) {
				$stems[] = $word;
			}
		}
		return array_values(array_unique($stems));
	}
}

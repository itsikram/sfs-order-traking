<?php
/**
 * Finds order numbers in free text.
 *
 * Our order numbers: "order_" + 10 characters [A-Z0-9] (customers see "SN6LTHF5IA"); orders from before that
 * format use a 13-character lowercase hex id with no prefix. B2Sign order numbers are a letter and 7 digits
 * ("B2612345"); B2Sign job ids add "-NN" for the line ("b2612345-01").
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class OrderNumberExtractor {
	public const MAX_TOKENS = 5;
	public const MAX_B2_NUMBERS = 20;

	/**
	 * Our order numbers a B2Sign job name may mention: "SN6LTHF5IA", "#sn6lthf5ia", "order_SN6LTHF5IA", "Order SN6LTHF5IA".
	 * Candidates only: each must still be confirmed against the orders table.
	 *
	 * @return string[] Uppercase, unique, at most MAX_TOKENS.
	 */
	public static function from_text(string $text): array {
		$text = (string) preg_replace('/\border[\s_#:-]*/i', ' ', $text);
		$text = str_replace('#', ' ', $text);
		preg_match_all('/(?<![A-Za-z0-9])([A-Za-z0-9]{10}|[0-9A-Fa-f]{13})(?![A-Za-z0-9])/', $text, $matches);
		$tokens = array();
		foreach ($matches[1] as $token) {
			$token = strtoupper($token);
			// All digits: phone numbers and the like, never one of our random ids in practice.
			if (ctype_digit($token)) {
				continue;
			}
			$tokens[$token] = true;
			if (count($tokens) >= self::MAX_TOKENS) {
				break;
			}
		}
		return array_keys($tokens);
	}

	/**
	 * B2Sign order numbers in text typed by staff: "B2612345, b2612346-01 #B2612347" → B2612345, B2612346, B2612347.
	 *
	 * @return string[]
	 */
	public static function b2_numbers(string $text): array {
		preg_match_all('/(?<![A-Za-z0-9])([A-Za-z][0-9]{7})(?:-[0-9]{1,3})?(?![A-Za-z0-9])/', $text, $matches);
		$numbers = array();
		foreach ($matches[1] as $number) {
			$numbers[strtoupper($number)] = true;
			if (count($numbers) >= self::MAX_B2_NUMBERS) {
				break;
			}
		}
		return array_keys($numbers);
	}

	public static function is_b2_number(string $value): bool {
		return 1 === preg_match('/^[A-Z][0-9]{7}$/', $value);
	}

	/**
	 * Stored order_id meta values that mean this customer-facing number (current and legacy forms).
	 *
	 * @return string[]
	 */
	public static function order_id_values(string $number): array {
		$number = strtoupper($number);
		return array_values(array_unique(array('order_' . $number, strtolower($number), $number)));
	}
}

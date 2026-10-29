<?php
/**
 * Text helpers shared by the validators and normalizers. No WordPress calls.
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Core;

final class Text {
	/**
	 * One line of plain text: control characters and runs of whitespace become one space, then trimmed and cut to $max characters.
	 *
	 * @param mixed $value
	 */
	public static function line($value, int $max): string {
		if (!is_scalar($value) || is_bool($value)) {
			return '';
		}
		$text = (string) $value;
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		}
		$text = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Z}\s]+/u', ' ', $text));
		if (mb_strlen($text) > $max) {
			$text = rtrim(mb_substr($text, 0, $max));
		}
		return $text;
	}

	/**
	 * Comparable form of a carrier phrase: lowercase, single spaces, no surrounding punctuation,
	 * trademark signs dropped and curly quotes straightened.
	 */
	public static function phrase(string $text): string {
		$text = str_replace(array("\u{2019}", "\u{2018}", "\u{2122}", "\u{00AE}"), array("'", "'", '', ''), $text);
		$text = mb_strtolower(trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $text)));
		return trim($text, " .!:;,\t");
	}

	/**
	 * Words of a phrase for loose comparisons (product titles), without very short words.
	 *
	 * @return string[]
	 */
	public static function words(string $text): array {
		$words = preg_split('/[^a-z0-9]+/', self::phrase($text)) ?: array();
		return array_values(array_unique(array_filter($words, static function ($word) {
			return strlen($word) > 2;
		})));
	}
}

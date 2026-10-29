<?php
/**
 * A tiny assertion base class so the tests run with plain PHP (Composer and PHPUnit are not installed here).
 *
 * @package sfs-order-tracking
 */

declare(strict_types=1);

namespace SFS\OrderTracking\Tests;

class AssertionFailed extends \Exception {
}

abstract class TestCase {
	/** @var int */
	public $assertions = 0;

	public function setUp(): void {
	}

	public function tearDown(): void {
	}

	protected function fail(string $message): void {
		throw new AssertionFailed($message);
	}

	/**
	 * @param mixed $expected
	 * @param mixed $actual
	 */
	protected function assertSame($expected, $actual, string $message = ''): void {
		$this->assertions++;
		if ($expected !== $actual) {
			$this->fail(($message ? $message . "\n" : '') . 'Expected ' . self::export($expected) . "\n     got " . self::export($actual));
		}
	}

	/**
	 * @param mixed $actual
	 */
	protected function assertTrue($actual, string $message = ''): void {
		$this->assertSame(true, $actual, $message);
	}

	/**
	 * @param mixed $actual
	 */
	protected function assertFalse($actual, string $message = ''): void {
		$this->assertSame(false, $actual, $message);
	}

	/**
	 * @param mixed $actual
	 */
	protected function assertNull($actual, string $message = ''): void {
		$this->assertSame(null, $actual, $message);
	}

	/**
	 * @param mixed $actual
	 */
	protected function assertNotNull($actual, string $message = ''): void {
		$this->assertions++;
		if (null === $actual) {
			$this->fail($message ?: 'Expected a value, got null');
		}
	}

	protected function assertCount(int $expected, array $actual, string $message = ''): void {
		$this->assertSame($expected, count($actual), $message ?: 'Count');
	}

	/**
	 * @param mixed $needle
	 */
	protected function assertContains($needle, array $haystack, string $message = ''): void {
		$this->assertions++;
		if (!in_array($needle, $haystack, true)) {
			$this->fail(($message ? $message . "\n" : '') . self::export($needle) . ' not in ' . self::export($haystack));
		}
	}

	protected function assertStringContains(string $needle, string $haystack, string $message = ''): void {
		$this->assertions++;
		if (false === strpos($haystack, $needle)) {
			$readable = trim((string) preg_replace('/\s+/', ' ', strip_tags($haystack)));
			$this->fail(($message ? $message . "\n" : '') . self::export($needle) . ' not found in ' . self::export(substr($readable, 0, 1500)));
		}
	}

	protected function assertStringNotContains(string $needle, string $haystack, string $message = ''): void {
		$this->assertions++;
		if (false !== strpos($haystack, $needle)) {
			$this->fail(($message ? $message . "\n" : '') . self::export($needle) . ' must not appear in ' . self::export(substr($haystack, 0, 500)));
		}
	}

	protected function assertGreaterOrEqual(int $min, int $actual, string $message = ''): void {
		$this->assertions++;
		if ($actual < $min) {
			$this->fail(($message ? $message . "\n" : '') . "Expected >= {$min}, got {$actual}");
		}
	}

	protected function assertBetween(int $min, int $max, int $actual, string $message = ''): void {
		$this->assertions++;
		if ($actual < $min || $actual > $max) {
			$this->fail(($message ? $message . "\n" : '') . "Expected {$min}..{$max}, got {$actual}");
		}
	}

	/**
	 * @param mixed $value
	 */
	protected static function export($value): string {
		return str_replace("\n", "\n     ", var_export($value, true));
	}
}

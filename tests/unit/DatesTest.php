<?php
declare(strict_types=1);

namespace SFS\OrderTracking\Tests\Unit;

use SFS\OrderTracking\Core\B2DateParser;
use SFS\OrderTracking\Core\CarrierDateParser;
use SFS\OrderTracking\Tests\TestCase;

final class DatesTest extends TestCase {
	private static function la(string $local): int {
		return (new \DateTimeImmutable($local, new \DateTimeZone('America/Los_Angeles')))->getTimestamp();
	}

	public function test_b2sign_machine_dates(): void {
		$this->assertSame('2026-10-28 15:49:31', B2DateParser::machine('10/28/2026 15:49:31'));
		$this->assertSame('2026-10-30 17:30:00', B2DateParser::machine('2026-10-30 17:30:00'));
		$this->assertSame('2021-02-05 10:51:33', B2DateParser::machine('02/05/2021 10:51:33'));
		$this->assertSame('2026-10-30 00:00:00', B2DateParser::machine('2026-10-30'));
		$this->assertNull(B2DateParser::machine('13/40/2026 10:00:00'));
		$this->assertNull(B2DateParser::machine('Oct 28'));
		$this->assertNull(B2DateParser::machine(''));
	}

	public function test_b2sign_display_dates(): void {
		$now = self::la('2026-10-29 10:00');
		$this->assertSame('2026-10-28', B2DateParser::loose('Today, Oct 28', $now), 'the printed date wins over "Today"');
		$this->assertSame('2026-10-29', B2DateParser::loose('Today', $now));
		$this->assertSame('2026-10-28', B2DateParser::loose('Yesterday', $now));
		$this->assertSame('2026-10-27', B2DateParser::loose('Oct 27', $now));
		$this->assertSame('2026-10-30', B2DateParser::loose('Fri, Oct 30', $now, 'future'));
		$this->assertSame('2026-10-30', B2DateParser::loose('Fri, Oct 30', $now), 'one day ahead is still this year');
		$this->assertSame('2026-10-09 21:30:00', B2DateParser::loose('Oct 9 9:30pm', $now));
		$this->assertSame('2026-10-28 15:49:00', B2DateParser::loose('Today, Oct 28 3:49pm', $now));
		$this->assertSame('2026-10-09 00:15:00', B2DateParser::loose('Oct 9 12:15am', $now));
		$this->assertSame('2026-10-09 12:15:00', B2DateParser::loose('Oct 9 12:15pm', $now));
		$this->assertSame('2024-05-15 12:40:00', B2DateParser::loose('May 15 2024 12:40pm', $now), 'explicit year');
		$this->assertSame('2021-02-05 10:51:00', B2DateParser::loose('Feb 5 2021 10:51am', $now));
		$this->assertSame('2026-09-30', B2DateParser::loose('September 30', $now));
		$this->assertNull(B2DateParser::loose('Smarch 5', $now));
		$this->assertNull(B2DateParser::loose('Feb 30', $now));
		$this->assertNull(B2DateParser::loose('', $now));
		$this->assertNull(B2DateParser::loose('soon', $now));
	}

	public function test_b2sign_new_year_crossover(): void {
		$jan2 = self::la('2027-01-02 09:00');
		$this->assertSame('2026-12-30', B2DateParser::loose('Dec 30', $jan2), 'December seen in January is last year');
		$this->assertSame('2027-01-01', B2DateParser::loose('Jan 1', $jan2));
		$dec30 = self::la('2026-12-30 09:00');
		$this->assertSame('2027-01-03', B2DateParser::loose('Jan 3', $dec30, 'future'), 'an estimate in early January is next year');
		$this->assertSame('2026-01-03', B2DateParser::loose('Jan 3', $dec30, 'past'));
		$this->assertSame('2026-12-31', B2DateParser::loose('Dec 31', $dec30, 'past'), 'tomorrow stays this year');
		$this->assertSame('2026-12-28', B2DateParser::loose('Yesterday', self::la('2026-12-29 00:30')));
		$this->assertSame(self::la('2026-10-28 15:49:31'), B2DateParser::to_utc('2026-10-28 15:49:31'));
	}

	public function test_carrier_clock_and_dates(): void {
		$this->assertSame('11:50', CarrierDateParser::clock('11:50 A.M.'));
		$this->assertSame('20:28', CarrierDateParser::clock('8:28 P.M.'));
		$this->assertSame('15:44', CarrierDateParser::clock('3:44 PM'));
		$this->assertSame('17:48', CarrierDateParser::clock('5:48 pm'));
		$this->assertSame('00:00', CarrierDateParser::clock('12:00 AM'));
		$this->assertSame('12:30', CarrierDateParser::clock('12:30 PM'));
		$this->assertSame('15:44', CarrierDateParser::clock('15:44'));
		$this->assertNull(CarrierDateParser::clock('13:00 PM'));
		$this->assertNull(CarrierDateParser::clock(''));

		$this->assertSame('2026-10-27', CarrierDateParser::date('10/27/2026'));
		$this->assertSame('2026-10-22', CarrierDateParser::date('Thursday, 10/22/26'));
		$this->assertSame('2026-10-29', CarrierDateParser::date('Thursday, 10/29/2026'));
		$this->assertNull(CarrierDateParser::date('02/30/2026'));
		$this->assertNull(CarrierDateParser::date('Tuesday'));

		$this->assertSame('2026-10-28 20:28', CarrierDateParser::event('10/28/2026', '8:28 P.M.'));
		$this->assertSame('2026-10-28 00:00', CarrierDateParser::event('Wednesday, 10/28/26', '12:00 AM'));
		$this->assertSame('2026-10-28', CarrierDateParser::event('10/28/2026', ''));
		$this->assertNull(CarrierDateParser::event('', '8:28 P.M.'));

		$this->assertSame('2026-10-26 17:48', CarrierDateParser::date_time('10/26/2026 at 5:48 pm'));
		$this->assertSame('2026-10-26 17:48', CarrierDateParser::date_time('Monday 10/26/2026 at 5:48 pm'));
		$this->assertSame('2026-10-28 20:28', CarrierDateParser::date_time('Anytown, PA, United States, 10/28/2026, 8:28 P.M.'));
		$this->assertSame('2026-10-26', CarrierDateParser::date_time('10/26/2026'));
		$this->assertNull(CarrierDateParser::date_time('Monday'));
	}

	public function test_zones(): void {
		$this->assertSame(strtotime('2026-10-29 00:28:00 UTC'), CarrierDateParser::to_utc('2026-10-28 20:28', 'America/New_York'));
		$this->assertNull(CarrierDateParser::to_utc('2026-10-28 20:28', ''));
		$this->assertNull(CarrierDateParser::to_utc('10/28/2026', 'America/New_York'));
		// The FedEx mobile list showed 4:54 AM in Dhaka for a 6:54 PM EDT scan.
		$utc = CarrierDateParser::to_utc('2026-10-29 04:54', 'Asia/Dhaka');
		$this->assertSame(strtotime('2026-10-28 22:54:00 UTC'), $utc);
		$this->assertSame('2026-10-28 15:54', CarrierDateParser::local((int) $utc, 'America/Los_Angeles'));
	}

	public function test_estimates_and_windows(): void {
		$thursday = self::la('2026-10-29 10:00');
		$this->assertSame('11:00 AM – 3:00 PM', CarrierDateParser::window('11:00am-3:00pm'));
		$this->assertSame('11:00 AM – 3:00 PM', CarrierDateParser::window('Between 11:00 AM - 3:00 PM'));
		$this->assertSame('by end of day', CarrierDateParser::window('by End of Day'));
		$this->assertSame('by 7:00 PM', CarrierDateParser::window('Friday, 10/30/2026 by 7:00 P.M.'));
		$this->assertSame('', CarrierDateParser::window(''));

		$this->assertSame(array('date' => '2026-10-30', 'window' => 'by end of day'), CarrierDateParser::eta('Friday, 10/30/2026 by End of Day', $thursday, 'America/Los_Angeles'));
		$this->assertSame(array('date' => '2026-10-30', 'window' => '11:00 AM – 3:00 PM'), CarrierDateParser::eta('Friday Between 11:00 AM - 3:00 PM', $thursday, 'America/Los_Angeles'));
		$this->assertSame(array('date' => '2026-10-29', 'window' => 'by 7:00 PM'), CarrierDateParser::eta('Today by 7:00 P.M.', $thursday, 'America/Los_Angeles'));
		$this->assertSame(array('date' => '2026-10-30', 'window' => ''), CarrierDateParser::eta('Tomorrow', $thursday, 'America/Los_Angeles'));
		$this->assertSame(array('date' => '2026-11-02', 'window' => ''), CarrierDateParser::eta('Estimated delivery Monday, November 2', $thursday, 'America/Los_Angeles'));
		$this->assertSame(array('date' => '2026-10-29', 'window' => ''), CarrierDateParser::eta('Arriving Thursday', $thursday, 'America/Los_Angeles'), 'a bare weekday that is today');
		$this->assertNull(CarrierDateParser::eta('Your package is still on the way and we are actively working to get you a new delivery date.', $thursday, 'America/Los_Angeles'));
		$this->assertNull(CarrierDateParser::eta('Please monitor your email', $thursday, 'America/Los_Angeles'), '"monitor" is not Monday');
		$this->assertNull(CarrierDateParser::eta('', $thursday, 'America/Los_Angeles'));
	}
}

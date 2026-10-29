<?php
declare(strict_types=1);

namespace SFS\OrderTracking\Tests\Unit;

use SFS\OrderTracking\Core\OrderNumberExtractor;
use SFS\OrderTracking\Core\TrackingUrls;
use SFS\OrderTracking\Tests\TestCase;

final class ExtractorTest extends TestCase {
	public function test_our_order_numbers_in_job_names(): void {
		$this->assertSame(array('SN6LTHF5IA'), OrderNumberExtractor::from_text('SN6LTHF5IA'));
		$this->assertSame(array('SN6LTHF5IA'), OrderNumberExtractor::from_text('Job for #sn6lthf5ia - 2 banners'));
		$this->assertSame(array('SN6LTHF5IA'), OrderNumberExtractor::from_text('order_SN6LTHF5IA'));
		$this->assertSame(array('SN6LTHF5IA', 'ABCDEFGH12'), OrderNumberExtractor::from_text('Order SN6LTHF5IA / ABCDEFGH12'));
		$this->assertSame(array('5F3A12345B6C7'), OrderNumberExtractor::from_text('Legacy 5f3a12345b6c7 reprint'), 'legacy 13-hex ids');
		$this->assertSame(array(), OrderNumberExtractor::from_text('Call 2065551234'), 'all digits are phone numbers');
		$this->assertSame(array(), OrderNumberExtractor::from_text('Reprint copy of B2612345-01 (Banner 24" x 36")'), 'B2Sign job ids are not ours');
		$this->assertSame(array(), OrderNumberExtractor::from_text('SN6LTHF5IA2'), '11 characters');
		$this->assertSame(array(), OrderNumberExtractor::from_text(''));
		$this->assertSame(array('STOREFRONT'), OrderNumberExtractor::from_text('STOREFRONT sign'), 'a candidate only; confirmed against orders later');
		$this->assertCount(5, OrderNumberExtractor::from_text('AAAAAAAAA1 AAAAAAAAA2 AAAAAAAAA3 AAAAAAAAA4 AAAAAAAAA5 AAAAAAAAA6'));
		$this->assertSame(array('SN6LTHF5IA'), OrderNumberExtractor::from_text('SN6LTHF5IA sn6lthf5ia'), 'unique');
	}

	public function test_b2sign_order_numbers_typed_by_staff(): void {
		$this->assertSame(array('B2612345', 'B2612346', 'B2612347'), OrderNumberExtractor::b2_numbers('B2612345, b2612346-01 #B2612347 x'));
		$this->assertSame(array(), OrderNumberExtractor::b2_numbers('AB2612345'));
		$this->assertSame(array(), OrderNumberExtractor::b2_numbers('B26123456'));
		$this->assertSame(array('B2612345'), OrderNumberExtractor::b2_numbers("B2612345\nB2612345"));
		$this->assertTrue(OrderNumberExtractor::is_b2_number('B2612345'));
		$this->assertFalse(OrderNumberExtractor::is_b2_number('b2612345'));
		$this->assertSame(array('order_SN6LTHF5IA', 'sn6lthf5ia', 'SN6LTHF5IA'), OrderNumberExtractor::order_id_values('sn6lthf5ia'));
	}

	public function test_tracking_numbers_and_links(): void {
		$this->assertTrue(TrackingUrls::valid_number('ups', '1Z999AA10123456784'));
		$this->assertFalse(TrackingUrls::valid_number('ups', '1Z209A8C030980946'));
		$this->assertTrue(TrackingUrls::valid_number('fedex', '123456789012'));
		$this->assertFalse(TrackingUrls::valid_number('fedex', '87930538218'));
		$this->assertFalse(TrackingUrls::valid_number('dhl', '123456789012'));
		$this->assertTrue(TrackingUrls::valid_number('usps', '9400100000000000000001'));
		$this->assertSame('1Z999AA10123456784', TrackingUrls::normalize_number('1z999 aa1-0123456784'));
		$this->assertSame('ups', TrackingUrls::guess_carrier('1Z999AA10123456784'));
		$this->assertSame('fedex', TrackingUrls::guess_carrier('123456789012'));
		$this->assertSame('', TrackingUrls::guess_carrier('hello'));

		$ups = '1Z999AA10123456784';
		$this->assertTrue(TrackingUrls::allowed('ups', $ups, 'https://wwwapps.ups.com/WebTracking/track?track=yes&trackNums=' . $ups . '&loc=en_US'));
		$this->assertTrue(TrackingUrls::allowed('ups', $ups, 'https://www.ups.com/track?track=yes&trackNums=' . $ups . '&loc=en_us&requester=ST/trackdetails'));
		$this->assertFalse(TrackingUrls::allowed('ups', $ups, 'http://wwwapps.ups.com/WebTracking/track?trackNums=' . $ups), 'https only');
		$this->assertFalse(TrackingUrls::allowed('ups', $ups, 'https://evil.example/?trackNums=' . $ups));
		$this->assertFalse(TrackingUrls::allowed('ups', $ups, 'https://www.ups.com.evil.example/track?trackNums=' . $ups));
		$this->assertFalse(TrackingUrls::allowed('ups', $ups, 'https://www.fedex.com/fedextrack/?trknbr=' . $ups), 'other carrier host');
		$this->assertFalse(TrackingUrls::allowed('ups', $ups, 'https://user:pass@www.ups.com/track?trackNums=' . $ups));
		$this->assertFalse(TrackingUrls::allowed('ups', $ups, 'https://www.ups.com:8443/track?trackNums=' . $ups));
		$this->assertTrue(TrackingUrls::allowed('fedex', '123456789012', 'https://www.fedex.com/apps/fedextrack/?tracknumbers=123456789012&language=en&cntry_code=us'));
		$this->assertFalse(TrackingUrls::allowed('fedex', '123456789012', 'https://www.fedex.com/fedextrack/?trknbr=999999999999'), 'names another number');
		$this->assertFalse(TrackingUrls::allowed('fedex', '123456789012', ''));
		$this->assertFalse(TrackingUrls::allowed('usps', '9400100000000000000001', 'https://tools.usps.com/go/TrackConfirmAction?tLabels=9400100000000000000001'), 'no USPS page checks');

		$this->assertSame('https://www.ups.com/track?track=yes&trackNums=' . $ups . '&loc=en_us&requester=ST/trackdetails', TrackingUrls::check_url('ups', $ups));
		$this->assertSame('https://www.fedex.com/fedextrack/?trknbr=123456789012', TrackingUrls::check_url('fedex', '123456789012'));
		$this->assertSame('', TrackingUrls::check_url('usps', '9400100000000000000001'));
		$this->assertStringContains('tools.usps.com', TrackingUrls::public_url('usps', '9400100000000000000001'));
	}
}

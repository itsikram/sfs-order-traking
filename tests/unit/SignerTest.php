<?php
declare(strict_types=1);

namespace SFS\OrderTracking\Tests\Unit;

use SFS\OrderTracking\Core\RequestSigner;
use SFS\OrderTracking\Core\TokenSigner;
use SFS\OrderTracking\Tests\TestCase;

final class SignerTest extends TestCase {
	private function vectors(): array {
		return json_decode((string) file_get_contents(dirname(__DIR__) . '/vectors/hmac.json'), true)['cases'];
	}

	public function test_shared_vectors_match_node_crypto(): void {
		foreach ($this->vectors() as $case) {
			$canonical = RequestSigner::canonical($case['timestamp'], $case['nonce'], $case['method'], $case['route'], $case['body']);
			$this->assertSame($case['canonical'], $canonical, $case['name']);
			$this->assertSame($case['signature'], RequestSigner::sign($case['secret'], $canonical), $case['name']);
		}
	}

	public function test_verify_accepts_valid_and_rejects_tampering(): void {
		$case = $this->vectors()[1];
		$now = (int) $case['timestamp'];
		$headers = array('key' => 'sfs_test', 'timestamp' => $case['timestamp'], 'nonce' => $case['nonce'], 'signature' => $case['signature']);
		$verify = static function (array $h, ?string $body = null, string $route = '/sfs-tracking/v1/sync', string $key = 'sfs_test', ?string $secret = null, int $at = 0) use ($case, $now) {
			return RequestSigner::verify($h, 'POST', $route, $body ?? $case['body'], $key, $secret ?? $case['secret'], $at ?: $now);
		};

		$this->assertSame('', $verify($headers));
		$this->assertSame('', $verify($headers, null, 'sfs-tracking/v1/sync/'), 'route form does not matter');
		$this->assertSame('', $verify(array('signature' => strtoupper($case['signature'])) + $headers), 'hex case does not matter');
		$this->assertSame('bad_signature', $verify($headers, $case['body'] . ' '));
		$this->assertSame('bad_signature', $verify($headers, null, '/sfs-tracking/v1/ping'));
		$this->assertSame('bad_signature', $verify($headers, null, '/sfs-tracking/v1/sync', 'sfs_test', 'other-secret-0123456789abcdef0123456789'));
		$this->assertSame('bad_key', $verify($headers, null, '/sfs-tracking/v1/sync', 'sfs_other'));
		$this->assertSame('bad_key', $verify($headers, null, '/sfs-tracking/v1/sync', ''));
		$this->assertSame('', $verify($headers, null, '/sfs-tracking/v1/sync', 'sfs_test', null, $now + 300));
		$this->assertSame('clock_skew', $verify($headers, null, '/sfs-tracking/v1/sync', 'sfs_test', null, $now + 301));
		$this->assertSame('clock_skew', $verify($headers, null, '/sfs-tracking/v1/sync', 'sfs_test', null, $now - 301));
		$this->assertSame('missing_headers', $verify(array('signature' => '') + $headers));
		$this->assertSame('missing_headers', $verify(array()));
		$this->assertSame('malformed', $verify(array('nonce' => 'short') + $headers));
		$this->assertSame('malformed', $verify(array('nonce' => 'has spaces in the nonce!!') + $headers));
		$this->assertSame('malformed', $verify(array('timestamp' => '17932320610000') + $headers));
		$this->assertSame('malformed', $verify(array('signature' => 'zz' . substr($case['signature'], 2)) + $headers));
	}

	public function test_tokens_round_trip_expire_and_resist_tampering(): void {
		$token = TokenSigner::issue('track-secret', 'SN6LTHF5IA', 2000000000);
		$ok = TokenSigner::verify('track-secret', $token, 1999999999);
		$this->assertTrue($ok['ok']);
		$this->assertSame('SN6LTHF5IA', $ok['order']);
		$this->assertSame(2000000000, $ok['expires']);

		$expired = TokenSigner::verify('track-secret', $token, 2000000001);
		$this->assertFalse($expired['ok']);
		$this->assertSame('expired', $expired['error']);

		list($payload, $mac) = explode('.', $token);
		$forged = rtrim(strtr(base64_encode('v1|ZZZZZZZZZZ|2000000000'), '+/', '-_'), '=') . '.' . $mac;
		$this->assertSame('invalid', TokenSigner::verify('track-secret', $forged, 1)['error']);
		$this->assertSame('invalid', TokenSigner::verify('other-secret', $token, 1)['error']);
		$this->assertSame('invalid', TokenSigner::verify('', $token, 1)['error']);
		$this->assertSame('invalid', TokenSigner::verify('track-secret', 'garbage', 1)['error']);
		$this->assertSame('invalid', TokenSigner::verify('track-secret', $payload . '.' . str_repeat('0', 64), 1)['error']);
	}
}

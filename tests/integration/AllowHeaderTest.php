<?php
declare(strict_types=1);

namespace SFS\OrderTracking\Tests\Integration;

require_once __DIR__ . '/IntegrationCase.php';

use SFS\OrderTracking\Auth;
use SFS\OrderTracking\Core\RequestSigner;

/**
 * WordPress core's rest_send_allow_header (a rest_post_dispatch filter that serve_request applies after every
 * request) runs each handler's permission_callback again to build the Allow header. rest_do_request skips that
 * filter, so these tests apply it the way a real HTTP request does.
 */
final class AllowHeaderTest extends IntegrationCase {
	public function setUp(): void {
		parent::setUp();
		Auth::clear_failures();
		delete_transient(Auth::REJECT);
	}

	public function test_the_allow_header_check_after_dispatch_is_not_a_replay(): void {
		for ($i = 0; $i <= Auth::FAIL_LIMIT; $i++) {
			list($status, $data, $response) = $this->served('GET', '/sfs-tracking/v1/ping');
			$this->assertSame(200, $status, 'accepted call ' . ($i + 1) . ': ' . wp_json_encode($data));
			$allow[] = $response->get_headers()['Allow'] ?? '';
		}
		$this->assertSame(array_fill(0, Auth::FAIL_LIMIT + 1, 'GET'), $allow, 'the second check agrees with the first');
		list($status, $data, $response) = $this->served('POST', '/sfs-tracking/v1/sync', array(
			'client' => array('version' => '1.0.0', 'env' => 'local'),
			'run_id' => 'test_' . bin2hex(random_bytes(6)),
			'records' => array(),
			'carrier_results' => array(),
		));
		$this->assertSame(200, $status, 'sync: ' . wp_json_encode($data));
		$this->assertSame('POST', $response->get_headers()['Allow'] ?? '');
		$this->assertSame(0, $this->failures(), 'accepted calls count no failures');
		$this->assertFalse(Auth::locked());
		$this->assertNull(Auth::last_reject(), 'no false "last refused" on the Tracking sync page');
	}

	public function test_a_real_replay_is_still_refused_and_counted(): void {
		$timestamp = (string) time();
		$nonce = bin2hex(random_bytes(16));
		list($status) = $this->served('GET', '/sfs-tracking/v1/ping', '', $timestamp, $nonce);
		$this->assertSame(200, $status);
		list($status, $data) = $this->served('GET', '/sfs-tracking/v1/ping', '', $timestamp, $nonce);
		$this->assertSame(401, $status);
		$this->assertSame('sfs_replay', $data['code']);
		$this->assertSame(1, $this->failures(), 'the replayed request counts once, not again in the Allow check');
		Auth::clear_failures();
	}

	public function test_a_refused_call_counts_once(): void {
		list($status, $data) = $this->served('GET', '/sfs-tracking/v1/ping', '', null, null, array('x-sfs-key' => 'sfs_wrong01'));
		$this->assertSame(401, $status);
		$this->assertSame('sfs_bad_key', $data['code']);
		$this->assertSame(1, $this->failures());
		$this->assertSame('bad_key', Auth::last_reject()['code']);
		Auth::clear_failures();
		delete_transient(Auth::REJECT);
	}

	/**
	 * Signs, dispatches and then applies rest_post_dispatch like WP_REST_Server::serve_request.
	 *
	 * @param array|string $body
	 */
	private function served(string $method, string $route, $body = '', ?string $timestamp = null, ?string $nonce = null, array $override_headers = array()): array {
		$body = is_array($body) ? (string) wp_json_encode($body) : $body;
		$timestamp = $timestamp ?? (string) time();
		$nonce = $nonce ?? bin2hex(random_bytes(16));
		$signature = RequestSigner::sign(SFS_TRACKING_SECRET, RequestSigner::canonical($timestamp, $nonce, $method, $route, $body));
		$request = new \WP_REST_Request($method, $route);
		$request->set_body($body);
		$request->set_header('content-type', 'application/json');
		foreach (array_merge(array('x-sfs-key' => SFS_TRACKING_KEY_ID, 'x-sfs-timestamp' => $timestamp, 'x-sfs-nonce' => $nonce, 'x-sfs-signature' => $signature), $override_headers) as $name => $value) {
			$request->set_header($name, $value);
		}
		$server = rest_get_server();
		$response = $server->dispatch($request);
		$response = apply_filters('rest_post_dispatch', rest_ensure_response($response), $server, $request);
		return array($response->get_status(), $response->get_data(), $response);
	}

	private function failures(): int {
		return (int) get_transient('sfs_trk_fail_' . md5(Auth::ip()));
	}
}

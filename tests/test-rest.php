<?php
/**
 * Tests for PVSA_REST — the /ping liveness route and the token-gated /report
 * route (auth via capability or constant-time token, 503 on a fail verdict).
 *
 * The registry is replaced with a single fast in-memory check so these tests
 * never touch the network or the real probes.
 *
 * @package PressVitalsSiteAuditor
 */

class Test_PVSA_REST extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// Deterministic, network-free registry: one passing check.
		$this->force_single_check( 'pass' );

		// Fresh REST server; the plugin's PVSA_REST instance registers its routes.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'pvsa_registered_checks' );
		delete_option( PVSA_OPTION_TOKEN );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Make the engine run exactly one check with the given status.
	 *
	 * @param string $status pass|warn|fail.
	 */
	private function force_single_check( $status ) {
		remove_all_filters( 'pvsa_registered_checks' );
		add_filter(
			'pvsa_registered_checks',
			static function () use ( $status ) {
				return array(
					'only' => array(
						'label'    => 'Only',
						'group'    => 'Testing',
						'tier'     => 1,
						'callback' => static function () use ( $status ) {
							return array(
								'status' => $status,
								'detail' => 'forced ' . $status,
							);
						},
					),
				);
			}
		);
	}

	public function test_ping_is_public_and_returns_200() {
		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/pressvitals/v1/ping' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'pressvitals-site-auditor', $data['plugin'] );
	}

	public function test_report_requires_authentication() {
		wp_set_current_user( 0 );
		update_option( PVSA_OPTION_TOKEN, 'secret-token' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/pressvitals/v1/report' ) );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_report_allows_admin_capability() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/pressvitals/v1/report' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pass', $response->get_data()['verdict'] );
	}

	public function test_report_accepts_valid_token() {
		wp_set_current_user( 0 );
		update_option( PVSA_OPTION_TOKEN, 'secret-token' );

		$request = new WP_REST_Request( 'GET', '/pressvitals/v1/report' );
		$request->set_param( 'token', 'secret-token' );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_report_rejects_wrong_token() {
		wp_set_current_user( 0 );
		update_option( PVSA_OPTION_TOKEN, 'secret-token' );

		$request = new WP_REST_Request( 'GET', '/pressvitals/v1/report' );
		$request->set_param( 'token', 'wrong-token' );

		$response = rest_do_request( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_report_returns_503_on_fail_verdict() {
		$this->force_single_check( 'fail' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/pressvitals/v1/report' ) );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'fail', $response->get_data()['verdict'] );

		$headers = $response->get_headers();
		$this->assertSame( 'fail', $headers['X-PVSA-Verdict'] );
		$this->assertSame( '1', $headers['X-PVSA-Fail-Count'] );
		$this->assertSame( '0', $headers['X-PVSA-Warn-Count'] );
	}

	public function test_report_returns_200_with_always_200_on_fail_verdict() {
		$this->force_single_check( 'fail' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'GET', '/pressvitals/v1/report' );
		$request->set_param( 'always_200', '1' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'fail', $response->get_data()['verdict'] );
		$headers = $response->get_headers();
		$this->assertSame( 'fail', $headers['X-PVSA-Verdict'] );
		$this->assertSame( '1', $headers['X-PVSA-Fail-Count'] );
	}

	public function test_report_headers_on_warn_verdict() {
		$this->force_single_check( 'warn' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/pressvitals/v1/report' ) );
		$this->assertSame( 200, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertSame( 'warn', $headers['X-PVSA-Verdict'] );
		$this->assertSame( '0', $headers['X-PVSA-Fail-Count'] );
		$this->assertSame( '1', $headers['X-PVSA-Warn-Count'] );
	}
}

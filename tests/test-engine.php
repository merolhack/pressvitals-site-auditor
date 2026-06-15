<?php
/**
 * Tests for OHSA_Engine — the registry, the worst-of verdict aggregation,
 * defensive execution, settings, and a few deterministic built-in probes.
 *
 * @package OmniHealthSiteAuditor
 */

class Test_OHSA_Engine extends WP_UnitTestCase {

	/**
	 * @var OHSA_Engine
	 */
	private $engine;

	public function set_up() {
		parent::set_up();
		// Start from a clean registry, then register the built-in checks.
		remove_all_filters( 'ohsa_registered_checks' );
		$this->engine = new OHSA_Engine();
		$this->engine->init();
	}

	public function tear_down() {
		remove_all_filters( 'ohsa_registered_checks' );
		remove_all_filters( 'ohsa_last_backup_timestamp' );
		parent::tear_down();
	}

	/**
	 * Replace the whole registry with a controlled set (no network/DB probes).
	 *
	 * @param array $checks Map of id => definition.
	 */
	private function register_only( array $checks ) {
		remove_all_filters( 'ohsa_registered_checks' );
		add_filter(
			'ohsa_registered_checks',
			static function () use ( $checks ) {
				return $checks;
			}
		);
	}

	/**
	 * A pass/warn/fail check definition factory.
	 *
	 * @param string $status Status to return.
	 * @return array
	 */
	private function fake_check( $status ) {
		return array(
			'label'    => 'Fake ' . $status,
			'group'    => 'Testing',
			'tier'     => 3,
			'callback' => static function () use ( $status ) {
				return array(
					'status' => $status,
					'detail' => 'fake ' . $status,
				);
			},
		);
	}

	public function test_built_in_checks_are_registered() {
		$checks = $this->engine->get_checks();

		$this->assertIsArray( $checks );
		$this->assertGreaterThanOrEqual( 22, count( $checks ), 'Expected at least 22 built-in probes.' );
		foreach ( array( 'db_connection', 'php_version', 'backup_recency', 'ssl_cert_expiry', 'email_dns' ) as $id ) {
			$this->assertArrayHasKey( $id, $checks, "Missing built-in check: {$id}" );
		}
	}

	public function test_get_checks_drops_invalid_definitions() {
		add_filter(
			'ohsa_registered_checks',
			static function ( $checks ) {
				$checks['no_callback']  = array( 'label' => 'Nope', 'group' => 'X', 'tier' => 3 );
				$checks['not_callable'] = array( 'callback' => 'definitely_not_a_function_xyz' );
				$checks['ok']           = array(
					'label'    => 'OK',
					'group'    => 'X',
					'tier'     => 3,
					'callback' => '__return_true',
				);
				return $checks;
			},
			20
		);

		$checks = $this->engine->get_checks();
		$this->assertArrayNotHasKey( 'no_callback', $checks );
		$this->assertArrayNotHasKey( 'not_callable', $checks );
		$this->assertArrayHasKey( 'ok', $checks );
	}

	public function test_run_report_has_expected_shape() {
		$this->register_only(
			array(
				'a' => $this->fake_check( 'pass' ),
			)
		);

		$report = $this->engine->run();

		foreach ( array( 'verdict', 'pass', 'warn', 'fail', 'checks', 'generated_at', 'duration_ms' ) as $key ) {
			$this->assertArrayHasKey( $key, $report );
		}
		$this->assertArrayHasKey( 'a', $report['checks'] );
		$row = $report['checks']['a'];
		foreach ( array( 'id', 'label', 'group', 'tier', 'status', 'detail', 'duration_ms' ) as $key ) {
			$this->assertArrayHasKey( $key, $row, "Result row missing {$key}" );
		}
		$this->assertSame( 'a', $row['id'] );
		$this->assertSame( 3, $row['tier'] );
	}

	public function test_verdict_is_worst_of_all_checks() {
		// All pass -> pass.
		$this->register_only(
			array(
				'a' => $this->fake_check( 'pass' ),
				'b' => $this->fake_check( 'pass' ),
			)
		);
		$this->assertSame( 'pass', $this->engine->run()['verdict'] );

		// A warn present -> warn.
		$this->register_only(
			array(
				'a' => $this->fake_check( 'pass' ),
				'b' => $this->fake_check( 'warn' ),
			)
		);
		$report = $this->engine->run();
		$this->assertSame( 'warn', $report['verdict'] );
		$this->assertSame( 1, $report['pass'] );
		$this->assertSame( 1, $report['warn'] );

		// Any fail present -> fail (even alongside warns).
		$this->register_only(
			array(
				'a' => $this->fake_check( 'warn' ),
				'b' => $this->fake_check( 'fail' ),
			)
		);
		$this->assertSame( 'fail', $this->engine->run()['verdict'] );
	}

	public function test_throwing_check_becomes_fail_not_crash() {
		$this->register_only(
			array(
				'boom' => array(
					'label'    => 'Boom',
					'group'    => 'Testing',
					'tier'     => 1,
					'callback' => static function () {
						throw new \RuntimeException( 'kaboom' );
					},
				),
			)
		);

		$report = $this->engine->run();
		$this->assertSame( 'fail', $report['verdict'] );
		$this->assertSame( 'fail', $report['checks']['boom']['status'] );
	}

	public function test_malformed_result_becomes_fail() {
		$this->register_only(
			array(
				'bad' => array(
					'label'    => 'Bad',
					'group'    => 'Testing',
					'tier'     => 3,
					'callback' => static function () {
						return 'not-an-array';
					},
				),
			)
		);

		$report = $this->engine->run();
		$this->assertSame( 'fail', $report['checks']['bad']['status'] );
	}

	public function test_default_settings_keys() {
		$defaults = OHSA_Engine::default_settings();
		foreach ( array( 'error_log_warn_mb', 'error_log_fail_mb', 'autoload_warn_mb', 'autoload_fail_mb', 'alert_email' ) as $key ) {
			$this->assertArrayHasKey( $key, $defaults );
		}
	}

	public function test_get_setting_filter_override() {
		add_filter(
			'ohsa_setting_error_log_warn_mb',
			static function () {
				return 999;
			}
		);
		$this->assertSame( 999, OHSA_Engine::get_setting( 'error_log_warn_mb', 10 ) );
	}

	public function test_php_version_check_matches_runtime() {
		$result = $this->engine->check_php_version();
		$this->assertContains( $result['status'], array( 'pass', 'warn', 'fail' ) );

		if ( version_compare( PHP_VERSION, '8.2', '>=' ) ) {
			$this->assertSame( 'pass', $result['status'] );
		} elseif ( version_compare( PHP_VERSION, '8.0', '>=' ) ) {
			$this->assertSame( 'warn', $result['status'] );
		} else {
			$this->assertSame( 'fail', $result['status'] );
		}
	}

	public function test_homepage_indexable_fails_when_blog_not_public() {
		update_option( 'blog_public', '0' );
		$result = $this->engine->check_homepage_indexable();
		$this->assertSame( 'fail', $result['status'] );
		update_option( 'blog_public', '1' );
	}

	public function test_admin_username_check_warns_when_admin_exists() {
		if ( ! get_user_by( 'login', 'admin' ) ) {
			self::factory()->user->create(
				array(
					'user_login' => 'admin',
					'role'       => 'administrator',
				)
			);
		}
		$result = $this->engine->check_admin_username();
		$this->assertSame( 'warn', $result['status'] );
	}

	public function test_backup_recency_passes_with_recent_filter_timestamp() {
		add_filter(
			'ohsa_last_backup_timestamp',
			static function () {
				return time() - DAY_IN_SECONDS;
			}
		);
		$this->assertSame( 'pass', $this->engine->check_backup_recency()['status'] );
	}

	public function test_backup_recency_fails_with_stale_filter_timestamp() {
		add_filter(
			'ohsa_last_backup_timestamp',
			static function () {
				return time() - ( 30 * DAY_IN_SECONDS );
			}
		);
		$this->assertSame( 'fail', $this->engine->check_backup_recency()['status'] );
	}

	public function test_core_tables_present_passes_on_a_real_install() {
		// The WP test suite has a complete schema, so every core table exists.
		$result = $this->engine->check_core_tables_present();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	public function test_env_file_on_disk_passes_when_absent() {
		// A fresh test install has no .env in ABSPATH or one level up.
		$result = $this->engine->check_env_file_on_disk();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	public function test_wp_config_permissions_not_world_readable() {
		// In the test environment wp-config.php is absent (skipped) or restrictive;
		// it must never report a world-readable failure here.
		$result = $this->engine->check_wp_config_permissions();
		$this->assertContains( $result['status'], array( 'pass', 'warn' ), $result['detail'] );
	}
}

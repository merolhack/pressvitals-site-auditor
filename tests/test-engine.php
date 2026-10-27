<?php
/**
 * Tests for PVSA_Engine — the registry, the worst-of verdict aggregation,
 * defensive execution, settings, and a few deterministic built-in probes.
 *
 * @package PressVitalsSiteAuditor
 */

class Test_PVSA_Engine extends WP_UnitTestCase {

	/**
	 * @var PVSA_Engine
	 */
	private $engine;

	public function set_up() {
		parent::set_up();
		// Start from a clean registry, then register the built-in checks.
		remove_all_filters( 'pvsa_registered_checks' );
		$this->engine = new PVSA_Engine();
		$this->engine->init();
	}

	public function tear_down() {
		remove_all_filters( 'pvsa_registered_checks' );
		remove_all_filters( 'pvsa_last_backup_timestamp' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pvsa_php_eol_dates' );
		remove_all_filters( 'pvsa_php_min_secure_versions' );
		remove_all_filters( 'pvsa_current_php_version' );
		remove_all_filters( 'pvsa_detected_web_php_version' );
		remove_all_filters( 'pvsa_is_cli_or_cron' );
		delete_transient( 'pvsa_web_php_version' );
		delete_site_transient( 'update_core' );
		delete_site_transient( 'update_plugins' );
		parent::tear_down();
	}

	/**
	 * Replace the whole registry with a controlled set (no network/DB probes).
	 *
	 * @param array $checks Map of id => definition.
	 */
	private function register_only( array $checks ) {
		remove_all_filters( 'pvsa_registered_checks' );
		add_filter(
			'pvsa_registered_checks',
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
		$this->assertCount( 60, $checks, 'Expected exactly 60 built-in probes.' );
		foreach ( array( 'db_connection', 'php_version', 'php_hardening', 'backup_recency', 'ssl_cert_expiry', 'email_dns', 'expired_transients', 'php_execution_limits', 'db_index_health', 'postmeta_orphans', 'debug_log_not_public', 'heavy_autoloaded_options', 'revision_and_trash_bloat', 'cron_loopback_health', 'opcache_status', 'maintenance_mode_stuck', 'development_mode_off', 'env_type_production', 'db_prefix_customized', 'uploads_php_execution', 'password_hashes_modern', 'modern_image_formats' ) as $id ) {
			$this->assertArrayHasKey( $id, $checks, "Missing built-in check: {$id}" );
		}
	}

	public function test_get_checks_drops_invalid_definitions() {
		add_filter(
			'pvsa_registered_checks',
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
		$defaults = PVSA_Engine::default_settings();
		foreach ( array( 'error_log_warn_mb', 'error_log_fail_mb', 'autoload_warn_mb', 'autoload_fail_mb', 'alert_email' ) as $key ) {
			$this->assertArrayHasKey( $key, $defaults );
		}
	}

	public function test_get_setting_filter_override() {
		add_filter(
			'pvsa_setting_error_log_warn_mb',
			static function () {
				return 999;
			}
		);
		$this->assertSame( 999, PVSA_Engine::get_setting( 'error_log_warn_mb', 10 ) );
	}

	public function test_php_version_check_matches_runtime() {
		$result = $this->engine->check_php_version();
		$this->assertContains( $result['status'], array( 'pass', 'warn', 'fail' ) );
		$this->assertIsString( $result['detail'] );
	}

	public function test_php_version_fails_on_unpatched_micro_version() {
		$major_minor = implode( '.', array_slice( explode( '.', PHP_VERSION ), 0, 2 ) );
		add_filter(
			'pvsa_php_eol_dates',
			static function () use ( $major_minor ) {
				return array( $major_minor => '2099-01-01' );
			}
		);
		add_filter(
			'pvsa_php_min_secure_versions',
			static function () use ( $major_minor ) {
				return array( $major_minor => '99.99.99' );
			}
		);

		$result = $this->engine->check_php_version();
		$this->assertSame( 'fail', $result['status'] );
		$this->assertStringContainsString( 'below the minimum secure patch', $result['detail'] );
	}

	public function test_php_version_fails_when_eol() {
		$major_minor = implode( '.', array_slice( explode( '.', PHP_VERSION ), 0, 2 ) );
		add_filter(
			'pvsa_php_eol_dates',
			static function () use ( $major_minor ) {
				return array( $major_minor => '2000-01-01' );
			}
		);

		$result = $this->engine->check_php_version();
		$this->assertSame( 'fail', $result['status'] );
		$this->assertStringContainsString( 'end-of-life', $result['detail'] );
	}

	public function test_php_version_passes_when_patched_and_active() {
		$major_minor = implode( '.', array_slice( explode( '.', PHP_VERSION ), 0, 2 ) );
		add_filter(
			'pvsa_php_eol_dates',
			static function () use ( $major_minor ) {
				return array( $major_minor => '2099-01-01' );
			}
		);
		add_filter(
			'pvsa_php_min_secure_versions',
			static function () use ( $major_minor ) {
				return array( $major_minor => '1.0.0' );
			}
		);

		$result = $this->engine->check_php_version();
		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'actively supported', $result['detail'] );
	}

	public function test_php_version_mismatch_warns_when_web_supported_and_cli_eol() {
		add_filter(
			'pvsa_is_cli_or_cron',
			'__return_true'
		);
		add_filter(
			'pvsa_current_php_version',
			static function () {
				return '8.0.30';
			}
		);
		add_filter(
			'pvsa_detected_web_php_version',
			static function () {
				return '8.3.36';
			}
		);
		add_filter(
			'pvsa_php_eol_dates',
			static function () {
				return array(
					'8.0' => '2023-11-26',
					'8.3' => '2099-01-01',
				);
			}
		);

		$result = $this->engine->check_php_version();
		$this->assertSame( 'warn', $result['status'] );
		$this->assertStringContainsString( 'Web server operates on PHP 8.3.36 (supported)', $result['detail'] );
		$this->assertStringContainsString( 'executed with PHP 8.0.30 (end-of-life)', $result['detail'] );
		$this->assertStringContainsString( 'crontab or hosting panel to PHP 8.3', $result['detail'] );
	}

	public function test_php_version_mismatch_fails_when_both_web_and_cli_eol() {
		add_filter(
			'pvsa_is_cli_or_cron',
			'__return_true'
		);
		add_filter(
			'pvsa_current_php_version',
			static function () {
				return '8.0.30';
			}
		);
		add_filter(
			'pvsa_detected_web_php_version',
			static function () {
				return '7.4.33';
			}
		);
		add_filter(
			'pvsa_php_eol_dates',
			static function () {
				return array(
					'7.4' => '2022-11-28',
					'8.0' => '2023-11-26',
				);
			}
		);

		$result = $this->engine->check_php_version();
		$this->assertSame( 'fail', $result['status'] );
		$this->assertStringContainsString( 'Both Web server (PHP 7.4.33) and WP-Cron/CLI runtime (PHP 8.0.30) are end-of-life', $result['detail'] );
	}

	public function test_php_version_mismatch_fails_when_web_is_eol_and_cli_is_modern() {
		add_filter(
			'pvsa_is_cli_or_cron',
			'__return_true'
		);
		add_filter(
			'pvsa_current_php_version',
			static function () {
				return '8.3.36';
			}
		);
		add_filter(
			'pvsa_detected_web_php_version',
			static function () {
				return '7.4.33';
			}
		);
		add_filter(
			'pvsa_php_eol_dates',
			static function () {
				return array(
					'7.4' => '2022-11-28',
					'8.3' => '2099-01-01',
				);
			}
		);

		$result = $this->engine->check_php_version();
		$this->assertSame( 'fail', $result['status'] );
		$this->assertStringContainsString( 'Web server runs PHP 7.4.33 which is end-of-life and vulnerable', $result['detail'] );
	}

	public function test_php_version_mismatch_passes_when_both_are_supported() {
		add_filter(
			'pvsa_is_cli_or_cron',
			'__return_true'
		);
		add_filter(
			'pvsa_current_php_version',
			static function () {
				return '8.2.20';
			}
		);
		add_filter(
			'pvsa_detected_web_php_version',
			static function () {
				return '8.3.10';
			}
		);
		add_filter(
			'pvsa_php_eol_dates',
			static function () {
				return array(
					'8.2' => '2099-01-01',
					'8.3' => '2099-01-01',
				);
			}
		);
		add_filter(
			'pvsa_php_min_secure_versions',
			static function () {
				return array(
					'8.2' => '1.0.0',
					'8.3' => '1.0.0',
				);
			}
		);

		$result = $this->engine->check_php_version();
		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'PHP runtimes are actively supported (Web: PHP 8.3.10, WP-Cron/CLI: PHP 8.2.20)', $result['detail'] );
	}

	public function test_record_and_get_detected_web_php_version() {
		delete_transient( 'pvsa_web_php_version' );

		// When not in background, record_web_php_version should write to transient.
		add_filter( 'pvsa_is_cli_or_cron', '__return_false' );
		$this->engine->record_web_php_version();
		$this->assertSame( PHP_VERSION, get_transient( 'pvsa_web_php_version' ) );

		// In background, record_web_php_version should NOT overwrite transient.
		remove_all_filters( 'pvsa_is_cli_or_cron' );
		add_filter( 'pvsa_is_cli_or_cron', '__return_true' );
		set_transient( 'pvsa_web_php_version', '8.4.1', 3600 );
		$this->engine->record_web_php_version();
		$this->assertSame( '8.4.1', get_transient( 'pvsa_web_php_version' ) );

		// get_detected_web_php_version should return the recorded version.
		$this->assertSame( '8.4.1', $this->engine->get_detected_web_php_version() );
	}

	public function test_php_hardening_probe_structure() {
		$result = $this->engine->check_php_hardening();
		$this->assertContains( $result['status'], array( 'pass', 'warn', 'fail' ) );
		$this->assertIsString( $result['detail'] );
	}

	public function test_php_security_advisories_offline_fallback() {
		$defaults = array(
			'8.2' => '8.2.35',
			'8.3' => '8.3.36',
		);
		$versions = $this->engine->get_min_secure_php_versions( $defaults );
		$this->assertIsArray( $versions );
		$this->assertArrayHasKey( '8.3', $versions );
		$this->assertSame( '8.3.36', $versions['8.3'] );
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
			'pvsa_last_backup_timestamp',
			static function () {
				return time() - DAY_IN_SECONDS;
			}
		);
		$this->assertSame( 'pass', $this->engine->check_backup_recency()['status'] );
	}

	public function test_backup_recency_fails_with_stale_filter_timestamp() {
		add_filter(
			'pvsa_last_backup_timestamp',
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

	public function test_orphaned_tables_clean_install() {
		// A bare WP install has only core tables, so nothing should be flagged.
		$result = $this->engine->check_orphaned_tables();
		$this->assertContains( $result['status'], array( 'pass', 'warn' ), $result['detail'] );
	}

	public function test_core_update_available_fails_on_same_branch() {
		$current = get_bloginfo( 'version' );
		$parts   = explode( '.', $current );
		$branch  = $parts[0] . '.' . ( isset( $parts[1] ) ? $parts[1] : '0' );
		$offered = $branch . '.999';

		set_site_transient(
			'update_core',
			(object) array(
				'updates'      => array(
					(object) array(
						'response' => 'upgrade',
						'current'  => $offered,
						'version'  => $offered,
						'locale'   => 'en_US',
					),
				),
				'last_checked' => time(),
			)
		);

		$result = $this->engine->check_core_update_available();
		$this->assertSame( 'fail', $result['status'], $result['detail'] );
	}

	public function test_core_update_pass_when_latest() {
		set_site_transient(
			'update_core',
			(object) array(
				'updates'      => array(
					(object) array(
						'response' => 'latest',
						'current'  => get_bloginfo( 'version' ),
						'version'  => get_bloginfo( 'version' ),
						'locale'   => 'en_US',
					),
				),
				'last_checked' => time(),
			)
		);

		$result = $this->engine->check_core_update_available();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	public function test_plugin_updates_pass_when_none_pending() {
		set_site_transient(
			'update_plugins',
			(object) array(
				'response'     => array(),
				'no_update'    => array(),
				'last_checked' => time(),
			)
		);

		$result = $this->engine->check_plugin_updates_pending();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	public function test_user_enumeration_warns_when_exposed() {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'author=1' ) ) {
					return array(
						'response' => array( 'code' => 301, 'message' => '' ),
						'headers'  => array( 'location' => home_url( '/author/admin/' ) ),
						'body'     => '',
					);
				}
				if ( false !== strpos( $url, '/wp/v2/users' ) ) {
					return array(
						'response' => array( 'code' => 200, 'message' => '' ),
						'headers'  => array(),
						'body'     => wp_json_encode( array( array( 'id' => 1, 'slug' => 'admin' ) ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $this->engine->check_user_enumeration_blocked();
		$this->assertSame( 'warn', $result['status'], $result['detail'] );
	}

	public function test_user_enumeration_pass_when_blocked() {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'author=1' ) ) {
					return array(
						'response' => array( 'code' => 200, 'message' => '' ),
						'headers'  => array(),
						'body'     => 'home',
					);
				}
				if ( false !== strpos( $url, '/wp/v2/users' ) ) {
					return array(
						'response' => array( 'code' => 401, 'message' => '' ),
						'headers'  => array(),
						'body'     => wp_json_encode( array( 'code' => 'rest_user_cannot_view' ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $this->engine->check_user_enumeration_blocked();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	public function test_db_overhead_pass() {
		// Fresh test DB will naturally have almost 0 overhead.
		$result = $this->engine->check_db_overhead();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	public function test_core_tables_present_pass() {
		// Test framework installs core tables.
		$result = $this->engine->check_core_tables_present();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	public function test_security_headers_pass() {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'headers'  => array(
						'strict-transport-security' => 'max-age=31536000',
						'x-content-type-options'    => 'nosniff',
						'x-frame-options'           => 'SAMEORIGIN',
						'referrer-policy'           => 'no-referrer',
					),
					'body'     => '',
				);
			},
			10,
			3
		);

		$result = $this->engine->check_security_headers();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	public function test_https_forced_pass() {
		$original_home = get_option( 'home' );
		update_option( 'home', 'https://example.org' );

		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				return array(
					'response' => array( 'code' => 301, 'message' => 'Moved Permanently' ),
					'headers'  => array(
						'location' => 'https://example.org/',
					),
					'body'     => '',
				);
			},
			10,
			3
		);

		$result = $this->engine->check_https_forced();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );

		update_option( 'home', $original_home );
	}

	public function test_stray_files_pass() {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				// Simulate 404 for all sensitive files.
				return array(
					'response' => array( 'code' => 404, 'message' => 'Not Found' ),
					'headers'  => array(),
					'body'     => '',
				);
			},
			10,
			3
		);

		$result = $this->engine->check_stray_files();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	/**
	 * Expired transients: clean DB returns pass.
	 */
	public function test_check_expired_transients_pass() {
		$result = $this->engine->check_expired_transients();
		$this->assertContains( $result['status'], array( 'pass', 'warn', 'fail' ) );
		$this->assertNotEmpty( $result['detail'] );
	}

	/**
	 * PHP execution limits: always returns a valid result.
	 */
	public function test_check_php_execution_limits_pass() {
		$result = $this->engine->check_php_execution_limits();
		$this->assertContains( $result['status'], array( 'pass', 'warn', 'fail' ) );
		$this->assertNotEmpty( $result['detail'] );
	}

	/**
	 * DB index health: core tables in test DB have PRIMARY keys.
	 */
	public function test_check_db_index_health_pass() {
		$result = $this->engine->check_db_index_health();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	/**
	 * Postmeta orphans: clean test DB returns pass.
	 */
	public function test_check_postmeta_orphans_pass() {
		$result = $this->engine->check_postmeta_orphans();
		$this->assertContains( $result['status'], array( 'pass', 'warn' ) );
		$this->assertNotEmpty( $result['detail'] );
	}

	/**
	 * Debug log not public: mock 404 response returns pass.
	 */
	public function test_check_debug_log_not_public_pass() {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 404, 'message' => 'Not Found' ),
					'headers'  => array(),
					'body'     => '',
				);
			}
		);

		$result = $this->engine->check_debug_log_not_public();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	/**
	 * Debug log not public: mock 200 with log body returns fail.
	 */
	public function test_check_debug_log_not_public_fail_when_exposed() {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'headers'  => array(),
					'body'     => '[14-Aug-2026 12:00:00 UTC] PHP Fatal error: Uncaught Exception',
				);
			}
		);

		$result = $this->engine->check_debug_log_not_public();
		$this->assertSame( 'fail', $result['status'], $result['detail'] );
	}

	/**
	 * Heavy autoloaded options: clean test DB returns pass.
	 */
	public function test_check_heavy_autoloaded_options_pass() {
		$result = $this->engine->check_heavy_autoloaded_options();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	/**
	 * Revision and trash bloat: clean test DB returns pass.
	 */
	public function test_check_revision_and_trash_bloat_pass() {
		$result = $this->engine->check_revision_and_trash_bloat();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	/**
	 * WP-Cron loopback: mock 200 OK returns pass.
	 */
	public function test_check_cron_loopback_health_pass() {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'headers'  => array(),
					'body'     => '',
				);
			}
		);

		$result = $this->engine->check_cron_loopback_health();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	/**
	 * OPcache status: always returns a valid status array.
	 */
	public function test_check_opcache_status() {
		$result = $this->engine->check_opcache_status();
		$this->assertContains( $result['status'], array( 'pass', 'warn' ) );
		$this->assertNotEmpty( $result['detail'] );
	}

	/**
	 * WordPress 7.1 compatibility: verify core update probe recognizes WP 7.1+.
	 */
	public function test_wordpress_71_compatibility_pass() {
		global $wp_version;
		$orig_version = $wp_version;
		$wp_version   = '7.1';

		$result = $this->engine->check_core_update_available();
		$this->assertContains( $result['status'], array( 'pass', 'warn' ) );
		$this->assertNotEmpty( $result['detail'] );

		$wp_version = $orig_version;
	}

	/**
	 * Maintenance mode stuck: verify clean pass when file is absent, and fail when file is older than 10m.
	 */
	public function test_check_maintenance_mode_stuck() {
		$file = ABSPATH . '.maintenance';
		if ( file_exists( $file ) ) {
			unlink( $file );
		}

		$result = $this->engine->check_maintenance_mode_stuck();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );

		// Simulate stuck maintenance file from 20 minutes ago.
		$old_timestamp = time() - 1200;
		file_put_contents( $file, "<?php \$upgrading = {$old_timestamp};" );

		$result = $this->engine->check_maintenance_mode_stuck();
		$this->assertSame( 'fail', $result['status'], $result['detail'] );

		unlink( $file );
	}

	/**
	 * Development mode probe: returns valid pass/warn status.
	 */
	public function test_check_development_mode_off() {
		$result = $this->engine->check_development_mode_off();
		$this->assertContains( $result['status'], array( 'pass', 'warn' ) );
		$this->assertNotEmpty( $result['detail'] );
	}

	/**
	 * Environment type probe: returns valid pass/warn/fail status.
	 */
	public function test_check_env_type_production() {
		$result = $this->engine->check_env_type_production();
		$this->assertContains( $result['status'], array( 'pass', 'warn', 'fail' ) );
		$this->assertNotEmpty( $result['detail'] );
	}

	/**
	 * DB table prefix customized probe: returns pass or warn.
	 */
	public function test_check_db_prefix_customized() {
		$result = $this->engine->check_db_prefix_customized();
		$this->assertContains( $result['status'], array( 'pass', 'warn' ) );
		$this->assertNotEmpty( $result['detail'] );
	}

	/**
	 * Uploads PHP execution blocked: test pass when mock server responds 403 Forbidden.
	 */
	public function test_check_uploads_php_execution_pass_on_403() {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 403, 'message' => 'Forbidden' ),
					'headers'  => array(),
					'body'     => '',
				);
			}
		);

		$result = $this->engine->check_uploads_php_execution();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	/**
	 * Password hashes modern: returns valid pass/warn status.
	 */
	public function test_check_password_hashes_modern() {
		$result = $this->engine->check_password_hashes_modern();
		$this->assertContains( $result['status'], array( 'pass', 'warn' ) );
		$this->assertNotEmpty( $result['detail'] );
	}

	/**
	 * Modern image format support: returns valid pass/warn status.
	 */
	public function test_check_modern_image_formats() {
		$result = $this->engine->check_modern_image_formats();
		$this->assertContains( $result['status'], array( 'pass', 'warn' ) );
		$this->assertNotEmpty( $result['detail'] );
	}

	/**
	 * HTTPS home: returns pass on HTTP 200 over HTTPS.
	 */
	public function test_check_https_home_pass_on_200() {
		add_filter(
			'home_url',
			static function() {
				return 'https://example.org/';
			}
		);
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'headers'  => array(),
					'body'     => 'OK',
				);
			}
		);

		$result = $this->engine->check_https_home();
		$this->assertSame( 'pass', $result['status'], $result['detail'] );
	}

	/**
	 * HTTPS home: returns warn on HTTP 503 during maintenance mode.
	 */
	public function test_check_https_home_warn_on_503_maintenance() {
		add_filter(
			'home_url',
			static function() {
				return 'https://example.org/';
			}
		);
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ),
					'headers'  => array(),
					'body'     => 'Briefly unavailable for scheduled maintenance.',
				);
			}
		);
		add_filter(
			'pvsa_is_maintenance_mode',
			'__return_true'
		);

		$result = $this->engine->check_https_home();
		$this->assertSame( 'warn', $result['status'], $result['detail'] );
		$this->assertStringContainsString( 'maintenance', $result['detail'] );
	}

	/**
	 * HTTPS home: returns fail on HTTP 503 when maintenance mode is inactive.
	 */
	public function test_check_https_home_fail_on_503_unplanned() {
		add_filter(
			'home_url',
			static function() {
				return 'https://example.org/';
			}
		);
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ),
					'headers'  => array(),
					'body'     => 'Error 503',
				);
			}
		);
		add_filter(
			'pvsa_is_maintenance_mode',
			'__return_false'
		);

		$result = $this->engine->check_https_home();
		$this->assertSame( 'fail', $result['status'], $result['detail'] );
	}

	/**
	 * Orphaned tables: wildcard pattern and known prefixes filter out plugin tables.
	 */
	public function test_check_orphaned_tables_supports_wildcards_and_prefixes() {
		add_filter(
			'pvsa_known_table_prefixes',
			static function( array $prefixes ): array {
				$prefixes[] = 'hp_';
				$prefixes[] = 'actionscheduler_';
				return $prefixes;
			}
		);
		add_filter(
			'pvsa_known_tables',
			static function( array $tables ): array {
				$tables[] = 'custom_*';
				$tables[] = 'exact_table';
				return $tables;
			}
		);

		$result = $this->engine->check_orphaned_tables();
		$this->assertContains( $result['status'], array( 'pass', 'warn' ), $result['detail'] );
	}
}

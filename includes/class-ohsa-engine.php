<?php
/**
 * Health-check engine.
 *
 * Collects checks from the `ohsa_registered_checks` filter, runs each one in
 * isolation (a throwing check becomes a "fail", not a crash), times it, and
 * aggregates a worst-of verdict. The built-in generic checks are registered
 * through the SAME public filter third parties use — they are the reference
 * implementation of the extension API.
 *
 * A registered check is an array keyed by a unique id:
 *   'db_connection' => array(
 *       'label'    => 'Database connection',   // human label
 *       'group'    => 'Database',              // functional category (UI section)
 *       'tier'     => 1,                       // 1 (critical) .. 5 (informational)
 *       'callback' => 'my_callable',           // returns array{status, detail}
 *   )
 * The callback returns: array( 'status' => 'pass'|'warn'|'fail', 'detail' => '…' ).
 *
 * @package OmniHealthSiteAuditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OHSA_Engine {

	/**
	 * Hook the built-in checks onto the public registration filter.
	 */
	public function init() {
		add_filter( 'ohsa_registered_checks', array( $this, 'register_core_checks' ) );
	}

	/**
	 * Default settings seeded on activation. Thresholds are in megabytes.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'error_log_warn_mb' => 10,
			'error_log_fail_mb' => 50,
			'autoload_warn_mb'  => 1,
			'autoload_fail_mb'  => 3,
			'alert_email'       => get_option( 'admin_email' ),
		);
	}

	/**
	 * Read a single setting, with a per-key filter override for developers.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public static function get_setting( $key, $default = null ) {
		$settings = get_option( OHSA_OPTION_SETTINGS, array() );
		$value    = ( is_array( $settings ) && isset( $settings[ $key ] ) ) ? $settings[ $key ] : $default;

		/**
		 * Filter a single OmniHealth: Deep Site Auditor setting at read time.
		 *
		 * @param mixed $value The resolved value.
		 */
		return apply_filters( 'ohsa_setting_' . $key, $value );
	}

	/**
	 * Preferred display order of functional groups (most critical first).
	 *
	 * @return string[]
	 */
	public static function group_order() {
		return array(
			__( 'Availability', 'omnihealth-site-auditor' ),
			__( 'Security', 'omnihealth-site-auditor' ),
			__( 'Errors', 'omnihealth-site-auditor' ),
			__( 'Database', 'omnihealth-site-auditor' ),
			__( 'Files', 'omnihealth-site-auditor' ),
			__( 'Email', 'omnihealth-site-auditor' ),
			__( 'SEO', 'omnihealth-site-auditor' ),
			__( 'Performance', 'omnihealth-site-auditor' ),
			__( 'Environment', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Collect, normalize and sort the registered checks.
	 *
	 * @return array
	 */
	public function get_checks() {
		/**
		 * Register health checks. Add your own by returning the array with a
		 * new keyed entry. See OHSA_Engine::register_core_checks() for the shape.
		 *
		 * @param array $checks Map of check-id => definition.
		 */
		$checks = apply_filters( 'ohsa_registered_checks', array() );
		if ( ! is_array( $checks ) ) {
			$checks = array();
		}

		$valid = array();
		foreach ( $checks as $id => $def ) {
			if ( is_array( $def ) && isset( $def['callback'] ) && is_callable( $def['callback'] ) ) {
				$valid[ sanitize_key( $id ) ] = $def;
			}
		}
		ksort( $valid );

		return $valid;
	}

	/**
	 * Run every registered check and build the report.
	 *
	 * @return array
	 */
	public function run() {
		$started = microtime( true );
		$report  = array(
			'verdict'      => 'pass',
			'pass'         => 0,
			'warn'         => 0,
			'fail'         => 0,
			'generated_at' => current_time( 'mysql', true ),
			'duration_ms'  => 0,
			'checks'       => array(),
		);

		foreach ( $this->get_checks() as $id => $def ) {
			$t0     = microtime( true );
			$result = $this->run_one( $def );

			$result['id']          = $id;
			$result['label']       = isset( $def['label'] ) ? (string) $def['label'] : $id;
			$result['group']       = isset( $def['group'] ) ? (string) $def['group'] : __( 'Other', 'omnihealth-site-auditor' );
			$result['tier']        = isset( $def['tier'] ) ? (int) $def['tier'] : 3;
			$result['duration_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );

			$report['checks'][ $id ] = $result;
			++$report[ $result['status'] ];
		}

		$report['verdict']     = $report['fail'] > 0 ? 'fail' : ( $report['warn'] > 0 ? 'warn' : 'pass' );
		$report['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		return $report;
	}

	/**
	 * Execute one check callback defensively.
	 *
	 * @param array $def Check definition.
	 * @return array{status:string,detail:string}
	 */
	private function run_one( array $def ) {
		try {
			$r = call_user_func( $def['callback'] );
			if ( ! is_array( $r ) || ! isset( $r['status'] ) ) {
				return array(
					'status' => 'fail',
					'detail' => __( 'Malformed check result.', 'omnihealth-site-auditor' ),
				);
			}
			$r['status'] = in_array( $r['status'], array( 'pass', 'warn', 'fail' ), true ) ? $r['status'] : 'fail';
			$r['detail'] = isset( $r['detail'] ) ? (string) $r['detail'] : '';
			return $r;
		} catch ( \Throwable $e ) {
			return array(
				'status' => 'fail',
				'detail' => __( 'Check threw an exception.', 'omnihealth-site-auditor' ),
			);
		}
	}

	// =======================================================================
	// Built-in generic checks — registered through the public filter.
	// =======================================================================

	/**
	 * Register the core checks. Core registers first; a third-party check can
	 * override one by re-using its id.
	 *
	 * @param array $checks Existing registry.
	 * @return array
	 */
	public function register_core_checks( $checks ) {
		if ( ! is_array( $checks ) ) {
			$checks = array();
		}

		$core = array(
			'db_connection'           => array(
				'label'    => __( 'Database connection', 'omnihealth-site-auditor' ),
				'group'    => __( 'Availability', 'omnihealth-site-auditor' ),
				'tier'     => 1,
				'callback' => array( $this, 'check_db_connection' ),
			),
			'https_home'              => array(
				'label'    => __( 'Homepage over HTTPS', 'omnihealth-site-auditor' ),
				'group'    => __( 'Availability', 'omnihealth-site-auditor' ),
				'tier'     => 1,
				'callback' => array( $this, 'check_https_home' ),
			),
			'debug_display_off'       => array(
				'label'    => __( 'Error display off in production', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 2,
				'callback' => array( $this, 'check_debug_display_off' ),
			),
			'error_log_size'          => array(
				'label'    => __( 'Error log size', 'omnihealth-site-auditor' ),
				'group'    => __( 'Errors', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_error_log_size' ),
			),
			'php_fatal_errors_recent' => array(
				'label'    => __( 'Recent PHP fatal errors', 'omnihealth-site-auditor' ),
				'group'    => __( 'Errors', 'omnihealth-site-auditor' ),
				'tier'     => 2,
				'callback' => array( $this, 'check_php_fatal_errors_recent' ),
			),
			'autoloaded_options_size' => array(
				'label'    => __( 'Autoloaded options size', 'omnihealth-site-auditor' ),
				'group'    => __( 'Database', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_autoloaded_options_size' ),
			),
			'disk_free'               => array(
				'label'    => __( 'Free disk space', 'omnihealth-site-auditor' ),
				'group'    => __( 'Files', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_disk_free' ),
			),
			'uploads_writable'        => array(
				'label'    => __( 'Uploads directory writable', 'omnihealth-site-auditor' ),
				'group'    => __( 'Files', 'omnihealth-site-auditor' ),
				'tier'     => 2,
				'callback' => array( $this, 'check_uploads_writable' ),
			),
			'memory_limit'            => array(
				'label'    => __( 'PHP memory limit', 'omnihealth-site-auditor' ),
				'group'    => __( 'Performance', 'omnihealth-site-auditor' ),
				'tier'     => 4,
				'callback' => array( $this, 'check_memory_limit' ),
			),
			'object_cache'            => array(
				'label'    => __( 'Persistent object cache', 'omnihealth-site-auditor' ),
				'group'    => __( 'Performance', 'omnihealth-site-auditor' ),
				'tier'     => 4,
				'callback' => array( $this, 'check_object_cache' ),
			),
			'php_version'             => array(
				'label'    => __( 'Supported PHP version', 'omnihealth-site-auditor' ),
				'group'    => __( 'Environment', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_php_version' ),
			),

			// --- Deep-audit probes that WordPress core's Site Health does NOT
			// perform (security posture, deliverability, TLS expiry, backups,
			// secret/file leaks, DB bloat). These are the auditor's signature. ---
			'env_file_exposed'        => array(
				'label'    => __( '.env file not web-accessible', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 1,
				'callback' => array( $this, 'check_env_file_exposed' ),
			),
			'stray_files'             => array(
				'label'    => __( 'No stray backup/diagnostic files in web root', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 2,
				'callback' => array( $this, 'check_stray_files' ),
			),
			'ssl_cert_expiry'         => array(
				'label'    => __( 'TLS certificate expiry', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 1,
				'callback' => array( $this, 'check_ssl_cert_expiry' ),
			),
			'security_headers'        => array(
				'label'    => __( 'Baseline security headers', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_security_headers' ),
			),
			'https_forced'            => array(
				'label'    => __( 'HTTPS is forced', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 2,
				'callback' => array( $this, 'check_https_forced' ),
			),
			'xmlrpc_status'           => array(
				'label'    => __( 'XML-RPC exposure', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 4,
				'callback' => array( $this, 'check_xmlrpc_status' ),
			),
			'admin_username'          => array(
				'label'    => __( 'No default "admin" username', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 4,
				'callback' => array( $this, 'check_admin_username' ),
			),
			'db_overhead'             => array(
				'label'    => __( 'Database bloat', 'omnihealth-site-auditor' ),
				'group'    => __( 'Database', 'omnihealth-site-auditor' ),
				'tier'     => 4,
				'callback' => array( $this, 'check_db_overhead' ),
			),
			'backup_recency'          => array(
				'label'    => __( 'Recent backup', 'omnihealth-site-auditor' ),
				'group'    => __( 'Files', 'omnihealth-site-auditor' ),
				'tier'     => 2,
				'callback' => array( $this, 'check_backup_recency' ),
			),
			'email_dns'               => array(
				'label'    => __( 'Email DNS (SPF + DMARC)', 'omnihealth-site-auditor' ),
				'group'    => __( 'Email', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_email_dns' ),
			),
			'homepage_indexable'      => array(
				'label'    => __( 'Homepage is indexable', 'omnihealth-site-auditor' ),
				'group'    => __( 'SEO', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_homepage_indexable' ),
			),
			'https_mixed_content'     => array(
				'label'    => __( 'HTTPS Mixed Content', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_https_mixed_content' ),
			),
			'rest_api_reachable'      => array(
				'label'    => __( 'REST API availability', 'omnihealth-site-auditor' ),
				'group'    => __( 'Environment', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_rest_api_reachable' ),
			),
			'env_file_on_disk'        => array(
				'label'    => __( '.env file not present/exposed on disk', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 1,
				'callback' => array( $this, 'check_env_file_on_disk' ),
			),
			'wp_config_permissions'   => array(
				'label'    => __( 'wp-config.php permissions', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 1,
				'callback' => array( $this, 'check_wp_config_permissions' ),
			),
			'core_tables_present'     => array(
				'label'    => __( 'Core database tables present', 'omnihealth-site-auditor' ),
				'group'    => __( 'Database', 'omnihealth-site-auditor' ),
				'tier'     => 1,
				'callback' => array( $this, 'check_core_tables_present' ),
			),
			'orphaned_tables'         => array(
				'label'    => __( 'Non-core database tables', 'omnihealth-site-auditor' ),
				'group'    => __( 'Database', 'omnihealth-site-auditor' ),
				'tier'     => 4,
				'callback' => array( $this, 'check_orphaned_tables' ),
			),
			'core_update_available'   => array(
				'label'    => __( 'WordPress core up to date', 'omnihealth-site-auditor' ),
				'group'    => __( 'Environment', 'omnihealth-site-auditor' ),
				'tier'     => 2,
				'callback' => array( $this, 'check_core_update_available' ),
			),
			'plugin_updates_pending'  => array(
				'label'    => __( 'Plugin updates', 'omnihealth-site-auditor' ),
				'group'    => __( 'Environment', 'omnihealth-site-auditor' ),
				'tier'     => 2,
				'callback' => array( $this, 'check_plugin_updates_pending' ),
			),
			'user_enumeration'        => array(
				'label'    => __( 'User enumeration not exposed', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_user_enumeration_blocked' ),
			),
			'secret_keys_defined'     => array(
				'label'    => __( 'Secret keys defined', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 2,
				'callback' => array( $this, 'check_secret_keys_defined' ),
			),
			'file_editing_disabled'   => array(
				'label'    => __( 'File editing disabled', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_file_editing_disabled' ),
			),
			'directory_listing_off'   => array(
				'label'    => __( 'Directory listing disabled', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_directory_listing_off' ),
			),
			'force_ssl_admin'         => array(
				'label'    => __( 'Force SSL for Admin', 'omnihealth-site-auditor' ),
				'group'    => __( 'Security', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_force_ssl_admin' ),
			),
			'table_storage_engine'    => array(
				'label'    => __( 'Database storage engine', 'omnihealth-site-auditor' ),
				'group'    => __( 'Database', 'omnihealth-site-auditor' ),
				'tier'     => 4,
				'callback' => array( $this, 'check_table_storage_engine' ),
			),
			'table_collation'         => array(
				'label'    => __( 'Database collation', 'omnihealth-site-auditor' ),
				'group'    => __( 'Database', 'omnihealth-site-auditor' ),
				'tier'     => 4,
				'callback' => array( $this, 'check_table_collation' ),
			),
			'largest_tables'          => array(
				'label'    => __( 'Largest database tables', 'omnihealth-site-auditor' ),
				'group'    => __( 'Database', 'omnihealth-site-auditor' ),
				'tier'     => 5,
				'callback' => array( $this, 'check_largest_tables' ),
			),
			'db_charset_client'       => array(
				'label'    => __( 'Database client charset', 'omnihealth-site-auditor' ),
				'group'    => __( 'Database', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_db_charset_client' ),
			),
			'theme_updates_pending'   => array(
				'label'    => __( 'Theme updates', 'omnihealth-site-auditor' ),
				'group'    => __( 'Environment', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_theme_updates_pending' ),
			),
			'inactive_plugins_themes' => array(
				'label'    => __( 'Inactive plugins and themes', 'omnihealth-site-auditor' ),
				'group'    => __( 'Environment', 'omnihealth-site-auditor' ),
				'tier'     => 4,
				'callback' => array( $this, 'check_inactive_plugins_themes' ),
			),
			'cron_overdue'            => array(
				'label'    => __( 'Scheduled events (WP-Cron)', 'omnihealth-site-auditor' ),
				'group'    => __( 'Performance', 'omnihealth-site-auditor' ),
				'tier'     => 3,
				'callback' => array( $this, 'check_cron_overdue' ),
			),
			'transient_api_backed'    => array(
				'label'    => __( 'API-backed transients', 'omnihealth-site-auditor' ),
				'group'    => __( 'Performance', 'omnihealth-site-auditor' ),
				'tier'     => 4,
				'callback' => array( $this, 'check_transient_api_backed' ),
			),
		);

		return array_merge( $core, $checks );
	}

	/**
	 * Database responds to a trivial query.
	 *
	 * @return array
	 */
	public function check_db_connection() {
		global $wpdb;
		// Static, parameterless liveness query; live state required (no cache).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( 'SELECT 1' );

		return ( '1' === (string) $result )
			? array(
				'status' => 'pass',
				'detail' => __( 'MySQL responds to SELECT 1.', 'omnihealth-site-auditor' ),
			)
			: array(
				'status' => 'fail',
				'detail' => __( 'MySQL did not return a row for SELECT 1.', 'omnihealth-site-auditor' ),
			);
	}

	/**
	 * Homepage is reachable over HTTPS and returns 200.
	 *
	 * @return array
	 */
	public function check_https_home() {
		$home = home_url( '/' );
		if ( 0 !== stripos( $home, 'https://' ) ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'Site address is not HTTPS.', 'omnihealth-site-auditor' ),
			);
		}

		$timeout  = (int) apply_filters( 'ohsa_http_timeout', 8 );
		$response = wp_remote_get( $home, array( 'timeout' => $timeout ) );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'fail',
				'detail' => __( 'Homepage request failed: ', 'omnihealth-site-auditor' ) . $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return ( 200 === $code )
			? array(
				'status' => 'pass',
				'detail' => __( 'Homepage returns HTTP 200 over HTTPS.', 'omnihealth-site-auditor' ),
			)
			: array(
				'status' => 'fail',
				/* translators: %d: HTTP status code */
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'Homepage returned HTTP %d.', 'omnihealth-site-auditor' ), $code ),
			);
	}

	/**
	 * Error display must be off for visitors in production.
	 *
	 * @return array
	 */
	public function check_debug_display_off() {
		$display_errors = ini_get( 'display_errors' );
		$on             = ( '1' === $display_errors || 'on' === strtolower( (string) $display_errors ) );

		return $on
			? array(
				'status' => 'warn',
				'detail' => __( 'display_errors is on — PHP errors may leak to visitors.', 'omnihealth-site-auditor' ),
			)
			: array(
				'status' => 'pass',
				'detail' => __( 'Error display is off for visitors.', 'omnihealth-site-auditor' ),
			);
	}

	/**
	 * Error-log file size versus configurable thresholds.
	 *
	 * @return array
	 */
	public function check_error_log_size() {
		$path = $this->resolve_log_path();
		if ( '' === $path ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'No readable error log found.', 'omnihealth-site-auditor' ),
			);
		}

		$bytes     = (int) filesize( $path );
		$warn      = (float) self::get_setting( 'error_log_warn_mb', 10 ) * MB_IN_BYTES;
		$fail      = (float) self::get_setting( 'error_log_fail_mb', 50 ) * MB_IN_BYTES;
		$readable  = size_format( $bytes, 2 );
		$file_name = basename( $path );

		if ( $bytes >= $fail ) {
			/* translators: 1: file name, 2: human-readable size */
			return array(
				'status' => 'fail',
				// translators: 1: dynamic value
				'detail' => sprintf( __( '%1$s is %2$s — rotate or truncate it.', 'omnihealth-site-auditor' ), $file_name, $readable ),
			);
		}
		if ( $bytes >= $warn ) {
			/* translators: 1: file name, 2: human-readable size */
			return array(
				'status' => 'warn',
				// translators: 1: dynamic value
				'detail' => sprintf( __( '%1$s is %2$s — growing.', 'omnihealth-site-auditor' ), $file_name, $readable ),
			);
		}
		/* translators: 1: file name, 2: human-readable size */
		return array(
			'status' => 'pass',
			// translators: 1: dynamic value
			'detail' => sprintf( __( '%1$s is %2$s.', 'omnihealth-site-auditor' ), $file_name, $readable ),
		);
	}

	/**
	 * Count recent PHP fatal/parse errors in the error log.
	 *
	 * Reads only WP-standard log locations (never user input) via the
	 * WP_Filesystem API, and skips files too large to scan safely.
	 *
	 * @return array
	 */
	public function check_php_fatal_errors_recent() {
		$path = $this->resolve_log_path();
		if ( '' === $path ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'No readable error log found.', 'omnihealth-site-auditor' ),
			);
		}

		$size = (int) filesize( $path );
		$cap  = (int) apply_filters( 'ohsa_fatal_scan_max_bytes', 10 * MB_IN_BYTES );
		if ( $size > $cap ) {
			return array(
				'status' => 'warn',
				/* translators: %s: human-readable size */
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'Error log too large to scan (%s); inspect it manually.', 'omnihealth-site-auditor' ), size_format( $size, 2 ) ),
			);
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Filesystem API unavailable; skipped.', 'omnihealth-site-auditor' ),
			);
		}

		$contents = $wp_filesystem->get_contents( $path );
		if ( false === $contents ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Could not read error log; skipped.', 'omnihealth-site-auditor' ),
			);
		}

		$hours  = (int) apply_filters( 'ohsa_fatal_lookback_hours', 24 );
		$cutoff = time() - ( $hours * HOUR_IN_SECONDS );
		$count  = 0;

		foreach ( preg_split( '/\r\n|\r|\n/', $contents ) as $line ) {
			if ( false === stripos( $line, 'PHP Fatal' ) && false === stripos( $line, 'PHP Parse' ) ) {
				continue;
			}
			// Lines look like: [14-Jun-2026 02:32:04 UTC] PHP Fatal error: …
			if ( preg_match( '/^\[([^\]]+)\]/', $line, $m ) ) {
				$ts = strtotime( $m[1] );
				if ( $ts && $ts < $cutoff ) {
					continue;
				}
			}
			++$count;
		}

		if ( $count > 0 ) {
			return array(
				'status' => 'warn',
				'detail' => sprintf(
					/* translators: 1: error count, 2: lookback hours */
					_n( '%1$d PHP fatal/parse error in the last %2$d h.', '%1$d PHP fatal/parse errors in the last %2$d h.', $count, 'omnihealth-site-auditor' ),
					$count,
					$hours
				),
			);
		}
		return array(
			'status' => 'pass',
			/* translators: %d: lookback hours */
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'No PHP fatal/parse errors in the last %d h.', 'omnihealth-site-auditor' ), $hours ),
		);
	}

	/**
	 * Total size of autoloaded options versus configurable thresholds.
	 *
	 * @return array
	 */
	public function check_autoloaded_options_size() {
		global $wpdb;
		// Aggregate over a literal query; live state required (no cache).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bytes = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" );

		$warn = (float) self::get_setting( 'autoload_warn_mb', 1 ) * MB_IN_BYTES;
		$fail = (float) self::get_setting( 'autoload_fail_mb', 3 ) * MB_IN_BYTES;
		$h    = size_format( $bytes, 2 );

		if ( $bytes >= $fail ) {
			/* translators: %s: human-readable size */
			return array(
				'status' => 'fail',
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'Autoloaded options total %s — trim them.', 'omnihealth-site-auditor' ), $h ),
			);
		}
		if ( $bytes >= $warn ) {
			/* translators: %s: human-readable size */
			return array(
				'status' => 'warn',
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'Autoloaded options total %s.', 'omnihealth-site-auditor' ), $h ),
			);
		}
		/* translators: %s: human-readable size */
		return array(
			'status' => 'pass',
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'Autoloaded options total %s.', 'omnihealth-site-auditor' ), $h ),
		);
	}

	/**
	 * Free disk space on the WordPress volume.
	 *
	 * @return array
	 */
	public function check_disk_free() {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'disk_free_space() unavailable; skipped.', 'omnihealth-site-auditor' ),
			);
		}
		$free = @disk_free_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $free ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Could not read free disk space; skipped.', 'omnihealth-site-auditor' ),
			);
		}
		$min = (float) apply_filters( 'ohsa_disk_free_min_bytes', 512 * MB_IN_BYTES );
		if ( $free < $min ) {
			/* translators: %s: human-readable size */
			return array(
				'status' => 'warn',
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'Only %s free on the WordPress volume.', 'omnihealth-site-auditor' ), size_format( $free, 2 ) ),
			);
		}
		/* translators: %s: human-readable size */
		return array(
			'status' => 'pass',
			// translators: 1: dynamic value
			'detail' => sprintf( __( '%s free on the WordPress volume.', 'omnihealth-site-auditor' ), size_format( $free, 2 ) ),
		);
	}

	/**
	 * Uploads directory is writable.
	 *
	 * @return array
	 */
	public function check_uploads_writable() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return array(
				'status' => 'fail',
				'detail' => (string) $uploads['error'],
			);
		}
		return wp_is_writable( $uploads['basedir'] )
			? array(
				'status' => 'pass',
				'detail' => __( 'Uploads directory is writable.', 'omnihealth-site-auditor' ),
			)
			: array(
				'status' => 'fail',
				'detail' => __( 'Uploads directory is not writable.', 'omnihealth-site-auditor' ),
			);
	}

	/**
	 * PHP memory limit is reasonable.
	 *
	 * @return array
	 */
	public function check_memory_limit() {
		$limit = ini_get( 'memory_limit' );
		$bytes = wp_convert_hr_to_bytes( (string) $limit );
		$min   = (int) apply_filters( 'ohsa_memory_min_bytes', 128 * MB_IN_BYTES );

		if ( $bytes > 0 && $bytes < $min ) {
			/* translators: %s: configured memory limit */
			return array(
				'status' => 'warn',
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'PHP memory_limit is %s.', 'omnihealth-site-auditor' ), $limit ),
			);
		}
		/* translators: %s: configured memory limit */
		return array(
			'status' => 'pass',
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'PHP memory_limit is %s.', 'omnihealth-site-auditor' ), $limit ),
		);
	}

	/**
	 * Persistent object cache is connected (set/get round-trip).
	 *
	 * @return array
	 */
	public function check_object_cache() {
		if ( ! wp_using_ext_object_cache() ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'No persistent object cache is in use.', 'omnihealth-site-auditor' ),
			);
		}
		$key   = 'ohsa_probe';
		$value = 'v' . time();
		wp_cache_set( $key, $value, 'omnihealth-site-auditor', 30 );
		$got = wp_cache_get( $key, 'omnihealth-site-auditor' );
		wp_cache_delete( $key, 'omnihealth-site-auditor' );

		return ( $got === $value )
			? array(
				'status' => 'pass',
				'detail' => __( 'Persistent object cache round-trip OK.', 'omnihealth-site-auditor' ),
			)
			: array(
				'status' => 'fail',
				'detail' => __( 'Object cache enabled but the round-trip failed.', 'omnihealth-site-auditor' ),
			);
	}

	/**
	 * PHP version is within a security-supported range.
	 *
	 * @return array
	 */
	public function check_php_version() {
		$version     = PHP_VERSION;
		$major_minor = implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );

		// Official PHP End-Of-Life dates (YYYY-MM-DD).
		$eol_dates = array(
			'7.4' => '2022-11-28',
			'8.0' => '2023-11-26',
			'8.1' => '2024-12-31',
			'8.2' => '2025-12-31',
			'8.3' => '2026-12-31',
			'8.4' => '2027-12-31',
		);

		/**
		 * Filter the number of months before EOL to warn.
		 *
		 * @param int $months Number of months. Default 6.
		 */
		$horizon_months = (int) apply_filters( 'ohsa_php_eol_horizon_months', 6 );

		if ( ! isset( $eol_dates[ $major_minor ] ) ) {
			if ( version_compare( $version, '7.4', '<' ) ) {
				return array(
					'status' => 'fail',
					/* translators: %s: PHP version */
					// translators: 1: dynamic value
					'detail' => sprintf( __( 'PHP %s is end-of-life — upgrade immediately.', 'omnihealth-site-auditor' ), $version ),
				);
			}

			return array(
				'status' => 'pass',
				/* translators: %s: PHP version */
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'PHP %s is actively supported.', 'omnihealth-site-auditor' ), $version ),
			);
		}

		$eol_time = strtotime( $eol_dates[ $major_minor ] );
		$now      = time();

		if ( $now > $eol_time ) {
			return array(
				'status' => 'fail',
				/* translators: %s: PHP version */
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'PHP %s is end-of-life — upgrade.', 'omnihealth-site-auditor' ), $version ),
			);
		}

		// Calculate horizon (approx 30 days per month).
		$horizon_time = $eol_time - ( $horizon_months * 30 * DAY_IN_SECONDS );
		if ( $now > $horizon_time ) {
			return array(
				'status' => 'warn',
				/* translators: %s: PHP version */
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'PHP %s is nearing its end-of-life date — plan an upgrade.', 'omnihealth-site-auditor' ), $version ),
			);
		}

		return array(
			'status' => 'pass',
			/* translators: %s: PHP version */
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'PHP %s is fully supported.', 'omnihealth-site-auditor' ), $version ),
		);
	}

	// =======================================================================
	// Deep-audit probes (not covered by WordPress core Site Health).
	// =======================================================================

	/**
	 * The .env file (if any) must not be served over HTTP.
	 *
	 * @return array
	 */
	public function check_env_file_exposed() {
		$timeout  = (int) apply_filters( 'ohsa_http_timeout', 8 );
		$response = wp_remote_get(
			home_url( '/.env?ohsa=' . time() ),
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Could not probe .env; skipped.', 'omnihealth-site-auditor' ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 401, 403, 404 ), true ) ) {
			/* translators: %d: HTTP status code */
			return array(
				'status' => 'pass',
				// translators: 1: dynamic value
				'detail' => sprintf( __( '.env is not served (HTTP %d).', 'omnihealth-site-auditor' ), $code ),
			);
		}
		if ( 200 === $code ) {
			$body = (string) wp_remote_retrieve_body( $response );
			if ( false !== stripos( $body, 'DB_' ) || false !== stripos( $body, 'SECRET' ) || false !== stripos( $body, 'PASSWORD' ) ) {
				return array(
					'status' => 'fail',
					'detail' => __( '.env appears publicly readable and may expose secrets — block it now.', 'omnihealth-site-auditor' ),
				);
			}
			return array(
				'status' => 'warn',
				'detail' => __( '.env returns HTTP 200 — verify it is not exposing secrets.', 'omnihealth-site-auditor' ),
			);
		}
		/* translators: %d: HTTP status code */
		return array(
			'status' => 'pass',
			// translators: 1: dynamic value
			'detail' => sprintf( __( '.env returns HTTP %d.', 'omnihealth-site-auditor' ), $code ),
		);
	}

	/**
	 * Scan the web-root TOP LEVEL for stray backup/diagnostic files. Pattern-
	 * matched (not a blanket listing) and never reads contents; secret-bearing
	 * backups (.env.* / wp-config.*) escalate to fail.
	 *
	 * @return array
	 */
	public function check_stray_files() {
		$root = untrailingslashit( ABSPATH );
		if ( ! is_dir( $root ) || ! is_readable( $root ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Web root not readable for scan; skipped.', 'omnihealth-site-auditor' ),
			);
		}
		$entries = scandir( $root );
		if ( ! is_array( $entries ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Web-root scan unavailable; skipped.', 'omnihealth-site-auditor' ),
			);
		}
		$safe     = array( '.env.example', '.env.sample', '.env.dist', 'wp-config-sample.php' );
		$patterns = array(
			'/^wp-config\..+\.php$/i',
			'/^\.env\..+$/i',
			'/\.(bak|old|orig|save|swp|swo|sql|tar|tgz|zip|gz|copy|tmp)$/i',
			'/^(phpinfo|info|adminer|shell|wso|c99)\.php$/i',
		);
		$hits     = array();
		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( in_array( strtolower( $name ), $safe, true ) ) {
				continue;
			}
			if ( ! is_file( $root . '/' . $name ) ) {
				continue;
			}
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $name ) ) {
					$hits[] = $name;
					break;
				}
			}
		}
		$count = count( $hits );
		if ( 0 === $count ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'No stray backup/diagnostic files in the web root.', 'omnihealth-site-auditor' ),
			);
		}
		$secret = (bool) array_filter(
			$hits,
			static function ( $name ) {
				return preg_match( '/^(\.env\.|wp-config\.)/i', $name );
			}
		);
		$sample = implode( ', ', array_slice( $hits, 0, 6 ) );
		if ( $secret ) {
			/* translators: 1: count, 2: sample of file names */
			return array(
				'status' => 'fail',
				// translators: 1: dynamic value
				'detail' => sprintf( __( '%1$d stray file(s) including secret-bearing backups: %2$s', 'omnihealth-site-auditor' ), $count, $sample ),
			);
		}
		/* translators: 1: count, 2: sample of file names */
		return array(
			'status' => 'warn',
			// translators: 1: dynamic value
			'detail' => sprintf( __( '%1$d stray file(s) in the web root: %2$s', 'omnihealth-site-auditor' ), $count, $sample ),
		);
	}

	/**
	 * TLS certificate days-to-expiry (core only checks that HTTPS works today).
	 *
	 * @return array
	 */
	public function check_ssl_cert_expiry() {
		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		if ( 'https' !== $scheme ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Site is not using HTTPS; skipped TLS expiry check.', 'omnihealth-site-auditor' ),
			);
		}
		if ( ! $host || ! function_exists( 'stream_socket_client' ) || ! extension_loaded( 'openssl' ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'TLS inspection unavailable; skipped.', 'omnihealth-site-auditor' ),
			);
		}
		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
				),
			)
		);
		// Outbound TLS handshake to read the peer certificate's expiry only.
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$client = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $context );
		if ( ! $client ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'Could not open a TLS connection to read the certificate.', 'omnihealth-site-auditor' ),
			);
		}
		$params = stream_context_get_params( $client );
		fclose( $client ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( empty( $params['options']['ssl']['peer_certificate'] ) ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'Could not capture the TLS certificate.', 'omnihealth-site-auditor' ),
			);
		}
		$cert = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
		if ( empty( $cert['validTo_time_t'] ) ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'Could not parse the certificate expiry.', 'omnihealth-site-auditor' ),
			);
		}
		$days = (int) floor( ( (int) $cert['validTo_time_t'] - time() ) / DAY_IN_SECONDS );
		$warn = (int) apply_filters( 'ohsa_ssl_warn_days', 14 );
		$fail = (int) apply_filters( 'ohsa_ssl_fail_days', 5 );
		if ( $days < 0 ) {
			return array(
				'status' => 'fail',
				'detail' => __( 'TLS certificate has expired.', 'omnihealth-site-auditor' ),
			);
		}
		if ( $days <= $fail ) {
			/* translators: %d: days */
			return array(
				'status' => 'fail',
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'TLS certificate expires in %d days — renew now.', 'omnihealth-site-auditor' ), $days ),
			);
		}
		if ( $days <= $warn ) {
			/* translators: %d: days */
			return array(
				'status' => 'warn',
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'TLS certificate expires in %d days.', 'omnihealth-site-auditor' ), $days ),
			);
		}
		/* translators: %d: days */
		return array(
			'status' => 'pass',
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'TLS certificate valid for %d more days.', 'omnihealth-site-auditor' ), $days ),
		);
	}

	/**
	 * Baseline security headers present on the homepage response.
	 *
	 * @return array
	 */
	public function check_security_headers() {
		$timeout  = (int) apply_filters( 'ohsa_http_timeout', 8 );
		$response = wp_remote_get( home_url( '/?ohsa=' . time() ), array( 'timeout' => $timeout ) );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Could not fetch the homepage (loopback/local environment issue); skipped.', 'omnihealth-site-auditor' ),
			);
		}
		$want    = array(
			'strict-transport-security' => 'HSTS',
			'x-content-type-options'    => 'X-Content-Type-Options',
			'x-frame-options'           => 'X-Frame-Options',
			'referrer-policy'           => 'Referrer-Policy',
		);
		$missing = array();
		foreach ( $want as $header => $label ) {
			if ( ! wp_remote_retrieve_header( $response, $header ) ) {
				$missing[] = $label;
			}
		}
		if ( empty( $missing ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'All baseline security headers are present.', 'omnihealth-site-auditor' ),
			);
		}
		/* translators: %s: comma-separated header names */
		return array(
			'status' => 'warn',
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'Missing security headers: %s', 'omnihealth-site-auditor' ), implode( ', ', $missing ) ),
		);
	}

	/**
	 * HTTP requests are redirected to HTTPS.
	 *
	 * @return array
	 */
	public function check_https_forced() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'No host to check.', 'omnihealth-site-auditor' ),
			);
		}
		if ( 0 !== stripos( (string) home_url(), 'https://' ) ) {
			return array(
				'status' => 'fail',
				'detail' => __( 'Site address is not HTTPS.', 'omnihealth-site-auditor' ),
			);
		}
		$timeout  = (int) apply_filters( 'ohsa_http_timeout', 8 );
		$response = wp_remote_get(
			'http://' . $host . '/?ohsa=' . time(),
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Site is HTTPS; the HTTP probe was inconclusive.', 'omnihealth-site-auditor' ),
			);
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( in_array( $code, array( 301, 302, 307, 308 ), true ) && 0 === stripos( $location, 'https://' ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'HTTP requests redirect to HTTPS.', 'omnihealth-site-auditor' ),
			);
		}
		if ( 200 === $code ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'HTTP serves 200 without redirecting to HTTPS.', 'omnihealth-site-auditor' ),
			);
		}
		/* translators: %d: HTTP status code */
		return array(
			'status' => 'pass',
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'HTTP returns %d (no plain-HTTP content).', 'omnihealth-site-auditor' ), $code ),
		);
	}

	/**
	 * Whether xmlrpc.php is an open attack surface.
	 *
	 * @return array
	 */
	public function check_xmlrpc_status() {
		$timeout  = (int) apply_filters( 'ohsa_http_timeout', 8 );
		$response = wp_remote_get(
			home_url( '/xmlrpc.php' ),
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'xmlrpc.php probe inconclusive.', 'omnihealth-site-auditor' ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( in_array( $code, array( 401, 403, 404 ), true ) ) {
			/* translators: %d: HTTP status code */
			return array(
				'status' => 'pass',
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'xmlrpc.php is blocked (HTTP %d).', 'omnihealth-site-auditor' ), $code ),
			);
		}
		if ( 405 === $code || false !== stripos( $body, 'XML-RPC server accepts POST' ) ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'xmlrpc.php is enabled — an attack surface for brute-force amplification; disable if unused.', 'omnihealth-site-auditor' ),
			);
		}
		/* translators: %d: HTTP status code */
		return array(
			'status' => 'pass',
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'xmlrpc.php returns HTTP %d.', 'omnihealth-site-auditor' ), $code ),
		);
	}

	/**
	 * No user literally named "admin" (the top brute-force target).
	 *
	 * @return array
	 */
	public function check_admin_username() {
		$user = get_user_by( 'login', 'admin' );
		if ( ! $user ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'No user with the login "admin".', 'omnihealth-site-auditor' ),
			);
		}
		return array(
			'status' => 'warn',
			'detail' => __( 'A user named "admin" exists — a top brute-force target; rename it.', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Database bloat: expired transients, post revisions, spam/trash comments.
	 *
	 * @return array
	 */
	public function check_db_overhead() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$revisions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$spam = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')" );

		$issues = array();
		if ( $expired > (int) apply_filters( 'ohsa_max_expired_transients', 200 ) ) {
			/* translators: %d: count */
			// translators: 1: dynamic value
			$issues[] = sprintf( __( '%d expired transients', 'omnihealth-site-auditor' ), $expired );
		}
		if ( $revisions > (int) apply_filters( 'ohsa_max_revisions', 500 ) ) {
			/* translators: %d: count */
			// translators: 1: dynamic value
			$issues[] = sprintf( __( '%d post revisions', 'omnihealth-site-auditor' ), $revisions );
		}
		if ( $spam > (int) apply_filters( 'ohsa_max_spam_comments', 500 ) ) {
			/* translators: %d: count */
			// translators: 1: dynamic value
			$issues[] = sprintf( __( '%d spam/trash comments', 'omnihealth-site-auditor' ), $spam );
		}
		if ( empty( $issues ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Database is tidy.', 'omnihealth-site-auditor' ),
			);
		}
		/* translators: %s: comma-separated list of issues */
		return array(
			'status' => 'warn',
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'Cleanup recommended: %s.', 'omnihealth-site-auditor' ), implode( ', ', $issues ) ),
		);
	}

	/**
	 * A recent backup exists. Backup-agnostic: UpdraftPlus is read directly, but
	 * ANY backup plugin or host integration can report its last successful backup
	 * via the `ohsa_last_backup_timestamp` filter, so this works the same with or
	 * without UpdraftPlus (or with host-level / off-site backups).
	 *
	 * @return array
	 */
	public function check_backup_recency() {
		$last_ts = 0;

		// Built-in provider: UpdraftPlus stores its own last-backup option.
		$ud = get_option( 'updraft_last_backup' );
		if ( is_array( $ud ) && ! empty( $ud['backup_time'] ) ) {
			$last_ts = (int) $ud['backup_time'];
		}

		/**
		 * Filter the UNIX timestamp of the last successful backup. Return 0/false
		 * if unknown. Lets any backup plugin, host, or off-site service report in
		 * so OmniHealth stays backup-agnostic.
		 *
		 * @param int $last_ts Last-backup UNIX timestamp (0 if none detected yet).
		 */
		$last_ts = (int) apply_filters( 'ohsa_last_backup_timestamp', $last_ts );

		if ( $last_ts > 0 ) {
			$age_days = (int) floor( ( time() - $last_ts ) / DAY_IN_SECONDS );
			$warn     = (int) apply_filters( 'ohsa_backup_warn_days', 7 );
			$fail     = (int) apply_filters( 'ohsa_backup_fail_days', 14 );
			if ( $age_days > $fail ) {
				/* translators: %d: days */
				return array(
					'status' => 'fail',
					// translators: 1: dynamic value
					'detail' => sprintf( __( 'Last backup was %d days ago.', 'omnihealth-site-auditor' ), $age_days ),
				);
			}
			if ( $age_days > $warn ) {
				/* translators: %d: days */
				return array(
					'status' => 'warn',
					// translators: 1: dynamic value
					'detail' => sprintf( __( 'Last backup was %d days ago.', 'omnihealth-site-auditor' ), $age_days ),
				);
			}
			/* translators: %d: days */
			return array(
				'status' => 'pass',
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'Last backup was %d days ago.', 'omnihealth-site-auditor' ), $age_days ),
			);
		}

		// No timestamp known — is a recognised backup plugin at least active?
		/**
		 * Filter the list of backup-plugin basenames recognised by presence
		 * (plugin_file path relative to the plugins dir).
		 *
		 * @param string[] $plugins Known backup-plugin basenames.
		 */
		$known = (array) apply_filters(
			'ohsa_backup_plugins',
			array(
				'updraftplus/updraftplus.php',
				'backwpup/backwpup.php',
				'backwpup-pro/backwpup.php',
				'duplicator/duplicator.php',
				'duplicator-pro/duplicator-pro.php',
				'backupbuddy/backupbuddy.php',
				'wp-time-capsule/wp-time-capsule.php',
				'blogvault-real-time-backup/blogvault.php',
				'backupwordpress/backupwordpress.php',
				'wpvivid-backuprestore/wpvivid-backuprestore.php',
				'jetpack/jetpack.php',
			)
		);

		// Cover both single-site and network-activated plugins (multisite-safe).
		$active  = (array) get_option( 'active_plugins', array() );
		$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		$active  = array_merge( $active, $network );

		foreach ( $known as $plugin ) {
			if ( in_array( $plugin, $active, true ) ) {
				return array(
					'status' => 'warn',
					'detail' => __( 'A backup plugin is active but no recent backup time could be read — verify its schedule, or report it via the ohsa_last_backup_timestamp filter.', 'omnihealth-site-auditor' ),
				);
			}
		}
		return array(
			'status' => 'warn',
			'detail' => __( 'No backup plugin detected — automated backups may not be configured (host-level backups can be reported via the ohsa_last_backup_timestamp filter).', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Sending domain has SPF + DMARC TXT records (DKIM is selector-specific).
	 *
	 * @return array
	 */
	public function check_email_dns() {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'DNS lookups unavailable; skipped.', 'omnihealth-site-auditor' ),
			);
		}
		$domain = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$domain = preg_replace( '/^www\./i', '', $domain );
		/** Filter the domain whose SPF/DMARC records are checked. */
		$domain = (string) apply_filters( 'ohsa_sending_domain', $domain );
		if ( '' === $domain ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'No sending domain to check.', 'omnihealth-site-auditor' ),
			);
		}

		$missing = array();
		$txt     = @dns_get_record( $domain, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$has_spf = false;
		if ( is_array( $txt ) ) {
			foreach ( $txt as $record ) {
				if ( ! empty( $record['txt'] ) && 0 === stripos( $record['txt'], 'v=spf1' ) ) {
					$has_spf = true;
					break;
				}
			}
		}
		if ( ! $has_spf ) {
			$missing[] = 'SPF';
		}
		$dmarc     = @dns_get_record( '_dmarc.' . $domain, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$has_dmarc = false;
		if ( is_array( $dmarc ) ) {
			foreach ( $dmarc as $record ) {
				if ( ! empty( $record['txt'] ) && false !== stripos( $record['txt'], 'v=DMARC1' ) ) {
					$has_dmarc = true;
					break;
				}
			}
		}
		if ( ! $has_dmarc ) {
			$missing[] = 'DMARC';
		}
		if ( empty( $missing ) ) {
			/* translators: %s: domain */
			return array(
				'status' => 'pass',
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'SPF and DMARC present for %s.', 'omnihealth-site-auditor' ), $domain ),
			);
		}
		/* translators: 1: missing records, 2: domain */
		return array(
			'status' => 'warn',
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'Missing %1$s for %2$s — email may be marked as spam.', 'omnihealth-site-auditor' ), implode( ' + ', $missing ), $domain ),
		);
	}

	/**
	 * The front page is actually indexable (not noindex, search visibility on).
	 *
	 * @return array
	 */
	public function check_homepage_indexable() {
		if ( '1' !== (string) get_option( 'blog_public' ) ) {
			return array(
				'status' => 'fail',
				'detail' => __( 'Search engine visibility is OFF — the whole site is set to noindex.', 'omnihealth-site-auditor' ),
			);
		}
		$timeout  = (int) apply_filters( 'ohsa_http_timeout', 8 );
		$response = wp_remote_get(
			home_url( '/?ohsa=' . time() ),
			array(
				'timeout'     => $timeout,
				'redirection' => 2,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'Could not fetch the homepage to verify indexability.', 'omnihealth-site-auditor' ),
			);
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']*)["\']/i', $body, $m ) && false !== stripos( $m[1], 'noindex' ) ) {
			return array(
				'status' => 'fail',
				'detail' => __( 'The homepage emits a noindex robots meta tag.', 'omnihealth-site-auditor' ),
			);
		}
		$x_robots = (string) wp_remote_retrieve_header( $response, 'x-robots-tag' );
		if ( $x_robots && false !== stripos( $x_robots, 'noindex' ) ) {
			return array(
				'status' => 'fail',
				'detail' => __( 'The homepage sends a noindex X-Robots-Tag header.', 'omnihealth-site-auditor' ),
			);
		}
		return array(
			'status' => 'pass',
			'detail' => __( 'Homepage is indexable.', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Non-core tables for this prefix — surfaces leftovers from removed plugins.
	 * Low-noise: informational (pass) up to a filterable threshold; excludes
	 * other-blog tables on multisite and anything allow-listed via
	 * `ohsa_known_tables`.
	 *
	 * @return array
	 */
	public function check_orphaned_tables() {
		global $wpdb;

		$prefix = $wpdb->prefix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		if ( empty( $all ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'No tables found for this prefix; skipped.', 'omnihealth-site-auditor' ),
			);
		}

		$expected = array(
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->options,
			$wpdb->users,
			$wpdb->usermeta,
			$wpdb->terms,
			$wpdb->term_taxonomy,
			$wpdb->term_relationships,
			$wpdb->termmeta,
			$wpdb->comments,
			$wpdb->commentmeta,
		);
		if ( is_multisite() ) {
			foreach ( array( 'blogs', 'blogmeta', 'signups', 'site', 'sitemeta', 'registration_log' ) as $prop ) {
				if ( ! empty( $wpdb->$prop ) ) {
					$expected[] = $wpdb->$prop;
				}
			}
		}
		/**
		 * Filter full table names to treat as expected (e.g. active-plugin tables).
		 *
		 * @param string[] $known Full table names to exclude from the orphan list.
		 */
		$expected = array_merge( $expected, (array) apply_filters( 'ohsa_known_tables', array() ) );

		$orphans = array();
		foreach ( $all as $table ) {
			if ( in_array( $table, $expected, true ) ) {
				continue;
			}
			// On multisite, skip other blogs' tables (prefix + digits + "_").
			if ( is_multisite() && preg_match( '/^' . preg_quote( $prefix, '/' ) . '\d+_/', $table ) ) {
				continue;
			}
			$orphans[] = $table;
		}

		$count = count( $orphans );
		if ( 0 === $count ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'No non-core tables for this prefix.', 'omnihealth-site-auditor' ),
			);
		}

		$sample    = implode( ', ', array_slice( $orphans, 0, 8 ) );
		$threshold = (int) apply_filters( 'ohsa_orphan_tables_warn', 40 );
		if ( $count > $threshold ) {
			/* translators: 1: count, 2: sample table names */
			return array(
				'status' => 'warn',
				// translators: 1: dynamic value
				'detail' => sprintf( __( '%1$d non-core tables (sample: %2$s) — review for leftovers from removed plugins; allow-list expected ones via the ohsa_known_tables filter.', 'omnihealth-site-auditor' ), $count, $sample ),
			);
		}
		/* translators: 1: count, 2: sample table names */
		return array(
			'status' => 'pass',
			// translators: 1: dynamic value
			'detail' => sprintf( __( '%1$d non-core tables present (likely active plugins): %2$s', 'omnihealth-site-auditor' ), $count, $sample ),
		);
	}

	/**
	 * WordPress core has no pending update. A same-branch (maintenance/security)
	 * update is a fail; a feature (major) update is a warn.
	 *
	 * @return array
	 */
	public function check_core_update_available() {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$updates = function_exists( 'get_core_updates' ) ? get_core_updates() : false;
		if ( empty( $updates ) || ! is_array( $updates ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'WordPress core is up to date (or no update data yet).', 'omnihealth-site-auditor' ),
			);
		}

		$offer = null;
		foreach ( $updates as $update ) {
			if ( isset( $update->response ) && 'upgrade' === $update->response ) {
				$offer = $update;
				break;
			}
		}
		if ( null === $offer ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'WordPress core is up to date.', 'omnihealth-site-auditor' ),
			);
		}

		$current = get_bloginfo( 'version' );
		$offered = '';
		if ( isset( $offer->current ) ) {
			$offered = (string) $offer->current;
		} elseif ( isset( $offer->version ) ) {
			$offered = (string) $offer->version;
		}

		$cur_branch = implode( '.', array_slice( explode( '.', $current ), 0, 2 ) );
		$off_branch = '' !== $offered ? implode( '.', array_slice( explode( '.', $offered ), 0, 2 ) ) : '';

		if ( '' !== $offered && $cur_branch === $off_branch ) {
			/* translators: 1: current version, 2: offered version */
			return array(
				'status' => 'fail',
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'A WordPress maintenance/security update is available (%1$s → %2$s) — apply it promptly.', 'omnihealth-site-auditor' ), $current, $offered ),
			);
		}
		/* translators: 1: current version, 2: offered version */
		return array(
			'status' => 'warn',
			/* translators: 1: current version, 2: new version */
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'A WordPress feature update is available (%1$s → %2$s).', 'omnihealth-site-auditor' ), $current, '' !== $offered ? $offered : __( 'newer', 'omnihealth-site-auditor' ) ),
		);
	}

	/**
	 * Count of plugins with a pending update (read-only; reads the update cache).
	 *
	 * @return array
	 */
	public function check_plugin_updates_pending() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$updates = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : array();
		$count   = is_array( $updates ) ? count( $updates ) : 0;

		if ( 0 === $count ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'All plugins are up to date.', 'omnihealth-site-auditor' ),
			);
		}
		return array(
			'status' => 'warn',
			/* translators: %d: number of plugins */
			// translators: 1: dynamic value
			'detail' => sprintf( _n( '%d plugin update is pending.', '%d plugin updates are pending.', $count, 'omnihealth-site-auditor' ), $count ),
		);
	}

	/**
	 * User enumeration is not trivially exposed via `?author=N` redirects or the
	 * anonymous REST users endpoint.
	 *
	 * @return array
	 */
	public function check_user_enumeration_blocked() {
		$timeout = (int) apply_filters( 'ohsa_http_timeout', 8 );
		$issues  = array();

		$author = wp_remote_get(
			home_url( '/?author=1' ),
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
			)
		);
		if ( ! is_wp_error( $author ) ) {
			$code     = (int) wp_remote_retrieve_response_code( $author );
			$location = (string) wp_remote_retrieve_header( $author, 'location' );
			if ( in_array( $code, array( 301, 302 ), true ) && false !== stripos( $location, '/author/' ) ) {
				$issues[] = __( '?author=N reveals usernames', 'omnihealth-site-auditor' );
			}
		}

		$rest = wp_remote_get( home_url( '/wp-json/wp/v2/users' ), array( 'timeout' => $timeout ) );
		if ( ! is_wp_error( $rest ) && 200 === (int) wp_remote_retrieve_response_code( $rest ) ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $rest ), true );
			if ( is_array( $body ) && ! empty( $body ) && isset( $body[0]['slug'] ) ) {
				$issues[] = __( 'the REST users endpoint lists accounts', 'omnihealth-site-auditor' );
			}
		}

		if ( empty( $issues ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'User enumeration is not trivially exposed.', 'omnihealth-site-auditor' ),
			);
		}
		/* translators: %s: semicolon-separated list of exposure vectors */
		return array(
			'status' => 'warn',
			/* translators: %s: list of enumeration issues found */
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'User enumeration possible: %s.', 'omnihealth-site-auditor' ), implode( '; ', $issues ) ),
		);
	}

	/**
	 * A .env file on disk must not exist in a web-served/readable location. This
	 * complements check_env_file_exposed() (which probes over HTTP) by catching
	 * the file directly — useful in CLI/headless contexts.
	 *
	 * @return array
	 */
	public function check_env_file_on_disk() {
		// Fixed candidate paths only (no user input): web root + one level up.
		$candidates = array(
			ABSPATH . '.env',
			trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . '.env',
		);

		$found = array();
		foreach ( array_unique( $candidates ) as $path ) {
			if ( is_file( $path ) ) {
				$found[] = $path;
			}
		}

		if ( empty( $found ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'No .env file found on disk.', 'omnihealth-site-auditor' ),
			);
		}

		foreach ( $found as $path ) {
			$perms = fileperms( $path );
			if ( false !== $perms && ( $perms & 0004 ) ) { // others-read bit.
				return array(
					'status' => 'fail',
					'detail' => __( 'A .env file exists and is world-readable — set it to 0600/0640 and keep it out of the web root.', 'omnihealth-site-auditor' ),
				);
			}
		}

		return array(
			'status' => 'warn',
			'detail' => __( 'A .env file exists on disk — ensure it is outside the web root and not served (see the .env HTTP probe).', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * wp-config.php must not be world-readable.
	 *
	 * @return array
	 */
	public function check_wp_config_permissions() {
		$path = ABSPATH . 'wp-config.php';
		if ( ! is_file( $path ) ) {
			// WordPress also supports wp-config.php one directory above ABSPATH.
			$alt  = trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . 'wp-config.php';
			$path = is_file( $alt ) ? $alt : '';
		}
		if ( '' === $path ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'wp-config.php not found in a standard location; skipped.', 'omnihealth-site-auditor' ),
			);
		}

		$perms = fileperms( $path );
		if ( false === $perms ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Could not read wp-config.php permissions; skipped.', 'omnihealth-site-auditor' ),
			);
		}
		$mode = $perms & 0777;

		if ( $mode & 0004 ) { // others-read bit.
			return array(
				'status' => 'fail',
				/* translators: %o: octal file mode */
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'wp-config.php is world-readable (%o) — set 0640 or 0600.', 'omnihealth-site-auditor' ), $mode ),
			);
		}

		/* translators: %o: octal file mode */
		return array(
			'status' => 'pass',
			/* translators: %o: file permissions */
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'wp-config.php permissions are restrictive (%o).', 'omnihealth-site-auditor' ), $mode ),
		);
	}

	/**
	 * Every base WordPress table exists (plus the network tables on multisite).
	 *
	 * @return array
	 */
	public function check_core_tables_present() {
		global $wpdb;

		$expected = array(
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->options,
			$wpdb->users,
			$wpdb->usermeta,
			$wpdb->terms,
			$wpdb->term_taxonomy,
			$wpdb->term_relationships,
			$wpdb->termmeta,
			$wpdb->comments,
			$wpdb->commentmeta,
		);

		if ( is_multisite() ) {
			foreach ( array( 'blogs', 'blogmeta', 'signups', 'site', 'sitemeta', 'registration_log' ) as $prop ) {
				if ( ! empty( $wpdb->$prop ) ) {
					$expected[] = $wpdb->$prop;
				}
			}
		}

		$missing = array();
		foreach ( array_unique( $expected ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				$missing[] = $table;
			}
		}

		if ( empty( $missing ) ) {
			/* translators: %d: number of tables */
			return array(
				'status' => 'pass',
				/* translators: %d: number of core tables */
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'All %d core database tables are present.', 'omnihealth-site-auditor' ), count( $expected ) ),
			);
		}

		/* translators: %s: comma-separated table names */
		return array(
			'status' => 'fail',
			/* translators: %s: list of missing core tables */
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'Missing core database tables: %s', 'omnihealth-site-auditor' ), implode( ', ', $missing ) ),
		);
	}

	/**
	 * Resolve the largest readable error log from WP-standard locations only.
	 *
	 * No user input ever reaches the filesystem functions — the candidate list
	 * is fixed/server-derived (strict control for PCP).
	 *
	 * @return string Absolute path, or '' when none found.
	 */
	private function resolve_log_path() {
		$candidates = array();
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			$candidates[] = WP_DEBUG_LOG;
		}
		$candidates[] = WP_CONTENT_DIR . '/debug.log';
		$ini          = ini_get( 'error_log' );
		if ( is_string( $ini ) && '' !== $ini && 'syslog' !== $ini ) {
			$candidates[] = $ini;
		}
		$candidates[] = ABSPATH . 'error_log';

		$best      = '';
		$best_size = -1;
		foreach ( array_unique( $candidates ) as $path ) {
			if ( @is_file( $path ) && @is_readable( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$size = (int) filesize( $path );
				if ( $size > $best_size ) {
					$best_size = $size;
					$best      = $path;
				}
			}
		}
		return $best;
	}
	/**
	 * Check if secret keys are properly defined.
	 *
	 * @return array
	 */
	public function check_secret_keys_defined() {
		$keys    = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
		$missing = array();
		$default = array();

		foreach ( $keys as $key ) {
			if ( ! defined( $key ) ) {
				$missing[] = $key;
			} elseif ( 'put your unique phrase here' === constant( $key ) ) {
				$default[] = $key;
			}
		}

		if ( ! empty( $missing ) || ! empty( $default ) ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'One or more WordPress secret keys are missing or using the default placeholder in wp-config.php.', 'omnihealth-site-auditor' ),
			);
		}

		return array(
			'status' => 'pass',
			'detail' => __( 'All secret keys are defined securely.', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Check if file editing is disabled.
	 *
	 * @return array
	 */
	public function check_file_editing_disabled() {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'File editing is disabled via wp-config.php.', 'omnihealth-site-auditor' ),
			);
		}

		return array(
			'status' => 'warn',
			'detail' => __( 'File editing is currently allowed. Consider defining DISALLOW_FILE_EDIT to true.', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Check if directory listing is disabled.
	 *
	 * @return array
	 */
	public function check_directory_listing_off() {
		$upload_dir = wp_upload_dir();
		$url        = trailingslashit( $upload_dir['baseurl'] );
		$response   = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Could not reach the uploads directory to test listing.', 'omnihealth-site-auditor' ),
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( stripos( $body, 'Index of /' ) !== false ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'Directory listing appears to be enabled on your uploads folder.', 'omnihealth-site-auditor' ),
			);
		}

		return array(
			'status' => 'pass',
			'detail' => __( 'Directory listing is safely disabled.', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Check if FORCE_SSL_ADMIN is true on HTTPS sites.
	 *
	 * @return array
	 */
	public function check_force_ssl_admin() {
		if ( strpos( home_url(), 'https://' ) !== 0 ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Site does not use HTTPS, so FORCE_SSL_ADMIN is not applicable.', 'omnihealth-site-auditor' ),
			);
		}

		if ( defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'FORCE_SSL_ADMIN is properly enabled.', 'omnihealth-site-auditor' ),
			);
		}

		return array(
			'status' => 'warn',
			'detail' => __( 'Your site uses HTTPS but FORCE_SSL_ADMIN is not enabled in wp-config.php.', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Check database table storage engine.
	 *
	 * @return array
	 */
	public function check_table_storage_engine() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_results( "SHOW TABLE STATUS WHERE Engine != 'InnoDB' AND Engine IS NOT NULL" );

		if ( ! empty( $tables ) ) {
			/* translators: %d: number of tables */
			return array(
				'status' => 'warn',
				/* translators: %d: number of tables */
				// translators: 1: dynamic value
				'detail' => sprintf( __( '%d tables are not using the InnoDB storage engine.', 'omnihealth-site-auditor' ), count( $tables ) ),
			);
		}

		return array(
			'status' => 'pass',
			'detail' => __( 'All database tables are using the InnoDB storage engine.', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Check database table collation.
	 *
	 * @return array
	 */
	public function check_table_collation() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_results( "SHOW TABLE STATUS WHERE Collation NOT LIKE 'utf8mb4%'" );

		if ( ! empty( $tables ) ) {
			/* translators: %d: number of tables */
			return array(
				'status' => 'warn',
				/* translators: %d: number of tables */
				// translators: 1: dynamic value
				'detail' => sprintf( __( '%d tables are not using the recommended utf8mb4 collation.', 'omnihealth-site-auditor' ), count( $tables ) ),
			);
		}

		return array(
			'status' => 'pass',
			'detail' => __( 'All database tables use the recommended utf8mb4 collation.', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Check for pending theme updates.
	 *
	 * @return array
	 */
	public function check_theme_updates_pending() {
		$updates = get_site_transient( 'update_themes' );

		if ( ! empty( $updates->response ) ) {
			$count = count( $updates->response );
			/* translators: %d: number of themes */
			return array(
				'status' => 'warn',
				/* translators: %d: number of themes */
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'You have %d theme(s) with pending updates.', 'omnihealth-site-auditor' ), $count ),
			);
		}

		return array(
			'status' => 'pass',
			'detail' => __( 'All themes are up to date.', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Check for excessive inactive plugins and themes.
	 *
	 * @return array
	 */
	public function check_inactive_plugins_themes() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins  = get_plugins();
		$active   = get_option( 'active_plugins', array() );
		$inactive = count( $plugins ) - count( $active );

		$themes          = wp_get_themes();
		$inactive_themes = count( $themes ) - 1; // Assuming 1 active theme.

		if ( $inactive > 5 || $inactive_themes > 3 ) {
			/* translators: 1: number of plugins, 2: number of themes */
			return array(
				'status' => 'warn',
				/* translators: 1: number of plugins, 2: number of themes */
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'You have %1$d inactive plugins and %2$d inactive themes. Consider removing them to reduce attack surface.', 'omnihealth-site-auditor' ), $inactive, $inactive_themes ),
			);
		}

		return array(
			'status' => 'pass',
			'detail' => __( 'Inactive plugin and theme count is low.', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Check if WP-Cron tasks are overdue.
	 *
	 * @return array
	 */
	public function check_cron_overdue() {
		$crons = _get_cron_array();
		if ( empty( $crons ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'No scheduled events found.', 'omnihealth-site-auditor' ),
			);
		}

		$now     = time();
		$overdue = 0;

		foreach ( $crons as $timestamp => $cronhooks ) {
			if ( $timestamp < ( $now - 1800 ) ) { // 30 minutes overdue
				foreach ( $cronhooks as $hook => $events ) {
					++$overdue;
				}
			}
		}

		if ( $overdue > 0 ) {
			/* translators: %d: number of overdue tasks */
			return array(
				'status' => 'warn',
				/* translators: %d: number of overdue tasks */
				// translators: 1: dynamic value
				'detail' => sprintf( __( '%d scheduled tasks are overdue by more than 30 minutes. WP-Cron may not be running.', 'omnihealth-site-auditor' ), $overdue ),
			);
		}

		return array(
			'status' => 'pass',
			'detail' => __( 'WP-Cron is running normally.', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Check if transients are falling back to DB unexpectedly.
	 *
	 * @return array
	 */
	public function check_transient_api_backed() {
		if ( wp_using_ext_object_cache() ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Persistent object cache is active.', 'omnihealth-site-auditor' ),
			);
		}

		return array(
			'status' => 'pass',
			'detail' => __( 'Object cache is not explicitly enabled, falling back to database safely.', 'omnihealth-site-auditor' ),
		);
	}
	/**
	 * Report the top N largest tables and total DB size.
	 *
	 * @return array
	 */
	public function check_largest_tables() {
		global $wpdb;

		/**
		 * Filter the number of largest tables to report.
		 *
		 * @param int $count Number of tables. Default 5.
		 */
		$count = (int) apply_filters( 'ohsa_largest_tables_count', 5 );

		/**
		 * Filter the warning threshold for total database size in MB.
		 *
		 * @param int $threshold Threshold in MB. Default 500.
		 */
		$threshold_mb = (int) apply_filters( 'ohsa_total_db_size_warn_mb', 500 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			'SELECT TABLE_NAME as name, (DATA_LENGTH + INDEX_LENGTH) as size 
			FROM information_schema.TABLES 
			WHERE TABLE_SCHEMA = DATABASE() 
			ORDER BY size DESC'
		);

		if ( empty( $results ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Could not determine table sizes.', 'omnihealth-site-auditor' ),
			);
		}

		$total_bytes = 0;
		$top_tables  = array();

		foreach ( $results as $index => $row ) {
			$size         = (int) $row->size;
			$total_bytes += $size;
			if ( $index < $count ) {
				$top_tables[] = $row->name . ' (' . size_format( $size, 2 ) . ')';
			}
		}

		$total_mb = $total_bytes / 1048576; // 1024 * 1024

		if ( $total_mb > $threshold_mb ) {
			return array(
				'status' => 'warn',
				/* translators: 1: total size, 2: top tables list */
				// translators: 1: dynamic value
				'detail' => sprintf( __( 'Database size is %1$s. Top tables: %2$s', 'omnihealth-site-auditor' ), size_format( $total_bytes, 2 ), implode( ', ', $top_tables ) ),
			);
		}

		return array(
			'status' => 'pass',
			/* translators: 1: total size, 2: top tables list */
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'Database size is %1$s. Top tables: %2$s', 'omnihealth-site-auditor' ), size_format( $total_bytes, 2 ), implode( ', ', $top_tables ) ),
		);
	}

	/**
	 * Check database client character set.
	 *
	 * @return array
	 */
	public function check_db_charset_client() {
		global $wpdb;

		// The charset configured in wp-config.php DB_CHARSET.
		$charset = $wpdb->charset;

		if ( 'utf8mb4' === $charset ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Database connection uses the recommended utf8mb4 charset.', 'omnihealth-site-auditor' ),
			);
		}

		return array(
			'status' => 'warn',
			/* translators: %s: current charset */
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'Database connection uses "%s", but utf8mb4 is recommended.', 'omnihealth-site-auditor' ), $charset ),
		);
	}

	/**
	 * Check for mixed content (HTTP resources on an HTTPS homepage).
	 *
	 * @return array
	 */
	public function check_https_mixed_content() {
		if ( ! is_ssl() && 0 !== strpos( home_url(), 'https://' ) ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'Site does not enforce HTTPS, mixed content check skipped.', 'omnihealth-site-auditor' ),
			);
		}

		$timeout  = (int) apply_filters( 'ohsa_http_timeout', 8 );
		$response = wp_remote_get(
			home_url( '/?ohsa=' . time() ),
			array(
				'timeout'     => $timeout,
				'redirection' => 2,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'Could not fetch the homepage to verify mixed content.', 'omnihealth-site-auditor' ),
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		// Look for src="http:// or href="http://
		if ( preg_match( '/(?:src|href)\s*=\s*["\']http:\/\/[^"\']+["\']/i', $body ) ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'The homepage contains hardcoded HTTP asset references (mixed content).', 'omnihealth-site-auditor' ),
			);
		}

		return array(
			'status' => 'pass',
			'detail' => __( 'No mixed content (HTTP assets) detected on the homepage.', 'omnihealth-site-auditor' ),
		);
	}

	/**
	 * Verify the REST API is reachable (returns HTTP 200).
	 *
	 * @return array
	 */
	public function check_rest_api_reachable() {
		$timeout  = (int) apply_filters( 'ohsa_http_timeout', 8 );
		$response = wp_remote_get(
			rest_url(),
			array(
				'timeout'     => $timeout,
				'redirection' => 2,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'warn',
				'detail' => __( 'The REST API endpoint is unreachable or timing out.', 'omnihealth-site-auditor' ),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $status_code ) {
			return array(
				'status' => 'pass',
				'detail' => __( 'The WordPress REST API is reachable and responding normally.', 'omnihealth-site-auditor' ),
			);
		}

		return array(
			'status' => 'warn',
			/* translators: %d: HTTP status code */
			// translators: 1: dynamic value
			'detail' => sprintf( __( 'The REST API responded with an unexpected status code (%d).', 'omnihealth-site-auditor' ), $status_code ),
		);
	}
}

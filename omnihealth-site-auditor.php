<?php
/**
 * Plugin Name:       OmniHealth: Deep Site Auditor
 * Plugin URI:        https://wordpress.org/plugins/omnihealth-site-auditor/
 * Description:       A headless-first diagnostic engine featuring 22+ proactive probes for performance, security, and DB health — extensible to 48+ via REST API and custom filters.
 * Version:           1.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            OmniHealth Contributors
 * Author URI:        https://merolhack.github.io/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       omnihealth-site-auditor
 * Domain Path:       /languages
 *
 * @package OmniHealthSiteAuditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OHSA_VERSION', '1.2.1' );
define( 'OHSA_PLUGIN_FILE', __FILE__ );
define( 'OHSA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OHSA_CRON_HOOK', 'ohsa_daily_check' );
define( 'OHSA_OPTION_SETTINGS', 'ohsa_settings' );
define( 'OHSA_OPTION_REPORT', 'ohsa_last_report' );
define( 'OHSA_OPTION_TOKEN', 'ohsa_token' );

require_once OHSA_PLUGIN_DIR . 'includes/class-ohsa-engine.php';
require_once OHSA_PLUGIN_DIR . 'includes/class-ohsa-rest.php';
require_once OHSA_PLUGIN_DIR . 'includes/class-ohsa-admin.php';

/**
 * Boot the plugin.
 */
function ohsa_init() {
	// Database Migrations & Versioning
	$db_version = get_option( 'ohsa_db_version', '0.0.0' );
	if ( version_compare( $db_version, OHSA_VERSION, '<' ) ) {
		if ( false === get_option( OHSA_OPTION_SETTINGS ) ) {
			add_option( OHSA_OPTION_SETTINGS, OHSA_Engine::default_settings() );
		}
		if ( ! get_option( OHSA_OPTION_TOKEN ) ) {
			add_option( OHSA_OPTION_TOKEN, bin2hex( random_bytes( 16 ) ) );
		}
		if ( ! wp_next_scheduled( OHSA_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', OHSA_CRON_HOOK );
		}
		update_option( 'ohsa_db_version', OHSA_VERSION );
	}

	$engine = new OHSA_Engine();
	$engine->init();

	( new OHSA_REST( $engine ) )->init();

	if ( is_admin() ) {
		( new OHSA_Admin( $engine ) )->init();

		// GitHub Update Checker (for sideloaded installs)
		if ( file_exists( OHSA_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			require_once OHSA_PLUGIN_DIR . 'vendor/autoload.php';
			if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
				\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
					'https://github.com/merolhack/omnihealth-site-auditor/',
					OHSA_PLUGIN_FILE,
					'omnihealth-site-auditor'
				);
			}
		}
	}

	add_action( OHSA_CRON_HOOK, 'ohsa_run_scheduled_check' );
}
add_action( 'plugins_loaded', 'ohsa_init' );

/**
 * Cron callback: run the checks, store the report, alert on failure.
 */
function ohsa_run_scheduled_check() {
	$engine = new OHSA_Engine();
	$engine->init();
	$report = $engine->run();

	update_option( OHSA_OPTION_REPORT, $report, false );

	if ( isset( $report['verdict'] ) && 'fail' === $report['verdict'] ) {
		ohsa_send_failure_email( $report );
	}
}

/**
 * Email the admin when the verdict is "fail". Plain-text, no external calls.
 *
 * @param array $report Report from OHSA_Engine::run().
 */
function ohsa_send_failure_email( array $report ) {
	$to = apply_filters( 'ohsa_alert_email', get_option( 'admin_email' ) );
	if ( ! is_email( $to ) ) {
		return;
	}

	/* translators: %s: site name */
	$subject = sprintf( __( '[OmniHealth: Deep Site Auditor] Health check FAILED on %s', 'omnihealth-site-auditor' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) );

	$lines   = array();
	$lines[] = sprintf(
		/* translators: 1: pass count, 2: warn count, 3: fail count */
		__( 'Verdict: FAIL (pass: %1$d, warn: %2$d, fail: %3$d)', 'omnihealth-site-auditor' ),
		(int) $report['pass'],
		(int) $report['warn'],
		(int) $report['fail']
	);
	$lines[] = '';

	foreach ( $report['checks'] as $check ) {
		if ( 'fail' === $check['status'] ) {
			$lines[] = '[FAIL] ' . $check['label'] . ' — ' . $check['detail'];
		}
	}
	$lines[] = '';
	$lines[] = admin_url( 'tools.php?page=omnihealth-site-auditor' );

	wp_mail( $to, $subject, implode( "\n", $lines ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
}

register_activation_hook( __FILE__, 'ohsa_activate' );
register_deactivation_hook( __FILE__, 'ohsa_deactivate' );

/**
 * On activation: verify the environment, then schedule the daily cron and seed
 * default settings + a token.
 *
 * This is a lightweight requirements gate — NOT the unit-test suite. The PHPUnit
 * tests run in CI / locally via `composer test`, never on a production server.
 */
function ohsa_activate() {
	// Requirements gate: bail cleanly rather than fataling later.
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'OmniHealth: Deep Site Auditor requires PHP 7.4 or newer. The plugin was not activated.', 'omnihealth-site-auditor' ),
			esc_html__( 'Plugin activation error', 'omnihealth-site-auditor' ),
			array( 'back_link' => true )
		);
	}
	if ( ! class_exists( 'OHSA_Engine' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'OmniHealth: Deep Site Auditor could not load its engine. The plugin was not activated.', 'omnihealth-site-auditor' ),
			esc_html__( 'Plugin activation error', 'omnihealth-site-auditor' ),
			array( 'back_link' => true )
		);
	}

	if ( ! wp_next_scheduled( OHSA_CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', OHSA_CRON_HOOK );
	}

	if ( false === get_option( OHSA_OPTION_SETTINGS ) ) {
		add_option( OHSA_OPTION_SETTINGS, OHSA_Engine::default_settings() );
	}

	if ( ! get_option( OHSA_OPTION_TOKEN ) ) {
		add_option( OHSA_OPTION_TOKEN, bin2hex( random_bytes( 16 ) ) );
	}
}

/**
 * On deactivation: unschedule the cron. (Options are kept until uninstall.)
 */
function ohsa_deactivate() {
	$timestamp = wp_next_scheduled( OHSA_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, OHSA_CRON_HOOK );
	}
}

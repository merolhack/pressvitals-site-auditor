<?php
/**
 * Plugin Name:       PressVitals Site Auditor
 * Plugin URI:        https://wordpress.org/plugins/pressvitals-site-auditor/
 * Description:       A headless-first diagnostic engine featuring 45+ proactive probes for performance, security, and DB health — extensible via REST API and custom filters.
 * Version:           1.4.0
 * Requires at least: 6.0
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            merolhack
 * Author URI:        https://merolhack.github.io/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pressvitals-site-auditor
 * Domain Path:       /languages
 *
 * @package PressVitalsSiteAuditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PVSA_VERSION', '1.4.0' );
define( 'PVSA_PLUGIN_FILE', __FILE__ );
define( 'PVSA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PVSA_CRON_HOOK', 'pvsa_daily_check' );
define( 'PVSA_OPTION_SETTINGS', 'pvsa_settings' );
define( 'PVSA_OPTION_REPORT', 'pvsa_last_report' );
define( 'PVSA_OPTION_TOKEN', 'pvsa_token' );

require_once PVSA_PLUGIN_DIR . 'includes/class-pvsa-engine.php';
require_once PVSA_PLUGIN_DIR . 'includes/class-pvsa-rest.php';
require_once PVSA_PLUGIN_DIR . 'includes/class-pvsa-admin.php';

/**
 * Boot the plugin.
 */
function pvsa_init() {

	// Database Migrations & Versioning
	$db_version = get_option( 'pvsa_db_version', '0.0.0' );
	if ( version_compare( $db_version, PVSA_VERSION, '<' ) ) {
		if ( false === get_option( PVSA_OPTION_SETTINGS ) ) {
			add_option( PVSA_OPTION_SETTINGS, PVSA_Engine::default_settings() );
		}
		if ( ! get_option( PVSA_OPTION_TOKEN ) ) {
			add_option( PVSA_OPTION_TOKEN, bin2hex( random_bytes( 16 ) ) );
		}
		if ( ! wp_next_scheduled( PVSA_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', PVSA_CRON_HOOK );
		}
		update_option( 'pvsa_db_version', PVSA_VERSION );
	}

	$engine = new PVSA_Engine();
	$engine->init();

	( new PVSA_REST( $engine ) )->init();

	if ( is_admin() ) {
		( new PVSA_Admin( $engine ) )->init();
	}

	add_action( PVSA_CRON_HOOK, 'pvsa_run_scheduled_check' );
}
add_action( 'plugins_loaded', 'pvsa_init' );

/**
 * Cron callback: run the checks, store the report, alert on failure.
 */
function pvsa_run_scheduled_check() {
	$engine = new PVSA_Engine();
	$engine->init();
	$report = $engine->run();

	update_option( PVSA_OPTION_REPORT, $report, false );

	if ( isset( $report['verdict'] ) && 'fail' === $report['verdict'] ) {
		pvsa_send_failure_alerts( $report );
	}
}

/**
 * Send failure alerts via email or other channels (webhooks, slack).
 *
 * @param array $report Report from PVSA_Engine::run().
 */
function pvsa_send_failure_alerts( array $report ) {
	$channels = apply_filters( 'pvsa_alert_channels', array( 'email' ) );

	/* translators: %s: site name */
	$subject = sprintf( __( '[PressVitals Site Auditor] Health check FAILED on %s', 'pressvitals-site-auditor' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) );

	$lines   = array();
	$lines[] = sprintf(
		/* translators: 1: pass count, 2: warn count, 3: fail count */
		__( 'Verdict: FAIL (pass: %1$d, warn: %2$d, fail: %3$d)', 'pressvitals-site-auditor' ),
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
	$lines[] = admin_url( 'tools.php?page=pressvitals-site-auditor' );
	$body    = implode( "\n", $lines );

	if ( in_array( 'email', $channels, true ) ) {
		$to = apply_filters( 'pvsa_alert_email', get_option( 'admin_email' ) );
		if ( is_email( $to ) ) {
			wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		}
	}

	// Trigger hook so external plugins can send webhook/Slack alerts
	do_action( 'pvsa_send_alerts', $report, $subject, $body, $channels );
}

register_activation_hook( __FILE__, 'pvsa_activate' );
register_deactivation_hook( __FILE__, 'pvsa_deactivate' );

/**
 * On activation: verify the environment, then schedule the daily cron and seed
 * default settings + a token.
 *
 * This is a lightweight requirements gate — NOT the unit-test suite. The PHPUnit
 * tests run in CI / locally via `composer test`, never on a production server.
 */
function pvsa_activate() {
	// Requirements gate: bail cleanly rather than fataling later.
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'PressVitals Site Auditor requires PHP 7.4 or newer. The plugin was not activated.', 'pressvitals-site-auditor' ),
			esc_html__( 'Plugin activation error', 'pressvitals-site-auditor' ),
			array( 'back_link' => true )
		);
	}
	if ( ! class_exists( 'PVSA_Engine' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'PressVitals Site Auditor could not load its engine. The plugin was not activated.', 'pressvitals-site-auditor' ),
			esc_html__( 'Plugin activation error', 'pressvitals-site-auditor' ),
			array( 'back_link' => true )
		);
	}

	if ( ! wp_next_scheduled( PVSA_CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', PVSA_CRON_HOOK );
	}

	if ( false === get_option( PVSA_OPTION_SETTINGS ) ) {
		add_option( PVSA_OPTION_SETTINGS, PVSA_Engine::default_settings() );
	}

	if ( ! get_option( PVSA_OPTION_TOKEN ) ) {
		add_option( PVSA_OPTION_TOKEN, bin2hex( random_bytes( 16 ) ) );
	}
}

/**
 * On deactivation: unschedule the cron. (Options are kept until uninstall.)
 */
function pvsa_deactivate() {
	$timestamp = wp_next_scheduled( PVSA_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, PVSA_CRON_HOOK );
	}
}

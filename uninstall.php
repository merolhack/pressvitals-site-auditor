<?php
/**
 * Uninstall cleanup for OmniHealth: Deep Site Auditor.
 *
 * Runs only when the user deletes the plugin from the WordPress admin.
 * Removes every option the plugin created and clears the scheduled cron.
 * Multisite-aware: cleans each site on the network.
 *
 * @package OmniHealthSiteAuditor
 */

// Exit if accessed directly or not during an uninstall request.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete this plugin's options + cron for the current site.
 */
function ohsa_uninstall_cleanup() {
	delete_option( 'ohsa_settings' );
	delete_option( 'ohsa_last_report' );
	delete_option( 'ohsa_token' );

	$timestamp = wp_next_scheduled( 'ohsa_daily_check' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'ohsa_daily_check' );
	}
	wp_clear_scheduled_hook( 'ohsa_daily_check' );
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		ohsa_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	ohsa_uninstall_cleanup();
}

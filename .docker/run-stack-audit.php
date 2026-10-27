<?php
/**
 * Test runner for validating PressVitals Site Auditor across diverse hosting stacks.
 * Executes environment diagnostic probes and asserts compatibility with hosting constraints.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/var/www/html/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) define( 'HOUR_IN_SECONDS', 3600 );
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) define( 'MINUTE_IN_SECONDS', 60 );
if ( ! defined( 'KB_IN_BYTES' ) ) define( 'KB_IN_BYTES', 1024 );
if ( ! defined( 'MB_IN_BYTES' ) ) define( 'MB_IN_BYTES', 1024 * KB_IN_BYTES );
if ( ! defined( 'GB_IN_BYTES' ) ) define( 'GB_IN_BYTES', 1024 * MB_IN_BYTES );
if ( ! defined( 'WPINC' ) ) define( 'WPINC', 'wp-includes' );
if ( ! defined( 'WP_CONTENT_DIR' ) ) define( 'WP_CONTENT_DIR', '/var/www/html/wp-content' );

// Standard WordPress functions
if ( ! function_exists( 'add_filter' ) ) {
    $GLOBALS['wp_filter'] = array();
    function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
        $GLOBALS['wp_filter'][ $tag ][] = $callback;
        return true;
    }
    function apply_filters( $tag, $value, ...$args ) {
        if ( ! empty( $GLOBALS['wp_filter'][ $tag ] ) ) {
            foreach ( $GLOBALS['wp_filter'][ $tag ] as $callback ) {
                $value = call_user_func( $callback, $value, ...$args );
            }
        }
        return $value;
    }
    function __( $text, $domain = 'default' ) { return $text; }
    function esc_html__( $text, $domain = 'default' ) { return $text; }
    function is_multisite() { return false; }
    function home_url( $path = '' ) { return 'https://example.com' . $path; }
    function wp_doing_cron() { return false; }
    function get_transient( $key ) { return false; }
    function set_transient( $key, $value, $exp = 0 ) { return true; }
    function get_site_transient( $key ) { return false; }
    function set_site_transient( $key, $value, $exp = 0 ) { return true; }
    function wp_get_current_user() { return (object) array( 'user_login' => 'admin' ); }
    function wp_is_maintenance_mode() { return false; }
    function wp_convert_hr_to_bytes( $size ) {
        $size = trim( (string) $size );
        if ( '' === $size ) return 0;
        $last = strtolower( $size[ strlen( $size ) - 1 ] );
        $val  = (int) $size;
        switch ( $last ) {
            case 'g': $val *= 1024 * 1024 * 1024; break;
            case 'm': $val *= 1024 * 1024; break;
            case 'k': $val *= 1024; break;
        }
        return $val;
    }
    function wp_image_editor_supports( $args ) {
        if ( extension_loaded( 'gd' ) ) {
            $info = gd_info();
            $mime = isset( $args['mime_type'] ) ? $args['mime_type'] : '';
            if ( 'image/webp' === $mime ) return ! empty( $info['WebP Support'] );
            if ( 'image/avif' === $mime ) return ! empty( $info['AVIF Support'] );
        }
        return false;
    }
}

require_once __DIR__ . '/../includes/class-pvsa-engine.php';

$engine = new PVSA_Engine();

$stack_name = getenv( 'HOSTING_STACK_NAME' ) ? getenv( 'HOSTING_STACK_NAME' ) : 'Unknown Stack';
echo "========================================================================\n";
echo " TESTING PRESSVITALS SITE AUDITOR ON: {$stack_name}\n";
echo "========================================================================\n";
echo "PHP Version: " . PHP_VERSION . " (" . php_sapi_name() . ")\n";
echo "Memory Limit: " . ini_get( 'memory_limit' ) . "\n";
echo "Max Execution Time: " . ini_get( 'max_execution_time' ) . "s\n";
echo "Expose PHP: " . ( ini_get( 'expose_php' ) ? 'On' : 'Off' ) . "\n";
echo "Disable Functions: " . ( ini_get( 'disable_functions' ) ? ini_get( 'disable_functions' ) : 'None' ) . "\n";
echo "OPcache Extension: " . ( extension_loaded( 'Zend OPcache' ) ? 'Loaded' : 'Not Loaded' ) . "\n";
echo "Redis Extension: " . ( extension_loaded( 'redis' ) ? 'Loaded' : 'Not Loaded' ) . "\n";
echo "------------------------------------------------------------------------\n";

$checks = array(
    'PHP Version'            => array( $engine, 'check_php_version' ),
    'PHP Hardening'          => array( $engine, 'check_php_hardening' ),
    'PHP Execution Limits'   => array( $engine, 'check_php_execution_limits' ),
    'Memory Limit'           => array( $engine, 'check_memory_limit' ),
    'Debug Display Off'      => array( $engine, 'check_debug_display_off' ),
    'Modern Image Formats'   => array( $engine, 'check_modern_image_formats' ),
);

$all_passed = true;
foreach ( $checks as $label => $callback ) {
    try {
        $result = call_user_func( $callback );
        $status = strtoupper( $result['status'] );
        $detail = isset( $result['detail'] ) ? $result['detail'] : '';
        $icon = ( 'PASS' === $status ) ? '✅' : ( ( 'WARN' === $status ) ? '⚠️' : '❌' );
        echo sprintf( "[%-4s] %s %-22s: %s\n", $status, $icon, $label, $detail );
        if ( 'FAIL' === $status ) {
            $all_passed = false;
        }
    } catch ( Throwable $t ) {
        echo sprintf( "[ERR ] ❌ %-22s: Exception: %s\n", $label, $t->getMessage() );
        $all_passed = false;
    }
}

echo "------------------------------------------------------------------------\n";
if ( $all_passed ) {
    echo "STATUS: COMPATIBLE & OPERATIONAL\n";
} else {
    echo "STATUS: ISSUES DETECTED\n";
}
echo "========================================================================\n\n";

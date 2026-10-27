#!/bin/bash
set -e

# If WordPress is not installed in /var/www/html, download and unpack it.
if [ ! -f /var/www/html/wp-includes/version.php ]; then
    echo "==> Downloading WordPress latest for IONOS container..."
    curl -sSfL https://wordpress.org/latest.tar.gz | tar -xz --strip-components=1 -C /var/www/html
    rm -f /var/www/html/index.html
fi
rm -f /var/www/html/index.html

# Ensure wp-config.php exists
if [ ! -f /var/www/html/wp-config.php ]; then
    echo "==> Generating wp-config.php for IONOS WAAS emulation..."
    cat << 'EOF' > /var/www/html/wp-config.php
<?php
define( 'DB_NAME', getenv('WORDPRESS_DB_NAME') ?: 'wp_ionos' );
define( 'DB_USER', getenv('WORDPRESS_DB_USER') ?: 'root' );
define( 'DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD') ?: 'root' );
define( 'DB_HOST', getenv('WORDPRESS_DB_HOST') ?: 'db' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// Custom table prefix matching IONOS production deployment (dqro.mx)
$table_prefix = 'dqro_';

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_MEMORY_LIMIT', '512M' );

define( 'AUTH_KEY',         'ionos_sample_key_auth_1234567890abcdef' );
define( 'SECURE_AUTH_KEY',  'ionos_sample_key_secauth_1234567890abcdef' );
define( 'LOGGED_IN_KEY',    'ionos_sample_key_logged_1234567890abcdef' );
define( 'NONCE_KEY',        'ionos_sample_key_nonce_1234567890abcdef' );
define( 'AUTH_SALT',        'ionos_sample_salt_auth_1234567890abcdef' );
define( 'SECURE_AUTH_SALT', 'ionos_sample_salt_secauth_1234567890abcdef' );
define( 'LOGGED_IN_SALT',   'ionos_sample_salt_logged_1234567890abcdef' );
define( 'NONCE_SALT',       'ionos_sample_salt_nonce_1234567890abcdef' );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
EOF
fi

# Ensure correct file permissions
chown -R www-data:www-data /var/www/html

# Start PHP 8.3 FPM service (servicing Web requests)
service php8.3-fpm start

# Start Apache in foreground
echo "==> Starting Apache in foreground (IONOS WAAS Emulation)..."
exec apache2ctl -D FOREGROUND

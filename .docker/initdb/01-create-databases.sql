-- Create one database per WordPress install used by docker-compose.yml.
-- Runs automatically the first time the mariadb container initialises.
CREATE DATABASE IF NOT EXISTS wp_latest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wp_mid    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wp_legacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

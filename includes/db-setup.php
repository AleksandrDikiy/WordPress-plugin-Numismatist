<?php
/**
 * Database table creation on plugin activation.
 *
 * @package Numismatist
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create or upgrade the coins table using dbDelta.
 */
function num_db_setup(): void {
	global $wpdb;

	$table           = $wpdb->prefix . NUM_TABLE_COINS;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id          BIGINT(20)    UNSIGNED NOT NULL AUTO_INCREMENT,
		name        VARCHAR(255)  NOT NULL DEFAULT '',
		url         VARCHAR(2048) NOT NULL DEFAULT '',
		year        SMALLINT(4)   UNSIGNED NULL DEFAULT NULL,
		material    VARCHAR(100)  NOT NULL DEFAULT '',
		circulation VARCHAR(100)  NOT NULL DEFAULT '',
		price       DECIMAL(10,2) UNSIGNED NULL DEFAULT NULL,
		photo       VARCHAR(2048) NOT NULL DEFAULT '',
		quantity    INT(11)       UNSIGNED NOT NULL DEFAULT 0,
		notes       TEXT          NOT NULL DEFAULT '',
		sorting     INT(11)       NOT NULL DEFAULT 0,
		created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY idx_year     (year),
		KEY idx_material (material(50)),
		KEY idx_sorting  (sorting)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'numismatist_db_version', NUM_VERSION, false );
}

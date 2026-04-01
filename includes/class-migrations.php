<?php
/**
 * Handles incremental database migrations.
 *
 * Add future schema changes as numbered static methods (migration_v1_0_1, etc.)
 * and register them in the MIGRATIONS constant.
 *
 * @package Numismatist
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Num_Migrations
 */
final class Num_Migrations {

	/**
	 * Ordered list of migration callbacks.
	 * Key = version string that introduced the migration.
	 */
	private const MIGRATIONS = [
		// '1.0.1' => [ self::class, 'migration_v1_0_1' ],
	];

	/**
	 * Run all pending migrations in order.
	 */
	public static function run(): void {
		$applied = (array) get_option( 'numismatist_applied_migrations', [] );

		foreach ( self::MIGRATIONS as $version => $callback ) {
			if ( in_array( $version, $applied, true ) ) {
				continue;
			}
			if ( is_callable( $callback ) ) {
				call_user_func( $callback );
				$applied[] = $version;
				update_option( 'numismatist_applied_migrations', $applied, false );
			}
		}
	}

	// ── Future migrations go here ─────────────────────────────────────────────
	// Example:
	// private static function migration_v1_0_1(): void {
	//     global $wpdb;
	//     $table = $wpdb->prefix . NUM_TABLE_COINS;
	//     $wpdb->query( "ALTER TABLE {$table} ADD COLUMN new_col TINYINT(1) NOT NULL DEFAULT 0" );
	// }
}

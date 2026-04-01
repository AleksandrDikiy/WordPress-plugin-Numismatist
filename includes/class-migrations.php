<?php
/**
 * Клас управління міграціями схеми бази даних.
 *
 * Міграції виконуються автоматично при активації плагіну
 * та при кожному завантаженні WordPress, якщо збережена версія БД
 * відрізняється від поточної версії плагіну (хук plugins_loaded).
 *
 * Правила додавання нових міграцій:
 *   1. Реалізуйте приватний статичний метод migration_vX_Y_Z().
 *   2. Зареєструйте його у константі MIGRATIONS (ключ = рядок версії).
 *   3. Ніколи не видаляйте та не змінюйте порядок наявних міграцій.
 *
 * Кожна міграція виконується рівно один раз — список виконаних міграцій
 * зберігається у wp_options → numismatist_applied_migrations.
 *
 * @package Numismatist
 */

declare( strict_types=1 );

// Пряме звернення до файлу заборонено.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Клас Num_Migrations
 * Управляє покроковими міграціями схеми таблиці монет.
 */
final class Num_Migrations {

	/**
	 * Впорядкований список міграцій: рядок версії → callable.
	 * Додавайте нові записи лише в кінець списку.
	 *
	 * @var array<string, array{0: class-string, 1: string}>
	 */
	private const MIGRATIONS = [
		'1.2.0' => [ self::class, 'migration_v1_2_0' ],
		'1.2.1' => [ self::class, 'migration_v1_2_1' ],
	];

	/**
	 * Запускає всі невиконані міграції у порядку їх реєстрації.
	 * Викликається при активації та через хук plugins_loaded.
	 */
	public static function run(): void {
		$виконані = (array) get_option( 'numismatist_applied_migrations', [] );

		foreach ( self::MIGRATIONS as $версія => $callable ) {
			if ( in_array( $версія, $виконані, true ) ) {
				continue; // Міграція вже виконувалась — пропускаємо.
			}
			if ( is_callable( $callable ) ) {
				call_user_func( $callable );
				$виконані[] = $версія;
				update_option( 'numismatist_applied_migrations', $виконані, false );
			}
		}

		// Синхронізуємо збережену версію схеми.
		update_option( 'numismatist_db_version', NUM_VERSION, false );
	}

	// ── Міграції ──────────────────────────────────────────────────────────────

	/**
	 * Міграція v1.2.0 — додає колонку user_id (застаріла назва з першої версії).
	 * Зберігається у списку, щоб не повторювати на сайтах, де вже була виконана.
	 */
	private static function migration_v1_2_0(): void {
		global $wpdb;

		$table = $wpdb->prefix . NUM_TABLE_COINS;

		if ( ! self::column_exists( $table, 'user_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `id`" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_user_id` (`user_id`)" );
		}
	}

	/**
	 * Міграція v1.2.1 — перейменовує user_id → id_user (канонічна назва за специфікацією).
	 *
	 * Сценарії:
	 *   - Свіжа установка: dbDelta вже створила id_user — нічого робити.
	 *   - Оновлення з v1.2.0: перейменовуємо колонку, переносимо дані, видаляємо старий індекс.
	 *   - Крайній випадок: ані user_id, ані id_user — просто додаємо id_user.
	 */
	private static function migration_v1_2_1(): void {
		global $wpdb;

		$table = $wpdb->prefix . NUM_TABLE_COINS;

		// Свіжа установка або вже перейменовано.
		if ( ! self::column_exists( $table, 'user_id' ) ) {
			if ( ! self::column_exists( $table, 'id_user' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `id_user` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `id`" );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_id_user` (`id_user`)" );
			}
			return;
		}

		// Оновлення з v1.2.0: додаємо нову колонку.
		if ( ! self::column_exists( $table, 'id_user' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `id_user` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `id`" );
		}

		// Переносимо дані зі старої колонки в нову.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "UPDATE `{$table}` SET id_user = user_id WHERE id_user = 0 AND user_id > 0" );

		// Видаляємо старий індекс та колонку.
		if ( self::key_exists( $table, 'idx_user_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` DROP KEY `idx_user_id`" );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `user_id`" );

		// Гарантуємо наявність індексу на id_user.
		if ( ! self::key_exists( $table, 'idx_id_user' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_id_user` (`id_user`)" );
		}
	}

	// ── Допоміжні методи перевірки схеми ─────────────────────────────────────

	/**
	 * Перевіряє, чи існує колонка у вказаній таблиці БД.
	 *
	 * @param string $table  Повна назва таблиці (з префіксом).
	 * @param string $column Назва колонки.
	 * @return bool
	 */
	private static function column_exists( string $table, string $column ): bool {
		global $wpdb;
		$result = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				$column
			)
		);
		return ! empty( $result );
	}

	/**
	 * Перевіряє, чи існує індекс з вказаною назвою у таблиці БД.
	 *
	 * @param string $table    Повна назва таблиці (з префіксом).
	 * @param string $key_name Назва індексу.
	 * @return bool
	 */
	private static function key_exists( string $table, string $key_name ): bool {
		global $wpdb;
		$result = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				DB_NAME,
				$table,
				$key_name
			)
		);
		return ! empty( $result );
	}
}

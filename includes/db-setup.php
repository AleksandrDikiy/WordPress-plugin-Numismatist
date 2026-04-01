<?php
/**
 * Створення таблиці бази даних при активації плагіну.
 *
 * Використовує dbDelta() — функцію WordPress для безпечного створення
 * та оновлення схеми БД. Безпечно викликати повторно: наявні колонки
 * та індекси не перезаписуються.
 *
 * Структура таблиці {prefix}coins:
 *   id          — первинний ключ, автоінкремент
 *   id_user     — ID користувача WordPress (wp_users.ID), забезпечує ізоляцію даних
 *   name        — назва монети (обов'язкове поле)
 *   url         — зовнішнє посилання на опис / каталог
 *   year        — рік випуску
 *   material    — матеріал (наприклад: срібло, мідь)
 *   circulation — тираж
 *   price       — орієнтовна ціна
 *   photo       — URL фото з медіатеки WordPress
 *   quantity    — кількість примірників
 *   notes       — довільні нотатки
 *   sorting     — ручне сортування (за замовчуванням 0)
 *   created_at  — дата/час створення запису (встановлюється автоматично)
 *   updated_at  — дата/час останнього оновлення (оновлюється автоматично)
 *
 * @package Numismatist
 */

declare( strict_types=1 );

// Пряме звернення до файлу заборонено.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Створює або оновлює таблицю монет за допомогою dbDelta.
 * Викликається при активації плагіну та при автоматичному оновленні схеми.
 */
function num_db_setup(): void {
	global $wpdb;

	$table           = $wpdb->prefix . NUM_TABLE_COINS;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id          BIGINT(20)    UNSIGNED NOT NULL AUTO_INCREMENT,
		id_user     BIGINT(20)    UNSIGNED NOT NULL DEFAULT 0,
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
		KEY idx_id_user  (id_user),
		KEY idx_year     (year),
		KEY idx_material (material(50)),
		KEY idx_sorting  (sorting)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Зберігаємо поточну версію схеми у налаштуваннях WordPress.
	update_option( 'numismatist_db_version', NUM_VERSION, false );
}

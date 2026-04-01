<?php
/**
 * Plugin Name:       Numismatist – Облік монет
 * Plugin URI:        https://github.com/your-username/numismatist
 * Description:       Мультикористувацький AJAX-плагін для обліку нумізматичних колекцій. Кожен зареєстрований користувач веде власну ізольовану колекцію. Розміщується на будь-якій сторінці сайту за допомогою шорткоду [numismatist].
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Your Name
 * Author URI:        https://your-site.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       numismatist
 * Domain Path:       /languages
 *
 * @package Numismatist
 */

declare( strict_types=1 );

// Пряме звернення до файлу заборонено.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Константи плагіну ────────────────────────────────────────────────────────
define( 'NUM_VERSION',     '1.3.0' );
define( 'NUM_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'NUM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'NUM_PLUGIN_FILE', __FILE__ );
define( 'NUM_TABLE_COINS', 'coins' ); // Повна назва: {$wpdb->prefix}coins

// ── Підключення файлів ───────────────────────────────────────────────────────
require_once NUM_PLUGIN_DIR . 'includes/db-setup.php';
require_once NUM_PLUGIN_DIR . 'includes/class-migrations.php';
require_once NUM_PLUGIN_DIR . 'includes/class-crud.php';
require_once NUM_PLUGIN_DIR . 'includes/class-ajax.php';
require_once NUM_PLUGIN_DIR . 'includes/class-shortcode.php';

// ── Хук активації ────────────────────────────────────────────────────────────
// Створює таблицю БД та запускає міграції при першій активації плагіну.
register_activation_hook( NUM_PLUGIN_FILE, 'num_activate' );

function num_activate(): void {
	num_db_setup();
	Num_Migrations::run();
}

// ── Автоматичне оновлення схеми БД ──────────────────────────────────────────
// Запускає міграції при кожному завантаженні WordPress, якщо збережена
// версія БД відрізняється від поточної версії плагіну.
// Це дозволяє оновлювати файли через rsync/FTP без повторної активації.
add_action( 'plugins_loaded', 'num_maybe_upgrade', 5 );

function num_maybe_upgrade(): void {
	$збережена_версія = get_option( 'numismatist_db_version', '0.0.0' );
	if ( version_compare( $збережена_версія, NUM_VERSION, '<' ) ) {
		num_db_setup();        // dbDelta безпечний для повторного виклику.
		Num_Migrations::run(); // Виконує лише невиконані міграції.
	}
}

// ── Реєстрація меню адміністрування ─────────────────────────────────────────
add_action( 'admin_menu', 'num_register_menu' );

function num_register_menu(): void {
	add_menu_page(
		__( 'Нумізматика', 'numismatist' ),      // Заголовок вкладки браузера.
		__( 'Монети', 'numismatist' ),            // Назва у меню WP.
		'manage_options',                          // Мінімальна роль: Адміністратор.
		'numismatist',                             // Slug сторінки.
		'num_render_admin_page',                   // Функція відображення.
		'dashicons-awards',                        // Іконка меню.
		30                                         // Позиція у меню.
	);
}

// ── Підключення CSS на сторінці адміністрування ──────────────────────────────
// Стилі підключаються ЛИШЕ на сторінці плагіну — не засмічують інші сторінки.
add_action( 'admin_enqueue_scripts', 'num_enqueue_admin_assets' );

function num_enqueue_admin_assets( string $hook_suffix ): void {
	if ( 'toplevel_page_numismatist' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style(
		'numismatist-admin-css',
		NUM_PLUGIN_URL . 'css/numismatist.css',
		[ 'dashicons' ],
		NUM_VERSION
	);
}

// ── Відображення інформаційної сторінки адміністрування ──────────────────────
// Сторінка містить шорткод, інструкцію та опис мультикористувацького режиму.
// Управління монетами відбувається на фронтенд-сторінці зі шорткодом.
function num_render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Недостатньо прав доступу.', 'numismatist' ) );
	}

	$shortcode_tag = '[numismatist]';
	?>
	<div class="wrap num-admin-info-wrap">

		<h1 class="num-admin-info-title">
			<span class="dashicons dashicons-awards"></span>
			<?php esc_html_e( 'Нумізматика — Облік монет', 'numismatist' ); ?>
		</h1>

		<!-- Картка: шорткод -->
		<div class="num-info-card num-info-card--accent">
			<div class="num-info-card__icon">
				<span class="dashicons dashicons-shortcode"></span>
			</div>
			<div class="num-info-card__body">
				<h2><?php esc_html_e( 'Шорткод для виводу колекції', 'numismatist' ); ?></h2>
				<p><?php esc_html_e( 'Вставте цей шорткод на будь-яку сторінку. Кожен авторизований користувач бачить і керує лише своєю колекцією. Гості бачать порожню таблицю в режимі перегляду.', 'numismatist' ); ?></p>
				<div class="num-shortcode-box">
					<code id="num-shortcode-code"><?php echo esc_html( $shortcode_tag ); ?></code>
					<button
						class="button"
						onclick="navigator.clipboard.writeText('<?php echo esc_js( $shortcode_tag ); ?>').then(function(){var b=this;b.textContent='✓ Скопійовано';setTimeout(function(){b.textContent='Копіювати';},2000);}.bind(this));"
					><?php esc_html_e( 'Копіювати', 'numismatist' ); ?></button>
				</div>
			</div>
		</div>

		<!-- Картка: інструкція налаштування -->
		<div class="num-info-card">
			<div class="num-info-card__icon">
				<span class="dashicons dashicons-list-view"></span>
			</div>
			<div class="num-info-card__body">
				<h2><?php esc_html_e( 'Як налаштувати', 'numismatist' ); ?></h2>
				<ol class="num-setup-steps">
					<li>
						<strong><?php esc_html_e( 'Створіть сторінку', 'numismatist' ); ?></strong> —
						<?php esc_html_e( 'Сторінки → Додати нову.', 'numismatist' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Вставте шорткод', 'numismatist' ); ?></strong> —
						<?php
						printf(
							/* translators: %s – шорткод у тегу <code> */
							esc_html__( 'Додайте блок «Шорткод» і вставте %s.', 'numismatist' ),
							'<code>[numismatist]</code>'
						);
						?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Опублікуйте', 'numismatist' ); ?></strong> —
						<?php esc_html_e( 'Натисніть «Опублікувати». Колекція одразу доступна за посиланням на сторінку.', 'numismatist' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Керуйте монетами', 'numismatist' ); ?></strong> —
						<?php esc_html_e( 'Будь-який авторизований користувач бачить кнопки ДОДАТИ, іконки редагування та видалення і керує лише своїми монетами.', 'numismatist' ); ?>
					</li>
				</ol>
			</div>
		</div>

		<!-- Картка: мультикористувацький режим -->
		<div class="num-info-card">
			<div class="num-info-card__icon">
				<span class="dashicons dashicons-groups"></span>
			</div>
			<div class="num-info-card__body">
				<h2><?php esc_html_e( 'Мультикористувацький режим', 'numismatist' ); ?></h2>
				<ul class="num-tips-list">
					<li><?php esc_html_e( 'Кожен зареєстрований користувач має власну ізольовану колекцію монет.', 'numismatist' ); ?></li>
					<li><?php esc_html_e( 'Ізоляція реалізована через поле id_user (= wp_users.ID) у таблиці монет.', 'numismatist' ); ?></li>
					<li><?php esc_html_e( 'Кожен SQL-запит містить WHERE id_user = {current_user_id} — доступ до чужих записів заблокований на рівні бази даних.', 'numismatist' ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %s – назва таблиці у тегу <code> */
							esc_html__( 'Таблиця бази даних: %s', 'numismatist' ),
							'<code>' . esc_html( $GLOBALS['wpdb']->prefix . NUM_TABLE_COINS ) . '</code>'
						);
						?>
					</li>
				</ul>
			</div>
		</div>

		<!-- Версія плагіну та версія схеми БД -->
		<p class="num-version-note">
			Numismatist v<?php echo esc_html( NUM_VERSION ); ?>
			&nbsp;·&nbsp;
			<?php
			printf(
				/* translators: %s – версія схеми БД у тегу <code> */
				esc_html__( 'Версія схеми БД: %s', 'numismatist' ),
				'<code>' . esc_html( get_option( 'numismatist_db_version', '—' ) ) . '</code>'
			);
			?>
		</p>

	</div><!-- .num-admin-info-wrap -->
	<?php
}

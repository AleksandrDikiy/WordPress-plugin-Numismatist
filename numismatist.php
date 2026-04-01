<?php
/**
 * Plugin Name:       Numismatist – Coin Collection Manager
 * Plugin URI:        https://github.com/your-repo/numismatist
 * Description:       A secure, AJAX-driven WordPress plugin for managing a personal coin (numismatics) collection. Use the shortcode [numismatist] to display the collection on any page.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Your Name
 * License:           GPL v2 or later
 * Text Domain:       numismatist
 *
 * @package Numismatist
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'NUM_VERSION',     '1.1.0' );
define( 'NUM_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'NUM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'NUM_PLUGIN_FILE', __FILE__ );
define( 'NUM_TABLE_COINS', 'coins' ); // $wpdb->prefix applied in CRUD.

// Autoload.
require_once NUM_PLUGIN_DIR . 'includes/db-setup.php';
require_once NUM_PLUGIN_DIR . 'includes/class-migrations.php';
require_once NUM_PLUGIN_DIR . 'includes/class-crud.php';
require_once NUM_PLUGIN_DIR . 'includes/class-ajax.php';
require_once NUM_PLUGIN_DIR . 'includes/class-shortcode.php';

// Activation hook.
register_activation_hook( NUM_PLUGIN_FILE, 'num_activate' );
function num_activate(): void {
	num_db_setup();
	Num_Migrations::run();
}

// Admin menu.
add_action( 'admin_menu', 'num_register_menu' );
function num_register_menu(): void {
	add_menu_page(
		__( 'Нумізматика', 'numismatist' ),
		__( 'Монети', 'numismatist' ),
		'manage_options',
		'numismatist',
		'num_render_admin_page',
		'dashicons-awards',
		30
	);
}

// Render admin info page (no table — collection lives on the frontend via shortcode).
function num_render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Недостатньо прав доступу.', 'numismatist' ) );
	}

	$shortcode_tag = '[numismatist]';
	?>
	<div class="wrap num-admin-info-wrap">
		<h1 class="num-admin-info-title">
			<span class="dashicons dashicons-awards" style="font-size:28px;height:28px;margin-right:8px;color:#2271b1;vertical-align:middle;"></span>
			<?php esc_html_e( 'Нумізматика — Облік монет', 'numismatist' ); ?>
		</h1>

		<!-- Shortcode block -->
		<div class="num-info-card num-info-card--accent">
			<div class="num-info-card__icon">
				<span class="dashicons dashicons-shortcode"></span>
			</div>
			<div class="num-info-card__body">
				<h2><?php esc_html_e( 'Шорткод для виводу колекції', 'numismatist' ); ?></h2>
				<p><?php esc_html_e( 'Вставте цей шорткод на будь-яку сторінку сайту — колекція монет відобразиться з пошуком, фільтрами і пагінацією. Авторизованим адміністраторам додатково показуються кнопки Додати / Редагувати / Видалити.', 'numismatist' ); ?></p>
				<div class="num-shortcode-box">
					<code id="num-shortcode-code"><?php echo esc_html( $shortcode_tag ); ?></code>
					<button
						class="button num-copy-btn"
						data-clipboard-target="#num-shortcode-code"
						onclick="navigator.clipboard.writeText('<?php echo esc_js( $shortcode_tag ); ?>').then(function(){var b=this;b.textContent='✓ Скопійовано';setTimeout(function(){b.textContent='Копіювати';},2000);}.bind(this));"
					>
						<?php esc_html_e( 'Копіювати', 'numismatist' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Setup steps -->
		<div class="num-info-card">
			<div class="num-info-card__icon">
				<span class="dashicons dashicons-list-view"></span>
			</div>
			<div class="num-info-card__body">
				<h2><?php esc_html_e( 'Як налаштувати', 'numismatist' ); ?></h2>
				<ol class="num-setup-steps">
					<li>
						<strong><?php esc_html_e( 'Створіть або відкрийте сторінку', 'numismatist' ); ?></strong><br>
						<?php esc_html_e( 'Перейдіть у Сторінки → Додати нову (або відкрийте існуючу).', 'numismatist' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Вставте шорткод', 'numismatist' ); ?></strong><br>
						<?php
						printf(
							/* translators: %s = shortcode tag in <code> */
							esc_html__( 'Додайте блок «Шорткод» (Shortcode) або «Класичний редактор» і вставте %s.', 'numismatist' ),
							'<code>[numismatist]</code>'
						);
						?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Опублікуйте сторінку', 'numismatist' ); ?></strong><br>
						<?php esc_html_e( 'Натисніть «Опублікувати» або «Оновити». Колекція одразу доступна за посиланням на сторінку.', 'numismatist' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Керуйте колекцією', 'numismatist' ); ?></strong><br>
						<?php esc_html_e( 'Відкрийте сторінку як адміністратор — ви побачите кнопки «ДОДАТИ», іконки редагування та видалення у кожному рядку таблиці.', 'numismatist' ); ?>
					</li>
				</ol>
			</div>
		</div>

		<!-- Tips -->
		<div class="num-info-card">
			<div class="num-info-card__icon">
				<span class="dashicons dashicons-info-outline"></span>
			</div>
			<div class="num-info-card__body">
				<h2><?php esc_html_e( 'Корисні підказки', 'numismatist' ); ?></h2>
				<ul class="num-tips-list">
					<li><?php esc_html_e( 'Шорткод можна розміщувати на кількох сторінках одночасно.', 'numismatist' ); ?></li>
					<li><?php esc_html_e( 'Пошук та фільтри (рік, матеріал) працюють через AJAX без перезавантаження сторінки.', 'numismatist' ); ?></li>
					<li><?php esc_html_e( 'Натисніть на назву монети, щоб переглянути та відредагувати усі поля у модальному вікні.', 'numismatist' ); ?></li>
					<li><?php esc_html_e( 'Фото зберігаються через стандартну медіатеку WordPress.', 'numismatist' ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %s = table name */
							esc_html__( 'Дані зберігаються у таблиці %s вашої бази даних WordPress.', 'numismatist' ),
							'<code>' . esc_html( $GLOBALS['wpdb']->prefix . NUM_TABLE_COINS ) . '</code>'
						);
						?>
					</li>
				</ul>
			</div>
		</div>

		<!-- Version badge -->
		<p class="num-version-note">
			<?php
			printf(
				/* translators: %s = version number */
				esc_html__( 'Numismatist v%s', 'numismatist' ),
				esc_html( NUM_VERSION )
			);
			?>
		</p>

	</div><!-- .num-admin-info-wrap -->

	<style>
	/* Scoped admin info page styles */
	.num-admin-info-wrap { max-width: 820px; }
	.num-admin-info-title { display:flex; align-items:center; font-size:1.5rem; margin:14px 0 22px; }
	.num-info-card { display:flex; gap:18px; background:#fff; border:1px solid #e0e0e0; border-radius:6px; padding:20px 22px; margin-bottom:16px; }
	.num-info-card--accent { border-left:4px solid #2271b1; }
	.num-info-card__icon { flex-shrink:0; width:36px; text-align:center; }
	.num-info-card__icon .dashicons { font-size:26px; width:26px; height:26px; color:#2271b1; margin-top:2px; }
	.num-info-card__body { flex:1; }
	.num-info-card__body h2 { margin:0 0 10px; font-size:1rem; font-weight:600; color:#1d2327; }
	.num-info-card__body p  { margin:0 0 12px; color:#444; }
	.num-shortcode-box { display:flex; align-items:center; gap:10px; background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; padding:10px 14px; }
	.num-shortcode-box code { font-size:1rem; font-family:monospace; color:#1d2327; background:none; flex:1; }
	.num-copy-btn { flex-shrink:0; }
	.num-setup-steps { margin:0; padding-left:22px; color:#444; }
	.num-setup-steps li { margin-bottom:10px; line-height:1.55; }
	.num-setup-steps li:last-child { margin-bottom:0; }
	.num-tips-list { margin:0; padding-left:20px; color:#444; }
	.num-tips-list li { margin-bottom:7px; line-height:1.5; }
	.num-tips-list li:last-child { margin-bottom:0; }
	.num-version-note { color:#a7aaad; font-size:12px; margin-top:4px; }
	</style>
	<?php
}

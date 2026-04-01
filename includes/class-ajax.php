<?php
/**
 * Клас обробки AJAX-запитів плагіну.
 *
 * Реєструє та обробляє всі AJAX-ендпоінти для операцій з монетами.
 *
 * Модель безпеки (три рівні перевірки):
 *   1. Перевірка nonce    — захист від CSRF-атак.
 *   2. is_user_logged_in() — лише авторизовані користувачі.
 *   3. id_user у SQL      — клас Num_CRUD обмежує запити поточним юзером.
 *
 * Важливо: wp_send_json_error() викликається БЕЗ HTTP-статус-коду.
 * WordPress AJAX завжди повертає HTTP 200, стан успіху/помилки передається
 * через поле "success" у тілі JSON-відповіді. Якщо передати код 4xx/5xx,
 * jQuery .fail() спрацює замість .done(), і реальне повідомлення про помилку
 * ніколи не дійде до користувача.
 *
 * @package Numismatist
 */

declare( strict_types=1 );

// Пряме звернення до файлу заборонено.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Клас Num_Ajax
 * Реєструє хуки wp_ajax_* та обробляє AJAX-запити.
 */
final class Num_Ajax {

	/**
	 * Рядок дії nonce — має збігатися з wp_create_nonce() у class-shortcode.php.
	 */
	private const NONCE_ACTION = 'numismatist_nonce';

	/**
	 * Конструктор. Реєструє всі AJAX-обробники для авторизованих користувачів.
	 */
	public function __construct() {
		$actions = [
			'num_get_coins',    // Отримати список монет (посторінково).
			'num_get_coin',     // Отримати одну монету за ID.
			'num_save_coin',    // Зберегти монету (додати або оновити).
			'num_delete_coin',  // Видалити монету.
			'num_get_filters',  // Оновити параметри фільтрів.
		];

		foreach ( $actions as $action ) {
			// wp_ajax_ — тільки для авторизованих. nopriv_ не реєструємо.
			add_action( 'wp_ajax_' . $action, [ $this, $action ] );
		}
	}

	// ── Аутентифікація ────────────────────────────────────────────────────────

	/**
	 * Перевіряє nonce та авторизацію. При невдачі повертає HTTP 200 з success:false,
	 * щоб JS-обробник міг відобразити реальне повідомлення про помилку.
	 */
	private function authenticate(): void {
		if (
			! isset( $_POST['nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['nonce'] ) ),
				self::NONCE_ACTION
			)
		) {
			wp_send_json_error( [ 'message' => __( 'Невірний токен безпеки.', 'numismatist' ) ] );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Необхідна авторизація.', 'numismatist' ) ] );
		}
	}

	// ── Санітизація вхідних даних ─────────────────────────────────────────────

	/** Читає та санітизує рядкове POST-поле. */
	private function post_str( string $key, string $default = '' ): string {
		return isset( $_POST[ $key ] )
			? sanitize_text_field( wp_unslash( $_POST[ $key ] ) )
			: $default;
	}

	/** Читає та санітизує цілочисельне POST-поле (невід'ємне). */
	private function post_int( string $key, int $default = 0 ): int {
		return isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : $default;
	}

	/** Читає та санітизує URL з POST-поля. */
	private function post_url( string $key ): string {
		return isset( $_POST[ $key ] )
			? esc_url_raw( wp_unslash( $_POST[ $key ] ) )
			: '';
	}

	/** Читає та санітизує багаторядкове текстове POST-поле. */
	private function post_textarea( string $key ): string {
		return isset( $_POST[ $key ] )
			? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) )
			: '';
	}

	/** Читає та санітизує числове поле з плаваючою комою з POST. */
	private function post_float( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) || '' === $_POST[ $key ] ) {
			return '';
		}
		return (string) floatval( $_POST[ $key ] );
	}

	// ── AJAX-обробники ────────────────────────────────────────────────────────

	/**
	 * Повертає посторінковий список монет поточного користувача.
	 * POST-параметри: nonce, search, year, material, per_page, page.
	 */
	public function num_get_coins(): void {
		$this->authenticate();

		$per_page = max( 1, $this->post_int( 'per_page', 10 ) );
		$page     = max( 1, $this->post_int( 'page', 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		// Num_CRUD автоматично обмежує запит поточним юзером.
		$crud   = new Num_CRUD();
		$result = $crud->get_coins( [
			'search'   => $this->post_str( 'search' ),
			'year'     => $this->post_str( 'year' ),
			'material' => $this->post_str( 'material' ),
			'per_page' => $per_page,
			'offset'   => $offset,
		] );

		$total_pages = (int) ceil( max( 1, $result['total'] ) / $per_page );

		wp_send_json_success( [
			'items'       => $result['items'],
			'total'       => $result['total'],
			'total_pages' => $total_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		] );
	}

	/**
	 * Повертає дані однієї монети за ID (тільки якщо належить поточному юзеру).
	 * POST-параметри: nonce, id.
	 */
	public function num_get_coin(): void {
		$this->authenticate();

		$id   = $this->post_int( 'id' );
		$crud = new Num_CRUD();
		$coin = $crud->get_coin( $id );

		if ( null === $coin ) {
			wp_send_json_error( [ 'message' => __( 'Запис не знайдено.', 'numismatist' ) ] );
		}

		wp_send_json_success( [ 'coin' => $coin ] );
	}

	/**
	 * Зберігає монету (додає або оновлює). Завжди прив'язана до поточного юзера.
	 * POST-параметри: nonce, id (0 = нова монета), name, url, year, material,
	 *                 circulation, price, photo, quantity, notes, sorting.
	 */
	public function num_save_coin(): void {
		$this->authenticate();

		$id   = $this->post_int( 'id' );
		$name = $this->post_str( 'name' );

		if ( '' === $name ) {
			wp_send_json_error( [ 'message' => __( 'Назва є обов\'язковим полем.', 'numismatist' ) ] );
		}

		$data = [
			'name'        => $name,
			'url'         => $this->post_url( 'url' ),
			'year'        => $this->post_str( 'year' ),
			'material'    => $this->post_str( 'material' ),
			'circulation' => $this->post_str( 'circulation' ),
			'price'       => $this->post_float( 'price' ),
			'photo'       => $this->post_url( 'photo' ),
			'quantity'    => $this->post_int( 'quantity' ),
			'notes'       => $this->post_textarea( 'notes' ),
			'sorting'     => $this->post_int( 'sorting' ),
		];

		$crud = new Num_CRUD();

		// Новий запис — id_user встановлюється автоматично у insert_coin().
		if ( 0 === $id ) {
			$new_id = $crud->insert_coin( $data );
			if ( false === $new_id ) {
				global $wpdb;
				wp_send_json_error( [
					'message' => __( 'Помилка збереження запису.', 'numismatist' ),
					// Додаткова діагностика — видно лише при WP_DEBUG = true.
					'debug'   => WP_DEBUG ? ( $wpdb->last_error ?: 'Невідома помилка БД.' ) : '',
				] );
			}
			wp_send_json_success( [
				'id'      => $new_id,
				'message' => __( 'Запис успішно додано.', 'numismatist' ),
			] );
		}

		// Оновлення — WHERE містить id_user: захист від редагування чужих монет.
		$ok = $crud->update_coin( $id, $data );
		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => __( 'Помилка оновлення запису.', 'numismatist' ) ] );
		}
		wp_send_json_success( [
			'id'      => $id,
			'message' => __( 'Запис успішно оновлено.', 'numismatist' ),
		] );
	}

	/**
	 * Видаляє монету поточного користувача.
	 * POST-параметри: nonce, id.
	 */
	public function num_delete_coin(): void {
		$this->authenticate();

		$id   = $this->post_int( 'id' );
		$crud = new Num_CRUD();

		// get_coin() вже перевіряє id_user — поверне null для чужих монет.
		if ( null === $crud->get_coin( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Запис не знайдено.', 'numismatist' ) ] );
		}

		$ok = $crud->delete_coin( $id );
		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => __( 'Помилка видалення запису.', 'numismatist' ) ] );
		}

		wp_send_json_success( [ 'message' => __( 'Запис видалено.', 'numismatist' ) ] );
	}

	/**
	 * Повертає оновлений список унікальних років та матеріалів поточного юзера.
	 * Викликається після додавання, оновлення або видалення монети.
	 * POST-параметри: nonce.
	 */
	public function num_get_filters(): void {
		$this->authenticate();

		$crud = new Num_CRUD();
		wp_send_json_success( [
			'years'     => $crud->get_distinct_years(),
			'materials' => $crud->get_distinct_materials(),
		] );
	}
}

// Ініціалізація — реєструє всі хуки wp_ajax_*.
new Num_Ajax();

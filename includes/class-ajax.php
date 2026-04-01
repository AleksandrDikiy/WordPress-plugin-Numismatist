<?php
/**
 * Registers and handles all AJAX endpoints for the plugin.
 *
 * Every handler: verifies nonce → checks capability → sanitizes input → calls CRUD → responds JSON.
 *
 * @package Numismatist
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Num_Ajax
 */
final class Num_Ajax {

	/** Nonce action string. */
	private const NONCE_ACTION = 'numismatist_nonce';

	/** Required capability. */
	private const CAPABILITY = 'manage_options';

	public function __construct() {
		$actions = [
			'num_get_coins',
			'num_get_coin',
			'num_save_coin',
			'num_delete_coin',
			'num_get_filters',
		];

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, [ $this, $action ] );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/** Verify nonce + capability; die on failure. */
	private function authenticate(): void {
		if (
			! isset( $_POST['nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE_ACTION )
		) {
			wp_send_json_error( [ 'message' => __( 'Невірний токен безпеки.', 'numismatist' ) ], 403 );
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Недостатньо прав.', 'numismatist' ) ], 403 );
		}
	}

	/** Retrieve and sanitize a POST string field. */
	private function post_str( string $key, string $default = '' ): string {
		return isset( $_POST[ $key ] )
			? sanitize_text_field( wp_unslash( $_POST[ $key ] ) )
			: $default;
	}

	/** Retrieve and sanitize a POST integer field. */
	private function post_int( string $key, int $default = 0 ): int {
		return isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : $default;
	}

	/** Retrieve and sanitize a POST URL field. */
	private function post_url( string $key ): string {
		return isset( $_POST[ $key ] )
			? esc_url_raw( wp_unslash( $_POST[ $key ] ) )
			: '';
	}

	/** Retrieve and sanitize a POST textarea field. */
	private function post_textarea( string $key ): string {
		return isset( $_POST[ $key ] )
			? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) )
			: '';
	}

	/** Retrieve and sanitize a POST float field. */
	private function post_float( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) || '' === $_POST[ $key ] ) {
			return '';
		}
		return (string) floatval( $_POST[ $key ] );
	}

	// ── Handlers ──────────────────────────────────────────────────────────────

	/**
	 * Get paginated list of coins.
	 * POST params: nonce, search, year, material, per_page, page
	 */
	public function num_get_coins(): void {
		$this->authenticate();

		$per_page = max( 1, $this->post_int( 'per_page', 10 ) );
		$page     = max( 1, $this->post_int( 'page', 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$crud   = new Num_CRUD();
		$result = $crud->get_coins( [
			'search'   => $this->post_str( 'search' ),
			'year'     => $this->post_str( 'year' ),
			'material' => $this->post_str( 'material' ),
			'per_page' => $per_page,
			'offset'   => $offset,
		] );

		$total_pages = $per_page > 0 ? (int) ceil( $result['total'] / $per_page ) : 1;

		wp_send_json_success( [
			'items'       => $result['items'],
			'total'       => $result['total'],
			'total_pages' => $total_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		] );
	}

	/**
	 * Get a single coin by ID.
	 * POST params: nonce, id
	 */
	public function num_get_coin(): void {
		$this->authenticate();

		$id   = $this->post_int( 'id' );
		$crud = new Num_CRUD();
		$coin = $crud->get_coin( $id );

		if ( null === $coin ) {
			wp_send_json_error( [ 'message' => __( 'Запис не знайдено.', 'numismatist' ) ], 404 );
		}

		wp_send_json_success( [ 'coin' => $coin ] );
	}

	/**
	 * Insert or update a coin record.
	 * POST params: nonce, id (0 = insert), name, url, year, material, circulation, price, photo, quantity, notes, sorting
	 */
	public function num_save_coin(): void {
		$this->authenticate();

		$id   = $this->post_int( 'id' );
		$name = $this->post_str( 'name' );

		if ( '' === $name ) {
			wp_send_json_error( [ 'message' => __( 'Назва є обов\'язковим полем.', 'numismatist' ) ], 422 );
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

		if ( 0 === $id ) {
			$new_id = $crud->insert_coin( $data );
			if ( false === $new_id ) {
				wp_send_json_error( [ 'message' => __( 'Помилка збереження запису.', 'numismatist' ) ], 500 );
			}
			wp_send_json_success( [
				'id'      => $new_id,
				'message' => __( 'Запис успішно додано.', 'numismatist' ),
			] );
		}

		// Update existing.
		$ok = $crud->update_coin( $id, $data );
		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => __( 'Помилка оновлення запису.', 'numismatist' ) ], 500 );
		}
		wp_send_json_success( [
			'id'      => $id,
			'message' => __( 'Запис успішно оновлено.', 'numismatist' ),
		] );
	}

	/**
	 * Delete a coin record.
	 * POST params: nonce, id
	 */
	public function num_delete_coin(): void {
		$this->authenticate();

		$id   = $this->post_int( 'id' );
		$crud = new Num_CRUD();

		if ( null === $crud->get_coin( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Запис не знайдено.', 'numismatist' ) ], 404 );
		}

		$ok = $crud->delete_coin( $id );
		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => __( 'Помилка видалення запису.', 'numismatist' ) ], 500 );
		}

		wp_send_json_success( [ 'message' => __( 'Запис видалено.', 'numismatist' ) ] );
	}

	/**
	 * Return fresh filter options (years + materials) after saves/deletes.
	 * POST params: nonce
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

// Instantiate to register hooks.
new Num_Ajax();

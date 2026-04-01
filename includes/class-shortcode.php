<?php
/**
 * Shortcode [numismatist] — renders the full coin collection table on any page.
 *
 * Usage:  [numismatist]
 *
 * The table (search, filters, pagination) is visible to all visitors.
 * Add / Edit / Delete controls are rendered only for users with manage_options.
 *
 * @package Numismatist
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Num_Shortcode
 */
final class Num_Shortcode {

	public function __construct() {
		add_shortcode( 'numismatist', [ $this, 'render' ] );
	}

	/**
	 * Enqueue frontend assets (called from inside shortcode render).
	 * wp_enqueue_* is safe to call here — WP queues them properly.
	 */
	private function enqueue_assets(): void {
		$is_admin = current_user_can( 'manage_options' );

		// Dashicons needed for admin icon buttons.
		if ( $is_admin ) {
			wp_enqueue_style( 'dashicons' );
		}

		wp_enqueue_style(
			'numismatist-css',
			NUM_PLUGIN_URL . 'css/numismatist.css',
			$is_admin ? [ 'dashicons' ] : [],
			NUM_VERSION
		);

		wp_enqueue_script(
			'numismatist-js',
			NUM_PLUGIN_URL . 'js/numismatist.js',
			[ 'jquery' ],
			NUM_VERSION,
			true
		);

		// Media library only for admins.
		if ( $is_admin ) {
			wp_enqueue_media();
		}

		wp_localize_script(
			'numismatist-js',
			'numData',
			[
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'numismatist_nonce' ),
				'isAdmin'  => $is_admin ? '1' : '0',
				'i18n'     => [
					'confirmDelete' => __( 'Видалити цей запис?', 'numismatist' ),
					'errorGeneric'  => __( 'Сталася помилка. Спробуйте ще раз.', 'numismatist' ),
					'selectPhoto'   => __( 'Обрати фото', 'numismatist' ),
					'usePhoto'      => __( 'Використати це фото', 'numismatist' ),
					'loading'       => __( 'Завантаження…', 'numismatist' ),
					'empty'         => __( 'Записи відсутні', 'numismatist' ),
				],
			]
		);
	}

	/**
	 * Shortcode render callback.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes (unused).
	 * @return string  Rendered HTML.
	 */
	public function render( $atts ): string {
		$this->enqueue_assets();

		$is_admin  = current_user_can( 'manage_options' );
		$crud      = new Num_CRUD();
		$years     = $crud->get_distinct_years();
		$materials = $crud->get_distinct_materials();

		ob_start();
		?>
		<div class="num-wrap num-frontend">

			<!-- ── Top bar ── -->
			<div class="num-toolbar">
				<input
					type="text"
					id="num-search"
					class="num-search-input"
					placeholder="<?php esc_attr_e( 'Пошук за назвою…', 'numismatist' ); ?>"
					autocomplete="off"
				/>

				<select id="num-filter-year" class="num-select">
					<option value=""><?php esc_html_e( 'Всі роки', 'numismatist' ); ?></option>
					<?php foreach ( $years as $year ) : ?>
						<option value="<?php echo esc_attr( (string) $year ); ?>"><?php echo esc_html( (string) $year ); ?></option>
					<?php endforeach; ?>
				</select>

				<select id="num-filter-material" class="num-select">
					<option value=""><?php esc_html_e( 'Всі матеріали', 'numismatist' ); ?></option>
					<?php foreach ( $materials as $material ) : ?>
						<option value="<?php echo esc_attr( $material ); ?>"><?php echo esc_html( $material ); ?></option>
					<?php endforeach; ?>
				</select>

				<?php if ( $is_admin ) : ?>
					<button id="num-btn-add" class="button num-btn-add"><?php esc_html_e( 'ДОДАТИ', 'numismatist' ); ?></button>
				<?php endif; ?>
			</div>

			<!-- ── Table ── -->
			<div class="num-table-wrap">
				<table class="num-table">
					<thead>
						<tr>
							<th class="num-col-num">№</th>
							<th class="num-col-name"><?php esc_html_e( 'Назва', 'numismatist' ); ?></th>
							<th class="num-col-year"><?php esc_html_e( 'Рік', 'numismatist' ); ?></th>
							<th class="num-col-photo"><?php esc_html_e( 'Фото', 'numismatist' ); ?></th>
							<th class="num-col-qty"><?php esc_html_e( 'Кількість', 'numismatist' ); ?></th>
							<?php if ( $is_admin ) : ?>
								<th class="num-col-actions"><?php esc_html_e( 'Дії', 'numismatist' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody id="num-table-body">
						<tr>
							<td colspan="<?php echo $is_admin ? '6' : '5'; ?>" class="num-loading">
								<?php esc_html_e( 'Завантаження…', 'numismatist' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- ── Pagination ── -->
			<div class="num-pagination-bar">
				<div class="num-per-page-wrap">
					<label for="num-per-page"><?php esc_html_e( 'Записів на сторінці:', 'numismatist' ); ?></label>
					<select id="num-per-page" class="num-select num-select-sm">
						<option value="10" selected>10</option>
						<option value="20">20</option>
						<option value="50">50</option>
						<option value="100">100</option>
					</select>
				</div>
				<div class="num-pagination-info-wrap">
					<span id="num-pagination-info"></span>
					<span class="num-pagination-buttons">
						<button class="num-page-btn" id="num-page-first" title="<?php esc_attr_e( 'Перша', 'numismatist' ); ?>">«</button>
						<button class="num-page-btn" id="num-page-prev"  title="<?php esc_attr_e( 'Попередня', 'numismatist' ); ?>">‹</button>
						<button class="num-page-btn" id="num-page-next"  title="<?php esc_attr_e( 'Наступна', 'numismatist' ); ?>">›</button>
						<button class="num-page-btn" id="num-page-last"  title="<?php esc_attr_e( 'Остання', 'numismatist' ); ?>">»</button>
					</span>
				</div>
			</div>

		</div><!-- .num-wrap -->

		<?php if ( $is_admin ) : ?>
		<!-- ── Modal (admins only) ── -->
		<div id="num-modal-overlay" class="num-modal-overlay" aria-hidden="true">
			<div class="num-modal" role="dialog" aria-modal="true" aria-labelledby="num-modal-title">

				<div class="num-modal-header">
					<h2 id="num-modal-title"><?php esc_html_e( 'Монета', 'numismatist' ); ?></h2>
					<button class="num-modal-close" id="num-modal-close" aria-label="<?php esc_attr_e( 'Закрити', 'numismatist' ); ?>">×</button>
				</div>

				<div class="num-modal-body">
					<input type="hidden" id="num-field-id" value="0" />

					<div class="num-form-grid">
						<div class="num-form-col">
							<div class="num-field-group">
								<label for="num-field-name"><?php esc_html_e( 'Назва', 'numismatist' ); ?> <span class="required">*</span></label>
								<input type="text" id="num-field-name" class="num-input" maxlength="255" />
							</div>
							<div class="num-field-group">
								<label for="num-field-url"><?php esc_html_e( 'URL (зовнішнє посилання)', 'numismatist' ); ?></label>
								<input type="url" id="num-field-url" class="num-input" maxlength="2048" />
							</div>
							<div class="num-field-group">
								<label for="num-field-year"><?php esc_html_e( 'Рік', 'numismatist' ); ?></label>
								<input type="number" id="num-field-year" class="num-input" min="1" max="9999" />
							</div>
							<div class="num-field-group">
								<label for="num-field-material"><?php esc_html_e( 'Матеріал', 'numismatist' ); ?></label>
								<input type="text" id="num-field-material" class="num-input" maxlength="100" />
							</div>
							<div class="num-field-group">
								<label for="num-field-circulation"><?php esc_html_e( 'Тираж', 'numismatist' ); ?></label>
								<input type="text" id="num-field-circulation" class="num-input" maxlength="100" />
							</div>
						</div>

						<div class="num-form-col">
							<div class="num-field-group">
								<label for="num-field-price"><?php esc_html_e( 'Ціна', 'numismatist' ); ?></label>
								<input type="number" id="num-field-price" class="num-input" min="0" step="0.01" />
							</div>
							<div class="num-field-group">
								<label for="num-field-quantity"><?php esc_html_e( 'Кількість', 'numismatist' ); ?></label>
								<input type="number" id="num-field-quantity" class="num-input" min="0" value="1" />
							</div>
							<div class="num-field-group">
								<label for="num-field-sorting"><?php esc_html_e( 'Сортування', 'numismatist' ); ?></label>
								<input type="number" id="num-field-sorting" class="num-input" min="0" value="0" />
							</div>
							<div class="num-field-group">
								<label><?php esc_html_e( 'Фото', 'numismatist' ); ?></label>
								<div class="num-photo-wrap">
									<img id="num-photo-preview" src="" alt="" class="num-photo-preview hidden" />
									<div class="num-photo-actions">
										<button type="button" id="num-btn-media" class="button"><?php esc_html_e( 'Обрати фото', 'numismatist' ); ?></button>
										<button type="button" id="num-btn-media-remove" class="button num-btn-remove hidden"><?php esc_html_e( 'Видалити фото', 'numismatist' ); ?></button>
									</div>
									<input type="hidden" id="num-field-photo" value="" />
								</div>
							</div>
						</div>
					</div>

					<div class="num-field-group num-field-group--full">
						<label for="num-field-notes"><?php esc_html_e( 'Нотатки', 'numismatist' ); ?></label>
						<textarea id="num-field-notes" class="num-textarea" rows="4"></textarea>
					</div>
				</div>

				<div class="num-modal-footer">
					<span id="num-form-error" class="num-form-error"></span>
					<button type="button" id="num-btn-cancel" class="button"><?php esc_html_e( 'Скасувати', 'numismatist' ); ?></button>
					<button type="button" id="num-btn-save" class="button button-primary"><?php esc_html_e( 'Зберегти', 'numismatist' ); ?></button>
				</div>

			</div>
		</div>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}
}

// Instantiate.
new Num_Shortcode();

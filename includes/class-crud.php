<?php
/**
 * Клас для операцій з базою даних (CRUD).
 *
 * Центральний вузол для всіх операцій читання та запису таблиці монет.
 *
 * Мультикористувацька ізоляція:
 *   Кожен запит автоматично обмежується поточним користувачем через
 *   колонку id_user (= wp_users.ID). Користувач не може прочитати,
 *   змінити або видалити монети іншого користувача — це перевіряється
 *   на рівні SQL-запиту (WHERE id_user = %d).
 *
 * Захист від SQL-ін'єкцій:
 *   Усі запити використовують $wpdb->prepare() з параметризованими
 *   підстановками. Вставка та оновлення виконуються через $wpdb->insert()
 *   та $wpdb->update() з масивами форматів.
 *
 * @package Numismatist
 */

declare( strict_types=1 );

// Пряме звернення до файлу заборонено.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Клас Num_CRUD
 * Реалізує операції читання та запису для таблиці {prefix}coins.
 */
final class Num_CRUD {

	/** @var \wpdb Об'єкт бази даних WordPress. */
	private \wpdb $db;

	/** @var string Повна назва таблиці монет (з префіксом). */
	private string $table;

	/**
	 * ID поточного користувача WordPress (wp_users.ID).
	 * Зберігається у колонці id_user. За замовчуванням — поточний юзер.
	 *
	 * @var int
	 */
	private int $id_user;

	/**
	 * Конструктор. Ініціалізує з'єднання з БД та визначає користувача.
	 *
	 * @param int $id_user Явне вказання ID користувача (0 = поточний).
	 */
	public function __construct( int $id_user = 0 ) {
		global $wpdb;
		$this->db      = $wpdb;
		$this->table   = $wpdb->prefix . NUM_TABLE_COINS;
		$this->id_user = $id_user > 0 ? $id_user : (int) get_current_user_id();
	}

	// ── Читання даних ─────────────────────────────────────────────────────────

	/**
	 * Повертає посторінковий список монет поточного користувача.
	 * Підтримує пошук за назвою та фільтрацію за роком і матеріалом.
	 *
	 * @param array{
	 *   search:   string,
	 *   year:     int|string,
	 *   material: string,
	 *   per_page: int,
	 *   offset:   int,
	 * } $args Параметри запиту.
	 *
	 * @return array{ items: array<object>, total: int }
	 */
	public function get_coins( array $args ): array {
		$search   = $args['search']   ?? '';
		$year     = $args['year']     ?? '';
		$material = $args['material'] ?? '';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 10 ) );
		$offset   = max( 0, (int) ( $args['offset']   ?? 0 ) );

		// Обов'язкове обмеження за користувачем.
		$where  = ' WHERE id_user = %d';
		$params = [ $this->id_user ];

		if ( '' !== $search ) {
			$where   .= ' AND name LIKE %s';
			$params[] = '%' . $this->db->esc_like( $search ) . '%';
		}
		if ( '' !== $year ) {
			$where   .= ' AND year = %d';
			$params[] = (int) $year;
		}
		if ( '' !== $material ) {
			$where   .= ' AND material = %s';
			$params[] = $material;
		}

		// Загальна кількість записів (для пагінації).
		$count_sql = "SELECT COUNT(*) FROM {$this->table}" . $where;
		$total     = (int) $this->db->get_var( $this->db->prepare( $count_sql, ...$params ) );

		// Дані поточної сторінки.
		$data_sql = "SELECT * FROM {$this->table}" . $where . ' ORDER BY sorting ASC, id ASC LIMIT %d OFFSET %d';
		$params[] = $per_page;
		$params[] = $offset;

		$items = (array) $this->db->get_results(
			$this->db->prepare( $data_sql, ...$params )
		);

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Повертає одну монету за ID.
	 * Якщо запис не належить поточному користувачу — повертає null.
	 *
	 * @param int $id Ідентифікатор монети.
	 * @return object|null
	 */
	public function get_coin( int $id ): ?object {
		$result = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d AND id_user = %d LIMIT 1",
				$id,
				$this->id_user
			)
		);
		return $result instanceof \stdClass ? $result : null;
	}

	/**
	 * Повертає список унікальних років монет поточного користувача.
	 * Використовується для наповнення випадаючого фільтру.
	 *
	 * @return int[]
	 */
	public function get_distinct_years(): array {
		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT DISTINCT year FROM {$this->table}
				 WHERE id_user = %d AND year IS NOT NULL
				 ORDER BY year DESC",
				$this->id_user
			)
		);
		return array_map( 'intval', array_filter( $rows ) );
	}

	/**
	 * Повертає список унікальних матеріалів монет поточного користувача.
	 * Використовується для наповнення випадаючого фільтру.
	 *
	 * @return string[]
	 */
	public function get_distinct_materials(): array {
		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT DISTINCT material FROM {$this->table}
				 WHERE id_user = %d AND material != ''
				 ORDER BY material ASC",
				$this->id_user
			)
		);
		return array_values( array_filter( $rows, 'strlen' ) );
	}

	// ── Запис даних ───────────────────────────────────────────────────────────

	/**
	 * Додає нову монету до колекції поточного користувача.
	 * Поле id_user встановлюється автоматично.
	 *
	 * @param array<string, mixed> $data Санітизовані поля монети.
	 * @return int|false ID нового запису або false у разі помилки.
	 */
	public function insert_coin( array $data ): int|false {
		$prepared            = $this->prepare_data( $data );
		$prepared['id_user'] = $this->id_user; // Прив'язуємо до поточного юзера.

		$result = $this->db->insert(
			$this->table,
			$prepared,
			$this->get_format( $prepared )
		);

		return false !== $result ? (int) $this->db->insert_id : false;
	}

	/**
	 * Оновлює монету поточного користувача.
	 * WHERE містить id_user — захист від редагування чужих записів.
	 *
	 * @param int                  $id   Ідентифікатор монети.
	 * @param array<string, mixed> $data Санітизовані поля монети.
	 * @return bool true — успішно, false — помилка або запис не знайдено.
	 */
	public function update_coin( int $id, array $data ): bool {
		$prepared = $this->prepare_data( $data );
		$result   = $this->db->update(
			$this->table,
			$prepared,
			[ 'id' => $id, 'id_user' => $this->id_user ],
			$this->get_format( $prepared ),
			[ '%d', '%d' ]
		);
		return false !== $result;
	}

	/**
	 * Видаляє монету поточного користувача.
	 * WHERE містить id_user — захист від видалення чужих записів.
	 *
	 * @param int $id Ідентифікатор монети.
	 * @return bool true — успішно, false — помилка.
	 */
	public function delete_coin( int $id ): bool {
		$result = $this->db->delete(
			$this->table,
			[ 'id' => $id, 'id_user' => $this->id_user ],
			[ '%d', '%d' ]
		);
		return false !== $result;
	}

	// ── Приватні допоміжні методи ─────────────────────────────────────────────

	/**
	 * Перетворює сирі дані з POST-запиту на типізований масив для БД.
	 * Поле id_user у цьому методі не встановлюється — воно задається окремо.
	 *
	 * @param array<string, mixed> $data Сирі дані.
	 * @return array<string, mixed>
	 */
	private function prepare_data( array $data ): array {
		return [
			'name'        => (string) ( $data['name']        ?? '' ),
			'url'         => (string) ( $data['url']         ?? '' ),
			'year'        => isset( $data['year'] ) && '' !== $data['year'] ? (int) $data['year'] : null,
			'material'    => (string) ( $data['material']    ?? '' ),
			'circulation' => (string) ( $data['circulation'] ?? '' ),
			'price'       => isset( $data['price'] ) && '' !== $data['price'] ? (float) $data['price'] : null,
			'photo'       => (string) ( $data['photo']       ?? '' ),
			'quantity'    => (int) ( $data['quantity'] ?? 0 ),
			'notes'       => (string) ( $data['notes']       ?? '' ),
			'sorting'     => (int) ( $data['sorting'] ?? 0 ),
		];
	}

	/**
	 * Повертає масив форматів для $wpdb->insert() / update().
	 * Підтримує поле id_user, якщо воно присутнє у $data.
	 *
	 * @param array<string, mixed> $data Підготовлені дані (можуть включати id_user).
	 * @return string[] Масив форматів ('%s', '%d', '%f').
	 */
	private function get_format( array $data ): array {
		$map = [
			'id_user'     => '%d',
			'name'        => '%s',
			'url'         => '%s',
			'year'        => null === ( $data['year']  ?? null ) ? '%s' : '%d',
			'material'    => '%s',
			'circulation' => '%s',
			'price'       => null === ( $data['price'] ?? null ) ? '%s' : '%f',
			'photo'       => '%s',
			'quantity'    => '%d',
			'notes'       => '%s',
			'sorting'     => '%d',
		];
		$out = [];
		foreach ( array_keys( $data ) as $key ) {
			$out[] = $map[ $key ] ?? '%s';
		}
		return $out;
	}
}

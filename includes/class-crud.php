<?php
/**
 * Central CRUD node for all database operations on the coins table.
 * All queries use $wpdb->prepare() to prevent SQL injection.
 *
 * @package Numismatist
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Num_CRUD
 */
final class Num_CRUD {

	/** @var \wpdb */
	private \wpdb $db;

	/** @var string Fully-qualified table name. */
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->db    = $wpdb;
		$this->table = $wpdb->prefix . NUM_TABLE_COINS;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Retrieve a paginated, filtered, and searched list of coins.
	 *
	 * @param array{
	 *   search: string,
	 *   year: int|string,
	 *   material: string,
	 *   per_page: int,
	 *   offset: int,
	 * } $args Query parameters.
	 *
	 * @return array{ items: array<object>, total: int }
	 */
	public function get_coins( array $args ): array {
		$search   = $args['search']   ?? '';
		$year     = $args['year']     ?? '';
		$material = $args['material'] ?? '';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 10 ) );
		$offset   = max( 0, (int) ( $args['offset']   ?? 0 ) );

		$where  = ' WHERE 1=1';
		$params = [];

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

		// Total count.
		$count_sql = "SELECT COUNT(*) FROM {$this->table}" . $where;
		$total     = (int) ( empty( $params )
			? $this->db->get_var( $count_sql )
			: $this->db->get_var( $this->db->prepare( $count_sql, ...$params ) ) );

		// Data query.
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
	 * Retrieve a single coin by ID.
	 *
	 * @param int $id Record ID.
	 * @return object|null
	 */
	public function get_coin( int $id ): ?object {
		$result = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
				$id
			)
		);
		return $result instanceof \stdClass ? $result : null;
	}

	/**
	 * Get distinct years for the filter dropdown.
	 *
	 * @return int[]
	 */
	public function get_distinct_years(): array {
		$rows = $this->db->get_col(
			"SELECT DISTINCT year FROM {$this->table} WHERE year IS NOT NULL ORDER BY year DESC"
		);
		return array_map( 'intval', array_filter( $rows ) );
	}

	/**
	 * Get distinct non-empty materials for the filter dropdown.
	 *
	 * @return string[]
	 */
	public function get_distinct_materials(): array {
		$rows = $this->db->get_col(
			"SELECT DISTINCT material FROM {$this->table} WHERE material != '' ORDER BY material ASC"
		);
		return array_values( array_filter( $rows, 'strlen' ) );
	}

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Insert a new coin record.
	 *
	 * @param array<string, mixed> $data Sanitized field data.
	 * @return int|false Inserted ID or false on failure.
	 */
	public function insert_coin( array $data ): int|false {
		$result = $this->db->insert(
			$this->table,
			$this->prepare_data( $data ),
			$this->get_format( $data )
		);
		return false !== $result ? (int) $this->db->insert_id : false;
	}

	/**
	 * Update an existing coin record.
	 *
	 * @param int                  $id   Record ID.
	 * @param array<string, mixed> $data Sanitized field data.
	 * @return bool
	 */
	public function update_coin( int $id, array $data ): bool {
		$result = $this->db->update(
			$this->table,
			$this->prepare_data( $data ),
			[ 'id' => $id ],
			$this->get_format( $data ),
			[ '%d' ]
		);
		return false !== $result;
	}

	/**
	 * Delete a coin record.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function delete_coin( int $id ): bool {
		$result = $this->db->delete(
			$this->table,
			[ 'id' => $id ],
			[ '%d' ]
		);
		return false !== $result;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Map raw POST data to typed DB fields.
	 *
	 * @param array<string, mixed> $data Raw data.
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
	 * Return the sprintf-style format array for $wpdb->insert/update.
	 *
	 * @param array<string, mixed> $data Prepared data.
	 * @return string[]
	 */
	private function get_format( array $data ): array {
		$map = [
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

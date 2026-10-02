<?php
/**
 * Persistence of generated variants.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Storage;

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- Repository over plugin tables: table names come from DatabaseManager, column lists are internal constants and every value is bound through $wpdb->prepare().

/**
 * Class VariantRepository
 */
class VariantRepository {

	/**
	 * Object cache group shared by the repositories.
	 */
	const CACHE_GROUP = 'trust_optimize';

	/**
	 * Columns that hold integers.
	 *
	 * @var string[]
	 */
	const INT_COLUMNS = array( 'id', 'attachment_id', 'width', 'height', 'quality', 'file_size', 'source_file_size' );

	/**
	 * Nullable text columns.
	 *
	 * @var string[]
	 */
	const NULLABLE_COLUMNS = array( 'relative_path', 'file_hash', 'reason' );

	/**
	 * Columns written by upsert(), with defaults for a new row.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'status'               => VariantStatus::PENDING,
		'naming'               => 'v2',
		'source_relative_path' => '',
		'relative_path'        => null,
		'width'                => 0,
		'height'               => 0,
		'quality'              => 0,
		'file_size'            => 0,
		'source_file_size'     => 0,
		'file_hash'            => null,
		'reason'               => null,
	);

	/**
	 * Variants table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager $database Database manager.
	 */
	public function __construct( DatabaseManager $database ) {
		$tables      = $database->get_plugin_table_names();
		$this->table = $tables['variants'];
	}

	/**
	 * Insert or update the variant identified by (attachment_id, size_name, format).
	 *
	 * Only the columns present in $data are overwritten on an existing row.
	 *
	 * @param array $data Must contain attachment_id, size_name and format.
	 * @return int Row id.
	 */
	public function upsert( array $data ) {
		global $wpdb;

		$attachment_id = (int) $data['attachment_id'];
		$now           = current_time( 'mysql', true );
		$insert        = array_merge(
			self::DEFAULTS,
			array_intersect_key( $data, self::DEFAULTS ),
			array(
				'attachment_id' => $attachment_id,
				'size_name'     => (string) $data['size_name'],
				'format'        => (string) $data['format'],
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		$update        = array_merge( array_intersect_key( $data, self::DEFAULTS ), array( 'updated_at' => $now ) );

		$columns = array();
		$values  = array();
		$args    = array();
		foreach ( $insert as $column => $value ) {
			list( $placeholder, $arg ) = $this->placeholder( $column, $value );
			$columns[]                 = $column;
			$values[]                  = $placeholder;
			if ( null !== $arg ) {
				$args[] = $arg;
			}
		}

		$assignments = array();
		foreach ( array_keys( $update ) as $column ) {
			$assignments[] = "{$column} = VALUES({$column})";
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table} (" . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $assignments ),
				$args
			)
		);

		$this->invalidate( $attachment_id );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE attachment_id = %d AND size_name = %s AND format = %s",
				$attachment_id,
				(string) $data['size_name'],
				(string) $data['format']
			)
		);
	}

	/**
	 * Atomically move a variant between statuses (compare-and-set).
	 *
	 * @param int    $id     Variant row id.
	 * @param string $from   Expected current status.
	 * @param string $to     New status.
	 * @param array  $fields Further columns to set together with the status.
	 * @return bool True when this call performed the transition.
	 */
	public function transition( $id, $from, $to, array $fields = array() ) {
		global $wpdb;

		$set  = array_merge( array_intersect_key( $fields, self::DEFAULTS ), array( 'status' => $to ) );
		$sets = array();
		$args = array();
		foreach ( $set as $column => $value ) {
			list( $placeholder, $arg ) = $this->placeholder( $column, $value );
			$sets[]                    = "{$column} = {$placeholder}";
			if ( null !== $arg ) {
				$args[] = $arg;
			}
		}
		$sets[] = 'updated_at = %s';
		$args[] = current_time( 'mysql', true );
		$args[] = (int) $id;
		$args[] = $from;

		$changed = 1 === (int) $wpdb->query(
			$wpdb->prepare( "UPDATE {$this->table} SET " . implode( ', ', $sets ) . ' WHERE id = %d AND status = %s', $args )
		);

		if ( $changed ) {
			$this->invalidate_by_id( $id );
		}

		return $changed;
	}

	/**
	 * All variants of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array[] Rows ordered by id.
	 */
	public function get_for_attachment( $attachment_id ) {
		$rows = $this->load( array( (int) $attachment_id ) );

		return $rows[ (int) $attachment_id ];
	}

	/**
	 * Variants with status "done" of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array[]
	 */
	public function get_done_for_attachment( $attachment_id ) {
		$done = $this->get_done_for_attachments( array( $attachment_id ) );

		return $done[ (int) $attachment_id ];
	}

	/**
	 * Variants with status "done" for several attachments in one query at most.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return array[] Rows keyed by attachment ID (every requested ID is present).
	 */
	public function get_done_for_attachments( array $attachment_ids ) {
		$ids    = array_values( array_unique( array_map( 'intval', $attachment_ids ) ) );
		$loaded = $this->load( $ids );
		$result = array();

		foreach ( $ids as $id ) {
			$result[ $id ] = array_values(
				array_filter(
					$loaded[ $id ],
					static function ( $row ) {
						return VariantStatus::DONE === $row['status'];
					}
				)
			);
		}

		return $result;
	}

	/**
	 * Variants generated from a given source file.
	 *
	 * @param string $source_relative_path Source path relative to uploads.
	 * @return array[]
	 */
	public function find_by_source_path( $source_relative_path ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE source_relative_path = %s ORDER BY id", $source_relative_path ),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize' ), (array) $rows );
	}

	/**
	 * Whether a variant row owns the file at a relative path.
	 *
	 * @param string $relative_path Path relative to uploads.
	 * @return bool
	 */
	public function owns( $relative_path ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$this->table} WHERE relative_path = %s LIMIT 1", $relative_path )
		);
	}

	/**
	 * Delete one variant row.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		$attachment_id = $this->attachment_id_of( $id );
		$deleted       = 1 === (int) $wpdb->delete( $this->table, array( 'id' => (int) $id ), array( '%d' ) );

		if ( null !== $attachment_id ) {
			$this->invalidate( $attachment_id );
		}

		return $deleted;
	}

	/**
	 * Delete every variant row of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function delete_for_attachment( $attachment_id ) {
		global $wpdb;

		$wpdb->delete( $this->table, array( 'attachment_id' => (int) $attachment_id ), array( '%d' ) );
		$this->invalidate( (int) $attachment_id );
	}

	/**
	 * IDs of attachments that have variant rows, in ascending order.
	 *
	 * @param int $after_id Return IDs greater than this one.
	 * @param int $limit    Maximum number of IDs.
	 * @return int[]
	 */
	public function get_attachment_ids_after( $after_id, $limit ) {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT attachment_id FROM {$this->table} WHERE attachment_id > %d ORDER BY attachment_id LIMIT %d",
					(int) $after_id,
					(int) $limit
				)
			)
		);
	}

	/**
	 * Number of variants per status for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int[] Counts keyed by status.
	 */
	public function count_by_status( $attachment_id ) {
		$counts = array();

		foreach ( $this->get_for_attachment( $attachment_id ) as $row ) {
			$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * Rows for the given attachments, from cache or one query.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array[] Rows keyed by attachment ID.
	 */
	private function load( array $ids ) {
		global $wpdb;

		$result  = array();
		$missing = array();

		foreach ( $ids as $id ) {
			$cached = wp_cache_get( $this->cache_key( $id ), self::CACHE_GROUP );
			if ( false === $cached ) {
				$missing[]     = $id;
				$result[ $id ] = array();
			} else {
				$result[ $id ] = $cached;
			}
		}

		if ( $missing ) {
			$in   = implode( ', ', array_fill( 0, count( $missing ), '%d' ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$this->table} WHERE attachment_id IN ({$in}) ORDER BY id", $missing ),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$row                               = $this->normalize( $row );
				$result[ $row['attachment_id'] ][] = $row;
			}

			foreach ( $missing as $id ) {
				wp_cache_set( $this->cache_key( $id ), $result[ $id ], self::CACHE_GROUP );
			}
		}

		return $result;
	}

	/**
	 * Placeholder and bound argument for a column; NULL is written as a literal.
	 *
	 * @param string $column Column name.
	 * @param mixed  $value  Value.
	 * @return array{0:string,1:mixed} Placeholder SQL and argument (null when none).
	 */
	private function placeholder( $column, $value ) {
		if ( null === $value && in_array( $column, self::NULLABLE_COLUMNS, true ) ) {
			return array( 'NULL', null );
		}

		if ( in_array( $column, self::INT_COLUMNS, true ) ) {
			return array( '%d', (int) $value );
		}

		return array( '%s', (string) $value );
	}

	/**
	 * Cast numeric columns of a row.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private function normalize( array $row ) {
		foreach ( self::INT_COLUMNS as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		return $row;
	}

	/**
	 * Attachment ID of a variant row.
	 *
	 * @param int $id Row id.
	 * @return int|null
	 */
	private function attachment_id_of( $id ) {
		global $wpdb;

		$attachment_id = $wpdb->get_var( $wpdb->prepare( "SELECT attachment_id FROM {$this->table} WHERE id = %d", (int) $id ) );

		return null === $attachment_id ? null : (int) $attachment_id;
	}

	/**
	 * Drop the cache entry of the attachment owning a row.
	 *
	 * @param int $id Row id.
	 */
	private function invalidate_by_id( $id ) {
		$attachment_id = $this->attachment_id_of( $id );

		if ( null !== $attachment_id ) {
			$this->invalidate( $attachment_id );
		}
	}

	/**
	 * Drop the cache entry of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function invalidate( $attachment_id ) {
		wp_cache_delete( $this->cache_key( $attachment_id ), self::CACHE_GROUP );
	}

	/**
	 * Cache key.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function cache_key( $attachment_id ) {
		return 'variants_' . (int) $attachment_id;
	}
}

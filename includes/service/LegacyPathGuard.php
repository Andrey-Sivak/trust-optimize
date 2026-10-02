<?php
/**
 * Protection of other attachments' files from deletion of 1.x variants.
 *
 * @package TrustOptimize\Service
 */

namespace TrustOptimize\Service;

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Migration\LegacyManifest;
use TrustOptimize\Storage\VariantRepository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- Reads core and plugin tables: names come from $wpdb and DatabaseManager, every value is bound through $wpdb->prepare().

/**
 * Class LegacyPathGuard
 *
 * A 1.x variant was named after its source without the extension ("logo.webp" -> "logo.png"),
 * so its path can be the original or a thumbnail of another attachment, or the file of
 * another attachment's 1.x variant. Such a file must never be deleted. 2.0 names cannot
 * collide this way, so only 1.x paths are checked.
 */
class LegacyPathGuard {

	/**
	 * Why a path is not ours: the file of another attachment.
	 */
	const SOURCE_ATTACHMENT_FILE = 'attachment_file';

	/**
	 * Why a path is not ours: the file of another attachment's variant row.
	 */
	const SOURCE_VARIANT = 'variant';

	/**
	 * Why a path is not ours: the file in the 1.x registry of another attachment.
	 */
	const SOURCE_LEGACY_REGISTRY = 'legacy_registry';

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	private $database;

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager   $database Database manager.
	 * @param VariantRepository $variants Variant repository.
	 */
	public function __construct( DatabaseManager $database, VariantRepository $variants ) {
		$this->database = $database;
		$this->variants = $variants;
	}

	/**
	 * Paths that belong to something else than the given attachment's own variants.
	 *
	 * @param int      $attachment_id Attachment whose 1.x variants use the paths.
	 * @param string[] $paths         Paths relative to uploads.
	 * @return array[] For every conflicting path: array( 'attachment_id' => owner or 0, 'source' => SOURCE_* ).
	 */
	public function find_conflicts( $attachment_id, array $paths ) {
		$attachment_id = (int) $attachment_id;
		$paths         = array_values( array_unique( array_filter( $paths ) ) );
		$conflicts     = array();

		if ( empty( $paths ) ) {
			return $conflicts;
		}

		foreach ( $this->attachment_files( $attachment_id, $paths ) as $path => $owner ) {
			$conflicts[ $path ] = array(
				'attachment_id' => $owner,
				'source'        => self::SOURCE_ATTACHMENT_FILE,
			);
		}

		foreach ( $this->variants->find_other_owners( $attachment_id, $paths ) as $path => $owner ) {
			$conflicts += array(
				$path => array(
					'attachment_id' => $owner,
					'source'        => self::SOURCE_VARIANT,
				),
			);
		}

		foreach ( $this->legacy_registry( $attachment_id, $paths ) as $path => $owner ) {
			$conflicts += array(
				$path => array(
					'attachment_id' => $owner,
					'source'        => self::SOURCE_LEGACY_REGISTRY,
				),
			);
		}

		return $conflicts;
	}

	/**
	 * Paths that are the original, the original image or a size file of another attachment.
	 *
	 * @param int      $attachment_id Attachment to ignore.
	 * @param string[] $paths         Paths relative to uploads.
	 * @return int[] Owner attachment ID keyed by path.
	 */
	private function attachment_files( $attachment_id, array $paths ) {
		global $wpdb;

		$found = array();
		$in    = implode( ', ', array_fill( 0, count( $paths ), '%s' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value AS file FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$in}) AND post_id <> %d",
				array_merge( $paths, array( $attachment_id ) )
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$found[ $row['file'] ] = (int) $row['post_id'];
		}

		// Thumbnails and the original of a scaled image live next to the attached file only in metadata.
		$by_dir = array();
		foreach ( array_diff( $paths, array_keys( $found ) ) as $path ) {
			$by_dir[ self::directory_of( $path ) ][] = $path;
		}

		foreach ( $by_dir as $dir => $dir_paths ) {
			$like = '' === $dir ? '%' : $wpdb->esc_like( $dir . '/' ) . '%';
			$ids  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value AS file FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s AND post_id <> %d",
					$like,
					$attachment_id
				),
				ARRAY_A
			);

			$ids = array_filter(
				(array) $ids,
				static function ( $row ) use ( $dir ) {
					return self::directory_of( $row['file'] ) === $dir;
				}
			);

			update_meta_cache( 'post', array_column( $ids, 'post_id' ) );

			foreach ( $ids as $row ) {
				$metadata = wp_get_attachment_metadata( (int) $row['post_id'] );
				$files    = array();

				if ( is_array( $metadata ) ) {
					$files[] = $metadata['original_image'] ?? null;
					foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
						$files[] = $size['file'] ?? null;
					}
				}

				foreach ( array_filter( $files ) as $file ) {
					$path = ( '' === $dir ? '' : $dir . '/' ) . basename( $file );

					if ( in_array( $path, $dir_paths, true ) ) {
						$found += array( $path => (int) $row['post_id'] );
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Paths listed in the 1.x registry of another attachment.
	 *
	 * @param int      $attachment_id Attachment to ignore.
	 * @param string[] $paths         Paths relative to uploads.
	 * @return int[] Owner attachment ID keyed by path.
	 */
	private function legacy_registry( $attachment_id, array $paths ) {
		global $wpdb;

		$table = $this->database->get_plugin_table_names()['images'];

		if ( ! $this->database->table_exists( $table ) ) {
			return array();
		}

		$likes = array();
		$args  = array();
		foreach ( $paths as $path ) {
			$likes[] = 'metadata LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( wp_json_encode( basename( $path ) ) ) . '%';
		}
		$args[] = $attachment_id;

		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT attachment_id, metadata FROM {$table} WHERE (" . implode( ' OR ', $likes ) . ') AND attachment_id <> %d', $args ),
			ARRAY_A
		);
		$found = array();

		foreach ( (array) $rows as $row ) {
			foreach ( LegacyManifest::variants( (int) $row['attachment_id'], $row['metadata'] ) as $entry ) {
				if ( in_array( $entry['relative_path'], $paths, true ) ) {
					$found += array( $entry['relative_path'] => (int) $row['attachment_id'] );
				}
			}
		}

		return $found;
	}

	/**
	 * Directory part of a path relative to uploads.
	 *
	 * @param string $path Path relative to uploads.
	 * @return string Empty string for the uploads root.
	 */
	private static function directory_of( $path ) {
		$dir = dirname( $path );

		return '.' === $dir ? '' : $dir;
	}
}

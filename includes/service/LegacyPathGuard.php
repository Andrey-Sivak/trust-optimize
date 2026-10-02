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
	 * Files per directory, kept while a batch runs (see begin_batch()); null when not caching.
	 *
	 * @var array|null
	 */
	private $directories = null;

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
	 * Remember the files of every directory until end_batch().
	 *
	 * For a loop over many attachments: the metadata of a directory is read once instead of once per
	 * attachment. Nothing may be added to the media library meanwhile.
	 */
	public function begin_batch() {
		$this->directories = array();
	}

	/**
	 * Forget the remembered directories.
	 */
	public function end_batch() {
		$this->directories = null;
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
		$found = array();

		foreach ( array_unique( array_map( array( __CLASS__, 'directory_of' ), $paths ) ) as $dir ) {
			$files = $this->directory_files( $dir );

			foreach ( $paths as $path ) {
				foreach ( $files[ $path ] ?? array() as $owner ) {
					if ( $owner !== $attachment_id ) {
						$found += array( $path => $owner );
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Files that attachments keep in a directory: the attached file, the original of a scaled image and every size.
	 *
	 * @param string $dir Directory relative to uploads ('' for the uploads root).
	 * @return int[][] Attachment IDs keyed by path relative to uploads.
	 */
	private function directory_files( $dir ) {
		global $wpdb;

		if ( isset( $this->directories[ $dir ] ) ) {
			return $this->directories[ $dir ];
		}

		$like  = '' === $dir ? '%' : $wpdb->esc_like( $dir . '/' ) . '%';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_id, meta_value AS file FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", $like ),
			ARRAY_A
		);
		$rows  = array_filter(
			(array) $rows,
			static function ( $row ) use ( $dir ) {
				return self::directory_of( $row['file'] ) === $dir;
			}
		);
		$files = array();

		update_meta_cache( 'post', array_column( $rows, 'post_id' ) );

		foreach ( $rows as $row ) {
			$id       = (int) $row['post_id'];
			$metadata = wp_get_attachment_metadata( $id );
			$names    = array( basename( $row['file'] ) );

			if ( is_array( $metadata ) ) {
				$names[] = $metadata['original_image'] ?? null;
				foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
					$names[] = $size['file'] ?? null;
				}
			}

			foreach ( array_filter( $names ) as $name ) {
				$files[ ( '' === $dir ? '' : $dir . '/' ) . basename( $name ) ][] = $id;
			}
		}

		if ( null !== $this->directories ) {
			$this->directories[ $dir ] = $files;
		}

		return $files;
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

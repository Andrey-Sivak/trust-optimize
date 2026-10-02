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
	 * Owners per path, kept while a batch runs (see begin_batch()); null when not caching.
	 *
	 * @var array|null
	 */
	private $owners = null;

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
	 * Remember who owns the paths asked about until end_batch().
	 *
	 * For a loop over many attachments: a path is looked up once however many attachments ask. Nothing may be added to the media library meanwhile.
	 */
	public function begin_batch() {
		$this->owners = array();
	}

	/**
	 * Forget the remembered paths.
	 */
	public function end_batch() {
		$this->owners = null;
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

		foreach ( $this->owners_of( $paths ) as $path => $owners ) {
			foreach ( $owners as $owner ) {
				if ( $owner !== $attachment_id ) {
					$found += array( $path => $owner );
				}
			}
		}

		return $found;
	}

	/**
	 * Attachments that keep a file at each path: as the attached file, the original of a scaled image or a size.
	 *
	 * One query for all paths; only the attachments that mention one of the file names are read.
	 *
	 * @param string[] $paths Paths relative to uploads.
	 * @return int[][] Attachment IDs keyed by path (every path is present).
	 */
	private function owners_of( array $paths ) {
		global $wpdb;

		$owners  = array();
		$unknown = array();

		foreach ( $paths as $path ) {
			if ( isset( $this->owners[ $path ] ) ) {
				$owners[ $path ] = $this->owners[ $path ];
			} else {
				$owners[ $path ] = array();
				$unknown[]       = $path;
			}
		}

		if ( empty( $unknown ) ) {
			return $owners;
		}

		$in    = implode( ', ', array_fill( 0, count( $unknown ), '%s' ) );
		$likes = array();
		$args  = $unknown;
		foreach ( $unknown as $path ) {
			$name    = basename( $path );
			$likes[] = 'm.meta_value LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( 's:' . strlen( $name ) . ':"' . $name . '";' ) . '%';
		}

		// Size files and original_image exist only inside the serialized metadata, so it is matched by the serialized file name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.post_id, a.meta_value AS file, m.meta_value AS data FROM {$wpdb->postmeta} a LEFT JOIN {$wpdb->postmeta} m ON m.post_id = a.post_id AND m.meta_key = '_wp_attachment_metadata' WHERE a.meta_key = '_wp_attached_file' AND (a.meta_value IN ({$in}) OR " . implode( ' OR ', $likes ) . ')',
				$args
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$dir      = self::directory_of( $row['file'] );
			$metadata = maybe_unserialize( $row['data'] );
			$names    = array( basename( $row['file'] ) );

			if ( is_array( $metadata ) ) {
				$names[] = $metadata['original_image'] ?? null;
				foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
					$names[] = $size['file'] ?? null;
				}
			}

			foreach ( array_filter( $names ) as $name ) {
				$path = ( '' === $dir ? '' : $dir . '/' ) . basename( $name );

				if ( in_array( $path, $unknown, true ) ) {
					$owners[ $path ][] = (int) $row['post_id'];
				}
			}
		}

		if ( null !== $this->owners ) {
			foreach ( $unknown as $path ) {
				$this->owners[ $path ] = $owners[ $path ];
			}
		}

		return $owners;
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

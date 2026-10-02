<?php
/**
 * Report of 1.x files that collide with files of other attachments.
 *
 * @package TrustOptimize\Migration
 */

namespace TrustOptimize\Migration;

/**
 * Class ConflictReport
 *
 * Schema 1.x could write a variant over a file of another attachment (H-1), so the
 * original of that attachment may be gone. Such files are never touched; they are
 * listed here for the site owner, who can restore the originals from a backup.
 */
class ConflictReport {

	/**
	 * Option that holds the report.
	 */
	const OPTION = 'trust_optimize_migration_conflicts';

	/**
	 * Record a conflict (once per attachment and path).
	 *
	 * @param int    $attachment_id Attachment whose 1.x variant uses the path.
	 * @param string $path          Path relative to uploads.
	 * @param int    $conflicts_with Attachment that owns the file, 0 when it is another variant.
	 * @param string $source        What owns the file: attachment_file, variant or legacy_registry.
	 */
	public function add( $attachment_id, $path, $conflicts_with, $source ) {
		$entries = $this->all();
		$key     = (int) $attachment_id . ':' . $path;

		if ( isset( $entries[ $key ] ) ) {
			return;
		}

		$entries[ $key ] = array(
			'attachment_id'  => (int) $attachment_id,
			'path'           => (string) $path,
			'conflicts_with' => (int) $conflicts_with,
			'source'         => (string) $source,
			'found_at'       => current_time( 'mysql', true ),
		);

		update_option( self::OPTION, $entries, false );
	}

	/**
	 * All recorded conflicts.
	 *
	 * @return array[] Entries keyed by "attachment_id:path".
	 */
	public function all() {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? $entries : array();
	}
}

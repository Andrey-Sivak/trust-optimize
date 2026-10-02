<?php
/**
 * WP-CLI commands for the migration report.
 *
 * @package TrustOptimize\CLI
 */

namespace TrustOptimize\CLI;

use TrustOptimize\Migration\ConflictReport;

/**
 * Class MigrationCommand
 */
class MigrationCommand {

	/**
	 * Conflict report.
	 *
	 * @var ConflictReport
	 */
	private $conflicts;

	/**
	 * Constructor.
	 *
	 * @param ConflictReport $conflicts Conflict report.
	 */
	public function __construct( ConflictReport $conflicts ) {
		$this->conflicts = $conflicts;
	}

	/**
	 * List files of TrustOptimize 1.x that collide with files of other attachments.
	 *
	 * The original of an attachment in the list may have been overwritten by TrustOptimize 1.x:
	 * restore the file from a backup. The files in the list are never deleted by the plugin.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp trust-optimize migration conflicts --format=csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function conflicts( $args, $assoc_args ) {
		$items = array_values( $this->conflicts->all() );

		if ( empty( $items ) ) {
			\WP_CLI::success( 'No conflicts found.' );
			return;
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $items, array( 'attachment_id', 'path', 'conflicts_with', 'source', 'found_at' ) );
	}
}

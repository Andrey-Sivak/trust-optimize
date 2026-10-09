<?php
/**
 * Helpers for tests that run uninstall.php, which drops the plugin tables.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Storage\VariantRepository;

/**
 * Trait Uninstall_Fixture
 *
 * DROP TABLE commits the test transaction, so whatever the test created stays in the database: the
 * trait puts the tables back and the caller deletes its attachments afterwards.
 */
trait Uninstall_Fixture {

	/**
	 * Previous error_log setting.
	 *
	 * @var string|false
	 */
	private $previous_error_log;

	/**
	 * File that receives error_log() output.
	 *
	 * @var string
	 */
	private $log_file;

	/**
	 * Load the uninstall functions without running a real uninstall.
	 */
	private function load_uninstall_functions() {
		if ( ! function_exists( 'trust_optimize_uninstall_site' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'trust-optimize/trust-optimize.php' );
			update_option( 'trust_optimize_options', array( 'remove_data_on_uninstall' => 0 ) );
			include TRUST_OPTIMIZE_PLUGIN_DIR . 'uninstall.php';
		}
	}

	/**
	 * Send error_log() to a file the test can read.
	 */
	private function capture_error_log() {
		$this->log_file           = wp_tempnam( 'uninstall-log' );
		$this->previous_error_log = ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky
	}

	/**
	 * What error_log() wrote since capture_error_log().
	 *
	 * @return string
	 */
	private function logged() {
		return (string) file_get_contents( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Stop capturing error_log().
	 */
	private function release_error_log() {
		if ( null !== $this->log_file ) {
			ini_set( 'error_log', (string) $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			unlink( $this->log_file );
			$this->log_file = null;
		}
	}

	/**
	 * Stop turning CREATE/DROP TABLE into temporary-table statements: uninstall must drop the real tables.
	 *
	 * Call it first in set_up(), before the test creates data. The next test's set_up() adds the filters again.
	 */
	private function use_real_tables() {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Create the plugin tables of the current site as real tables.
	 *
	 * Roll the test transaction back first when it holds data that must not be committed.
	 */
	private function restore_plugin_tables() {
		$this->use_real_tables();
		( new DatabaseManager() )->create_tables();
	}

	/**
	 * Give the current site a variant row backed by a file.
	 *
	 * @param int    $attachment_id Attachment ID (it does not have to exist).
	 * @param string $relative_dir  Directory relative to uploads, created when missing.
	 * @param string $name          File name of the variant.
	 * @return string Absolute path of the file.
	 */
	private function add_variant_file( $attachment_id, $relative_dir, $name ) {
		$dir  = wp_upload_dir()['basedir'] . '/' . $relative_dir;
		$path = $dir . '/' . $name;
		wp_mkdir_p( $dir );
		file_put_contents( $path, 'variant ' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		( new VariantRepository( new DatabaseManager() ) )->upsert(
			array(
				'attachment_id'        => $attachment_id,
				'size_name'            => $name,
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => $relative_dir . '/source.jpg',
				'relative_path'        => $relative_dir . '/' . $name,
				'file_hash'            => hash( 'sha256', 'variant ' . $name ),
			)
		);

		return $path;
	}

	/**
	 * Whether a plugin table of the current site exists.
	 *
	 * @param string $key Table key: attachments, variants or jobs.
	 * @return bool
	 */
	private function table_exists( $key ) {
		$database = new DatabaseManager();

		return $database->table_exists( $database->get_plugin_table_names()[ $key ] );
	}
}

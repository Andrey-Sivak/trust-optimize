<?php
/**
 * Atomic, collision-safe writing of generated image files.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Files;

use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Utils\UploadsPath;
use WP_Error;
use WP_Image_Editor;

/**
 * Class AtomicImageWriter
 */
class AtomicImageWriter {

	/**
	 * Variant repository (decides which files belong to the plugin).
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * File ownership checks.
	 *
	 * @var FileOwnership
	 */
	private $ownership;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository $variants  Variant repository.
	 * @param FileOwnership     $ownership File ownership checks.
	 */
	public function __construct( VariantRepository $variants, FileOwnership $ownership ) {
		$this->variants  = $variants;
		$this->ownership = $ownership;
	}

	/**
	 * Save an editor's image to a target path through a temporary file.
	 *
	 * The temporary name ends with the target extension: WP_Image_Editor::get_output_format()
	 * replaces an extension that does not match the MIME type.
	 *
	 * @param WP_Image_Editor $editor      Editor holding the image.
	 * @param string          $target_path Absolute target path inside uploads.
	 * @param string          $mime        Expected MIME type of the output.
	 * @return array|WP_Error Editor result with 'path' and 'file' pointing at the target.
	 */
	public function save( WP_Image_Editor $editor, $target_path, $mime ) {
		$target_path = wp_normalize_path( $target_path );

		if ( ! UploadsPath::is_inside( $target_path ) ) {
			return new WP_Error( 'target_outside_uploads', 'Target path is outside the uploads directory.' );
		}

		$relative = ltrim( substr( $target_path, strlen( UploadsPath::basedir() ) ), '/' );
		// An existing file is replaced only when a variant row owns it and no attachment registered it as its original.
		if ( file_exists( $target_path ) && ( ! $this->variants->owns( $relative ) || $this->ownership->is_attachment_file( $relative ) ) ) {
			return new WP_Error( 'target_exists_foreign', 'Target file exists and is not a TrustOptimize variant.' );
		}

		$temp_path = $target_path . '.tmp-' . wp_generate_uuid4() . '.' . pathinfo( $target_path, PATHINFO_EXTENSION );
		$saved     = $editor->save( $temp_path, $mime );

		if ( is_wp_error( $saved ) ) {
			$this->discard( $temp_path );
			return $saved;
		}

		$saved_path = isset( $saved['path'] ) ? wp_normalize_path( $saved['path'] ) : $temp_path;

		if ( ( $saved['mime-type'] ?? '' ) !== $mime || ! file_exists( $saved_path ) ) {
			$this->discard( $temp_path );
			$this->discard( $saved_path );
			return new WP_Error(
				'unexpected_output',
				sprintf( 'Expected %s output, got %s.', $mime, $saved['mime-type'] ?? 'nothing' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace; WP_Filesystem has no equivalent.
		if ( ! rename( $saved_path, $target_path ) ) {
			$this->discard( $saved_path );
			return new WP_Error( 'rename_failed', 'Could not move the generated file into place.' );
		}

		$saved['path'] = $target_path;
		$saved['file'] = basename( $target_path );

		return $saved;
	}

	/**
	 * Remove a temporary file if it exists.
	 *
	 * @param string $path Absolute path.
	 */
	private function discard( $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}

<?php
/**
 * UploadsPath tests.
 *
 * @package TrustOptimize\Tests\Unit
 */

namespace TrustOptimize\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustOptimize\Utils\UploadsPath;

/**
 * Class UploadsPathTest
 */
class UploadsPathTest extends TestCase {

	/**
	 * Scratch directory holding the fake uploads and an outside directory.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Fake uploads directory.
	 *
	 * @var string
	 */
	private $uploads;

	protected function setUp(): void {
		$this->root    = sys_get_temp_dir() . '/trust-optimize-uploads-' . uniqid( '', true );
		$this->uploads = $this->root . '/uploads';
		mkdir( $this->uploads . '/2026/05', 0777, true );
		mkdir( $this->root . '/outside', 0777, true );

		$GLOBALS['trust_optimize_test_uploads']  = $this->uploads;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['trust_optimize_test_uploads'] );
		$this->remove( $this->root );
	}

	/**
	 * Recursively remove a directory without following symlinks.
	 *
	 * @param string $path Path.
	 */
	private function remove( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		foreach ( glob( $path . '/*' ) ?: array() as $child ) {
			$this->remove( $child );
		}
		rmdir( $path );
	}

	public function test_basedir_is_normalized_without_trailing_slash() {
		$GLOBALS['trust_optimize_test_uploads'] = 'C:\\www\\uploads\\';

		$this->assertSame( 'C:/www/uploads', UploadsPath::basedir() );
	}

	public function test_relative_strips_the_uploads_prefix() {
		$this->assertSame( '2026/05/a.jpg', UploadsPath::relative( $this->uploads . '/2026/05/a.jpg' ) );
		$this->assertSame( 'a.jpg', UploadsPath::relative( str_replace( '/', '\\', $this->uploads . '/a.jpg' ) ) );
		$this->assertNull( UploadsPath::relative( $this->root . '/outside/a.jpg' ) );
		$this->assertNull( UploadsPath::relative( $this->uploads ) );
	}

	public function test_resolve_joins_relative_dir_and_file() {
		$this->assertSame( $this->uploads . '/2026/05/a.jpg.webp', UploadsPath::resolve( '2026/05', 'a.jpg.webp' ) );
		$this->assertSame( $this->uploads . '/2026/05/a.jpg', UploadsPath::resolve( '2026/05/', 'a.jpg' ) );
		$this->assertSame( $this->uploads . '/a.jpg', UploadsPath::resolve( '', 'a.jpg' ) );
	}

	public function test_resolve_accepts_windows_separators() {
		$this->assertSame( $this->uploads . '/2026/05/a.jpg', UploadsPath::resolve( '2026\\05', 'a.jpg' ) );
	}

	/**
	 * @dataProvider unsafe_input_provider
	 *
	 * @param string $relative_dir Relative directory.
	 * @param string $file         File name.
	 */
	public function test_resolve_rejects_unsafe_input( $relative_dir, $file ) {
		$this->assertNull( UploadsPath::resolve( $relative_dir, $file ) );
	}

	public function unsafe_input_provider() {
		return array(
			'traversal'          => array( '../outside', 'a.jpg' ),
			'nested traversal'   => array( '2026/../../outside', 'a.jpg' ),
			'dot segment'        => array( '2026/./05', 'a.jpg' ),
			'empty segment'      => array( '2026//05', 'a.jpg' ),
			'absolute'           => array( '/etc', 'a.jpg' ),
			'windows drive'      => array( 'C:/Windows', 'a.jpg' ),
			'empty file'         => array( '2026/05', '' ),
			'file with slash'    => array( '2026/05', 'sub/a.jpg' ),
			'file is traversal'  => array( '2026/05', '..' ),
			'backslash in file'  => array( '2026/05', '..\\a.jpg' ),
		);
	}

	public function test_is_inside_accepts_existing_and_missing_files() {
		touch( $this->uploads . '/2026/05/a.jpg' );

		$this->assertTrue( UploadsPath::is_inside( $this->uploads . '/2026/05/a.jpg' ) );
		$this->assertTrue( UploadsPath::is_inside( $this->uploads . '/2026/05/missing.jpg' ) );
		$this->assertTrue( UploadsPath::is_inside( $this->uploads . '/2027/01/not-created-yet.jpg' ) );
	}

	public function test_is_inside_rejects_paths_outside_and_traversal() {
		$this->assertFalse( UploadsPath::is_inside( $this->root . '/outside/a.jpg' ) );
		$this->assertFalse( UploadsPath::is_inside( $this->uploads . '-evil/a.jpg' ) );
		$this->assertFalse( UploadsPath::is_inside( $this->uploads . '/../outside/a.jpg' ) );
		$this->assertFalse( UploadsPath::is_inside( $this->uploads ) );
	}

	public function test_is_inside_rejects_symlink_pointing_outside() {
		symlink( $this->root . '/outside', $this->uploads . '/link' );

		$this->assertFalse( UploadsPath::is_inside( $this->uploads . '/link/a.jpg' ) );
		$this->assertFalse( UploadsPath::is_inside( $this->uploads . '/link/new/a.jpg' ) );
	}

	public function test_is_inside_accepts_symlinked_uploads_root() {
		symlink( $this->uploads, $this->root . '/uploads-link' );
		$GLOBALS['trust_optimize_test_uploads'] = $this->root . '/uploads-link';

		$this->assertTrue( UploadsPath::is_inside( $this->root . '/uploads-link/2026/05/a.jpg' ) );
	}

	public function test_is_inside_handles_windows_separators() {
		$this->assertTrue( UploadsPath::is_inside( str_replace( '/', '\\', $this->uploads . '/2026/05/a.jpg' ) ) );
	}
}

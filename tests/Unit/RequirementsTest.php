<?php
/**
 * Requirements tests.
 *
 * @package TrustOptimize\Tests\Unit
 */

namespace TrustOptimize\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustOptimize\Core\Requirements;

/**
 * Class RequirementsTest
 */
class RequirementsTest extends TestCase {

	/**
	 * Database version strings and whether they are supported.
	 *
	 * @return array
	 */
	public function database_provider() {
		return array(
			'mysql 5.7'             => array( '5.7.44', true ),
			'mysql 8.0'             => array( '8.0.36', true ),
			'mysql 5.6'             => array( '5.6.51', false ),
			'mariadb with prefix'   => array( '5.5.5-10.6.18-MariaDB', true ),
			'mariadb 10.3'          => array( '10.3.39-MariaDB-log', true ),
			'mariadb 10.2'          => array( '10.2.44-MariaDB', false ),
			'mariadb prefix 10.2'   => array( '5.5.5-10.2.44-MariaDB', false ),
			'mariadb 11'            => array( '11.4.8-MariaDB', true ),
			'mysql fork'            => array( '8.0.35-27-percona', true ),
			'unrecognised'          => array( 'unknown', true ),
		);
	}

	/**
	 * @dataProvider database_provider
	 *
	 * @param string $server_info Server version string.
	 * @param bool   $expected    Expected result.
	 */
	public function test_is_database_supported( $server_info, $expected ) {
		$this->assertSame( $expected, Requirements::is_database_supported( $server_info ) );
	}
}

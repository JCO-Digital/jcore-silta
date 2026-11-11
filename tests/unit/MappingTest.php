<?php
/**
 * Tests for what a mapping remembers about its local object.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Sync\Mapping;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride -- Isolated database fixture.

/**
 * Mapping::set().
 */
final class MappingTest extends TestCase {

	/**
	 * A database holding one mapping row at most.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public string $prefix = 'wp_';

			/**
			 * The row.
			 *
			 * @var array<string, mixed>|null
			 */
			public ?array $row = null;

			/**
			 * Passes the statement on.
			 *
			 * @param string $sql     Statement.
			 * @param mixed  ...$args Arguments.
			 * @return string
			 */
			public function prepare( string $sql, mixed ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Core signature.
				return $sql;
			}

			/**
			 * Reads the row.
			 *
			 * @return array<string, mixed>|null
			 */
			public function get_row(): ?array {
				return $this->row;
			}

			/**
			 * Stores a new row.
			 *
			 * @param string               $table Table.
			 * @param array<string, mixed> $data  Columns.
			 * @return void
			 */
			public function insert( string $table, array $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Core signature.
				$this->row = array( 'id' => 1 ) + $data;
			}

			/**
			 * Changes the row.
			 *
			 * @param string               $table Table.
			 * @param array<string, mixed> $data  Columns.
			 * @return void
			 */
			public function update( string $table, array $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Core signature.
				$this->row = array_merge( (array) $this->row, $data );
			}
		};
	}

	/**
	 * Drops the fixture.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_what_is_known_about_a_local_object_stays_with_it(): void {
		$received = array(
			'files'    => array(),
			'received' => array(
				'local'  => 70,
				'hashes' => array( 'abc' ),
			),
		);

		Mapping::set( 'site-a', 'post', 7, 70, 'r1', 'l1', $received );
		$this->assertSame( $received, Mapping::get( 'site-a', 'post', 7 )['extra'] );

		// Synced again without new files: still the same attachment, still known.
		Mapping::set( 'site-a', 'post', 7, 70, 'r2', 'l2' );
		$this->assertSame( $received, Mapping::get( 'site-a', 'post', 7 )['extra'] );

		// The same remote attachment now maps to another local one, which that
		// site sent no file for: what was known about the first isn't carried over.
		Mapping::set( 'site-a', 'post', 7, 71, 'r3', 'l3' );
		$row = Mapping::get( 'site-a', 'post', 7 );
		$this->assertSame( 71, $row['local_id'] );
		$this->assertSame( array(), $row['extra'] );
	}
}

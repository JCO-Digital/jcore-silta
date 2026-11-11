<?php
/**
 * Database stand-in for raw meta writes.
 *
 * @package Jcore\Silta\Tests
 */

namespace Jcore\Silta\Tests;

/**
 * Captures what Meta_Writer deletes and inserts, without a database.
 */
final class MetaDatabaseStub {
	/**
	 * Post meta table.
	 *
	 * @var string
	 */
	public string $postmeta = 'postmeta';
	/**
	 * Term meta table.
	 *
	 * @var string
	 */
	public string $termmeta = 'termmeta';
	/**
	 * Rows inserted: key => values, in order.
	 *
	 * @var array<string, string[]>
	 */
	public array $inserted = array();
	/**
	 * Keys deleted, in order.
	 *
	 * @var string[]
	 */
	public array $deleted = array();
	/**
	 * Statements run.
	 *
	 * @var int
	 */
	public int $queries = 0;

	/**
	 * Stands in for prepare(): a row becomes JSON, so query() can read back
	 * what would be written; a delete keeps its keys.
	 *
	 * @param string $sql     Statement.
	 * @param mixed  ...$args Arguments, or one array of them.
	 *
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		$args = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
		if ( '(%d, %s, %s)' === $sql ) {
			return (string) wp_json_encode( array( $args[1], $args[2] ) );
		}

		return str_starts_with( $sql, 'DELETE' ) ? 'DELETE ' . wp_json_encode( array_slice( $args, 3 ) ) : 'INSERT ';
	}

	/**
	 * Captures the rows of an insert and the keys of a delete.
	 *
	 * @param string $sql Statement from prepare().
	 *
	 * @return void
	 */
	public function query( string $sql ): void {
		++$this->queries;
		if ( str_starts_with( $sql, 'DELETE ' ) ) {
			$this->deleted = array_merge( $this->deleted, (array) json_decode( substr( $sql, 7 ), true ) );

			return;
		}
		foreach ( (array) json_decode( '[' . substr( $sql, 7 ) . ']', true ) as $row ) {
			$this->inserted[ $row[0] ][] = $row[1];
		}
	}
}

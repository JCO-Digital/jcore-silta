<?php
/**
 * Database stand-in for remote security tests.
 *
 * @package Jcore\Silta\Tests
 */

namespace Jcore\Silta\Tests;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Implements the wpdb call signatures used by the plugin.

/** Database stand-in for ownership reads and unique nonce inserts. */
final class RemoteDatabaseStub {
	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';
	/**
	 * Job row.
	 *
	 * @var array|null
	 */
	public ?array $row = null;
	/**
	 * Writes.
	 *
	 * @var int
	 */
	public int $writes = 0;

	/**
	 * Last columns updated.
	 *
	 * @var array
	 */
	public array $changes = array();
	/**
	 * Fail reservations.
	 *
	 * @var bool
	 */
	public bool $fail = false;
	/**
	 * Reserved nonces.
	 *
	 * @var array
	 */
	private array $nonces = array();
	/**
	 * Last database error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Token holding the job row, '' when it is free.
	 *
	 * @var string
	 */
	public string $lock = '';

	/**
	 * Provides prepare for the test fixture.
	 *
	 * @param string $sql     Sql.
	 * @param mixed  ...$args Args.
	 *
	 * @return array
	 */
	public function prepare( string $sql, mixed ...$args ): array {
		return array( $sql, $args );
	}

	/**
	 * Provides get row for the test fixture.
	 *
	 * @param array  $sql    Sql.
	 * @param string $format Format.
	 *
	 * @return ?array
	 */
	public function get_row( array $sql, string $format ): ?array {
		return $this->row;
	}

	/**
	 * Provides query for the test fixture.
	 *
	 * @param array $query Query.
	 *
	 * @return int|false
	 */
	public function query( array $query ): int|false {
		if ( $this->fail ) {
			return false;
		}
		// Claiming and releasing the job row.
		if ( str_contains( $query[0], 'SET lock_token = %s' ) ) {
			if ( '' !== $this->lock && $this->lock !== $query[1][1] ) {
				return 0;
			}
			$this->lock = $query[1][1];

			return 1;
		}
		if ( str_contains( $query[0], "SET lock_token = ''" ) ) {
			if ( $this->lock === $query[1][2] ) {
				$this->lock = '';
			}

			return 1;
		}
		$nonce = $query[1][1];
		if ( isset( $this->nonces[ $nonce ] ) ) {
			return 0;
		}
		$this->nonces[ $nonce ] = true;
		return 1;
	}

	/**
	 * Answers single-value reads: who holds the job row, and counts (none).
	 *
	 * @param array $query Prepared query.
	 *
	 * @return ?string
	 */
	public function get_var( array $query ): ?string {
		if ( str_contains( $query[0], 'SELECT lock_token' ) ) {
			return $this->lock;
		}

		return str_contains( $query[0], 'COUNT(*)' ) ? '0' : null;
	}

	/**
	 * Provides update for the test fixture.
	 *
	 * @param mixed ...$args Args.
	 *
	 * @return void
	 */
	public function update( mixed ...$args ): void {
		++$this->writes;
		$this->changes = $args[1];
	}

	/**
	 * Returns an empty set of snapshots.
	 *
	 * @param array  $query Prepared query.
	 * @param string $format Row format.
	 *
	 * @return array
	 */
	public function get_results( array $query, string $format ): array {
		return array();
	}

	/**
	 * Records snapshot deletion after undo.
	 *
	 * @param mixed ...$args Database arguments.
	 *
	 * @return void
	 */
	public function delete( mixed ...$args ): void {
		++$this->writes;
	}
}

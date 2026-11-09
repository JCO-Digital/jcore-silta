<?php
/**
 * Tests that a taxonomy with no terms counts as absent when comparing and
 * hashing posts, so a taxonomy registered on only one site (or added later,
 * like a WooCommerce attribute) causes no diffs or false conflicts.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Sync\Differ;
use Jcore\Silta\Sync\State;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Empty term sets.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class TermSetsTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/stubs/woocommerce.php';
		require_once __DIR__ . '/stubs/incoming.php';
		require_once __DIR__ . '/stubs/differ.php';
	}

	/**
	 * A product state.
	 *
	 * @param array<string, int[]> $terms Terms by taxonomy.
	 *
	 * @return array<string, mixed>
	 */
	private function product( array $terms ): array {
		return array(
			'key'          => 'post:50',
			'type'         => 'post',
			'id'           => 50,
			'post_type'    => 'product',
			'modified_gmt' => '2026-01-01 00:00:00',
			'content'      => '',
			'fields'       => array(
				'post_title'  => 'Mug',
				'post_name'   => 'mug',
				'post_status' => 'publish',
			),
			'meta'         => array( '_sku' => array( 'MUG' ) ),
			'terms'        => $terms,
		);
	}

	public function test_an_empty_taxonomy_does_not_change_the_hash(): void {
		$without = $this->product( array( 'product_cat' => array( 15 ) ) );
		$with    = $this->product(
			array(
				'product_cat' => array( 15 ),
				'pa_koko'     => array(),
			)
		);

		$this->assertSame( State::hash( $without ), State::hash( $with ) );
		$this->assertNotSame( State::hash( $without ), State::hash( $this->product( array( 'product_cat' => array( 16 ) ) ) ) );
		$this->assertNotSame(
			State::hash( $without ),
			State::hash(
				$this->product(
					array(
						'product_cat' => array( 15 ),
						'pa_koko'     => array( 3 ),
					)
				)
			),
			'A taxonomy with terms still counts.'
		);
	}

	public function test_a_taxonomy_added_later_does_not_read_as_modified(): void {
		// Stored at the last sync, before the target had pa_koko.
		$stored = State::hash( $this->product( array( 'product_cat' => array( 15 ) ) ) );

		$now = $this->product(
			array(
				'product_cat' => array( 15 ),
				'pa_koko'     => array(),
			)
		);
		$this->assertTrue( State::hash_matches( $stored, $now ) );
	}

	public function test_hashes_stored_by_earlier_versions_still_match(): void {
		$state  = $this->product(
			array(
				'product_cat' => array( 15 ),
				'product_tag' => array(),
			)
		);
		$legacy = State::legacy_hash( $state );

		$this->assertNotSame( $legacy, State::hash( $state ), 'The legacy hash keeps empty term sets.' );
		$this->assertTrue( State::hash_matches( $legacy, $state ) );
		$this->assertFalse( State::hash_matches( $legacy, $this->product( array( 'product_cat' => array( 16 ) ) ) ) );
	}

	public function test_a_taxonomy_only_one_side_has_differs_only_when_it_has_terms(): void {
		$incoming = $this->product(
			array(
				'product_cat' => array( 15 ),
				'pa_koko'     => array(),
			)
		);
		$local    = $this->product( array( 'product_cat' => array( 15 ) ) );

		$sections = Differ::sections( $incoming, $local );
		$this->assertFalse( $sections['terms.pa_koko']['changed'], 'Empty on the source, missing here.' );

		$details = Differ::details( $incoming, $local );
		$this->assertFalse( $details['terms.pa_koko']['changed'] );
		$this->assertSame( $details['terms.pa_koko']['before'], $details['terms.pa_koko']['after'] );

		$incoming['terms']['pa_koko'] = array( 3 );
		$this->assertTrue( Differ::sections( $incoming, $local )['terms.pa_koko']['changed'], 'Terms on the source, missing here.' );

		$local['terms']['pa_koko']    = array( 3 );
		$incoming['terms']['pa_koko'] = array();
		$this->assertTrue( Differ::sections( $incoming, $local )['terms.pa_koko']['changed'], 'Cleared on the source.' );

		unset( $incoming['terms']['pa_koko'] );
		$this->assertArrayNotHasKey( 'terms.pa_koko', Differ::sections( $incoming, $local ), 'Terms here, taxonomy missing on the source: a sync leaves them alone.' );
		$this->assertArrayNotHasKey( 'terms.pa_koko', Differ::details( $incoming, $local ) );
	}
}

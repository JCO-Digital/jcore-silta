<?php
/**
 * Tests for Gravity Forms references, matching and form normalization.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Integrations\Gravity_Forms as Integration;
use Jcore\Silta\References\Blocks;
use Jcore\Silta\References\Walker;
use Jcore\Silta\Sync\Context;
use Jcore\Silta\Sync\Gravity_Forms;
use Jcore\Silta\Sync\Matcher;
use Jcore\Silta\Sync\State;
use PHPUnit\Framework\TestCase;

/**
 * Gravity Forms.
 */
final class GravityFormsTest extends TestCase {

	/**
	 * Remote ID => local ID, per kind. Anything else is unresolved.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $map = array(
		'form' => array(
			1 => 7,
			2 => 8,
		),
		'post' => array(
			10 => 110,
		),
	);

	/**
	 * Declares the form block the way the plugin does on boot.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_block_references'] = array( Integration::class, 'block_references' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['jcore_silta_test_filters']['jcore_silta_block_references'] );
	}

	/**
	 * Walks post content the way the Walker does: blocks, then shortcodes.
	 *
	 * @param string        $content Content.
	 * @param callable|null $visit   Visitor; the test visitor by default.
	 *
	 * @return string
	 */
	private function content( string $content, ?callable $visit = null ): string {
		$visit ??= $this->visitor();

		return Integration::walk_shortcodes( Blocks::walk( $content, $visit, static fn(): ?array => null ), $visit );
	}

	/**
	 * Visitor mapping through $map; null for unknown IDs.
	 *
	 * @return callable
	 */
	private function visitor(): callable {
		return fn( string $kind, int $id ): ?int => $this->map[ $kind ][ $id ] ?? null;
	}

	public function test_form_block_id_is_remapped_keeping_its_type(): void {
		$this->assertSame(
			'<!-- wp:gravityforms/form {"formId":"7","title":false,"ajax":true} /-->',
			$this->content( '<!-- wp:gravityforms/form {"formId":"1","title":false,"ajax":true} /-->' )
		);
		$this->assertSame(
			'<!-- wp:gravityforms/form {"formId":8} /-->',
			$this->content( '<!-- wp:gravityforms/form {"formId":2} /-->' )
		);
	}

	public function test_unchanged_and_unresolved_blocks_keep_their_bytes(): void {
		$content = "<!-- wp:gravityforms/form {\"formId\":\"99\",  \"title\":false} /-->\n<!-- wp:paragraph --><p>gravityforms</p><!-- /wp:paragraph -->";
		// Context keeps an unresolved form ID; so does this visitor.
		$keep = fn( string $kind, int $id ): int => $this->map[ $kind ][ $id ] ?? $id;
		$this->assertSame( $content, $this->content( $content, $keep ) );

		$other = '<!-- wp:gravityforms/form {"title":false} /-->';
		$this->assertSame( $other, $this->content( $other ) );
	}

	public function test_shortcodes_are_remapped_in_every_quoting_style(): void {
		$content = '[gravityform id="1" title="false"] [gravityform id=2 ajax="true"] [gravityforms id=\'1\'] [gravityform title="x" id="2"/]';
		$this->assertSame(
			'[gravityform id="7" title="false"] [gravityform id=8 ajax="true"] [gravityforms id=\'7\'] [gravityform title="x" id="8"/]',
			$this->content( $content )
		);
	}

	public function test_shortcodes_that_are_not_form_ids_are_left_alone(): void {
		$content = '[[gravityform id="1"]] [gravityform form_id="1"] [gravityformfoo id="1"] [gravityform id="55"] [gallery id="1"]';
		$this->assertSame( $content, $this->content( $content ) );
	}

	public function test_shortcode_inside_a_shortcode_block_is_remapped(): void {
		$content = "<!-- wp:shortcode -->\n[gravityform id=\"1\" title=\"false\"]\n<!-- /wp:shortcode -->";
		$this->assertSame( str_replace( 'id="1"', 'id="7"', $content ), $this->content( $content ) );
	}

	public function test_plain_meta_values_are_remapped_but_serialized_ones_are_not(): void {
		$serialized = serialize( array( 'text' => '[gravityform id="1"]' ) );
		$meta       = array(
			'intro'  => array( '<p>Fill in:</p>[gravityform id="2" title="false"]', 'no form' ),
			'packed' => array( $serialized ),
		);
		Integration::walk_meta( $meta, $this->visitor() );

		$this->assertSame( '<p>Fill in:</p>[gravityform id="8" title="false"]', $meta['intro'][0] );
		$this->assertSame( 'no form', $meta['intro'][1] );
		$this->assertSame( $serialized, $meta['packed'][0] );
	}

	public function test_confirmation_page_is_remapped_and_unresolved_ones_keep_their_id(): void {
		$record = array(
			'type'          => 'form',
			'confirmations' => array(
				'a' => array(
					'type'   => 'page',
					'pageId' => 10,
				),
				'b' => array(
					'type'   => 'page',
					'pageId' => '10',
				),
				'c' => array(
					'type'   => 'page',
					'pageId' => '404',
				),
				'd' => array(
					'type'    => 'message',
					'message' => 'Thanks',
				),
			),
		);
		Walker::walk( $record, $this->visitor(), static fn(): ?array => null );

		$this->assertSame( 110, $record['confirmations']['a']['pageId'] );
		$this->assertSame( '110', $record['confirmations']['b']['pageId'] );
		$this->assertSame( '404', $record['confirmations']['c']['pageId'] );
		$this->assertArrayNotHasKey( 'pageId', $record['confirmations']['d'] );
	}

	public function test_walker_collects_form_references_of_a_post(): void {
		$record = array(
			'type'    => 'post',
			'fields'  => array(),
			'content' => '<!-- wp:gravityforms/form {"formId":"1"} /-->[gravityform id="2"]',
			'meta'    => array(
				'contact_form'  => array( '3' ),
				'_contact_form' => array( 'field_contact_form' ),
				'body'          => array( '[gravityform id="4"]' ),
			),
		);
		$seen   = array();
		Walker::walk(
			$record,
			static function ( string $kind, int $id ) use ( &$seen ): int {
				$seen[] = $kind . ':' . $id;

				return $id;
			},
			static fn( string $key ): ?array => 'field_contact_form' === $key ? array( 'type' => 'forms' ) : null
		);
		sort( $seen );

		$this->assertSame( array( 'form:1', 'form:2', 'form:3', 'form:4' ), $seen );
	}

	public function test_acf_form_field_is_remapped(): void {
		$record = array(
			'type'    => 'post',
			'fields'  => array(),
			'content' => '',
			'meta'    => array(
				'contact_form'  => array( '1' ),
				'_contact_form' => array( 'field_contact_form' ),
				'more_forms'    => array( serialize( array( '1', '2' ) ) ),
				'_more_forms'   => array( 'field_more_forms' ),
			),
		);
		Walker::walk( $record, $this->visitor(), static fn(): ?array => array( 'type' => 'forms' ) );

		$this->assertSame( array( '7' ), $record['meta']['contact_form'] );
		$this->assertSame( array( serialize( array( '7', '8' ) ) ), $record['meta']['more_forms'] );
	}

	public function test_matching_prefers_a_clone_then_a_unique_title(): void {
		$this->assertSame(
			array(
				'id'     => 3,
				'method' => 'clone',
			),
			Matcher::form_candidate( 3, 'Contact', 'Contact', array( 3, 9 ) )
		);
		$this->assertSame(
			array(
				'id'     => 9,
				'method' => 'title',
			),
			Matcher::form_candidate( 3, 'Contact', 'Newsletter', array( 9 ) )
		);
		$this->assertNull( Matcher::form_candidate( 3, 'Contact', '', array( 9, 12 ) ) );
		$this->assertNull( Matcher::form_candidate( 3, 'Contact', '', array() ) );
		$this->assertNull( Matcher::form_candidate( 3, '', '', array( 9 ) ) );
	}

	public function test_form_state_drops_site_specific_values_and_sorts_keys(): void {
		$form = array(
			'id'             => 5,
			'title'          => 'Contact',
			'is_active'      => '1',
			'is_trash'       => '0',
			'date_created'   => '2026-10-01 10:00:00',
			'labelPlacement' => 'top_label',
			'button'         => array(
				'type' => 'text',
				'text' => 'Send',
			),
			'fields'         => array(
				array(
					'type'   => 'text',
					'id'     => 1,
					'formId' => 5,
					'label'  => 'Name',
				),
			),
			'confirmations'  => array(
				array(
					'id'      => '5f1',
					'type'    => 'message',
					'message' => 'Thanks',
				),
			),
			'notifications'  => array(),
		);

		$state = Gravity_Forms::from_form( 5, $form );

		$this->assertSame( 'form', $state['type'] );
		$this->assertSame( 'Contact', $state['name'] );
		$this->assertTrue( $state['active'] );
		$this->assertSame(
			array(
				'button'         => array(
					'text' => 'Send',
					'type' => 'text',
				),
				'labelPlacement' => 'top_label',
			),
			$state['settings']
		);
		$this->assertSame(
			array(
				array(
					'id'    => 1,
					'label' => 'Name',
					'type'  => 'text',
				),
			),
			$state['fields']
		);
		$this->assertSame( array( '5f1' ), array_keys( $state['confirmations'] ) );

		// The same form with another ID, another date and keys in another order hashes the same.
		$copy                        = $form;
		$copy['id']                  = 12;
		$copy['date_created']        = '2026-10-19 08:00:00';
		$copy['fields'][0]           = array_reverse( $copy['fields'][0], true );
		$copy['fields'][0]['formId'] = 12;
		$copy['button']              = array_reverse( $copy['button'], true );
		$this->assertSame( State::hash( $state ), State::hash( Gravity_Forms::from_form( 12, $copy ) ) );

		$copy['button']['text'] = 'Submit';
		$this->assertNotSame( State::hash( $state ), State::hash( Gravity_Forms::from_form( 12, $copy ) ) );
	}

	public function test_form_id_is_dropped_from_sub_fields_and_legacy_copies_from_settings(): void {
		$state = Gravity_Forms::from_form(
			5,
			array(
				'title'         => 'Repeater',
				'nextFieldId'   => 4,
				'notification'  => array( 'to' => 'legacy@example.com' ),
				'autoResponder' => array( 'to' => 'legacy@example.com' ),
				'fields'        => array(
					array(
						'id'     => 1,
						'type'   => 'repeater',
						'formId' => 5,
						'fields' => array(
							array(
								'id'     => 2,
								'type'   => 'text',
								'formId' => 5,
							),
						),
					),
				),
			)
		);

		$this->assertSame( array(), $state['settings'] );
		$this->assertArrayNotHasKey( 'formId', $state['fields'][0] );
		$this->assertArrayNotHasKey( 'formId', $state['fields'][0]['fields'][0] );
	}

	public function test_compose_sets_form_id_on_fields(): void {
		$state = Gravity_Forms::from_form(
			5,
			array(
				'title'     => 'Contact',
				'is_active' => true,
				'fields'    => array(
					array(
						'id'   => 1,
						'type' => 'text',
					),
				),
			)
		);
		$form  = Gravity_Forms::compose( $state, 'Contact (2)', 40 );

		$this->assertSame( 'Contact (2)', $form['title'] );
		$this->assertSame( 40, $form['fields'][0]['formId'] );
		$this->assertTrue( $form['is_active'] );
		$this->assertArrayNotHasKey( 'formId', Gravity_Forms::compose( $state, 'Contact' )['fields'][0] );
	}

	public function test_search_replace_reaches_every_text_of_a_form(): void {
		$record = array(
			'type'          => 'form',
			'name'          => 'Contact',
			'settings'      => array( 'description' => 'See https://staging.test/terms' ),
			'fields'        => array(
				array(
					'type'    => 'html',
					'content' => '<a href="https://staging.test/privacy">Privacy</a>',
					'choices' => array( array( 'value' => 'https://staging.test/a' ) ),
					'id'      => 3,
				),
			),
			'confirmations' => array(
				'x' => array(
					'type' => 'redirect',
					'url'  => 'https://staging.test/thanks',
				),
			),
			'notifications' => array(
				'y' => array( 'message' => 'Sent from https://staging.test' ),
			),
		);

		$replaced = Context::replace_form( $record, static fn( string $value ): string => str_replace( 'https://staging.test', 'https://www.example.com', $value ) );

		$this->assertSame( 'See https://www.example.com/terms', $replaced['settings']['description'] );
		$this->assertSame( '<a href="https://www.example.com/privacy">Privacy</a>', $replaced['fields'][0]['content'] );
		$this->assertSame( 'https://www.example.com/a', $replaced['fields'][0]['choices'][0]['value'] );
		$this->assertSame( 3, $replaced['fields'][0]['id'] );
		$this->assertSame( 'https://www.example.com/thanks', $replaced['confirmations']['x']['url'] );
		$this->assertSame( 'Sent from https://www.example.com', $replaced['notifications']['y']['message'] );
	}
}

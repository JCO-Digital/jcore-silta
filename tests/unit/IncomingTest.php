<?php
/**
 * Incoming bundle, meta and content policy validation.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Sync\Content;
use Jcore\Silta\Sync\Context;
use Jcore\Silta\Sync\Importer;
use Jcore\Silta\Sync\Menus;
use Jcore\Silta\Sync\Meta_Filter;
use Jcore\Silta\Sync\Meta_Writer;
use Jcore\Silta\Sync\Planner;
use Jcore\Silta\Sync\Preflight;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Validates untrusted input before it reaches WordPress.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class IncomingTest extends TestCase {

	/**
	 * Loads guarded WordPress helpers for this test process.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		require_once __DIR__ . '/stubs/incoming.php';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['jcore_silta_test_options'], $GLOBALS['jcore_silta_test_unfiltered'], $GLOBALS['jcore_silta_test_kses'] );
		unset( $GLOBALS['jcore_silta_test_filters']['jcore_silta_syncable_post_type'], $GLOBALS['jcore_silta_test_filters']['jcore_silta_syncable_taxonomy'] );
		Meta_Filter::reset();
	}

	public function test_raw_meta_rejects_objects_enums_and_references_at_every_level(): void {
		$unsafe = array( 'a:1:{i:+0;O:8:"stdClass":0:{}}', 'a:2:{i:0;d:+1.0;i:1;O:8:"stdClass":0:{}}', 'a:1:{S:1:"x";O:8:"stdClass":0:{}}', 'a:1:{i:0;S:19:"O:8:\22stdClass\22:0:{}";}', 'O:8:"stdClass":0:{}', 'C:3:"Foo":3:{abc}', 'E:7:"Foo:Bar";', 'a:1:{i:0;R:1;}', 'a:1:{i:0;r:1;}' );
		foreach ( $unsafe as $value ) {
			$this->assertFalse( Meta_Writer::valid_value( $value ), $value );
			$this->assertFalse( Meta_Writer::valid_value( serialize( array( $value ) ) ), $value );
			$this->assertFalse( Meta_Writer::valid_value( serialize( ' ' . $value . ' ' ) ), $value );
		}
		$this->assertFalse( Meta_Writer::valid_value( 'O:8:"stdClass":0:{};' ) );
		$this->assertFalse( Meta_Writer::valid_value( 'O:9999999999999999999999:"x":0:{}' ) );
		$this->assertFalse( Meta_Writer::valid_value( 'C:3:"Foo":999999999999999999999:{x}' ) );
		$this->assertFalse( Meta_Writer::valid_value( 'E:9999999999999999999999:"Foo:Bar";' ) );
		$this->assertFalse( Meta_Writer::valid_value( 'a:1:{i:0;O:8:"stdClass":0:{}}' ) );
		// Forms PHP reads but the tokenizer stops at must not pass as plain text.
		$this->assertFalse( Meta_Writer::valid_value( 'a:2:{i:0;R:01;i:1;O:8:"stdClass":0:{}}' ) );
		$this->assertFalse( Meta_Writer::valid_value( 'a:1:{i:0;s:1:"x";}O:8:"stdClass":0:{}' ) );
		$this->assertTrue( Meta_Writer::valid_value( 'i:12 apples;' ) );
		$this->assertTrue( Meta_Writer::valid_value( 'Text mentions O:8:"stdClass":0:{}' ) );
		$this->assertTrue(
			Meta_Writer::valid_value(
				serialize(
					array(
						'token' => 'O: and C: are prefixes',
						'ids'   => array( 3, '4' ),
					)
				)
			)
		);
		$this->assertTrue( Meta_Writer::valid_value( 'b:0;' ) );
	}

	public function test_rejected_keys_are_not_deleted_or_partially_written(): void {
		$GLOBALS['wpdb'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fake database for raw-write assertions.
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
			 * Captured deletes.
			 *
			 * @var array
			 */
			public array $deleted = array();
			/**
			 * Captured inserts.
			 *
			 * @var array
			 */
			public array $inserted = array();

			/**
			 * Captures deletes.
			 *
			 * @param string $table Table.
			 * @param array  $where Conditions.
			 * @return void
			 */
			public function delete( string $table, array $where ): void {
				$this->deleted[] = $where['meta_key'];
			}

			/**
			 * Captures inserts.
			 *
			 * @param string $table Table.
			 * @param array  $data  Row.
			 * @return void
			 */
			public function insert( string $table, array $data ): void {
				$this->inserted[ $data['meta_key'] ][] = $data['meta_value'];
			}
		};
		$values          = array(
			'good'                    => array( serialize( array( 'hello' ) ) ),
			'_hero'                   => array( 'field_image' ),
			'bad'                     => array( 'safe first row', 'O:8:"stdClass":0:{}' ),
			'_wp_attached_file'       => array( '../../evil.php' ),
			'_wp_attachment_metadata' => array( 'unsafe' ),
			'_wp_page_template'       => array( '../../evil.php' ),
			'_edit_lock'              => array( '123' ),
			'_WP_ATTACHED_FILE'       => array( '../../evil.php' ),
			'_menu_item_url'          => array( 'javascript:alert(1)' ),
			'_jcore_silta_local'      => array( 'spoof' ),
		);
		foreach ( array( 'post', 'term' ) as $type ) {
			$GLOBALS['wpdb']->deleted  = array();
			$GLOBALS['wpdb']->inserted = array();
			$warnings                  = Meta_Writer::replace( $type, 3, array_merge( array_keys( $values ), array( 'removed' ) ), $values );
			$this->assertCount( 1, $warnings );
			$this->assertStringContainsString( 'bad', $warnings[0] );
			$this->assertSame( array( 'good', '_hero', 'removed' ), $GLOBALS['wpdb']->deleted );
			$this->assertSame(
				array(
					'good'  => $values['good'],
					'_hero' => $values['_hero'],
				),
				$GLOBALS['wpdb']->inserted
			);
		}
		$this->assertCount( 1, Meta_Writer::replace( 'post', 3, array( '_wp_page_template' ), array( '_wp_page_template' => array( 'O:8:"stdClass":0:{}' ) ), true ) );
	}

	public function test_type_status_and_taxonomy_policy(): void {
		foreach ( array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'custom_css', 'user_request', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', 'missing' ) as $type ) {
			$this->assertFalse( Content::syncable_type( $type ), $type );
			$this->assertSame( array(), Content::ids( $type ) );
			$this->assertSame( array(), Content::query( array( 'post_type' => $type ) )['items'] );
		}
		foreach ( array( 'post', 'page', 'wp_block', 'attachment' ) as $type ) {
			$this->assertTrue( Content::syncable_type( $type ) );
		}
		$this->assertTrue( Content::syncable_type( 'nav_menu_item', true ) );
		$this->assertFalse( Content::syncable_type( 'nav_menu_item' ) );
		$this->assertTrue( Content::syncable_status( 'attachment', 'inherit' ) );
		foreach ( array( 'trash', 'auto-draft', 'inherit' ) as $status ) {
			$this->assertFalse( Content::syncable_status( 'post', $status ) );
		}
		$this->assertTrue( Content::syncable_taxonomy( 'category' ) );
		foreach ( array( 'nav_menu', 'language', 'post_translations', 'wp_theme', 'missing' ) as $taxonomy ) {
			$this->assertFalse( Content::syncable_taxonomy( $taxonomy ) );
		}
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_syncable_post_type'] = static fn(): bool => true;
		$this->assertTrue( Content::syncable_type( 'wp_template' ) );
		$this->assertFalse( Content::syncable_type( 'missing' ) );
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_syncable_taxonomy'] = static fn(): bool => false;
		$this->assertFalse( Content::syncable_taxonomy( 'category' ) );
	}

	public function test_preflight_blocks_status_and_unrelated_taxonomies(): void {
		$record                          = $this->record();
		$record['fields']['post_status'] = 'trash';
		$this->assertArrayHasKey( '', Preflight::check( $record ) );
		$record['fields']['post_status'] = 'draft';
		$record['post_type']             = 'page';
		$record['terms']                 = array( 'category' => array( 1 ) );
		$this->assertArrayHasKey( 'terms.category', Preflight::check( $record ) );
		$this->assertArrayHasKey(
			'',
			Preflight::check(
				array(
					'type'     => 'term',
					'taxonomy' => 'language',
				)
			)
		);
	}

	public function test_bundle_structure_and_meta_rows(): void {
		$record = $this->record();
		$this->assertNull( Context::record_error( $record ) );
		foreach ( array(
			'key'       => array(),
			'id'        => '1',
			'type'      => 'other',
			'post_type' => array(),
			'content'   => array(),
			'meta'      => array( 'bad' => array( array() ) ),
			'fields'    => array( 'post_status' => array() ),
			'refs'      => array( array() ),
			'language'  => array(
				'lang'         => 'fi',
				'translations' => array( 'en' => array() ),
			),
		) as $key => $value ) {
			$bad         = $record;
			$bad[ $key ] = $value;
			$this->assertNotNull( Context::record_error( $bad ), $key );
		}
		$record['meta'] = array(
			'_hero' => 'field_image',
			'hero'  => array( '3' ),
		);
		$ctx            = new Context( array( 'objects' => array( $record, 'malformed' ) ), array(), 'peer' );
		$this->assertSame( array( 'field_image' ), $ctx->objects['post:1']['meta']['_hero'] );
		$this->assertArrayHasKey( 'invalid:record:1', $ctx->errors );
		$ctx = new Context( array( 'objects' => array( 'post:1' => $record ) ), array(), 'peer' );
		$this->assertSame( array(), $ctx->objects );
		$this->assertSame( 'error', ( new Planner( $ctx ) )->plan()['invalid:bundle']['action'] );
		$this->assertSame( 'failed', ( new Importer( $ctx, 'job' ) )->run( array() )['invalid:bundle']['status'] );
	}

	public function test_form_records_and_references(): void {
		$form = array(
			'key'           => 'form:3',
			'type'          => 'form',
			'id'            => 3,
			'name'          => 'Contact',
			'title'         => 'Contact',
			'hash'          => 'abc',
			'active'        => true,
			'settings'      => array( 'labelPlacement' => 'top_label' ),
			'fields'        => array(
				array(
					'id'   => 1,
					'type' => 'text',
				),
			),
			'confirmations' => array(
				'c1' => array(
					'type'   => 'page',
					'pageId' => '12',
				),
			),
			'notifications' => array(),
			'refs'          => array( 'post:12' ),
		);
		$this->assertNull( Context::record_error( $form ) );
		foreach ( array(
			'active'        => '1',
			'name'          => 3,
			'settings'      => 'x',
			'fields'        => array( 'a' => array() ),
			'confirmations' => array( 'c1' => 'page' ),
			'notifications' => null,
			'key'           => 'form:4',
		) as $key => $value ) {
			$bad         = $form;
			$bad[ $key ] = $value;
			$this->assertNotNull( Context::record_error( $bad ), $key );
		}
		$bad                                  = $form;
		$bad['confirmations']['c1']['pageId'] = array( 12 );
		$this->assertNotNull( Context::record_error( $bad ) );

		$this->assertNull(
			Context::record_error(
				array(
					'key'   => 'form:3',
					'type'  => 'form',
					'id'    => 3,
					'name'  => 'Contact',
					'title' => 'Contact',
				),
				true
			)
		);

		$post         = $this->record();
		$post['refs'] = array( 'form:3', 'post:2' );
		$this->assertNull( Context::record_error( $post ) );

		// Gravity Forms is not loaded in the tests, as on a site without it.
		$this->assertArrayHasKey( '', Preflight::check( $form ) );
	}

	public function test_kses_follows_the_acting_users_capability(): void {
		$GLOBALS['jcore_silta_test_kses']       = false;
		$GLOBALS['jcore_silta_test_unfiltered'] = false;
		$this->assertFalse( Importer::kses_off() );
		$this->assertTrue( $GLOBALS['jcore_silta_test_kses'] );
		$GLOBALS['jcore_silta_test_unfiltered'] = true;
		$this->assertTrue( Importer::kses_off() );
		$this->assertFalse( $GLOBALS['jcore_silta_test_kses'] );
	}

	public function test_menu_classes_never_instantiate_or_accept_nested_values(): void {
		$method = new \ReflectionMethod( Menus::class, 'classes' );
		$this->assertSame( array(), $method->invoke( null, 'O:8:"stdClass":0:{}' ) );
		$this->assertSame( array( 'good', 'badclass' ), $method->invoke( null, serialize( array( 'good', 'bad"class', array( 'nested' ) ) ) ) );
	}

	/**
	 * A record in the exporter wire format.
	 *
	 * @return array<string, mixed>
	 */
	private function record(): array {
		return array(
			'key'       => 'post:1',
			'type'      => 'post',
			'id'        => 1,
			'post_type' => 'post',
			'hash'      => 'hash',
			'content'   => 'content',
			'fields'    => array(
				'post_title'     => 'Title',
				'post_name'      => 'title',
				'post_excerpt'   => '',
				'post_status'    => 'draft',
				'post_date'      => '2026-10-14 12:00:00',
				'post_date_gmt'  => '2026-10-14 09:00:00',
				'post_author'    => 1,
				'post_parent'    => 0,
				'menu_order'     => 0,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_password'  => '',
			),
		);
	}

	/**
	 * Sanitizes the dedicated menu fields before WordPress writes its meta.
	 *
	 * @return void
	 */
	public function test_menu_arguments_are_sanitized_and_use_local_ids(): void {
		$item = array(
			'object_id'   => 101,
			'object'      => 'PoSt<script>',
			'position'    => 1,
			'item_type'   => 'custom',
			'title'       => 'Link',
			'url'         => 'javascript:alert(1)',
			'description' => '',
			'attr_title'  => '',
			'target'      => '<b>_blank</b>',
			'classes'     => 'good bad"class',
			'xfn'         => '<b>friend</b>',
		);
		$args = ( new \ReflectionMethod( Menus::class, 'item_args' ) )->invoke( null, $item, 205 );
		$this->assertSame( 101, $args['menu-item-object-id'] );
		$this->assertSame( 205, $args['menu-item-parent-id'] );
		$this->assertSame( '', $args['menu-item-url'] );
		// A string: wp_update_nav_menu_item() explode()s it.
		$this->assertSame( 'good badclass', $args['menu-item-classes'] );
		$this->assertSame( '_blank', $args['menu-item-target'] );
		$this->assertSame( 'friend', $args['menu-item-xfn'] );
		$this->assertSame( 'postscript', $args['menu-item-object'] );
	}

	/**
	 * Valid and malformed copies cannot hide a bundle error through duplicate keys.
	 *
	 * @return void
	 */
	public function test_duplicate_records_do_not_hide_validation_errors(): void {
		$valid     = $this->record();
		$bad       = $valid;
		$bad['id'] = '1';
		$ctx       = new Context( array( 'objects' => array( $valid, $bad ) ), array(), 'peer' );
		$this->assertArrayHasKey( 'post:1', $ctx->objects );
		$this->assertArrayHasKey( 'invalid:record:1', $ctx->errors );
		$ctx = new Context( array( 'objects' => array( $bad, $valid ) ), array(), 'peer' );
		$this->assertSame( array(), $ctx->objects );
		$this->assertArrayHasKey( 'post:1', $ctx->errors );
	}
}

<?php
/**
 * Tests for filtering HTML from the other site in meta and Gravity Forms forms.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Sync\Gravity_Forms;
use Jcore\Silta\Sync\Meta_Filter;
use Jcore\Silta\Sync\Meta_Writer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Meta_Writer::kses(), Meta_Filter and Gravity_Forms::filter_html().
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class KsesTest extends TestCase {

	/**
	 * Loads guarded WordPress helpers for this test process; the acting user
	 * has no unfiltered_html.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		require_once __DIR__ . '/stubs/incoming.php';
		require_once __DIR__ . '/stubs/kses.php';
		$GLOBALS['jcore_silta_test_unfiltered'] = false;
		$GLOBALS['jcore_silta_test_kses_calls'] = array();
	}

	/**
	 * Installs a fake database that records meta writes.
	 *
	 * @return object
	 */
	private function database(): object {
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
			 * Captured inserts.
			 *
			 * @var array
			 */
			public array $inserted = array();

			/**
			 * Ignores deletes.
			 *
			 * @return void
			 */
			public function delete(): void {}

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

		return $GLOBALS['wpdb'];
	}

	/**
	 * Calls the private Gravity_Forms::filter_html().
	 *
	 * @param array<string, mixed> $state Form state.
	 *
	 * @return array<string, mixed>
	 */
	private function filter_form( array $state ): array {
		return ( new \ReflectionMethod( Gravity_Forms::class, 'filter_html' ) )->invoke( null, $state );
	}

	public function test_oembed_cache_is_never_synced(): void {
		$this->assertFalse( Meta_Filter::synced( '_oembed_0123456789abcdef0123456789abcdef' ) );
		$this->assertFalse( Meta_Filter::synced( '_oembed_time_0123456789abcdef0123456789abcdef' ) );
		$this->assertTrue( Meta_Filter::synced( '_oembedded' ) );
	}

	public function test_html_is_filtered_in_plain_serialized_and_json_values(): void {
		$this->assertSame( '<p>Hi</p>', Meta_Writer::kses( '<p onclick="steal()">Hi</p><script>alert(1)</script>' ) );

		$packed = Meta_Writer::kses(
			serialize(
				array(
					'title'  => '<script>alert(1)</script>Hi',
					'count'  => 3,
					'nested' => serialize( array( 'body' => '<b onmouseover="x()">Bold</b>' ) ),
				)
			)
		);
		$value  = unserialize( $packed );
		$this->assertSame( 'Hi', $value['title'] );
		$this->assertSame( 3, $value['count'] );
		$this->assertSame( array( 'body' => '<b>Bold</b>' ), unserialize( $value['nested'] ) );

		// Escaped "<" is still HTML once a theme decodes the JSON; "{}" stays an object.
		$json = Meta_Writer::kses( '{"html":"\u003cscript\u003ealert(1)\u003c\/script\u003eok","n":2,"o":{},"list":["<em>a</em><style>x</style>"]}' );
		$this->assertSame( '{"html":"ok","n":2,"o":{},"list":["<em>a<\/em>"]}', $json );
	}

	public function test_values_without_html_keep_their_bytes(): void {
		$values = array(
			'a & b > c',
			'C:\\0dir',
			' ' . serialize( array( 'x' => 'Fish & chips' ) ) . ' ',
			serialize( array( 'S' => 'tokens' ) ),
			'a:1:{i:0;S:3:"\61bc";}',
			'{"a":"x & y",  "b":[1, 2.50]}',
			'[1,2',
			'',
		);
		foreach ( $values as $value ) {
			$this->assertSame( $value, Meta_Writer::kses( $value ) );
		}
		$this->assertSame( array(), $GLOBALS['jcore_silta_test_kses_calls'] );
	}

	public function test_meta_writes_are_filtered_for_users_without_unfiltered_html(): void {
		$db     = $this->database();
		$values = array(
			'intro'         => array( '<p>Hi</p><script>alert(1)</script>', 'Terms & conditions' ),
			'_oembed_abc'   => array( '<script>alert(1)</script>' ),
			'_wp_page_meta' => array( serialize( array( '<i>key</i>' => '<a href="/x" onclick="y()">x</a>' ) ) ),
		);

		Meta_Writer::replace( 'post', 3, array_keys( $values ), $values );

		$this->assertSame(
			array(
				'intro'         => array( '<p>Hi</p>', 'Terms & conditions' ),
				'_wp_page_meta' => array( serialize( array( 'key' => '<a href="/x">x</a>' ) ) ),
			),
			$db->inserted
		);
	}

	public function test_meta_is_written_unfiltered_for_unfiltered_html_and_restores(): void {
		$db     = $this->database();
		$values = array( 'intro' => array( '<p>Hi</p><script>alert(1)</script>' ) );

		// A snapshot is this site's own data and goes back exactly as it was.
		Meta_Writer::replace( 'post', 3, array( 'intro' ), $values, false, true );
		$this->assertSame( $values, $db->inserted );

		$db->inserted                           = array();
		$GLOBALS['jcore_silta_test_unfiltered'] = true;
		Meta_Writer::replace( 'term', 3, array( 'intro' ), $values );
		$this->assertSame( $values, $db->inserted );
		$this->assertSame( array(), $GLOBALS['jcore_silta_test_kses_calls'] );
	}

	/**
	 * A form with HTML in every place Gravity Forms prints it.
	 *
	 * @return array<string, mixed>
	 */
	private function form(): array {
		$xss = '<script>alert(1)</script>';

		return array(
			'type'          => 'form',
			'id'            => 4,
			'name'          => 'Contact',
			'active'        => true,
			'settings'      => array(
				'description'         => 'Intro' . $xss,
				'requireLogin'        => true,
				'requireLoginMessage' => 'Log in' . $xss,
				'scheduleMessage'     => 'Closed' . $xss,
				'button'              => array(
					'type' => 'text',
					'text' => 'Send' . $xss,
				),
				'pagination'          => array( 'pages' => array( 'One' . $xss, 'Two' ) ),
				'version'             => '2.9.1',
			),
			'fields'        => array(
				array(
					'id'               => 1,
					'type'             => 'checkbox',
					'label'            => 'Pick' . $xss,
					'adminLabel'       => 'Admin' . $xss,
					'defaultValue'     => 'Default' . $xss,
					'choices'          => array(
						array(
							'text'  => 'A & B' . $xss,
							'value' => 'a<b',
						),
					),
					'inputs'           => array(
						array(
							'id'          => '1.1',
							'label'       => 'In' . $xss,
							'customLabel' => 'Custom' . $xss,
						),
					),
					'conditionalLogic' => array(
						'rules' => array(
							array(
								'fieldId'  => '2',
								'operator' => '<',
								'value'    => '5',
							),
						),
					),
				),
				array(
					'id'     => 2,
					'type'   => 'repeater',
					'label'  => 'Rows',
					'fields' => array(
						array(
							'id'          => 3,
							'type'        => 'html',
							'content'     => '<p onclick="x()">Note</p>',
							'description' => 'Sub' . $xss,
						),
					),
				),
			),
			'confirmations' => array(
				'c1' => array(
					'id'      => 'c1',
					'type'    => 'message',
					'message' => '<p>Thanks</p>' . $xss,
				),
			),
			'notifications' => array(
				'n1' => array(
					'id'      => 'n1',
					'subject' => 'New <entry>',
					'message' => '<p>{all_fields}</p>' . $xss,
				),
			),
		);
	}

	public function test_form_html_is_filtered_everywhere_without_gravity_forms_sanitizing(): void {
		$state = $this->filter_form( $this->form() );

		$this->assertSame( 'Intro', $state['settings']['description'] );
		$this->assertSame( 'Log in', $state['settings']['requireLoginMessage'] );
		$this->assertSame( 'Closed', $state['settings']['scheduleMessage'] );
		$this->assertSame( 'Send', $state['settings']['button']['text'] );
		$this->assertSame( array( 'One', 'Two' ), $state['settings']['pagination']['pages'] );

		$field = $state['fields'][0];
		$this->assertSame( 'Pick', $field['label'] );
		$this->assertSame( 'Admin', $field['adminLabel'] );
		$this->assertSame( 'Default', $field['defaultValue'] );
		$this->assertSame( 'A & B', $field['choices'][0]['text'] );
		$this->assertSame( 'In', $field['inputs'][0]['label'] );
		$this->assertSame( 'Custom', $field['inputs'][0]['customLabel'] );
		$this->assertSame( '<p>Note</p>', $state['fields'][1]['fields'][0]['content'] );
		$this->assertSame( 'Sub', $state['fields'][1]['fields'][0]['description'] );
		$this->assertSame( '<p>Thanks</p>', $state['confirmations']['c1']['message'] );
		$this->assertSame( '<p>{all_fields}</p>', $state['notifications']['n1']['message'] );

		// Values that are not HTML are left alone, "<" or not.
		$this->assertSame( 'a<b', $field['choices'][0]['value'] );
		$this->assertSame( '<', $field['conditionalLogic']['rules'][0]['operator'] );
		$this->assertSame( 'New <entry>', $state['notifications']['n1']['subject'] );
		$this->assertSame( 'Rows', $state['fields'][1]['label'] );
	}

	public function test_form_goes_through_gravity_forms_sanitizing_keeping_the_synced_version(): void {
		require_once __DIR__ . '/stubs/gravity-forms-model.php';
		$form         = $this->form();
		$form['name'] = 'Contact <b>form</b>';

		$state = $this->filter_form( $form );

		$this->assertCount( 1, \GFFormsModel::$sanitized );
		$this->assertSame( 0, \GFFormsModel::$sanitized[0]['id'] );
		$this->assertSame( 'Contact form', $state['name'] );
		$this->assertSame( '2.9.1', $state['settings']['version'] );
		$this->assertFalse( $state['fields'][1]['isRequired'] );
		$this->assertArrayNotHasKey( 'formId', $state['fields'][0] );
		$this->assertSame( 'Log in', $state['settings']['requireLoginMessage'] );
		$this->assertSame( 'Sub', $state['fields'][1]['fields'][0]['description'] );
		$this->assertSame( '<p>Thanks</p>', $state['confirmations']['c1']['message'] );
		$this->assertTrue( $state['active'] );
		$this->assertSame( 'form', $state['type'] );
	}

	public function test_form_is_left_alone_for_unfiltered_html(): void {
		require_once __DIR__ . '/stubs/gravity-forms-model.php';
		$GLOBALS['jcore_silta_test_unfiltered'] = true;

		$this->assertSame( $this->form(), $this->filter_form( $this->form() ) );
		$this->assertSame( array(), \GFFormsModel::$sanitized );
	}
}

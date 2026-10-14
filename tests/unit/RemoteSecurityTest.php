<?php
/**
 * Tests the peer boundary and initiator authorization policies.
 *
 * @package Jcore\Silta\Tests
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Crypto\Identity;
use Jcore\Silta\Crypto\Signature;
use Jcore\Silta\Endpoint\Local;
use Jcore\Silta\Jobs\Jobs;
use Jcore\Silta\Jobs\Runner;
use Jcore\Silta\Remote\Client;
use Jcore\Silta\Remote\Connections;
use Jcore\Silta\Remote\Trusted_Keys;
use Jcore\Silta\Remote\Verifier;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionMethod;
use ReflectionProperty;
use WP_Error;
use WP_REST_Request;

/** Security checks which do not require a WordPress installation. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class RemoteSecurityTest extends TestCase {

	/** Job UUID. */
	private const JOB = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

	/** Peer UUID. */
	private const PEER = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

	/**
	 * Provides setUp for the test fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		require_once __DIR__ . '/stubs/remote.php';
		require_once __DIR__ . '/stubs/RemoteDatabaseStub.php';
		$GLOBALS['jcore_silta_test_filters']       = array();
		$GLOBALS['jcore_silta_remote_caps']        = array();
		$GLOBALS['jcore_silta_remote_options']     = array();
		$GLOBALS['jcore_silta_remote_transients']  = array();
		$GLOBALS['jcore_silta_remote_taxonomies']  = array();
		$GLOBALS['jcore_silta_remote_types']       = array();
		$GLOBALS['jcore_silta_remote_environment'] = 'production';
		$GLOBALS['jcore_silta_remote_user']        = 7;
		$GLOBALS['jcore_silta_remote_on_touch']    = null;
		$GLOBALS['jcore_silta_remote_response']    = array(
			'code' => 200,
			'body' => '{}',
		);
		$GLOBALS['wpdb']                           = new RemoteDatabaseStub(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database stand-in.
	}

	/**
	 * Provides tearDown for the test fixture.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['jcore_silta_test_filters']    = array();
		$GLOBALS['jcore_silta_remote_on_touch'] = null;
		( new ReflectionProperty( Identity::class, 'seed' ) )->setValue( null, null );
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * Checks peer cannot import or record results against foreign or initiator job.
	 *
	 * @return void
	 */
	public function test_peer_cannot_import_or_record_results_against_foreign_or_initiator_job(): void {
		foreach ( array( array( 'target', 'other' ), array( 'initiator', self::PEER ) ) as [$role, $peer] ) {
			$GLOBALS['wpdb']->row = $this->job( $role, $peer );
			$this->assertSame( 'jcore_silta_not_yours', Jobs::ensure_target( self::JOB, self::PEER, array() )->get_error_code() );
			$this->assertSame( 'jcore_silta_not_yours', Jobs::record_results( self::JOB, array(), self::PEER )->get_error_code() );
			$this->assertSame( 'jcore_silta_not_yours', ( new Local( self::PEER ) )->import( array( 'options' => array( 'job' => self::JOB ) ) )->get_error_code() );
			$this->assertSame( 'jcore_silta_not_yours', ( new Local( self::PEER ) )->undo( array( 'job' => self::JOB ) )->get_error_code() );
		}
		$this->assertSame( 0, $GLOBALS['wpdb']->writes );
	}

	/**
	 * Checks peer can reuse its own target job.
	 *
	 * @return void
	 */
	public function test_peer_can_reuse_its_own_target_job(): void {
		$GLOBALS['wpdb']->row = $this->job();
		$this->assertSame( self::JOB, Jobs::ensure_target( self::JOB, self::PEER, array() )['uuid'] );
		$this->assertTrue( Jobs::target_permission( $this->job(), self::PEER ) );
	}

	/**
	 * Checks the explicit local undo path accepts its own initiator job.
	 *
	 * @return void
	 */
	public function test_local_initiator_undo_and_owned_target_results_keep_working(): void {
		$GLOBALS['wpdb']->row = $this->job( 'initiator' );
		$summary              = ( new Local( self::PEER, true ) )->undo( array( 'job' => self::JOB ) );
		$this->assertSame( 0, $summary['restored'] );
		$this->assertSame( 'undone', $GLOBALS['wpdb']->changes['status'] );
		$GLOBALS['wpdb']->row = $this->job();
		$this->assertTrue( Jobs::record_results( self::JOB, array( 'post:1' => array( 'action' => 'create' ) ), self::PEER ) );
		$this->assertSame( array( 'create' => 1 ), json_decode( $GLOBALS['wpdb']->changes['summary'], true ) );
	}

	/**
	 * Checks sync user can only access own initiator jobs.
	 *
	 * @return void
	 */
	public function test_sync_user_can_only_access_own_initiator_jobs(): void {
		$this->assertTrue( Jobs::user_permission( $this->job( 'initiator' ) ) );
		$job            = $this->job( 'initiator' );
		$job['user_id'] = 8;
		$this->assertFalse( Jobs::user_permission( $job ) );
		$this->assertFalse( Jobs::user_permission( $this->job() ) );
		$GLOBALS['wpdb']->row = $job;
		$this->assertInstanceOf( WP_Error::class, Runner::load( 1 ) );
		$GLOBALS['jcore_silta_remote_caps']['manage_options'] = true;
		$this->assertTrue( Jobs::user_permission( $job ) );
		$this->assertTrue( Jobs::user_permission( $this->job() ) );
	}

	/**
	 * Checks saving shared rules requires admin before network or database work.
	 *
	 * @return void
	 */
	public function test_saving_shared_rules_requires_admin_before_network_or_database_work(): void {
		$error = Runner::create( 'connection', 'push', array(), array( 'save_rules' => true ) );
		$this->assertSame( 'jcore_silta_forbidden', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	/**
	 * Checks push checks each post and menu.
	 *
	 * @return void
	 */
	public function test_push_checks_each_post_and_menu(): void {
		$GLOBALS['jcore_silta_remote_caps']['read_post'] = array( 12 );
		$this->assertTrue( Runner::can_export( 'post', 12 ) );
		$this->assertFalse( Runner::can_export( 'post', 13 ) );
		$this->assertFalse( Runner::can_export( 'menu', 1 ) );
		$GLOBALS['jcore_silta_remote_caps']['edit_theme_options'] = true;
		$this->assertTrue( Runner::can_export( 'menu', 1 ) );
	}

	/**
	 * Checks pull requires edit existing create new and taxonomy caps.
	 *
	 * @return void
	 */
	public function test_pull_requires_edit_existing_create_new_and_taxonomy_caps(): void {
		$GLOBALS['jcore_silta_remote_types']['book'] = (object) array( 'cap' => (object) array( 'create_posts' => 'create_books' ) );
		$record                                      = array(
			'type'      => 'post',
			'post_type' => 'book',
			'terms'     => array(),
		);
		$this->assertFalse( Runner::can_import_record( $record, null ) );
		$GLOBALS['jcore_silta_remote_caps']['create_books'] = true;
		$this->assertTrue( Runner::can_import_record( $record, null ) );
		$this->assertFalse( Runner::can_import_record( $record, 15 ) );
		$GLOBALS['jcore_silta_remote_caps']['edit_post'] = array( 15 );
		$this->assertTrue( Runner::can_import_record( $record, 15 ) );
		$record['terms']                                   = array( 'genre' => array( 4 ) );
		$GLOBALS['jcore_silta_remote_taxonomies']['genre'] = (object) array(
			'cap' => (object) array(
				'assign_terms' => 'assign_genres',
				'edit_terms'   => 'edit_genres',
			),
		);
		$this->assertFalse( Runner::can_import_record( $record, 15 ) );
		$GLOBALS['jcore_silta_remote_caps']['assign_genres'] = true;
		$this->assertTrue( Runner::can_import_record( $record, 15 ) );
		$term = array(
			'type'     => 'term',
			'taxonomy' => 'genre',
		);
		$this->assertFalse( Runner::can_import_record( $term, null ) );
		$GLOBALS['jcore_silta_remote_caps']['edit_genres'] = true;
		$this->assertTrue( Runner::can_import_record( $term, null ) );
		$this->assertFalse( Runner::can_import_record( array( 'type' => 'menu' ), null ) );
	}

	/**
	 * Checks a pull needs publish_posts to publish and edit_others_posts to
	 * hand a post to another author, like the post editor.
	 *
	 * @return void
	 */
	public function test_pull_checks_the_status_and_author_it_would_write(): void {
		$GLOBALS['jcore_silta_remote_types']['book'] = (object) array(
			'cap' => (object) array(
				'publish_posts'     => 'publish_books',
				'edit_others_posts' => 'edit_others_books',
			),
		);

		$check    = new \ReflectionMethod( Runner::class, 'can_write_post' );
		$incoming = static fn( int $author ): array => array(
			'post_type' => 'book',
			'fields'    => array( 'post_author' => $author ),
		);
		$mine     = array(
			'fields' => array(
				'post_status' => 'publish',
				'post_author' => 7,
			),
		);

		$this->assertTrue( $check->invoke( null, $incoming( 7 ), null, 'draft' ) );
		$this->assertTrue( $check->invoke( null, $incoming( 0 ), null, 'pending' ) );
		$this->assertFalse( $check->invoke( null, $incoming( 7 ), null, 'publish' ) );
		$this->assertFalse( $check->invoke( null, $incoming( 7 ), null, 'private' ) );
		// An already published post the user may edit stays published.
		$this->assertTrue( $check->invoke( null, $incoming( 7 ), $mine, 'publish' ) );
		$this->assertFalse( $check->invoke( null, $incoming( 1 ), null, 'draft' ) );
		$this->assertFalse( $check->invoke( null, $incoming( 1 ), $mine, 'publish' ) );
		// An unmatched author keeps the existing post's author.
		$this->assertTrue( $check->invoke( null, $incoming( 0 ), $mine, 'publish' ) );

		$GLOBALS['jcore_silta_remote_caps']['publish_books']     = true;
		$GLOBALS['jcore_silta_remote_caps']['edit_others_books'] = true;
		$this->assertTrue( $check->invoke( null, $incoming( 1 ), null, 'publish' ) );
	}

	/**
	 * Checks pairing response cannot change host or downgrade https.
	 *
	 * @return void
	 */
	public function test_pairing_response_cannot_change_host_or_downgrade_https(): void {
		$this->assertInstanceOf( WP_Error::class, Connections::validate_url( 'http://remote.test/wp-json/', 'https://remote.test/wp-json/' ) );
		$this->assertInstanceOf( WP_Error::class, Connections::validate_url( 'https://attacker.test/wp-json/', 'https://remote.test/wp-json/' ) );
		$this->assertInstanceOf( WP_Error::class, Connections::validate_url( 'https://user:pass@remote.test/' ) );
		$this->assertInstanceOf( WP_Error::class, Connections::validate_url( 'file:///tmp/foo' ) );
		$this->assertSame( 'https://REMOTE.test/new/', Connections::validate_url( 'https://REMOTE.test/new/', 'https://remote.test/old/' ) );
		$GLOBALS['jcore_silta_remote_environment'] = 'development';
		$this->assertSame( 'http://localhost:8091/wp-json/', Connections::validate_url( 'http://localhost:8091/wp-json/', 'http://localhost:8091/' ) );
	}

	/**
	 * Checks client defaults to safe urls and caps response with private opt in.
	 *
	 * @return void
	 */
	public function test_client_defaults_to_safe_urls_and_caps_response_with_private_opt_in(): void {
		( new ReflectionProperty( Identity::class, 'seed' ) )->setValue( null, str_repeat( 's', 32 ) );
		$client = new Client(
			array(
				'rest' => 'https://remote.test/wp-json/',
				'site' => self::PEER,
			)
		);
		$this->assertSame( array(), $client->call( 'handshake' ) );
		$args = $GLOBALS['jcore_silta_remote_request']['args'];
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( 32 * 1024 * 1024, $args['limit_response_size'] );
		$this->assertSame( 0, $args['redirection'] );
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_allow_private_network'] = static fn(): bool => true;
		$client->call( 'handshake' );
		$this->assertFalse( $GLOBALS['jcore_silta_remote_request']['args']['reject_unsafe_urls'] );
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_max_response_bytes'] = static fn(): int => 5;
		$GLOBALS['jcore_silta_remote_response']['body']                        = '12345';
		$this->assertSame( 'jcore_silta_response_too_large', $client->call( 'handshake' )->get_error_code() );
	}

	/**
	 * Checks body limit runs before signatures on normal and pairing routes.
	 *
	 * @return void
	 */
	public function test_body_limit_runs_before_signatures_on_normal_and_pairing_routes(): void {
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_max_request_bytes'] = static fn(): int => 10;
		$request = $this->request( 'export', array( 'keys' => array( 'post:1' ) ) );
		$this->assertSame( 413, Verifier::verify( $request, 'read' )->get_error_data()['status'] );
		$this->assertSame( 413, Verifier::verify_pairing( $request )->get_error_data()['status'] );
	}

	/**
	 * Checks collection caps are filterable and include orphan deletions.
	 *
	 * @return void
	 */
	public function test_collection_caps_are_filterable_and_include_orphan_deletions(): void {
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_max_export_keys'] = static fn(): int => 1;
		$this->assertTrue( Verifier::check_limits( $this->request( 'export', array( 'keys' => array( 'post:1' ) ) ) ) );
		$this->assertSame( 413, Verifier::check_limits( $this->request( 'export', array( 'keys' => array( 'post:1', 'post:2' ) ) ) )->get_error_data()['status'] );
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_max_request_objects'] = static fn(): int => 1;
		foreach ( array( 'import', 'plan', 'diff' ) as $action ) {
			$request = $this->request( $action, array( 'bundle' => array( 'objects' => array( array(), array() ) ) ) );
			$this->assertSame( 413, Verifier::check_limits( $request )->get_error_data()['status'] );
		}
		$this->assertSame( 413, Verifier::check_limits( $this->request( 'import', array( 'options' => array( 'trash' => array( 1, 2 ) ) ) ) )->get_error_data()['status'] );
	}

	/**
	 * Checks nonce reservation rejects duplicate and database failure.
	 *
	 * @return void
	 */
	public function test_nonce_reservation_rejects_duplicate_and_database_failure(): void {
		$claim = new ReflectionMethod( Verifier::class, 'claim_nonce' );
		$this->assertTrue( $claim->invoke( null, 'fingerprint', 'nonce' ) );
		$this->assertSame( 'jcore_silta_replay', $claim->invoke( null, 'fingerprint', 'nonce' )->get_error_code() );
		$this->assertTrue( $claim->invoke( null, 'other', 'nonce' ) );
		$GLOBALS['wpdb']->fail = true;
		$this->assertInstanceOf( WP_Error::class, $claim->invoke( null, 'fingerprint', 'different' ) );
	}

	/**
	 * Checks pairing cannot evict a different active key but same key and inherited work.
	 *
	 * @return void
	 */
	public function test_pairing_cannot_evict_a_different_active_key_but_same_key_and_inherited_work(): void {
		$raw         = sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( str_repeat( 'k', 32 ) ) );
		$fingerprint = Signature::fingerprint( $raw );
		$existing    = array(
			'site'      => self::PEER,
			'enabled'   => true,
			'last_used' => 0,
		);
		$this->pending_key( array( 'different' => $existing ) );
		$error = Trusted_Keys::pair( 'token', base64_encode( $raw ), array( 'uuid' => self::PEER ) );
		$this->assertSame( 'jcore_silta_key_exists', $error->get_error_code() );
		$this->assertArrayHasKey( 'different', $GLOBALS['jcore_silta_remote_options'][ Trusted_Keys::OPTION ]['keys'] );
		$this->assertSame( 'jcore_silta_key_exists', Trusted_Keys::pair( 'token', base64_encode( $raw ), array( 'uuid' => strtoupper( self::PEER ) ) )->get_error_code() );
		$this->pending_key( array( $fingerprint => $existing ) );
		$this->assertSame( $fingerprint, Trusted_Keys::pair( 'token', base64_encode( $raw ), array( 'uuid' => self::PEER ) )['fingerprint'] );
		$existing['enabled']   = false;
		$existing['inherited'] = true;
		$this->pending_key( array( 'different' => $existing ) );
		$this->assertSame( $fingerprint, Trusted_Keys::pair( 'token', base64_encode( $raw ), array( 'uuid' => self::PEER ) )['fingerprint'] );
		$this->assertArrayNotHasKey( 'different', $GLOBALS['jcore_silta_remote_options'][ Trusted_Keys::OPTION ]['keys'] );
	}

	/**
	 * Checks touch cannot restore a key revoked during the touch.
	 *
	 * @return void
	 */
	public function test_touch_cannot_restore_a_key_revoked_during_the_touch(): void {
		$this->pending_key(
			array(
				'fingerprint' => array(
					'enabled'   => true,
					'last_used' => 0,
				),
			)
		);
		$GLOBALS['jcore_silta_remote_on_touch'] = static function (): void {
			Trusted_Keys::delete( 'fingerprint' );
		};
		Trusted_Keys::touch( 'fingerprint' );
		$this->assertNull( Trusted_Keys::get( 'fingerprint' ) );
	}

	/**
	 * Checks public connections omit all auth secrets.
	 *
	 * @return void
	 */
	public function test_public_connections_omit_all_auth_secrets(): void {
		$view = Connections::public_view(
			array(
				'auth' => array(
					'user' => 'username',
					'pass' => 'encrypted-secret',
				),
			)
		);
		$this->assertArrayNotHasKey( 'auth', $view );
		$this->assertSame( 'username', $view['auth_user'] );
		$this->assertTrue( $view['has_auth'] );
	}

	/**
	 * Checks pull discards unrequested wrong kind and wrong id records.
	 *
	 * @return void
	 */
	public function test_pull_discards_unrequested_wrong_kind_and_wrong_id_records(): void {
		$items   = array(
			array(
				'key'         => 'post:12',
				'object_type' => 'post',
				'source_id'   => 12,
			),
		);
		$good    = array(
			'key'       => 'post:12',
			'type'      => 'post',
			'id'        => 12,
			'post_type' => 'post',
			'hash'      => 'abc',
			'content'   => '',
			'refs'      => array( 'post:13' ),
			'fields'    => array(
				'post_title'     => 'Title',
				'post_name'      => 'title',
				'post_excerpt'   => '',
				'post_status'    => 'publish',
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
		$records = array(
			array_merge( $good, array( 'refs' => array( array() ) ) ),
			array(
				'key'  => 'post:13',
				'type' => 'post',
				'id'   => 13,
			),
			array(
				'key'  => 'post:12',
				'type' => 'term',
				'id'   => 12,
			),
			array(
				'key'  => 'post:12',
				'type' => 'post',
				'id'   => 999,
			),
			array(
				'key'  => array(),
				'type' => 'post',
				'id'   => 12,
			),
			'garbage',
			$good,
		);
		$this->assertSame( array( 'post:12' => $good ), Runner::requested_records( $records, $items ) );
	}

	/**
	 * Provides job for the test fixture.
	 *
	 * @param string $role Role.
	 * @param string $peer Peer.
	 *
	 * @return array
	 */
	private function job( string $role = 'target', string $peer = self::PEER ): array {
		return array(
			'id'          => 1,
			'uuid'        => self::JOB,
			'role'        => $role,
			'remote_site' => $peer,
			'user_id'     => 7,
			'options'     => '{}',
			'state'       => '{}',
			'summary'     => '{}',
		);
	}

	/**
	 * Provides request for the test fixture.
	 *
	 * @param string $action Action.
	 * @param array  $args   Args.
	 *
	 * @return WP_REST_Request
	 */
	private function request( string $action, array $args ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/jcore-silta/v1/remote/' . $action );
		$request->set_body( wp_json_encode( $args ) );
		return $request;
	}

	/**
	 * Provides pending key for the test fixture.
	 *
	 * @param array $keys Keys.
	 *
	 * @return void
	 */
	private function pending_key( array $keys ): void {
		$GLOBALS['jcore_silta_remote_options'][ Trusted_Keys::OPTION ] = array(
			'keys'   => $keys,
			'tokens' => array(
				hash( 'sha256', 'token' ) => array(
					'label'   => '',
					'scopes'  => array( 'read' ),
					'user_id' => 7,
					'expires' => time() + 100,
				),
			),
		);
	}
}

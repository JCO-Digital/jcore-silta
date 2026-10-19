<?php
/**
 * Tests for jcore-maailma global content: the block's ID and slug
 * references, and keeping slugs while Silta writes.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Integrations\Maailma;
use Jcore\Silta\References\Blocks;
use Jcore\Silta\References\Walker;
use Jcore\Silta\Sync\Context;
use Jcore\Silta\Sync\Importer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Maailma.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class MaailmaTest extends TestCase {

	private const TYPE = 'jcore-global-content';

	/**
	 * Source post ID => target post ID. Anything else is unresolved.
	 *
	 * @var array<int, int>
	 */
	private array $map = array(
		10 => 110,
		11 => 111,
		12 => 12,
		20 => 120,
		30 => 130,
	);

	/**
	 * Source slug => source post ID, as the source site (or the bundle) knows them.
	 *
	 * @var array<string, int>
	 */
	private array $source_slugs = array(
		'footer'    => 10,
		'footer-2'  => 11,
		'clone'     => 12,
		'wrong'     => 30,
		'stale-new' => 10,
	);

	protected function setUp(): void {
		require_once __DIR__ . '/stubs/incoming.php';
		require_once __DIR__ . '/stubs/maailma.php';

		// The target site's posts.
		$GLOBALS['jcore_silta_test_posts']                                   = array(
			110 => array(
				'post_type' => self::TYPE,
				'post_name' => 'footer',
			),
			111 => array(
				'post_type' => self::TYPE,
				'post_name' => 'footer-3',
			),
			12  => array(
				'post_type' => self::TYPE,
				'post_name' => 'clone-renamed',
			),
			130 => array(
				'post_type' => 'page',
				'post_name' => 'wrong-type',
			),
		);
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_block_references'] = array( Maailma::class, 'block_references' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['jcore_silta_test_posts'], $GLOBALS['jcore_silta_test_actions'], $GLOBALS['jcore_silta_test_slug_syncs'], $GLOBALS['jcore_silta_test_filters']['jcore_silta_block_references'] );
	}

	/**
	 * Remapping visitor; records what it saw.
	 *
	 * @param array<int, string> $seen Visited keys, appended to.
	 *
	 * @return callable
	 */
	private function visitor( array &$seen = array() ): callable {
		return function ( string $kind, int $id ) use ( &$seen ): ?int {
			$seen[] = $kind . ':' . $id;

			return 'post' === $kind ? ( $this->map[ $id ] ?? null ) : null;
		};
	}

	/**
	 * Resolver for the source's slugs.
	 *
	 * @return callable
	 */
	private function slugs(): callable {
		return fn( string $post_type, string $slug ): ?int => self::TYPE === $post_type ? ( $this->source_slugs[ $slug ] ?? null ) : null;
	}

	/**
	 * Walks content on the target.
	 *
	 * @param string             $content Content.
	 * @param array<int, string> $seen    Visited keys.
	 *
	 * @return string
	 */
	private function walk( string $content, array &$seen = array() ): string {
		return Blocks::walk( $content, $this->visitor( $seen ), static fn(): ?array => null, $this->slugs() );
	}

	public function test_block_declares_its_id_and_slug_attributes(): void {
		$this->assertSame(
			array(
				'jco/global-content' => array(
					'selectedPostId' => 'post',
					'selectedSlug'   => 'slug:' . self::TYPE,
				),
			),
			Maailma::block_references( array() )
		);
	}

	public function test_id_is_remapped_and_slug_follows_the_target_post(): void {
		$this->assertSame(
			'<!-- wp:jco/global-content {"selectedPostId":111,"selectedSlug":"footer-3"} /-->',
			$this->walk( '<!-- wp:jco/global-content {"selectedPostId":11,"selectedSlug":"footer-2"} /-->' )
		);
	}

	public function test_slug_the_target_shares_only_remaps_the_id(): void {
		$this->assertSame(
			'<!-- wp:jco/global-content {"selectedPostId":110,"selectedSlug":"footer"} /-->',
			$this->walk( '<!-- wp:jco/global-content {"selectedPostId":10,"selectedSlug":"footer"} /-->' )
		);
	}

	public function test_block_that_maps_to_itself_stays_byte_identical(): void {
		// Same ID on both sites (a database clone): the slug is left alone, as
		// it is for a reference to a post the job is still going to create.
		$content = '<!-- wp:jco/global-content {"selectedPostId":12,"selectedSlug":"clone"} /-->';

		$this->assertSame( $content, $this->walk( $content ) );
	}

	public function test_block_saved_before_slugs_has_only_its_id_remapped(): void {
		$this->assertSame(
			'<!-- wp:jco/global-content {"selectedPostId":110} /-->',
			$this->walk( '<!-- wp:jco/global-content {"selectedPostId":10} /-->' )
		);
		$this->assertSame(
			'<!-- wp:jco/global-content {"selectedPostId":110,"selectedSlug":""} /-->',
			$this->walk( '<!-- wp:jco/global-content {"selectedPostId":10,"selectedSlug":""} /-->' )
		);
	}

	public function test_slug_naming_another_post_than_the_id_is_followed_too(): void {
		// The block renders the post its slug names; a stale ID that doesn't
		// resolve is dropped, so the block falls back to nothing but the slug.
		$seen   = array();
		$result = $this->walk( '<!-- wp:jco/global-content {"selectedPostId":99,"selectedSlug":"stale-new"} /-->', $seen );

		$this->assertSame( '<!-- wp:jco/global-content {"selectedSlug":"footer"} /-->', $result );
		$this->assertSame( array( 'post:99', 'post:10' ), $seen );
	}

	public function test_unknown_slugs_and_unresolved_posts_keep_the_slug(): void {
		$seen = array();
		$this->assertSame(
			'<!-- wp:jco/global-content {"selectedPostId":110,"selectedSlug":"nowhere"} /-->',
			$this->walk( '<!-- wp:jco/global-content {"selectedPostId":10,"selectedSlug":"nowhere"} /-->', $seen )
		);
		$this->assertSame( array( 'post:10' ), $seen );

		$this->source_slugs['missing'] = 98;
		$this->assertSame(
			'<!-- wp:jco/global-content {"selectedSlug":"missing"} /-->',
			$this->walk( '<!-- wp:jco/global-content {"selectedPostId":98,"selectedSlug":"missing"} /-->' )
		);
	}

	public function test_slug_is_not_taken_from_a_post_of_another_type(): void {
		$this->assertSame(
			'<!-- wp:jco/global-content {"selectedSlug":"wrong"} /-->',
			$this->walk( '<!-- wp:jco/global-content {"selectedSlug":"wrong"} /-->' )
		);
	}

	public function test_source_lookup_finds_slugs_on_this_site(): void {
		$GLOBALS['jcore_silta_test_posts'][5] = array(
			'post_type' => self::TYPE,
			'post_name' => 'contact-box',
		);

		$this->assertSame( 5, Blocks::local_slug( self::TYPE, 'Contact Box' ) );
		$this->assertNull( Blocks::local_slug( 'page', 'contact-box' ) );
		$this->assertNull( Blocks::local_slug( self::TYPE, '' ) );
	}

	public function test_export_discovers_embedded_and_nested_references(): void {
		// Global content that embeds other global content (by slug only), an
		// image, and has a translation: all of them become dependencies.
		$GLOBALS['jcore_silta_test_posts'][7] = array(
			'post_type' => self::TYPE,
			'post_name' => 'opening-hours',
		);
		$record                               = array(
			'type'     => 'post',
			'content'  => '<!-- wp:jco/global-content {"selectedSlug":"opening-hours"} /--><!-- wp:image {"id":20} --><figure><img class="wp-image-20"/></figure><!-- /wp:image --><!-- wp:jco/global-content {"selectedPostId":11,"selectedSlug":"footer-2"} /-->',
			'language' => array(
				'lang'         => 'fi',
				'translations' => array( 'en' => 13 ),
			),
		);
		$refs                                 = array();
		$copy                                 = $record;

		// The exporter's collecting visitor, with the default lookup on this site.
		Walker::walk(
			$copy,
			static function ( string $kind, int $id ) use ( &$refs ): int {
				$refs[ $kind . ':' . $id ] = true;

				return $id;
			},
			static fn(): ?array => null
		);

		$this->assertSame( array( 'post:7', 'post:20', 'post:11', 'post:13' ), array_keys( $refs ) );
		$this->assertSame( $record['content'], $copy['content'] );
	}

	public function test_target_resolves_slugs_through_the_bundle(): void {
		$post = static fn( int $id, string $type, string $name ): array => array(
			'key'       => 'post:' . $id,
			'type'      => 'post',
			'id'        => $id,
			'post_type' => $type,
			'hash'      => 'hash',
			'content'   => '',
			'fields'    => array(
				'post_title'     => $name,
				'post_name'      => $name,
				'post_excerpt'   => '',
				'post_status'    => 'publish',
				'post_date'      => '2026-10-19 12:00:00',
				'post_date_gmt'  => '2026-10-19 09:00:00',
				'post_author'    => 1,
				'post_parent'    => 0,
				'menu_order'     => 0,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_password'  => '',
			),
		);
		$ctx  = new Context(
			array(
				'objects' => array( $post( 10, self::TYPE, 'footer' ), $post( 40, 'page', 'about' ) ),
				'stubs'   => array(
					'post:11' => array(
						'key'       => 'post:11',
						'type'      => 'post',
						'id'        => 11,
						'post_type' => self::TYPE,
						'post_name' => 'footer-2',
					),
					'post:12' => array(
						'key'     => 'post:12',
						'missing' => true,
					),
				),
			),
			array(),
			'peer'
		);

		$this->assertSame( 10, $ctx->slug( self::TYPE, 'footer' ) );
		$this->assertSame( 11, $ctx->slug( self::TYPE, 'Footer-2' ) );
		$this->assertNull( $ctx->slug( self::TYPE, 'about' ) );
		$this->assertNull( $ctx->slug( self::TYPE, 'nowhere' ) );
		$this->assertSame( 40, $ctx->slug( 'page', 'about' ) );

		// The target walks with the bundle's slugs and its own visitor.
		$this->assertSame(
			'<!-- wp:jco/global-content {"selectedPostId":111,"selectedSlug":"footer-3"} /-->',
			Blocks::walk( '<!-- wp:jco/global-content {"selectedPostId":11,"selectedSlug":"footer-2"} /-->', $this->visitor(), static fn(): ?array => null, array( $ctx, 'slug' ) )
		);
	}

	public function test_slug_sync_is_suspended_only_while_silta_writes(): void {
		require_once __DIR__ . '/stubs/maailma-plugin.php';
		$hook = 'save_post_' . self::TYPE;
		add_action( $hook, 'Jcore\Maailma\update_slug', 10, 3 );
		Maailma::register();
		$this->assertSame( 9, has_action( $hook, array( Maailma::class, 'suspend_slug_sync' ) ) );

		do_action( $hook, 5, null, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Maailma's hook.
		$this->assertSame( 1, $GLOBALS['jcore_silta_test_slug_syncs'], 'Outside Silta, Maailma renames as usual.' );

		$writing = new \ReflectionProperty( Importer::class, 'writing' );
		$writing->setValue( null, 1 );
		do_action( $hook, 5, null, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Maailma's hook.
		$writing->setValue( null, 0 );
		$this->assertSame( 1, $GLOBALS['jcore_silta_test_slug_syncs'], 'While Silta writes, Maailma keeps the slug.' );
		$this->assertSame( 10, has_action( $hook, 'Jcore\Maailma\update_slug' ), 'Its callback is back afterwards.' );
		$this->assertFalse( has_action( $hook, array( Maailma::class, 'resume_slug_sync' ) ) );

		do_action( $hook, 5, null, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Maailma's hook.
		$this->assertSame( 2, $GLOBALS['jcore_silta_test_slug_syncs'] );
	}

	public function test_slug_sync_suspension_without_maailma_is_a_no_op(): void {
		$writing = new \ReflectionProperty( Importer::class, 'writing' );
		$writing->setValue( null, 1 );
		Maailma::suspend_slug_sync();
		$writing->setValue( null, 0 );

		$this->assertFalse( has_action( 'save_post_' . self::TYPE, array( Maailma::class, 'resume_slug_sync' ) ) );
	}
}

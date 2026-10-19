<?php
/**
 * JCORE Maailma global content.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Integrations;

use Jcore\Silta\Sync\Importer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes global content from jcore-maailma travel intact.
 *
 * Global content posts sync like any other post type. What needs help:
 *
 * - The `jco/global-content` block names its post by slug and, as a fallback,
 *   by ID. Both are declared through `jcore_silta_block_references`, so the
 *   post is pulled in as a dependency and both attributes are remapped.
 * - Maailma renames a global content post's slug after its title on every
 *   update. Slugs are what templates and the block find global content by, so
 *   while Silta writes a post, the slug it brings is kept.
 */
final class Maailma {

	/**
	 * The block that embeds global content.
	 */
	public const BLOCK = 'jco/global-content';

	/**
	 * Maailma's callback that renames slugs after titles.
	 */
	private const SLUG_CALLBACK = 'Jcore\Maailma\update_slug';

	/**
	 * Priority Maailma's slug callback was removed from, while it is suspended.
	 *
	 * @var int|null
	 */
	private static ?int $suspended = null;

	/**
	 * Hooks the integration. Harmless when Maailma isn't active.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'jcore_silta_block_references', array( self::class, 'block_references' ) );
		add_action( 'save_post_' . self::post_type(), array( self::class, 'suspend_slug_sync' ), 9, 0 );
	}

	/**
	 * Returns the global content post type.
	 *
	 * @return string
	 */
	public static function post_type(): string {
		return defined( 'JCORE_MAAILMA_POST_TYPE' ) ? (string) JCORE_MAAILMA_POST_TYPE : 'jcore-global-content';
	}

	/**
	 * Declares the block's attributes: the post's slug, which the block looks
	 * up first, and its ID, the fallback.
	 *
	 * @param array<string, array<string, string>> $paths Paths per block.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function block_references( array $paths ): array {
		$paths[ self::BLOCK ] = array(
			'selectedPostId' => 'post',
			'selectedSlug'   => 'slug:' . self::post_type(),
		);

		return $paths;
	}

	/**
	 * Keeps Maailma from renaming the slug of a post Silta is writing. Runs
	 * just before Maailma's own `save_post` callback and puts it back right
	 * after it would have run.
	 *
	 * @return void
	 */
	public static function suspend_slug_sync(): void {
		$hook     = 'save_post_' . self::post_type();
		$priority = has_action( $hook, self::SLUG_CALLBACK );
		if ( ! Importer::writing() || false === $priority || null !== self::$suspended ) {
			return;
		}

		remove_action( $hook, self::SLUG_CALLBACK, $priority );
		self::$suspended = (int) $priority;
		add_action( $hook, array( self::class, 'resume_slug_sync' ), self::$suspended + 1, 0 );
	}

	/**
	 * Puts Maailma's slug callback back.
	 *
	 * @return void
	 */
	public static function resume_slug_sync(): void {
		if ( null === self::$suspended ) {
			return;
		}

		$hook = 'save_post_' . self::post_type();
		add_action( $hook, self::SLUG_CALLBACK, self::$suspended, 3 );
		remove_action( $hook, array( self::class, 'resume_slug_sync' ), self::$suspended + 1 );
		self::$suspended = null;
	}
}

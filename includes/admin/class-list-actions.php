<?php
/**
 * Push and pull actions in post lists.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Admin;

use Jcore\Silta\Capabilities;
use Jcore\Silta\Remote\Connections;
use Jcore\Silta\Sync\Content;
use Jcore\Silta\Sync\Mapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds "Push with Silta" row and bulk actions, and "Pull from …" for posts that
 * came from a connected site. They open the Silta screen with those posts
 * selected; nothing is synced without the review step.
 */
final class List_Actions {

	/**
	 * Bulk action name.
	 */
	private const BULK = 'jcore_silta_push';

	/**
	 * Connections, loaded once per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $connections = null;

	/**
	 * Hooks the actions.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'init' ) );
	}

	/**
	 * Adds the hooks for users who can sync, when there is a connection.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! current_user_can( Capabilities::SYNC ) || empty( self::connections() ) ) {
			return;
		}

		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( 'media_row_actions', array( self::class, 'row_actions' ), 10, 2 );

		foreach ( Content::types() as $type ) {
			$screen = 'attachment' === $type['name'] ? 'upload' : 'edit-' . $type['name'];
			add_filter( 'bulk_actions-' . $screen, array( self::class, 'bulk_actions' ) );
			add_filter( 'handle_bulk_actions-' . $screen, array( self::class, 'handle_bulk' ), 10, 3 );
		}
	}

	/**
	 * Adds the row actions.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Post.
	 *
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( ! in_array( $post->post_type, array_column( Content::types(), 'name' ), true ) ) {
			return $actions;
		}

		if ( self::can( 'write' ) ) {
			$actions['jcore_silta_push'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					Menu::url(
						array(
							'posts'     => $post->ID,
							'direction' => 'push',
						)
					)
				),
				esc_html__( 'Push with Silta', 'jcore-silta' )
			);
		}

		foreach ( Mapping::for_local( 'post', $post->ID ) as $row ) {
			$connection = self::connection_for_site( (string) $row['site'] );
			if ( null === $connection || ! in_array( 'read', (array) $connection['scopes'], true ) ) {
				continue;
			}

			$actions[ 'jcore_silta_pull_' . $connection['id'] ] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					Menu::url(
						array(
							'posts'      => (int) $row['remote_id'],
							'direction'  => 'pull',
							'connection' => (string) $connection['id'],
							'diff'       => 1,
						)
					)
				),
				/* translators: %s: connected site's label. */
				esc_html( sprintf( __( 'Pull from %s', 'jcore-silta' ), $connection['label'] ) )
			);
		}

		return $actions;
	}

	/**
	 * Adds the bulk action.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 *
	 * @return array<string, string>
	 */
	public static function bulk_actions( array $actions ): array {
		if ( self::can( 'write' ) ) {
			$actions[ self::BULK ] = __( 'Push with Silta', 'jcore-silta' );
		}

		return $actions;
	}

	/**
	 * Sends the bulk selection to the Silta screen.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Selected IDs.
	 *
	 * @return string
	 */
	public static function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( self::BULK !== $action || empty( $ids ) ) {
			return $redirect;
		}

		return Menu::url(
			array(
				'posts'     => implode( ',', array_map( 'intval', $ids ) ),
				'direction' => 'push',
			)
		);
	}

	/**
	 * Whether any connection has a scope.
	 *
	 * @param string $scope read or write.
	 *
	 * @return bool
	 */
	private static function can( string $scope ): bool {
		foreach ( self::connections() as $connection ) {
			if ( in_array( $scope, (array) $connection['scopes'], true ) && 'ok' === $connection['status'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the connection to a site.
	 *
	 * @param string $site Site UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function connection_for_site( string $site ): ?array {
		foreach ( self::connections() as $connection ) {
			if ( $connection['site'] === $site && 'ok' === $connection['status'] ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Returns the connections.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function connections(): array {
		if ( null === self::$connections ) {
			self::$connections = Connections::all();
		}

		return self::$connections;
	}
}

<?php
/**
 * Finds the local copy of a remote object.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

use Jcore\Silta\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the matching ladder: stored mapping, origin, GUID, file path, slug.
 * Gravity Forms forms have no GUID or slug: after the mapping and origin they
 * match by the same ID with the same title (a database clone), then by title.
 */
final class Matcher {

	/**
	 * Remote site UUID.
	 *
	 * @var string
	 */
	private string $site;

	/**
	 * Remote home URL, for GUIDs rewritten by a search-replace.
	 *
	 * @var string
	 */
	private string $source_home;

	/**
	 * Creates a matcher for objects from one site.
	 *
	 * @param string $site        Remote site UUID.
	 * @param string $source_home Remote home URL.
	 */
	public function __construct( string $site, string $source_home ) {
		$this->site        = $site;
		$this->source_home = untrailingslashit( $source_home );
	}

	/**
	 * Finds the local copy of an object or stub.
	 *
	 * @param array<string, mixed> $record Object or stub.
	 *
	 * @return array{id: int, method: string}|null
	 */
	public function match( array $record ): ?array {
		return match ( $record['type'] ?? '' ) {
			'post' => $this->post( $record ),
			'term' => $this->term( $record ),
			'menu' => $this->menu( $record ),
			'form' => $this->form( $record ),
			default => null,
		};
	}

	/**
	 * Matches a post.
	 *
	 * @param array<string, mixed> $record Post or stub.
	 *
	 * @return array{id: int, method: string}|null
	 */
	private function post( array $record ): ?array {
		global $wpdb;

		$id        = (int) $record['id'];
		$post_type = (string) $record['post_type'];

		$row = Mapping::get( $this->site, 'post', $id );
		if ( null !== $row && $this->post_ok( (int) $row['local_id'], $post_type ) ) {
			return array(
				'id'     => (int) $row['local_id'],
				'method' => 'mapping',
			);
		}

		$origin = $this->origin( (array) ( $record['origins'] ?? array() ), 'post' );
		if ( null !== $origin && $this->post_ok( $origin, $post_type ) && $this->free( 'post', $origin, $id ) ) {
			return array(
				'id'     => $origin,
				'method' => 'origin',
			);
		}

		$guid = (string) ( $record['guid'] ?? '' );
		if ( '' !== $guid ) {
			$guids = array_unique( array( $guid, str_replace( $this->source_home, Site::home(), $guid ) ) );
			$found = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lookup by GUID.
				$wpdb->prepare(
					"SELECT ID FROM %i WHERE guid IN (%s, %s) AND post_type = %s AND post_status NOT IN ('trash', 'auto-draft')",
					$wpdb->posts,
					$guids[0],
					$guids[1] ?? $guids[0],
					$post_type
				)
			);
			if ( 1 === count( $found ) && $this->free( 'post', (int) $found[0], $id ) ) {
				return array(
					'id'     => (int) $found[0],
					'method' => 'guid',
				);
			}
		}

		if ( 'attachment' === $post_type ) {
			$path = (string) ( $record['file']['path'] ?? $record['path'] ?? '' );
			if ( '' !== $path ) {
				$found = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lookup by file.
					$wpdb->prepare(
						"SELECT p.ID FROM %i p INNER JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file' WHERE m.meta_value = %s AND p.post_type = 'attachment'",
						$wpdb->posts,
						$wpdb->postmeta,
						$path
					)
				);
				if ( 1 === count( $found ) && $this->free( 'post', (int) $found[0], $id ) ) {
					return array(
						'id'     => (int) $found[0],
						'method' => 'path',
					);
				}
			}

			return null;
		}

		$slug = (string) ( $record['fields']['post_name'] ?? $record['post_name'] ?? '' );
		if ( '' === $slug ) {
			return null;
		}

		$candidates = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lookup by slug.
			$wpdb->prepare(
				"SELECT ID, post_parent FROM %i WHERE post_name = %s AND post_type = %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit')",
				$wpdb->posts,
				$slug,
				$post_type
			),
			ARRAY_A
		);
		$candidates = array_values(
			array_filter(
				(array) $candidates,
				function ( array $candidate ) use ( $id ): bool {
					return $this->free( 'post', (int) $candidate['ID'], $id );
				}
			)
		);

		if ( count( $candidates ) > 1 ) {
			// Several pages share the slug under different parents: use the parent.
			$parent       = (int) ( $record['fields']['post_parent'] ?? $record['parent'] ?? 0 );
			$local_parent = 0 === $parent ? 0 : ( Mapping::get( $this->site, 'post', $parent )['local_id'] ?? -1 );
			$candidates   = array_values(
				array_filter(
					$candidates,
					static function ( array $candidate ) use ( $local_parent ): bool {
						return (int) $candidate['post_parent'] === $local_parent;
					}
				)
			);
		}

		if ( 1 === count( $candidates ) ) {
			return array(
				'id'     => (int) $candidates[0]['ID'],
				'method' => 'slug',
			);
		}

		return null;
	}

	/**
	 * Matches a term.
	 *
	 * @param array<string, mixed> $record Term or stub.
	 *
	 * @return array{id: int, method: string}|null
	 */
	private function term( array $record ): ?array {
		$id       = (int) $record['id'];
		$taxonomy = (string) $record['taxonomy'];

		$row = Mapping::get( $this->site, 'term', $id );
		if ( null !== $row && $this->term_ok( (int) $row['local_id'], $taxonomy ) ) {
			return array(
				'id'     => (int) $row['local_id'],
				'method' => 'mapping',
			);
		}

		$origin = $this->origin( (array) ( $record['origins'] ?? array() ), 'term' );
		if ( null !== $origin && $this->term_ok( $origin, $taxonomy ) && $this->free( 'term', $origin, $id ) ) {
			return array(
				'id'     => $origin,
				'method' => 'origin',
			);
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$term = get_term_by( 'slug', (string) $record['slug'], $taxonomy );
		if ( $term instanceof \WP_Term && $this->free( 'term', (int) $term->term_id, $id ) ) {
			return array(
				'id'     => (int) $term->term_id,
				'method' => 'slug',
			);
		}

		return null;
	}

	/**
	 * Matches a menu.
	 *
	 * @param array<string, mixed> $record Menu.
	 *
	 * @return array{id: int, method: string}|null
	 */
	private function menu( array $record ): ?array {
		$id = (int) $record['id'];

		$row = Mapping::get( $this->site, 'term', $id );
		if ( null !== $row && $this->term_ok( (int) $row['local_id'], 'nav_menu' ) ) {
			return array(
				'id'     => (int) $row['local_id'],
				'method' => 'mapping',
			);
		}

		$origin = $this->origin( (array) ( $record['origins'] ?? array() ), 'term' );
		if ( null !== $origin && $this->term_ok( $origin, 'nav_menu' ) ) {
			return array(
				'id'     => $origin,
				'method' => 'origin',
			);
		}

		$menu = wp_get_nav_menu_object( (string) $record['slug'] );
		if ( $menu instanceof \WP_Term && $this->free( 'term', (int) $menu->term_id, $id ) ) {
			return array(
				'id'     => (int) $menu->term_id,
				'method' => 'slug',
			);
		}

		return null;
	}

	/**
	 * Matches a Gravity Forms form.
	 *
	 * Forms live in Gravity Forms' own tables, so there is no GUID: a form that
	 * has the same ID and the same title is taken to be the same form, which
	 * is what a database clone leaves behind. Then a form with the same title,
	 * if exactly one has it (Gravity Forms keeps titles unique).
	 *
	 * @param array<string, mixed> $record Form or stub.
	 *
	 * @return array{id: int, method: string}|null
	 */
	private function form( array $record ): ?array {
		if ( ! Gravity_Forms::active() ) {
			return null;
		}
		$id    = (int) $record['id'];
		$title = (string) ( $record['name'] ?? '' );

		$row = Mapping::get( $this->site, 'form', $id );
		if ( null !== $row && Gravity_Forms::exists( (int) $row['local_id'] ) ) {
			return array(
				'id'     => (int) $row['local_id'],
				'method' => 'mapping',
			);
		}

		$origin = $this->origin( (array) ( $record['origins'] ?? array() ), 'form' );
		if ( null !== $origin && Gravity_Forms::exists( $origin ) && $this->free( 'form', $origin, $id ) ) {
			return array(
				'id'     => $origin,
				'method' => 'origin',
			);
		}

		$candidate = self::form_candidate( $id, $title, Gravity_Forms::title( $id ), Gravity_Forms::ids_by_title( $title ) );
		if ( null !== $candidate && $this->free( 'form', $candidate['id'], $id ) ) {
			return $candidate;
		}

		return null;
	}

	/**
	 * Picks the local form a remote one matches, without a mapping.
	 *
	 * @param int    $id          Remote form ID.
	 * @param string $title       Remote form title.
	 * @param string $local_title Title of the local form with the same ID, or ''.
	 * @param int[]  $by_title    Local forms with the remote title.
	 *
	 * @return array{id: int, method: string}|null
	 */
	public static function form_candidate( int $id, string $title, string $local_title, array $by_title ): ?array {
		if ( '' === $title ) {
			return null;
		}
		if ( $local_title === $title ) {
			return array(
				'id'     => $id,
				'method' => 'clone',
			);
		}
		$by_title = array_values( array_unique( array_map( 'intval', $by_title ) ) );
		if ( 1 === count( $by_title ) ) {
			return array(
				'id'     => $by_title[0],
				'method' => 'title',
			);
		}

		return null;
	}

	/**
	 * Resolves an object's origins: this site, or a third site mapped here.
	 *
	 * @param array<int, array<string, mixed>> $origins Origins from the bundle.
	 * @param string                           $type    post, term or form.
	 *
	 * @return int|null Local ID.
	 */
	private function origin( array $origins, string $type ): ?int {
		foreach ( $origins as $origin ) {
			$site = (string) ( $origin['site'] ?? '' );
			$id   = (int) ( $origin['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			if ( Site::uuid() === $site ) {
				return $id;
			}
			$row = Mapping::get( $site, $type, $id );
			if ( null !== $row ) {
				return (int) $row['local_id'];
			}
		}

		return null;
	}

	/**
	 * Whether a local post exists with the right type and isn't trashed.
	 *
	 * @param int    $id        Local ID.
	 * @param string $post_type Expected post type.
	 *
	 * @return bool
	 */
	private function post_ok( int $id, string $post_type ): bool {
		$post = get_post( $id );

		return $post instanceof \WP_Post && $post->post_type === $post_type && 'trash' !== $post->post_status;
	}

	/**
	 * Whether a local term exists in the right taxonomy.
	 *
	 * @param int    $id       Local ID.
	 * @param string $taxonomy Expected taxonomy.
	 *
	 * @return bool
	 */
	private function term_ok( int $id, string $taxonomy ): bool {
		$term = get_term( $id );

		return $term instanceof \WP_Term && $term->taxonomy === $taxonomy;
	}

	/**
	 * Whether a local object isn't already the copy of another object from the
	 * same site, so two remote posts never merge into one.
	 *
	 * @param string $type      post or term.
	 * @param int    $local_id  Local ID.
	 * @param int    $remote_id Remote ID being matched.
	 *
	 * @return bool
	 */
	private function free( string $type, int $local_id, int $remote_id ): bool {
		$mapped = Mapping::remote_for( $this->site, $type, $local_id );

		return null === $mapped || $mapped === $remote_id;
	}
}

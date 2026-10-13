<?php
/**
 * Builds bundles on the source.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

use Jcore\Silta\References\Walker;
use Jcore\Silta\Site;
use Jcore\Silta\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports posts, terms and menus with everything the target needs to place
 * them: their references, stubs for referenced objects not in the bundle, the
 * users and ACF fields they mention, and how their meta groups into sections.
 */
final class Exporter {

	/**
	 * Users referenced, by ID.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $users = array();

	/**
	 * ACF fields described, by key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $acf = array();

	/**
	 * Exports objects by key.
	 *
	 * @param string[] $keys `type:id` keys.
	 *
	 * @return array<string, mixed> Bundle.
	 */
	public function export( array $keys ): array {
		$objects = array();
		$missing = array();

		foreach ( array_unique( $keys ) as $key ) {
			list( $type, $id ) = Util::split_key( (string) $key );
			$record            = $this->object( $type, $id );
			if ( null === $record ) {
				$missing[] = $key;
				continue;
			}
			$objects[ $record['key'] ] = $record;
		}

		$stubs = array();
		foreach ( $objects as $record ) {
			foreach ( $record['refs'] as $ref ) {
				if ( isset( $objects[ $ref ] ) || isset( $stubs[ $ref ] ) ) {
					continue;
				}
				$stubs[ $ref ] = self::stub( $ref ) ?? array(
					'key'     => $ref,
					'missing' => true,
				);
			}
		}

		return array(
			'protocol'   => Site::PROTOCOL,
			'site'       => Site::info(),
			'objects'    => array_values( $objects ),
			'stubs'      => $stubs,
			'users'      => $this->users,
			'acf_fields' => $this->acf,
			'missing'    => $missing,
		);
	}

	/**
	 * Describes an ACF field and remembers it for the bundle.
	 *
	 * @param string $key Field key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function field( string $key ): ?array {
		$description = Acf::describe( $key );
		if ( null !== $description ) {
			$this->acf[ $key ] = $description;
		}

		return $description;
	}

	/**
	 * Returns the minimal description of a referenced object the target needs
	 * to find its own copy.
	 *
	 * @param string $key `type:id` key.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function stub( string $key ): ?array {
		list( $type, $id ) = Util::split_key( $key );

		if ( 'post' === $type ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || in_array( $post->post_type, array( 'revision', 'nav_menu_item' ), true ) ) {
				return null;
			}

			$stub = array(
				'key'       => $key,
				'type'      => 'post',
				'id'        => $id,
				'post_type' => $post->post_type,
				'guid'      => $post->guid,
				'post_name' => $post->post_name,
				'parent'    => (int) $post->post_parent,
				'title'     => '' !== $post->post_title ? $post->post_title : $post->post_name,
				'status'    => $post->post_status,
				'origins'   => self::origins( 'post', $id ),
			);
			if ( 'attachment' === $post->post_type ) {
				$stub['path'] = (string) get_post_meta( $id, '_wp_attached_file', true );
			}

			return $stub;
		}

		if ( 'term' === $type ) {
			$term = get_term( $id );
			if ( ! $term instanceof \WP_Term ) {
				return null;
			}

			return array(
				'key'      => $key,
				'type'     => 'term',
				'id'       => $id,
				'taxonomy' => $term->taxonomy,
				'slug'     => $term->slug,
				'name'     => $term->name,
				'title'    => $term->name,
				'parent'   => (int) $term->parent,
				'origins'  => self::origins( 'term', $id ),
			);
		}

		return null;
	}

	/**
	 * Returns where a local object originally came from, so a target that is
	 * one of those sites can map it straight back.
	 *
	 * @param string $type post or term.
	 * @param int    $id   Local ID.
	 *
	 * @return array<int, array{site: string, id: int}>
	 */
	public static function origins( string $type, int $id ): array {
		$origins = array();
		foreach ( Mapping::for_local( $type, $id ) as $row ) {
			$origins[] = array(
				'site' => (string) $row['site'],
				'id'   => (int) $row['remote_id'],
			);
		}

		return $origins;
	}

	/**
	 * Exports one object.
	 *
	 * @param string $type post, term or menu.
	 * @param int    $id   Object ID.
	 *
	 * @return array<string, mixed>|null
	 */
	private function object( string $type, int $id ): ?array {
		$state = State::read( $type, $id );
		if ( null === $state ) {
			return null;
		}

		$state['key']     = Util::key( $type, $id );
		$state['hash']    = State::hash( $state );
		$state['title']   = self::title( $state );
		$state['origins'] = self::origins( 'post' === $type ? 'post' : 'term', $id );

		$refs = array();
		$copy = $state;
		Walker::walk(
			$copy,
			function ( string $kind, int $ref ) use ( &$refs ): int {
				if ( 'user' === $kind ) {
					$this->user( $ref );
				} else {
					$refs[ Util::key( $kind, $ref ) ] = true;
				}

				return $ref;
			},
			array( $this, 'field' )
		);
		unset( $refs[ $state['key'] ] );
		$state['refs'] = array_keys( $refs );

		// Parents are imported before their children.
		$state['depth'] = match ( $type ) {
			'post' => count( get_post_ancestors( $id ) ),
			'term' => count( get_ancestors( $id, (string) $state['taxonomy'], 'taxonomy' ) ),
			default => 0,
		};

		if ( isset( $state['meta'] ) ) {
			list( $state['meta_groups'], $state['group_labels'] ) = $this->groups( $state['meta'] );
		}

		return $state;
	}

	/**
	 * Groups meta keys into diff sections: an ACF field and everything stored
	 * for it (sub fields, rows, the `_name` references) form one section; any
	 * other key is its own section.
	 *
	 * @param array<string, string[]> $meta Raw meta.
	 *
	 * @return array{0: array<string, string>, 1: array<string, string>} Group per key, label per group.
	 */
	private function groups( array $meta ): array {
		$groups = array();
		$labels = array();

		foreach ( $meta as $key => $values ) {
			$reference = $meta[ '_' . $key ][0] ?? null;
			if ( Acf::is_key( $reference ) ) {
				$description = $this->field( (string) $reference );
				if ( null !== $description && '' !== $description['top'] ) {
					$groups[ $key ]                = $description['top'];
					$groups[ '_' . $key ]          = $description['top'];
					$labels[ $description['top'] ] = '' !== $description['top_label'] ? $description['top_label'] : $description['top'];
					continue;
				}
			}

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ]   = $key;
				$labels[ $key ] ??= $key;
			}
		}

		return array( $groups, $labels );
	}

	/**
	 * Records a referenced user.
	 *
	 * @param int $id User ID.
	 *
	 * @return void
	 */
	private function user( int $id ): void {
		if ( isset( $this->users[ $id ] ) ) {
			return;
		}

		$user = get_userdata( $id );
		if ( ! $user ) {
			return;
		}

		$this->users[ $id ] = array(
			'login' => $user->user_login,
			'email' => $user->user_email,
			'name'  => $user->display_name,
		);
	}

	/**
	 * Returns a display title for an object.
	 *
	 * @param array<string, mixed> $state Object.
	 *
	 * @return string
	 */
	public static function title( array $state ): string {
		return match ( $state['type'] ) {
			'post' => '' !== (string) $state['fields']['post_title'] ? (string) $state['fields']['post_title'] : ( '' !== (string) $state['fields']['post_name'] ? (string) $state['fields']['post_name'] : '#' . $state['id'] ),
			'term', 'menu' => (string) $state['name'],
			default => '',
		};
	}
}

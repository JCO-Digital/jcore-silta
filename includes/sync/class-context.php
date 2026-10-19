<?php
/**
 * What the target knows while planning or importing a bundle.
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
 * Holds a bundle on the target and turns its objects into local terms: remote
 * IDs resolved to local ones, URLs and rules replaced.
 */
final class Context {

	/**
	 * Remote (source) site UUID.
	 *
	 * @var string
	 */
	public string $site;

	/**
	 * Source home and uploads URLs.
	 *
	 * @var array<string, string>
	 */
	public array $source;

	/**
	 * Objects in the bundle, by key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $objects = array();

	/**
	 * Validation errors for records omitted from the bundle.
	 *
	 * @var array<string, string>
	 */
	public array $errors = array();

	/**
	 * Options: status_mode, overrides, rules, job_refs.
	 *
	 * @var array<string, mixed>
	 */
	public array $options;

	/**
	 * Stubs of referenced objects, by key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stubs;

	/**
	 * Users referenced, by remote ID.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $users;

	/**
	 * ACF fields described by the source, by key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $acf_fields;

	/**
	 * The matcher.
	 *
	 * @var Matcher
	 */
	private Matcher $matcher;

	/**
	 * Base replacer: URLs and rules.
	 *
	 * @var Replacer
	 */
	private Replacer $replacer;

	/**
	 * Resolved IDs, by key; null when unresolved.
	 *
	 * @var array<string, int|null>
	 */
	private array $resolved = array();

	/**
	 * Remote post IDs in the bundle by post type and slug, built on first use.
	 *
	 * @var array<string, array<string, int>>|null
	 */
	private ?array $slugs = null;

	/**
	 * Creates a context.
	 *
	 * @param array<string, mixed> $bundle  Bundle from the source.
	 * @param array<string, mixed> $options Options.
	 * @param string               $site    Source site UUID, as verified by the transport.
	 */
	public function __construct( array $bundle, array $options, string $site ) {
		$this->site       = $site;
		$source           = is_array( $bundle['site'] ?? null ) ? $bundle['site'] : array();
		$this->source     = array(
			'home'    => is_string( $source['home'] ?? null ) ? $source['home'] : '',
			'uploads' => is_string( $source['uploads'] ?? null ) ? $source['uploads'] : '',
		);
		$this->options    = $options;
		$this->stubs      = array();
		$this->users      = array();
		$this->acf_fields = array();

		foreach ( (array) ( $bundle['users'] ?? array() ) as $id => $user ) {
			if ( ctype_digit( (string) $id ) && (int) $id > 0 && is_array( $user ) && is_string( $user['login'] ?? null ) && is_string( $user['email'] ?? null ) ) {
				$this->users[ (int) $id ] = array_intersect_key( $user, array_flip( array( 'login', 'email' ) ) );
			}
		}
		foreach ( (array) ( $bundle['acf_fields'] ?? array() ) as $key => $field ) {
			if ( ! Acf::is_key( $key ) || ! is_array( $field ) ) {
				continue;
			}
			foreach ( array( 'type', 'name', 'label', 'top', 'top_label' ) as $property ) {
				if ( isset( $field[ $property ] ) && ! is_string( $field[ $property ] ) ) {
					continue 2;
				}
			}
			$this->acf_fields[ $key ] = $field;
		}
		foreach ( (array) ( $bundle['stubs'] ?? array() ) as $key => $stub ) {
			if ( null === self::record_error( $stub, true ) && $key === $stub['key'] ) {
				$this->stubs[ $key ] = ! empty( $stub['missing'] ) ? array_intersect_key( $stub, array_flip( array( 'key', 'missing' ) ) ) : $stub;
			} else {
				$this->errors[ 'invalid:stub:' . $key ] = __( 'Malformed reference stub was omitted from the bundle.', 'jcore-silta' );
			}
		}
		$objects = $bundle['objects'] ?? null;
		if ( ! is_array( $objects ) || ! array_is_list( $objects ) ) {
			$this->errors['invalid:bundle'] = __( 'Bundle objects must be a list of records.', 'jcore-silta' );
			$objects                        = array();
		}
		foreach ( $objects as $index => $record ) {
			$error = self::record_error( $record );
			if ( null !== $error ) {
				$key = is_array( $record ) && is_string( $record['key'] ?? null ) && preg_match( '/^(post|term|menu|form):[1-9][0-9]*$/', $record['key'] ) ? $record['key'] : 'invalid:record:' . $index;
				if ( isset( $this->objects[ $key ] ) || isset( $this->errors[ $key ] ) ) {
					$key = 'invalid:record:' . $index;
				}
				$this->errors[ $key ] = $error;
				continue;
			}
			if ( isset( $this->objects[ $record['key'] ] ) || isset( $this->errors[ $record['key'] ] ) ) {
				$this->errors[ 'invalid:record:' . $index ] = __( 'Duplicate object key was omitted from the bundle.', 'jcore-silta' );
				continue;
			}
			$record['meta'] = array_map( static fn( $values ): array => (array) $values, $record['meta'] ?? array() );
			if ( 'menu' === $record['type'] ) {
				foreach ( $record['items'] as &$item ) {
					$item['meta'] = array_map( static fn( $values ): array => (array) $values, $item['meta'] ?? array() );
				}
				unset( $item );
			}
			$this->objects[ $record['key'] ] = $record;
		}

		$this->matcher  = new Matcher( $site, $this->source['home'] );
		$this->replacer = Replacer::between(
			$this->source,
			array(
				'home'    => Site::home(),
				'uploads' => Site::uploads_url(),
			),
			(array) ( $options['rules'] ?? array() )
		);
	}

	/**
	 * Validates the fields consumed by matching, reference walkers and import.
	 *
	 * @param mixed $record Incoming record.
	 * @param bool  $stub   Whether this is a reference stub.
	 *
	 * @return string|null Error, or null when the structure is valid.
	 */
	public static function record_error( mixed $record, bool $stub = false ): ?string {
		$error = __( 'Malformed bundle record: invalid field types or object key.', 'jcore-silta' );
		if ( ! is_array( $record ) || ! is_string( $record['key'] ?? null ) || ! preg_match( '/^(post|term|menu|form):[1-9][0-9]*$/', $record['key'] ) ) {
			return $error;
		}
		if ( $stub && true === ( $record['missing'] ?? false ) ) {
			return null;
		}
		if ( ! in_array( $record['type'] ?? null, array( 'post', 'term', 'menu', 'form' ), true ) || ! is_int( $record['id'] ?? null ) || $record['id'] <= 0 || $record['key'] !== Util::key( $record['type'], $record['id'] ) ) {
			return $error;
		}
		$required = match ( $record['type'] ) {
			'post' => array( 'post_type' ),
			'term' => array( 'taxonomy', 'slug', 'name' ),
			'menu' => array( 'slug', 'name' ),
			'form' => array( 'name' ),
		};
		if ( ! $stub ) {
			$required[] = 'hash';
			$required[] = 'post' === $record['type'] ? 'content' : ( 'term' === $record['type'] ? 'description' : 'name' );
		}
		foreach ( array_unique( array_merge( $required, array( 'title', 'guid', 'modified_gmt', 'path', 'post_name', 'status' ) ) ) as $key ) {
			if ( ( in_array( $key, $required, true ) || array_key_exists( $key, $record ) ) && ! is_string( $record[ $key ] ?? null ) ) {
				return $error;
			}
		}
		foreach ( array( 'parent', 'depth', 'featured_image' ) as $key ) {
			if ( isset( $record[ $key ] ) && ( ! is_int( $record[ $key ] ) || $record[ $key ] < 0 ) ) {
				return $error;
			}
		}
		foreach ( array( 'meta', 'meta_groups', 'group_labels', 'terms', 'fields', 'file', 'settings', 'confirmations', 'notifications' ) as $key ) {
			if ( isset( $record[ $key ] ) && ! is_array( $record[ $key ] ) ) {
				return $error;
			}
		}
		if ( ! self::valid_meta( $record['meta'] ?? array() ) ) {
			return $error;
		}
		foreach ( array( 'meta_groups', 'group_labels' ) as $map ) {
			foreach ( $record[ $map ] ?? array() as $key => $value ) {
				if ( ! is_string( $key ) || ! is_string( $value ) ) {
					return $error;
				}
			}
		}
		if ( isset( $record['origins'] ) ) {
			if ( ! is_array( $record['origins'] ) || ! array_is_list( $record['origins'] ) ) {
				return $error;
			}
			foreach ( $record['origins'] as $origin ) {
				if ( ! is_array( $origin ) || ! is_string( $origin['site'] ?? null ) || ! is_int( $origin['id'] ?? null ) || $origin['id'] <= 0 ) {
					return $error;
				}
			}
		}
		if ( isset( $record['refs'] ) ) {
			if ( ! is_array( $record['refs'] ) || ! array_is_list( $record['refs'] ) ) {
				return $error;
			}
			foreach ( $record['refs'] as $ref ) {
				if ( ! is_string( $ref ) || ! preg_match( '/^(post|term|form):[1-9][0-9]*$/', $ref ) ) {
					return $error;
				}
			}
		}
		if ( isset( $record['language'] ) ) {
			$language = $record['language'];
			if ( ! is_array( $language ) || ! is_string( $language['lang'] ?? null ) || ! is_array( $language['translations'] ?? null ) ) {
				return $error;
			}
			foreach ( $language['translations'] as $lang => $id ) {
				if ( ! is_string( $lang ) || ! is_int( $id ) || $id <= 0 ) {
					return $error;
				}
			}
		}
		foreach ( $record['terms'] ?? array() as $taxonomy => $ids ) {
			if ( ! is_string( $taxonomy ) || ! is_array( $ids ) || ! array_is_list( $ids ) ) {
				return $error;
			}
			foreach ( $ids as $id ) {
				if ( ! is_int( $id ) || $id <= 0 ) {
					return $error;
				}
			}
		}
		if ( 'post' === $record['type'] ) {
			$fields = $record['fields'] ?? array();
			foreach ( array_merge( State::FIELDS, array( 'post_date_gmt', 'post_mime_type' ) ) as $key ) {
				if ( $stub || ( ! array_key_exists( $key, $fields ) && in_array( $key, array( 'page_template', 'post_format', 'sticky', 'post_mime_type' ), true ) ) ) {
					continue;
				}
				$value = $fields[ $key ] ?? null;
				$valid = match ( $key ) {
					'post_author', 'post_parent' => is_int( $value ) && $value >= 0,
					'menu_order' => is_int( $value ),
					'sticky' => is_bool( $value ),
					default => is_string( $value ),
				};
				if ( ! $valid ) {
					return $error;
				}
			}
		}
		if ( 'form' === $record['type'] && ! $stub ) {
			if ( ! is_bool( $record['active'] ?? null ) || ! is_array( $record['settings'] ?? null ) || ! is_array( $record['fields'] ?? null ) || ! array_is_list( $record['fields'] ) || ! is_array( $record['confirmations'] ?? null ) || ! is_array( $record['notifications'] ?? null ) ) {
				return $error;
			}
			foreach ( array( 'fields', 'confirmations', 'notifications' ) as $list ) {
				foreach ( $record[ $list ] as $item ) {
					if ( ! is_array( $item ) ) {
						return $error;
					}
				}
			}
			foreach ( $record['confirmations'] as $confirmation ) {
				if ( isset( $confirmation['pageId'] ) && ! is_int( $confirmation['pageId'] ) && ! is_string( $confirmation['pageId'] ) ) {
					return $error;
				}
			}
		}
		if ( 'menu' === $record['type'] && ! $stub ) {
			if ( ! is_array( $record['items'] ?? null ) || ! array_is_list( $record['items'] ) || ! is_array( $record['locations'] ?? null ) || ! array_is_list( $record['locations'] ) ) {
				return $error;
			}
			foreach ( $record['locations'] as $location ) {
				if ( ! is_string( $location ) ) {
					return $error;
				}
			}
			$ids = array();
			foreach ( $record['items'] as $item ) {
				if ( ! is_array( $item ) || ! self::valid_meta( $item['meta'] ?? array() ) ) {
					return $error;
				}
				foreach ( array( 'id', 'object_id', 'parent', 'position' ) as $key ) {
					if ( ! is_int( $item[ $key ] ?? null ) || $item[ $key ] < ( 'id' === $key ? 1 : 0 ) ) {
						return $error;
					}
				}
				if ( isset( $ids[ $item['id'] ] ) ) {
					return $error;
				}
				$ids[ $item['id'] ] = true;
				foreach ( array( 'title', 'item_type', 'object', 'url', 'target', 'attr_title', 'description', 'classes', 'xfn', 'status' ) as $key ) {
					if ( ! is_string( $item[ $key ] ?? null ) ) {
						return $error;
					}
				}
				if ( ! in_array( $item['item_type'], array( 'custom', 'post_type', 'taxonomy', 'post_type_archive' ), true ) ) {
					return $error;
				}
			}
		}

		return null;
	}

	/**
	 * Checks a meta map's keys and raw string rows.
	 *
	 * @param mixed $meta Meta map.
	 *
	 * @return bool
	 */
	private static function valid_meta( mixed $meta ): bool {
		if ( ! is_array( $meta ) ) {
			return false;
		}
		foreach ( $meta as $key => $values ) {
			if ( ! is_string( $key ) || '' === $key || str_contains( $key, "\0" ) ) {
				return false;
			}
			if ( is_string( $values ) ) {
				continue;
			}
			if ( ! is_array( $values ) || ! array_is_list( $values ) ) {
				return false;
			}
			foreach ( $values as $value ) {
				if ( ! is_string( $value ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Finds the local copy of an object, honouring a manual override.
	 *
	 * @param array<string, mixed> $record Object.
	 *
	 * @return array{id: int, method: string}|null
	 */
	public function match( array $record ): ?array {
		if ( ( 'post' === $record['type'] && ! Content::syncable_type( $record['post_type'] ) ) || ( 'term' === $record['type'] && ! Content::syncable_taxonomy( $record['taxonomy'] ) ) || ( 'form' === $record['type'] && ! Gravity_Forms::active() ) ) {
			return null;
		}
		$override = $this->options['overrides'][ $record['key'] ] ?? null;
		if ( 'create' === $override ) {
			return null;
		}
		if ( is_numeric( $override ) && (int) $override > 0 && $this->override_ok( $record, (int) $override ) ) {
			return array(
				'id'     => (int) $override,
				'method' => 'manual',
			);
		}

		$match = $this->matcher->match( $record );

		return null !== $match && $this->override_ok( $record, $match['id'] ) ? $match : null;
	}

	/**
	 * Resolves a remote ID to a local one.
	 *
	 * @param string $kind post, term, form or user.
	 * @param int    $id   Remote ID.
	 *
	 * @return int|null
	 */
	public function resolve( string $kind, int $id ): ?int {
		if ( $id <= 0 ) {
			return 0;
		}

		if ( 'user' === $kind ) {
			return $this->user( $id );
		}

		$key = Util::key( $kind, $id );
		if ( array_key_exists( $key, $this->resolved ) ) {
			return $this->resolved[ $key ];
		}

		$row = Mapping::get( $this->site, $kind, $id );
		if ( null !== $row && $this->exists( $kind, (int) $row['local_id'] ) ) {
			$this->resolved[ $key ] = (int) $row['local_id'];

			return $this->resolved[ $key ];
		}

		$info = $this->objects[ $key ] ?? $this->stubs[ $key ] ?? null;
		if ( is_array( $info ) && empty( $info['missing'] ) ) {
			if ( ( 'post' === $info['type'] && ! Content::syncable_type( $info['post_type'] ) ) || ( 'term' === $info['type'] && ! Content::syncable_taxonomy( $info['taxonomy'] ) ) ) {
				$this->resolved[ $key ] = null;

				return null;
			}
			$match = isset( $this->objects[ $key ] ) ? $this->match( $info ) : $this->matcher->match( $info );
			if ( null !== $match && $this->override_ok( $info, $match['id'] ) ) {
				$this->resolved[ $key ] = $match['id'];

				return $match['id'];
			}
		}

		$this->resolved[ $key ] = null;

		return null;
	}

	/**
	 * Records the local ID of an object once it has been written, so later
	 * references to it resolve.
	 *
	 * @param string $key      Object key.
	 * @param int    $local_id Local ID.
	 *
	 * @return void
	 */
	public function settle( string $key, int $local_id ): void {
		$this->resolved[ $key ] = $local_id;
	}

	/**
	 * Describes an ACF field: from the bundle, else from this site.
	 *
	 * @param string $key Field key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function field( string $key ): ?array {
		return $this->acf_fields[ $key ] ?? Acf::describe( $key );
	}

	/**
	 * Finds the remote post a slug in block attributes names, among the posts
	 * and stubs of the bundle: the source resolved the slug when it exported
	 * the post, so the post it names travels as a reference.
	 *
	 * @param string $post_type Post type.
	 * @param string $slug      Slug, as stored in the attribute.
	 *
	 * @return int|null Remote post ID.
	 */
	public function slug( string $post_type, string $slug ): ?int {
		if ( null === $this->slugs ) {
			$this->slugs = array();
			foreach ( array_merge( array_values( $this->stubs ), array_values( $this->objects ) ) as $info ) {
				if ( 'post' !== ( $info['type'] ?? '' ) || ! empty( $info['missing'] ) ) {
					continue;
				}
				$name = $info['fields']['post_name'] ?? $info['post_name'] ?? '';
				if ( is_string( $name ) && '' !== $name && is_string( $info['post_type'] ?? null ) ) {
					$this->slugs[ $info['post_type'] ][ $name ] = (int) $info['id'];
				}
			}
		}

		return $this->slugs[ $post_type ][ sanitize_title( $slug ) ] ?? null;
	}

	/**
	 * Returns a stub or object by key, for display.
	 *
	 * @param string $key Key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function info( string $key ): ?array {
		return $this->objects[ $key ] ?? $this->stubs[ $key ] ?? null;
	}

	/**
	 * Turns an incoming object into local terms: IDs resolved, URLs and rules
	 * replaced. Unresolved references are listed under `unresolved`.
	 *
	 * @param array<string, mixed> $record Object as exported.
	 *
	 * @return array<string, mixed>
	 */
	public function transform( array $record ): array {
		$unresolved = array();
		$self       = (string) $record['key'];

		// While planning, references to objects the job will create keep their
		// source IDs, so the diff doesn't show them as removed.
		$pending = ! empty( $this->options['preview'] ) ? array_flip( (array) ( $this->options['job_refs'] ?? array() ) ) : array();

		Walker::walk(
			$record,
			function ( string $kind, int $id ) use ( &$unresolved, $self, $pending ): ?int {
				$local = $this->resolve( $kind, $id );
				$key   = Util::key( $kind, $id );
				if ( null === $local && 'user' !== $kind && $key !== $self ) {
					$unresolved[ $key ] = true;
					// A form embed without its ID can't render anything, so an
					// unresolved form keeps the source ID, and is reported.
					if ( isset( $pending[ $key ] ) || 'form' === $kind ) {
						return $id;
					}
				}

				return $local;
			},
			array( $this, 'field' ),
			array( $this, 'slug' )
		);

		$record               = $this->replace( $record, $this->replacer->with( $this->media_pairs( $record ) ) );
		$record['unresolved'] = array_keys( $unresolved );

		return $record;
	}

	/**
	 * Returns the status an incoming post should get.
	 *
	 * @param array<string, mixed>      $incoming Incoming post.
	 * @param array<string, mixed>|null $local    Local copy, if any.
	 *
	 * @return string
	 */
	public function status( array $incoming, ?array $local ): string {
		$status = (string) $incoming['fields']['post_status'];
		if ( in_array( $status, array( 'inherit', 'auto-draft' ), true ) ) {
			return $status;
		}

		return match ( (string) ( $this->options['status_mode'] ?? 'source' ) ) {
			'draft' => 'draft',
			'keep' => null !== $local ? (string) $local['fields']['post_status'] : $status,
			default => $status,
		};
	}

	/**
	 * Says why writing over a local object would lose changes made there, if
	 * it would: `modified` (changed since the last sync) or `newer` (never
	 * synced, but edited after the source).
	 *
	 * @param array<string, mixed> $record   Incoming object.
	 * @param int                  $local_id Local ID.
	 * @param array<string, mixed> $local    Local state.
	 *
	 * @return string|null
	 */
	public function conflict( array $record, int $local_id, array $local ): ?string {
		$row = Mapping::get( $this->site, Mapping::type_for( (string) $record['type'] ), (int) $record['id'] );

		if ( null !== $row && (int) $row['local_id'] === $local_id && '' !== $row['local_hash'] ) {
			return $row['local_hash'] !== State::hash( $local ) ? 'modified' : null;
		}

		$theirs = (string) ( $record['modified_gmt'] ?? '' );
		$ours   = (string) ( $local['modified_gmt'] ?? '' );
		if ( '' !== $theirs && '' !== $ours && $ours > $theirs ) {
			return 'newer';
		}

		return null;
	}

	/**
	 * Whether a manual override points at a sensible local object.
	 *
	 * @param array<string, mixed> $record Object.
	 * @param int                  $id     Local ID.
	 *
	 * @return bool
	 */
	private function override_ok( array $record, int $id ): bool {
		if ( 'post' === $record['type'] ) {
			$post = get_post( $id );

			return $post instanceof \WP_Post && $post->post_type === $record['post_type'] && Content::syncable_type( $post->post_type ) && Content::syncable_status( $post->post_type, $post->post_status );
		}
		if ( 'form' === $record['type'] ) {
			return Gravity_Forms::exists( $id );
		}

		$term = get_term( $id );

		return $term instanceof \WP_Term && $term->taxonomy === ( 'menu' === $record['type'] ? 'nav_menu' : $record['taxonomy'] );
	}

	/**
	 * Resolves a user by login, then email.
	 *
	 * @param int $id Remote user ID.
	 *
	 * @return int|null
	 */
	private function user( int $id ): ?int {
		$user = $this->users[ $id ] ?? null;
		if ( null === $user ) {
			return null;
		}

		$local = get_user_by( 'login', (string) ( $user['login'] ?? '' ) );
		if ( ! $local && ! empty( $user['email'] ) ) {
			$local = get_user_by( 'email', (string) $user['email'] );
		}

		return $local ? (int) $local->ID : null;
	}

	/**
	 * Whether a local object exists.
	 *
	 * @param string $kind post, term or form.
	 * @param int    $id   Local ID.
	 *
	 * @return bool
	 */
	private function exists( string $kind, int $id ): bool {
		if ( 'form' === $kind ) {
			return Gravity_Forms::exists( $id );
		}
		if ( 'term' === $kind ) {
			$term = get_term( $id );

			return $term instanceof \WP_Term && Content::syncable_taxonomy( $term->taxonomy );
		}

		$post = get_post( $id );

		return $post instanceof \WP_Post && Content::syncable_type( $post->post_type ) && Content::syncable_status( $post->post_type, $post->post_status );
	}

	/**
	 * Builds pairs rewriting the URLs of referenced media whose files got
	 * another name here.
	 *
	 * @param array<string, mixed> $record Object.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function media_pairs( array $record ): array {
		$from_base = untrailingslashit( $this->source['uploads'] );
		$to_base   = Site::uploads_url();
		if ( '' === $from_base ) {
			return array();
		}

		$pairs = array();
		foreach ( (array) ( $record['refs'] ?? array() ) as $ref ) {
			if ( ! str_starts_with( (string) $ref, 'post:' ) ) {
				continue;
			}
			list( , $id ) = Util::split_key( (string) $ref );
			$row          = Mapping::get( $this->site, 'post', $id );
			foreach ( (array) ( $row['extra']['files'] ?? array() ) as $source => $target ) {
				if ( $source !== $target ) {
					$pairs = array_merge( $pairs, Replacer::url_variants( $from_base . '/' . $source, $to_base . '/' . $target ) );
				}
			}
		}

		return $pairs;
	}

	/**
	 * Applies the replacer to an object's text.
	 *
	 * @param array<string, mixed> $record   Object.
	 * @param Replacer             $replacer Replacer.
	 *
	 * @return array<string, mixed>
	 */
	private function replace( array $record, Replacer $replacer ): array {
		if ( $replacer->is_empty() ) {
			return $record;
		}

		$text = static function ( $value ) use ( $replacer ) {
			if ( ! is_string( $value ) ) {
				return $value;
			}
			try {
				return $replacer->string( $value );
			} catch ( \UnexpectedValueException $e ) {
				// Keep unsafe raw meta intact so Meta_Writer skips the key and reports it.
				return $value;
			}
		};

		if ( 'post' === $record['type'] ) {
			foreach ( array( 'post_title', 'post_excerpt' ) as $field ) {
				$record['fields'][ $field ] = $text( $record['fields'][ $field ] ?? '' );
			}
			$record['content'] = $text( $record['content'] ?? '' );
		}

		if ( 'term' === $record['type'] ) {
			$record['description'] = $text( $record['description'] ?? '' );
		}

		if ( 'menu' === $record['type'] ) {
			foreach ( (array) $record['items'] as $index => $item ) {
				foreach ( array( 'title', 'url', 'description', 'attr_title' ) as $field ) {
					$record['items'][ $index ][ $field ] = $text( $item[ $field ] ?? '' );
				}
				foreach ( (array) ( $item['meta'] ?? array() ) as $key => $values ) {
					$record['items'][ $index ]['meta'][ $key ] = array_map( $text, (array) $values );
				}
			}
		}

		if ( 'form' === $record['type'] ) {
			$record = self::replace_form( $record, $text );
		}

		foreach ( (array) ( $record['meta'] ?? array() ) as $key => $values ) {
			$record['meta'][ $key ] = array_map( $text, (array) $values );
		}

		return $record;
	}

	/**
	 * Applies the replacer to every text of a form: settings, fields (HTML
	 * content, labels, defaults, choices), confirmations and notifications.
	 *
	 * @param array<string, mixed> $record Form.
	 * @param callable             $text   Replaces one string.
	 *
	 * @return array<string, mixed>
	 */
	public static function replace_form( array $record, callable $text ): array {
		$walk = static function ( mixed $value, int $depth = 0 ) use ( &$walk, $text ): mixed {
			if ( is_string( $value ) ) {
				return $text( $value );
			}
			if ( ! is_array( $value ) || $depth > 32 ) {
				return $value;
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $walk( $item, $depth + 1 );
			}

			return $value;
		};

		$record['name'] = $text( (string) ( $record['name'] ?? '' ) );
		foreach ( array( 'settings', 'fields', 'confirmations', 'notifications' ) as $part ) {
			if ( isset( $record[ $part ] ) && is_array( $record[ $part ] ) ) {
				$record[ $part ] = $walk( $record[ $part ] );
			}
		}

		return $record;
	}
}

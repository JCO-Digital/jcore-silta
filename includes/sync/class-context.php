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
	 * Creates a context.
	 *
	 * @param array<string, mixed> $bundle  Bundle from the source.
	 * @param array<string, mixed> $options Options.
	 * @param string               $site    Source site UUID, as verified by the transport.
	 */
	public function __construct( array $bundle, array $options, string $site ) {
		$this->site       = $site;
		$this->source     = array(
			'home'    => (string) ( $bundle['site']['home'] ?? '' ),
			'uploads' => (string) ( $bundle['site']['uploads'] ?? '' ),
		);
		$this->options    = $options;
		$this->stubs      = (array) ( $bundle['stubs'] ?? array() );
		$this->users      = array();
		$this->acf_fields = (array) ( $bundle['acf_fields'] ?? array() );

		foreach ( (array) ( $bundle['users'] ?? array() ) as $id => $user ) {
			$this->users[ (int) $id ] = (array) $user;
		}
		foreach ( (array) ( $bundle['objects'] ?? array() ) as $record ) {
			if ( is_array( $record ) && ! empty( $record['key'] ) ) {
				$this->objects[ (string) $record['key'] ] = $record;
			}
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
	 * Finds the local copy of an object, honouring a manual override.
	 *
	 * @param array<string, mixed> $record Object.
	 *
	 * @return array{id: int, method: string}|null
	 */
	public function match( array $record ): ?array {
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

		return $this->matcher->match( $record );
	}

	/**
	 * Resolves a remote ID to a local one.
	 *
	 * @param string $kind post, term or user.
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
			$match = isset( $this->objects[ $key ] ) ? $this->match( $info ) : $this->matcher->match( $info );
			if ( null !== $match ) {
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
					if ( isset( $pending[ $key ] ) ) {
						return $id;
					}
				}

				return $local;
			},
			array( $this, 'field' )
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
		$row = Mapping::get( $this->site, 'post' === $record['type'] ? 'post' : 'term', (int) $record['id'] );

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

			return $post instanceof \WP_Post && $post->post_type === $record['post_type'];
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
	 * @param string $kind post or term.
	 * @param int    $id   Local ID.
	 *
	 * @return bool
	 */
	private function exists( string $kind, int $id ): bool {
		if ( 'term' === $kind ) {
			return get_term( $id ) instanceof \WP_Term;
		}

		$post = get_post( $id );

		return $post instanceof \WP_Post && 'trash' !== $post->post_status;
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

		$text = static fn( $value ) => is_string( $value ) ? $replacer->string( $value ) : $value;

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

		foreach ( (array) ( $record['meta'] ?? array() ) as $key => $values ) {
			$record['meta'][ $key ] = array_map( $text, (array) $values );
		}

		return $record;
	}
}

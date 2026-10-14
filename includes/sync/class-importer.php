<?php
/**
 * Writes a bundle on the target.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

use Jcore\Silta\Capabilities;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and updates posts, attachments, terms and menus from a bundle,
 * snapshotting everything it changes and recording the mappings.
 */
final class Importer {

	/**
	 * Post columns written through wp_insert_post() / wp_update_post().
	 */
	private const COLUMNS = array( 'post_title', 'post_name', 'post_excerpt', 'post_status', 'post_parent', 'menu_order', 'comment_status', 'ping_status', 'post_password' );

	/**
	 * The bundle and its options.
	 *
	 * @var Context
	 */
	private Context $ctx;

	/**
	 * Job UUID the changes are recorded under.
	 *
	 * @var string
	 */
	private string $job;

	/**
	 * Creates an importer.
	 *
	 * @param Context $ctx Context.
	 * @param string  $job Job UUID.
	 */
	public function __construct( Context $ctx, string $job ) {
		$this->ctx = $ctx;
		$this->job = $job;
	}

	/**
	 * Imports every object in the bundle, in bundle order, then trashes the
	 * listed orphans.
	 *
	 * @param array<string, mixed> $options selections (key => section list or null),
	 *                                      confirmed (key => bool), transfers (key => transfer ID),
	 *                                      trash (local post IDs).
	 *
	 * @return array<string, array<string, mixed>> Result per key.
	 */
	public function run( array $options ): array {
		$kses    = self::kses_off();
		$results = array();
		foreach ( $this->ctx->errors as $key => $error ) {
			$results[ $key ] = self::failed( $error );
		}

		foreach ( $this->ctx->objects as $key => $record ) {
			try {
				$results[ $key ] = match ( $record['type'] ) {
					'post' => $this->post( $record, $options ),
					'term' => $this->term( $record ),
					'menu' => $this->menu( $record, $options ),
					default => self::failed( __( 'Unknown object type.', 'jcore-silta' ) ),
				};
			} catch ( \Throwable $e ) {
				$results[ $key ] = self::failed( $e->getMessage() );
			}
		}

		foreach ( (array) ( $options['trash'] ?? array() ) as $id ) {
			$results[ 'orphan:' . (int) $id ] = $this->trash( (int) $id );
		}

		if ( $kses ) {
			kses_init_filters();
		}

		return $results;
	}

	/**
	 * Switches off HTML filtering only when the acting user may save unfiltered HTML.
	 *
	 * @return bool Whether the filters were on, so they can be restored.
	 */
	public static function kses_off(): bool {
		if ( ! Capabilities::unfiltered_html() ) {
			kses_init_filters();

			return false;
		}
		$active = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $active ) {
			kses_remove_filters();
		}

		return $active;
	}

	/**
	 * Writes the selected sections of a post state onto an existing post.
	 *
	 * @param int                       $id       Local post ID.
	 * @param array<string, mixed>      $incoming State to write, with local IDs.
	 * @param array<string, mixed>|null $local    Current local state, or null right after creating.
	 * @param callable                  $wants    Callback( string $section ): bool.
	 * @param bool                      $restore  Whether this restores a snapshot (also restores the file).
	 * @param string[]                  $warnings Warnings for rejected meta values.
	 *
	 * @return true|WP_Error
	 */
	public static function write_post( int $id, array $incoming, ?array $local, callable $wants, bool $restore = false, array &$warnings = array() ): bool|WP_Error {
		if ( ! Content::syncable_type( (string) $incoming['post_type'] ) ) {
			return new WP_Error( 'jcore_silta_post_type', __( 'This post type cannot be synced.', 'jcore-silta' ), array( 'status' => 400 ) );
		}
		$fields     = (array) $incoming['fields'];
		$attachment = 'attachment' === $incoming['post_type'];
		$postarr    = array( 'ID' => $id );

		foreach ( self::COLUMNS as $column ) {
			if ( array_key_exists( $column, $fields ) && $wants( 'fields.' . $column ) ) {
				$postarr[ $column ] = $fields[ $column ];
			}
		}
		if ( $attachment ) {
			unset( $postarr['post_status'] );
		}
		if ( (int) ( $fields['post_author'] ?? 0 ) > 0 && $wants( 'fields.post_author' ) ) {
			$postarr['post_author'] = (int) $fields['post_author'];
		}
		if ( $wants( 'fields.post_date' ) ) {
			$postarr['post_date']     = $fields['post_date'];
			$postarr['post_date_gmt'] = $fields['post_date_gmt'];
			$postarr['edit_date']     = true;
		}
		if ( $wants( 'content' ) ) {
			$postarr['post_content'] = (string) $incoming['content'];
		}

		if ( count( $postarr ) > 1 ) {
			$updated = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		if ( $restore && $attachment && $wants( 'file' ) && ! empty( $incoming['file']['path'] ) ) {
			update_attached_file( $id, Media::basedir() . '/' . $incoming['file']['path'] );
			wp_update_attachment_metadata( $id, (array) $incoming['file']['metadata'] );
		}

		if ( ! $attachment ) {
			if ( $wants( 'fields.page_template' ) ) {
				$template = (string) ( $fields['page_template'] ?? '' );
				$warnings = array_merge( $warnings, Meta_Writer::replace( 'post', $id, array( '_wp_page_template' ), '' === $template ? array() : array( '_wp_page_template' => array( $template ) ), true ) );
			}
			if ( $wants( 'fields.post_format' ) && post_type_supports( (string) $incoming['post_type'], 'post-formats' ) ) {
				set_post_format( $id, (string) ( $fields['post_format'] ?? '' ) );
			}
			if ( 'post' === $incoming['post_type'] && $wants( 'fields.sticky' ) ) {
				if ( ! empty( $fields['sticky'] ) ) {
					stick_post( $id );
				} else {
					unstick_post( $id );
				}
			}
			if ( $wants( 'featured_image' ) ) {
				$thumbnail = (int) ( $incoming['featured_image'] ?? 0 );
				Meta_Writer::replace( 'post', $id, array( '_thumbnail_id' ), $thumbnail > 0 ? array( '_thumbnail_id' => array( (string) $thumbnail ) ) : array(), true );
			}
		}

		$known  = (array) ( $incoming['meta_groups'] ?? array() );
		$theirs = Differ::group_meta( (array) ( $incoming['meta'] ?? array() ), $known );
		$ours   = null === $local ? array() : Differ::group_meta( (array) ( $local['meta'] ?? array() ), $known );
		foreach ( array_keys( $theirs + $ours ) as $group ) {
			if ( ! $wants( 'meta.' . $group ) ) {
				continue;
			}
			$keys     = array_merge( array_keys( $theirs[ $group ] ?? array() ), array_keys( $ours[ $group ] ?? array() ) );
			$warnings = array_merge( $warnings, Meta_Writer::replace( 'post', $id, $keys, $theirs[ $group ] ?? array() ) );
		}

		foreach ( (array) ( $incoming['terms'] ?? array() ) as $taxonomy => $ids ) {
			if ( Content::syncable_taxonomy( (string) $taxonomy ) && is_object_in_taxonomy( $incoming['post_type'], (string) $taxonomy ) && $wants( 'terms.' . $taxonomy ) ) {
				wp_set_object_terms( $id, array_map( 'intval', (array) $ids ), (string) $taxonomy );
			}
		}

		if ( ! empty( $incoming['language']['lang'] ) && $wants( 'language' ) ) {
			Polylang::set_post( $id, $incoming['language'] );
		}

		clean_post_cache( $id );

		return true;
	}

	/**
	 * Imports a post or attachment.
	 *
	 * @param array<string, mixed> $record  Incoming post.
	 * @param array<string, mixed> $options Run options.
	 *
	 * @return array<string, mixed>
	 */
	private function post( array $record, array $options ): array {
		$key    = (string) $record['key'];
		$errors = Preflight::check( $record );
		if ( isset( $errors[''] ) ) {
			return self::failed( $errors[''] );
		}

		$match    = $this->ctx->match( $record );
		$local_id = (int) ( $match['id'] ?? 0 );
		$local    = $local_id > 0 ? State::post( $local_id ) : null;
		if ( null === $local ) {
			$local_id = 0;
		}

		$incoming                          = $this->ctx->transform( $record );
		$incoming['fields']['post_status'] = $this->ctx->status( $incoming, $local );
		$warnings                          = array_values( $errors );
		$wants                             = self::wants( $options['selections'][ $key ] ?? null, $errors );
		$attachment                        = 'attachment' === $record['post_type'];
		$extra                             = null;

		if ( null !== $local ) {
			$changed = array();
			foreach ( Differ::sections( $incoming, $local ) as $section => $info ) {
				if ( $info['changed'] && $wants( $section ) ) {
					$changed[ $section ] = true;
				}
			}

			if ( empty( $changed ) ) {
				$this->ctx->settle( $key, $local_id );
				Mapping::set( $this->ctx->site, 'post', (int) $record['id'], $local_id, (string) $record['hash'], State::hash( $local ) );

				return array(
					'status'     => 'imported',
					'action'     => 'unchanged',
					'local_id'   => $local_id,
					'unresolved' => $incoming['unresolved'],
					'warnings'   => $warnings,
				);
			}

			// Checked after the comparison, as the plan does: identical content is
			// never a conflict.
			$conflict = $this->ctx->conflict( $record, $local_id, $local );
			if ( null !== $conflict && empty( $options['confirmed'][ $key ] ) ) {
				$this->ctx->settle( $key, $local_id );

				return array(
					'status'   => 'skipped',
					'reason'   => 'conflict',
					'conflict' => $conflict,
					'local_id' => $local_id,
				);
			}

			Snapshots::updated(
				$this->job,
				'post',
				$local_id,
				array(
					'state'     => $local,
					'site'      => $this->ctx->site,
					'remote_id' => (int) $record['id'],
					'mapping'   => Mapping::get( $this->ctx->site, 'post', (int) $record['id'] ),
				)
			);

			if ( $attachment && isset( $changed['file'] ) ) {
				$extra = $this->replace_file( $local_id, $incoming, is_string( $options['transfers'][ $key ] ?? null ) ? $options['transfers'][ $key ] : '', $record );
				if ( is_wp_error( $extra ) ) {
					return self::failed( $extra->get_error_message() ) + array( 'resend' => Media::resend( $extra ) );
				}
			}

			$written = self::write_post( $local_id, $incoming, $local, static fn( string $section ): bool => isset( $changed[ $section ] ), false, $warnings );
			if ( is_wp_error( $written ) ) {
				return self::failed( $written->get_error_message() );
			}
			$action = 'updated';
		} else {
			if ( $attachment ) {
				$created = $this->create_attachment( $incoming, is_string( $options['transfers'][ $key ] ?? null ) ? $options['transfers'][ $key ] : '', $record );
				if ( is_wp_error( $created ) ) {
					return self::failed( $created->get_error_message() ) + array( 'resend' => Media::resend( $created ) );
				}
				list( $local_id, $extra ) = $created;
			} else {
				$created = $this->create_post( $incoming );
				if ( is_wp_error( $created ) ) {
					return self::failed( $created->get_error_message() );
				}
				$local_id = $created;
			}

			Snapshots::created(
				$this->job,
				'post',
				$local_id,
				array(
					'site'      => $this->ctx->site,
					'remote_id' => (int) $record['id'],
				)
			);

			// Columns and content went in with the insert; write everything else.
			$rest = self::wants( null, $errors );
			self::write_post(
				$local_id,
				$incoming,
				null,
				static function ( string $section ) use ( $rest ): bool {
					if ( 'content' === $section || 'fields.post_author' === $section || 'fields.post_date' === $section ) {
						return false;
					}
					if ( str_starts_with( $section, 'fields.' ) && in_array( substr( $section, 7 ), self::COLUMNS, true ) ) {
						return false;
					}

					return $rest( $section );
				},
				false,
				$warnings
			);
			$action = 'created';
		}

		$this->ctx->settle( $key, $local_id );
		$after = State::post( $local_id );
		Mapping::set( $this->ctx->site, 'post', (int) $record['id'], $local_id, (string) $record['hash'], null === $after ? '' : State::hash( $after ), $extra );

		return array(
			'status'     => 'imported',
			'action'     => $action,
			'local_id'   => $local_id,
			'unresolved' => $incoming['unresolved'],
			'warnings'   => $warnings,
		);
	}

	/**
	 * Inserts a new post with its columns and content.
	 *
	 * @param array<string, mixed> $incoming Incoming post.
	 *
	 * @return int|WP_Error
	 */
	private function create_post( array $incoming ): int|WP_Error {
		$fields  = (array) $incoming['fields'];
		$postarr = array(
			'post_type'    => (string) $incoming['post_type'],
			'post_content' => (string) $incoming['content'],
			'post_author'  => (int) $fields['post_author'] > 0 ? (int) $fields['post_author'] : get_current_user_id(),
			'post_date'    => (string) $fields['post_date'],
			'edit_date'    => true,
		);
		if ( '0000-00-00 00:00:00' !== (string) $fields['post_date_gmt'] ) {
			$postarr['post_date_gmt'] = (string) $fields['post_date_gmt'];
		}
		foreach ( self::COLUMNS as $column ) {
			if ( array_key_exists( $column, $fields ) ) {
				$postarr[ $column ] = $fields[ $column ];
			}
		}

		$id = wp_insert_post( wp_slash( $postarr ), true );

		return is_wp_error( $id ) ? $id : (int) $id;
	}

	/**
	 * Creates an attachment from a finished file transfer.
	 *
	 * @param array<string, mixed> $incoming Incoming attachment.
	 * @param string               $transfer Transfer ID.
	 * @param array<string, mixed> $record   Original attachment before transformation.
	 *
	 * @return array{0: int, 1: array<string, mixed>}|WP_Error ID and mapping extra.
	 */
	private function create_attachment( array $incoming, string $transfer, array $record ): array|WP_Error {
		if ( '' === $transfer ) {
			return new WP_Error( 'jcore_silta_no_transfer', __( "The attachment's file was not transferred.", 'jcore-silta' ), array( 'status' => 400 ) );
		}

		$files = Media::finalize( $transfer, $this->ctx->site, $record );
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$fields  = (array) $incoming['fields'];
		$postarr = array(
			'post_mime_type' => $files['mime'],
			'post_title'     => (string) $fields['post_title'],
			'post_name'      => (string) $fields['post_name'],
			'post_excerpt'   => (string) $fields['post_excerpt'],
			'post_content'   => (string) $incoming['content'],
			'post_status'    => 'inherit',
			'post_author'    => (int) $fields['post_author'] > 0 ? (int) $fields['post_author'] : get_current_user_id(),
			'post_date'      => (string) $fields['post_date'],
			'post_date_gmt'  => (string) $fields['post_date_gmt'],
		);

		$id = wp_insert_attachment( wp_slash( $postarr ), Media::basedir() . '/' . $files['main'], (int) $fields['post_parent'], true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$this->finish_file( (int) $id, $incoming, $files );

		return array( (int) $id, array( 'files' => self::renamed( $files['map'] ) ) );
	}

	/**
	 * Points an existing attachment at newly transferred files. The old files
	 * stay on disk, so undo can point it back.
	 *
	 * @param int                  $id       Local attachment ID.
	 * @param array<string, mixed> $incoming Incoming attachment.
	 * @param string               $transfer Transfer ID.
	 * @param array<string, mixed> $record   Original attachment before transformation.
	 *
	 * @return array<string, mixed>|WP_Error Mapping extra.
	 */
	private function replace_file( int $id, array $incoming, string $transfer, array $record ): array|WP_Error {
		if ( '' === $transfer ) {
			return new WP_Error( 'jcore_silta_no_transfer', __( "The attachment's file was not transferred.", 'jcore-silta' ), array( 'status' => 400 ) );
		}

		$files = Media::finalize( $transfer, $this->ctx->site, $record, $id );
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$updated = wp_update_post(
			array(
				'ID'             => $id,
				'post_mime_type' => $files['mime'],
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		update_attached_file( $id, Media::basedir() . '/' . $files['main'] );
		$this->finish_file( $id, $incoming, $files );

		return array( 'files' => self::renamed( $files['map'] ) );
	}

	/**
	 * Writes an attachment's metadata and creates the sizes this site has but
	 * the source didn't send.
	 *
	 * @param int                  $id       Attachment ID.
	 * @param array<string, mixed> $incoming Incoming attachment.
	 * @param array<string, mixed> $files    Finalized transfer.
	 *
	 * @return void
	 */
	private function finish_file( int $id, array $incoming, array $files ): void {
		$metadata = Media::target_metadata( $files['metadata'], (string) $files['main'], (array) $files['map'] );
		wp_update_attachment_metadata( $id, $metadata );

		if ( wp_attachment_is_image( $id ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			if ( function_exists( 'wp_update_image_subsizes' ) ) {
				wp_update_image_subsizes( $id );
				$generated = Media::describe( $id, false );
				if ( null !== $generated ) {
					$map = $files['map'];
					foreach ( $generated['files'] as $entry ) {
						$map[ $entry['path'] ] = $entry['path'];
					}
					wp_update_attachment_metadata( $id, Media::target_metadata( $generated['metadata'], $files['main'], $map ) );
				}
			}
		}
	}

	/**
	 * Imports a term: matched terms are left as they are, missing ones created.
	 *
	 * @param array<string, mixed> $record Incoming term.
	 *
	 * @return array<string, mixed>
	 */
	private function term( array $record ): array {
		$key    = (string) $record['key'];
		$errors = Preflight::check( $record );
		if ( isset( $errors[''] ) ) {
			return self::failed( $errors[''] );
		}

		$match = $this->ctx->match( $record );
		if ( null !== $match ) {
			$this->ctx->settle( $key, $match['id'] );
			Mapping::set( $this->ctx->site, 'term', (int) $record['id'], $match['id'], (string) $record['hash'] );

			return array(
				'status'   => 'imported',
				'action'   => 'matched',
				'local_id' => $match['id'],
			);
		}

		$incoming = $this->ctx->transform( $record );
		$created  = wp_insert_term(
			wp_slash( (string) $incoming['name'] ),
			(string) $incoming['taxonomy'],
			wp_slash(
				array(
					'slug'        => (string) $incoming['slug'],
					'description' => (string) $incoming['description'],
					'parent'      => (int) $incoming['parent'],
				)
			)
		);

		if ( is_wp_error( $created ) ) {
			$existing = (int) $created->get_error_data( 'term_exists' );
			if ( $existing <= 0 ) {
				return self::failed( $created->get_error_message() );
			}
			$this->ctx->settle( $key, $existing );
			Mapping::set( $this->ctx->site, 'term', (int) $record['id'], $existing, (string) $record['hash'] );

			return array(
				'status'   => 'imported',
				'action'   => 'matched',
				'local_id' => $existing,
			);
		}

		$id       = (int) $created['term_id'];
		$warnings = Meta_Writer::replace( 'term', $id, array_keys( (array) $incoming['meta'] ), (array) $incoming['meta'] );
		if ( ! empty( $incoming['language']['lang'] ) ) {
			Polylang::set_term( $id, $incoming['language'] );
		}

		Snapshots::created(
			$this->job,
			'term',
			$id,
			array(
				'site'      => $this->ctx->site,
				'remote_id' => (int) $record['id'],
			)
		);
		$this->ctx->settle( $key, $id );
		Mapping::set( $this->ctx->site, 'term', (int) $record['id'], $id, (string) $record['hash'] );

		return array(
			'status'     => 'imported',
			'action'     => 'created',
			'local_id'   => $id,
			'warnings'   => $warnings,
			'unresolved' => $incoming['unresolved'],
		);
	}

	/**
	 * Imports a menu.
	 *
	 * @param array<string, mixed> $record  Incoming menu.
	 * @param array<string, mixed> $options Run options.
	 *
	 * @return array<string, mixed>
	 */
	private function menu( array $record, array $options ): array {
		$key      = (string) $record['key'];
		$match    = $this->ctx->match( $record );
		$local_id = (int) ( $match['id'] ?? 0 );
		$local    = $local_id > 0 ? Menus::state( $local_id ) : null;
		$incoming = $this->ctx->transform( $record );
		$wants    = self::wants( $options['selections'][ $key ] ?? null, array() );

		if ( null !== $local ) {
			$changed = array();
			foreach ( Differ::sections( $incoming, $local ) as $section => $info ) {
				if ( $info['changed'] && $wants( $section ) ) {
					$changed[ $section ] = true;
				}
			}
			if ( empty( $changed ) ) {
				Mapping::set( $this->ctx->site, 'term', (int) $record['id'], $local_id, (string) $record['hash'], State::hash( $local ) );

				return array(
					'status'   => 'imported',
					'action'   => 'unchanged',
					'local_id' => $local_id,
				);
			}

			$conflict = $this->ctx->conflict( $record, $local_id, $local );
			if ( null !== $conflict && empty( $options['confirmed'][ $key ] ) ) {
				return array(
					'status'   => 'skipped',
					'reason'   => 'conflict',
					'conflict' => $conflict,
					'local_id' => $local_id,
				);
			}

			Snapshots::updated(
				$this->job,
				'menu',
				$local_id,
				array(
					'state'     => $local,
					'site'      => $this->ctx->site,
					'remote_id' => (int) $record['id'],
					'mapping'   => Mapping::get( $this->ctx->site, 'term', (int) $record['id'] ),
				)
			);
			$wants = static fn( string $section ): bool => isset( $changed[ $section ] );
		}

		$written = Menus::write( $incoming, $local_id, $wants, $this->ctx->site );
		if ( is_wp_error( $written ) ) {
			return self::failed( $written->get_error_message() );
		}

		if ( null === $local ) {
			Snapshots::created(
				$this->job,
				'menu',
				$written['id'],
				array(
					'site'      => $this->ctx->site,
					'remote_id' => (int) $record['id'],
				)
			);
		}

		$after = Menus::state( $written['id'] );
		Mapping::set( $this->ctx->site, 'term', (int) $record['id'], $written['id'], (string) $record['hash'], null === $after ? '' : State::hash( $after ) );

		return array(
			'status'     => 'imported',
			'action'     => null === $local ? 'created' : 'updated',
			'local_id'   => $written['id'],
			'unresolved' => $incoming['unresolved'],
			'warnings'   => $written['warnings'],
		);
	}

	/**
	 * Trashes an orphan: a local post synced from the source whose original no
	 * longer exists there.
	 *
	 * @param int $id Local post ID.
	 *
	 * @return array<string, mixed>
	 */
	private function trash( int $id ): array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
			return array(
				'status' => 'skipped',
				'reason' => 'gone',
			);
		}

		if ( null === Mapping::remote_for( $this->ctx->site, 'post', $id ) ) {
			return self::failed( __( 'Only posts synced from the other site can be trashed by a sync.', 'jcore-silta' ) );
		}

		Snapshots::trashed( $this->job, $id );
		wp_trash_post( $id );

		return array(
			'status'   => 'imported',
			'action'   => 'trashed',
			'local_id' => $id,
		);
	}

	/**
	 * Builds the section filter for a selection.
	 *
	 * @param array<int, string>|null $selection Sections, or null for all. A
	 *                                           group name (fields, meta, terms)
	 *                                           selects all its sections.
	 * @param array<string, string>   $errors    Sections that can't be written.
	 *
	 * @return callable
	 */
	private static function wants( ?array $selection, array $errors ): callable {
		return static function ( string $section ) use ( $selection, $errors ): bool {
			if ( isset( $errors[ $section ] ) ) {
				return false;
			}
			if ( null === $selection ) {
				return true;
			}
			foreach ( $selection as $selected ) {
				if ( $selected === $section || str_starts_with( $section, $selected . '.' ) ) {
					return true;
				}
			}

			return false;
		};
	}

	/**
	 * Keeps the files whose name changed on the way.
	 *
	 * @param array<string, string> $map Source → target paths.
	 *
	 * @return array<string, string>
	 */
	private static function renamed( array $map ): array {
		return array_filter(
			$map,
			static fn( string $target, string $source ): bool => $source !== $target,
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Builds a failed result.
	 *
	 * @param string $message Error.
	 *
	 * @return array<string, mixed>
	 */
	private static function failed( string $message ): array {
		return array(
			'status' => 'failed',
			'error'  => $message,
		);
	}
}

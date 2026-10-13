<?php
/**
 * Works out what a sync would do, without doing it.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plans a bundle on the target: matches, conflicts, changed sections, problems
 * and missing dependencies per object, and the full diff on request.
 */
final class Planner {

	/**
	 * The bundle and its options.
	 *
	 * @var Context
	 */
	private Context $ctx;

	/**
	 * Creates a planner.
	 *
	 * @param Context $ctx Context.
	 */
	public function __construct( Context $ctx ) {
		$this->ctx = $ctx;
	}

	/**
	 * Plans every object in the bundle.
	 *
	 * @return array<string, array<string, mixed>> Plan per object key.
	 */
	public function plan(): array {
		$plans = array();
		foreach ( $this->ctx->objects as $key => $record ) {
			try {
				$plans[ $key ] = $this->object( $record );
			} catch ( \Throwable $e ) {
				$plans[ $key ] = array(
					'key'    => $key,
					'action' => 'error',
					'errors' => array( '' => $e->getMessage() ),
				);
			}
		}

		return $plans;
	}

	/**
	 * Returns the full section-by-section comparison of one object.
	 *
	 * @param string $key Object key.
	 *
	 * @return array<string, mixed>
	 */
	public function diff( string $key ): array {
		$record = $this->ctx->objects[ $key ] ?? null;
		if ( null === $record ) {
			return array();
		}

		$match    = $this->ctx->match( $record );
		$local    = null === $match ? null : State::read( $record['type'], $match['id'] );
		$incoming = $this->ctx->transform( $record );
		if ( 'post' === $record['type'] ) {
			$incoming['fields']['post_status'] = $this->ctx->status( $incoming, $local );
		}

		return array(
			'key'      => $key,
			'match'    => null === $match ? null : self::describe( $record['type'], $match['id'], $match['method'] ),
			'sections' => Differ::details( $incoming, $local ),
			'errors'   => Preflight::check( $record ),
		);
	}

	/**
	 * Plans one object.
	 *
	 * @param array<string, mixed> $record Object.
	 *
	 * @return array<string, mixed>
	 */
	private function object( array $record ): array {
		$key    = (string) $record['key'];
		$errors = Preflight::check( $record );
		$match  = isset( $errors[''] ) ? null : $this->ctx->match( $record );

		$plan = array(
			'key'      => $key,
			'type'     => $record['type'],
			'subtype'  => (string) ( $record['post_type'] ?? $record['taxonomy'] ?? 'nav_menu' ),
			'title'    => (string) ( $record['title'] ?? '' ),
			'match'    => null === $match ? null : self::describe( $record['type'], $match['id'], $match['method'] ),
			'action'   => 'create',
			'conflict' => null,
			'sections' => array(),
			'changed'  => array(),
			'errors'   => $errors,
			'missing'  => array(),
		);

		if ( isset( $errors[''] ) ) {
			$plan['action'] = 'error';

			return $plan;
		}

		$incoming        = $this->ctx->transform( $record );
		$plan['missing'] = $this->missing( (array) $incoming['unresolved'] );

		if ( 'term' === $record['type'] ) {
			$plan['action'] = null === $match ? 'create' : 'match';

			return $plan;
		}

		$local = null === $match ? null : State::read( $record['type'], $match['id'] );
		if ( 'post' === $record['type'] ) {
			$incoming['fields']['post_status'] = $this->ctx->status( $incoming, $local );
		}

		$sections = Differ::sections( $incoming, $local );
		foreach ( $sections as $section => $info ) {
			$plan['sections'][ $section ] = $info['label'];
			if ( $info['changed'] ) {
				$plan['changed'][] = $section;
			}
		}

		if ( null !== $local ) {
			$plan['action'] = empty( $plan['changed'] ) ? 'unchanged' : 'update';
			if ( 'update' === $plan['action'] ) {
				$plan['conflict'] = $this->ctx->conflict( $record, (int) $match['id'], $local );
			}
		}

		return $plan;
	}

	/**
	 * Describes the unresolved references that are not part of the job.
	 *
	 * @param string[] $unresolved Unresolved keys.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function missing( array $unresolved ): array {
		$job     = array_flip( (array) ( $this->ctx->options['job_refs'] ?? array() ) );
		$missing = array();

		foreach ( $unresolved as $key ) {
			if ( isset( $job[ $key ] ) ) {
				continue;
			}
			$info      = $this->ctx->info( $key );
			$missing[] = array(
				'key'      => $key,
				'title'    => (string) ( $info['title'] ?? $key ),
				'subtype'  => (string) ( $info['post_type'] ?? $info['taxonomy'] ?? '' ),
				'dangling' => null === $info || ! empty( $info['missing'] ),
			);
		}

		return $missing;
	}

	/**
	 * Describes a local object for display.
	 *
	 * @param string $type   post, term or menu.
	 * @param int    $id     Local ID.
	 * @param string $method How it was matched.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe( string $type, int $id, string $method = '' ): array {
		if ( 'post' === $type ) {
			$post = get_post( $id );

			return array(
				'id'       => $id,
				'method'   => $method,
				'title'    => $post instanceof \WP_Post ? ( '' !== $post->post_title ? $post->post_title : $post->post_name ) : '#' . $id,
				'status'   => $post instanceof \WP_Post ? $post->post_status : '',
				'modified' => $post instanceof \WP_Post ? $post->post_modified_gmt : '',
				'edit'     => (string) get_edit_post_link( $id, 'raw' ),
				'view'     => $post instanceof \WP_Post && 'attachment' !== $post->post_type ? (string) get_permalink( $post ) : (string) wp_get_attachment_url( $id ),
			);
		}

		$term = get_term( $id );

		return array(
			'id'     => $id,
			'method' => $method,
			'title'  => $term instanceof \WP_Term ? $term->name : '#' . $id,
			'edit'   => 'menu' === $type ? admin_url( 'nav-menus.php?action=edit&menu=' . $id ) : (string) get_edit_term_link( $id ),
		);
	}
}

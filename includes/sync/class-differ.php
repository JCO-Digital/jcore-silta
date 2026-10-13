<?php
/**
 * Section-by-section comparison.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Splits an object into the sections diff mode offers and compares them.
 *
 * Sections: `fields.<field>`, `content`, `featured_image`, `meta.<group>`
 * (an ACF field with everything stored for it, or a single meta key),
 * `terms.<taxonomy>`, `language`, `file` (attachments) and, for menus,
 * `fields.name`, `items` and `locations`.
 */
final class Differ {

	/**
	 * Fields not offered for attachments.
	 */
	private const NOT_FOR_ATTACHMENTS = array( 'post_status', 'menu_order', 'comment_status', 'ping_status', 'post_password', 'page_template', 'post_format', 'sticky' );

	/**
	 * Compares an incoming object with its local copy.
	 *
	 * @param array<string, mixed>      $incoming Transformed incoming object.
	 * @param array<string, mixed>|null $local    Local state, or null when new.
	 *
	 * @return array<string, array{label: string, group: string, changed: bool}>
	 */
	public static function sections( array $incoming, ?array $local ): array {
		$theirs = self::parts( $incoming, $incoming );
		$ours   = null === $local ? array() : self::parts( $local, $incoming );

		$sections = array();
		foreach ( $theirs + $ours as $key => $part ) {
			$sections[ $key ] = array(
				'label'   => $part['label'],
				'group'   => $part['group'],
				'changed' => ( $theirs[ $key ]['value'] ?? null ) !== ( $ours[ $key ]['value'] ?? null ),
			);
		}

		return $sections;
	}

	/**
	 * Compares and renders every section for the diff view.
	 *
	 * @param array<string, mixed>      $incoming Transformed incoming object.
	 * @param array<string, mixed>|null $local    Local state, or null when new.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function details( array $incoming, ?array $local ): array {
		$theirs = self::parts( $incoming, $incoming );
		$ours   = null === $local ? array() : self::parts( $local, $incoming );

		$details = array();
		foreach ( $theirs + $ours as $key => $part ) {
			$before  = $ours[ $key ]['display'] ?? '';
			$after   = $theirs[ $key ]['display'] ?? '';
			$changed = ( $theirs[ $key ]['value'] ?? null ) !== ( $ours[ $key ]['value'] ?? null );

			$details[ $key ] = array(
				'label'   => $part['label'],
				'group'   => $part['group'],
				'changed' => $changed,
				'before'  => $before,
				'after'   => $after,
				'diff'    => $changed && ! empty( $part['text'] ) ? self::text_diff( (string) $before, (string) $after ) : '',
			);
		}

		return $details;
	}

	/**
	 * Splits a state into sections, each with a comparable value and a display.
	 *
	 * @param array<string, mixed> $state    State to split.
	 * @param array<string, mixed> $incoming Incoming object, for meta groups and labels.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function parts( array $state, array $incoming ): array {
		return match ( $state['type'] ) {
			'post' => self::post_parts( $state, $incoming ),
			'menu' => self::menu_parts( $state ),
			default => array(),
		};
	}

	/**
	 * Splits a post.
	 *
	 * @param array<string, mixed> $state    Post state.
	 * @param array<string, mixed> $incoming Incoming object.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function post_parts( array $state, array $incoming ): array {
		$attachment = 'attachment' === $state['post_type'];
		$labels     = self::field_labels( $attachment );
		$fields     = (array) $state['fields'];
		$parts      = array();

		foreach ( State::FIELDS as $field ) {
			if ( $attachment && in_array( $field, self::NOT_FOR_ATTACHMENTS, true ) ) {
				continue;
			}
			if ( 'sticky' === $field && 'post' !== $state['post_type'] ) {
				continue;
			}
			if ( 'post_author' === $field && 0 === (int) ( $incoming['fields']['post_author'] ?? 0 ) ) {
				// The source's author doesn't exist here; the author is left alone.
				continue;
			}

			$value = $fields[ $field ] ?? '';
			if ( 'post_date' === $field ) {
				$gmt   = (string) ( $fields['post_date_gmt'] ?? '' );
				$value = (string) $value . ( '0000-00-00 00:00:00' === $gmt ? '' : ' / ' . $gmt );
			}

			$parts[ 'fields.' . $field ] = array(
				'label'   => $labels[ $field ],
				'group'   => 'fields',
				'value'   => is_bool( $value ) ? $value : (string) $value,
				'display' => self::display_field( $field, $value ),
				'text'    => in_array( $field, array( 'post_title', 'post_excerpt' ), true ),
			);
		}

		$parts['content'] = array(
			'label'   => $attachment ? __( 'Description', 'jcore-silta' ) : __( 'Content', 'jcore-silta' ),
			'group'   => 'content',
			'value'   => (string) $state['content'],
			'display' => (string) $state['content'],
			'text'    => true,
		);

		if ( ! $attachment ) {
			$thumbnail               = (int) ( $state['featured_image'] ?? 0 );
			$parts['featured_image'] = array(
				'label'   => __( 'Featured image', 'jcore-silta' ),
				'group'   => 'featured_image',
				'value'   => $thumbnail,
				'display' => self::display_post( $thumbnail ),
				'text'    => false,
			);
		}

		$known  = (array) ( $incoming['meta_groups'] ?? array() );
		$glabel = (array) ( $incoming['group_labels'] ?? array() );
		foreach ( self::group_meta( (array) ( $state['meta'] ?? array() ), $known ) as $group => $meta ) {
			$parts[ 'meta.' . $group ] = array(
				'label'   => (string) ( $glabel[ $group ] ?? self::local_group_label( $group ) ),
				'group'   => 'meta',
				'value'   => $meta,
				'display' => self::display_meta( $meta ),
				'text'    => true,
			);
		}

		foreach ( (array) ( $state['terms'] ?? array() ) as $taxonomy => $ids ) {
			$ids = array_map( 'intval', (array) $ids );
			sort( $ids );
			$record = get_taxonomy( (string) $taxonomy );

			$parts[ 'terms.' . $taxonomy ] = array(
				'label'   => $record ? (string) $record->labels->name : (string) $taxonomy,
				'group'   => 'terms',
				'value'   => $ids,
				'display' => self::display_terms( $ids ),
				'text'    => false,
			);
		}

		if ( ! empty( $state['language'] ) || ! empty( $incoming['language'] ) ) {
			$language = $state['language'] ?? null;
			$value    = null === $language ? null : array(
				'lang'         => (string) $language['lang'],
				'translations' => (array) $language['translations'],
			);

			$parts['language'] = array(
				'label'   => __( 'Language and translations', 'jcore-silta' ),
				'group'   => 'language',
				'value'   => $value,
				'display' => self::display_language( $value ),
				'text'    => false,
			);
		}

		if ( $attachment ) {
			$parts['file'] = array(
				'label'   => __( 'File', 'jcore-silta' ),
				'group'   => 'file',
				'value'   => State::file_hashes( $state['file'] ?? null ),
				'display' => self::display_files( $state['file'] ?? null ),
				'text'    => false,
			);
		}

		return $parts;
	}

	/**
	 * Splits a menu.
	 *
	 * @param array<string, mixed> $state Menu state.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function menu_parts( array $state ): array {
		$lines = array();
		$ids   = array();
		foreach ( (array) $state['items'] as $item ) {
			$ids[ (int) $item['id'] ] = $item;
		}
		foreach ( (array) $state['items'] as $item ) {
			$depth  = 0;
			$parent = (int) $item['parent'];
			while ( $parent > 0 && isset( $ids[ $parent ] ) && $depth < 20 ) {
				++$depth;
				$parent = (int) $ids[ $parent ]['parent'];
			}
			$target  = 'custom' === $item['item_type'] ? (string) $item['url'] : $item['object'] . ' #' . $item['object_id'];
			$lines[] = str_repeat( '  ', $depth ) . '- ' . ( '' !== (string) $item['title'] ? $item['title'] : '(' . $item['object'] . ')' ) . ' → ' . $target;
		}
		$items = implode( "\n", $lines );

		$locations = (array) $state['locations'];
		sort( $locations );

		return array(
			'fields.name' => array(
				'label'   => __( 'Name', 'jcore-silta' ),
				'group'   => 'fields',
				'value'   => (string) $state['name'],
				'display' => (string) $state['name'],
				'text'    => false,
			),
			'items'       => array(
				'label'   => __( 'Items', 'jcore-silta' ),
				'group'   => 'items',
				'value'   => $items,
				'display' => $items,
				'text'    => true,
			),
			'locations'   => array(
				'label'   => __( 'Locations', 'jcore-silta' ),
				'group'   => 'locations',
				'value'   => $locations,
				'display' => implode( ', ', $locations ),
				'text'    => false,
			),
		);
	}

	/**
	 * Groups raw meta into sections.
	 *
	 * @param array<string, string[]> $meta  Raw meta.
	 * @param array<string, string>   $known Group per key, as the source grouped them.
	 *
	 * @return array<string, array<string, string[]>>
	 */
	public static function group_meta( array $meta, array $known ): array {
		$groups = array();

		foreach ( $meta as $key => $values ) {
			$key   = (string) $key;
			$group = $known[ $key ] ?? null;

			if ( null === $group ) {
				$base      = str_starts_with( $key, '_' ) && isset( $meta[ substr( $key, 1 ) ] ) ? substr( $key, 1 ) : $key;
				$reference = $meta[ '_' . $base ][0] ?? null;
				$group     = $known[ $base ] ?? null;
				if ( null === $group && Acf::is_key( $reference ) ) {
					$description = Acf::describe( (string) $reference );
					$group       = null !== $description && '' !== $description['top'] ? $description['top'] : null;
				}
				$group ??= $base;
			}

			$groups[ $group ][ $key ] = array_values( (array) $values );
		}

		foreach ( $groups as $group => $keys ) {
			ksort( $keys );
			$groups[ $group ] = $keys;
		}
		ksort( $groups );

		return $groups;
	}

	/**
	 * Section labels for core fields.
	 *
	 * @param bool $attachment Whether the post is an attachment.
	 *
	 * @return array<string, string>
	 */
	private static function field_labels( bool $attachment ): array {
		return array(
			'post_title'     => __( 'Title', 'jcore-silta' ),
			'post_name'      => __( 'Slug', 'jcore-silta' ),
			'post_excerpt'   => $attachment ? __( 'Caption', 'jcore-silta' ) : __( 'Excerpt', 'jcore-silta' ),
			'post_status'    => __( 'Status', 'jcore-silta' ),
			'post_date'      => __( 'Date', 'jcore-silta' ),
			'post_author'    => __( 'Author', 'jcore-silta' ),
			'post_parent'    => $attachment ? __( 'Attached to', 'jcore-silta' ) : __( 'Parent', 'jcore-silta' ),
			'menu_order'     => __( 'Order', 'jcore-silta' ),
			'comment_status' => __( 'Comments', 'jcore-silta' ),
			'ping_status'    => __( 'Pingbacks', 'jcore-silta' ),
			'post_password'  => __( 'Password', 'jcore-silta' ),
			'page_template'  => __( 'Template', 'jcore-silta' ),
			'post_format'    => __( 'Format', 'jcore-silta' ),
			'sticky'         => __( 'Sticky', 'jcore-silta' ),
		);
	}

	/**
	 * Label for a meta group the source didn't describe.
	 *
	 * @param string $group Group.
	 *
	 * @return string
	 */
	private static function local_group_label( string $group ): string {
		return $group;
	}

	/**
	 * Renders a field value.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 *
	 * @return string
	 */
	private static function display_field( string $field, mixed $value ): string {
		switch ( $field ) {
			case 'post_author':
				$user = get_userdata( (int) $value );

				return $user ? $user->display_name : '—';
			case 'post_parent':
				return self::display_post( (int) $value );
			case 'sticky':
				return $value ? __( 'Yes', 'jcore-silta' ) : __( 'No', 'jcore-silta' );
			case 'post_status':
				$status = get_post_status_object( (string) $value );

				return $status ? (string) $status->label : (string) $value;
		}

		return (string) $value;
	}

	/**
	 * Renders a reference to a local post.
	 *
	 * @param int $id Post ID.
	 *
	 * @return string
	 */
	private static function display_post( int $id ): string {
		if ( $id <= 0 ) {
			return '—';
		}

		$post = get_post( $id );

		return $post instanceof \WP_Post ? sprintf( '%s (#%d)', '' !== $post->post_title ? $post->post_title : $post->post_name, $id ) : '#' . $id;
	}

	/**
	 * Renders terms.
	 *
	 * @param int[] $ids Term IDs.
	 *
	 * @return string
	 */
	private static function display_terms( array $ids ): string {
		$names = array();
		foreach ( $ids as $id ) {
			$term    = get_term( $id );
			$names[] = $term instanceof \WP_Term ? $term->name : '#' . $id;
		}

		return empty( $names ) ? '—' : implode( ', ', $names );
	}

	/**
	 * Renders a language and its translations.
	 *
	 * @param array<string, mixed>|null $language Language.
	 *
	 * @return string
	 */
	private static function display_language( ?array $language ): string {
		if ( null === $language ) {
			return '—';
		}

		$parts = array();
		foreach ( (array) $language['translations'] as $lang => $id ) {
			$parts[] = $lang . ': ' . self::display_post( (int) $id );
		}

		return (string) $language['lang'] . ( empty( $parts ) ? '' : ' (' . implode( '; ', $parts ) . ')' );
	}

	/**
	 * Renders an attachment's files.
	 *
	 * @param array<string, mixed>|null $file File description.
	 *
	 * @return string
	 */
	private static function display_files( ?array $file ): string {
		if ( null === $file ) {
			return '—';
		}

		$lines = array();
		foreach ( (array) $file['files'] as $entry ) {
			$lines[] = sprintf( '%s (%s, %s)', $entry['path'], size_format( (int) $entry['size'] ), substr( (string) $entry['hash'], 0, 8 ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Renders a meta group as text, decoding serialized and JSON values.
	 *
	 * @param array<string, string[]> $meta Raw meta of the group.
	 *
	 * @return string
	 */
	private static function display_meta( array $meta ): string {
		$lines = array();
		foreach ( $meta as $key => $values ) {
			// ACF's `_name` rows only point at the field definition; they are
			// compared, but listing them would bury the values that changed.
			if ( str_starts_with( (string) $key, '_' ) && isset( $meta[ substr( (string) $key, 1 ) ] ) && Acf::is_key( $values[0] ?? null ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				$lines[] = $key . ': ' . self::readable( (string) $value );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Makes a raw value readable.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private static function readable( string $value ): string {
		if ( Replacer::is_serialized( $value ) ) {
			$decoded = unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Display only, no classes allowed.
			if ( false !== $decoded || 'b:0;' === $value ) {
				return (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
			}
		}

		if ( '' !== $value && ( '{' === $value[0] || '[' === $value[0] ) ) {
			$decoded = json_decode( $value );
			if ( null !== $decoded ) {
				return (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
		}

		return $value;
	}

	/**
	 * Renders a side-by-side diff like the revisions screen.
	 *
	 * @param string $before Local text.
	 * @param string $after  Incoming text.
	 *
	 * @return string HTML table, escaped by core's renderer.
	 */
	private static function text_diff( string $before, string $after ): string {
		if ( ! function_exists( 'wp_text_diff' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		// One block per line, so the diff lines up block by block.
		$split = static fn( string $text ): string => (string) preg_replace( '/(-->)(?!\n)/', "$1\n", (string) preg_replace( '/(?<!\n)(<!-- \/?wp:)/', "\n$1", $text ) );

		return (string) wp_text_diff(
			$split( $before ),
			$split( $after ),
			// No titles: which site is "this site" depends on the direction, so
			// the screen labels the columns itself.
			array( 'show_split_view' => true )
		);
	}
}

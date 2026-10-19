<?php
/**
 * Gravity Forms forms.
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
 * Reads and writes Gravity Forms form definitions through GFAPI: title,
 * active state, settings, fields, confirmations and notifications.
 *
 * Entries are never synced: they are what visitors submitted. Add-on feeds
 * are left alone too (see docs/design.md): they hold environment-specific
 * settings such as payment modes, CRM lists and webhook URLs, their meta has
 * no generic shape to remap, and they need the same add-on on both sides.
 */
final class Gravity_Forms {

	/**
	 * Form properties that are not settings: stored in their own columns,
	 * synced as their own sections, or meaningless on another site.
	 */
	private const NOT_SETTINGS = array( 'id', 'title', 'is_active', 'is_trash', 'date_created', 'date_updated', 'fields', 'confirmations', 'notifications', 'notification', 'autoResponder', 'nextFieldId' );

	/**
	 * Field property that only makes sense on the site that holds the form.
	 * Gravity Forms sets it on every field and sub-field when it loads a form.
	 */
	private const FIELD_LOCAL = 'formId';

	/**
	 * Properties whose text is HTML, filtered with kses for users without
	 * unfiltered_html, like the post content Silta writes.
	 */
	private const HTML_KEYS = array( 'content', 'description', 'label', 'message', 'errorMessage' );

	/**
	 * Whether Gravity Forms is active.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return class_exists( 'GFAPI' );
	}

	/**
	 * Whether a form exists here and is not in the trash.
	 *
	 * @param int $id Form ID.
	 *
	 * @return bool
	 */
	public static function exists( int $id ): bool {
		if ( $id <= 0 || ! self::active() || ! \GFAPI::form_id_exists( $id ) ) {
			return false;
		}
		$form = \GFAPI::get_form( $id );

		return is_array( $form ) && empty( $form['is_trash'] );
	}

	/**
	 * Reads a form.
	 *
	 * @param int $id Form ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function state( int $id ): ?array {
		if ( ! self::exists( $id ) ) {
			return null;
		}

		$form = self::plain( \GFAPI::get_form( $id ) );
		if ( null === $form ) {
			return null;
		}

		return self::from_form( $id, $form );
	}

	/**
	 * Turns a form, as GFAPI returns it decoded to plain arrays, into a state.
	 *
	 * @param int                  $id   Form ID.
	 * @param array<string, mixed> $form Form.
	 *
	 * @return array<string, mixed>
	 */
	public static function from_form( int $id, array $form ): array {
		$fields = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) ) {
				$fields[] = self::normalize( self::without_form_id( $field ) );
			}
		}

		return array(
			'type'          => 'form',
			'id'            => $id,
			'name'          => (string) ( $form['title'] ?? '' ),
			'active'        => ! empty( $form['is_active'] ),
			'settings'      => self::normalize( array_diff_key( $form, array_flip( self::NOT_SETTINGS ) ) ),
			'fields'        => $fields,
			'confirmations' => self::keyed( (array) ( $form['confirmations'] ?? array() ) ),
			'notifications' => self::keyed( (array) ( $form['notifications'] ?? array() ) ),
		);
	}

	/**
	 * Removes the form ID Gravity Forms copies into a field and its sub-fields.
	 *
	 * @param array<mixed> $field Field.
	 * @param int          $depth Nesting depth.
	 *
	 * @return array<mixed>
	 */
	private static function without_form_id( array $field, int $depth = 0 ): array {
		unset( $field[ self::FIELD_LOCAL ] );
		if ( $depth < 8 && isset( $field['fields'] ) && is_array( $field['fields'] ) ) {
			foreach ( $field['fields'] as $index => $sub ) {
				if ( is_array( $sub ) ) {
					$field['fields'][ $index ] = self::without_form_id( $sub, $depth + 1 );
				}
			}
		}

		return $field;
	}

	/**
	 * Lists the forms on this site, for the picker.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		if ( ! self::active() ) {
			return array();
		}

		$forms = \GFAPI::get_forms( null, false, 'title' );
		$list  = array();
		foreach ( is_array( $forms ) ? $forms : array() as $form ) {
			$list[] = array(
				'id'     => (int) $form['id'],
				'title'  => (string) $form['title'],
				'active' => ! empty( $form['is_active'] ),
				'fields' => count( (array) ( $form['fields'] ?? array() ) ),
			);
		}

		return $list;
	}

	/**
	 * Returns the minimal description of a form the target needs to find its
	 * own copy.
	 *
	 * @param int $id Form ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function stub( int $id ): ?array {
		if ( ! self::exists( $id ) ) {
			return null;
		}
		$form = \GFAPI::get_form( $id );

		return array(
			'key'   => 'form:' . $id,
			'type'  => 'form',
			'id'    => $id,
			'name'  => (string) $form['title'],
			'title' => (string) $form['title'],
		);
	}

	/**
	 * Finds local forms by title, trashed ones left out.
	 *
	 * @param string $title Title.
	 *
	 * @return int[]
	 */
	public static function ids_by_title( string $title ): array {
		if ( ! self::active() || '' === $title ) {
			return array();
		}

		$ids = array();
		foreach ( (array) \GFAPI::get_forms( null, false ) as $form ) {
			if ( is_array( $form ) && (string) $form['title'] === $title ) {
				$ids[] = (int) $form['id'];
			}
		}

		return $ids;
	}

	/**
	 * Returns a form's title, or '' when there is no such form.
	 *
	 * @param int $id Form ID.
	 *
	 * @return string
	 */
	public static function title( int $id ): string {
		if ( ! self::exists( $id ) ) {
			return '';
		}
		$form = \GFAPI::get_form( $id );

		return is_array( $form ) ? (string) $form['title'] : '';
	}

	/**
	 * Field types a form uses that this site doesn't have, e.g. because an
	 * add-on that provides them is missing.
	 *
	 * @param array<string, mixed> $record Form state.
	 *
	 * @return string[]
	 */
	public static function unknown_field_types( array $record ): array {
		if ( ! class_exists( 'GF_Fields' ) || ! method_exists( 'GF_Fields', 'exists' ) ) {
			return array();
		}

		$unknown = array();
		foreach ( (array) ( $record['fields'] ?? array() ) as $field ) {
			$type = is_array( $field ) ? (string) ( $field['type'] ?? '' ) : '';
			if ( '' !== $type && ! \GF_Fields::exists( $type ) ) {
				$unknown[ $type ] = true;
			}
		}

		return array_keys( $unknown );
	}

	/**
	 * Whether the current user may edit forms here, or create them.
	 *
	 * @param bool $create Whether a form would be created.
	 *
	 * @return bool
	 */
	public static function can_edit( bool $create = false ): bool {
		if ( Capabilities::unrestricted() ) {
			return true;
		}
		if ( ! class_exists( 'GFCommon' ) ) {
			return false;
		}

		return \GFCommon::current_user_can_any( $create ? array( 'gravityforms_create_form' ) : array( 'gravityforms_edit_forms' ) );
	}

	/**
	 * Creates or updates a form from an incoming (already remapped) state.
	 *
	 * @param array<string, mixed> $incoming Incoming form with local IDs.
	 * @param int                  $local_id Matched local form, or 0.
	 * @param callable             $wants    Callback( string $section ): bool.
	 *
	 * @return array{id: int, warnings: string[]}|WP_Error
	 */
	public static function write( array $incoming, int $local_id, callable $wants ): array|WP_Error {
		if ( ! self::active() ) {
			return new WP_Error( 'jcore_silta_no_gravity_forms', __( 'Gravity Forms is not active on this site.', 'jcore-silta' ), array( 'status' => 400 ) );
		}
		$incoming = self::filter_html( $incoming );

		if ( $local_id <= 0 ) {
			// Gravity Forms numbers a title that is taken, as when duplicating a
			// form. Not "accessible" defaults: the source's settings are kept as
			// they are.
			$id = \GFAPI::add_form( self::compose( $incoming, (string) $incoming['name'] ), false );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$id = (int) $id;
			// add_form() leaves the active state to the defaults, and adds a
			// default confirmation when there is none: write the form once more.
			$saved = self::save( $id, $incoming );
			if ( is_wp_error( $saved ) ) {
				\GFAPI::delete_form( $id );

				return $saved;
			}

			return array(
				'id'       => $id,
				'warnings' => array(),
			);
		}

		$current = self::state( $local_id );
		if ( null === $current ) {
			return new WP_Error( 'jcore_silta_no_form', __( 'The form to update no longer exists here.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		$next = $current;
		foreach ( array(
			'fields.title'  => 'name',
			'fields.active' => 'active',
			'settings'      => 'settings',
			'form_fields'   => 'fields',
			'confirmations' => 'confirmations',
			'notifications' => 'notifications',
		) as $section => $property ) {
			if ( $wants( $section ) ) {
				$next[ $property ] = $incoming[ $property ];
			}
		}

		$saved = self::save( $local_id, $next );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'id'       => $local_id,
			'warnings' => array(),
		);
	}

	/**
	 * Puts a form back the way a snapshot recorded it.
	 *
	 * @param array<string, mixed> $snapshot Form state from state().
	 *
	 * @return string[] Warnings.
	 */
	public static function restore( array $snapshot ): array {
		$id = (int) ( $snapshot['id'] ?? 0 );
		if ( ! self::active() || $id <= 0 || ! \GFAPI::form_id_exists( $id ) ) {
			return array();
		}

		$saved = self::save( $id, $snapshot );
		if ( is_wp_error( $saved ) ) {
			return array( $saved->get_error_message() );
		}
		\GFAPI::update_form_property( $id, 'is_trash', 0 );

		return array();
	}

	/**
	 * Takes away a form a job created. It goes to the trash, deactivated,
	 * rather than being deleted: deleting a form deletes its entries too, and
	 * visitors may have submitted some since.
	 *
	 * @param int $id Form ID.
	 *
	 * @return void
	 */
	public static function remove( int $id ): void {
		if ( ! self::active() || ! \GFAPI::form_id_exists( $id ) ) {
			return;
		}
		\GFAPI::update_form_property( $id, 'is_active', 0 );
		\GFAPI::update_form_property( $id, 'is_trash', 1 );
	}

	/**
	 * Writes a whole state over a form.
	 *
	 * @param int                  $id    Form ID.
	 * @param array<string, mixed> $state State.
	 *
	 * @return true|WP_Error
	 */
	private static function save( int $id, array $state ): bool|WP_Error {
		// update_form() also writes the title (numbered if another form has
		// it) and the active state.
		$result = \GFAPI::update_form( self::compose( $state, (string) $state['name'], $id ) + array( 'id' => $id ), $id );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Builds the form array GFAPI takes from a state.
	 *
	 * @param array<string, mixed> $state State.
	 * @param string               $title Title to give the form.
	 * @param int                  $id    Form ID the fields belong to, or 0 for a new form.
	 *
	 * @return array<string, mixed>
	 */
	public static function compose( array $state, string $title, int $id = 0 ): array {
		$fields = array();
		foreach ( (array) ( $state['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) ) {
				$fields[] = $id > 0 ? array( 'formId' => $id ) + $field : $field;
			}
		}

		return array_merge(
			(array) ( $state['settings'] ?? array() ),
			array(
				'title'         => $title,
				'is_active'     => ! empty( $state['active'] ),
				'fields'        => $fields,
				'confirmations' => (array) ( $state['confirmations'] ?? array() ),
				'notifications' => (array) ( $state['notifications'] ?? array() ),
			)
		);
	}

	/**
	 * Converts what GFAPI returns (field objects included) to plain arrays,
	 * the way Gravity Forms' own export does.
	 *
	 * @param mixed $form Form.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function plain( mixed $form ): ?array {
		if ( ! is_array( $form ) ) {
			return null;
		}
		$decoded = json_decode( (string) wp_json_encode( $form ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Sorts the keys of maps, recursively, leaving lists in their order, so
	 * two copies of the same form hash and compare the same.
	 *
	 * @param array<mixed> $value Value.
	 * @param int          $depth Nesting depth.
	 *
	 * @return array<mixed>
	 */
	public static function normalize( array $value, int $depth = 0 ): array {
		if ( $depth > 32 ) {
			return array();
		}
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::normalize( $item, $depth + 1 );
			}
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}

	/**
	 * Normalizes confirmations or notifications, keyed by their own IDs.
	 *
	 * @param array<mixed> $items Items.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function keyed( array $items ): array {
		$keyed = array();
		foreach ( $items as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id           = isset( $item['id'] ) && is_scalar( $item['id'] ) && '' !== (string) $item['id'] ? (string) $item['id'] : (string) $key;
			$keyed[ $id ] = self::normalize( $item );
		}
		ksort( $keyed, SORT_STRING );

		return $keyed;
	}

	/**
	 * Filters HTML properties with kses unless the acting user may post
	 * unfiltered HTML.
	 *
	 * @param array<string, mixed> $state State.
	 *
	 * @return array<string, mixed>
	 */
	private static function filter_html( array $state ): array {
		if ( Capabilities::unfiltered_html() ) {
			return $state;
		}

		$clean = static function ( array $item ): array {
			foreach ( self::HTML_KEYS as $key ) {
				if ( isset( $item[ $key ] ) && is_string( $item[ $key ] ) ) {
					$item[ $key ] = wp_kses_post( $item[ $key ] );
				}
			}

			return $item;
		};

		foreach ( array( 'fields', 'confirmations', 'notifications' ) as $list ) {
			foreach ( (array) ( $state[ $list ] ?? array() ) as $index => $item ) {
				if ( is_array( $item ) ) {
					$state[ $list ][ $index ] = $clean( $item );
				}
			}
		}
		$state['settings'] = $clean( (array) ( $state['settings'] ?? array() ) );

		return $state;
	}
}

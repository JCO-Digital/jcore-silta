<?php
/**
 * Gravity Forms: the references to forms in posts, and in forms to pages.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Integrations;

use Jcore\Silta\Sync\Replacer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds and remaps the IDs of Gravity Forms forms where posts embed them, and
 * the pages forms redirect to. Forms themselves sync as their own kind (see
 * Sync\Gravity_Forms).
 *
 * - The `gravityforms/form` block's `formId` is declared through
 *   `jcore_silta_block_references`, like any block attribute holding an ID.
 * - The `[gravityform id="N"]` shortcode (and its `[gravityforms]` alias) is
 *   remapped in post content and in plain-text meta such as a WYSIWYG field.
 * - A confirmation that redirects to a page (`pageId`) is remapped.
 *
 * ACF fields of the "ACF: Gravity Forms" add-on (`forms`) are in Sync\Acf.
 * An unresolved form ID keeps its source value (see Context::transform()): a
 * block or shortcode without an ID can't render anything, and the job report
 * lists the reference as unresolved either way.
 */
final class Gravity_Forms {

	/**
	 * The block that embeds a form.
	 */
	public const BLOCK = 'gravityforms/form';

	/**
	 * Hooks the integration. Harmless when Gravity Forms isn't active: there
	 * are no blocks or shortcodes to find then.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'jcore_silta_block_references', array( self::class, 'block_references' ) );
	}

	/**
	 * Declares the form block's attribute holding the form ID.
	 *
	 * @param array<string, array<string, string>> $paths Paths per block.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function block_references( array $paths ): array {
		$paths[ self::BLOCK ] = array( 'formId' => 'form' );

		return $paths;
	}

	/**
	 * Opening tags of the form shortcodes. `[[gravityform …]]` is an escaped
	 * shortcode that shows literally, so it is left alone.
	 */
	private const SHORTCODE = '/(?<!\[)\[(?P<tag>gravityforms?)(?P<attrs>\s[^\]]*)?\](?!\])/';

	/**
	 * The `id` attribute inside a shortcode, quoted or not.
	 */
	private const SHORTCODE_ID = '/(?P<before>(?:^|\s)id\s*=\s*)(?P<quote>["\']?)(?P<id>\d+)(?P=quote)(?=\s|$|\/)/';

	/**
	 * Applies a visitor to the IDs of form shortcodes in a text.
	 *
	 * @param string   $text  Text.
	 * @param callable $visit Visitor.
	 *
	 * @return string
	 */
	public static function walk_shortcodes( string $text, callable $visit ): string {
		if ( ! str_contains( $text, '[gravityform' ) ) {
			return $text;
		}

		$keep = self::keep( $visit );

		return (string) preg_replace_callback(
			self::SHORTCODE,
			static function ( array $token ) use ( $keep ): string {
				$attrs = (string) ( $token['attrs'] ?? '' );
				if ( '' === $attrs ) {
					return $token[0];
				}
				$mapped = (string) preg_replace_callback(
					self::SHORTCODE_ID,
					static function ( array $id ) use ( $keep ): string {
						return $id['before'] . $id['quote'] . $keep( 'form', (int) $id['id'] ) . $id['quote'];
					},
					$attrs,
					1
				);

				return '[' . $token['tag'] . $mapped . ']';
			},
			$text
		);
	}

	/**
	 * Applies a visitor to form shortcodes in plain-text meta values. Serialized
	 * values are left alone: their string lengths would change.
	 *
	 * @param array<string, string[]> $meta  Raw meta, modified in place.
	 * @param callable                $visit Visitor.
	 *
	 * @return void
	 */
	public static function walk_meta( array &$meta, callable $visit ): void {
		foreach ( $meta as $key => $values ) {
			foreach ( (array) $values as $index => $raw ) {
				if ( ! is_string( $raw ) || ! str_contains( $raw, '[gravityform' ) || Replacer::is_serialized( $raw ) ) {
					continue;
				}
				$meta[ $key ][ $index ] = self::walk_shortcodes( $raw, $visit );
			}
		}
	}

	/**
	 * Applies a visitor to the pages a form's confirmations redirect to.
	 *
	 * @param array<string, mixed> $record Form state, modified in place.
	 * @param callable             $visit  Visitor.
	 *
	 * @return void
	 */
	public static function walk_form( array &$record, callable $visit ): void {
		if ( empty( $record['confirmations'] ) || ! is_array( $record['confirmations'] ) ) {
			return;
		}

		$keep = self::keep( $visit );
		foreach ( $record['confirmations'] as $index => $confirmation ) {
			if ( ! is_array( $confirmation ) ) {
				continue;
			}
			$page = $confirmation['pageId'] ?? null;
			if ( is_int( $page ) && $page > 0 ) {
				$record['confirmations'][ $index ]['pageId'] = $keep( 'post', $page );
			} elseif ( is_string( $page ) && '' !== $page && ctype_digit( $page ) && (int) $page > 0 ) {
				$record['confirmations'][ $index ]['pageId'] = (string) $keep( 'post', (int) $page );
			}
		}
	}

	/**
	 * Wraps a visitor so an unresolved ID keeps its source value.
	 *
	 * @param callable $visit Visitor.
	 *
	 * @return callable( string, int ): int
	 */
	private static function keep( callable $visit ): callable {
		return static function ( string $kind, int $id ) use ( $visit ): int {
			$new = $visit( $kind, $id );

			return null === $new || (int) $new <= 0 ? $id : (int) $new;
		};
	}
}

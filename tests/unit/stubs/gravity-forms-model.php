<?php
/**
 * Stand-in for Gravity Forms' form sanitizing, for the HTML filtering tests.
 *
 * @package Jcore\Silta
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Guarded stand-in for Gravity Forms.

if ( ! class_exists( 'GFFormsModel' ) ) {
	/**
	 * Stand-in for the parts of GFFormsModel that sanitize a form.
	 */
	final class GFFormsModel {

		/**
		 * Forms passed to sanitize_settings().
		 *
		 * @var array<int, array<string, mixed>>
		 */
		public static array $sanitized = array();

		/**
		 * Leaves fields as arrays; the real one makes GF_Field objects of them.
		 *
		 * @param array<string, mixed> $form Form.
		 * @return array<string, mixed>
		 */
		public static function convert_field_objects( array $form ): array {
			return $form;
		}

		/**
		 * Like the real one: stamps the version, strips the title and kses the
		 * description, field labels and the login message.
		 *
		 * @param array<string, mixed> $form Form.
		 * @return array<string, mixed>
		 */
		public static function sanitize_settings( array $form ): array {
			self::$sanitized[] = $form;

			$form['version'] = '9.9.9';
			$form['title']   = trim( strip_tags( (string) $form['title'] ) );
			if ( isset( $form['description'] ) ) {
				$form['description'] = wp_kses_post( $form['description'] );
			}
			if ( isset( $form['requireLogin'] ) ) {
				$form['requireLogin']        = (bool) $form['requireLogin'];
				$form['requireLoginMessage'] = wp_kses_post( (string) $form['requireLoginMessage'] );
			}
			foreach ( $form['fields'] as $index => $field ) {
				$form['fields'][ $index ]['isRequired'] = ! empty( $field['isRequired'] );
				if ( isset( $field['label'] ) ) {
					$form['fields'][ $index ]['label'] = wp_kses_post( $field['label'] );
				}
			}

			return $form;
		}
	}
}

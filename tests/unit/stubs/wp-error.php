<?php
/**
 * WP_Error and is_wp_error() stand-ins shared by the unit test stubs.
 *
 * @package Jcore\Silta\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Core stand-ins.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- The class and its check belong together.

if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal error value. */
	class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		public string $code;
		/**
		 * Message.
		 *
		 * @var string
		 */
		public string $message;
		/**
		 * Data.
		 *
		 * @var mixed
		 */
		public mixed $data;

		/**
		 * Creates the test fixture.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Provides get error code for the test fixture.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Provides get error message for the test fixture.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * Provides get error data for the test fixture.
		 *
		 * @return mixed
		 */
		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stand-in for is_wp_error().
	 *
	 * @param mixed $value Value.
	 *
	 * @return bool
	 */
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

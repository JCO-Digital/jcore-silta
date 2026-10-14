<?php
/**
 * WordPress stand-ins for isolated media validation tests.
 *
 * @package Jcore\Silta
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Database fixture and guarded core stand-ins.
// phpcs:disable Universal.Namespaces -- Scoped fixture helpers coexist with guarded global core stand-ins.

namespace Jcore\Silta\Tests {
	/** Database stand-in for individually stored transfers and runner items. */
	final class MediaDatabaseStub {
		/**
		 * Table prefix.
		 *
		 * @var string
		 */
		public string $prefix = 'wp_';

		/**
		 * Last query error.
		 *
		 * @var string
		 */
		public string $last_error = '';

		/**
		 * Transfer rows keyed by UUID.
		 *
		 * @var array
		 */
		public array $transfers = array();

		/**
		 * Job item rows keyed by ID.
		 *
		 * @var array
		 */
		public array $items = array();

		/**
		 * Simulates failed writes or reads.
		 *
		 * @var bool
		 */
		public bool $fail = false;

		/**
		 * Simulates losing atomic consumption to another finalizer.
		 *
		 * @var bool
		 */
		public bool $lose_delete = false;

		/**
		 * Prepares the subset of SQL used by the media and job fixtures.
		 *
		 * @param string $sql     SQL.
		 * @param mixed  ...$args Arguments.
		 *
		 * @return string
		 */
		public function prepare( string $sql, mixed ...$args ): string {
			$args = is_array( $args[0] ?? null ) ? $args[0] : $args;
			return preg_replace_callback(
				'/%[isd]/',
				static function ( array $placeholder ) use ( &$args ): string {
					$value = array_shift( $args );
					return '%d' === $placeholder[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
				},
				$sql
			);
		}

		/**
		 * Inserts one manifest with a unique transfer key.
		 *
		 * @param string $table   Table.
		 * @param array  $row     Columns.
		 * @param array  $formats Formats.
		 *
		 * @return int|false
		 */
		public function insert( string $table, array $row, array $formats ): int|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Core signature.
			if ( $this->fail || isset( $this->transfers[ $row['transfer'] ] ) ) {
				return false;
			}
			$this->transfers[ $row['transfer'] ] = $row;
			return 1;
		}

		/**
		 * Reads a primary-key value or a peer count.
		 *
		 * @param string $sql SQL.
		 *
		 * @return mixed
		 */
		public function get_var( string $sql ): mixed {
			$this->last_error = $this->fail ? 'database unavailable' : '';
			if ( $this->fail ) {
				return null;
			}
			if ( str_contains( $sql, 'COUNT(*)' ) ) {
				preg_match( "/peer = '([^']+)'/", $sql, $match );
				return count( array_filter( $this->transfers, static fn( array $row ): bool => $row['peer'] === $match[1] ) );
			}
			preg_match( "/transfer = '([^']+)'/", $sql, $match );
			$row = $this->transfers[ $match[1] ] ?? null;
			return str_starts_with( $sql, 'SELECT manifest' ) ? ( $row['manifest'] ?? null ) : ( $row['transfer'] ?? null );
		}

		/**
		 * Returns UUIDs selected through the expiry index.
		 *
		 * @param string $sql SQL.
		 *
		 * @return array
		 */
		public function get_col( string $sql ): array {
			preg_match( '/created < (\d+)/', $sql, $match );
			return array_keys( array_filter( $this->transfers, static fn( array $row ): bool => $row['created'] < (int) $match[1] ) );
		}

		/**
		 * Deletes one row, returning its affected-row count.
		 *
		 * @param string $sql SQL.
		 *
		 * @return int|false
		 */
		public function query( string $sql ): int|false {
			if ( $this->fail ) {
				return false;
			}
			preg_match( "/transfer = '([^']+)'/", $sql, $match );
			$id = $match[1];
			if ( $this->lose_delete ) {
				unset( $this->transfers[ $id ] );
				return 0;
			}
			if ( ! isset( $this->transfers[ $id ] ) ) {
				return 0;
			}
			if ( preg_match( '/created < (\d+)/', $sql, $expiry ) && $this->transfers[ $id ]['created'] >= (int) $expiry[1] ) {
				return 0;
			}
			unset( $this->transfers[ $id ] );
			return 1;
		}

		/**
		 * Filters job items using the runner's column filters.
		 *
		 * @param string $sql    SQL.
		 * @param string $format Row format.
		 *
		 * @return array
		 */
		public function get_results( string $sql, string $format ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Core signature.
			$rows = array_values( $this->items );
			preg_match_all( "/(job_id|id|included|status|object_type|subtype|action) (?:= ('[^']*'|[0-9]+)|IN \(([^)]+)\))/", $sql, $matches, PREG_SET_ORDER );
			foreach ( $matches as $match ) {
				$values = array_map( static fn( string $value ): string => trim( $value, " '\t" ), explode( ',', $match[3] ?? $match[2] ) );
				$rows   = array_values( array_filter( $rows, static fn( array $row ): bool => in_array( (string) $row[ $match[1] ], $values, true ) ) );
			}
			if ( preg_match( '/LIMIT (\d+)/', $sql, $limit ) ) {
				$rows = array_slice( $rows, 0, (int) $limit[1] );
			}
			return $rows;
		}

		/**
		 * Reads an individual job item.
		 *
		 * @param string $sql    SQL.
		 * @param string $format Row format.
		 *
		 * @return array|null
		 */
		public function get_row( string $sql, string $format ): ?array {
			return $this->get_results( $sql, $format )[0] ?? null;
		}

		/**
		 * Persists a runner's item changes.
		 *
		 * @param string $table   Table.
		 * @param array  $changes Columns.
		 * @param array  $where   Item ID.
		 *
		 * @return int
		 */
		public function update( string $table, array $changes, array $where ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Core signature.
			$this->items[ $where['id'] ] = array_merge( $this->items[ $where['id'] ], $changes );
			return 1;
		}
	}
}

namespace Jcore\Silta\Sync {
	/**
	 * Allows a fixture to claim a destination immediately before publication.
	 *
	 * @param string $source Staged source.
	 * @param string $target Upload destination.
	 *
	 * @return bool
	 */
	function link( string $source, string $target ): bool {
		apply_filters( 'jcore_silta_media_test_link', null, $source, $target );
		return @\link( $source, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional collision fixture.
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_get_upload_dir' ) ) {
		/**
		 * Isolates media's upload directory from other test fixture helpers.
		 *
		 * @return array<string, string>
		 */
		function wp_get_upload_dir(): array {
			return array(
				'basedir' => $GLOBALS['jcore_silta_media_test_dir'] ?? sys_get_temp_dir(),
				'baseurl' => 'https://local.example/uploads',
			);
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_cache_delete' ) ) {
		/**
		 * No object cache is used in media fixtures.
		 *
		 * @param string $key   Cache key.
		 * @param string $group Cache group.
		 *
		 * @return bool
		 */
		function wp_cache_delete( string $key, string $group ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Core signature.
			return true;
		}
	}
}

namespace {

	require_once __DIR__ . '/wp-error.php';

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Guarded WordPress class stand-ins.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Guarded WordPress stand-ins.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Match core signatures.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Core function stand-ins.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Isolated temporary test files.

	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	if ( ! function_exists( 'current_user_can' ) ) {
		/**
		 * Grants administrator permission only to the runner import fixture.
		 *
		 * @param string $capability Capability.
		 *
		 * @return bool
		 */
		function current_user_can( string $capability ): bool {
			return 'manage_options' === $capability && ! empty( $GLOBALS['jcore_silta_media_test_admin'] );
		}
	}

	if ( ! function_exists( 'home_url' ) ) {
		/**
		 * Supplies the fixture site's home URL.
		 *
		 * @return string
		 */
		function home_url(): string {
			return 'https://local.example';
		}
	}

	if ( ! function_exists( 'wp_get_current_user' ) ) {
		/**
		 * Supplies an anonymous user for runner metadata.
		 *
		 * @return object
		 */
		function wp_get_current_user(): object {
			return new class() {
				/**
				 * Whether the fixture user has a profile.
				 *
				 * @return bool
				 */
				public function exists(): bool {
					return false;
				}
			};
		}
	}

	if ( ! function_exists( 'get_bloginfo' ) ) {
		/**
		 * Supplies the fixture site's name.
		 *
		 * @param string $field Requested field.
		 *
		 * @return string
		 */
		function get_bloginfo( string $field ): string {
			return 'Media test site';
		}
	}

	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}
	if ( ! defined( 'KB_IN_BYTES' ) ) {
		define( 'KB_IN_BYTES', 1024 );
	}
	if ( ! defined( 'MB_IN_BYTES' ) ) {
		define( 'MB_IN_BYTES', 1024 * 1024 );
	}
	if ( ! defined( 'GB_IN_BYTES' ) ) {
		define( 'GB_IN_BYTES', 1024 * MB_IN_BYTES );
	}

	if ( ! class_exists( 'WP_Post' ) ) {
		/**
		 * Attachment post used by undo fixtures.
		 */
		class WP_Post {
			/**
			 * Post type.
			 *
			 * @var string
			 */
			public string $post_type = 'attachment';
		}
	}

	if ( ! function_exists( 'get_post' ) ) {
		/**
		 * Returns the fixture attachment.
		 *
		 * @param int $id Post ID.
		 *
		 * @return WP_Post|null
		 */
		function get_post( int $id ): ?WP_Post {
			return $GLOBALS['jcore_silta_media_test_posts'][ $id ] ?? null;
		}
	}

	if ( ! function_exists( 'wp_update_post' ) ) {
		/**
		 * Records changes without deleting fixture files.
		 *
		 * @param array $data  Post fields.
		 * @param bool  $error Return errors.
		 *
		 * @return int
		 */
		function wp_update_post( array $data, bool $error = false ): int {
			$GLOBALS['jcore_silta_media_test_updated'][ $data['ID'] ] = $data;
			return $data['ID'];
		}
	}

	if ( ! function_exists( 'delete_post_meta' ) ) {
		/**
		 * Removes scheduled deletion metadata from the fixture attachment.
		 *
		 * @param int    $id  Post ID.
		 * @param string $key Meta key.
		 *
		 * @return bool
		 */
		function delete_post_meta( int $id, string $key ): bool {
			unset( $GLOBALS['jcore_silta_media_test_meta'][ $id ][ $key ] );
			return true;
		}
	}

	if ( ! function_exists( '__' ) ) {
		/**
		 * Returns untranslated text in tests.
		 *
		 * @param string $text   Text.
		 * @param string $domain Domain.
		 *
		 * @return string
		 */
		function __( string $text, string $domain = 'default' ): string {
			return $text;
		}
	}

	if ( ! function_exists( 'wp_get_upload_dir' ) ) {
		/**
		 * Returns the isolated upload directory.
		 *
		 * @return array<string, string>
		 */
		function wp_get_upload_dir(): array {
			return array(
				'basedir' => $GLOBALS['jcore_silta_media_test_dir'] ?? sys_get_temp_dir(),
				'baseurl' => 'https://local.example/uploads',
			);
		}
	}

	if ( ! function_exists( 'get_post_meta' ) ) {
		/**
		 * Returns test attachment meta.
		 *
		 * @param int    $id     ID.
		 * @param string $key    Meta key.
		 * @param bool   $single Single value.
		 *
		 * @return mixed
		 */
		function get_post_meta( int $id, string $key = '', bool $single = false ): mixed {
			return $GLOBALS['jcore_silta_media_test_meta'][ $id ][ $key ] ?? '';
		}
	}

	if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
		/**
		 * Returns test file metadata.
		 *
		 * @param int $id Attachment.
		 *
		 * @return mixed
		 */
		function wp_get_attachment_metadata( int $id ): mixed {
			return get_post_meta( $id, '_wp_attachment_metadata', true );
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		/**
		 * Reads the test option store.
		 *
		 * @param string $key     Key.
		 * @param mixed  $fallback Default.
		 *
		 * @return mixed
		 */
		function get_option( string $key, mixed $fallback = false ): mixed {
			return $GLOBALS['jcore_silta_test_options'][ $key ] ?? $fallback;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		/**
		 * Updates a test option.
		 *
		 * @param string $key      Key.
		 * @param mixed  $value    Value.
		 * @param bool   $autoload Autoload.
		 *
		 * @return bool
		 */
		function update_option( string $key, mixed $value, bool $autoload = true ): bool {
			if ( get_option( $key ) === $value ) {
				return false;
			}
			$GLOBALS['jcore_silta_test_options'][ $key ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'delete_option' ) ) {
		/**
		 * Removes a legacy option from the fixture store.
		 *
		 * @param string $key Option name.
		 *
		 * @return bool
		 */
		function delete_option( string $key ): bool {
			$exists = array_key_exists( $key, $GLOBALS['jcore_silta_test_options'] );
			unset( $GLOBALS['jcore_silta_test_options'][ $key ] );
			return $exists;
		}
	}

	if ( ! function_exists( 'wp_generate_uuid4' ) ) {
		/**
		 * Generates an isolated test transfer UUID.
		 *
		 * @return string
		 */
		function wp_generate_uuid4(): string {
			return '12345678-1234-4234-8234-' . bin2hex( random_bytes( 6 ) );
		}
	}

	if ( ! function_exists( 'wp_is_uuid' ) ) {
		/**
		 * Validates a UUID.
		 *
		 * @param string $value UUID.
		 *
		 * @return bool
		 */
		function wp_is_uuid( string $value ): bool {
			return (bool) preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $value );
		}
	}

	if ( ! function_exists( 'validate_file' ) ) {
		/**
		 * Rejects traversal and drive-letter paths like core.
		 *
		 * @param string $file Path.
		 *
		 * @return int
		 */
		function validate_file( string $file ): int {
			return str_contains( $file, '../' ) || '..' === $file || str_contains( $file, ':' ) ? 1 : 0;
		}
	}

	if ( ! function_exists( 'sanitize_file_name' ) ) {
		/**
		 * Cleans the ASCII test filenames and executable inner extensions.
		 *
		 * @param string $name Filename.
		 *
		 * @return string
		 */
		function sanitize_file_name( string $name ): string {
			$clean = preg_replace( '/[\\x00-\\x20?<>:"\\\\|*]/', '', $name );
			$clean = preg_replace( '/\\.(php|phtml|phar)\\./i', '.$1_.', $clean );
			return trim( $clean, '.-_' );
		}
	}

	if ( ! function_exists( 'get_allowed_mime_types' ) ) {
		/**
		 * Core-like types used by the fixtures, including filtered unsafe types.
		 *
		 * @return array<string, string>
		 */
		function get_allowed_mime_types(): array {
			return apply_filters(
				'jcore_silta_media_test_mimes',
				array(
					'png'  => 'image/png',
					'jpg'  => 'image/jpeg',
					'webp' => 'image/webp',
					'pdf'  => 'application/pdf',
					'php'  => 'application/x-httpd-php',
					'html' => 'text/html',
					'svg'  => 'image/svg+xml',
				)
			);
		}
	}

	if ( ! function_exists( 'wp_check_filetype' ) ) {
		/**
		 * Checks the extension against the supplied allowlist.
		 *
		 * @param string     $file  Filename.
		 * @param array|null $mimes Allowlist.
		 *
		 * @return array<string, string|false>
		 */
		function wp_check_filetype( string $file, ?array $mimes = null ): array {
			$mimes = $mimes ?? get_allowed_mime_types();
			$ext   = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			return array(
				'ext'  => isset( $mimes[ $ext ] ) ? $ext : false,
				'type' => $mimes[ $ext ] ?? false,
			);
		}
	}

	if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
		/**
		 * Inspects image bytes; other test bytes must be a PDF header.
		 *
		 * @param string $file     Staged file.
		 * @param string $filename Original filename.
		 * @param array  $mimes    Allowed types.
		 *
		 * @return array<string, string|false>
		 */
		function wp_check_filetype_and_ext( string $file, string $filename, array $mimes ): array {
			$type = wp_check_filetype( $filename, $mimes );
			if ( str_starts_with( (string) $type['type'], 'image/' ) ) {
				$image  = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image fixture.
				$actual = $image['mime'] ?? false;
			} else {
				$actual = str_starts_with( (string) file_get_contents( $file ), '%PDF-' ) ? 'application/pdf' : false;
			}
			return array(
				'ext'             => $actual === $type['type'] ? $type['ext'] : false,
				'type'            => $actual === $type['type'] ? $actual : false,
				'proper_filename' => false,
			);
		}
	}

	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		/**
		 * Creates a test directory.
		 *
		 * @param string $dir Directory.
		 *
		 * @return bool
		 */
		function wp_mkdir_p( string $dir ): bool {
			return is_dir( $dir ) || mkdir( $dir, 0777, true );
		}
	}

	if ( ! function_exists( 'wp_delete_file' ) ) {
		/**
		 * Removes a test file.
		 *
		 * @param string $file File.
		 *
		 * @return void
		 */
		function wp_delete_file( string $file ): void {
			unlink( $file );
		}
	}

	if ( ! function_exists( 'wp_unique_filename' ) ) {
		/**
		 * Chooses a unique test filename.
		 *
		 * @param string $dir  Directory.
		 * @param string $name Filename.
		 *
		 * @return string
		 */
		function wp_unique_filename( string $dir, string $name ): string {
			$ext  = pathinfo( $name, PATHINFO_EXTENSION );
			$root = pathinfo( $name, PATHINFO_FILENAME );
			$free = $name;
			$i    = 1;
			while ( file_exists( $dir . '/' . $free ) ) {
				$free = $root . '-' . $i . '.' . $ext;
				++$i;
			}
			return $free;
		}
	}
}

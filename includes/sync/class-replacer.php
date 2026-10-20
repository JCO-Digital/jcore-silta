<?php
/**
 * Serialization-safe search and replace.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces strings in plain text, JSON and PHP-serialized data.
 *
 * Serialized data is rewritten token by token, recomputing string lengths, and
 * is never unserialized. Object, enum and reference tokens are refused. The
 * same walk can pass every string through a callback instead, see mapping().
 */
final class Replacer {

	/**
	 * Find => replace pairs.
	 *
	 * @var array<string, string>
	 */
	private array $pairs = array();

	/**
	 * Callback that rewrites each plain string instead of the pairs, see mapping().
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $map = null;

	/**
	 * Creates a replacer from [find, replace] pairs. Earlier pairs win on
	 * duplicates; the longest match is replaced first.
	 *
	 * @param array<int, array{0: string, 1: string}> $pairs Pairs.
	 */
	public function __construct( array $pairs = array() ) {
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || ! is_string( $pair[0] ?? null ) || ! is_string( $pair[1] ?? null ) ) {
				continue;
			}
			$find = (string) ( $pair[0] ?? '' );
			if ( '' === $find || isset( $this->pairs[ $find ] ) ) {
				continue;
			}
			$replace = (string) ( $pair[1] ?? '' );
			if ( $find !== $replace ) {
				$this->pairs[ $find ] = $replace;
			}
		}
	}

	/**
	 * Builds the replacer for a transfer between two sites: the uploads and
	 * home URLs (in plain, JSON-escaped and URL-encoded forms, with either
	 * scheme on the source side) and the user's rules.
	 *
	 * @param array<string, string>                   $source Source `home` and `uploads` URLs.
	 * @param array<string, string>                   $target Target `home` and `uploads` URLs.
	 * @param array<int, array{0: string, 1: string}> $rules  User rules.
	 *
	 * @return self
	 */
	public static function between( array $source, array $target, array $rules = array() ): self {
		$pairs = array();

		foreach ( array( 'uploads', 'home' ) as $which ) {
			$from = untrailingslashit( (string) ( $source[ $which ] ?? '' ) );
			$to   = untrailingslashit( (string) ( $target[ $which ] ?? '' ) );
			if ( '' !== $from && '' !== $to && $from !== $to ) {
				$pairs = array_merge( $pairs, self::url_variants( $from, $to ) );
			}
		}

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! is_string( $rule[0] ?? null ) || ! is_string( $rule[1] ?? null ) ) {
				continue;
			}
			$pairs = array_merge( $pairs, self::variants( (string) $rule[0], (string) $rule[1] ) );
		}

		return new self( $pairs );
	}

	/**
	 * Builds a replacer that passes every plain string through a callback:
	 * the whole value, or each string inside serialized data (array keys
	 * included, nested serialized strings walked in turn), with the lengths
	 * recomputed. The callback is not carried over by with().
	 *
	 * @param callable $callback Callback( string $text ): string.
	 *
	 * @return self
	 */
	public static function mapping( callable $callback ): self {
		$replacer      = new self();
		$replacer->map = \Closure::fromCallable( $callback );

		return $replacer;
	}

	/**
	 * Returns a replacer with extra pairs tried before this one's.
	 *
	 * @param array<int, array{0: string, 1: string}> $pairs Extra pairs.
	 *
	 * @return self
	 */
	public function with( array $pairs ): self {
		$merged = $pairs;
		foreach ( $this->pairs as $find => $replace ) {
			$merged[] = array( $find, $replace );
		}

		return new self( $merged );
	}

	/**
	 * Returns pairs for a URL in every encoding it appears in.
	 *
	 * @param string $from Source URL.
	 * @param string $to   Target URL.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function url_variants( string $from, string $to ): array {
		$froms = array( $from );
		if ( str_starts_with( $from, 'http://' ) ) {
			$froms[] = 'https://' . substr( $from, 7 );
		} elseif ( str_starts_with( $from, 'https://' ) ) {
			$froms[] = 'http://' . substr( $from, 8 );
		}

		$pairs = array();
		foreach ( $froms as $variant ) {
			$pairs = array_merge( $pairs, self::variants( $variant, $to ) );
		}

		// Protocol-relative references.
		$bare_from = preg_replace( '#^https?:#', '', $from );
		$bare_to   = preg_replace( '#^https?:#', '', $to );
		if ( is_string( $bare_from ) && is_string( $bare_to ) && str_starts_with( $bare_from, '//' ) ) {
			$pairs = array_merge( $pairs, self::variants( $bare_from, $bare_to ) );
		}

		return $pairs;
	}

	/**
	 * Returns a pair plus its JSON-escaped and URL-encoded forms.
	 *
	 * @param string $from Find.
	 * @param string $to   Replace.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function variants( string $from, string $to ): array {
		$pairs = array( array( $from, $to ) );

		if ( str_contains( $from, '/' ) ) {
			$pairs[] = array( str_replace( '/', '\\/', $from ), str_replace( '/', '\\/', $to ) );
		}

		$encoded = rawurlencode( $from );
		if ( $encoded !== $from ) {
			$pairs[] = array( $encoded, rawurlencode( $to ) );
		}

		return $pairs;
	}

	/**
	 * Whether there is anything to replace.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return empty( $this->pairs ) && null === $this->map;
	}

	/**
	 * Returns the pairs, for display.
	 *
	 * @return array<string, string>
	 */
	public function pairs(): array {
		return $this->pairs;
	}

	/**
	 * Replaces in a string, which may be serialized.
	 *
	 * @param string $value Value.
	 * @param int    $depth Depth across nested serialized strings.
	 *
	 * @return string
	 * @throws \UnexpectedValueException When serialized data is unsafe.
	 */
	public function string( string $value, int $depth = 0 ): string {
		if ( $depth > 64 ) {
			throw new \UnexpectedValueException( esc_html__( 'Serialized data exceeds the nesting limit.', 'jcore-silta' ) );
		}
		if ( '' === $value ) {
			return $value;
		}

		if ( self::is_serialized( trim( $value ) ) ) {
			$result = $this->serialized( trim( $value ), $depth );
			if ( null !== $result ) {
				return $result;
			}
		}

		return null === $this->map ? strtr( $value, $this->pairs ) : (string) ( $this->map )( $value );
	}

	/**
	 * Whether a string is PHP-serialized data.
	 *
	 * @param string $value Value.
	 *
	 * @return bool
	 */
	public static function is_serialized( string $value ): bool {
		// A cheap prefix test; the tokenizer validates the rest and falls back to
		// plain replacement when the value turns out not to be serialized after all.
		// Core's is_serialized() misses custom-serialized objects (`C:`).
		return 1 === preg_match( '/^(?:N;|[bidsSaOCERr]:)/', $value ) && in_array( substr( $value, -1 ), array( ';', '}' ), true );
	}

	/**
	 * Rewrites serialized data token by token.
	 *
	 * @param string $data  Serialized data.
	 * @param int    $depth Depth across nested serialized strings.
	 *
	 * @return string|null Null when the data is malformed.
	 * @throws \UnexpectedValueException When serialized data is unsafe.
	 */
	private function serialized( string $data, int $depth ): ?string {
		$out    = '';
		$pos    = 0;
		$unsafe = false;

		$valid = $this->token( $data, $pos, $out, $depth, $unsafe );

		if ( $unsafe ) {
			throw new \UnexpectedValueException( esc_html__( 'Serialized objects, enums and references cannot be synced.', 'jcore-silta' ) );
		}
		if ( $valid && strlen( $data ) === $pos ) {
			return $out;
		}

		// PHP accepts forms the tokenizer doesn't (`R:01`, trailing data…), so
		// what it stops at may still hide an object from the checks above. Only
		// data PHP itself can't read passes on as plain text.
		if ( self::php_reads( $data ) ) {
			throw new \UnexpectedValueException( esc_html__( 'Serialized data in an unsupported form cannot be synced.', 'jcore-silta' ) );
		}

		return null;
	}

	/**
	 * Whether PHP would unserialize a string, without instantiating anything.
	 *
	 * @param string $data Serialized-looking data.
	 *
	 * @return bool
	 */
	private static function php_reads( string $data ): bool {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Classes are not allowed; only checks whether the data parses.
		return 'b:0;' === $data || false !== @unserialize( $data, array( 'allowed_classes' => false ) );
	}

	/**
	 * Copies one serialized value at $pos into $out, replacing inside strings.
	 *
	 * @param string $data  Serialized data.
	 * @param int    $pos   Position, advanced past the value.
	 * @param string $out   Output, appended to.
	 * @param int    $depth Nesting depth.
	 * @param bool   $unsafe Whether an unsafe token was found.
	 *
	 * @return bool False when the data is malformed.
	 * @throws \UnexpectedValueException When the nesting limit is exceeded.
	 */
	private function token( string $data, int &$pos, string &$out, int $depth, bool &$unsafe ): bool {
		if ( $depth > 64 ) {
			throw new \UnexpectedValueException( esc_html__( 'Serialized data exceeds the nesting limit.', 'jcore-silta' ) );
		}
		if ( ! isset( $data[ $pos ] ) ) {
			return false;
		}

		switch ( $data[ $pos ] ) {
			case 'N':
				if ( 'N;' !== substr( $data, $pos, 2 ) ) {
					return false;
				}
				$out .= 'N;';
				$pos += 2;

				return true;

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $data, ';', $pos );
				if ( false === $end || ':' !== ( $data[ $pos + 1 ] ?? '' ) ) {
					return false;
				}
				$scalar = substr( $data, $pos, $end - $pos + 1 );
				if ( ! preg_match( '/^(?:b:[01];|i:[+-]?\d+;|d:(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:E[+-]?\d+)?|NAN|-?INF);|[Rr]:[1-9]\d*;)$/i', $scalar ) ) {
					return false;
				}
				$unsafe = $unsafe || in_array( $data[ $pos ], array( 'R', 'r' ), true );
				$out   .= $scalar;
				$pos    = $end + 1;

				return true;

			case 's':
			case 'S':
				if ( ! preg_match( '/\G[sS]:(\d+):"/', $data, $match, 0, $pos ) ) {
					return false;
				}
				$length = (int) $match[1];
				$start  = $pos + strlen( $match[0] );
				if ( $length > strlen( $data ) - $start ) {
					return false;
				}
				$end    = $start + $length;
				$string = substr( $data, $start, $length );
				if ( 'S' === $data[ $pos ] ) {
					// PHP also accepts hex-escaped string tokens inside arrays.
					$string = '';
					$end    = $start;
					if ( $length > strlen( $data ) - $start ) {
						return false;
					}
					for ( $i = 0; $i < $length; ++$i ) {
						if ( ! isset( $data[ $end ] ) ) {
							return false;
						}
						if ( '\\' === $data[ $end ] ) {
							$hex = substr( $data, $end + 1, 2 );
							if ( 2 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
								return false;
							}
							$string .= chr( hexdec( $hex ) );
							$end    += 3;
						} else {
							$string .= $data[ $end++ ];
						}
					}
				}
				if ( '";' !== substr( $data, $end, 2 ) ) {
					return false;
				}
				$replaced = $this->string( $string, $depth + 1 );
				$out     .= 's:' . strlen( $replaced ) . ':"' . $replaced . '";';
				$pos      = $end + 2;

				return true;

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $data, $match, 0, $pos ) ) {
					return false;
				}
				$out .= $match[0];
				$pos += strlen( $match[0] );

				return $this->members( $data, $pos, $out, $depth, (int) $match[1], $unsafe );

			case 'O':
				if ( ! preg_match( '/\GO:(\d+):"/', $data, $match, 0, $pos ) ) {
					return false;
				}
				$unsafe = true;
				$start  = $pos + strlen( $match[0] );
				if ( (int) $match[1] > strlen( $data ) - $start ) {
					return false;
				}
				$class = substr( $data, $start, (int) $match[1] );
				if ( ! preg_match( '/\G":(\d+):\{/', $data, $count, 0, $start + (int) $match[1] ) ) {
					return false;
				}
				$out .= $match[0] . $class . $count[0];
				$pos  = $start + (int) $match[1] + strlen( $count[0] );

				return $this->members( $data, $pos, $out, $depth, (int) $count[1], $unsafe );

			case 'C':
				// Validate the opaque payload without decoding it.
				if ( ! preg_match( '/\GC:(\d+):"/', $data, $match, 0, $pos ) ) {
					return false;
				}
				$unsafe = true;
				$start  = $pos + strlen( $match[0] );
				if ( (int) $match[1] > strlen( $data ) - $start ) {
					return false;
				}
				$start += (int) $match[1];
				if ( ! preg_match( '/\G":(\d+):\{/', $data, $length, 0, $start ) ) {
					return false;
				}
				$start += strlen( $length[0] );
				if ( (int) $length[1] > strlen( $data ) - $start ) {
					return false;
				}
				$end = $start + (int) $length[1];
				if ( '}' !== ( $data[ $end ] ?? '' ) ) {
					return false;
				}
				$out .= substr( $data, $pos, $end - $pos + 1 );
				$pos  = $end + 1;

				return true;

			case 'E':
				if ( ! preg_match( '/\GE:(\d+):"/', $data, $match, 0, $pos ) ) {
					return false;
				}
				$unsafe = true;
				$start  = $pos + strlen( $match[0] );
				if ( (int) $match[1] > strlen( $data ) - $start ) {
					return false;
				}
				$end = $start + (int) $match[1];
				if ( '";' !== substr( $data, $end, 2 ) ) {
					return false;
				}
				$out .= substr( $data, $pos, $end - $pos + 2 );
				$pos  = $end + 2;

				return true;
		}

		return false;
	}

	/**
	 * Copies the key/value pairs of an array or object and its closing brace.
	 *
	 * @param string $data  Serialized data.
	 * @param int    $pos   Position, advanced past the closing brace.
	 * @param string $out   Output, appended to.
	 * @param int    $depth Nesting depth.
	 * @param int    $count Number of members.
	 * @param bool   $unsafe Whether an unsafe token was found.
	 *
	 * @return bool
	 */
	private function members( string $data, int &$pos, string &$out, int $depth, int $count, bool &$unsafe ): bool {
		for ( $i = 0; $i < $count * 2; $i++ ) {
			if ( ! $this->token( $data, $pos, $out, $depth + 1, $unsafe ) ) {
				return false;
			}
		}

		if ( '}' !== ( $data[ $pos ] ?? '' ) ) {
			return false;
		}
		$out .= '}';
		++$pos;

		return true;
	}
}

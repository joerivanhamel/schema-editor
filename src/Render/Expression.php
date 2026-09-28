<?php
/**
 * The placeholder language: "{{ path | filter:arg || other.path || 'literal' }}".
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Render;

use DateTimeImmutable;
use Throwable;

/**
 * Parses and evaluates one expression. Alternatives are tried left to right and the first non-empty wins; each
 * alternative is a path (or a quoted literal) followed by optional filters. Strings are normalised to plain text
 * (tags stripped, entities decoded, whitespace collapsed) unless the "raw" filter is present.
 */
final class Expression {

	public const PLACEHOLDER = '/\{\{\s*(.+?)\s*\}\}/s';

	public function __construct( private readonly ValueResolverInterface $resolver ) {
	}

	/**
	 * Evaluate an expression (the text between the braces).
	 *
	 * @return mixed null when every alternative is empty.
	 */
	public function evaluate( string $expression, Context $context ): mixed {
		foreach ( $this->splitAlternatives( $expression ) as $alternative ) {
			$value = $this->evaluateAlternative( $alternative, $context );
			if ( ! self::isEmpty( $value ) ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * Whether a resolved value counts as "nothing": null, empty string, empty array. false and 0 are values.
	 */
	public static function isEmpty( mixed $value ): bool {
		return null === $value || '' === $value || [] === $value || false === $value;
	}

	/**
	 * Split on "||" outside quotes.
	 *
	 * @return array<int,string>
	 */
	private function splitAlternatives( string $expression ): array {
		return array_map( 'trim', $this->splitOutsideQuotes( $expression, '||' ) );
	}

	/**
	 * Path or literal, then filters.
	 */
	private function evaluateAlternative( string $alternative, Context $context ): mixed {
		$parts = array_map( 'trim', $this->splitOutsideQuotes( $alternative, '|' ) );
		$head  = array_shift( $parts ) ?? '';
		$raw   = in_array( 'raw', $parts, true );
		if ( '' === $head ) {
			return null;
		}
		if ( self::isQuoted( $head ) ) {
			$value = substr( $head, 1, -1 );
		} else {
			$value = $this->resolver->resolve( $head, $context );
		}
		if ( ! $raw ) {
			$value = self::plain( $value );
		}
		foreach ( $parts as $filter ) {
			if ( 'raw' === $filter ) {
				continue;
			}
			$value = $this->applyFilter( $filter, $value );
		}
		return $value;
	}

	/**
	 * Built-in filters; anything else is handed to the resolver.
	 */
	private function applyFilter( string $filter, mixed $value ): mixed {
		$name     = $filter;
		$argument = null;
		$colon    = strpos( $filter, ':' );
		if ( false !== $colon ) {
			$name     = trim( substr( $filter, 0, $colon ) );
			$argument = trim( substr( $filter, $colon + 1 ) );
			if ( self::isQuoted( $argument ) ) {
				$argument = substr( $argument, 1, -1 );
			}
		}
		if ( self::isEmpty( $value ) && ! in_array( $name, [ 'count', 'default' ], true ) ) {
			return $value;
		}
		switch ( $name ) {
			case 'plain':
				return self::plain( $value );
			case 'year':
				return is_scalar( $value ) && preg_match( '/\b(\d{4})\b/', (string) $value, $m ) ? $m[1] : null;
			case 'int':
				return is_numeric( $value ) ? (int) $value : ( is_scalar( $value ) && preg_match( '/-?\d+/', (string) $value, $m ) ? (int) $m[0] : null );
			case 'float':
				return is_numeric( $value ) ? (float) $value : null;
			case 'bool':
				return in_array( is_scalar( $value ) ? strtolower( (string) $value ) : $value, [ '1', 'true', 'yes', 'on', 1, true ], true );
			case 'lower':
				return is_string( $value ) ? mb_strtolower( $value ) : $value;
			case 'upper':
				return is_string( $value ) ? mb_strtoupper( $value ) : $value;
			case 'ucfirst':
				return is_string( $value ) ? mb_strtoupper( mb_substr( $value, 0, 1 ) ) . mb_substr( $value, 1 ) : $value;
			case 'first_word':
				return is_string( $value ) ? (string) ( preg_split( '/\s+/', trim( $value ) )[0] ?? '' ) : $value;
			case 'slug':
				if ( ! is_string( $value ) ) {
					return $value;
				}
				$slug = mb_strtolower( trim( $value ) );
				$slug = preg_replace( '/[^\p{L}\p{N}]+/u', '-', $slug ) ?? '';
				return trim( $slug, '-' );
			case 'words':
				if ( ! is_string( $value ) ) {
					return $value;
				}
				$words = preg_split( '/\s+/', trim( $value ) ) ?: [];
				$limit = max( 1, (int) $argument );
				return count( $words ) > $limit ? implode( ' ', array_slice( $words, 0, $limit ) ) . '…' : $value;
			case 'chars':
				if ( ! is_string( $value ) ) {
					return $value;
				}
				$limit = max( 1, (int) $argument );
				return mb_strlen( $value ) > $limit ? rtrim( mb_substr( $value, 0, $limit ) ) . '…' : $value;
			case 'join':
				// Unquoted arguments are trimmed, so "join:, " would lose its space: quote it (join:', ') or rely on the default.
				return is_array( $value ) ? implode( ( null === $argument || '' === $argument ) ? ', ' : $argument, array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : $value;
			case 'first':
				return is_array( $value ) ? ( array_values( $value )[0] ?? null ) : $value;
			case 'count':
				return is_array( $value ) ? count( $value ) : ( self::isEmpty( $value ) ? 0 : 1 );
			case 'date':
				if ( ! is_scalar( $value ) ) {
					return null;
				}
				try {
					$date = is_numeric( $value ) ? ( new DateTimeImmutable() )->setTimestamp( (int) $value ) : new DateTimeImmutable( (string) $value );
					return $date->format( $argument ?: 'c' );
				} catch ( Throwable ) {
					return null;
				}
			case 'default':
				return self::isEmpty( $value ) ? $argument : $value;
			case 'youtube_id':
				return is_string( $value ) ? self::youtubeId( $value ) : null;
			case 'youtube_embed':
				$id = is_string( $value ) ? self::youtubeId( $value ) : null;
				return $id ? 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $id ) : null;
			case 'youtube_watch':
				$id = is_string( $value ) ? self::youtubeId( $value ) : null;
				return $id ? 'https://www.youtube.com/watch?v=' . rawurlencode( $id ) : null;
			case 'iso_duration':
				// "1:24" or "0:18" or "1:02:03" or plain seconds to ISO 8601.
				if ( ! is_scalar( $value ) ) {
					return null;
				}
				$text = trim( (string) $value );
				if ( preg_match( '/^PT/', $text ) ) {
					return $text;
				}
				$parts   = array_map( 'intval', explode( ':', $text ) );
				$seconds = 0;
				foreach ( $parts as $part ) {
					$seconds = $seconds * 60 + $part;
				}
				if ( $seconds <= 0 ) {
					return null;
				}
				return sprintf( 'PT%dM%dS', intdiv( $seconds, 60 ), $seconds % 60 );
			default:
				return $this->resolver->filter( $name, $value, $argument );
		}
	}

	/**
	 * The video ID from a bare ID or any YouTube URL form (watch?v=, youtu.be/, /embed/, /shorts/, /live/).
	 */
	public static function youtubeId( string $value ): ?string {
		$value = trim( $value );
		if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/))([A-Za-z0-9_-]{11})~', $value, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Plain text: tags stripped, entities decoded, whitespace collapsed. Arrays are cleaned element by element.
	 */
	public static function plain( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			$text = strip_tags( $value );
			$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$text = preg_replace( '/[ \t\r\n]+/u', ' ', $text ) ?? $text;
			return trim( $text );
		}
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'plain' ], $value );
		}
		return $value;
	}

	private static function isQuoted( string $text ): bool {
		$length = strlen( $text );
		return $length >= 2 && ( ( '\'' === $text[0] && '\'' === $text[ $length - 1 ] ) || ( '"' === $text[0] && '"' === $text[ $length - 1 ] ) );
	}

	/**
	 * Split on a separator that is not inside single or double quotes.
	 *
	 * @return array<int,string>
	 */
	private function splitOutsideQuotes( string $text, string $separator ): array {
		$out     = [];
		$current = '';
		$quote   = '';
		$length  = strlen( $text );
		$sep_len = strlen( $separator );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $text[ $i ];
			if ( '' !== $quote ) {
				$current .= $char;
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '\'' === $char || '"' === $char ) {
				$quote    = $char;
				$current .= $char;
				continue;
			}
			if ( substr( $text, $i, $sep_len ) === $separator ) {
				$out[]   = $current;
				$current = '';
				$i      += $sep_len - 1;
				continue;
			}
			$current .= $char;
		}
		$out[] = $current;
		return $out;
	}
}

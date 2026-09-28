<?php
/**
 * A resolver backed by plain arrays, for tests and the CLI dry-run.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Render;

/**
 * Data shape: [ 'post' => [...], 'acf' => [...], 'option' => [...], 'site' => [...], 'page' => [...],
 * 'repeat' => [ [...], ... ] ]. request.* reads the context's request (a RequestContext or an array), else data['request'].
 * post.* reads the context's post when it is an array, else data['post']; item.* and parent.* read the context.
 * A path segment may contain "*" and matches the first key that fits the pattern (fnmatch).
 */
final class ArrayResolver implements ValueResolverInterface {

	/**
	 * @param array<string,mixed> $data Namespaced data.
	 */
	public function __construct( private readonly array $data = [] ) {
	}

	public function resolve( string $path, Context $context ): mixed {
		$segments  = explode( '.', $path );
		$namespace = array_shift( $segments );
		$source    = match ( $namespace ) {
			'post'   => is_array( $context->post ) ? $context->post : ( $this->data['post'] ?? null ),
			'item'   => $context->item,
			'parent' => $context->parent,
			'request' => $context->request instanceof \JoeriVanhamel\SchemaEditor\Template\RequestContext ? $context->request->toArray() : ( $context->request ?? ( $this->data['request'] ?? null ) ),
			default  => $this->data[ $namespace ] ?? null,
		};
		return self::walk( $source, $segments );
	}

	public function filter( string $name, mixed $value, ?string $argument ): mixed {
		if ( 'image' === $name ) {
			if ( is_array( $value ) ) {
				return $value['url'] ?? null;
			}
			return is_string( $value ) ? $value : null;
		}
		return $value;
	}

	public function repeat( array $query, Context $context ): array {
		$items = $this->data['repeat'] ?? [];
		return array_map( static fn( mixed $item ): Context => new Context( $item, null, $context->post ), array_values( $items ) );
	}

	/**
	 * Walk segments into nested arrays; "*" segments match the first fitting key.
	 *
	 * @param array<int,string> $segments Remaining path.
	 */
	public static function walk( mixed $value, array $segments ): mixed {
		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) ) {
				return null;
			}
			if ( array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
				continue;
			}
			if ( str_contains( $segment, '*' ) ) {
				$found = null;
				foreach ( $value as $key => $candidate ) {
					if ( fnmatch( $segment, (string) $key ) && ! Expression::isEmpty( $candidate ) ) {
						$found = $candidate;
						break;
					}
				}
				$value = $found;
				continue;
			}
			return null;
		}
		return $value;
	}
}

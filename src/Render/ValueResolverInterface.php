<?php
/**
 * The seam between the template renderer and the data source.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Render;

/**
 * Resolves placeholder paths ("post.title", "acf.hero_image", "option.sd_legal_name") to values, runs the
 * data-dependent filters ("image"), and answers "@repeat" and "@each" queries. WordPressResolver is the real one;
 * ArrayResolver serves the tests.
 */
interface ValueResolverInterface {

	/**
	 * Resolve a dotted path in a context. Return null (or '' or []) when there is nothing.
	 *
	 * @param string  $path    Dotted path, first segment is the namespace (post, acf, option, ...).
	 * @param Context $context Current context (post, item, parent).
	 * @return mixed Scalar, array or null.
	 */
	public function resolve( string $path, Context $context ): mixed;

	/**
	 * Apply a filter the renderer does not implement itself (image sizes, permalinks). Return the value unchanged
	 * for unknown filters.
	 *
	 * @param string      $name     Filter name.
	 * @param mixed       $value    Value to filter.
	 * @param string|null $argument Filter argument after the colon, if any.
	 * @return mixed
	 */
	public function filter( string $name, mixed $value, ?string $argument ): mixed;

	/**
	 * Items for a "@repeat" node: one context per item.
	 *
	 * @param array<string,mixed> $query   Query arguments (WP_Query shape).
	 * @param Context             $context Outer context.
	 * @return array<int,Context>
	 */
	public function repeat( array $query, Context $context ): array;
}

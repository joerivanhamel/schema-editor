<?php
/**
 * Turns a template's node definitions into concrete graph operations for one request.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Render;

use JoeriVanhamel\SchemaEditor\Template\Template;

/**
 * Rendering rules, which are what make the output safe to print without anyone checking it by hand:
 *
 *  - A property whose value resolves to nothing is omitted. A string with several placeholders is omitted when
 *    any of them is empty (no "Home › " leftovers).
 *  - An object with nothing left but "@type" is omitted; an empty list is omitted.
 *  - A node that lacks a property named in "@required" is omitted entirely.
 *  - "@if": the node (or a nested object) is only rendered when the expression is non-empty. A list of
 *    expressions must all hold. Comparisons ("post.id == rankmath.titles.local_seo_about_page") yield true or
 *    nothing.
 *  - "@repeat": the node is rendered once per query result, with that result as "post" and the page as "parent".
 *  - "@each": on a node, the node is rendered once per entry; inside a value, an array is built with one object
 *    per entry. The entries come from an array-valued expression (each entry is "item") or from a query object
 *    in the "@repeat" shape (each result is "post", the page is "parent"). loop.index is the 1-based position.
 *  - A list entry that renders to a list (a placeholder yielding a list, or an "@each" object) is spliced into
 *    the surrounding list: JSON-LD has no lists of lists.
 *  - "@single": true on an "@each" value prints a list of exactly one entry as that entry.
 *  - A template's "vars" are evaluated once per render, in order, and read as var.NAME.
 *
 * Directives: @op (add | merge | remove), @key, @match, @required, @if, @repeat, @each.
 */
final class Renderer {

	private const DIRECTIVES = [ '@op', '@key', '@match', '@required', '@if', '@repeat', '@each', '@single' ];

	private readonly Expression $expression;

	public function __construct( private readonly ValueResolverInterface $resolver ) {
		$this->expression = new Expression( $resolver );
	}

	/**
	 * Render every node of a template.
	 *
	 * @return array<int,array<string,mixed>> Operations: ['op' => 'add', 'key' => string, 'node' => array]
	 *                                       | ['op' => 'merge', 'match' => string, 'properties' => array]
	 *                                       | ['op' => 'remove', 'match' => string, 'properties' => array|null].
	 */
	public function render( Template $template, Context $context ): array {
		$context    = $this->withVars( $template->vars, $context );
		$operations = [];
		foreach ( $template->nodes as $index => $node ) {
			foreach ( $this->renderNode( $node, $context, $index ) as $operation ) {
				$operations[] = $operation;
			}
		}
		return $operations;
	}

	/**
	 * Evaluate a template's variables in order; each may use the ones before it.
	 *
	 * @param array<string,mixed> $vars Name => expression (string) or value definition.
	 */
	private function withVars( array $vars, Context $context ): Context {
		$values = $context->vars;
		foreach ( $vars as $name => $definition ) {
			$scope           = $context->withVars( $values );
			$values[ $name ] = is_string( $definition ) ? $this->evaluate( $definition, $scope ) : $this->renderValue( $definition, $scope );
		}
		return $context->withVars( $values );
	}

	/**
	 * Whether a condition holds: one expression, or a list of expressions that must all hold.
	 */
	private function holds( mixed $condition, Context $context ): bool {
		foreach ( is_array( $condition ) ? $condition : [ $condition ] as $expression ) {
			if ( ! is_string( $expression ) || Expression::isEmpty( $this->evaluate( $expression, $context ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The contexts an "@each" produces: one per entry of an array-valued expression (as "item"), or one per result
	 * of a query object (as "post", with the current post as "parent").
	 *
	 * @return array<int,Context>
	 */
	private function eachContexts( mixed $spec, Context $context ): array {
		$out = [];
		if ( is_array( $spec ) ) {
			foreach ( $this->resolver->repeat( $spec, $context ) as $i => $item_context ) {
				$out[] = new Context( $item_context->post, $item_context->item, $item_context->parent, $context->vars, $i + 1, $context->request );
			}
			return $out;
		}
		$items = is_string( $spec ) ? $this->evaluate( $spec, $context ) : null;
		if ( ! is_array( $items ) ) {
			return [];
		}
		$position = 0;
		foreach ( $items as $item ) {
			$out[] = $context->withItem( $item, ++$position );
		}
		return $out;
	}

	/**
	 * One node definition, possibly repeated.
	 *
	 * @param array<string,mixed> $node Node definition.
	 * @return array<int,array<string,mixed>>
	 */
	private function renderNode( array $node, Context $context, int $index ): array {
		if ( isset( $node['@repeat'] ) && is_array( $node['@repeat'] ) ) {
			$operations = [];
			$inner      = $node;
			unset( $inner['@repeat'] );
			foreach ( $this->resolver->repeat( $node['@repeat'], $context ) as $i => $item_context ) {
				$item_context = new Context( $item_context->post, $item_context->item, $item_context->parent, $context->vars, $i + 1, $context->request );
				foreach ( $this->renderNode( $inner, $item_context, $index ) as $operation ) {
					$operations[] = $operation;
				}
			}
			return $operations;
		}
		if ( isset( $node['@each'] ) ) {
			$operations = [];
			$inner      = $node;
			unset( $inner['@each'] );
			foreach ( $this->eachContexts( $node['@each'], $context ) as $item_context ) {
				foreach ( $this->renderNode( $inner, $item_context, $index ) as $operation ) {
					$operations[] = $operation;
				}
			}
			return $operations;
		}
		if ( isset( $node['@if'] ) && ! $this->holds( $node['@if'], $context ) ) {
			return [];
		}
		$op = (string) ( $node['@op'] ?? 'add' );
		return match ( $op ) {
			'merge'  => $this->renderMerge( $node, $context ),
			'remove' => $this->renderRemove( $node, $context ),
			default  => $this->renderAdd( $node, $context, $index ),
		};
	}

	/**
	 * @param array<string,mixed> $node Node definition.
	 * @return array<int,array<string,mixed>>
	 */
	private function renderAdd( array $node, Context $context, int $index ): array {
		$rendered = $this->renderProperties( $node, $context );
		if ( null === $rendered ) {
			return [];
		}
		$required = $node['@required'] ?? [];
		foreach ( (array) $required as $property ) {
			if ( ! isset( $rendered[ $property ] ) || Expression::isEmpty( $rendered[ $property ] ) ) {
				return [];
			}
		}
		$key = isset( $node['@key'] ) ? $this->renderValue( $node['@key'], $context ) : null;
		if ( ! is_string( $key ) || '' === $key ) {
			$type = $rendered['@type'] ?? ( 'node' . ( $index + 1 ) );
			$key  = is_array( $type ) ? (string) ( $type[0] ?? 'node' ) : (string) $type;
			// A repeated node needs a key of its own per item; the @id is the stable, unique choice.
			if ( isset( $rendered['@id'] ) && is_string( $rendered['@id'] ) && ( null !== $context->parent || $context->index > 0 ) ) {
				$key .= '_' . substr( md5( $rendered['@id'] ), 0, 8 );
			}
		}
		return [
			[
				'op'   => 'add',
				'key'  => $key,
				'node' => $rendered,
			],
		];
	}

	/**
	 * @param array<string,mixed> $node Node definition.
	 * @return array<int,array<string,mixed>>
	 */
	private function renderMerge( array $node, Context $context ): array {
		$match      = $this->renderValue( (string) $node['@match'], $context );
		$properties = $this->renderProperties( $node, $context );
		if ( ! is_string( $match ) || '' === $match || null === $properties ) {
			return [];
		}
		unset( $properties['@type'] ); // a merge never retypes the node it edits
		if ( [] === $properties ) {
			return [];
		}
		return [
			[
				'op'         => 'merge',
				'match'      => $match,
				'properties' => $properties,
			],
		];
	}

	/**
	 * @param array<string,mixed> $node Node definition.
	 * @return array<int,array<string,mixed>>
	 */
	private function renderRemove( array $node, Context $context ): array {
		$match = $this->renderValue( (string) $node['@match'], $context );
		if ( ! is_string( $match ) || '' === $match ) {
			return [];
		}
		$properties = null;
		if ( isset( $node['properties'] ) && is_array( $node['properties'] ) ) {
			$properties = array_values( array_filter( array_map( 'strval', $node['properties'] ) ) );
		}
		return [
			[
				'op'         => 'remove',
				'match'      => $match,
				'properties' => $properties,
			],
		];
	}

	/**
	 * Render every non-directive property of a node or object; null when nothing meaningful is left.
	 *
	 * @param array<string,mixed> $object Definition.
	 * @return array<string,mixed>|null
	 */
	private function renderProperties( array $object, Context $context ): ?array {
		if ( isset( $object['@if'] ) && ! $this->holds( $object['@if'], $context ) ) {
			return null;
		}
		$out = [];
		foreach ( $object as $property => $value ) {
			if ( in_array( $property, self::DIRECTIVES, true ) || ( 'properties' === $property && 'remove' === ( $object['@op'] ?? '' ) ) ) {
				continue;
			}
			$rendered = $this->renderValue( $value, $context );
			if ( Expression::isEmpty( $rendered ) ) {
				continue;
			}
			if ( '@type' === $property && is_array( $rendered ) ) {
				// ["LocalBusiness", "Organization"] where the setting already says "Organization" is one type.
				$rendered = array_values( array_unique( array_map( 'strval', array_filter( $rendered, 'is_scalar' ) ) ) );
				$rendered = 1 === count( $rendered ) ? $rendered[0] : $rendered;
			}
			$out[ (string) $property ] = $rendered;
		}
		$meaningful = array_diff( array_keys( $out ), [ '@type' ] );
		return [] === $meaningful ? null : $out;
	}

	/**
	 * Render any value: strings get placeholders resolved, arrays recurse, "@each" expands.
	 */
	private function renderValue( mixed $value, Context $context ): mixed {
		if ( is_string( $value ) ) {
			return $this->renderString( $value, $context );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( isset( $value['@each'] ) && ( is_string( $value['@each'] ) || is_array( $value['@each'] ) ) ) {
			$list = $this->renderEach( $value, $context );
			// "@single": true prints a list of one as that one object (memberOf, openingHoursSpecification).
			return ! empty( $value['@single'] ) && 1 === count( $list ) ? $list[0] : $list;
		}
		if ( array_is_list( $value ) ) {
			$list = [];
			foreach ( $value as $entry ) {
				$rendered = $this->renderValue( $entry, $context );
				if ( Expression::isEmpty( $rendered ) ) {
					continue;
				}
				$spliced = is_string( $entry ) || ( is_array( $entry ) && isset( $entry['@each'] ) );
				if ( $spliced && is_array( $rendered ) && array_is_list( $rendered ) ) {
					foreach ( $rendered as $part ) {
						if ( ! Expression::isEmpty( $part ) ) {
							$list[] = $part;
						}
					}
					continue;
				}
				$list[] = $rendered;
			}
			return $list;
		}
		return $this->renderProperties( $value, $context );
	}

	/**
	 * "@each": one object per entry of an array-valued path.
	 *
	 * @param array<string,mixed> $definition The object carrying "@each".
	 * @return array<int,array<string,mixed>>
	 */
	private function renderEach( array $definition, Context $context ): array {
		$template = $definition;
		unset( $template['@each'], $template['@single'] );
		$out = [];
		foreach ( $this->eachContexts( $definition['@each'], $context ) as $item_context ) {
			$rendered = $this->renderProperties( $template, $item_context );
			if ( null !== $rendered ) {
				$out[] = $rendered;
			}
		}
		return $out;
	}

	/**
	 * A string with placeholders. A string that is exactly one placeholder keeps the resolved value's type.
	 */
	private function renderString( string $text, Context $context ): mixed {
		if ( ! preg_match_all( Expression::PLACEHOLDER, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $text;
		}
		if ( 1 === count( $matches[0] ) && trim( $text ) === $matches[0][0][0] ) {
			return $this->evaluate( $matches[1][0][0], $context );
		}
		$missing = false;
		$result  = preg_replace_callback(
			Expression::PLACEHOLDER,
			function ( array $m ) use ( $context, &$missing ): string {
				$value = $this->evaluate( $m[1], $context );
				if ( Expression::isEmpty( $value ) ) {
					$missing = true;
					return '';
				}
				if ( is_array( $value ) ) {
					return implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
				}
				return is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
			},
			$text
		);
		return $missing ? null : $result;
	}

	/**
	 * Evaluate a directive expression ("@if", "@each"). Authors naturally write these with the same braces as a
	 * value placeholder, so a surrounding "{{ … }}" is accepted and stripped.
	 */
	private function evaluate( string $expression, Context $context ): mixed {
		$expression = trim( $expression );
		if ( preg_match( '/^\{\{(.*)\}\}$/s', $expression, $m ) ) {
			$expression = trim( $m[1] );
		}
		return $this->expression->evaluate( $expression, $context );
	}
}

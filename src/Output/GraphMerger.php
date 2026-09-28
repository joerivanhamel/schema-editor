<?php
/**
 * Applies rendered operations to a keyed graph (Rank Math's $data array, or the plugin's own).
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Output;

/**
 * Pure array work so it can be unit-tested. Selectors:
 *
 *   "#organization"      a node whose @id ends with "#organization"
 *   "@type:WebPage"      a node whose @type is, or includes, "WebPage"
 *   "@key:Service"       the node stored under that array key
 *   "https://…/#website" a node with exactly that @id
 */
final class GraphMerger {

	/**
	 * @param array<string,array<string,mixed>> $graph      Keyed nodes.
	 * @param array<int,array<string,mixed>>    $operations From Renderer::render().
	 * @return array<string,array<string,mixed>>
	 */
	public static function apply( array $graph, array $operations ): array {
		foreach ( $operations as $operation ) {
			switch ( $operation['op'] ?? '' ) {
				case 'add':
					$graph[ (string) $operation['key'] ] = $operation['node'];
					break;
				case 'merge':
					foreach ( self::matching( $graph, (string) $operation['match'] ) as $key ) {
						$graph[ $key ] = array_merge( $graph[ $key ], $operation['properties'] );
					}
					break;
				case 'remove':
					foreach ( self::matching( $graph, (string) $operation['match'] ) as $key ) {
						if ( empty( $operation['properties'] ) ) {
							unset( $graph[ $key ] );
							continue;
						}
						foreach ( $operation['properties'] as $property ) {
							unset( $graph[ $key ][ $property ] );
						}
					}
					break;
			}
		}
		return $graph;
	}

	/**
	 * Keys of the nodes a selector matches.
	 *
	 * @param array<string,array<string,mixed>> $graph Keyed nodes.
	 * @return array<int,string>
	 */
	public static function matching( array $graph, string $selector ): array {
		$keys = [];
		foreach ( $graph as $key => $node ) {
			if ( is_array( $node ) && self::nodeMatches( (string) $key, $node, $selector ) ) {
				$keys[] = (string) $key;
			}
		}
		return $keys;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private static function nodeMatches( string $key, array $node, string $selector ): bool {
		if ( str_starts_with( $selector, '@key:' ) ) {
			return $key === substr( $selector, 5 );
		}
		if ( str_starts_with( $selector, '@type:' ) ) {
			$wanted = substr( $selector, 6 );
			$type   = $node['@type'] ?? null;
			return is_array( $type ) ? in_array( $wanted, $type, true ) : $type === $wanted;
		}
		$id = isset( $node['@id'] ) && is_string( $node['@id'] ) ? $node['@id'] : '';
		if ( str_starts_with( $selector, '#' ) ) {
			return '' !== $id && str_ends_with( $id, $selector );
		}
		return '' !== $id && $id === $selector;
	}
}

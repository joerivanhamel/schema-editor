<?php
/**
 * A schema template: a name, where it applies, and the node operations it carries.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Template;

use InvalidArgumentException;

/**
 * Immutable value object parsed from a template document (the JSON the editor stores).
 *
 * Document shape:
 *
 *   {
 *     "name":     "Service page",
 *     "enabled":  true,
 *     "priority": 10,
 *     "targets":  [ { "type": "page_template", "value": "page-consulting.php" } ],
 *     "exclude":  [ { "type": "post", "value": 52 } ],
 *     "output":   "any",
 *     "vars":     { "image": "post.featured_image_id || rankmath.titles.open_graph_image_id" },
 *     "nodes":    [ { "@op": "add", "@key": "Service", "@type": "Service", "name": "{{post.title}}" } ]
 *   }
 *
 * "output" says when the template runs: "any" (default), "standalone" (only when the plugin prints its own
 * graph, for templates that build what Rank Math would otherwise provide) or "rankmath" (only when the plugin
 * edits Rank Math's graph). "vars" are named expressions evaluated once per render and read as var.NAME.
 */
final class Template {

	/**
	 * @param string              $name     Human name (mirrors the post title).
	 * @param bool                $enabled  Disabled templates are parsed but never applied.
	 * @param int                 $priority Lower runs first; later templates may replace or edit earlier nodes.
	 * @param array<int,array>    $targets  Where the template applies (any match applies it).
	 * @param array<int,array>    $exclude  Targets that veto it even when a target matches.
	 * @param array<int,array>    $nodes    Node operations, in order.
	 * @param int                 $postId   Backing post ID when stored in WordPress (0 for files and tests).
	 * @param string              $output   any | standalone | rankmath.
	 * @param array<string,mixed> $vars     Named expressions, evaluated in order.
	 */
	public function __construct(
		public readonly string $name,
		public readonly bool $enabled,
		public readonly int $priority,
		public readonly array $targets,
		public readonly array $exclude,
		public readonly array $nodes,
		public readonly int $postId = 0,
		public readonly string $output = self::OUTPUT_ANY,
		public readonly array $vars = []
	) {
	}

	public const OUTPUT_ANY        = 'any';
	public const OUTPUT_STANDALONE = 'standalone';
	public const OUTPUT_RANKMATH   = 'rankmath';

	/**
	 * Whether the template runs in an output mode ("standalone" or "rankmath").
	 */
	public function runsIn( string $mode ): bool {
		return self::OUTPUT_ANY === $this->output || $mode === $this->output;
	}

	/**
	 * Parse a document. Throws on anything that would make the template unusable, so the editor can show the
	 * reason and the runtime can skip the template instead of printing broken JSON-LD.
	 *
	 * @throws InvalidArgumentException When the JSON is invalid or the shape is wrong.
	 */
	public static function fromJson( string $json, int $post_id = 0, string $fallback_name = '' ): self {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'The template is not valid JSON: ' . json_last_error_msg() );
		}
		return self::fromArray( $data, $post_id, $fallback_name );
	}

	/**
	 * @param array<string,mixed> $data Decoded document.
	 *
	 * @throws InvalidArgumentException When the shape is wrong.
	 */
	public static function fromArray( array $data, int $post_id = 0, string $fallback_name = '' ): self {
		$nodes = $data['nodes'] ?? null;
		if ( ! is_array( $nodes ) || array_values( $nodes ) !== $nodes ) {
			throw new InvalidArgumentException( '"nodes" must be a JSON array of node objects.' );
		}
		foreach ( $nodes as $i => $node ) {
			if ( ! is_array( $node ) ) {
				throw new InvalidArgumentException( sprintf( 'Node %d is not an object.', $i + 1 ) );
			}
			$op = (string) ( $node['@op'] ?? 'add' );
			if ( ! in_array( $op, [ 'add', 'merge', 'remove' ], true ) ) {
				throw new InvalidArgumentException( sprintf( 'Node %d: unknown "@op" "%s" (add, merge or remove).', $i + 1, $op ) );
			}
			if ( 'add' !== $op && empty( $node['@match'] ) ) {
				throw new InvalidArgumentException( sprintf( 'Node %d: "%s" needs a "@match" selector.', $i + 1, $op ) );
			}
			if ( 'add' === $op && empty( $node['@type'] ) && empty( $node['@repeat'] ) && empty( $node['@each'] ) ) {
				throw new InvalidArgumentException( sprintf( 'Node %d: an added node needs a "@type".', $i + 1 ) );
			}
		}
		$output = (string) ( $data['output'] ?? self::OUTPUT_ANY );
		if ( ! in_array( $output, [ self::OUTPUT_ANY, self::OUTPUT_STANDALONE, self::OUTPUT_RANKMATH ], true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown "output" "%s" (any, standalone or rankmath).', $output ) );
		}
		$vars = $data['vars'] ?? [];
		if ( ! is_array( $vars ) || ( [] !== $vars && array_is_list( $vars ) ) ) {
			throw new InvalidArgumentException( '"vars" must be a JSON object of name: expression.' );
		}
		foreach ( array_keys( $vars ) as $var_name ) {
			if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $var_name ) ) {
				throw new InvalidArgumentException( sprintf( 'Variable name "%s" may use letters, digits and underscores only.', $var_name ) );
			}
		}
		$targets = self::targetList( $data['targets'] ?? [], 'targets' );
		$exclude = self::targetList( $data['exclude'] ?? [], 'exclude' );
		$name    = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = '' !== $fallback_name ? $fallback_name : 'Untitled template';
		}
		return new self(
			$name,
			(bool) ( $data['enabled'] ?? true ),
			(int) ( $data['priority'] ?? 10 ),
			$targets,
			$exclude,
			array_values( $nodes ),
			$post_id,
			$output,
			$vars
		);
	}

	/**
	 * Validate and normalise a list of targets.
	 *
	 * @param mixed  $list  Raw list.
	 * @param string $field Field name for messages.
	 * @return array<int,array{type:string,value:mixed}>
	 *
	 * @throws InvalidArgumentException When a target is malformed.
	 */
	private static function targetList( mixed $list, string $field ): array {
		if ( ! is_array( $list ) ) {
			throw new InvalidArgumentException( sprintf( '"%s" must be a JSON array.', $field ) );
		}
		$out = [];
		foreach ( $list as $i => $target ) {
			if ( ! is_array( $target ) || empty( $target['type'] ) || ! is_string( $target['type'] ) ) {
				throw new InvalidArgumentException( sprintf( '%s %d needs a "type".', ucfirst( $field ), $i + 1 ) );
			}
			$type = $target['type'];
			if ( ! in_array( $type, Targets::TYPES, true ) ) {
				throw new InvalidArgumentException( sprintf( '%s %d: unknown type "%s". Known types: %s.', ucfirst( $field ), $i + 1, $type, implode( ', ', Targets::TYPES ) ) );
			}
			if ( in_array( $type, Targets::TYPES_WITH_VALUE, true ) && ( ! isset( $target['value'] ) || '' === $target['value'] ) ) {
				throw new InvalidArgumentException( sprintf( '%s %d: "%s" needs a "value".', ucfirst( $field ), $i + 1, $type ) );
			}
			$out[] = [
				'type'  => $type,
				'value' => $target['value'] ?? null,
			];
		}
		return $out;
	}

	/**
	 * The document as an array (for export and the editor).
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		$out = [
			'name'     => $this->name,
			'enabled'  => $this->enabled,
			'priority' => $this->priority,
			'targets'  => $this->targets,
			'exclude'  => $this->exclude,
		];
		if ( self::OUTPUT_ANY !== $this->output ) {
			$out['output'] = $this->output;
		}
		if ( [] !== $this->vars ) {
			$out['vars'] = $this->vars;
		}
		$out['nodes'] = $this->nodes;
		return $out;
	}
}

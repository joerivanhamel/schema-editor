<?php
/**
 * Rendering context: which post, which repeated item, which outer post, the template's variables and the loop
 * position.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Render;

/**
 * Carried through rendering. "post" is whatever the placeholders address as post.*; inside a "@repeat" node it is
 * the repeated item and "parent" is the page being rendered; inside "@each" the current array entry is "item".
 * "vars" holds the template's evaluated "vars" (var.*); "index" is the 1-based position inside "@each" or
 * "@repeat" (loop.index), 0 outside a loop. "request" is the RequestContext being rendered (request.*), so
 * conditions and page data work the same on the front end, in the editor's Preview and on the command line.
 */
final class Context {

	/**
	 * @param mixed               $post   The subject (WP_Post at runtime, array in tests).
	 * @param mixed               $item   Current "@each" entry.
	 * @param mixed               $parent The outer subject inside a repeat.
	 * @param array<string,mixed> $vars   Evaluated template variables.
	 * @param int                 $index  1-based loop position, 0 outside a loop.
	 * @param mixed               $request The RequestContext (or an array in tests); null when unknown.
	 */
	public function __construct(
		public readonly mixed $post = null,
		public readonly mixed $item = null,
		public readonly mixed $parent = null,
		public readonly array $vars = [],
		public readonly int $index = 0,
		public readonly mixed $request = null
	) {
	}

	public function withPost( mixed $post ): self {
		return new self( $post, $this->item, $this->post, $this->vars, $this->index, $this->request );
	}

	public function withItem( mixed $item, int $index = 0 ): self {
		return new self( $this->post, $item, $this->parent, $this->vars, $index, $this->request );
	}

	/**
	 * @param array<string,mixed> $vars Evaluated variables.
	 */
	public function withVars( array $vars ): self {
		return new self( $this->post, $this->item, $this->parent, $vars, $this->index, $this->request );
	}

	public function withRequest( mixed $request ): self {
		return new self( $this->post, $this->item, $this->parent, $this->vars, $this->index, $request );
	}

	public function withIndex( int $index ): self {
		return new self( $this->post, $this->item, $this->parent, $this->vars, $index, $this->request );
	}
}

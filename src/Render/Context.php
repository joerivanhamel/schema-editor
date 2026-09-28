<?php
/**
 * Rendering context: which post, which repeated item, which outer post.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Render;

/**
 * Carried through rendering. "post" is whatever the placeholders address as post.*; inside a "@repeat" node it is
 * the repeated item and "parent" is the page being rendered; inside "@each" the current array entry is "item".
 */
final class Context {

	/**
	 * @param mixed $post   The subject (WP_Post at runtime, array in tests).
	 * @param mixed $item   Current "@each" entry.
	 * @param mixed $parent The outer subject inside a repeat.
	 */
	public function __construct(
		public readonly mixed $post = null,
		public readonly mixed $item = null,
		public readonly mixed $parent = null
	) {
	}

	public function withPost( mixed $post ): self {
		return new self( $post, $this->item, $this->post );
	}

	public function withItem( mixed $item ): self {
		return new self( $this->post, $item, $this->parent );
	}
}

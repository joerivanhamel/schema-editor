<?php
/**
 * What the current request is, expressed without WordPress so target matching can be tested.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Template;

/**
 * A snapshot of the request the templates are matched against.
 */
final class RequestContext {

	/**
	 * @param int           $postId          Queried post ID on singular requests, else 0.
	 * @param string        $postType        Post type on singular requests and post-type archives.
	 * @param string        $pageTemplate    Page template file name (e.g. page-about.php) or "default".
	 * @param string        $slug            Post slug on singular requests.
	 * @param bool          $isSingular      Single post, page or custom post.
	 * @param bool          $isFrontPage     The site's front page.
	 * @param bool          $isPostsPage     The blog listing (is_home when a static front page is set).
	 * @param bool          $isPostTypeArchive Post type archive.
	 * @param string        $taxonomy        Taxonomy on term archives.
	 * @param string        $termSlug        Term slug on term archives.
	 * @param bool          $isSearch        Search results.
	 * @param bool          $is404           Not found.
	 * @param array<string> $terms           Slugs of every term attached to the post, prefixed "taxonomy:slug".
	 * @param bool          $isAuthor        An author archive (author.php).
	 * @param int           $authorId        The queried author's user ID on author archives.
	 * @param string        $authorLogin     The queried author's user_nicename on author archives.
	 * @param bool          $isArchive       Any archive (term, post type, author, date).
	 * @param bool          $isPaged         Page 2 or later of a listing.
	 */
	public function __construct(
		public readonly int $postId = 0,
		public readonly string $postType = '',
		public readonly string $pageTemplate = '',
		public readonly string $slug = '',
		public readonly bool $isSingular = false,
		public readonly bool $isFrontPage = false,
		public readonly bool $isPostsPage = false,
		public readonly bool $isPostTypeArchive = false,
		public readonly string $taxonomy = '',
		public readonly string $termSlug = '',
		public readonly bool $isSearch = false,
		public readonly bool $is404 = false,
		public readonly array $terms = [],
		public readonly bool $isAuthor = false,
		public readonly int $authorId = 0,
		public readonly string $authorLogin = '',
		public readonly bool $isArchive = false,
		public readonly bool $isPaged = false
	) {
	}

	/**
	 * The request as the flat values templates read through request.* (is_front_page, is_home, is_singular,
	 * is_page, is_archive, is_collection, is_search, is_author, is_404, is_paged, post_type, taxonomy, term,
	 * page_template, slug, post_id). A false condition is left out, so "@if": "request.is_search" reads naturally.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array_filter(
			[
				'post_id'       => $this->postId ?: null,
				'post_type'     => $this->postType,
				'page_template' => $this->pageTemplate,
				'slug'          => $this->slug,
				'taxonomy'      => $this->taxonomy,
				'term'          => $this->termSlug,
				'is_singular'   => $this->isSingular,
				'is_page'       => $this->isSingular && 'page' === $this->postType,
				'is_front_page' => $this->isFrontPage,
				'is_home'       => $this->isPostsPage,
				'is_archive'    => $this->isArchive,
				'is_collection' => $this->isPostsPage || $this->isArchive,
				'is_search'     => $this->isSearch,
				'is_author'     => $this->isAuthor,
				'is_404'        => $this->is404,
				'is_paged'      => $this->isPaged,
			],
			static fn( mixed $value ): bool => null !== $value && '' !== $value && false !== $value
		);
	}

	/**
	 * Build from WordPress's conditional tags. Only called at runtime, never in tests.
	 */
	public static function fromWordPress(): self {
		$post_id       = is_singular() ? (int) get_queried_object_id() : 0;
		$post_type     = '';
		$page_template = '';
		$slug          = '';
		$terms         = [];
		if ( $post_id ) {
			$post_type = (string) get_post_type( $post_id );
			$slug      = (string) get_post_field( 'post_name', $post_id );
			$template  = (string) get_page_template_slug( $post_id );
			// get_page_template_slug() returns '' for the default template; name it so targets can address it.
			$page_template = '' !== $template ? basename( $template ) : 'default';
			foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
				$attached = get_the_terms( $post_id, $taxonomy );
				if ( is_array( $attached ) ) {
					foreach ( $attached as $term ) {
						$terms[] = $taxonomy . ':' . $term->slug;
					}
				}
			}
		} elseif ( is_post_type_archive() ) {
			$object    = get_queried_object();
			$post_type = is_object( $object ) && isset( $object->name ) ? (string) $object->name : '';
		}
		$taxonomy  = '';
		$term_slug = '';
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$taxonomy  = $term->taxonomy;
				$term_slug = $term->slug;
			}
		}
		$author_id    = 0;
		$author_login = '';
		if ( is_author() ) {
			$author = get_queried_object();
			if ( $author instanceof \WP_User ) {
				$author_id    = $author->ID;
				$author_login = $author->user_nicename;
			}
		}
		return new self(
			$post_id,
			$post_type,
			$page_template,
			$slug,
			is_singular(),
			is_front_page(),
			is_home(),
			is_post_type_archive(),
			$taxonomy,
			$term_slug,
			is_search(),
			is_404(),
			$terms,
			is_author(),
			$author_id,
			$author_login,
			is_archive(),
			is_paged()
		);
	}
}

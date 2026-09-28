<?php
/**
 * Decides whether a template applies to a request.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Template;

/**
 * Pure matcher: a template applies when any of its targets matches the request and none of its excludes does.
 */
final class Targets {

	/** Every target type the editor accepts. */
	public const TYPES = [
		'all',               // every front-end request
		'singular',          // any single post of any type
		'post_type',         // value: post type name; single posts of that type
		'page_template',     // value: template file name (page-about.php) or "default"
		'post',              // value: post ID
		'slug',              // value: post slug
		'term',              // value: "taxonomy:slug"; single posts carrying that term
		'front_page',        // the front page
		'posts_page',        // the blog listing
		'post_type_archive', // value: post type name
		'taxonomy',          // value: taxonomy name; its term archives
		'search',            // search results
		'404',               // not found
		'author',            // author archives (author.php); optional value: the author's user_nicename or ID
	];

	/** Target types that need a value. */
	public const TYPES_WITH_VALUE = [ 'post_type', 'page_template', 'post', 'slug', 'term', 'post_type_archive', 'taxonomy' ];

	/**
	 * Whether the template applies to the request.
	 */
	public static function applies( Template $template, RequestContext $request ): bool {
		if ( ! $template->enabled ) {
			return false;
		}
		foreach ( $template->exclude as $target ) {
			if ( self::matches( $target, $request ) ) {
				return false;
			}
		}
		foreach ( $template->targets as $target ) {
			if ( self::matches( $target, $request ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether one target matches the request.
	 *
	 * @param array{type:string,value:mixed} $target Target.
	 */
	public static function matches( array $target, RequestContext $request ): bool {
		$value = $target['value'] ?? null;
		return match ( $target['type'] ) {
			'all'               => true,
			'singular'          => $request->isSingular,
			'post_type'         => $request->isSingular && $request->postType === (string) $value,
			'page_template'     => $request->isSingular && $request->pageTemplate === basename( (string) $value ),
			'post'              => $request->isSingular && $request->postId === (int) $value,
			'slug'              => $request->isSingular && $request->slug === (string) $value,
			'term'              => $request->isSingular && in_array( (string) $value, $request->terms, true ),
			'front_page'        => $request->isFrontPage,
			'posts_page'        => $request->isPostsPage,
			'post_type_archive' => $request->isPostTypeArchive && $request->postType === (string) $value,
			'taxonomy'          => '' !== $request->taxonomy && $request->taxonomy === (string) $value,
			'search'            => $request->isSearch,
			'404'               => $request->is404,
			'author'            => $request->isAuthor && ( null === $value || '' === $value || $request->authorLogin === (string) $value || $request->authorId === (int) $value ),
			default             => false,
		};
	}
}

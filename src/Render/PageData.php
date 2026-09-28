<?php
/**
 * What the current page is, as the templates address it through page.*: its canonical URL, SEO title, SEO
 * description and breadcrumb trail.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Render;

use JoeriVanhamel\SchemaEditor\Template\RequestContext;
use WP_Post;

/**
 * On the front end the values come from Rank Math's "Paper" (the same title, description and canonical Rank
 * Math prints in the head, whether or not its schema module is on), else from WordPress. In the editor's Preview
 * and on the command line there is no front-end request, so they are worked out from the post being previewed.
 *
 *   page.url | page.canonical   the canonical URL
 *   page.title                  the SEO title (the document title)
 *   page.description            the SEO meta description (nothing when none is set)
 *   page.breadcrumbs            [ { name, url, position }, ... ] (see Breadcrumbs)
 */
final class PageData {

	/**
	 * @param array<int,string> $segments Path after "page.".
	 */
	public static function value( array $segments, mixed $post, mixed $request ): mixed {
		$key = (string) ( $segments[0] ?? '' );
		return match ( $key ) {
			'url', 'canonical' => self::url( $post ),
			'title'            => self::title( $post ),
			'description'      => self::description( $post ),
			'breadcrumbs'      => ArrayResolver::walk( self::breadcrumbs( $post, $request ), array_slice( $segments, 1 ) ),
			default            => null,
		};
	}

	/**
	 * Whether this is a real front-end request (Rank Math's Paper describes it) rather than a preview or CLI run.
	 */
	private static function frontEnd(): bool {
		return ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && did_action( 'wp' ) > 0;
	}

	private static function paper(): ?object {
		if ( ! self::frontEnd() || ! class_exists( '\RankMath\Paper\Paper' ) ) {
			return null;
		}
		$paper = \RankMath\Paper\Paper::get();
		return is_object( $paper ) ? $paper : null;
	}

	public static function url( mixed $post ): ?string {
		$paper = self::paper();
		if ( $paper && method_exists( $paper, 'get_canonical' ) ) {
			$url = (string) $paper->get_canonical();
			if ( '' !== $url ) {
				return $url;
			}
		}
		if ( self::frontEnd() ) {
			return self::wordPressCanonical();
		}
		return $post instanceof WP_Post ? (string) get_permalink( $post ) : null;
	}

	public static function title( mixed $post ): ?string {
		$paper = self::paper();
		if ( $paper && method_exists( $paper, 'get_title' ) ) {
			$title = (string) $paper->get_title();
			if ( '' !== $title ) {
				return $title;
			}
		}
		if ( self::frontEnd() ) {
			return wp_get_document_title();
		}
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$seo = (string) get_post_meta( $post->ID, 'rank_math_title', true );
		if ( '' !== $seo && class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'replace_vars' ) ) {
			$seo = (string) \RankMath\Helper::replace_vars( $seo, $post );
		}
		return '' !== $seo ? $seo : get_the_title( $post );
	}

	public static function description( mixed $post ): ?string {
		$paper = self::paper();
		if ( $paper && method_exists( $paper, 'get_description' ) ) {
			$description = (string) $paper->get_description();
			return '' !== $description ? $description : null;
		}
		if ( self::frontEnd() && ! is_singular() ) {
			return null;
		}
		$post = self::frontEnd() ? get_queried_object() : $post;
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$seo = (string) get_post_meta( $post->ID, 'rank_math_description', true );
		if ( '' !== $seo && class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'replace_vars' ) ) {
			$seo = (string) \RankMath\Helper::replace_vars( $seo, $post );
		}
		return '' !== $seo ? $seo : null;
	}

	/**
	 * The breadcrumb trail with positions; the current page's own crumb carries the canonical URL.
	 *
	 * @return array<int,array{name:string,url:string,position:int}>
	 */
	public static function breadcrumbs( mixed $post, mixed $request ): array {
		// Read several times per page (a condition, the list, the reference); work it out once per request.
		static $cache = [];
		$key = ( $post instanceof WP_Post ? $post->ID : 0 ) . '|' . ( $request instanceof RequestContext ? md5( (string) wp_json_encode( $request->toArray() ) ) : '' );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$trail = Breadcrumbs::trail( $post, $request instanceof RequestContext ? $request : null );
		$url   = self::url( $post ) ?? '';
		$out   = [];
		foreach ( array_values( $trail ) as $i => $crumb ) {
			$out[] = [
				'name'     => $crumb['name'],
				'url'      => '' !== $crumb['url'] ? $crumb['url'] : $url,
				'position' => $i + 1,
			];
		}
		$cache[ $key ] = array_values( array_filter( $out, static fn( array $crumb ): bool => '' !== $crumb['name'] && '' !== $crumb['url'] ) );
		return $cache[ $key ];
	}

	/**
	 * WordPress's own idea of the canonical URL for the current request, when Rank Math is not there.
	 */
	private static function wordPressCanonical(): ?string {
		if ( is_front_page() ) {
			return home_url( '/' );
		}
		if ( is_singular() ) {
			$url = wp_get_canonical_url();
			return $url ? (string) $url : null;
		}
		if ( is_home() ) {
			$page = (int) get_option( 'page_for_posts' );
			return $page ? (string) get_permalink( $page ) : home_url( '/' );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object() );
			return is_string( $link ) ? $link : null;
		}
		if ( is_post_type_archive() ) {
			$link = get_post_type_archive_link( (string) get_query_var( 'post_type' ) );
			return $link ? (string) $link : null;
		}
		if ( is_author() ) {
			return (string) get_author_posts_url( (int) get_queried_object_id() );
		}
		if ( is_search() ) {
			return (string) get_search_link();
		}
		return null;
	}
}

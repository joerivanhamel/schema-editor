<?php
/**
 * The breadcrumb trail for the current page, for a BreadcrumbList built by a template (page.breadcrumbs).
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Render;

use JoeriVanhamel\SchemaEditor\Settings;
use JoeriVanhamel\SchemaEditor\Template\RequestContext;
use WP_Post;
use WP_Term;
use WP_User;

/**
 * Google asks that breadcrumb markup match the trail the page shows. Two sources, in this order:
 *
 *  1. The theme's own trail. When Settings → "Breadcrumb trail function" names a function (for example the one
 *     the theme's breadcrumb template calls), its crumbs are used as they are, so the markup is the visible trail.
 *     Each crumb may be [ 'label' | 'name' | 'title' | 0 => text, 'url' | 'link' | 'item' | 1 => URL ]; an empty
 *     URL means the current page. Only used on the front end, where the theme's conditionals hold.
 *  2. A trail built from WordPress: the home page (labelled with Rank Math's breadcrumb home label, else the front
 *     page's title), then the posts page for posts, the post type archive for other types, the page's ancestors,
 *     the term's ancestors. Every label is a title, name or setting from the database.
 *
 * Either result passes through the "schema_editor/breadcrumbs" filter. The front page, search results and "not
 * found" have no trail.
 */
final class Breadcrumbs {

	/**
	 * @return array<int,array{name:string,url:string}> Ordered crumbs; the last is the current page (url '').
	 */
	public static function trail( mixed $post, ?RequestContext $request ): array {
		$front_end = ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && did_action( 'wp' ) > 0;
		$trail     = null;
		$callback  = trim( (string) ( new Settings() )->get( 'breadcrumb_callback' ) );
		if ( $front_end && '' !== $callback && is_callable( $callback ) ) {
			$trail = self::normalise( call_user_func( $callback ) );
		}
		if ( null === $trail ) {
			$trail = $front_end ? self::fromQuery() : self::fromPost( $post );
		}
		$trail = apply_filters( 'schema_editor/breadcrumbs', $trail, $post, $request );
		return is_array( $trail ) ? self::normalise( $trail ) : [];
	}

	/**
	 * @return array<int,array{name:string,url:string}>
	 */
	private static function normalise( mixed $crumbs ): array {
		if ( ! is_array( $crumbs ) ) {
			return [];
		}
		$out = [];
		foreach ( $crumbs as $crumb ) {
			if ( is_object( $crumb ) ) {
				$crumb = (array) $crumb;
			}
			if ( ! is_array( $crumb ) ) {
				continue;
			}
			$name = $crumb['name'] ?? $crumb['label'] ?? $crumb['title'] ?? $crumb[0] ?? '';
			$url  = $crumb['url'] ?? $crumb['link'] ?? $crumb['item'] ?? $crumb[1] ?? '';
			$name = Expression::plain( is_scalar( $name ) ? (string) $name : '' );
			if ( '' === $name ) {
				continue;
			}
			$out[] = [
				'name' => (string) $name,
				'url'  => is_scalar( $url ) ? (string) $url : '',
			];
		}
		return $out;
	}

	/**
	 * @return array{name:string,url:string}
	 */
	private static function home(): array {
		$label   = '';
		$general = get_option( 'rank-math-options-general', [] );
		if ( is_array( $general ) && ! empty( $general['breadcrumbs_home_label'] ) ) {
			$label = (string) $general['breadcrumbs_home_label'];
		}
		$front = (int) get_option( 'page_on_front' );
		if ( '' === $label && $front ) {
			$label = get_the_title( $front );
		}
		if ( '' === $label ) {
			$label = (string) get_bloginfo( 'name' );
		}
		return [ 'name' => $label, 'url' => home_url( '/' ) ];
	}

	/**
	 * @return array<int,array{name:string,url:string}>
	 */
	private static function postsPage(): array {
		$page = (int) get_option( 'page_for_posts' );
		return $page ? [ [ 'name' => get_the_title( $page ), 'url' => (string) get_permalink( $page ) ] ] : [];
	}

	/**
	 * @return array<int,array{name:string,url:string}>
	 */
	private static function typeArchive( string $post_type ): array {
		$object = get_post_type_object( $post_type );
		$link   = get_post_type_archive_link( $post_type );
		if ( ! $object || ! $link ) {
			return [];
		}
		return [ [ 'name' => (string) ( $object->labels->name ?? $object->label ), 'url' => (string) $link ] ];
	}

	/**
	 * A post's crumbs after home: its listing (posts page or archive), its ancestors, itself.
	 *
	 * @return array<int,array{name:string,url:string}>
	 */
	private static function forPost( WP_Post $post ): array {
		$crumbs = [];
		if ( 'post' === $post->post_type ) {
			$crumbs = self::postsPage();
		} elseif ( 'page' !== $post->post_type ) {
			$crumbs = self::typeArchive( $post->post_type );
		}
		foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
			$crumbs[] = [ 'name' => get_the_title( $ancestor ), 'url' => (string) get_permalink( $ancestor ) ];
		}
		$crumbs[] = [ 'name' => get_the_title( $post ), 'url' => '' ];
		return $crumbs;
	}

	/**
	 * @return array<int,array{name:string,url:string}>
	 */
	private static function fromPost( mixed $post ): array {
		if ( ! $post instanceof WP_Post || (int) get_option( 'page_on_front' ) === $post->ID ) {
			return [];
		}
		if ( (int) get_option( 'page_for_posts' ) === $post->ID ) {
			return [ self::home(), [ 'name' => get_the_title( $post ), 'url' => '' ] ];
		}
		return array_merge( [ self::home() ], self::forPost( $post ) );
	}

	/**
	 * @return array<int,array{name:string,url:string}>
	 */
	private static function fromQuery(): array {
		if ( is_front_page() || is_search() || is_404() ) {
			return [];
		}
		$crumbs = [ self::home() ];
		if ( is_home() ) {
			$page = (int) get_option( 'page_for_posts' );
			if ( $page ) {
				$crumbs[] = [ 'name' => get_the_title( $page ), 'url' => '' ];
			}
			return $crumbs;
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			return $post instanceof WP_Post ? array_merge( $crumbs, self::forPost( $post ) ) : [];
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( ! $term instanceof WP_Term ) {
				return [];
			}
			$taxonomy = get_taxonomy( $term->taxonomy );
			$types    = $taxonomy ? (array) $taxonomy->object_type : [];
			if ( in_array( 'post', $types, true ) ) {
				$crumbs = array_merge( $crumbs, self::postsPage() );
			} elseif ( isset( $types[0] ) ) {
				$crumbs = array_merge( $crumbs, self::typeArchive( (string) $types[0] ) );
			}
			foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, $term->taxonomy );
				if ( $ancestor instanceof WP_Term ) {
					$link     = get_term_link( $ancestor );
					$crumbs[] = [ 'name' => $ancestor->name, 'url' => is_string( $link ) ? $link : '' ];
				}
			}
			$crumbs[] = [ 'name' => $term->name, 'url' => '' ];
			return $crumbs;
		}
		if ( is_post_type_archive() ) {
			$archive = self::typeArchive( (string) get_query_var( 'post_type' ) );
			if ( $archive ) {
				$archive[0]['url'] = '';
			}
			return array_merge( $crumbs, $archive );
		}
		if ( is_author() ) {
			$author = get_queried_object();
			if ( $author instanceof WP_User ) {
				$crumbs   = array_merge( $crumbs, self::postsPage() );
				$crumbs[] = [ 'name' => $author->display_name, 'url' => '' ];
			}
			return $crumbs;
		}
		return [];
	}
}

<?php
/**
 * The runtime resolver: reads posts, fields, options and Rank Math settings.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Render;

use WP_Post;
use WP_Query;

/**
 * Namespaces:
 *
 *   post.id | title | permalink | slug | type | excerpt | content | date | modified | featured_image |
 *        featured_image_id | author.name | author.url | author.id | parent_id
 *   item.*        the current "@each" entry (array or object)
 *   parent.*      the page being rendered, inside a "@repeat" node (same keys as post.*)
 *   acf.FIELD     an ACF field of the post; "acf.*_hero_image" matches the first field whose name fits;
 *                 "acf.FIELD.SUB" walks arrays; ".url" on an attachment ID gives its URL
 *   option.FIELD  an ACF options field (get_field on 'option'); globs work here too
 *   meta.KEY      raw post meta
 *   rankmath.title | description         the post's Rank Math title and description
 *   rankmath.titles.KEY | general.KEY    a value from Rank Math's settings
 *   site.url | name | description | language
 *   terms.TAXONOMY | terms.TAXONOMY.slugs   names (or slugs) of the post's terms in that taxonomy
 *   settings.NAME  a WordPress option
 *
 * Filters: image[:size] turns an attachment ID, ACF image array or URL into a URL; permalink turns a post ID into
 * its permalink.
 */
final class WordPressResolver implements ValueResolverInterface {

	public function resolve( string $path, Context $context ): mixed {
		$segments  = explode( '.', $path );
		$namespace = (string) array_shift( $segments );
		$value     = match ( $namespace ) {
			'post'     => $this->postValue( $context->post, $segments ),
			'parent'   => $this->postValue( $context->parent, $segments ),
			'author'   => $this->userValue( $this->contextAuthorId( $context ), $segments ),
			'item'     => ArrayResolver::walk( is_object( $context->item ) ? (array) $context->item : $context->item, $segments ),
			'acf'      => $this->acfValue( $this->postId( $context->post ), $segments ),
			'option'   => $this->acfValue( 'option', $segments ),
			'meta'     => $this->metaValue( $this->postId( $context->post ), $segments ),
			'rankmath' => $this->rankMathValue( $this->postId( $context->post ), $segments ),
			'site'     => $this->siteValue( $segments ),
			'terms'    => $this->termsValue( $this->postId( $context->post ), $segments ),
			'settings' => ArrayResolver::walk( get_option( (string) ( $segments[0] ?? '' ) ), array_slice( $segments, 1 ) ),
			default    => null,
		};
		return false === $value ? null : $value; // ACF returns false for an empty field
	}

	public function filter( string $name, mixed $value, ?string $argument ): mixed {
		switch ( $name ) {
			case 'image':
				return $this->imageUrl( $value, $argument );
			case 'permalink':
				$id = is_numeric( $value ) ? (int) $value : ( $value instanceof WP_Post ? $value->ID : 0 );
				return $id ? (string) get_permalink( $id ) : null;
			default:
				return $value;
		}
	}

	public function repeat( array $query, Context $context ): array {
		$args  = array_merge(
			[
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			],
			$query
		);
		$query = new WP_Query( $args );
		$out   = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$out[] = new Context( $post, null, $context->post );
			}
		}
		return $out;
	}

	private function postId( mixed $post ): int {
		if ( $post instanceof WP_Post ) {
			return $post->ID;
		}
		if ( is_numeric( $post ) ) {
			return (int) $post;
		}
		return 0;
	}

	/**
	 * @param array<int,string> $segments Path after "post.".
	 */
	private function postValue( mixed $post, array $segments ): mixed {
		if ( is_numeric( $post ) ) {
			$post = get_post( (int) $post );
		}
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$key = (string) ( $segments[0] ?? '' );
		switch ( $key ) {
			case 'id':
				return $post->ID;
			case 'title':
				return get_the_title( $post );
			case 'permalink':
				return (string) get_permalink( $post );
			case 'slug':
				return $post->post_name;
			case 'type':
				return $post->post_type;
			case 'excerpt':
				return '' !== $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 55, '…' );
			case 'content':
				return wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			case 'date':
				return (string) get_the_date( 'c', $post );
			case 'modified':
				return (string) get_the_modified_date( 'c', $post );
			case 'featured_image':
				return (string) ( get_the_post_thumbnail_url( $post, isset( $segments[1] ) ? $segments[1] : 'full' ) ?: '' );
			case 'featured_image_id':
				return (int) get_post_thumbnail_id( $post ) ?: null;
			case 'parent_id':
				return $post->post_parent ?: null;
			case 'author':
				return $this->userValue( (int) $post->post_author, array_slice( $segments, 1 ) ?: [ 'name' ] );
			default:
				return null;
		}
	}

	/**
	 * The user "author.*" addresses: the queried author on an author archive, otherwise the post's author.
	 */
	private function contextAuthorId( Context $context ): int {
		if ( function_exists( 'is_author' ) && is_author() ) {
			$user = get_queried_object();
			if ( $user instanceof \WP_User ) {
				return $user->ID;
			}
		}
		$post = is_numeric( $context->post ) ? get_post( (int) $context->post ) : $context->post;
		return $post instanceof WP_Post ? (int) $post->post_author : 0;
	}

	/**
	 * A user: id | name | url (author archive) | description | login | email | avatar | acf.FIELD (ACF user fields).
	 *
	 * @param array<int,string> $segments Key then sub-keys.
	 */
	private function userValue( int $user_id, array $segments ): mixed {
		if ( ! $user_id ) {
			return null;
		}
		$key = (string) ( $segments[0] ?? 'name' );
		switch ( $key ) {
			case 'id':
				return $user_id;
			case 'name':
				return (string) get_the_author_meta( 'display_name', $user_id );
			case 'first_name':
			case 'last_name':
			case 'description':
			case 'user_url':
				return (string) get_the_author_meta( $key, $user_id );
			case 'login':
				return (string) get_the_author_meta( 'user_nicename', $user_id );
			case 'email':
				return (string) get_the_author_meta( 'user_email', $user_id );
			case 'url':
				return (string) get_author_posts_url( $user_id );
			case 'avatar':
				$url = get_avatar_url( $user_id, [ 'size' => (int) ( $segments[1] ?? 512 ) ] );
				return $url ? (string) $url : null;
			case 'acf':
				if ( ! function_exists( 'get_field' ) || empty( $segments[1] ) ) {
					return null;
				}
				$value = get_field( (string) $segments[1], 'user_' . $user_id );
				$rest  = array_slice( $segments, 2 );
				if ( $rest && 'url' === $rest[0] && ( is_numeric( $value ) || ( is_array( $value ) && isset( $value['ID'] ) ) ) ) {
					return $this->imageUrl( $value, $rest[1] ?? 'full' );
				}
				return $rest ? ArrayResolver::walk( is_object( $value ) ? (array) $value : $value, $rest ) : $value;
			default:
				return null;
		}
	}

	/**
	 * @param int|string        $scope    Post ID or 'option'.
	 * @param array<int,string> $segments Field name then sub-keys.
	 */
	private function acfValue( int|string $scope, array $segments ): mixed {
		if ( ! function_exists( 'get_field' ) || [] === $segments || ( is_int( $scope ) && 0 === $scope ) ) {
			return null;
		}
		$field = (string) array_shift( $segments );
		if ( str_contains( $field, '*' ) ) {
			// Match against the field names the field groups define, not only the values saved in meta:
			// a field that still holds its default value is not in get_fields() but get_field() does return it.
			$value = null;
			foreach ( self::acfFieldNames( $scope ) as $name ) {
				if ( fnmatch( $field, $name ) ) {
					$candidate = get_field( $name, $scope );
					if ( ! Expression::isEmpty( $candidate ) ) {
						$value = $candidate;
						break;
					}
				}
			}
		} else {
			$value = get_field( $field, $scope );
		}
		if ( [] === $segments ) {
			return $value;
		}
		// ".url" on an attachment ID or image array.
		if ( 'url' === $segments[0] && ( is_numeric( $value ) || ( is_array( $value ) && isset( $value['ID'] ) ) ) ) {
			return $this->imageUrl( $value, $segments[1] ?? 'full' );
		}
		return ArrayResolver::walk( is_object( $value ) ? (array) $value : $value, $segments );
	}

	/**
	 * Every top-level ACF field name that applies to a post (or to the options pages), from the field-group
	 * definitions, plus any name that has a saved value. Sub-fields of groups and repeaters are addressed through
	 * their parent ("acf.hero.image"), so only top-level names are listed.
	 *
	 * @param int|string $scope Post ID or 'option'.
	 * @return array<int,string>
	 */
	public static function acfFieldNames( int|string $scope ): array {
		static $cache = [];
		$key = (string) $scope;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$names = [];
		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			$groups = is_int( $scope )
				? acf_get_field_groups( [ 'post_id' => $scope, 'post_type' => (string) get_post_type( $scope ) ] )
				: array_filter( (array) acf_get_field_groups(), static fn( $g ): bool => self::groupTargetsOptions( (array) $g ) );
			foreach ( (array) $groups as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field_def ) {
					if ( is_array( $field_def ) && ! empty( $field_def['name'] ) ) {
						$names[] = (string) $field_def['name'];
					}
				}
			}
		}
		if ( function_exists( 'get_fields' ) ) {
			$saved = get_fields( $scope );
			if ( is_array( $saved ) ) {
				foreach ( array_keys( $saved ) as $name ) {
					$names[] = (string) $name;
				}
			}
		}
		$cache[ $key ] = array_values( array_unique( $names ) );
		return $cache[ $key ];
	}

	/**
	 * Whether a field group's location rules include an options page.
	 *
	 * @param array<string,mixed> $group Field group.
	 */
	private static function groupTargetsOptions( array $group ): bool {
		foreach ( (array) ( $group['location'] ?? [] ) as $and ) {
			foreach ( (array) $and as $rule ) {
				if ( is_array( $rule ) && 'options_page' === ( $rule['param'] ?? '' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param array<int,string> $segments Meta key then sub-keys.
	 */
	private function metaValue( int $post_id, array $segments ): mixed {
		if ( ! $post_id || [] === $segments ) {
			return null;
		}
		$key   = (string) array_shift( $segments );
		$value = get_post_meta( $post_id, $key, true );
		return ArrayResolver::walk( $value, $segments );
	}

	/**
	 * @param array<int,string> $segments title | description | titles.KEY | general.KEY | sitemap.KEY.
	 */
	private function rankMathValue( int $post_id, array $segments ): mixed {
		$key = (string) ( $segments[0] ?? '' );
		if ( in_array( $key, [ 'title', 'description' ], true ) ) {
			return $post_id ? get_post_meta( $post_id, 'rank_math_' . $key, true ) : null;
		}
		if ( in_array( $key, [ 'titles', 'general', 'sitemap' ], true ) ) {
			return ArrayResolver::walk( get_option( 'rank-math-options-' . $key, [] ), array_slice( $segments, 1 ) );
		}
		return null;
	}

	/**
	 * @param array<int,string> $segments url | name | description | language.
	 */
	private function siteValue( array $segments ): mixed {
		return match ( (string) ( $segments[0] ?? '' ) ) {
			'url', 'home' => home_url( '/' ),
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'language'    => get_bloginfo( 'language' ),
			default       => null,
		};
	}

	/**
	 * @param array<int,string> $segments Taxonomy then "slugs" | "names" | "first".
	 * @return array<int,string>|string|null
	 */
	private function termsValue( int $post_id, array $segments ): mixed {
		$taxonomy = (string) ( $segments[0] ?? '' );
		if ( ! $post_id || '' === $taxonomy ) {
			return null;
		}
		// "terms.all": every taxonomy of the post type that an editor can see (public, or shown in the admin), so
		// custom taxonomies registered as non-public but managed in the UI still count; internal ones do not.
		$taxonomies = 'all' === $taxonomy
			? array_values(
				array_filter(
					get_object_taxonomies( (string) get_post_type( $post_id ) ),
					static function ( string $t ): bool {
						$object = get_taxonomy( $t );
						return $object && ( ! empty( $object->public ) || ! empty( $object->show_ui ) ) && 'post_format' !== $t;
					}
				)
			)
			: [ $taxonomy ];
		$terms      = [];
		foreach ( $taxonomies as $tax ) {
			$found = get_the_terms( $post_id, $tax );
			if ( is_array( $found ) ) {
				$terms = array_merge( $terms, $found );
			}
		}
		if ( [] === $terms ) {
			return null;
		}
		$mode = (string) ( $segments[1] ?? 'names' );
		$list = array_map( static fn( $term ) => 'slugs' === $mode ? $term->slug : $term->name, $terms );
		return 'first' === $mode ? ( $list[0] ?? null ) : array_values( $list );
	}

	/**
	 * An attachment ID, ACF image array or URL to a URL of the given size ("large", "full" or "1024x576").
	 */
	private function imageUrl( mixed $value, ?string $size ): ?string {
		$size = $size ?: 'full';
		if ( preg_match( '/^(\d+)x(\d+)$/', $size, $m ) ) {
			$size = [ (int) $m[1], (int) $m[2] ];
		}
		$id = 0;
		if ( is_array( $value ) && isset( $value['ID'] ) ) {
			$id = (int) $value['ID'];
		} elseif ( is_array( $value ) && isset( $value['id'] ) ) {
			$id = (int) $value['id'];
		} elseif ( is_numeric( $value ) ) {
			$id = (int) $value;
		} elseif ( is_string( $value ) && str_starts_with( $value, 'http' ) ) {
			return $value;
		} elseif ( is_array( $value ) && isset( $value['url'] ) && is_string( $value['url'] ) ) {
			return $value['url'];
		}
		if ( ! $id ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $id, $size );
		return $url ? (string) $url : null;
	}
}

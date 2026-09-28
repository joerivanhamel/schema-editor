<?php
/**
 * Merges the templates into Rank Math's JSON-LD graph.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Output;

use JoeriVanhamel\SchemaEditor\Render\Context;
use JoeriVanhamel\SchemaEditor\Settings;
use JoeriVanhamel\SchemaEditor\Template\RequestContext;

/**
 * Hooks rank_math/json_ld late (priority 50) so templates see, and may edit or replace, every node Rank Math and
 * the theme produced. Rank Math prints the result as one @graph, so the page never carries two graphs.
 */
final class RankMathBridge {

	public const PRIORITY = 50;

	public function __construct(
		private readonly Engine $engine,
		private readonly Settings $settings
	) {
	}

	public static function rankMathActive(): bool {
		return class_exists( '\RankMath\Helper' ) || function_exists( 'rank_math' );
	}

	/**
	 * Whether Rank Math will print a JSON-LD graph: the plugin is active and its "Schema (Structured Data)"
	 * module is on. With the module off, rank_math/json_ld never runs, so the templates must print their own.
	 */
	public static function rankMathSchemaActive(): bool {
		if ( ! self::rankMathActive() ) {
			return false;
		}
		if ( class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'is_module_active' ) ) {
			return (bool) \RankMath\Helper::is_module_active( 'rich-snippet' );
		}
		$modules = get_option( 'rank_math_modules', [] );
		return is_array( $modules ) && in_array( 'rich-snippet', $modules, true );
	}

	public function register(): void {
		add_filter( 'rank_math/json_ld', [ $this, 'filter' ], self::PRIORITY );
		if ( $this->settings->get( 'force_global_entities' ) ) {
			add_filter( 'rank_math/schema/add_global_entities', [ $this, 'forceGlobalEntities' ] );
		}
		if ( $this->settings->get( 'disable_rankmath_search' ) ) {
			add_filter( 'rank_math/json_ld/disable_search', '__return_true' );
		}
	}

	/**
	 * Rank Math prints Organization, WebSite and WebPage only when the post has a schema type; a page set to
	 * "off" gets nothing, so a template's merge into #webpage would find no node. Print them on every singular.
	 *
	 * @param mixed $can_add Rank Math's own decision.
	 * @return mixed
	 */
	public function forceGlobalEntities( mixed $can_add ): mixed {
		return is_singular() ? true : $can_add;
	}

	/**
	 * @param mixed $data Rank Math's keyed graph.
	 * @return mixed
	 */
	public function filter( mixed $data ): mixed {
		if ( ! is_array( $data ) || is_admin() ) {
			return $data;
		}
		$request = RequestContext::fromWordPress();
		$context = new Context( $request->postId ? get_post( $request->postId ) : null );
		return $this->engine->apply( $data, $request, $context, Engine::MODE_RANKMATH );
	}
}

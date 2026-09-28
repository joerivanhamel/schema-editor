<?php
/**
 * An administrator's view of the graph the plugin would print on its own, on any front-end URL.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Output;

use JoeriVanhamel\SchemaEditor\Render\Context;
use JoeriVanhamel\SchemaEditor\Template\RequestContext;

/**
 * Adding ?schema_editor_preview=standalone to a front-end URL, while logged in as an administrator, answers with
 * the JSON-LD graph the plugin would print in standalone mode for that URL, instead of the page. It works while
 * Rank Math's schema module is still on, so a site can be compared page by page before the switch. Nothing is
 * stored or changed; anyone who is not an administrator gets the normal page.
 */
final class GraphPreview {

	public const PARAMETER = 'schema_editor_preview';

	public function __construct( private readonly Engine $engine ) {
	}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybeRespond' ], 999 );
	}

	public function maybeRespond(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view for administrators.
		$mode = isset( $_GET[ self::PARAMETER ] ) ? sanitize_key( (string) wp_unslash( $_GET[ self::PARAMETER ] ) ) : '';
		if ( 'standalone' !== $mode || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$request = RequestContext::fromWordPress();
		$context = new Context( $request->postId ? get_post( $request->postId ) : null );
		$names   = array_map( static fn( $t ): string => $t->name, $this->engine->matching( $request, Engine::MODE_STANDALONE ) );
		$graph   = $this->engine->apply( [], $request, $context, Engine::MODE_STANDALONE );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		wp_send_json(
			[
				'templates' => $names,
				'graph'     => [
					'@context' => 'https://schema.org',
					'@graph'   => array_values( $graph ),
				],
			]
		);
	}
}

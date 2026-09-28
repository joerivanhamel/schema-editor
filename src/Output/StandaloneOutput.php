<?php
/**
 * Prints the plugin's own graph when Rank Math is not there to print it.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Output;

use JoeriVanhamel\SchemaEditor\Render\Context;
use JoeriVanhamel\SchemaEditor\Template\RequestContext;

/**
 * One <script type="application/ld+json"> with @context and @graph in wp_head. "merge" and "remove" operations
 * still work against nodes earlier templates added.
 */
final class StandaloneOutput {

	public function __construct( private readonly Engine $engine ) {
	}

	public function register(): void {
		add_action( 'wp_head', [ $this, 'print' ], 20 );
	}

	public function print(): void {
		$request = RequestContext::fromWordPress();
		$context = new Context( $request->postId ? get_post( $request->postId ) : null );
		$graph   = $this->engine->apply( [], $request, $context, Engine::MODE_STANDALONE );
		if ( [] === $graph ) {
			return;
		}
		$json = wp_json_encode(
			[
				'@context' => 'https://schema.org',
				'@graph'   => array_values( $graph ),
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( false === $json ) {
			return;
		}
		// JSON-LD is not HTML: "<" inside a string must not close the script element. Unicode-escape it.
		$json = str_replace( '</', '<\/', $json );
		echo '<script type="application/ld+json" class="schema-editor">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD, encoded above.
	}
}

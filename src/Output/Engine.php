<?php
/**
 * Applies every matching template to a graph.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Output;

use JoeriVanhamel\SchemaEditor\Render\Context;
use JoeriVanhamel\SchemaEditor\Render\Renderer;
use JoeriVanhamel\SchemaEditor\Template\Repository;
use JoeriVanhamel\SchemaEditor\Template\RequestContext;
use JoeriVanhamel\SchemaEditor\Template\Targets;
use JoeriVanhamel\SchemaEditor\Template\Template;

/**
 * The one place templates meet a request: pick the ones that apply, render them in priority order, merge.
 *
 * The output mode ("rankmath" when the templates edit Rank Math's graph, "standalone" when the plugin prints its
 * own) decides which templates run: a template marked "output": "standalone" only builds what Rank Math would
 * otherwise provide, so it stays out of Rank Math's graph. Null means "every template" (the Preview, the CLI).
 */
final class Engine {

	public const MODE_RANKMATH   = 'rankmath';
	public const MODE_STANDALONE = 'standalone';

	public function __construct(
		private readonly Repository $repository,
		private readonly Renderer $renderer
	) {
	}

	/**
	 * Templates that apply to the request, in the order they will run.
	 *
	 * @return array<int,Template>
	 */
	public function matching( RequestContext $request, ?string $mode = null ): array {
		return array_values(
			array_filter(
				$this->repository->all(),
				static fn( Template $t ): bool => ( null === $mode || $t->runsIn( $mode ) ) && Targets::applies( $t, $request )
			)
		);
	}

	/**
	 * Rendered operations for the request, template by template.
	 *
	 * @return array<int,array{template:Template,operations:array<int,array<string,mixed>>}>
	 */
	public function operations( RequestContext $request, Context $context, ?string $mode = null ): array {
		$out     = [];
		$context = $context->withRequest( $request );
		foreach ( $this->matching( $request, $mode ) as $template ) {
			$out[] = [
				'template'   => $template,
				'operations' => $this->renderer->render( $template, $context ),
			];
		}
		return $out;
	}

	/**
	 * The graph after every matching template has been applied.
	 *
	 * @param array<string,array<string,mixed>> $graph Keyed nodes to start from (Rank Math's, or empty).
	 * @return array<string,array<string,mixed>>
	 */
	public function apply( array $graph, RequestContext $request, Context $context, ?string $mode = null ): array {
		foreach ( $this->operations( $request, $context, $mode ) as $entry ) {
			$graph = GraphMerger::apply( $graph, $entry['operations'] );
		}
		return $graph;
	}
}

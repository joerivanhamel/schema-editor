<?php
/**
 * WP-CLI: list, import, export and render templates from the terminal or a deploy job.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Cli;

use InvalidArgumentException;
use JoeriVanhamel\SchemaEditor\Admin\Preview;
use JoeriVanhamel\SchemaEditor\Output\Engine;
use JoeriVanhamel\SchemaEditor\Render\Context;
use JoeriVanhamel\SchemaEditor\Template\Repository;
use WP_CLI;
use WP_Post;

/**
 * Manage Schema Editor templates.
 */
final class Command {

	public function __construct(
		private readonly Engine $engine,
		private readonly Repository $repository
	) {
	}

	/**
	 * List templates with their targets, priority and validity.
	 *
	 * ## EXAMPLES
	 *
	 *     wp schema-editor list
	 *
	 * @subcommand list
	 */
	public function listTemplates(): void {
		$rows = [];
		foreach ( $this->repository->all( true ) as $template ) {
			$rows[] = [
				'id'       => $template->postId,
				'name'     => $template->name,
				'enabled'  => $template->enabled ? 'yes' : 'no',
				'priority' => $template->priority,
				'targets'  => implode( ', ', array_map( static fn( array $t ): string => $t['type'] . ( isset( $t['value'] ) ? '=' . (string) $t['value'] : '' ), $template->targets ) ),
				'nodes'    => count( $template->nodes ),
				'status'   => 'valid',
			];
		}
		foreach ( $this->repository->errors() as $error ) {
			$rows[] = [
				'id'       => $error['post_id'],
				'name'     => $error['name'],
				'enabled'  => '',
				'priority' => '',
				'targets'  => '',
				'nodes'    => '',
				'status'   => 'INVALID: ' . $error['error'],
			];
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'name', 'enabled', 'priority', 'targets', 'nodes', 'status' ] );
	}

	/**
	 * Import templates from a JSON file or every *.json file in a directory. Matching names are updated.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : File or directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp schema-editor import templates/examples/
	 *
	 * @param array<int,string> $args Positional arguments.
	 */
	public function import( array $args ): void {
		$path = (string) ( $args[0] ?? '' );
		if ( ! file_exists( $path ) ) {
			WP_CLI::error( "Not found: $path" );
		}
		$files = is_dir( $path ) ? glob( rtrim( $path, '/\\' ) . '/*.json' ) ?: [] : [ $path ];
		sort( $files );
		$documents = [];
		foreach ( $files as $file ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file.
			if ( ! is_array( $decoded ) ) {
				WP_CLI::error( "Not valid JSON: $file" );
			}
			foreach ( array_is_list( $decoded ) ? $decoded : [ $decoded ] as $document ) {
				$documents[] = $document;
			}
		}
		try {
			$report = $this->repository->import( $documents );
		} catch ( InvalidArgumentException $e ) {
			WP_CLI::error( 'Import stopped: ' . $e->getMessage() );
		}
		foreach ( $report as $entry ) {
			WP_CLI::log( sprintf( '%s: %s (#%d)', $entry['action'], $entry['name'], $entry['post_id'] ) );
		}
		WP_CLI::success( count( $report ) . ' template(s) imported.' );
	}

	/**
	 * Export every template as JSON to stdout or a file.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<file>]
	 * : Write to this file instead of stdout.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public function export( array $args, array $assoc_args ): void {
		$documents = array_map( static fn( $t ) => $t->toArray(), $this->repository->all( true ) );
		$json      = Repository::encode( $documents );
		if ( ! empty( $assoc_args['file'] ) ) {
			file_put_contents( (string) $assoc_args['file'], $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI.
			WP_CLI::success( 'Written to ' . $assoc_args['file'] );
			return;
		}
		WP_CLI::line( $json );
	}

	/**
	 * Render the graph the plugin would print on its own (standalone mode) for a post.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post ID or URL.
	 *
	 * ## EXAMPLES
	 *
	 *     wp schema-editor render 6
	 *     wp schema-editor render https://example.com/consulting/
	 *
	 * @param array<int,string> $args Positional arguments.
	 */
	public function render( array $args ): void {
		$target  = (string) ( $args[0] ?? '' );
		$post_id = is_numeric( $target ) ? (int) $target : (int) url_to_postid( $target );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post ) {
			WP_CLI::error( 'No post found for ' . $target );
		}
		$request = Preview::requestFor( $post );
		$matched = $this->engine->matching( $request, Engine::MODE_STANDALONE );
		WP_CLI::log( 'Request: ' . $request->postType . ' #' . $request->postId . ' template ' . $request->pageTemplate );
		WP_CLI::log( 'Templates: ' . ( $matched ? implode( ', ', array_map( static fn( $t ) => $t->name, $matched ) ) : 'none' ) );
		$graph = $this->engine->apply( [], $request, new Context( $post ), Engine::MODE_STANDALONE );
		WP_CLI::line( Repository::encode( [ '@context' => 'https://schema.org', '@graph' => array_values( $graph ) ] ) );
	}
}

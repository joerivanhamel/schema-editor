<?php
/**
 * Preview: render the templates for a chosen post without leaving the editor.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Admin;

use InvalidArgumentException;
use JoeriVanhamel\SchemaEditor\Output\Engine;
use JoeriVanhamel\SchemaEditor\Output\GraphMerger;
use JoeriVanhamel\SchemaEditor\Render\Context;
use JoeriVanhamel\SchemaEditor\Render\Renderer;
use JoeriVanhamel\SchemaEditor\Render\WordPressResolver;
use JoeriVanhamel\SchemaEditor\Template\Repository;
use JoeriVanhamel\SchemaEditor\Template\RequestContext;
use JoeriVanhamel\SchemaEditor\Template\Targets;
use JoeriVanhamel\SchemaEditor\Template\Template;
use WP_Post;

/**
 * AJAX endpoint. Renders the document currently in the editor (unsaved is fine) against a post, and also every
 * other saved template that applies, so the preview shows the nodes the page will actually get from this plugin.
 * Rank Math's own nodes are not shown here: they exist only on the front-end request.
 */
final class Preview {

	public const NONCE  = 'schema_editor_preview';
	public const ACTION = 'schema_editor_preview';

	public function __construct(
		private readonly Engine $engine,
		private readonly Repository $repository
	) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handle' ] );
	}

	public static function box(): void {
		echo '<p>' . esc_html__( 'Post ID or URL to preview against:', 'schema-editor' ) . ' ';
		echo '<input type="text" id="schema_editor_preview_target" class="regular-text" placeholder="123 or https://…/garden-design/"> ';
		echo '<button type="button" class="button" id="schema_editor_preview_run">' . esc_html__( 'Preview', 'schema-editor' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Shows the nodes this plugin adds for that post, from the document as it is in the editor plus every other saved template that applies. Rank Math\'s own nodes appear only on the live page.', 'schema-editor' ) . '</p>';
		echo '<pre id="schema_editor_preview_output" style="max-height:480px;overflow:auto;background:#f6f7f7;padding:12px;border:1px solid #dcdcde"></pre>';
	}

	public function handle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'schema-editor' ) ], 403 );
		}
		$target   = trim( (string) wp_unslash( $_POST['target'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- resolved to a post ID below.
		$document = (string) wp_unslash( $_POST['document'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, validated below.
		$post_id  = is_numeric( $target ) ? (int) $target : (int) url_to_postid( $target );
		$post     = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post ) {
			wp_send_json_error( [ 'message' => __( 'No post found for that ID or URL.', 'schema-editor' ) ] );
		}
		$editing_id = (int) ( $_POST['post_id'] ?? 0 );
		try {
			$current = '' !== trim( $document ) ? Template::fromJson( $document, $editing_id, 'Current document' ) : null;
		} catch ( InvalidArgumentException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		$request  = self::requestFor( $post );
		$context  = new Context( $post );
		$renderer = new Renderer( new WordPressResolver() );
		$graph    = [];
		$matched  = [];
		$list     = $this->repository->all( true );
		if ( $current ) {
			$list = array_filter( $list, static fn( Template $t ): bool => $t->postId !== $editing_id );
			$list[] = $current;
		}
		usort( $list, static fn( Template $a, Template $b ): int => [ $a->priority, $a->name ] <=> [ $b->priority, $b->name ] );
		foreach ( $list as $template ) {
			if ( ! Targets::applies( $template, $request ) ) {
				continue;
			}
			$operations = $renderer->render( $template, $context );
			$matched[]  = [
				'name'       => $template->name,
				'priority'   => $template->priority,
				'operations' => $operations,
			];
			$graph      = GraphMerger::apply( $graph, $operations );
		}
		wp_send_json_success(
			[
				'request'  => [
					'post_id'       => $request->postId,
					'post_type'     => $request->postType,
					'page_template' => $request->pageTemplate,
					'slug'          => $request->slug,
				],
				'matched'  => $matched,
				'graph'    => [
					'@context' => 'https://schema.org',
					'@graph'   => array_values( $graph ),
				],
				'warnings' => $this->engine->matching( $request ) ? [] : [ __( 'No saved template applies to this post.', 'schema-editor' ) ],
				'fields'   => self::fieldInventory( $post ),
			]
		);
	}

	/**
	 * The ACF field names available on a post with a glimpse of each value, so a template author can see what
	 * "acf.*" can address without opening the post. Empty when ACF is absent.
	 *
	 * @return array<int,array{name:string,value:string}>
	 */
	public static function fieldInventory( WP_Post $post ): array {
		if ( ! function_exists( 'get_field' ) ) {
			return [];
		}
		$out = [];
		foreach ( WordPressResolver::acfFieldNames( $post->ID ) as $name ) {
			$value = get_field( $name, $post->ID );
			if ( is_array( $value ) ) {
				$glimpse = isset( $value['ID'] ) ? 'attachment #' . (int) $value['ID'] : 'array(' . count( $value ) . ')';
			} elseif ( is_object( $value ) ) {
				$glimpse = 'object';
			} elseif ( null === $value || false === $value || '' === $value ) {
				$glimpse = '';
			} else {
				$glimpse = mb_substr( wp_strip_all_tags( (string) $value ), 0, 60 );
			}
			$out[] = [
				'name'  => $name,
				'value' => $glimpse,
			];
		}
		return $out;
	}

	/**
	 * A request context for a post, as if it were being viewed.
	 */
	public static function requestFor( WP_Post $post ): RequestContext {
		$template = (string) get_page_template_slug( $post );
		$terms    = [];
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$attached = get_the_terms( $post, $taxonomy );
			if ( is_array( $attached ) ) {
				foreach ( $attached as $term ) {
					$terms[] = $taxonomy . ':' . $term->slug;
				}
			}
		}
		return new RequestContext(
			$post->ID,
			$post->post_type,
			'' !== $template ? basename( $template ) : 'default',
			$post->post_name,
			true,
			(int) get_option( 'page_on_front' ) === $post->ID,
			(int) get_option( 'page_for_posts' ) === $post->ID,
			false,
			'',
			'',
			false,
			false,
			$terms
		);
	}
}

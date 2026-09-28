<?php
/**
 * The schema_template post type and its list-table columns.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Admin;

use InvalidArgumentException;
use JoeriVanhamel\SchemaEditor\Template\Repository;
use JoeriVanhamel\SchemaEditor\Template\Template;
use WP_Post;

/**
 * Registers the type (admin only, never queryable on the front end) and shows targets, priority and validity in
 * the list.
 */
final class PostType {

	public function register(): void {
		add_action( 'init', [ $this, 'registerPostType' ] );
		add_filter( 'manage_' . Repository::POST_TYPE . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_' . Repository::POST_TYPE . '_posts_custom_column', [ $this, 'column' ], 10, 2 );
		add_filter( 'enter_title_here', [ $this, 'titlePlaceholder' ], 10, 2 );
	}

	public function registerPostType(): void {
		register_post_type(
			Repository::POST_TYPE,
			[
				'labels'              => [
					'name'               => __( 'Schema Templates', 'schema-editor' ),
					'singular_name'      => __( 'Schema Template', 'schema-editor' ),
					'menu_name'          => __( 'Schema Editor', 'schema-editor' ),
					'add_new'            => __( 'Add Template', 'schema-editor' ),
					'add_new_item'       => __( 'Add Schema Template', 'schema-editor' ),
					'edit_item'          => __( 'Edit Schema Template', 'schema-editor' ),
					'new_item'           => __( 'New Schema Template', 'schema-editor' ),
					'all_items'          => __( 'All Templates', 'schema-editor' ),
					'search_items'       => __( 'Search templates', 'schema-editor' ),
					'not_found'          => __( 'No templates yet.', 'schema-editor' ),
					'not_found_in_trash' => __( 'No templates in the bin.', 'schema-editor' ),
				],
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'query_var'           => false,
				'rewrite'             => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-editor-code',
				'menu_position'       => 81,
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'supports'            => [ 'title' ],
			]
		);
	}

	/**
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function columns( array $columns ): array {
		$date = $columns['date'] ?? '';
		unset( $columns['date'] );
		$columns['se_targets']  = __( 'Applies to', 'schema-editor' );
		$columns['se_priority'] = __( 'Priority', 'schema-editor' );
		$columns['se_status']   = __( 'Status', 'schema-editor' );
		if ( '' !== $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	public function column( string $column, int $post_id ): void {
		if ( ! str_starts_with( $column, 'se_' ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		try {
			$template = Template::fromJson( $post->post_content, $post->ID, $post->post_title );
		} catch ( InvalidArgumentException $e ) {
			if ( 'se_status' === $column ) {
				echo '<span style="color:#b32d2e">' . esc_html__( 'Invalid', 'schema-editor' ) . '</span><br><small>' . esc_html( $e->getMessage() ) . '</small>';
			} else {
				echo '&mdash;';
			}
			return;
		}
		switch ( $column ) {
			case 'se_targets':
				$labels = array_map(
					static fn( array $t ): string => $t['type'] . ( isset( $t['value'] ) && null !== $t['value'] ? ' = ' . (string) $t['value'] : '' ),
					$template->targets
				);
				echo esc_html( $labels ? implode( ', ', $labels ) : __( 'nothing (no targets)', 'schema-editor' ) );
				if ( $template->exclude ) {
					echo '<br><small>' . esc_html( __( 'except', 'schema-editor' ) . ' ' . implode( ', ', array_map( static fn( array $t ): string => $t['type'] . ( isset( $t['value'] ) ? ' = ' . (string) $t['value'] : '' ), $template->exclude ) ) ) . '</small>';
				}
				break;
			case 'se_priority':
				echo esc_html( (string) $template->priority );
				break;
			case 'se_status':
				echo $template->enabled
					? '<span style="color:#00753a">' . esc_html__( 'Enabled', 'schema-editor' ) . '</span>'
					: '<span style="color:#757575">' . esc_html__( 'Disabled', 'schema-editor' ) . '</span>';
				echo ' <small>' . esc_html( sprintf( /* translators: %d: node count */ _n( '%d node', '%d nodes', count( $template->nodes ), 'schema-editor' ), count( $template->nodes ) ) ) . '</small>';
				break;
		}
	}

	public function titlePlaceholder( string $placeholder, WP_Post $post ): string {
		return Repository::POST_TYPE === $post->post_type ? __( 'Template name, e.g. Service page', 'schema-editor' ) : $placeholder;
	}
}

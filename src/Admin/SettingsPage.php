<?php
/**
 * Settings, import and export.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Admin;

use InvalidArgumentException;
use JoeriVanhamel\SchemaEditor\Settings;
use JoeriVanhamel\SchemaEditor\Template\Repository;
use JoeriVanhamel\SchemaEditor\Template\Template;

/**
 * A submenu under Schema Editor. Export writes every template (valid or not) as one JSON file; import reads a file
 * holding one document or an array of documents and creates or updates by name.
 */
final class SettingsPage {

	private const SLUG          = 'schema-editor-settings';
	private const EXPORT_ACTION = 'schema_editor_export';
	private const IMPORT_ACTION = 'schema_editor_import';

	public function __construct(
		private readonly Settings $settings,
		private readonly Repository $repository
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'registerSettings' ] );
		add_action( 'admin_post_' . self::EXPORT_ACTION, [ $this, 'export' ] );
		add_action( 'admin_post_' . self::IMPORT_ACTION, [ $this, 'import' ] );
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Repository::POST_TYPE,
			__( 'Schema Editor Settings', 'schema-editor' ),
			__( 'Settings & Import', 'schema-editor' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function registerSettings(): void {
		register_setting(
			'schema_editor',
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
				'default'           => Settings::DEFAULTS,
			]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$values    = $this->settings->all();
		$rank_math = class_exists( '\RankMath\Helper' ) || function_exists( 'rank_math' );
		$message   = isset( $_GET['se_message'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['se_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		echo '<div class="wrap"><h1>' . esc_html__( 'Schema Editor', 'schema-editor' ) . '</h1>';
		if ( '' !== $message ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
		echo '<p>' . esc_html( $rank_math ? __( 'Rank Math is active: templates are merged into its graph and it prints the JSON-LD.', 'schema-editor' ) : __( 'Rank Math is not active: the plugin prints its own JSON-LD graph in the head.', 'schema-editor' ) ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'schema_editor' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="se_output_mode">' . esc_html__( 'Output', 'schema-editor' ) . '</label></th><td>';
		echo '<select id="se_output_mode" name="' . esc_attr( Settings::OPTION ) . '[output_mode]">';
		foreach ( [
			'auto'       => __( 'Automatic (inside Rank Math\'s graph while its Schema module is on, else the plugin\'s own graph)', 'schema-editor' ),
			'rankmath'   => __( 'Rank Math only', 'schema-editor' ),
			'standalone' => __( 'Standalone script (the plugin prints the whole graph)', 'schema-editor' ),
			'off'        => __( 'Off', 'schema-editor' ),
		] as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $values['output_mode'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		$mode = \JoeriVanhamel\SchemaEditor\Plugin::resolveMode( $this->settings );
		echo '<p class="description">' . esc_html(
			match ( $mode ) {
				'rankmath'   => __( 'In force now: templates edit Rank Math\'s graph. Templates marked "output": "standalone" are not running.', 'schema-editor' ),
				'standalone' => __( 'In force now: the plugin prints the whole graph. Templates marked "output": "rankmath" are not running.', 'schema-editor' ),
				default      => __( 'In force now: no structured data is printed by this plugin.', 'schema-editor' ),
			}
		) . ' ' . esc_html__( 'An administrator can add ?schema_editor_preview=standalone to any page address to see the graph the plugin would print on its own.', 'schema-editor' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="se_breadcrumb_callback">' . esc_html__( 'Breadcrumb trail function', 'schema-editor' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text code" id="se_breadcrumb_callback" name="' . esc_attr( Settings::OPTION ) . '[breadcrumb_callback]" value="' . esc_attr( (string) $values['breadcrumb_callback'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Optional. The name of the theme function that returns the visible breadcrumb trail, so page.breadcrumbs (and a BreadcrumbList built from it) matches what visitors see. Leave empty to build the trail from page titles, the posts page, post type archives and terms.', 'schema-editor' ) . '</p></td></tr>';
		$this->checkbox( 'force_global_entities', __( 'Always print Rank Math\'s Organization, WebSite and WebPage nodes on single posts and pages (Rank Math skips them when a post\'s schema type is "off", which leaves nothing for a template to merge into)', 'schema-editor' ), (bool) $values['force_global_entities'] );
		$this->checkbox( 'disable_rankmath_search', __( 'Remove the sitelinks search box (WebSite.potentialAction) from Rank Math\'s graph', 'schema-editor' ), (bool) $values['disable_rankmath_search'] );
		$this->checkbox( 'debug_comment', __( 'Print an HTML comment in the footer naming the templates that ran (for checking on staging)', 'schema-editor' ), (bool) $values['debug_comment'] );
		$this->checkbox( 'delete_on_uninstall', __( 'Delete every template when the plugin is deleted', 'schema-editor' ), (bool) $values['delete_on_uninstall'] );
		echo '</tbody></table>';
		submit_button();
		echo '</form>';

		echo '<hr><h2>' . esc_html__( 'Export', 'schema-editor' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::EXPORT_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT_ACTION ) . '">';
		submit_button( __( 'Download all templates as JSON', 'schema-editor' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Import', 'schema-editor' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A JSON file holding one template document or an array of them. A template with the same name as an existing one replaces it.', 'schema-editor' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::IMPORT_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::IMPORT_ACTION ) . '">';
		echo '<input type="file" name="schema_editor_file" accept="application/json,.json" required> ';
		submit_button( __( 'Import', 'schema-editor' ), 'primary', 'submit', false );
		echo '</form></div>';
	}

	private function checkbox( string $key, string $label, bool $checked ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( Settings::OPTION ) . '[' . esc_attr( $key ) . ']" value="1"' . checked( $checked, true, false ) . '> ' . esc_html__( 'Enabled', 'schema-editor' ) . '</label></td></tr>';
	}

	public function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'schema-editor' ) );
		}
		check_admin_referer( self::EXPORT_ACTION );
		$documents = [];
		foreach ( get_posts(
			[
				'post_type'      => Repository::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		) as $post ) {
			$decoded = json_decode( $post->post_content, true );
			if ( is_array( $decoded ) ) {
				$decoded['name'] = $post->post_title;
				$documents[]     = $decoded;
			}
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="schema-templates-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo Repository::encode( $documents ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	public function import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'schema-editor' ) );
		}
		check_admin_referer( self::IMPORT_ACTION );
		$file = $_FILES['schema_editor_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read as a local file below.
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->back( __( 'No file received.', 'schema-editor' ) );
		}
		$json    = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- uploaded file.
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			$this->back( __( 'The file is not valid JSON.', 'schema-editor' ) );
		}
		$documents = array_is_list( $decoded ) ? $decoded : [ $decoded ];
		try {
			$report = $this->repository->import( $documents );
		} catch ( InvalidArgumentException $e ) {
			$this->back( sprintf( /* translators: %s: error */ __( 'Import stopped: %s', 'schema-editor' ), $e->getMessage() ) );
		}
		$this->back( sprintf( /* translators: %d: count */ __( 'Imported %d template(s): ', 'schema-editor' ), count( $report ) ) . implode( ', ', array_map( static fn( array $r ): string => $r['name'] . ' (' . $r['action'] . ')', $report ) ) );
	}

	private function back( string $message ): never {
		wp_safe_redirect( add_query_arg( [ 'post_type' => Repository::POST_TYPE, 'page' => self::SLUG, 'se_message' => rawurlencode( $message ) ], admin_url( 'edit.php' ) ) );
		exit;
	}
}

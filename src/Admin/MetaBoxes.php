<?php
/**
 * The template editor: a JSON code editor, a reference panel and the save handler.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Admin;

use InvalidArgumentException;
use JoeriVanhamel\SchemaEditor\Template\Repository;
use JoeriVanhamel\SchemaEditor\Template\Targets;
use JoeriVanhamel\SchemaEditor\Template\Template;
use WP_Post;

/**
 * The document is edited as one JSON text. On save it is validated; an invalid document is still stored (so
 * nothing is lost) but flagged, and the runtime skips it until it parses.
 */
final class MetaBoxes {

	private const NONCE  = 'schema_editor_document_nonce';
	private const FIELD  = 'schema_editor_document';
	private const FIELD_ID = 'schema_editor_document_field';
	private const NOTICE = 'schema_editor_notice_';

	public function __construct( private readonly Repository $repository ) {
	}

	public function register(): void {
		add_action( 'add_meta_boxes_' . Repository::POST_TYPE, [ $this, 'addBoxes' ] );
		add_action( 'save_post_' . Repository::POST_TYPE, [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
		add_filter( 'wp_insert_post_data', [ $this, 'keepContentOnQuickEdit' ], 10, 2 );
	}

	public function addBoxes(): void {
		add_meta_box( 'schema_editor_document', __( 'Template document (JSON)', 'schema-editor' ), [ $this, 'documentBox' ], Repository::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'schema_editor_preview', __( 'Preview', 'schema-editor' ), [ Preview::class, 'box' ], Repository::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'schema_editor_reference', __( 'Reference', 'schema-editor' ), [ $this, 'referenceBox' ], Repository::POST_TYPE, 'side', 'default' );
	}

	public function documentBox( WP_Post $post ): void {
		$content = $post->post_content;
		if ( '' === trim( $content ) ) {
			$content = Repository::encode(
				[
					'name'     => $post->post_title,
					'enabled'  => true,
					'priority' => 10,
					'targets'  => [ [ 'type' => 'post_type', 'value' => 'page' ] ],
					'exclude'  => [],
					'nodes'    => [
						[
							'@op'       => 'add',
							'@key'      => 'Example',
							'@required' => [ 'name' ],
							'@type'     => 'Thing',
							'@id'       => '{{post.permalink}}#thing',
							'name'      => '{{post.title}}',
							'url'       => '{{post.permalink}}',
						],
					],
				]
			);
		}
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<p class="description">' . esc_html__( 'Placeholders are written as {{post.title}}; alternatives as {{acf.summary || rankmath.description}}; filters as {{acf.hero|image:large}}. A property whose value resolves to nothing is left out. See the Reference panel.', 'schema-editor' ) . '</p>';
		// The textarea id must differ from the meta box id (which WordPress also renders as an element id).
		echo '<textarea id="' . esc_attr( self::FIELD_ID ) . '" name="' . esc_attr( self::FIELD ) . '" rows="28" style="width:100%;font-family:monospace" spellcheck="false">' . esc_textarea( $content ) . '</textarea>';
	}

	public function referenceBox(): void {
		$targets = implode( ', ', Targets::TYPES );
		echo '<p><strong>' . esc_html__( 'Targets', 'schema-editor' ) . '</strong><br><code style="font-size:11px">' . esc_html( $targets ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'Operations', 'schema-editor' ) . '</strong><br>';
		echo '<code>@op</code> add (default) | merge | remove<br>';
		echo '<code>@match</code> <code>#organization</code>, <code>@type:WebPage</code>, <code>@key:Service</code><br>';
		echo '<code>@key</code>, <code>@required</code>, <code>@if</code> (one expression or a list; <code>a == b</code>, <code>a != b</code>; also on nested objects), <code>@repeat</code> (WP_Query args), <code>@each</code> (array path or query; on a node or a value; <code>loop.index</code>)</p>';
		echo '<p><strong>' . esc_html__( 'Template keys', 'schema-editor' ) . '</strong><br>';
		echo '<code>"output"</code> any | standalone | rankmath<br><code>"vars"</code> { "name": "expression" }, read as <code>var.name</code></p>';
		echo '<p><strong>' . esc_html__( 'Values', 'schema-editor' ) . '</strong><br>';
		echo '<code>post.title|permalink|slug|excerpt|content|date|modified|featured_image|author.name</code><br>';
		echo '<code>acf.field</code>, <code>acf.*_hero_image</code>, <code>acf.field.sub</code><br>';
		echo '<code>option.field</code> (ACF options), <code>meta.key</code>, <code>settings.name</code><br>';
		echo '<code>rankmath.title|description</code>, <code>rankmath.titles.KEY</code><br>';
		echo '<code>site.url|home_url|name|language</code>, <code>terms.taxonomy</code>, <code>item.*</code>, <code>parent.*</code><br>';
		echo '<code>page.url|title|description|breadcrumbs</code><br>';
		echo '<code>request.is_front_page|is_home|is_singular|is_page|is_collection|is_search|is_author|is_404</code></p>';
		echo '<p><strong>' . esc_html__( 'Filters', 'schema-editor' ) . '</strong><br>';
		echo '<code>image:size</code> <code>permalink</code> <code>year</code> <code>int</code> <code>date:c</code> <code>first_word</code> <code>slug</code> <code>lower</code> <code>words:55</code> <code>join</code> <code>first</code> <code>youtube_embed</code> <code>youtube_watch</code> <code>iso_duration</code> <code>raw</code><br>';
		echo '<code>not</code> <code>before:,</code> <code>after:,</code> <code>split:x</code> <code>lines</code> <code>pluck:key</code> <code>key:a.b</code> <code>last</code> <code>unique</code> <code>time</code> <code>opening_hours</code> <code>map_url</code><br>';
		echo '<code>image_width</code> <code>image_height</code> <code>image_alt</code> <code>post:title</code> <code>meta:key</code> <code>acf:field</code> <code>template_page</code> <code>find_post:type</code></p>';
	}

	public function assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || Repository::POST_TYPE !== get_current_screen()?->post_type ) {
			return;
		}
		$editor = wp_enqueue_code_editor( [ 'type' => 'application/json' ] ); // false when the user has switched syntax highlighting off
		$deps   = [ 'jquery', 'wp-util' ];
		if ( false !== $editor ) {
			$deps[] = 'code-editor'; // our script must run after CodeMirror is registered
		}
		// Cache-bust by file time while developing (SCRIPT_DEBUG), by plugin version otherwise.
		$version = static fn( string $file ): string => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? (string) filemtime( SCHEMA_EDITOR_DIR . '/assets/' . $file ) : SCHEMA_EDITOR_VERSION;
		wp_enqueue_script( 'schema-editor-admin', plugins_url( 'assets/admin.js', SCHEMA_EDITOR_FILE ), $deps, $version( 'admin.js' ), true );
		wp_enqueue_style( 'schema-editor-admin', plugins_url( 'assets/admin.css', SCHEMA_EDITOR_FILE ), [], $version( 'admin.css' ) );
		wp_localize_script(
			'schema-editor-admin',
			'schemaEditor',
			[
				'codeEditor' => $editor,
				'fieldId'    => self::FIELD_ID,
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( Preview::NONCE ),
				'postId'     => (int) ( $_GET['post'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, screen context.
			]
		);
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( (string) wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}
		// The document is JSON the site owner wrote; it is stored verbatim and only ever printed through wp_json_encode.
		$document = (string) wp_unslash( $_POST[ self::FIELD ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, validated below.
		$notice   = '';
		try {
			$template = Template::fromJson( $document, $post_id, $post->post_title );
			$array    = $template->toArray();
			// The post title is the name; keep the two in step whichever was edited.
			$array['name'] = '' !== trim( $post->post_title ) ? $post->post_title : $template->name;
			$document      = Repository::encode( $array );
		} catch ( InvalidArgumentException $e ) {
			$notice = $e->getMessage();
		}
		remove_action( 'save_post_' . Repository::POST_TYPE, [ $this, 'save' ], 10 );
		wp_update_post(
			wp_slash(
				[
					'ID'           => $post_id,
					'post_content' => $document,
				]
			)
		);
		add_action( 'save_post_' . Repository::POST_TYPE, [ $this, 'save' ], 10, 2 );
		set_transient( self::NOTICE . get_current_user_id(), $notice, 60 );
		$this->repository->flush();
	}

	/**
	 * Quick Edit submits no document; keep the stored content instead of blanking it.
	 *
	 * @param array<string,mixed> $data    Post data.
	 * @param array<string,mixed> $postarr Submitted array.
	 * @return array<string,mixed>
	 */
	public function keepContentOnQuickEdit( array $data, array $postarr ): array {
		// Only a Quick Edit that arrives with an empty body. Every other save (the editor, import, WP-CLI, code) must
		// be able to replace the document; 0.1.0 to 0.1.3 applied this to all admin saves and silently undid
		// re-imports of an existing template.
		$quick_edit = wp_doing_ajax() && 'inline-save' === ( $_POST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress verified the inline-save nonce.
		if ( $quick_edit && Repository::POST_TYPE === ( $data['post_type'] ?? '' ) && ! empty( $postarr['ID'] ) && '' === trim( (string) ( $data['post_content'] ?? '' ) ) ) {
			$existing = get_post_field( 'post_content', (int) $postarr['ID'] );
			if ( is_string( $existing ) && '' !== $existing ) {
				$data['post_content'] = $existing;
			}
		}
		return $data;
	}

	public function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || Repository::POST_TYPE !== $screen->post_type ) {
			return;
		}
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( false !== $notice ) {
			delete_transient( $key );
			if ( '' !== $notice ) {
				echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Schema Editor: this template is not valid and will not be applied until it is fixed.', 'schema-editor' ) . '</strong><br>' . esc_html( (string) $notice ) . '</p></div>';
			}
		}
		foreach ( $this->repository->errors() as $error ) {
			if ( 'edit' === $screen->base ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( /* translators: 1: template name, 2: error */ __( 'Schema Editor: "%1$s" is invalid and is being skipped: %2$s', 'schema-editor' ), $error['name'], $error['error'] ) ) . '</p></div>';
			}
		}
	}
}

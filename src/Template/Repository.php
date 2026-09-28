<?php
/**
 * Templates stored as posts of the schema_template type; the document JSON lives in post_content.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Template;

use InvalidArgumentException;
use WP_Post;

/**
 * Reads, validates and writes templates. Invalid documents are kept (so the author can fix them) but never applied;
 * their errors are listed by errors() for the admin notice and the CLI.
 */
final class Repository {

	public const POST_TYPE = 'schema_template';

	/** @var array<int,Template>|null */
	private ?array $templates = null;

	/** @var array<int,array{post_id:int,name:string,error:string}> */
	private array $errors = [];

	/**
	 * Every valid template, sorted by priority then title. Disabled ones are included only on request.
	 *
	 * @return array<int,Template>
	 */
	public function all( bool $include_disabled = false ): array {
		$this->load();
		$list = $this->templates ?? [];
		if ( ! $include_disabled ) {
			$list = array_filter( $list, static fn( Template $t ): bool => $t->enabled );
		}
		return array_values( $list );
	}

	/**
	 * @return array<int,array{post_id:int,name:string,error:string}>
	 */
	public function errors(): array {
		$this->load();
		return $this->errors;
	}

	public function get( int $post_id ): ?Template {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		try {
			return Template::fromJson( $post->post_content, $post->ID, $post->post_title );
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * Create or update a template from a document array. The post title follows the document's name.
	 *
	 * @param array<string,mixed> $document Template document.
	 * @return int Post ID.
	 *
	 * @throws InvalidArgumentException When the document is invalid.
	 */
	public function save( array $document, int $post_id = 0 ): int {
		$template = Template::fromArray( $document, $post_id );
		$args     = [
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $template->name,
			'post_content' => self::encode( $template->toArray() ),
		];
		if ( $post_id ) {
			$args['ID'] = $post_id;
			$result     = wp_update_post( wp_slash( $args ), true );
		} else {
			$result = wp_insert_post( wp_slash( $args ), true );
		}
		if ( is_wp_error( $result ) ) {
			throw new InvalidArgumentException( $result->get_error_message() );
		}
		$this->templates = null;
		return (int) $result;
	}

	/**
	 * Import documents; a document whose name matches an existing template updates it.
	 *
	 * @param array<int,array<string,mixed>> $documents Documents.
	 * @return array<int,array{post_id:int,name:string,action:string}>
	 *
	 * @throws InvalidArgumentException When a document is invalid (nothing before it is rolled back).
	 */
	public function import( array $documents ): array {
		$report = [];
		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) ) {
				throw new InvalidArgumentException( 'Every import entry must be a template object.' );
			}
			$name     = trim( (string) ( $document['name'] ?? '' ) );
			$existing = '' !== $name ? $this->findByName( $name ) : 0;
			$post_id  = $this->save( $document, $existing );
			$report[] = [
				'post_id' => $post_id,
				'name'    => $name,
				'action'  => $existing ? 'updated' : 'created',
			];
		}
		return $report;
	}

	/**
	 * Post ID of the template with this exact name, or 0.
	 */
	public function findByName( string $name ): int {
		$posts = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'title'          => $name,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);
		return (int) ( $posts[0] ?? 0 );
	}

	/**
	 * Pretty JSON, the way the editor shows it.
	 *
	 * @param array<string,mixed> $document Document.
	 */
	public static function encode( array $document ): string {
		return (string) wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public function flush(): void {
		$this->templates = null;
		$this->errors    = [];
	}

	private function load(): void {
		if ( null !== $this->templates ) {
			return;
		}
		$this->templates = [];
		$this->errors    = [];
		$posts           = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);
		foreach ( $posts as $post ) {
			try {
				$this->templates[] = Template::fromJson( $post->post_content, $post->ID, $post->post_title );
			} catch ( InvalidArgumentException $e ) {
				$this->errors[] = [
					'post_id' => $post->ID,
					'name'    => $post->post_title,
					'error'   => $e->getMessage(),
				];
			}
		}
		usort( $this->templates, static fn( Template $a, Template $b ): int => [ $a->priority, $a->name ] <=> [ $b->priority, $b->name ] );
	}
}

<?php
/**
 * Plugin settings: one option, typed access.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor;

/**
 * Option "schema_editor_settings".
 */
final class Settings {

	public const OPTION = 'schema_editor_settings';

	public const DEFAULTS = [
		'output_mode'             => 'auto',   // auto | rankmath | standalone | off
		'force_global_entities'   => true,     // Rank Math skips Organization, WebSite and WebPage on posts whose schema type is "off"; print them anyway
		'disable_rankmath_search' => false,    // drop the WebSite SearchAction (sitelinks search box) from Rank Math's graph
		'debug_comment'           => false,    // print an HTML comment naming the templates that ran
		'delete_on_uninstall'     => false,    // remove the templates when the plugin is deleted
	];

	/** @var array<string,mixed>|null */
	private ?array $values = null;

	public function get( string $key ): mixed {
		$this->load();
		return $this->values[ $key ] ?? self::DEFAULTS[ $key ] ?? null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$this->load();
		return $this->values ?? self::DEFAULTS;
	}

	/**
	 * Sanitise a submitted settings array (Settings API callback).
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];
		$mode  = (string) ( $input['output_mode'] ?? 'auto' );
		return [
			'output_mode'             => in_array( $mode, [ 'auto', 'rankmath', 'standalone', 'off' ], true ) ? $mode : 'auto',
			'force_global_entities'   => ! empty( $input['force_global_entities'] ),
			'disable_rankmath_search' => ! empty( $input['disable_rankmath_search'] ),
			'debug_comment'           => ! empty( $input['debug_comment'] ),
			'delete_on_uninstall'     => ! empty( $input['delete_on_uninstall'] ),
		];
	}

	private function load(): void {
		if ( null !== $this->values ) {
			return;
		}
		$stored       = get_option( self::OPTION, [] );
		$this->values = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : [] );
	}
}

<?php
/**
 * Plugin Name:       Schema Editor
 * Plugin URI:        https://www.joerivanhamel.com/
 * Description:       Write JSON-LD schema templates with database-driven placeholders and display targets. Merges into Rank Math's graph when Rank Math is active, prints its own graph otherwise. Nothing is hardcoded: every value comes from the post, its fields, the site's options or Rank Math's settings.
 * Version:           0.1.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Joeri Vanhamel
 * Author URI:        https://www.joerivanhamel.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       schema-editor
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCHEMA_EDITOR_VERSION', '0.1.2' );
define( 'SCHEMA_EDITOR_FILE', __FILE__ );
define( 'SCHEMA_EDITOR_DIR', __DIR__ );

// Composer's autoloader when the plugin is developed in place; a minimal PSR-4 loader when it is installed from a zip.
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'JoeriVanhamel\\SchemaEditor\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}

add_action(
	'plugins_loaded',
	static function (): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Schema Editor needs PHP 8.1 or newer and is inactive until then.', 'schema-editor' ) . '</p></div>';
				}
			);
			return;
		}
		( new JoeriVanhamel\SchemaEditor\Plugin() )->boot();
	}
);

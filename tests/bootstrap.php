<?php
/**
 * PHPUnit bootstrap: the pure classes need no WordPress, so only the autoloader is loaded.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

if ( is_readable( __DIR__ . '/../vendor/autoload.php' ) ) {
	require_once __DIR__ . '/../vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'JoeriVanhamel\\SchemaEditor\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$file = __DIR__ . '/../src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}

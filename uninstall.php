<?php
/**
 * Runs when the plugin is deleted from the Plugins screen. Templates are content the site owner wrote, so they are
 * kept unless the "delete templates on uninstall" setting was ticked; the settings option itself is always removed.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$schema_editor_settings = get_option( 'schema_editor_settings', [] );
if ( is_array( $schema_editor_settings ) && ! empty( $schema_editor_settings['delete_on_uninstall'] ) ) {
	$schema_editor_templates = get_posts(
		[
			'post_type'   => 'schema_template',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		]
	);
	foreach ( $schema_editor_templates as $schema_editor_id ) {
		wp_delete_post( (int) $schema_editor_id, true );
	}
}
delete_option( 'schema_editor_settings' );

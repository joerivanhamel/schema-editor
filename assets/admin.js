/* global schemaEditor, wp, jQuery */
( function ( $ ) {
	'use strict';

	var editor = null;

	function currentDocument() {
		if ( editor && editor.codemirror ) {
			return editor.codemirror.getValue();
		}
		return $( '#' + schemaEditor.fieldId ).val() || '';
	}

	$( function () {
		var textarea = document.getElementById( schemaEditor.fieldId );
		if ( textarea && window.wp && wp.codeEditor && schemaEditor.codeEditor ) {
			try {
				editor = wp.codeEditor.initialize( textarea, schemaEditor.codeEditor );
			} catch ( e ) {
				editor = null; // the plain textarea still works
			}
		}

		$( '#schema_editor_preview_run' ).on( 'click', function () {
			var output = $( '#schema_editor_preview_output' );
			output.text( 'Rendering…' );
			$.post( schemaEditor.ajaxUrl, {
				action: 'schema_editor_preview',
				nonce: schemaEditor.nonce,
				post_id: schemaEditor.postId,
				target: $( '#schema_editor_preview_target' ).val(),
				document: currentDocument()
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					output.text( 'Error: ' + ( response && response.data && response.data.message ? response.data.message : 'unknown' ) );
					return;
				}
				var d = response.data;
				var lines = [];
				lines.push( 'Request: ' + d.request.post_type + ' #' + d.request.post_id + ' (' + d.request.page_template + ', /' + d.request.slug + '/)' );
				lines.push( 'Templates that apply: ' + ( d.matched.length ? d.matched.map( function ( m ) { return m.name + ' [' + m.priority + ', ' + m.operations.length + ' op(s)]'; } ).join( '; ' ) : 'none' ) );
				( d.warnings || [] ).forEach( function ( w ) { lines.push( 'Note: ' + w ); } );
				lines.push( '' );
				lines.push( JSON.stringify( d.graph, null, 2 ) );
				if ( d.fields && d.fields.length ) {
					lines.push( '' );
					lines.push( 'ACF fields on this post (acf.NAME), with a glimpse of the value:' );
					d.fields.forEach( function ( f ) { lines.push( '  ' + f.name + ( f.value ? ' = ' + f.value : '  (empty)' ) ); } );
				}
				output.text( lines.join( '\n' ) );
			} ).fail( function ( xhr ) {
				output.text( 'Request failed (' + xhr.status + ').' );
			} );
		} );
	} );
}( jQuery ) );

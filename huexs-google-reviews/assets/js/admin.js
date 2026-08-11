/**
 * Huexs Google Reviews — administración: confirmaciones de acciones destructivas.
 */
( function () {
	'use strict';

	document.querySelectorAll( '[data-hgr-confirm]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var message = form.getAttribute( 'data-hgr-confirm' ) || '¿Seguro?';
			if ( ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	} );
} )();

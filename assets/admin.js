/* Copy a request id from the Health screen to the clipboard. */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( ! ( target instanceof Element ) ) {
			return;
		}

		var button = target.closest( '.bytephase-copy' );

		if ( ! button || ! navigator.clipboard ) {
			return;
		}

		navigator.clipboard.writeText( button.getAttribute( 'data-copy' ) || '' );
	} );
}() );

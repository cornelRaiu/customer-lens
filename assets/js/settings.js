/**
 * Customer Lens settings: the Copy buttons next to the privacy policy texts.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-mwtcl-copy]' );
		if ( ! button ) {
			return;
		}

		const field = document.getElementById( button.dataset.mwtclCopy );
		const label = button.textContent;
		const done = () => {
			button.textContent = button.dataset.copied;
			window.setTimeout( () => ( button.textContent = label ), 2000 );
		};

		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( field.value ).then( done );
		} else {
			field.select();
			document.execCommand( 'copy' );
			done();
		}
	} );
} )();

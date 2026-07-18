'use strict';

const ui = require( './ui.js' );

function initialize() {
	document.querySelectorAll( '.ext-pagelike' ).forEach( ( root ) => {
		ui.mount( root );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initialize, { once: true } );
} else {
	initialize();
}

module.exports = ui;


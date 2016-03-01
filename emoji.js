( function( window, document, settings ) {

	/**
	 * Flag to show if the emoji JSON blob is being loaded
	 *
	 * @type bool
	 */
	var loading = false;

	/**
	 * Flag to show if the emoji JSON blob is loaded
	 *
	 * @type bool
	 */
	var loaded = false;

	/**
	 * The list of all emoji.
	 *
	 * @type array
	 */
	var emoji = [];

	/**
	 * Pointer to the popup element
	 * @type HtmlElement
	 */
	var popup = null;

	/**
	 * Click handler for when a reaction button is clicked
	 *
	 * @param  Event event The click event
	 */
 	var reactionClick = function( event ) {
		var el;

		event = event || window.event;

		el = event.target || event.srcElement;

		// Bail early, if we can.
		if ( 'DIV' !== el.nodeName ) {
			return;
		}

		if ( ! node.className || typeof node.className !== 'string' ) {
			return;
		}

		if ( node.className.indexOf( 'emoji-reaction-add' ) !== -1 ) {
			event.preventDefault();
			event.stopPropagation();
			showReactionPopup( el );
		} else if ( node.className.indexOf( 'emoji-reaction' ) !== -1 ) {
			event.preventDefault();
			event.stopPropagation();
			react( el );
		}
	}

	var populateReactionPopup = function() {
		var ii, jj, tab, html;
		if ( ! loaded ) {
			return;
		}

		if ( ! popup ) {
			popup = document.getElementById( 'emoji-reaction-selector' );
		}

		for( ii = 0; ii < 7; ii++ ) {
			if ( ! emoji[ ii ] ) {
				continue;
			}

			tab = popup.getElementsByClassName( 'container-' + ii );
			if ( 1 !== tab.length ) {
				continue;
			}
			tab = tab[0];

			html = '';
			for( jj = 0; jj < emoji[ ii ].length; jj++ ) {
				if ( ! emoji[ ii ][ jj ] ) {
					continue;
				}

				html += '&#x' + emoji[ ii ][ jj ] + ';';
			}
			html = html.replace( /-/g, ';&#x' );

			tab.innerHTML = html;
		}
	}

	/**
	 * Displays the emoji selector
	 *
	 * @param  HtmlElement el The button that was clicked
	 */
	var showReactionPopup = function( el ) {

	};

	/**
	 * Send a reaction message back to the server
	 *
	 * @param  HtmlElement el The button that was clicked
	 */
	var react = function( el ) {

	};

	/**
	 * Load the emoji definition JSON blob
	 */
	var loadEmoji = function() {
		if ( loading ) {
			return;
		}
		loading = true;

		var xhr = new XMLHttpRequest();
		xhr.onreadystatechange = function() {
			if ( xhr.readyState === XMLHttpRequest.DONE ) {
				if ( 200 === xhr.status ) {
					loaded = true;
					emoji = JSON.parse( xhr.responseText );

					populateReactionPopup();
				}
			}
		}

		xhr.open( 'GET', settings.emoji_url, true );
		xhr.send();
	}

	if ( 'complete' === document.readyState ) {
		loadEmoji();
	} else {
		if ( document.addEventListener ) {
			document.addEventListener( 'DOMContentLoaded', loadEmoji, false );
			window.addEventListener( 'load', loadEmoji, false );
		} else {
			window.attachEvent( 'onload', loadEmoji );
			document.attachEvent( 'onreadystatechange', function() {
				if ( 'complete' === document.readyState ) {
					loadEmoji();
				}
			} );
		}
	}

	if ( document.addEventListener ) {
		document.addEventListener( "click", reactionClick );
	} else {
		document.attachEvent( "click", reactionClick );
	}

} )( window, document, window.wp.react.settings );

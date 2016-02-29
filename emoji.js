( function() {

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

	if ( document.addEventListener ) {
		document.addEventListener( "click", reactionClick );
	} else {
		document.attachEvent( "click", reactionClick );
	}

} )();

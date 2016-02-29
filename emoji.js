( function() {

 	var reactionClick = function( event ) {
		var el;

		event = event || window.event;

		el = event.target || event.srcElement;

		// Bail early, if we can.
		if ( 'DIV' !== el.nodeName ) {
			return true;
		}

		if ( ! node.className || typeof node.className !== 'string' ) {
			return true;
		}

		if ( node.className.indexOf( 'emoji-reaction-add' ) !== -1 ) {
			event.preventDefault();
			showReactionPopup( el );
			return false;
		}

		if ( node.className.indexOf( 'emoji-reaction' ) !== -1 ) {
			event.preventDefault();
			react( el );
			return false;
		}

		return true;
	}

	var showReactionPopup = function( el ) {

	};

	var react = function( el ) {

	};

	if ( document.addEventListener ) {
		document.addEventListener( "click", reactionClick );
	} else {
		document.attachEvent( "click", reactionClick );
	}

} )();

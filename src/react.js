/* global XMLHttpRequest */
import 'emoji-picker-element';

const settings = window.wp.react.settings;

/**
 * Pointer to the lazily-created <emoji-picker> element.
 *
 * @type {HTMLElement|null}
 */
let picker = null;

/**
 * Click handler for when a reaction button is clicked
 *
 * @param {Event} event The click event
 */
const reactionClick = function ( event ) {
	// The block editor's live preview renders post content inside an
	// iframe named "editor-canvas"; reactions shouldn't be clickable
	// there. Checking window.self !== window.top instead would also
	// catch any other legitimate embedding of the front end in an
	// iframe (e.g. WordPress Playground), which would silently break
	// reactions everywhere but the top-level page. Requiring the iframe
	// check too guards against window.name === 'editor-canvas' by
	// coincidence on a top-level page -- window.name is writable and
	// can persist across navigations in the same tab.
	const isEditor =
		( window.self !== window.top && 'editor-canvas' === window.name ) ||
		( window.wp && window.wp.blockEditor );
	if ( isEditor ) {
		return;
	}

	// Only the primary (usually left) mouse button should add a reaction.
	if ( 'button' in event && 0 !== event.button ) {
		return;
	}

	let parent = event.target;
	while ( parent ) {
		if (
			'DIV' === parent.nodeName &&
			parent.className &&
			typeof parent.className === 'string' &&
			parent.className.indexOf( 'emoji-reaction' ) !== -1
		) {
			break;
		}
		parent = parent.parentElement;
	}

	if ( ! parent ) {
		// A click inside the picker's own shadow DOM (e.g. a category tab
		// or the search box) still bubbles up here, but the DOM retargets
		// its event.target to the <emoji-picker> host element itself for
		// listeners outside the shadow tree -- so it looks identical to a
		// click landing directly on the picker. Only treat this as an
		// outside click, and dismiss the picker, when it's neither of
		// those.
		if ( ! picker || event.target !== picker ) {
			hideReactionPicker();
		}
		return;
	}

	if ( parent.className.indexOf( 'emoji-reaction-add' ) !== -1 ) {
		event.preventDefault();
		event.stopPropagation();
		if ( ! picker || 'none' === picker.style.display ) {
			showReactionPicker( parent );
		} else {
			hideReactionPicker();
		}
	} else if ( parent.className.indexOf( 'emoji-reaction' ) !== -1 ) {
		event.preventDefault();
		event.stopPropagation();
		react( parent.dataset.post, parent.dataset.emoji );
		hideReactionPicker();
	}
};

/**
 * Lazily create the <emoji-picker> element, so it isn't reachable in the
 * DOM (e.g. by a screen reader) until it's actually needed.
 *
 * @return {HTMLElement} The picker element.
 */
const getOrCreatePicker = function () {
	if ( picker ) {
		return picker;
	}

	picker = document.createElement( 'emoji-picker' );
	picker.dataSource = settings.emoji_data_url;
	picker.style.position = 'absolute';
	picker.style.display = 'none';

	picker.addEventListener( 'emoji-click', function ( event ) {
		// event.detail.unicode is only present when the library could
		// resolve a skin-tone-adjusted variant for the clicked emoji (see
		// getDetailForClickEvent()/unicodeWithSkin() in its source) --
		// notably, entries served from its "favorites"/recently-used bar
		// can omit it, silently leaving react() to send the literal
		// string "undefined" as the emoji, which the REST endpoint
		// rejects with no visible feedback (it just looks like the click
		// did nothing). event.detail.emoji.unicode is the same field on
		// the full emoji record the library always resolves the clicked
		// item to, and is reliably present.
		const unicode =
			event.detail.unicode ||
			( event.detail.emoji && event.detail.emoji.unicode );

		if ( unicode ) {
			react( picker.dataset.post, unicode );
		}

		hideReactionPicker();
	} );

	document.body.appendChild( picker );

	return picker;
};

/**
 * Display the emoji picker.
 *
 * @param {HTMLElement} el The button that was clicked
 */
const showReactionPicker = function ( el ) {
	const thePicker = getOrCreatePicker();

	thePicker.dataset.post = el.dataset.post;

	let left = 0;
	let top = 0;
	let parent = el;

	while ( parent ) {
		left += parent.offsetLeft;
		top += parent.offsetTop;
		parent = parent.offsetParent;
	}

	top -= 300;

	thePicker.style.left = left + 'px';
	thePicker.style.top = top + 'px';
	thePicker.style.display = 'block';
};

/**
 * Hide the reaction picker.
 */
const hideReactionPicker = function () {
	if ( picker && 'none' !== picker.style.display ) {
		picker.style.display = 'none';
	}
};

/**
 * Find the reaction bubble or add-button carrying a given post ID, and
 * return its parent -- the .emoji-reactions container for that post.
 *
 * @param {string} post The post ID.
 * @return {HTMLElement|null} The reactions container, or null if none was found.
 */
const findReactionsContainer = function ( post ) {
	let candidates = document.getElementsByClassName( 'emoji-reaction-add' );
	let ii;

	for ( ii = 0; ii < candidates.length; ii++ ) {
		if ( candidates[ ii ].dataset.post === post ) {
			return candidates[ ii ].parentElement;
		}
	}

	candidates = document.getElementsByClassName( 'emoji-reaction' );
	for ( ii = 0; ii < candidates.length; ii++ ) {
		if ( candidates[ ii ].dataset.post === post ) {
			return candidates[ ii ].parentElement;
		}
	}

	return null;
};

/**
 * Update the visible reaction bubbles for a post from a fresh summary,
 * so a reaction appears immediately rather than only on the next page
 * load.
 *
 * @param {string} post    The post ID the reaction was added to.
 * @param {Array}  summary Reaction summary groups, as returned by the reactions REST endpoint.
 */
const updateReactionDisplay = function ( post, summary ) {
	const container = findReactionsContainer( post );
	if ( ! container ) {
		return;
	}

	const addButton =
		container.getElementsByClassName( 'emoji-reaction-add' )[ 0 ] || null;

	for ( let ii = 0; ii < summary.length; ii++ ) {
		const group = summary[ ii ];
		const bubbles = container.getElementsByClassName( 'emoji-reaction' );
		let bubble = null;

		for ( let jj = 0; jj < bubbles.length; jj++ ) {
			if ( bubbles[ jj ].dataset.emoji === group.emoji ) {
				bubble = bubbles[ jj ];
				break;
			}
		}

		if ( ! bubble ) {
			bubble = document.createElement( 'div' );
			bubble.className = 'emoji-reaction';
			bubble.dataset.emoji = group.emoji;
			bubble.dataset.post = post;

			const emojiEl = document.createElement( 'div' );
			emojiEl.className = 'emoji';
			emojiEl.textContent = group.emoji;
			bubble.appendChild( emojiEl );

			const countEl = document.createElement( 'div' );
			countEl.className = 'count';
			bubble.appendChild( countEl );

			container.insertBefore( bubble, addButton );
		}

		bubble.dataset.count = group.count;
		bubble.getElementsByClassName( 'count' )[ 0 ].textContent = group.count;
	}
};

/**
 * The localStorage key used to persist this browser's anonymous
 * reaction identifier.
 *
 * @type {string}
 */
const CLIENT_ID_STORAGE_KEY = 'wp-react-client-id';

/**
 * Generate a v4-ish GUID using Math.random().
 *
 * Not cryptographically strong, but that's not the point -- this is just
 * a per-browser identifier, not a security token.
 *
 * @return {string} A GUID-shaped random string.
 */
const generateGuid = function () {
	let guid = '';
	let ii;
	let segment;
	for ( ii = 0; ii < 32; ii++ ) {
		segment = Math.floor( Math.random() * 16 );
		if ( 8 === ii || 12 === ii || 16 === ii || 20 === ii ) {
			guid += '-';
		}
		guid += segment.toString( 16 );
	}
	return guid;
};

/**
 * Get this browser's anonymous reaction identifier from localStorage,
 * generating and persisting one on first use.
 *
 * Deliberately kept in localStorage rather than a cookie so it's never
 * sent to the server except on an actual reaction request.
 *
 * @return {string|null} The client id, or null if localStorage isn't
 *                        available (e.g. disabled or private browsing).
 */
const getClientId = function () {
	try {
		let id = window.localStorage.getItem( CLIENT_ID_STORAGE_KEY );
		if ( ! id ) {
			id = generateGuid();
			window.localStorage.setItem( CLIENT_ID_STORAGE_KEY, id );
		}
		return id;
		// localStorage being disabled/unavailable (e.g. private browsing) is
		// routine, not worth logging.
		// eslint-disable-next-line no-unused-vars
	} catch ( error ) {
		return null;
	}
};

/**
 * Send a reaction message back to the server
 *
 * @param {string} post  The post ID to react to.
 * @param {string} emoji The reaction emoji.
 */
const react = function ( post, emoji ) {
	let params =
		'post=' +
		encodeURIComponent( post ) +
		'&emoji=' +
		encodeURIComponent( emoji );

	const clientId = getClientId();
	if ( clientId ) {
		params += '&client_id=' + encodeURIComponent( clientId );
	}

	const xhr = new XMLHttpRequest();

	xhr.onreadystatechange = function () {
		if ( xhr.readyState !== XMLHttpRequest.DONE ) {
			return;
		}

		if ( 200 !== xhr.status ) {
			// Surface this rather than failing silently -- from the
			// user's perspective, an uncaught non-200 here looks
			// identical to the reaction click simply not having worked.
			if ( window.console && window.console.warn ) {
				window.console.warn(
					'Reacting failed with status ' + xhr.status + ':',
					xhr.responseText
				);
			}
			return;
		}

		try {
			updateReactionDisplay( post, JSON.parse( xhr.responseText ) );
		} catch ( error ) {
			// The reaction was still recorded server-side even if we
			// can't reflect it immediately; it'll show up on reload.
			if ( window.console && window.console.warn ) {
				window.console.warn( error );
			}
		}
	};

	xhr.open( 'POST', settings.endpoint, true );

	xhr.setRequestHeader( 'Content-type', 'application/x-www-form-urlencoded' );

	if ( settings.nonce ) {
		xhr.setRequestHeader( 'X-WP-Nonce', settings.nonce );
	}

	xhr.send( params );
};

document.addEventListener( 'click', reactionClick );

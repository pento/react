/* global XMLHttpRequest */
( function ( window, document, settings ) {
	/**
	 * Flag to show if the emoji JSON blob is being loaded
	 *
	 * @type {boolean}
	 */
	let loading = false;

	/**
	 * Whether we're stuck with the legacy attachEvent() event model (IE8 and
	 * below), where event.button is a bitmask (primary = 1) rather than the
	 * modern enumeration (primary = 0).
	 *
	 * @type {boolean}
	 */
	const usesLegacyEventModel = ! document.addEventListener;

	/**
	 * Flag to show if the emoji JSON blob is loaded
	 *
	 * @type {boolean}
	 */
	let loaded = false;

	/**
	 * The list of all emoji.
	 *
	 * @type {Array}
	 */
	let emoji = [];

	/**
	 * Pointer to the popup element
	 *
	 * @type {HTMLElement}
	 */
	let popup = null;

	/**
	 * Flag to show if the popup has been populated already.
	 *
	 * @type {boolean}
	 */
	let popupPopulated = false;

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

		event = event || window.event;

		// Only the primary (usually left) mouse button should add a reaction.
		if (
			'button' in event &&
			( usesLegacyEventModel ? 1 : 0 ) !== event.button
		) {
			return;
		}

		const el = event.target || event.srcElement;

		let parent = el;
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
			hideReactionPopup();
			return;
		}

		if ( parent.className.indexOf( 'emoji-reaction-add' ) !== -1 ) {
			event.preventDefault();
			event.stopPropagation();
			if ( ! popup || 'none' === popup.style.display ) {
				showReactionPopup( parent );
			} else {
				hideReactionPopup();
			}
		} else if ( parent.className.indexOf( 'emoji-reaction-tab' ) !== -1 ) {
			event.preventDefault();
			event.stopPropagation();
			changeReactionTab( parseInt( parent.dataset.tab ) );
		} else if ( parent.className.indexOf( 'emoji-reaction' ) !== -1 ) {
			event.preventDefault();
			event.stopPropagation();
			react( parent );
			hideReactionPopup();
		}
	};

	/**
	 * Materialize the selector template into the DOM.
	 *
	 * The selector markup is printed inside a `text/html` script template
	 * rather than as live markup, so that it isn't reachable in the DOM
	 * (e.g. by a screen reader) until it's actually needed.
	 *
	 * This deliberately never looks up an existing #emoji-reaction-selector
	 * via getElementById() first: that ID isn't reserved, and post content
	 * saved by a user without unfiltered_html can still contain a div with
	 * an arbitrary id (wp_kses_post() allows the id attribute), so a
	 * getElementById() lookup here could pick up attacker-controlled markup
	 * and treat it as the trusted popup. populateReactionPopup() already
	 * caches the element this function returns and never calls it again
	 * once that succeeds, so tracking our own reference is both sufficient
	 * and safe.
	 *
	 * @return {HTMLElement|null} The selector popup element, or null if the template is missing.
	 */
	const getPopup = function () {
		const template = document.getElementById(
			'tmpl-emoji-reaction-selector'
		);
		if ( ! template ) {
			return null;
		}

		const wrapper = document.createElement( 'div' );
		wrapper.innerHTML = template.innerHTML;

		const element = wrapper.firstElementChild;
		if ( ! element ) {
			return null;
		}

		document.body.appendChild( element );

		return element;
	};

	/**
	 * Add the emoji list to the reaction popup.
	 */
	const populateReactionPopup = function () {
		let ii;
		let jj;
		let tab;
		let html;
		let character;
		if ( ! loaded ) {
			return;
		}

		if ( popupPopulated ) {
			return;
		}

		if ( ! popup ) {
			popup = getPopup();
		}

		if ( ! popup ) {
			return;
		}

		popupPopulated = true;

		for ( ii = 0; ii <= 7; ii++ ) {
			if ( ! emoji[ ii ] ) {
				continue;
			}

			tab = popup.getElementsByClassName( 'container-' + ii );
			if ( 1 !== tab.length ) {
				continue;
			}
			tab = tab[ 0 ];

			html = '';
			for ( jj = 0; jj < emoji[ ii ].length; jj++ ) {
				if ( ! emoji[ ii ][ jj ] ) {
					continue;
				}

				character = String.fromCodePoint.apply(
					this,
					emoji[ ii ][ jj ]
				);

				html +=
					'<div data-emoji="' +
					character +
					'" class="emoji-reaction"><div class="emoji">';
				html += character;
				html += '</div></div>';
			}

			tab.innerHTML = html;
		}
	};

	/**
	 * Displays the emoji selector
	 *
	 * @param {HTMLElement} el The button that was clicked
	 */
	const showReactionPopup = function ( el ) {
		let left = 0;
		let top = 0;
		let parent;

		populateReactionPopup();

		popup.dataset.post = el.dataset.post;

		if ( document.documentElement.clientWidth > 768 ) {
			parent = el;

			while ( parent ) {
				left += parent.offsetLeft;
				top += parent.offsetTop;
				parent = parent.offsetParent;
			}

			top -= 300;

			popup.style.left = left + 'px';
			popup.style.top = top + 'px';
		}

		changeReactionTab( 0 );

		popup.style.display = 'block';
	};

	/**
	 * Hide the reaction popup.
	 */
	const hideReactionPopup = function () {
		if ( popup && 'none' !== popup.style.display ) {
			popup.style.display = 'none';
		}
	};

	/**
	 * Switch to a different tab in the reactions popup.
	 *
	 * @param {number} tabNumber The tab number to switch to.
	 */
	const changeReactionTab = function ( tabNumber ) {
		let ii;
		for ( ii = 0; ii <= 7; ii++ ) {
			let tab = popup.getElementsByClassName( 'container-' + ii );
			if ( 1 !== tab.length ) {
				continue;
			}
			tab = tab[ 0 ];

			if ( ii === tabNumber ) {
				tab.style.display = 'block';
			} else {
				tab.style.display = 'none';
			}
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
		let candidates =
			document.getElementsByClassName( 'emoji-reaction-add' );
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
			container.getElementsByClassName( 'emoji-reaction-add' )[ 0 ] ||
			null;

		for ( let ii = 0; ii < summary.length; ii++ ) {
			const group = summary[ ii ];
			const bubbles =
				container.getElementsByClassName( 'emoji-reaction' );
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
			bubble.getElementsByClassName( 'count' )[ 0 ].textContent =
				group.count;
		}
	};

	/**
	 * Send a reaction message back to the server
	 *
	 * @param {HTMLElement} el The button that was clicked
	 */
	const react = function ( el ) {
		let post;

		if ( el.dataset.post ) {
			post = el.dataset.post;
		} else {
			post = el.parentElement.parentElement.dataset.post;
		}

		const params =
			'post=' +
			encodeURIComponent( post ) +
			'&emoji=' +
			encodeURIComponent( el.dataset.emoji );

		const xhr = new XMLHttpRequest();

		xhr.onreadystatechange = function () {
			if ( xhr.readyState !== XMLHttpRequest.DONE ) {
				return;
			}

			if ( 200 !== xhr.status ) {
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

		xhr.setRequestHeader(
			'Content-type',
			'application/x-www-form-urlencoded'
		);

		if ( settings.nonce ) {
			xhr.setRequestHeader( 'X-WP-Nonce', settings.nonce );
		}

		xhr.send( params );
	};

	/**
	 * Load the emoji definition JSON blob
	 */
	const loadEmoji = function () {
		if ( loading ) {
			return;
		}
		loading = true;

		const xhr = new XMLHttpRequest();
		xhr.onreadystatechange = function () {
			if ( xhr.readyState === XMLHttpRequest.DONE ) {
				if ( 200 === xhr.status ) {
					loaded = true;
					emoji = JSON.parse( xhr.responseText );
				}
			}
		};

		xhr.open( 'GET', settings.emoji_url, true );
		xhr.send();
	};

	if ( 'complete' === document.readyState ) {
		loadEmoji();
	} else if ( document.addEventListener ) {
		document.addEventListener( 'DOMContentLoaded', loadEmoji, false );
		window.addEventListener( 'load', loadEmoji, false );
	} else {
		window.attachEvent( 'onload', loadEmoji );
		document.attachEvent( 'onreadystatechange', function () {
			if ( 'complete' === document.readyState ) {
				loadEmoji();
			}
		} );
	}

	if ( document.addEventListener ) {
		document.addEventListener( 'click', reactionClick );
	} else {
		document.attachEvent( 'click', reactionClick );
	}
} )( window, document, window.wp.react.settings );

/* eslint-disable */
/*! http://mths.be/fromcodepoint v0.1.0 by @mathias */
if ( ! String.fromCodePoint ) {
	( function () {
		const defineProperty = ( function () {
			// IE 8 only supports `Object.defineProperty` on DOM elements
			try {
				const object = {};
				const $defineProperty = Object.defineProperty;
				var result =
					$defineProperty( object, object, object ) &&
					$defineProperty;
			} catch ( error ) {}
			return result;
		} )();
		const stringFromCharCode = String.fromCharCode;
		const floor = Math.floor;
		const fromCodePoint = function () {
			const MAX_SIZE = 0x4000;
			const codeUnits = [];
			let highSurrogate;
			let lowSurrogate;
			let index = -1;
			const length = arguments.length;
			if ( ! length ) {
				return '';
			}
			let result = '';
			while ( ++index < length ) {
				let codePoint = Number( arguments[ index ] );
				if (
					! isFinite( codePoint ) || // `NaN`, `+Infinity`, or `-Infinity`
					codePoint < 0 || // not a valid Unicode code point
					codePoint > 0x10ffff || // not a valid Unicode code point
					floor( codePoint ) != codePoint // not an integer
				) {
					throw RangeError( 'Invalid code point: ' + codePoint );
				}
				if ( codePoint <= 0xffff ) {
					// BMP code point
					codeUnits.push( codePoint );
				} else {
					// Astral code point; split in surrogate halves
					// http://mathiasbynens.be/notes/javascript-encoding#surrogate-formulae
					codePoint -= 0x10000;
					highSurrogate = ( codePoint >> 10 ) + 0xd800;
					lowSurrogate = ( codePoint % 0x400 ) + 0xdc00;
					codeUnits.push( highSurrogate, lowSurrogate );
				}
				if ( index + 1 == length || codeUnits.length > MAX_SIZE ) {
					result += stringFromCharCode.apply( null, codeUnits );
					codeUnits.length = 0;
				}
			}
			return result;
		};
		if ( defineProperty ) {
			defineProperty( String, 'fromCodePoint', {
				value: fromCodePoint,
				configurable: true,
				writable: true,
			} );
		} else {
			String.fromCodePoint = fromCodePoint;
		}
	} )();
}

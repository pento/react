/* global XMLHttpRequest */
( function ( window, document, settings ) {
	/**
	 * Flag to show if the emoji JSON blob is being loaded
	 *
	 * @type {boolean}
	 */
	let loading = false;

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
		const isEditor =
			window.self !== window.top ||
			( window.wp && window.wp.blockEditor );
		if ( isEditor ) {
			return;
		}

		event = event || window.event;

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
	 * @return {HTMLElement|null} The selector popup element, or null if the template is missing.
	 */
	const getPopup = function () {
		const existing = document.getElementById( 'emoji-reaction-selector' );
		if ( existing ) {
			return existing;
		}

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

		const params = 'post=' + post + '&emoji=' + el.dataset.emoji;

		const xhr = new XMLHttpRequest();

		xhr.open( 'POST', settings.endpoint, true );

		xhr.setRequestHeader(
			'Content-type',
			'application/x-www-form-urlencoded'
		);

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

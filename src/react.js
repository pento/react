/* global XMLHttpRequest */
import 'emoji-picker-element';

const settings = window.wp.react.settings;

/**
 * Prefix marking a reaction as a custom icon rather than an emoji.
 *
 * @type {string}
 */
const ICON_PREFIX = 'icon:';

/**
 * Read a boolean reaction setting.
 *
 * Read at call time rather than destructured at module scope, so that a
 * settings payload which predates a given key still works, and so the test
 * suite can change window.wp.react.settings between cases.
 *
 * @param {string}  key      The setting name.
 * @param {boolean} fallback Value to use when the setting is absent.
 * @return {boolean} The setting value.
 */
const settingEnabled = function ( key, fallback ) {
	return undefined === settings[ key ] ? fallback : !! settings[ key ];
};

/**
 * Whether visitors may pick any emoji, rather than just the configured set.
 *
 * @return {boolean} Whether the picker is enabled.
 */
const pickerEnabled = function () {
	return settingEnabled( 'enable_picker', true );
};

/**
 * Whether skin tone variations may be used.
 *
 * @return {boolean} Whether skin tones are allowed.
 */
const skinTonesAllowed = function () {
	return settingEnabled( 'allow_skin_tones', true );
};

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
		if ( parent.classList ) {
			// On a login-gated site the bubbles are real links to
			// wp-login.php. Let the browser navigate.
			if ( parent.classList.contains( 'emoji-reaction-login' ) ) {
				return;
			}

			// Stop at the container rather than walking past it. Its class,
			// 'emoji-reactions', contains 'emoji-reaction' as a substring, so
			// matching on substrings treated a click on the container's own
			// padding as a click on a bubble and posted
			// post=undefined&emoji=undefined.
			if ( parent.classList.contains( 'emoji-reactions' ) ) {
				parent = null;
				break;
			}

			if (
				parent.classList.contains( 'emoji-reaction-add' ) ||
				parent.classList.contains( 'emoji-reaction' )
			) {
				break;
			}
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

	if ( parent.classList.contains( 'emoji-reaction-add' ) ) {
		event.preventDefault();
		event.stopPropagation();

		// The button is rendered server-side and the page may be cached, so
		// it can outlive the setting that produced it.
		if ( ! pickerEnabled() ) {
			return;
		}

		if ( ! picker || 'none' === picker.style.display ) {
			showReactionPicker( parent );
		} else {
			hideReactionPicker();
		}
	} else {
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
		//
		// It doubles as the exact base emoji when skin tones are turned
		// off: event.detail.unicode is the tone-adjusted variant, while
		// event.detail.emoji.unicode is the dataset's untoned entry. Taking
		// the base from the library's own record beats stripping modifier
		// codepoints ourselves, which can't be done reliably -- a base
		// emoji often carries a trailing variation selector its toned
		// variants don't, and multi-person emoji change codepoint sequence
		// altogether when toned.
		const base = event.detail.emoji && event.detail.emoji.unicode;
		const unicode = skinTonesAllowed()
			? event.detail.unicode || base
			: base || event.detail.unicode;

		if ( unicode ) {
			react( picker.dataset.post, unicode );
		}

		hideReactionPicker();
	} );

	// The library's unicodeWithSkin() returns the base emoji whenever the
	// current skin tone is falsy, so this -- not the cosmetic hiding done by
	// hideSkinTonePicker() -- is what actually keeps toned reactions from
	// being submitted. Guarded because the element only exposes .database
	// once the custom element has been defined.
	if ( ! skinTonesAllowed() && picker.database ) {
		const stored = picker.database.setPreferredSkinTone( 0 );

		if ( stored && 'function' === typeof stored.catch ) {
			stored.catch( function () {} );
		}
	}

	document.body.appendChild( picker );

	return picker;
};

/**
 * Hide the picker's skin tone control.
 *
 * There is no supported way to do this: emoji-picker-element exposes no
 * ::part() and no attribute or property for it, so this reaches into its
 * shadow root and matches internal selectors. Both the class names and the
 * ids are targeted, since either could be renamed independently upstream.
 *
 * If upstream renames all four this silently stops hiding anything, which is
 * why it is only cosmetic: setPreferredSkinTone( 0 ) in getOrCreatePicker(),
 * and the server-side check behind the REST endpoint, are what enforce the
 * setting.
 *
 * @param {HTMLElement} thePicker The picker element.
 */
const hideSkinTonePicker = function ( thePicker ) {
	if ( ! thePicker.shadowRoot ) {
		return;
	}

	if ( thePicker.shadowRoot.getElementById( 'react-no-skintones' ) ) {
		return;
	}

	const style = document.createElement( 'style' );
	style.id = 'react-no-skintones';
	style.textContent =
		'.skintone-button-wrapper,.skintone-list,' +
		'#skintone-button,#skintone-list{display:none!important}';

	thePicker.shadowRoot.appendChild( style );
};

/**
 * Display the emoji picker.
 *
 * @param {HTMLElement} el The button that was clicked
 */
const showReactionPicker = function ( el ) {
	const thePicker = getOrCreatePicker();

	thePicker.dataset.post = el.dataset.post;

	// Applied per open rather than once at creation: the picker is a
	// create-once singleton, so doing it here is what lets the setting take
	// effect on a picker that already exists.
	if ( ! skinTonesAllowed() ) {
		hideSkinTonePicker( thePicker );
	}

	// Below 768px, static/react.css switches the picker to a fixed
	// bottom sheet (position: fixed; left: 0; bottom: 0). Leave that
	// alone rather than fighting it with inline positioning -- an
	// inline style always wins over a stylesheet rule regardless of the
	// media query, so setting left/top here would both override the
	// bottom-sheet placement and risk an off-screen negative top.
	if ( document.documentElement.clientWidth > 768 ) {
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
	} else {
		thePicker.style.left = '';
		thePicker.style.top = '';
	}

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
 * Whether a reaction bubble should stay on the page at a count of zero.
 *
 * The always-visible list is resolved server-side and covers both the
 * configured default emoji and any custom icons, so both keep their bubble
 * when their last reaction is removed. Anything else is taken away again.
 *
 * @param {string} emoji The reaction value.
 * @return {boolean} Whether to keep the bubble at zero.
 */
const isAlwaysVisible = function ( emoji ) {
	const always = settings.always_visible || [];

	return always.indexOf( emoji ) !== -1;
};

/**
 * Turn sanitized icon SVG markup into a live DOM node.
 *
 * Parsed as 'text/html', not 'image/svg+xml', for two reasons that both come
 * out of core's icon sanitizer: it lowercases attribute names, so `viewBox`
 * arrives as `viewbox`, and it does not guarantee an `xmlns`. The XML parser
 * is case-sensitive and would leave the icon with no usable viewBox, and with
 * no xmlns it yields a null namespaceURI that renders nothing at all. The HTML
 * parser's foreign-attribute adjustment restores the real `viewBox` and puts
 * the subtree in the SVG namespace either way.
 *
 * The markup has already been through core's svg/path/polygon allowlist on the
 * server and DOMParser does not execute script, so this is not an injection
 * surface -- but it is deliberately never assigned to innerHTML.
 *
 * @param {string} markup Sanitized SVG markup.
 * @return {Node|null} The imported <svg> node, or null if it can't be parsed.
 */
const parseIconSvg = function ( markup ) {
	if ( ! markup || 'function' !== typeof window.DOMParser ) {
		return null;
	}

	let svg = null;

	try {
		const doc = new window.DOMParser().parseFromString(
			'<body>' + markup,
			'text/html'
		);

		svg = doc.body && doc.body.querySelector( 'svg' );
	} catch {
		return null;
	}

	if ( ! svg || 'http://www.w3.org/2000/svg' !== svg.namespaceURI ) {
		return null;
	}

	return document.importNode( svg, true );
};

/**
 * Build a reaction bubble.
 *
 * @param {string} post  The post ID.
 * @param {string} emoji The reaction value.
 * @return {HTMLElement|null} The bubble, or null if it can't be rendered.
 */
const createReactionBubble = function ( post, emoji ) {
	const icon = ( settings.icons || {} )[ emoji ];

	const emojiEl = document.createElement( 'div' );
	emojiEl.className = 'emoji';

	let className = 'emoji-reaction';

	if ( icon ) {
		const svg = parseIconSvg( icon.svg );

		if ( ! svg ) {
			return null;
		}

		className += ' emoji-reaction-icon';
		emojiEl.appendChild( svg );
	} else if ( 0 === emoji.indexOf( ICON_PREFIX ) ) {
		// An icon reaction whose icon is no longer registered. Showing
		// nothing beats showing the raw token.
		return null;
	} else {
		emojiEl.textContent = emoji;
	}

	const bubble = document.createElement( 'div' );
	bubble.className = className;
	bubble.dataset.emoji = emoji;
	bubble.dataset.post = post;
	bubble.appendChild( emojiEl );

	const countEl = document.createElement( 'div' );
	countEl.className = 'count';
	bubble.appendChild( countEl );

	return bubble;
};

/**
 * Apply a reaction count to a bubble.
 *
 * The .count element is always present, even at zero. Hiding it with a class
 * rather than leaving it out keeps every caller -- and the stylesheet -- from
 * having to cope with a missing node.
 *
 * @param {HTMLElement} bubble The bubble.
 * @param {number}      count  The reaction count.
 */
const applyReactionCount = function ( bubble, count ) {
	bubble.dataset.count = count;
	bubble.getElementsByClassName( 'count' )[ 0 ].textContent = count;

	if ( 0 === Number( count ) ) {
		bubble.classList.add( 'is-zero' );
	} else {
		bubble.classList.remove( 'is-zero' );
	}
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

	const counts = {};
	for ( let ii = 0; ii < summary.length; ii++ ) {
		counts[ summary[ ii ].emoji ] = summary[ ii ].count;
	}

	// Reconcile every bubble already on the page, including any the summary
	// no longer mentions -- a reaction that was just toggled back off drops
	// out of the summary entirely, and would otherwise keep a stale count.
	// The live HTMLCollection is snapshotted first, because removing from it
	// while iterating would skip entries.
	const existing = Array.prototype.slice.call(
		container.getElementsByClassName( 'emoji-reaction' )
	);

	for ( let ii = 0; ii < existing.length; ii++ ) {
		const bubble = existing[ ii ];
		const emoji = bubble.dataset.emoji;
		const count = counts[ emoji ] || 0;

		if ( 0 === count && ! isAlwaysVisible( emoji ) ) {
			bubble.parentElement.removeChild( bubble );
		} else {
			applyReactionCount( bubble, count );
		}

		delete counts[ emoji ];
	}

	// Whatever is left in the summary is new, and needs a bubble.
	for ( const emoji in counts ) {
		if ( ! Object.prototype.hasOwnProperty.call( counts, emoji ) ) {
			continue;
		}

		const bubble = createReactionBubble( post, emoji );

		if ( ! bubble ) {
			continue;
		}

		applyReactionCount( bubble, counts[ emoji ] );
		container.insertBefore( bubble, addButton );
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
 * @param {string} emoji The reaction: an emoji, or a custom icon reference.
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

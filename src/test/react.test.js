/**
 * @jest-environment jsdom
 */

/**
 * `src/react.js` reads `window.wp.react.settings` and wires up
 * `document`-level event listeners as a side effect of being loaded,
 * rather than exporting anything. So it's tested the way a browser would
 * exercise it -- by setting up the DOM/settings it expects, requiring it
 * once, and then dispatching real events.
 *
 * The real `emoji-picker-element` library is mocked out here rather than
 * imported for real: these tests cover this plugin's own glue code (how
 * it creates/positions the picker and reacts to its `emoji-click` event),
 * not the third-party library itself, which needs fetch/IndexedDB the
 * jsdom test environment doesn't provide. With the import mocked away,
 * `document.createElement( 'emoji-picker' )` just yields a plain,
 * unregistered element -- enough to dispatch a synthetic `emoji-click`
 * event against.
 */
jest.mock( 'emoji-picker-element', () => ( {} ) );

const SETTINGS = {
	endpoint: 'https://example.test/wp-json/wp/v2/react',
	nonce: 'test-nonce',
	emoji_data_url:
		'https://example.test/wp-content/plugins/react/static/emoji-data.json',
};

/**
 * The settings react.js starts each test with.
 *
 * @type {Object}
 */
const DEFAULT_SETTINGS = { ...SETTINGS };

/**
 * The baseline reaction markup, restored before each test.
 *
 * @type {string}
 */
const FIXTURE = `
	<div data-emoji="&#128512;" data-count="3" data-post="42" class="emoji-reaction">
		<div class="emoji">&#128512;</div>
		<div class="count">3</div>
	</div>
	<div data-post="42" class="emoji-reaction-add"><div class="emoji">&#128515;+</div></div>
`;

/**
 * Minimal XMLHttpRequest stand-in that records what react.js does with it,
 * and lets tests manually resolve a request via its onreadystatechange
 * handler.
 */
class MockXHR {
	constructor() {
		this.readyState = 0;
		this.status = 0;
		this.responseText = '';
		this.requestHeaders = {};
		this.onreadystatechange = null;
		MockXHR.instances.push( this );
	}

	open( method, url ) {
		this.method = method;
		this.url = url;
	}

	setRequestHeader( name, value ) {
		this.requestHeaders[ name ] = value;
	}

	send( body ) {
		this.body = body;
	}

	respond( status, responseText ) {
		this.status = status;
		this.responseText = responseText;
		this.readyState = MockXHR.DONE;
		if ( this.onreadystatechange ) {
			this.onreadystatechange();
		}
	}
}
MockXHR.DONE = 4;
MockXHR.instances = [];

function click( element ) {
	element.dispatchEvent(
		new window.MouseEvent( 'click', {
			bubbles: true,
			cancelable: true,
			button: 0,
		} )
	);
}

beforeAll( () => {
	window.wp = { react: { settings: SETTINGS } };
	global.XMLHttpRequest = MockXHR;

	document.body.innerHTML = `<div class="emoji-reactions">${ FIXTURE }</div>`;

	require( '../react.js' );
} );

beforeEach( () => {
	MockXHR.instances = [];

	// Restore the settings object in place. It has to be mutated rather
	// than replaced: react.js holds a reference to this exact object, so
	// reassigning window.wp.react.settings would leave the module reading
	// the old one. Deleting the extra keys also restores the "setting
	// absent" state, which is what an older settings payload looks like.
	Object.keys( SETTINGS ).forEach( ( key ) => {
		if ( ! ( key in DEFAULT_SETTINGS ) ) {
			delete SETTINGS[ key ];
		}
	} );
	Object.assign( SETTINGS, DEFAULT_SETTINGS );

	// Reset the bubbles, but only inside the container -- the picker is
	// appended to document.body, and blowing away body.innerHTML would
	// detach the singleton react.js still holds a reference to.
	document.querySelector( '.emoji-reactions' ).innerHTML = FIXTURE;

	// The <emoji-picker> element is a lazily-created singleton, reused
	// across tests once a prior test has opened it -- reset it closed so
	// each test starts from a known state regardless of how the previous
	// one left it.
	const picker = document.querySelector( 'emoji-picker' );
	if ( picker ) {
		picker.style.display = 'none';
	}
} );

test( "clicking a reaction bubble POSTs the reaction to the REST endpoint, including this browser's anonymous client id", () => {
	const bubble = document.querySelector( '.emoji-reaction' );

	click( bubble );

	expect( MockXHR.instances ).toHaveLength( 1 );

	const request = MockXHR.instances[ 0 ];
	expect( request.method ).toBe( 'POST' );
	expect( request.url ).toBe( SETTINGS.endpoint );
	expect( request.requestHeaders[ 'Content-type' ] ).toBe(
		'application/x-www-form-urlencoded'
	);
	expect( request.requestHeaders[ 'X-WP-Nonce' ] ).toBe( SETTINGS.nonce );

	const clientId = window.localStorage.getItem( 'wp-react-client-id' );
	expect( clientId ).toEqual( expect.any( String ) );
	expect( request.body ).toBe(
		'post=42&emoji=' +
			encodeURIComponent( '😀' ) +
			'&client_id=' +
			encodeURIComponent( clientId )
	);
} );

test( 'the anonymous client id is stable across multiple reactions', () => {
	const bubble = document.querySelector( '.emoji-reaction' );

	click( bubble );
	click( bubble );

	expect( MockXHR.instances ).toHaveLength( 2 );

	const clientId = window.localStorage.getItem( 'wp-react-client-id' );
	expect( MockXHR.instances[ 0 ].body ).toContain(
		'client_id=' + encodeURIComponent( clientId )
	);
	expect( MockXHR.instances[ 1 ].body ).toContain(
		'client_id=' + encodeURIComponent( clientId )
	);
} );

test( 'reacting still POSTs, without a client id, when localStorage is unavailable', () => {
	const { localStorage } = window;
	Object.defineProperty( window, 'localStorage', {
		configurable: true,
		value: {
			getItem: () => {
				throw new Error( 'localStorage disabled' );
			},
			setItem: () => {
				throw new Error( 'localStorage disabled' );
			},
		},
	} );

	try {
		const bubble = document.querySelector( '.emoji-reaction' );

		click( bubble );

		expect( MockXHR.instances ).toHaveLength( 1 );
		expect( MockXHR.instances[ 0 ].body ).toBe(
			'post=42&emoji=' + encodeURIComponent( '😀' )
		);
	} finally {
		Object.defineProperty( window, 'localStorage', {
			configurable: true,
			value: localStorage,
		} );
	}
} );

test( 'the add-reaction button creates and shows an <emoji-picker>, pointed at the self-hosted emoji data', () => {
	const addButton = document.querySelector( '.emoji-reaction-add' );

	click( addButton );

	const picker = document.querySelector( 'emoji-picker' );
	expect( picker ).not.toBeNull();
	expect( picker.style.display ).toBe( 'block' );
	expect( picker.dataSource ).toBe( SETTINGS.emoji_data_url );
	expect( picker.dataset.post ).toBe( '42' );

	// Clicking the add button again, while the picker is open, closes it.
	click( addButton );
	expect( picker.style.display ).toBe( 'none' );
} );

test( 'positions the picker inline on desktop, but leaves positioning to CSS on narrow screens', () => {
	const addButton = document.querySelector( '.emoji-reaction-add' );
	const picker = document.querySelector( 'emoji-picker' );
	const originalClientWidth = document.documentElement.clientWidth;

	try {
		Object.defineProperty( document.documentElement, 'clientWidth', {
			configurable: true,
			value: 1024,
		} );
		click( addButton );
		expect( picker.style.top ).not.toBe( '' );
		expect( picker.style.left ).not.toBe( '' );
		click( addButton ); // close it again

		// Below 768px, static/react.css switches the picker to a fixed
		// bottom sheet (position: fixed; left: 0; bottom: 0) -- setting
		// inline left/top here would override that regardless of the
		// media query, and risk an off-screen negative top besides.
		Object.defineProperty( document.documentElement, 'clientWidth', {
			configurable: true,
			value: 375,
		} );
		click( addButton );
		expect( picker.style.top ).toBe( '' );
		expect( picker.style.left ).toBe( '' );
	} finally {
		Object.defineProperty( document.documentElement, 'clientWidth', {
			configurable: true,
			value: originalClientWidth,
		} );
	}
} );

test( "clicking inside the picker's own UI (e.g. a category tab) doesn't dismiss it", () => {
	const addButton = document.querySelector( '.emoji-reaction-add' );

	click( addButton );

	const picker = document.querySelector( 'emoji-picker' );
	expect( picker.style.display ).toBe( 'block' );

	// A click on something inside the picker's shadow DOM (a category
	// tab, the search box, etc.) still bubbles up to the document, but
	// the DOM retargets its event.target to the <emoji-picker> host
	// element itself for listeners outside the shadow tree -- dispatching
	// directly on the picker reproduces that retargeting without needing
	// a real shadow root.
	click( picker );

	expect( picker.style.display ).toBe( 'block' );
} );

test( 'clicking elsewhere on the page, outside the picker, does dismiss it', () => {
	const addButton = document.querySelector( '.emoji-reaction-add' );

	click( addButton );

	const picker = document.querySelector( 'emoji-picker' );
	expect( picker.style.display ).toBe( 'block' );

	click( document.body );

	expect( picker.style.display ).toBe( 'none' );
} );

test( 'picking an emoji from the picker POSTs the reaction and hides the picker', () => {
	const addButton = document.querySelector( '.emoji-reaction-add' );

	click( addButton );

	const picker = document.querySelector( 'emoji-picker' );
	picker.dispatchEvent(
		new window.CustomEvent( 'emoji-click', {
			detail: { unicode: '🎉' },
		} )
	);

	expect( MockXHR.instances ).toHaveLength( 1 );
	expect( MockXHR.instances[ 0 ].body ).toContain(
		'post=42&emoji=' + encodeURIComponent( '🎉' )
	);
	expect( picker.style.display ).toBe( 'none' );
} );

test( 'falls back to detail.emoji.unicode when the top-level detail.unicode is missing (e.g. from the favorites bar)', () => {
	// emoji-picker-element's own source (getDetailForClickEvent() in
	// picker.js) only includes a top-level `unicode` in the emoji-click
	// detail when it could resolve a skin-tone-adjusted variant; it can
	// be entirely absent, with the resolved emoji available only via
	// detail.emoji.unicode instead.
	const addButton = document.querySelector( '.emoji-reaction-add' );

	click( addButton );

	const picker = document.querySelector( 'emoji-picker' );
	picker.dispatchEvent(
		new window.CustomEvent( 'emoji-click', {
			detail: { emoji: { unicode: '🍉' } },
		} )
	);

	expect( MockXHR.instances ).toHaveLength( 1 );
	expect( MockXHR.instances[ 0 ].body ).toContain(
		'post=42&emoji=' + encodeURIComponent( '🍉' )
	);
	expect( picker.style.display ).toBe( 'none' );
} );

test( "doesn't POST when neither detail.unicode nor detail.emoji.unicode is present", () => {
	const addButton = document.querySelector( '.emoji-reaction-add' );

	click( addButton );

	const picker = document.querySelector( 'emoji-picker' );
	picker.dispatchEvent(
		new window.CustomEvent( 'emoji-click', {
			detail: {},
		} )
	);

	expect( MockXHR.instances ).toHaveLength( 0 );
	expect( picker.style.display ).toBe( 'none' );
} );

test( 'the add-reaction button does nothing when the picker is disabled', () => {
	SETTINGS.enable_picker = false;

	click( document.querySelector( '.emoji-reaction-add' ) );

	const picker = document.querySelector( 'emoji-picker' );

	// Either it was never created, or a previous test's singleton stayed shut.
	expect( ! picker || 'none' === picker.style.display ).toBe( true );
} );

test( 'clicking the reactions container itself does not POST', () => {
	// The container's class, 'emoji-reactions', contains 'emoji-reaction' as
	// a substring, so substring matching in the walk-up loop used to treat a
	// click on its own padding as a click on a bubble and POST
	// post=undefined&emoji=undefined.
	click( document.querySelector( '.emoji-reactions' ) );

	expect( MockXHR.instances ).toHaveLength( 0 );
} );

test( 'a login-gated bubble is left to navigate rather than POSTing', () => {
	const container = document.querySelector( '.emoji-reactions' );
	container.innerHTML =
		'<a href="#login" data-emoji="&#128512;" data-count="3" data-post="42" ' +
		'class="emoji-reaction emoji-reaction-login">' +
		'<div class="emoji">&#128512;</div><div class="count">3</div></a>';

	click( container.querySelector( '.emoji-reaction' ) );

	expect( MockXHR.instances ).toHaveLength( 0 );
} );

test( 'picking an emoji uses the base form when skin tones are disallowed', () => {
	SETTINGS.allow_skin_tones = false;

	click( document.querySelector( '.emoji-reaction-add' ) );

	document.querySelector( 'emoji-picker' ).dispatchEvent(
		new window.CustomEvent( 'emoji-click', {
			detail: { unicode: '👋🏽', emoji: { unicode: '👋' } },
		} )
	);

	expect( MockXHR.instances ).toHaveLength( 1 );
	expect( MockXHR.instances[ 0 ].body ).toContain(
		'emoji=' + encodeURIComponent( '👋' )
	);
} );

test( 'picking an emoji keeps the skin tone when they are allowed', () => {
	SETTINGS.allow_skin_tones = true;

	click( document.querySelector( '.emoji-reaction-add' ) );

	document.querySelector( 'emoji-picker' ).dispatchEvent(
		new window.CustomEvent( 'emoji-click', {
			detail: { unicode: '👋🏽', emoji: { unicode: '👋' } },
		} )
	);

	expect( MockXHR.instances[ 0 ].body ).toContain(
		'emoji=' + encodeURIComponent( '👋🏽' )
	);
} );

test( 'hides the skin-tone control inside the picker shadow root when skin tones are disallowed', () => {
	// Create the picker first, then give it a shadow root -- the mocked
	// library never registers the custom element, so it has none by default.
	click( document.querySelector( '.emoji-reaction-add' ) );

	const picker = document.querySelector( 'emoji-picker' );
	if ( ! picker.shadowRoot ) {
		picker.attachShadow( { mode: 'open' } );
	}

	picker.style.display = 'none';
	SETTINGS.allow_skin_tones = false;

	click( document.querySelector( '.emoji-reaction-add' ) );

	const style = picker.shadowRoot.getElementById( 'react-no-skintones' );
	expect( style ).not.toBeNull();
	expect( style.textContent ).toContain( 'skintone' );
} );

test( 'still reacts when the picker exposes no shadow root to hide tones in', () => {
	SETTINGS.allow_skin_tones = false;

	// The defensive path: hideSkinTonePicker() must not throw when there's
	// no shadow root, because reacting has to keep working regardless.
	expect( () => {
		click( document.querySelector( '.emoji-reaction-add' ) );
	} ).not.toThrow();
} );

test( 'an always-visible bubble survives dropping to zero, with its count hidden', () => {
	SETTINGS.always_visible = [ '😀' ];

	click( document.querySelector( '.emoji-reaction' ) );
	MockXHR.instances[ 0 ].respond( 200, '[]' );

	const bubble = document.querySelector( '.emoji-reaction' );

	expect( bubble ).not.toBeNull();
	expect( bubble.dataset.count ).toBe( '0' );
	expect( bubble.classList.contains( 'is-zero' ) ).toBe( true );
	expect( bubble.querySelector( '.count' ).textContent ).toBe( '0' );
} );

test( 'a bubble that is not always visible is removed when it drops to zero', () => {
	SETTINGS.always_visible = [];

	click( document.querySelector( '.emoji-reaction' ) );
	MockXHR.instances[ 0 ].respond( 200, '[]' );

	expect( document.querySelector( '.emoji-reaction' ) ).toBeNull();
} );

test( 'the is-zero class is cleared again once a reaction comes back', () => {
	SETTINGS.always_visible = [ '😀' ];

	click( document.querySelector( '.emoji-reaction' ) );
	MockXHR.instances[ 0 ].respond( 200, '[]' );

	click( document.querySelector( '.emoji-reaction' ) );
	MockXHR.instances[ 1 ].respond(
		200,
		JSON.stringify( [ { emoji: '😀', count: 1 } ] )
	);

	const bubble = document.querySelector( '.emoji-reaction' );
	expect( bubble.classList.contains( 'is-zero' ) ).toBe( false );
	expect( bubble.querySelector( '.count' ).textContent ).toBe( '1' );
} );

test( 'a summary entry with no bubble yet is inserted before the add button', () => {
	click( document.querySelector( '.emoji-reaction' ) );
	MockXHR.instances[ 0 ].respond(
		200,
		JSON.stringify( [
			{ emoji: '😀', count: 3 },
			{ emoji: '🎉', count: 1 },
		] )
	);

	const children = Array.from(
		document.querySelector( '.emoji-reactions' ).children
	);
	const newBubble = children.find( ( el ) => '🎉' === el.dataset.emoji );

	expect( newBubble ).toBeDefined();
	expect( children.indexOf( newBubble ) ).toBeLessThan(
		children.findIndex( ( el ) =>
			el.classList.contains( 'emoji-reaction-add' )
		)
	);
} );

test( 'renders a custom icon reaction as a real SVG node with a usable viewBox', () => {
	// The markup is exactly what core's icon sanitizer produces: attribute
	// names lowercased, so `viewBox` arrives as `viewbox`. Parsing it as
	// 'image/svg+xml' would leave namespaceURI null and viewBox missing,
	// rendering nothing -- this is the regression test for that.
	SETTINGS.icons = {
		'icon:react-custom/heart': {
			label: 'Heart',
			svg: '<svg xmlns="http://www.w3.org/2000/svg" viewbox="0 0 24 24"><path d="M1 21h4V9H1v12z"/></svg>',
		},
	};

	click( document.querySelector( '.emoji-reaction' ) );
	MockXHR.instances[ 0 ].respond(
		200,
		JSON.stringify( [ { emoji: 'icon:react-custom/heart', count: 2 } ] )
	);

	const bubble = document.querySelector( '.emoji-reaction-icon' );
	expect( bubble ).not.toBeNull();

	const svg = bubble.querySelector( '.emoji' ).firstElementChild;
	expect( svg.tagName.toLowerCase() ).toBe( 'svg' );
	expect( svg.namespaceURI ).toBe( 'http://www.w3.org/2000/svg' );
	expect( svg.hasAttribute( 'viewBox' ) ).toBe( true );
	expect( bubble.querySelector( '.count' ).textContent ).toBe( '2' );
} );

test( 'skips a custom icon reaction whose icon is not registered', () => {
	SETTINGS.icons = {};

	click( document.querySelector( '.emoji-reaction' ) );
	MockXHR.instances[ 0 ].respond(
		200,
		JSON.stringify( [ { emoji: 'icon:react-custom/long-gone', count: 1 } ] )
	);

	// Better to show nothing than to show the raw token as text.
	expect( document.body.textContent ).not.toContain( 'icon:react-custom' );
} );

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

	document.body.innerHTML = `
		<div class="emoji-reactions">
			<div data-emoji="&#128512;" data-count="3" data-post="42" class="emoji-reaction">
				<div class="emoji">&#128512;</div>
				<div class="count">3</div>
			</div>
			<div data-post="42" class="emoji-reaction-add"><div class="emoji">&#128515;+</div></div>
		</div>
	`;

	require( '../react.js' );
} );

beforeEach( () => {
	MockXHR.instances = [];

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

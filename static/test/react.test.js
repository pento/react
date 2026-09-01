/**
 * @jest-environment jsdom
 */

/**
 * `static/react.js` is a legacy, non-modular IIFE: it reads
 * `window.wp.react.settings` and wires up `document`-level event listeners
 * as a side effect of being loaded, rather than exporting anything. So it's
 * tested the way a browser would exercise it -- by setting up the DOM/
 * settings it expects, requiring it once, and then dispatching real events.
 */

const SETTINGS = {
	endpoint: 'https://example.test/wp-json/wp/v2/react',
	nonce: 'test-nonce',
	emoji_url: 'https://example.test/wp-content/plugins/react/static/emoji.json',
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
		<script type="text/html" id="tmpl-emoji-reaction-selector">
			<div id="emoji-reaction-selector">
				<div class="tabs">
					<div data-tab="0" class="emoji-reaction-tab"></div>
					<div data-tab="1" class="emoji-reaction-tab"></div>
				</div>
				<div class="container container-0"></div>
				<div class="container container-1"></div>
			</div>
		</script>
	`;

	require( '../react.js' );

	// react.js only calls loadEmoji() once (it guards on an internal
	// "loading" flag), whether that happens immediately at require time or
	// on a later 'load'/'DOMContentLoaded' event -- so dispatching 'load'
	// here reliably triggers it exactly once, regardless of jsdom's
	// document.readyState at require time.
	window.dispatchEvent( new window.Event( 'load' ) );

	const emojiRequest = MockXHR.instances.find(
		( instance ) => instance.url === SETTINGS.emoji_url
	);
	emojiRequest.respond( 200, JSON.stringify( { 0: [ [ '0x1f600' ] ], 1: [ [ '0x1f601' ] ] } ) );
} );

beforeEach( () => {
	MockXHR.instances = [];
} );

test( 'clicking a reaction bubble POSTs the reaction to the REST endpoint, including this browser\'s anonymous client id', () => {
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

test( 'the add-reaction button opens the popup on the first tab, switching tabs shows only that tab, and a second click closes the popup', () => {
	const addButton = document.querySelector( '.emoji-reaction-add' );

	click( addButton );

	const popup = document.getElementById( 'emoji-reaction-selector' );
	expect( popup ).not.toBeNull();
	expect( popup.style.display ).toBe( 'block' );
	expect(
		popup.querySelector( '.container-0' ).style.display
	).toBe( 'block' );
	expect(
		popup.querySelector( '.container-1' ).style.display
	).toBe( 'none' );

	click( popup.querySelector( '.emoji-reaction-tab[data-tab="1"]' ) );

	expect(
		popup.querySelector( '.container-0' ).style.display
	).toBe( 'none' );
	expect(
		popup.querySelector( '.container-1' ).style.display
	).toBe( 'block' );

	click( addButton );

	expect( popup.style.display ).toBe( 'none' );
} );

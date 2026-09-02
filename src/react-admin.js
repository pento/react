/**
 * Settings > Discussion glue for the Reactions plugin.
 *
 * Deliberately a separate bundle rather than a reuse of static/react.js:
 * that script installs a document-level click handler that posts reactions,
 * which has no business running in wp-admin. The cost is that
 * emoji-picker-element is bundled twice -- acceptable, since the two bundles
 * never load on the same request.
 */
import 'emoji-picker-element';

const settings =
	( window.wp && window.wp.react && window.wp.react.adminSettings ) || {};

/**
 * Pointer to the lazily-created <emoji-picker> element.
 *
 * @type {HTMLElement|null}
 */
let picker = null;

/**
 * Build one entry in the default reactions list.
 *
 * Built with createElement/textContent rather than innerHTML: the emoji ends
 * up in an attribute value, and this keeps it from ever being parsed as
 * markup.
 *
 * @param {string} emoji The emoji.
 * @return {HTMLElement} The list item.
 */
const createEmojiItem = function ( emoji ) {
	const item = document.createElement( 'li' );
	item.className = 'react-emoji-item';

	const input = document.createElement( 'input' );
	input.type = 'hidden';
	input.name = 'react_default_emoji[]';
	input.value = emoji;
	item.appendChild( input );

	const glyph = document.createElement( 'span' );
	glyph.className = 'react-emoji-item-glyph';
	glyph.setAttribute( 'aria-hidden', 'true' );
	glyph.textContent = emoji;
	item.appendChild( glyph );

	const remove = document.createElement( 'button' );
	remove.type = 'button';
	remove.className = 'button-link react-emoji-remove';
	remove.setAttribute(
		'aria-label',
		( settings.removeLabel || 'Remove %s' ).replace( '%s', emoji )
	);

	const times = document.createElement( 'span' );
	times.setAttribute( 'aria-hidden', 'true' );
	times.textContent = '×';
	remove.appendChild( times );

	item.appendChild( remove );

	return item;
};

/**
 * The emoji currently in the default reactions list.
 *
 * @param {HTMLElement} list The list element.
 * @return {Array} The emoji.
 */
const currentEmoji = function ( list ) {
	const inputs = list.querySelectorAll(
		'input[name="react_default_emoji[]"]'
	);
	const emoji = [];

	for ( let ii = 0; ii < inputs.length; ii++ ) {
		emoji.push( inputs[ ii ].value );
	}

	return emoji;
};

/**
 * Wire up the default reactions field.
 */
const initDefaultEmoji = function () {
	const fieldset = document.getElementById( 'react-default-emoji' );

	if ( ! fieldset ) {
		return;
	}

	const list = fieldset.getElementsByClassName( 'react-emoji-list' )[ 0 ];
	const addButton = document.getElementById( 'react-add-default-emoji' );
	const pickerHolder = document.getElementById( 'react-emoji-picker' );

	if ( ! list || ! addButton || ! pickerHolder ) {
		return;
	}

	fieldset.addEventListener( 'click', function ( event ) {
		const button = event.target.closest( '.react-emoji-remove' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		const item = button.closest( '.react-emoji-item' );

		if ( item ) {
			item.parentElement.removeChild( item );
		}
	} );

	addButton.addEventListener( 'click', function ( event ) {
		event.preventDefault();

		if ( ! picker ) {
			picker = document.createElement( 'emoji-picker' );

			if ( settings.emoji_data_url ) {
				picker.dataSource = settings.emoji_data_url;
			}

			picker.addEventListener( 'emoji-click', function ( pick ) {
				// Same fallback as the front end, for the same reason: the
				// library's favourites bar can omit detail.unicode.
				const emoji =
					pick.detail.unicode ||
					( pick.detail.emoji && pick.detail.emoji.unicode );

				if ( ! emoji ) {
					return;
				}

				if ( currentEmoji( list ).indexOf( emoji ) !== -1 ) {
					return;
				}

				const max = settings.maxDefaults || 12;

				if ( currentEmoji( list ).length >= max ) {
					return;
				}

				list.appendChild( createEmojiItem( emoji ) );
			} );

			pickerHolder.appendChild( picker );
		}

		const open = pickerHolder.hidden;
		pickerHolder.hidden = ! open;
		addButton.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	} );
};

/**
 * Let a chosen icon file upload even if "Save Changes" is used.
 *
 * The "Upload icon" button carries formenctype="multipart/form-data", so
 * uploading works with no JavaScript at all. This only covers the case where
 * a file has been picked and the main Save button is clicked instead, which
 * would otherwise post urlencoded and silently drop the file.
 */
const initIconUpload = function () {
	const file = document.getElementById( 'react_icon_file' );

	if ( ! file || ! file.form ) {
		return;
	}

	file.addEventListener( 'change', function () {
		if ( file.files && file.files.length ) {
			file.form.enctype = 'multipart/form-data';
		}
	} );
};

initDefaultEmoji();
initIconUpload();

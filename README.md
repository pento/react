# Reactions #
Contributors:      pento, georgestephanis
Tags:              emoji, reactions, comments
Requires at least: 4.4
Tested up to:      7.1
Stable tag:        trunk
License:           GPLv2 or later
License URI:       http://www.gnu.org/licenses/gpl-2.0.html

💩 reactions -- lets visitors react to posts with an emoji, Facebook/GitHub-style.

[![WordPress Playground](https://img.shields.io/badge/WordPress%20Playground-Try%20it%20now!-blue?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/pento/react/master/blueprint.json)
[![CI](https://github.com/pento/react/actions/workflows/ci.yml/badge.svg)](https://github.com/pento/react/actions/workflows/ci.yml)

## Description ##

Adds an emoji reaction picker under each post's content. Reactions are stored as ordinary
WordPress comments (`comment_type` = `reaction`), so they inherit existing comment
moderation -- and are automatically excluded from the theme's normal comment list, comment
feeds, and things like the Recent Comments widget, so they don't show up twice.

A REST route is registered at `/wp/v2/react`:

- `GET /wp/v2/react?post[]=123` -- list reaction tallies for one or more posts. Returns an
  array of `{ emoji, count, post_id }` groups, with `X-WP-Total`/`X-WP-TotalGroups` response
  headers.
- `POST /wp/v2/react` -- add a reaction, or remove it if the current user/visitor already
  reacted with this emoji on this post (a toggle). Requires a `post` (post ID) and `emoji` (one
  of the emoji offered by the picker) param, plus a valid REST nonce. Accepts an optional
  `client_id` param -- a per-browser identifier logged-out visitors can send to toggle their
  own reactions off; logged-in reactions are toggled by user ID instead.

Additional background at https://make.wordpress.org/core/2016/03/07/reactions/.

## Installation ##

1. Upload the plugin files to `/wp-content/plugins/react`, or install through the WordPress
   plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.

## Settings ##

Settings > Discussion gains a **Reactions** section:

* **Default reactions** -- emoji shown under every post, even before anyone has reacted, so
  visitors can react in one click. Reorder by removing and re-adding; the display order is the
  order they're listed in.
* **Emoji picker** -- turn off to hide the picker button, restricting reactions to the default
  set above ([#14](https://github.com/pento/react/issues/14)).
* **Skin tones** -- turn off to record reactions with the default skin tone.
* **Reacting** -- require visitors to be logged in. Counts stay visible to everyone; logged-out
  visitors get a link to the log in screen ([#32](https://github.com/pento/react/issues/32)).
* **Custom reaction icons** -- upload SVGs to use as reactions alongside emoji, registered
  through the [Icon Registration API](https://developer.wordpress.org/news/2026/08/hands-on-with-the-wordpress-7-1-icon-registration-api/)
  added in WordPress 7.1. Custom icons always appear as one-click reactions under the post,
  in the same row as the default emoji -- by design, they aren't listed inside the emoji
  picker (see below).

Every one of these is enforced in the REST controller, not just in the browser --
`POST /wp/v2/react` is a public route, so the JavaScript side is only ever a convenience.

Settings changes are never retroactive. Turning off skin tones or the picker, or retiring a
custom icon, stops *new* reactions of that kind without stranding any that visitors have
already left: those keep rendering, and stay removable.

### Custom reaction icons ###

WordPress sanitizes registered icons hard, and it's worth knowing the shape of that before
preparing artwork:

* **SVG only** -- PNG and JPEG can't be registered as icons at all.
* Only `<svg>`, `<path>` and `<polygon>` survive. `<g>`, `<circle>`, `<rect>`, gradients and
  the rest are stripped, and because sanitization keeps the *children* of a stripped element, a
  `<g transform="...">` wrapper would silently disappear and leave its paths misplaced.
* `stroke` attributes are stripped, so outline icon sets (Feather, Lucide, Heroicons outline)
  render as solid blobs.

Rather than let any of that fail quietly, uploads are checked up front and rejected with an
explanation. Flatten artwork to filled paths first.

Uploads are handled entirely server-side: the file itself is posted to WordPress, read, and
sanitized there. Only the resulting markup is stored, in a non-autoloaded option -- the file is
never moved into the uploads directory and never becomes an attachment, so SVG stays disabled
as an upload type site-wide and nothing is added to your media library.

Custom icons are offered as always-visible reactions rather than as entries in the emoji
picker. That's deliberate: the picker's own custom-emoji support needs an image *URL* per
entry, which would mean re-encoding every icon as a data URI purely so it could be browsed
alongside 1,900 emoji it has nothing in common with. Treating them as prepopulated reactions is
both simpler and a better fit for what they're for -- a small, curated set specific to the site.

## Hooks ##

| Hook | Type | Fires |
| --- | --- | --- |
| `rest_reaction_query` | filter | Before querying reactions via the REST API -- filters the `WP_Comment_Query` args. |
| `rest_prepare_reaction` | filter | After a reaction group is prepared for a REST response. |
| `react_reaction_created` | action | After a new reaction comment is inserted. |
| `react_reaction_removed` | action | After a reaction comment is removed by toggling it off (reacting again with the same emoji). |
| `react_reaction_count_invalidated` | action | After a post's cached reaction count is invalidated (on insert, delete, or status change). |
| `react_reaction_markup` | filter | Filters the reactions widget's HTML before it's appended to the post content. |
| `react_reaction_count_cache_group` | filter | Filters the cache group the per-post reaction count is stored under. |
| `react_reaction_count_cache_ttl` | filter | Filters the cache TTL (in seconds) for the per-post reaction count. |

## Development ##

See [AGENTS.md](AGENTS.md) for build/lint/test commands and repository conventions.

## Security ##

See [SECURITY.md](SECURITY.md) to report a vulnerability.

## Changelog ##

### Unreleased ###

* Added a **Reactions** section to Settings > Discussion: configurable default reactions, an
  optional emoji picker, a skin tone toggle, an option to require visitors to be logged in, and
  custom SVG reaction icons registered via the WordPress 7.1 Icon Registration API. Every
  setting is enforced server-side in the REST controller, and settings changes never strand
  reactions that have already been left. Closes
  [#14](https://github.com/pento/react/issues/14); addresses the authentication half of
  [#32](https://github.com/pento/react/issues/32) (rate limiting is still open).
* Fixed a click on the reactions container's own padding being treated as a click on a
  reaction, which POSTed `post=undefined&emoji=undefined`. The walk-up in `reactionClick()` now
  matches exact class tokens, rather than substrings -- the container's own class,
  `emoji-reactions`, contains `emoji-reaction`.
* Fixed a reaction toggled back off keeping a stale count until the next page load;
  `updateReactionDisplay()` now reconciles bubbles the summary no longer mentions.
* Swaps the hand-rolled emoji picker for the third-party
  [`emoji-picker-element`](https://github.com/nolanlawson/emoji-picker-element) web component
  (Apache-2.0 licensed, compatible with this plugin's GPLv2-or-later license via the "or later"
  clause) -- see [issue #7](https://github.com/pento/react/issues/7). This adds a build step
  (`npm run build`, see [AGENTS.md](AGENTS.md)) and drops support for browsers old enough to
  lack `document.addEventListener()`. **Breaking:** removes the `react_categories` filter --
  the new picker manages its own category grid internally, with no equivalent per-category
  customization hook.
* Fixes reactions always being attributed to "Anonymous", even when the reacting user is logged
  in.
* Adds the ability to remove a reaction by reacting again with the same emoji (a toggle), for
  both logged-in users and logged-out visitors (via a per-browser identifier kept in
  `localStorage`, never sent except with a reaction request).
* Adds a GitHub Actions CI workflow (PHPUnit across PHP 8.1-8.3, phpcs, JS lint/tests on every
  push/PR) and a new JS test suite for `static/react.js`.
* Fixes WP 7.1 / PHP 8.5 compatibility issues.
* Fixes a stored-XSS vector where unescaped reaction comment content could be rendered on the
  front end.
* Fixes reactions being counted twice (in both the reaction widget and the normal comment
  count/list/feeds) by filtering them out of default comment queries, with a cache to avoid a
  per-post-per-page-load COUNT query.
* Fixes two DOM-based injection issues where post content containing an element with a
  reused, unreserved ID could be picked up by the reaction picker's `getElementById()` lookups
  and treated as trusted markup.
* Adds `react_reaction_created` and `react_reaction_count_invalidated` actions, and
  `react_reaction_markup`, `react_categories`, `react_reaction_count_cache_group`, and
  `react_reaction_count_cache_ttl` filters.
* Removes a committed, unused 683KB raw build artifact (`static/emoji-raw.json`).
* Adds a WordPress Playground blueprint for a one-click demo.

### 0.1.0 ###

First release.

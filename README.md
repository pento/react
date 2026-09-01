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
- `POST /wp/v2/react` -- add a reaction. Requires a `post` (post ID) and `emoji` (one of the
  emoji offered by the picker) param, plus a valid REST nonce.

Additional background at https://make.wordpress.org/core/2016/03/07/reactions/.

## Installation ##

1. Upload the plugin files to `/wp-content/plugins/react`, or install through the WordPress
   plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.

## Hooks ##

| Hook | Type | Fires |
| --- | --- | --- |
| `rest_reaction_query` | filter | Before querying reactions via the REST API -- filters the `WP_Comment_Query` args. |
| `rest_prepare_reaction` | filter | After a reaction group is prepared for a REST response. |
| `react_reaction_created` | action | After a new reaction comment is inserted. |
| `react_reaction_count_invalidated` | action | After a post's cached reaction count is invalidated (on insert, delete, or status change). |
| `react_reaction_markup` | filter | Filters the reactions widget's HTML before it's appended to the post content. |
| `react_categories` | filter | Filters the emoji picker's category list. See the filter's own docblock in `print_selector()` for a known limitation on adding more than 8 categories. |
| `react_reaction_count_cache_group` | filter | Filters the cache group the per-post reaction count is stored under. |
| `react_reaction_count_cache_ttl` | filter | Filters the cache TTL (in seconds) for the per-post reaction count. |

## Development ##

See [AGENTS.md](AGENTS.md) for build/lint/test commands and repository conventions.

## Security ##

See [SECURITY.md](SECURITY.md) to report a vulnerability.

## Changelog ##

### Unreleased ###

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

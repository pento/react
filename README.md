# Reactions

💩 reactions — lets visitors react to posts with an emoji, Facebook/GitHub-style.

[![WordPress Playground](https://img.shields.io/badge/WordPress%20Playground-Try%20it%20now!-blue?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/pento/react/master/blueprint.json)
[![CI](https://github.com/pento/react/actions/workflows/ci.yml/badge.svg)](https://github.com/pento/react/actions/workflows/ci.yml)

> This is the repository README, aimed at people working on the plugin. The user-facing
> description, FAQ and changelog live in [`readme.txt`](readme.txt), which is what
> WordPress.org publishes.

## What it does

Adds an emoji reaction picker under each post's content. Reactions are stored as ordinary
WordPress comments (`comment_type` = `reaction`), so they inherit existing comment moderation —
and are automatically excluded from the theme's normal comment list, comment feeds, and things
like the Recent Comments widget, so they don't show up twice.

Additional background at <https://make.wordpress.org/core/2016/03/07/reactions/>.

## Requirements

| | Minimum | Notes |
| --- | --- | --- |
| WordPress | 4.7 | `WP_REST_Controller`, `register_setting()`'s `$args` array, and `rest_authorization_required_code()` all landed in 4.7. |
| PHP | 7.0 | Null coalescing in the REST controller. CI currently only exercises 8.1–8.3. |

Custom reaction icons additionally need **WordPress 7.1**, for the Icon Registration API. They're
feature-detected, so the rest of the plugin works fine without it.

## REST API

A REST route is registered at `/wp/v2/react`:

- `GET /wp/v2/react?post[]=123` — list reaction tallies for one or more posts. Returns an array
  of `{ emoji, count, post_id }` groups, with `X-WP-Total`/`X-WP-TotalGroups` response headers.
- `POST /wp/v2/react` — add a reaction, or remove it if the current user/visitor already reacted
  the same way on this post (a toggle). Requires a `post` (post ID) and `emoji` param, plus a
  valid REST nonce. Accepts an optional `client_id` param — a per-browser identifier logged-out
  visitors can send to toggle their own reactions off; logged-in reactions are toggled by user
  ID instead.

The `emoji` param takes either an emoji from `static/emoji-data.json` or a custom icon
reference such as `icon:react-custom/heart`. Its error code is still `rest_invalid_emoji` for
backwards compatibility, even though the value need not be an emoji.

## Settings

Settings > Discussion gains a **Reactions** section — default reactions, an optional emoji
picker, a skin tone toggle, a login requirement, and custom SVG icons. See
[`readme.txt`](readme.txt) for what each one does from a site owner's point of view.

Three things about how they're implemented are worth knowing before changing this area:

- **Every setting is enforced in the REST controller, not the browser.** `POST /wp/v2/react` is
  a public route, so the JavaScript side is only ever a convenience.
- **Settings changes are never retroactive.** Removal goes through the same route as creation,
  so a single settings-dependent check would leave existing reactions visible but impossible to
  un-react. `React_Settings::is_known_reaction()` is a permanent superset that gates validation;
  `is_offerable_reaction()` is the settings-dependent one, checked only when inserting.
- **Custom icons are always-visible reactions, not picker entries.** `emoji-picker-element`'s
  custom-emoji support needs an image URL per entry, which would mean re-encoding each icon as a
  data URI purely to browse it alongside 1,900 emoji. Uploads are handled entirely server-side
  and only sanitized markup is stored, so SVG stays disabled as an upload type site-wide.

[AGENTS.md](AGENTS.md) documents the invariants in this area in more detail.

## Hooks

| Hook | Type | Fires |
| --- | --- | --- |
| `rest_reaction_query` | filter | Before querying reactions via the REST API — filters the `WP_Comment_Query` args. |
| `rest_prepare_reaction` | filter | After a reaction group is prepared for a REST response. |
| `react_reaction_created` | action | After a new reaction comment is inserted. |
| `react_reaction_removed` | action | After a reaction comment is removed by toggling it off. |
| `react_reaction_count_invalidated` | action | After a post's cached reaction count is invalidated (on insert, delete, or status change). |
| `react_reaction_markup` | filter | Filters the reactions widget's HTML before it's appended to the post content. |
| `react_reaction_count_cache_group` | filter | Filters the cache group the per-post reaction count is stored under. |
| `react_reaction_count_cache_ttl` | filter | Filters the cache TTL (in seconds) for the per-post reaction count. |

## Development

See [AGENTS.md](AGENTS.md) for build/lint/test commands and repository conventions.

## Security

See [SECURITY.md](SECURITY.md) to report a vulnerability.

## Changelog

See [`readme.txt`](readme.txt) — it's kept there rather than duplicated, so the two can't drift.

## License

GPLv2 or later. See [license.txt](license.txt).

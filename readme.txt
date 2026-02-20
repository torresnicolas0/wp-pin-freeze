=== Pin & Freeze ===
Contributors: nicoto
Donate link: https://github.com/sponsors/torresnicolas0
Tags: gutenberg, blocks, html, caching, editor
Requires at least: 5.2
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pin and freeze dynamic block output or full post/page content as editable static HTML.

== Description ==
Pin & Freeze lets administrators and editors freeze rendered HTML in two scopes:

1. Individual blocks (granular pinning).
2. Entire posts/pages (global pinning).

When an item is pinned, dynamic rendering is replaced by static HTML and the visual editing surface is locked with an overlay to avoid accidental changes.

Key features:

- Global block attributes: `wppf_is_pinned`, `wppf_html`.
- Dynamic block pin capture stores rendered HTML (SSR) instead of raw Gutenberg serialization comments.
- `render_block` interception for pinned blocks.
- `the_content` interception for pinned posts/pages.
- Inspector controls for block pinning and static HTML editing.
- Document settings panel for post pinning and full HTML editing.
- Admin list indicators with violet titles and pin state/actions.
- Capability-aware HTML sanitization (`unfiltered_html` respected).

== Installation ==
1. Upload the `pin-freeze` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Open a post/page in the block editor.
4. Use block inspector controls or the "Post Pinning" panel to pin/unpin HTML.

== Frequently Asked Questions ==
= Who can store unsanitized HTML? =
Only users with `unfiltered_html`. Other users' pinned HTML is sanitized with `wp_kses_post`.

= How do I unpin from the list table? =
On `Posts` or `Pages` list, use the "Despinear" row action shown for pinned content.

= Does this work for custom post types? =
Yes, for public post types registered with `show_in_rest` enabled.

== Screenshots ==
1. Block inspector panel with Pin HTML controls.
2. Document sidebar panel with Capture and History tabs.
3. Admin posts list showing pinned state and violet title style.

== Upgrade Notice ==
= 1.0.2 =
Adds an Apply workflow for pinned block HTML editing and updates pinned overlay styling for clearer visual lock state.

= 1.0.1 =
Improves block pin capture to store rendered HTML for dynamic blocks instead of serialized block comments.

= 1.0.0 =
Initial public release.

== Changelog ==
= 1.0.2 =
* Added \"Aplicar cambios\" workflow for pinned block HTML edits in the inspector.
* Added applied HTML preview in the inspector before saving the post.
* Updated pinned block overlay with diagonal violet pattern and text badge styling.

= 1.0.1 =
* Block pinning now captures rendered HTML for dynamic blocks via Block Renderer REST endpoint.
* Added safe fallback for static blocks using block content generation.
* Prevented pinning when only serialized Gutenberg block comments are captured.
* Improved inspector feedback when rendered HTML capture fails.

= 1.0.0 =
* Initial release.
* Block-level pinning with overlay lock and inspector code editor.
* Post/page-level pinning with document panel and static HTML meta.
* Admin list pinned indicators, violet title styling, and unpin row action.

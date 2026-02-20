# Block Pin Render Capture

## Problem
Some dynamic blocks can serialize to Gutenberg comments (for example `<!-- wp:namespace/block {...} /-->`) instead of final frontend HTML.

If that serialized comment is stored in `wppf_html`, pinned rendering shows the comment text rather than real markup.

## Implemented Strategy (v1.0.2)

1. When user enables **Pin HTML** for a block, the editor attempts server-side render capture first for likely dynamic blocks.
2. Capture endpoint: `POST /wp/v2/block-renderer/<namespace%2Fblock>?context=edit&post_id=<id>`.
3. If rendered response is valid HTML (not comment-only), it is stored in `wppf_html`.
4. Fallback for static blocks uses `getBlockContent( block )`.
5. If capture returns comment-only or empty output, pinning is canceled and the inspector shows a warning.
6. Editing pinned HTML in the inspector now uses a draft + **Aplicar cambios** flow, with an applied preview panel before post save.

## Why this is safer for WordPress.org

1. It avoids storing invalid frozen payloads for dynamic blocks.
2. It keeps behavior aligned with user expectation: frozen **rendered HTML**, not block serialization format.
3. No unsafe eval, no external tracking, no custom unauthenticated endpoints.

## Related file

- `src/components/BlockPinControl.js`

# Pin & Freeze

WordPress plugin to pin/freeze rendered HTML for:

- Individual Gutenberg blocks.
- Entire posts/pages.

When pinned, dynamic rendering is replaced by static HTML and the visual editor is locked to avoid accidental edits.

## Release

- Current version: `1.0.1`
- Block pinning now captures rendered HTML for dynamic blocks (SSR) and avoids storing raw Gutenberg comment serialization as frozen HTML.
- Dynamic block capture uses the Block Renderer REST endpoint with static-content fallback.

## Requirements

- WordPress 5.2+
- PHP 7.2+

## Installation

1. Copy this plugin folder to `wp-content/plugins/pin-freeze`.
2. Activate **Pin & Freeze** from WordPress admin.
3. Edit a post/page in Gutenberg and use:
   - Block inspector: pin per block.
   - Document panel: pin full post/page.

## Development

```bash
npm install
npm run build
```

Compiled assets are generated in `build/`.

## Main Files

- `pin-freeze.php` plugin bootstrap and runtime hooks.
- `includes/settings-page.php` settings page (`Ajustes > Pin & Freeze`).
- `includes/ajax-fetcher.php` frontend capture via AJAX.
- `includes/history-manager.php` snapshot history manager.
- `docs/BLOCK_PIN_RENDER_CAPTURE.md` technical note for dynamic block pin capture strategy.
- `src/` editor-side React code.
- `languages/` translations.

## License

GPL-2.0-or-later

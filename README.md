# WP Pin & Freeze

WordPress plugin to pin/freeze rendered HTML for:

- Individual Gutenberg blocks.
- Entire posts/pages.

When pinned, dynamic rendering is replaced by static HTML and the visual editor is locked to avoid accidental edits.

## Requirements

- WordPress 5.2+
- PHP 7.2+

## Installation

1. Copy this plugin folder to `wp-content/plugins/wp-pin-freeze`.
2. Activate **WP Pin & Freeze** from WordPress admin.
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

- `wp-pin-freeze.php` plugin bootstrap and runtime hooks.
- `includes/settings-page.php` settings page (`Ajustes > WP Pin & Freeze`).
- `includes/ajax-fetcher.php` frontend capture via AJAX.
- `includes/history-manager.php` snapshot history manager.
- `src/` editor-side React code.
- `languages/` translations.

## License

GPL-2.0-or-later

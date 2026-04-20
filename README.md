# 3MMM Home Photo Sets

This plugin replaces the homepage `ministry-photos` WPBakery carousel with a structured photo-set gallery.

What it does:

- seeds the current seven homepage photo sets using the existing media IDs
- shows the newest sets first
- keeps each set together and stops before the configured max image count is exceeded
- gives each set a real heading, excerpt, and optional description instead of relying on caption-card images
- lets future updates happen through a simple `Photo Sets` admin menu
- checks GitHub for plugin updates through the bundled plugin update checker

Usage:

1. Install and activate the plugin.
2. The homepage carousel with `el_class="ministry-photos"` will be replaced automatically.
3. To update the gallery later, add or edit entries under `Photo Sets`.
4. Publish new sets with their own post dates to control ordering.

Shortcode:

`[mmm_photo_sets_gallery max_images="25"]`

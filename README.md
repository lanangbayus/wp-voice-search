=== WP Voice Search ===
Contributors: lanangbayus
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.0.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Voice search for WordPress — works with Shortcode and Gutenberg core/search. Auto mic icon from latest user upload, real-time meter, tooltip, and i18n-ready.

== Description ==
WP Voice Search adds a microphone button to your search forms (shortcode, classic theme forms, and Gutenberg core/search). It supports:
- Real-time audio meter animation
- Auto-detected custom mic icon via CSS var `--wpvs-mic-icon` (automatically set from your latest uploaded icon)
- Tooltip synced with listening state
- Language auto-detection (Polylang/WPML/Weglot/site language)
- Accessibility-friendly (ARIA live, keyboard focus)
- Dark mode friendly

== Installation ==
1. Upload the `wp-voice-search-level1` folder to `/wp-content/plugins/`.
2. Activate **WP Voice Search** from Plugins menu.
3. Optional: Upload your mic icon (e.g., `microphone-1.png`). The plugin will pick the latest icon (or use the selected attachment ID in option `wpvs_mic_icon_id`).

== Usage ==
*Shortcode*
```
[voice_search placeholder="Search…" lang="id-ID" autostart="false" submit="true" btn="🎤"]
```
*Gutenberg*
Insert **Search** block. The plugin will inject mic, meter, and icon automatically.

== FAQ ==
= My icon didn’t change =
Ensure caching is cleared. The plugin appends a `?ver=` based on file mtime to bypass cache.

== Changelog ==
= 1.0.3 =
* Initial public release (Level 1): unified shortcode + Gutenberg, dynamic icon, meter, tooltip, auto language.

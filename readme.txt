=== Multilingual Page Translator ===
Contributors: codex
Tags: translation, multilingual, acf, pages, language switcher
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later

Duplicate WordPress pages into multiple languages, translate ACF fields and ACF Gutenberg blocks, rewrite internal links, and show a flag language dropdown.

== Description ==

Multilingual Page Translator helps create connected translated copies of pages. It stores a language and translation group for each page, duplicates source pages into target languages, copies all post meta including ACF field values, translates ACF Gutenberg block data recursively, and rewrites internal page links to the matching translated page when possible.

Automatic translation is optional but needs a real translation API endpoint. Configure a LibreTranslate-compatible endpoint in Page Translator > Settings, then select "Use configured translation API when available" during duplication. Without an endpoint, the plugin creates duplicate pages for manual editing only.

== Features ==

* Duplicate one page or all source-language pages into another language.
* Copy ACF fields and nested array meta values.
* Translate ACF Gutenberg block fields, including groups, repeaters, flexible content arrays, link titles, image alt text, and text fields.
* Skip technical ACF values such as field keys, IDs, URLs, files, images, colors, anchors, classes, and layout settings.
* Optionally translate title, content, excerpt, and text-based meta values.
* Replace internal links with translated page URLs when a connected translation exists.
* Create translated pages as Pending Review or Draft so an editor can approve before publishing.
* Hide languages from the front-end dropdown while keeping them available in admin.
* Adds language-prefixed URLs such as `/pt/`, `/en/`, and `/es/` for translated pages.
* Translates ACF-style `attrs.data` fields inside custom Gutenberg blocks, including non-`acf/` block namespaces.
* Add a flags dropdown to a theme menu automatically.
* Use `[mpt_language_switcher]` anywhere shortcodes are supported.
* Add hreflang tags for connected published page translations.

== Installation ==

1. Upload the `multilingual-page-translator` folder to `wp-content/plugins/`.
2. Activate "Multilingual Page Translator" in WordPress.
3. Open Page Translator in the admin menu.
4. Configure languages, duplicate pages, then edit translated drafts.

== Translation API ==

The optional endpoint expects a LibreTranslate-style response:

`{"translatedText":"Translated text"}`

The plugin sends JSON with `q`, `source`, `target`, `format`, and optional `api_key`.

For a local LibreTranslate server, use:

`http://127.0.0.1:5055/translate`

== Front-End Languages ==

Set `"display": "1"` on languages that should appear in the front-end dropdown. Set `"display": "0"` to keep a language available in the admin without showing it to visitors.

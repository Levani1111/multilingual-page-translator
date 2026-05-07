# Multilingual Page Translator

WordPress plugin by **L.P.**

Duplicate pages into other languages, copy ACF content, update internal links, and show a clean flag language switcher.

## What It Does

Multilingual Page Translator helps manage a small multilingual WordPress site without adding a heavy translation system.

It connects translated pages together, copies page content and ACF fields, and adds language URLs such as `/en/`. The default language can stay on the main site URL, for example Portuguese on `/`.

Automatic translation can be used when a translation API is available. If no API is available, the plugin still creates connected pages for manual editing.

## Features

- Duplicate one page or all pages into another language.
- Copy ACF fields, repeaters, flexible content, groups, and custom Gutenberg block data.
- Translate text-based ACF values when automatic translation is enabled.
- Keep image, file, gallery, color, layout, and technical values safe.
- Replace internal page links with the matching translated page link.
- Translate ACF option-page values, such as footer content.
- Show a flag dropdown in the header menu.
- Add `hreflang` tags for connected published translations.
- Create translated pages as drafts or pending review, so an editor can check them first.

## Installation

1. Upload the `multilingual-page-translator` folder to `wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Go to **Page Translator** in the admin menu.
4. Set the site languages.
5. Duplicate or translate pages.
6. Review and publish the translated pages.

## Language URLs

For a Portuguese default site with English translations:

- Portuguese: `/`
- English: `/en/`

Use the page editor sidebar to choose the language for each page and connect translated versions.

You can also place the switcher manually with:

```text
[mpt_language_switcher]
```

## Automatic Translation

Automatic translation depends on an available translation service. If the service is blocked, offline, or rate-limited, the plugin will still duplicate pages, but text may need manual editing.

Editors should always review translated pages before publishing.

## ACF Support

The plugin is built for ACF-heavy sites. It works with normal ACF fields, option pages, and ACF data saved inside custom Gutenberg blocks.

Text fields are translated. Technical values such as IDs, file URLs, image data, colors, CSS classes, anchors, and layout settings are preserved.

## Developer Notes

Plugin files:

- `multilingual-page-translator.php`
- `assets/mpt-front.css`
- `assets/mpt-front.js`
- `assets/mpt-admin.css`
- `assets/mpt-admin.js`
- `readme.txt`

Before release:

1. Update the plugin version in `multilingual-page-translator.php`.
2. Update `Stable tag` in `readme.txt`.
3. Run `php -l multilingual-page-translator.php`.
4. Build a zip from the plugin folder if needed.
5. Clear WordPress cache after updating frontend CSS or JS.

Do not commit local translation servers, virtual environments, cache folders, or generated zip files.

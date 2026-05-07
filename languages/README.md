# Translations (Wedding Party RSVP — free)

This directory holds translation templates and JSON language packs for PHP and JavaScript.

## Generate the POT template (WP-CLI)

From the **plugin root** (parent of this folder), with [WP-CLI i18n](https://make.wordpress.org/cli/handbook/how-to-install/) installed:

```bash
wp i18n make-pot . languages/wedding-party-rsvp.pot \
  --domain=wedding-party-rsvp \
  --exclude=node_modules,vendor,build,assets/blueprints
```

Or use the npm script (same command):

```bash
npm run i18n:make-pot
```

## JavaScript JSON translations

After you have a `.po` file for a locale (e.g. from GlotPress or by copying the POT to `wedding-party-rsvp-de_DE.po` and translating), build the script translation JSON files WordPress loads for `wp_set_script_translations`:

```bash
wp i18n make-json languages --pretty-print
```

This produces `*.json` files next to the `.po` files. Deploy those JSON files with the same text domain and `languages/` path; WordPress maps them to script handles (see `wgrsvp_set_script_translations()` in the main plugin file).

## WPML / Polylang

See `wpml-config.xml` in the plugin root for admin option strings. Guest rows live in a custom table and are not post content, so per-row “translation” of guest PII is out of scope for WPML; translate site-wide settings and duplicate RSVP pages per language as needed.

# TEST-REPORT — wedding-party-rsvp
**Date:** 2026-09-30
**Plugin version:** 8.5.3
**Tested by:** Claude Code /run-tests command

## Capability Detection
| Check | Result |
|---|---|
| phpcs config | found (`phpcs.xml`) |
| PHPUnit config | found (`phpunit.xml.dist`) |
| e2e / Playwright | not found |
| wp-env config | not found (in this plugin; used a running host wp-env for Plugin Check) |
| composer / vendor | found / found |
| npm / node_modules | found / found |

## Phase Results

| Phase | Status | Notes |
|---|---|---|
| 1 — PHP Syntax | PASS | Plugin PHP is clean. `svn-checkout/` (gitignored local SVN working copy) has unresolved conflict markers and was excluded. |
| 2 — phpcs | PASS | 20 ERRORs fixed. 0 ERRORs remaining. 166 warnings remaining (159 auto-fixable alignment). |
| 3 — Composer | SKIPPED | `vendor/` already present. |
| 4 — PHPUnit | PASS | 9 tests, 60 assertions. WordPress test suite via `WP_TESTS_DIR` / `wp-phpunit`. |
| 5 — npm | PASS | `npm install` exit 0. 72 audit findings (5 low, 32 moderate, 33 high, 2 critical) — install warnings only; did not fail the run. |
| 6 — Browser env | SKIPPED | No `e2e/` directory or Playwright config. |
| 7 — Playwright | SKIPPED | No Playwright config. |
| 8 — Plugin Check | PASS | 0 ERRORs on a distribution-shaped tree (same excludes as `create-plugin-zip.sh`). 5 documented warnings. |

## Issues Found and Fixed

1. **`wedding-party-rsvp.php:1237`** — Missing docblock for `save_rsvp_guest_updates_for_party()`. Added a function doc comment.
2. **`includes/class-wgrsvp-print-partners.php:176`** — Doc comment missing a short description. Added one.
3. **`includes/class-wgrsvp-mobile-admin.php:156`** — `<?php else :` failed EmbeddedPhp (open tag not on its own line). Split into `<?php else : ?>` plus a following PHP assignment block.
4. **`includes/class-wgrsvp-guestbook-captcha.php:139`** — Doc comment missing a short description. Added one.
5. **`includes/class-wgrsvp-guestbook.php:76`** — Doc short description started with lowercase `dbDelta`. Reworded.
6. **`includes/class-wgrsvp-guestbook.php:262`** — Empty `if` used only as a comment. Removed the no-op `if`; kept the comment.
7. **`includes/class-wgrsvp-guestbook.php:504`** — Associative multi-item array on one line. Expanded to one item per line.
8. **`includes/class-wgrsvp-docs-hub.php` (20, 27, 34, 140)** — Missing doc short descriptions. Added them.
9. **`includes/class-wgrsvp-drip.php:607`** — `count()` used in a `while` condition. Assigned to `$step_count` and increment inside the loop.
10. **`includes/class-wgrsvp-drip.php:859`** — Unescaped `$sent` in `printf`. Wrapped with `absint()`.
11. **`includes/class-wgrsvp-itinerary-travel.php:287`** — Doc short description started with `register_setting`. Capitalized.
12. **`includes/class-wgrsvp-ics.php:302`** — Missing `@param` for `$format`. Documented.
13. **`includes/class-wgrsvp-growth-checklist.php:91`** — Empty `if` / `elseif` used to skip the Getting started panel. Replaced with a boolean `$setup_guide_active` guard (same behavior).
14. **`includes/class-wgrsvp-setup-guide.php:857–868`** — Double-quoted SQL and interpolated `$table_safe` on a pre-6.2 branch. Plugin requires WordPress 6.2+, so the query now always uses `$wpdb->prepare()` with `%i`.
15. **`includes/class-wgrsvp-setup-guide.php:988, 997`** — Missing doc short descriptions. Added them.
16. **`admin-docs/index.php`** — Empty file (no ABSPATH guard). Added a direct-access exit and package docblock.
17. **Plugin Check Offloading on Playground / captcha URLs** — Admin hyperlinks and required vendor APIs were flagged as “offloaded content.” Added `phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent` (and EnqueuedResourceOffloading on the Turnstile/reCAPTCHA client scripts) with reasons. Captcha endpoints are already documented under `== External Services ==` in `readme.txt`.
18. **Plugin Check nonce / DirectDB warnings** — Extended existing ignore comments on read-only GET filters (`docs-hub`, `song-requests`) and the already-prepared dynamic `WHERE` in `class-wgrsvp-mobile-rest.php`.

## Known Limitations

1. **`svn-checkout/` parse error** — Local gitignored WordPress.org SVN working copy contains unresolved `<<<<<<<` conflict markers. Not plugin source; not shipped. Do not use that tree until the SVN conflict is resolved by hand.
2. **PHPUnit environment** — Tests need WordPress core (`WP_CORE_DIR`, default `/tmp/wordpress`) and MariaDB/MySQL database `wgrsvp_phpunit`. Run `bash bin/install-wp-tests.sh` if either is missing.
3. **No Playwright / e2e** — Browser tests were not run.
4. **No in-repo `.wp-env.json`** — Plugin Check ran against a dist-shaped copy dropped into a host wp-env that already had Plugin Check active, then the copy was removed.
5. **Full-repo Plugin Check vs zip** — Scanning the raw git tree flags hidden files (`.release`, `languages/.gitkeep`), `*.sh` tooling, root `*.md`, and `.github`. Those are excluded by `create-plugin-zip.sh` / `.distignore`. Always run PCP on the zip or a zip-shaped tree before WordPress.org upload.
6. **phpcs warnings (166)** — Almost all are `Generic.Formatting` equals-sign / double-arrow alignment. Not treated as failures. Running `phpcbf` would rewrite large arrays in the main plugin file and setup guide.
7. **npm audit (72)** — Comes from `@wordpress/scripts` and `@wordpress/dataviews` transitive deps. Not a plugin-runtime issue; review before the next build-tool upgrade.
8. **PCP remaining warnings (dist tree)** — `NonceVerification.Recommended` on read-only admin GET params (`class-wgrsvp-docs-hub.php`, `class-wgrsvp-song-requests.php`) and `PluginCheck.Security.DirectDB.UnescapedDBParameter` on a placeholder-built `WHERE` in `class-wgrsvp-mobile-rest.php`. Inline comments explain why. Not auto-fixed.

## PHPUnit skips

No plugin tests were skipped. There are no optional-plugin dependencies (WooCommerce, ACF, TEC) in this suite.

WordPress core bootstrap still prints “Not running ajax / ms-files / external-http tests.” Those messages apply to **core** groups, not this plugin. Guestbook cases extend `WP_Ajax_UnitTestCase` and ran (2 passed) because `phpunit.xml.dist` does not exclude the `ajax` group.

## Recommended Next Actions Before Next Release

1. Resolve or delete the local `svn-checkout/` conflicted tree before any SVN publish.
2. Run `./create-plugin-zip.sh` and Plugin Check the zip contents (not the git checkout) as the last WP.org gate.
3. Keep `WP_CORE_DIR` and `wgrsvp_phpunit` available on CI so `vendor/bin/phpunit --testdox` stays green.
4. Optionally run `phpcbf` on a dedicated branch if you want the 159 alignment warnings gone (large, noisy diff).
5. Review `npm audit` when next upgrading `@wordpress/scripts`.
6. Confirm guestbook captcha still works end-to-end after the ignore-only changes (enqueue URLs were not changed).

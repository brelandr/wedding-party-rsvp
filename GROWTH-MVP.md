# Growth roadmap — chosen themes & MVP (internal)

This document implements the actionable part of the product growth plan: **theme selection**, **MVP scope**, and **copy you can paste** on the LandTech sales page.

## 1. Themes chosen for this release

1. **Onboarding & perceived polish** — reduce abandonment with an in-dashboard **Getting started** checklist (links to Settings, Menu Options, import paths, setup wizard, Playground), a **Live demo** link on the plugin list, and a **Next steps: pending RSVPs** panel (per-user dismissible) with deep links to logistics, comms, and gifts.
2. **Straggler elimination** — **quick filters** on the Wedding Dashboard: no email, no phone, no mailing address, pending with no email/phone; exports honor the same filter.

## 2. MVP scope by theme

| Theme | Surface | Free vs Pro | New DB tables |
|-------|---------|-------------|---------------|
| Onboarding checklist + next steps | wp-admin → Wedding RSVP (guest list) | **Free** (Pro links use existing `wedding-rsvp-comm` when licensed) | No — uses `wgrsvp_getting_started_panel_dismissed` option and `wgrsvp_next_steps_notice_dismissed` user meta |
| Straggler filters | Same guest list (`wgrsvp_gap` query arg) | **Free** | No |
| Plugin row “Live demo” | Plugins screen | **Free** | No |

**Out of scope for this MVP:** client-facing read-only portal, Zapier webhooks, day-of QR mode, Google Sheets sync (future sprints).

## 3. LandTech sales page — problem → outcome → proof (paste-friendly)

Use these bullets beside your existing feature list:

- **Problem:** Couples and planners juggle spreadsheets, group chats, and last-minute “who never RSVP’d?” panic.
- **Outcome:** One guest ops hub on **your** WordPress site—Party IDs, meals, exports, reminders, and thank-you tracking without another SaaS login.
- **Proof:** Unlimited guests on the free core; optional Pro for batch email/SMS, seating, sub-events, and styling—see **Live demo** (Playground) from the plugin listing.

## 4. Code touchpoints

- [`includes/class-wgrsvp-growth-checklist.php`](includes/class-wgrsvp-growth-checklist.php) — checklist + next steps UI.
- [`wedding-party-rsvp.php`](wedding-party-rsvp.php) — straggler SQL helpers, guest list UI, export parity, dismiss handlers, logistics anchor for reminder settings.

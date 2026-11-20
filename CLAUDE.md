# TBT Points — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the task, not from a full-repository scan.
- The current release is specified in `docs/SPEC-0.1.5.md`. Follow it in order. Phase 0 is read-only and ends with a report; do not edit anything before Mariusz answers it.
- `docs/prototypes/*.html` are the approved designs. Where the spec and a prototype disagree, the spec wins; where the spec is silent, the prototype decides.
- Sibling code is the reference for patterns: `aparatofan/tbt-results` (access rules, assets, tokens, fonts, REST boot, deploy) and `aparatofan/tbt_notes` (the `tbt_notes_activity_recorded` action and access helpers).
- Do not change unrelated code, formatting, wording, or styling.

## Project basics

- WordPress 6.x plugin; PHP 8.0+. Pure PHP + vanilla JavaScript. No jQuery, build system, CDN, Composer, or npm dependency.
- Bootstrap: `tbt-points.php`. Server-side: `includes/`. Assets: `assets/`. Standalone tests: `tests/`.
- Prefixes: `TBTP_` (classes, constants), `tbtp_` (functions, options, filters), `tbtp-` (CSS), REST namespace `tbt-points/v1`. Never use unprefixed `.tbt-*` class names; TBT Hub claims them.
- `assets/css/tbt-tokens.css` is a vendored copy of Hub's tokens. Never edit a value in it. No stylesheet here defines a colour of its own.

## Architecture to preserve

- **Points owns one table, the ledger** (`{prefix}tbtp_ledger`). Balance is always `SUM(points)`; no cached balance. Rows are never edited or deleted: a correction is a new row.
- **Earning listens to Notes' `tbt_notes_activity_recorded` action, plus `tbt_points_record_event()` for trusted plugins whose events are not finished work (TBT Dictionary words).** Both use the same award path. Neither reads or writes Notes' activity table, and neither throws.
- **The award calculation is one pure function** (`TBTP_Rules::award()`), unit-tested. Change the tests with the rules.
- The unique key `(activity_id, kind)` guarantees an activity earns once.
- **Visibility as TBT Results:** admins see every student, teachers their own classes' students, students only themselves. Rules are admin-only. Enforced in REST, never only in the UI.
- Dates are stored in UTC; "today" is the site time zone.
- No page hero: Divi supplies the header. Tablet and desktop only (min 768 px).
- Points must not fatal when Notes is missing or older than 1.22.0.

## Security rules

- Every REST route has a real `permission_callback`; nonce `wp_rest`; no-cache headers on reads.
- `$wpdb->prepare()` for every query with a value; `$wpdb->esc_like()` for search.
- Escape all output. Never expose credentials or real configuration values.

## Validation

```bash
for f in tbt-points.php includes/*.php; do php -l "$f"; done
node --check assets/js/points.js
php tests/test-rules.php
```

State clearly what still needs a browser or live-site check.

## Git and deployment

- **Never merge a PR and never push to `main`.** A merge to `main` deploys to the live site; that is Mariusz's decision. Stop at an open PR and report.
- Use a focused feature branch. Keep commits task-focused.
- Every release bumps `TBTP_VERSION` and the plugin header version; the version is the cache buster.
- **Never rename `tbt-points.php` or the `/tbt-points/` server folder.** The FTP deploy uploads but never deletes.
- Markdown files, `.github/`, `docs/` and `tests/` are excluded from the upload.

## Context discipline

- Prefer targeted search and narrow reads over broad exploration.
- Summarise completion as: what changed, what was checked, and what still needs live-site verification.

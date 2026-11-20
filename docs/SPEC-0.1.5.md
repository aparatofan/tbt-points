# TBT Points 0.1.5 — Dictionary game rule

TBT Dictionary 0.2.0 reports each **finished game round** to the Notes activity log as tool `dictionary`, with a score (`score` / `score_max`) and `object_ref` = game type + the chosen groups. Mariusz's decision (20 Nov 2026): it earns through its own Rules line, **Dictionary game**, default **5**, plus the usual bonus at 80 %. Replaying the same groups earns Points' normal `repeat` rate.

Nothing else changes: no schema change (`TBTP_DB_VERSION` stays `1`), the Notes listener and `tbt_points_record_event()` are untouched. Without this release a `dictionary` row earns "Any other activity" (5) under the label of an unknown tool.

## Phase 0 — read-only (stop and report)

Confirm `main` is 0.1.4 with nothing else in flight and the tests pass. **Hard stop** if not.

## Tasks

1. `TBTP_Rules::tool_labels()` — add `'dictionary' => 'Dictionary game'` (before `dictionary_word`).
2. `TBTP_Rules::defaults()` — add `'dictionary' => 5`. Older saved rules get the default through `merge()`.
3. `points.js` — `dictionary` in `TOOL_ORDER` just before `dictionary_word`; note "A finished game round in My dictionary".
4. Tests: `dictionary` uses its own rule (5) and gets the bonus at 80 %+; older saved rules keep their numbers.
5. `TBTP_VERSION` and header → `0.1.5`; CLAUDE.md "Start here" → `docs/SPEC-0.1.5.md`.

## Validation

```bash
for f in tbt-points.php includes/*.php; do php -l "$f"; done
node --check assets/js/points.js
php tests/test-rules.php
```

## Acceptance (live)

- [ ] Rules: a **Dictionary game** row (5) above **Dictionary: new word** (1); change and save: it stays.
- [ ] (With Dictionary 0.2.0) a finished round of 4/5 shows **Dictionary game** in My points: 5 + 5 bonus (80 %).
- [ ] Hard refresh shows `?ver=0.1.5`.

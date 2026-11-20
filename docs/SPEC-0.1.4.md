# TBT Points 0.1.4 — Dictionary words: own rule and a direct way in

TBT Dictionary 0.1.0 gives a point for every new word a student adds. Mariusz wants these words ONLY in Points: not in TBT Results and not in the Notes Class Progress panel. Both read the Notes activity log, so a word must NOT go through Notes. This release adds (1) a "Dictionary: new word" rule (default 1) on its own line in Rules, and (2) a PHP function trusted plugins call to earn for an event that is not finished work, using the same rules as everything else.

No schema change: `TBTP_DB_VERSION` stays 1 (the ledger already allows a NULL `activity_id`, as adjustments do). Notes-based earning does not change. Deploy before or together with Dictionary 0.1.0.

## Phase 0 — read-only (stop and report)

Confirm on main: `TBTP_VERSION` is 0.1.3 and nothing else is in flight; `TBTP_Earn::handle()` is as described below; `TBTP_DB::insert()` leaves `activity_id` NULL when not given; existing tests pass. Hard stop if anything differs.

## Tasks

1. **Rule.** `TBTP_Rules::tool_labels()` — add `'dictionary_word' => 'Dictionary: new word'`. `TBTP_Rules::defaults()` — add `'dictionary_word' => 1`. Rules saved before 0.1.4 lack the key; `merge()` fills in the default.
2. **Share the award path.** Move the body of `TBTP_Earn::handle()` after its Notes-specific guards into one private method used by both entry points, so the checks stay in one place: managers never earn (`TBT_Notes_Capabilities::user_can_manage`, behind `TBTP_Access::notes_ready()`), table exists, start date, first/repeat, day bonus, `daily_cap`, ledger inserts. The Notes listener's behaviour must not change.
3. **Direct entry point**, in `tbt-points.php`:

   ```php
   /**
    * Earn for an event that is not a Notes activity (e.g. a Dictionary word).
    * Trusted PHP callers only: never pass a user_id taken from a browser.
    *
    * @param array $args user_id, tool, object_ref (required); object_title.
    * @return int Points added (earn + day bonus), 0 when nothing was earned.
    */
   function tbt_points_record_event( array $args ): int
   ```

   - Same rules as a Notes row with no score (no 80 % bonus).
   - Once per (`user_id`, `tool`, `object_ref`) EVER: if an earn row exists for it, return 0. No repeat points for direct events. (Dictionary sends `object_ref = 'word:' . md5( term_key )`, so delete + re-add earns nothing.)
   - Ledger rows: kind `earn` (and `day` when due), `activity_id` NULL, `tool`, `object_ref`, `title` = `object_title`, `created_at` now (UTC).
   - Never throws: any `\Throwable` → 0. Returns 0 when Points is not ready (no table, Notes missing, before the start date).
4. **Rules page.** `points.js` — `dictionary_word` last in `TOOL_ORDER`; note "Each new word in My dictionary; once per word".
5. **CLAUDE.md** — in Architecture to preserve, change the earning line to: "Earning listens to Notes' `tbt_notes_activity_recorded` action, plus `tbt_points_record_event()` for trusted plugins whose events are not finished work (TBT Dictionary words). Both use the same award path. Neither reads or writes Notes' activity table, and neither throws." Point "Start here" at `docs/SPEC-0.1.4.md` and save this spec there.
6. **Tests:** `dictionary_word` uses its own rule (1); no bonus; old saved rules keep their numbers and get the default; if the shared path has a pure part, test that a direct event earns once and never repeat.
7. `TBTP_VERSION` and header → 0.1.4.

No lock on the "once ever" check (decided 20 Nov 2026): Dictionary's own duplicate check on the word covers the race.

## Validation

```bash
for f in tbt-points.php includes/*.php; do php -l "$f"; done
node --check assets/js/points.js
php tests/test-rules.php
```

## Acceptance (live)

- [ ] Rules: a "Dictionary: new word" row, value 1; change, Save, reload: it stays. Other saved numbers unchanged.
- [ ] A Swipe deck still earns exactly as before (+5, and +2 for the first activity of the day).
- [ ] (With Dictionary 0.1.0 live) adding a word shows "Dictionary: new word" in My points; the first word of the day also gives the day bonus.
- [ ] That word appears nowhere in TBT Results or the Class Progress panel.
- [ ] Hard refresh shows `?ver=0.1.4`.

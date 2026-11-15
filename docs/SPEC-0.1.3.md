# TBT Points 0.1.3 — Poll gets its own rule, and a vote can ask what it earned

TBT Poll 0.1.0 (`aparatofan/tbt-poll`) reports each vote to the Notes activity log as tool `poll` (no score, once per poll per student, because a vote is final). Without this release those rows earn the “Any other activity” rate. Mariusz wants polls on their own line in Rules, at 3 points to start.

Right after voting, the poll tells the student what the vote earned. It needs one read-only helper for that, because the real number can differ from the rule (start date, daily cap, the day bonus).

No schema change: `TBTP_DB_VERSION` stays `1`. Earning, REST shapes and pages do not change.

## Phase 0 — read-only (stop and report)

1. Confirm `TBTP_VERSION` is `0.1.2` on main.
2. Confirm `TBTP_DB::EARNED_KINDS` is `earn`, `repeat`, `day`, and that every row of those kinds written by `TBTP_Earn` carries the Notes `activity_id` that caused it (the `day` bonus included).
3. Confirm Earn runs synchronously inside `tbt_notes_record_activity()`, so its ledger rows exist by the time that call returns.

**Hard stop** if any of these is false. Otherwise report and wait for go.

## Tasks

1. `TBTP_Rules::tool_labels()` — add `'poll' => 'Poll'` after `lesson`.
2. `TBTP_Rules::defaults()` — add `'poll' => 3`. Rules saved before 0.1.3 have no `poll` key; `merge()` fills in the default.
3. `points.js` — `poll` last in `TOOL_ORDER`; note “When a student votes; once per poll”.
4. `TBTP_DB::points_for_activity( $activity_id )` — `SUM(points)` of rows with that `activity_id` and a kind in `EARNED_KINDS`; 0 when there are none or the table is missing. `$wpdb->prepare()`.
5. Public wrapper in `tbt-points.php`:

   ```php
   function tbtp_points_for_activity( $activity_id ) // int, never throws, 0 on any failure
   ```

   Read-only. It is the only thing TBT Poll calls in Points, through `function_exists()`.
6. Tests in `tests/test-rules.php`: poll uses its own rule; no score, no bonus; old saved rules keep their numbers and get the poll default (3).
7. `TBTP_VERSION` and header → `0.1.3`.

## Validation

```bash
for f in tbt-points.php includes/*.php; do php -l "$f"; done
node --check assets/js/points.js
php tests/test-rules.php
```

## Acceptance (live)

- [ ] Points → Rules: a **Poll** row after Lesson, value 3; change it, Save, reload: the new value stays.
- [ ] The other numbers saved earlier are unchanged.
- [ ] After TBT Poll 0.1.0 is live: a student votes and the poll says “You earned 3 points.” (more on their first activity of the day, when the day bonus applies); My points shows a **Poll** row with the poll title.
- [ ] Hard refresh shows `?ver=0.1.3`.

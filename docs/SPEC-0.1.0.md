# TBT Points 0.1.0 — Earning points

Students earn points automatically for the work they finish on The Blue Tree. They see their balance and history on **My points**; the teacher sees balances, gives or takes points with a reason, and (admin only) sets the rules.

Design: `docs/prototypes/points.html` (approved 14 Nov 2026). Where this spec and the prototype disagree, the spec wins; where the spec is silent, the prototype decides.

## Releases

| Release | Contents |
|---|---|
| **0.1.0 (this spec)** | Ledger, rules, automatic earning, My points (balance + history), teacher **Students** and **Rules** tabs |
| 0.2.0 (next spec) | Rewards catalogue (with **photos** of the real items, chosen from the Media Library), requests, hold → hand over / decline, stock, the **Requests** and **Rewards** tabs, the Rewards and My requests sections on My points |

Points earned under 0.1.0 are real and carry into 0.2.0. Nothing in 0.1.0 may assume rewards don't exist: the ledger's `kind` already reserves `hold`, `spend` and `release`.

## Source of the events

TBT Notes 1.22.0+ fires, after every recorded activity row:

```php
do_action( 'tbt_notes_activity_recorded', int $id, array $row );
```

`$row` has `user_id, class_id, teacher_id, student_name, tool, object_ref, object_title, post_id, score, score_max, duration_seconds, created_at` (UTC). Every tool reports through it: `test`, `homework`, `swipe`, `matching`, `dragdrop`, `drill`, `fill`. Points listens to it and never reads or writes Notes' table directly for earning.

## Files

```
tbt-points.php
includes/class-tbtp-db.php       the ledger table, every query
includes/class-tbtp-rules.php    stored rules + the pure award calculation (unit-tested)
includes/class-tbtp-earn.php     the Notes listener
includes/class-tbtp-access.php   who sees which students (copy TBT Results' TBTR_Access pattern)
includes/class-tbtp-rest.php
includes/class-tbtp-pages.php    [tbt_my_points], [tbt_points], assets
assets/css/tbt-tokens.css, assets/css/fonts.css, assets/fonts/   as TBT Results
assets/css/points.css, assets/js/points.js
tests/test-rules.php
.github/workflows/deploy.yml     copy TBT Results'; secrets FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, FTP_SERVER_DIR are set
CLAUDE.md                        already in the repo
```

Prefixes: `TBTP_` / `tbtp_` / `tbtp-`, REST `tbt-points/v1`, text domain `tbt-points`.

---

## Phase 0 — read-only inventory (stop and report)

1. **Notes.** In `aparatofan/tbt_notes` (main): confirm the action's name, arguments and that it fires for **both** the REST route and `tbt_notes_record_activity()`; whether it fires inside the request that recorded the row (so a slow listener slows the student's request); and the helpers TBT Results uses for classes and students.
2. **TBT Results as the pattern.** In `aparatofan/tbt-results`: `TBTR_Access`, assets/tokens/fonts, REST boot and nonce refresh, the login card, the Notes version check, the Hub item, the deploy workflow (including the `allow_create` first deploy).
3. **Scores in rows.** Confirm which tools send `score`/`score_max` (expected: `test`, `dragdrop`, `homework`; `fill` sends `score` only — game points, no maximum; the rest send neither).
4. **Time zone.** How Results computes "today" in the site time zone; Points uses the same.

**Hard stop** if anything contradicts this spec. Otherwise end with the report and wait for Mariusz to say go.

---

## Task 1 — The ledger (`class-tbtp-db.php`)

Table `{prefix}tbtp_ledger`, created by `dbDelta` on activation and from a `plugins_loaded` upgrade check (FTP deploys never fire activation). `TBTP_DB_VERSION` = `1`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned PK | |
| `user_id` | bigint unsigned | the student |
| `points` | int (signed) | + earned, − taken/spent |
| `kind` | varchar(16) | `earn`, `day`, `adjust` now; `hold`, `spend`, `release` reserved for 0.2.0 |
| `activity_id` | bigint unsigned NULL | Notes activity row ID for `earn` and `day` |
| `tool` | varchar(32) | for `earn`; '' otherwise |
| `object_ref` | varchar(64) | for `earn`; '' otherwise |
| `title` | varchar(190) | what the student sees, e.g. the activity title |
| `detail` | varchar(190) | e.g. `18 / 20`, `340 game points`, `again`, a teacher's reason |
| `created_by` | bigint unsigned | 0 = automatic; the teacher's ID for `adjust` |
| `created_at` | datetime | UTC |

Keys: `user_created (user_id, created_at)`, `user_activity (user_id, tool, object_ref)`, **unique** `activity_kind (activity_id, kind)` (MySQL allows many NULLs, so adjustments are unaffected; the same activity can never earn twice).

Balance = `SUM(points)` for the user. No cached balance.

## Task 2 — Rules (`class-tbtp-rules.php`)

Stored in option `tbtp_rules` (autoload). Defaults (the prototype's numbers):

```
tools:      test 10, homework 15, swipe 5, matching 5, dragdrop 5, drill 5, fill 5
unknown:    5            (a tool slug not in the list)
bonus:      5 points at 80 % or more   (only rows with score and score_max > 0)
repeat:     1 point, at most once a day per activity
day:        2 points for the first activity of the day
daily_cap:  50           (earn + day only; teachers' adjustments don't count)
start:      the date the plugin is first activated (site time zone)
```

`TBTP_Rules::get()` merges stored values over defaults; `save()` validates: every number an integer 0–1000, `bonus_at` 1–100, `start` a real `Y-m-d`.

**The calculation is one pure static method**, no WordPress, unit-tested:

```php
TBTP_Rules::award( array $rules, array $in ): array
// $in:  tool, percent (int|null), is_first (bool), repeat_today (bool),
//       day_given (bool), earned_today (int)
// out:  [ 'earn' => int, 'earn_detail' => string, 'day' => int ]
```

1. `earn`: first time → tool points (+ bonus when `percent` ≥ `bonus_at`); a repeat → `repeat` points if `repeat_today` is false, else 0.
2. `day`: `rules.day` if `day_given` is false, else 0.
3. Cap: `room = max(0, daily_cap − earned_today)`; `earn = min(earn, room)`, then `day = min(day, room − earn)`.
4. `earn_detail`: `again` for a repeat; otherwise empty (the listener adds the score text).

## Task 3 — Earning (`class-tbtp-earn.php`)

On `tbt_notes_activity_recorded( $id, $row )`:

1. Ignore when: the user is a teacher/admin (`TBT_Notes_Capabilities::user_can_manage()`), or the row's `created_at` is before `start` (midnight, site time zone), or Points' table is missing.
2. Work out the inputs from the ledger (site-time-zone "today"):
   - `is_first`: no earlier `earn` row for this user + tool + object_ref.
   - `repeat_today`: an `earn` row for this activity today already exists.
   - `day_given`: a `day` row for this user today exists.
   - `earned_today`: sum of today's `earn` + `day` points for this user.
   - `percent`: `round(score / score_max * 100)` when both set and `score_max` > 0, else null.
3. `TBTP_Rules::award()`.
4. Insert an `earn` row when `earn` > 0, and a `day` row when `day` > 0, both with `activity_id` = `$id`. `title` = `object_title`. `detail`: `18 / 20` for scored rows, `340 game points` for `fill`, `again` for repeats (`again · 18 / 20` when both).
5. A duplicate-key error (same activity twice) is ignored silently. **Any failure is swallowed**: earning must never break the student's request in Notes.

## Task 4 — Who sees what (`class-tbtp-access.php`)

Same rules as TBT Results (copy its approach): admin sees every student, including students in no class who have ledger rows; a teacher sees students in their own classes; a student sees only themselves. **Rules are admin-only** (`user_can_manage_all()`); teachers (e.g. Madzia) can view balances and give or take points for their own students.

## Task 5 — REST (`class-tbtp-rest.php`)

Real `permission_callback`s, nonce `wp_rest`, no-cache headers.

| Route | Who | Does |
|---|---|---|
| `GET /me` | logged-in student | `balance`, `week` (earned in the last 7 days), `history` (50 per page, newest first), `has_more` |
| `GET /teacher/students` | teacher | per visible student: `id, name, class_title, balance, earned_30, last_earned`; stats: `earned_30` total, `earning` "N of M" |
| `GET /teacher/student/{id}` | teacher, student visible | name, balance, history (paged) |
| `POST /teacher/adjust` | teacher, student visible | `user_id`, `points` (integer −1000…1000, not 0), `reason` (required, 3–190 chars) → `adjust` row, `created_by` = teacher. Returns the new balance |
| `GET`/`POST /rules` | admin | read / save rules (Task 2 validation; field errors returned per field) |

History item: `{ when (ISO, site TZ), kind, label, title, detail, points }`, where `label` is the tool label for `earn` (Test, Homework, Swipe, Matching Game, Drag & Drop, Translation Drill, Fill), "Bonus" for `day` (title "First activity today"), "From your teacher" for `adjust` (title = the reason).

## Task 6 — Pages (`class-tbtp-pages.php`, `points.js`, `points.css`)

From the prototype. **No hero** (Divi supplies it). Tablet and desktop only (min 768 px). Tokens only, no colour values of our own.

**Student `[tbt_my_points]`** (Learning Tools menu): Admin Bar "My points"; tiles **points to spend** (big blue tile), **earned this week**; the **History** table (When · label pill · title and detail · ±points, green/red), "Show more". The Rewards and My requests sections and the "held" tile come in 0.2.0 — leave them out, no placeholder. Logged out: the login card. A teacher opening it: one line, "This page shows a student's own points."

**Teacher `[tbt_points]`** (Teacher Tools): Admin Bar "Points" with tabs **Students** and (admin only) **Rules**. Requests and Rewards tabs arrive in 0.2.0.
- Students: tiles (points earned in the last 30 days, students earning N of M), table (Student · Class · Balance · Last 30 days · Last earned · "Give or take points"). The button opens the inline row from the prototype: points, reason, Save, Cancel. Clicking a name shows that student's history under the table (or as a view with "← All students", as in Results).
- Rules: the prototype's form, all fields, "Save rules", inline errors, a short "Saved" confirmation.

Non-teacher: "This page is for teachers." Logged out: login card.

## Task 7 — Bootstrap

`tbt-points.php`: header `Version: 0.1.0`, `Requires PHP: 8.0`; constants `TBTP_VERSION`, `TBTP_DB_VERSION`, `TBTP_FILE/DIR/URL`. Activation sets `start` to today if no rules are stored yet. Notes check as Results (Notes ≥ 1.22.0); without Notes the pages show one line and the routes answer 503, nothing fatals. Hub item: slug `tbt-points`, "TBT Points", "Students earn points for their work and exchange them for rewards.", capability `TBT_NOTES_CAP` or `manage_options`.

## Task 8 — Tests (`tests/test-rules.php`)

Pure PHP: first time with and without bonus (79 % / 80 %); unscored tool (no bonus); unknown tool; repeat once a day then 0; day bonus once; cap cutting `earn`, then `day`, then both to 0; a cap already reached; `fill` (score without max: no bonus).

## Validation

```bash
for f in tbt-points.php includes/*.php; do php -l "$f"; done
node --check assets/js/points.js
php tests/test-rules.php
```

---

## Acceptance checklist (live, after merge and first deploy with `allow_create`)

Mariusz creates **My points** (Learning Tools, `[tbt_my_points]`) and **Points** (Teacher Tools, `[tbt_points]`). On Rules, set the start date to today.

- [ ] As the test student, finish a Swipe deck: My points shows +5 (Swipe) and +2 (Bonus · First activity today).
- [ ] Finish the same deck again: +1 "again". A third time today: nothing.
- [ ] Finish a test with 80 % or more: test points + 5. Under 80 %: test points only.
- [ ] Finish a Fill game: +5, detail "340 game points" (whatever the score was).
- [ ] Score a homework 8 / 10: +15 + 5 for the student.
- [ ] Reach 50 in a day: further work that day adds nothing; tomorrow it starts again.
- [ ] Teacher: give +20 with a reason; the student sees "From your teacher · reason · +20". Take −10: balance goes down.
- [ ] Madzia sees only her students and no Rules tab.
- [ ] Change a rule (Swipe 5 → 3), save, finish a new deck: +3.
- [ ] Work finished before the start date earns nothing.
- [ ] Hard refresh shows `?ver=0.1.0`.

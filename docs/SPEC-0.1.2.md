# TBT Points 0.1.2 — Lesson (Learning History) gets its own rule

Learning History 1.5.0 reports each newly completed lesson to the Notes activity log as tool `lesson` (no score, once per lesson per student). Without this release those rows earn the "Any other activity" rate. Mariusz wants lessons on their own line in Rules so he can set the value himself.

No schema change: `TBTP_DB_VERSION` stays `1`. Earning, REST shapes and pages do not change.

## Tasks

1. `TBTP_Rules::tool_labels()` — add `'lesson' => 'Lesson'`.
2. `TBTP_Rules::defaults()` — add `'lesson' => 5` (placeholder, same as "Any other activity", so nothing changes until Mariusz sets it). Rules saved before 0.1.2 have no `lesson` key; `merge()` fills in the default.
3. `points.js` — `lesson` last in `TOOL_ORDER`; note "Added to Learning History; once per lesson".
4. Tests: lesson uses its own rule; no bonus; old saved rules keep their numbers and get the lesson default.
5. `TBTP_VERSION` and header → `0.1.2`.

## Acceptance (live)

- [ ] Points → Rules: a **Lesson** row after Fill, value 5; change it, Save, reload: the new value stays.
- [ ] The other numbers saved earlier are unchanged.
- [ ] A student adds a new lesson to their history: My points shows a **Lesson** row with the lesson title, worth the Rules value.
- [ ] Hard refresh shows `?ver=0.1.2`.

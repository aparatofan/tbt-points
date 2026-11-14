# TBT Points 0.1.1 — Find a student; headings in sentence case

Two small changes to the teacher's **Points** page, after 0.1.0 went live (14 Nov 2026):

1. **Search.** A teacher can type a student's name and see only that student (and similar names), then open their points history.
2. **Headings.** On the live site Divi turns "Points" and "Students" into capitals ("POINTS", "STUDENTS"). The approved design is sentence case.

Student page, earning, rules and the REST data shapes do not change. No schema change: `TBTP_DB_VERSION` stays `1`.

## Phase 0 — read-only inventory (stop and report)

1. **Student list.** Confirm `GET /teacher/students` returns every visible student in one response (no paging), so the search can filter in the browser. Report the count on the live site if known (the screenshot shows 66).
2. **Admin Bar.** How `points.js` draws the bar (title, line, tabs) and how TBT Results draws its search (`aparatofan/tbt-results`: markup, classes, icon, clear button, 300 ms wait, URL parameter). Points copies Results' search.
3. **Divi capitals.** Why `.tbtp h2` loses to Divi (Divi sets heading `text-transform` from ID-scoped selectors). Report how TBT Homework pins heading type on its app's own ID (`#tbth-homework-student …` in `tbt-homework-library.css`) and whether TBT Results has the same problem and fix.

**Hard stop** if anything contradicts this spec. Otherwise end with the report and wait for Mariusz to say go.

---

## Task 1 — Search on the Students tab (`points.js`, `points.css`)

- The Admin Bar gets a search box between the line and the tabs, exactly like TBT Results' (same size, icon, × clear, placeholder **"Search a student…"**). It shows on the Students tab only (not on Rules, not on one student's history).
- Typing filters the table as you type (no server call; the list is already loaded). It matches the student's **name** and **class title**, ignoring case and Polish accents (`Mędykowska` = `medykowska`, `ł` = `l`).
- The stat tiles above the table keep showing everyone (they are totals, not search results).
- Under the table title, a small count: "3 of 66 students" while searching; nothing when the box is empty.
- No match: "No student matches "…"." with the clear button still working.
- The search text is kept in the URL (`tbtp_q`), so "← All students" from a student's history brings the teacher back to the same filtered list, and a refresh keeps it.
- Enter in the box with exactly one match opens that student's history.

## Task 2 — Headings in sentence case (`points.css`, `class-tbtp-pages.php`)

- Give each app wrapper an ID (`tbtp-app-teacher`, `tbtp-app-student`) in `class-tbtp-pages.php`.
- Pin heading type on those IDs, as TBT Homework does: `text-transform: none`, the design's letter-spacing, for the Admin Bar title, the stage titles and the student-history heading. Both pages.
- Do not use `!important` unless Phase 0 shows an ID selector cannot win; if so, say so first.

## Task 3 — Version

`TBTP_VERSION` and header → `0.1.1`.

## Validation

As in `CLAUDE.md`.

---

## Acceptance checklist (live)

- [ ] Points → Students: the bar reads "Points" and the card "Students", not in capitals. My points: "My points", "History", not in capitals.
- [ ] Type "medyk": Ania Mędykowska appears; "3 of 66 students"-style count shows the match count.
- [ ] Type a class name: that class's students appear.
- [ ] Clear (×): the full list is back, the count disappears.
- [ ] Search, open a student, press "← All students": the same search and list are back.
- [ ] Rules tab: no search box.
- [ ] Hard refresh shows `?ver=0.1.1`.

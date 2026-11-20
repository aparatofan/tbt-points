# TBT Points 0.1.4 — Dictionary words get their own rule

TBT Dictionary 0.1.0 reports each new word a student adds to the Notes activity log as tool `dictionary_word` (no score, one row per word, `object_ref` `word:{id}`). Without this release each word earns the "Any other activity" rate (5). Mariusz wants **1 point per word**, on its own line in Rules so he can change it.

No schema change: `TBTP_DB_VERSION` stays `1`. Earning, REST shapes and pages do not change. Deploy this **before or together with** TBT Dictionary 0.1.0.

## Tasks

1. `TBTP_Rules::tool_labels()` — add `'dictionary_word' => 'Dictionary: new word'`.
2. `TBTP_Rules::defaults()` — add `'dictionary_word' => 1`. Rules saved before 0.1.4 have no such key; `merge()` fills in the default.
3. `points.js` — `dictionary_word` last in `TOOL_ORDER`; note "Each new word in My dictionary; once per word".
4. Tests: `dictionary_word` uses its own rule (1); no bonus (no score); old saved rules keep their numbers and get the default.
5. `TBTP_VERSION` and header → `0.1.4`.

## Acceptance (live)

- [ ] Points → Rules: a **Dictionary: new word** row, value 1; change it, Save, reload: the new value stays.
- [ ] The other numbers saved earlier are unchanged.
- [ ] (With Dictionary 0.1.0 live) the test student adds a word: My points shows a **Dictionary: new word** row with the word, worth the Rules value.
- [ ] Hard refresh shows `?ver=0.1.4`.

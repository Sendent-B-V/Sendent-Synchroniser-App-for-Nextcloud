# Per-event change feed rows: task queue

## Branch workflow

The integration branch is `feat/notification`. Every PR targets it, not main. Task branches are cut from it and named `task/<id-slug>`. One task id per PR where possible. Tasks in the sync service repo are tracked in this file too, so there is one queue.

## How to use this file

- Status is TODO, WIP, DONE or BLOCKED. WIP carries the name of whoever holds it.
- Est is in days.
- After lists the ids that must be DONE first. When you finish a task, take the first TODO whose After column is all DONE.
- Notes carries the tags, the open questions the task depends on, and a short done note once finished: date, PR number, and anything the next person needs.
- `solo` marks a task that edits shared files. Never run two solo tasks at once. Check the WIP rows before picking one.
- `safe` marks a task with no shared files. Always a good pick while someone holds a solo task.
- `all` marks a decision or contract everyone agrees before anyone writes code against it.
- "needs Qn" means the task changes with the answer to that question in CONTRACTS.md. The task holds whichever way it is answered; only its content shifts.
- Status flips are committed straight to the integration branch, alone, with the message "TODO: <id> <status>". A change to what a task means, or to CONTRACTS.md, goes through a PR.
- Task ids appear in branch names, commit messages and this file only. Not in code comments.

Shared files for the solo tag: `appinfo/info.xml`, `lib/AppInfo/Application.php`, `lib/Listener/DavChangeListener.php`, `lib/Service/ChangeNotification/ChangeLedgerService.php`, `lib/Controller/ChangeFeedApiController.php`, `lib/Cron/ChangeNotificationMaintenance.php`.

Ids N1 to N5 are taken by the Graph sign-in feature in this repo, so this feature starts at N6.

## Phase 0: decisions and contracts

All of Phase 0 is one PR, the decision PR. Nothing below starts until the rows in its After column are DONE.

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| DONE | C1 | Settle Q1: build per-event rows, or token read on the existing row. | 0 | | all. Settled 16 November 2026: per-event rows are built. |
| DONE | C2 | Settle Q2: can the sync service process a row for an event created in Nextcloud. | 0 | | all. Settled 18 November 2026: Artem says the file name is enough. |
| DONE | C3 | Settle Q3: UID in the row or not. | 0 | C1 | all. Settled 18 November 2026: no UID in the row. Changed 26 November 2026: the UID goes in as an optional field, task N9.3. |
| DONE | C4 | Settle Q4: what is sent when an event moves out of the synced calendar. | 0 | C1 | all. Settled 19 November 2026: sent as deleted, as Artem asked. |
| DONE | C5 | Settle Q5: restore sent as created or as its own kind. | 0 | C1 | all. Settled 18 November 2026: sent as created. |
| DONE | C6 | Settle Q6: retention period and the too-old cursor. | 0 | C1 | all. Settled 23 November 2026: 3 days, no signal for a too-old cursor, agreed by Maarten |
| DONE | C7 | Settle Q7: explicit row for a deleted calendar or not. | 0 | C1 | all. Settled 18 November 2026: no extra row. |
| DONE | C8 | Finish and agree contract C8, the row shape. | 0.5 | C3, C5 | all. Agreed by Maarten 23 November 2026 |
| DONE | C9 | Finish and agree contract C9, which changes produce a row. | 0.25 | C4, C7 | all. Changed 19 November 2026: every calendar of a sync user gets rows; the sync service filters. Agreed by Maarten 23 November 2026 |
| DONE | C10 | Write and agree contract C10, retention and the too-old cursor. | 0.25 | C6 | all. Agreed by Maarten 23 November 2026 |

## Phase 1: storage

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| DONE | N6.1 | Migration for a separate append-only table of per-event rows, with the indexes the read and the cleanup need. Why: per-calendar rows are overwritten in place, per-event rows must not be. Files: new migration next to `lib/Migration/Version000004Date20260820.php`, `appinfo/info.xml`. | 0.5 | C8 | solo. Must run on MySQL, MariaDB and Postgres. Done 23 November 2026, pull request 46. Table `sndntsync_evt_changes`. The version in `appinfo/info.xml` is not changed yet; existing installs need that before release. |
| DONE | N6.2 | Entity and mapper for the new table: insert, read a page by sequence, highest sequence, delete older than a time, delete for one user. Files: new classes in `lib/Db/`, modelled on `DirtyCollection` and `DirtyCollectionMapper`. Unit tests included. | 0.5 | N6.1 | safe. Done 24 November 2026, pull request 47. A second migration makes the index on the sequence number unique, so an insert with a number already in use fails. The unit tests use a table in memory; the queries on the real databases are for the integration test. |

## Phase 2: write path

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| DONE | N7.1 | Turn each of the six event-level changes into the file name and kind for a row, following contract C9. Covers the trash name, the silent purge, the restore and the move. Why: the listener reads only the calendar today. Files: `lib/Service/ChangeNotification/DavEventReferenceExtractor.php`, `lib/ChangeNotification/`. Unit tests included. | 1 | C8, C9 | safe. Done 23 November 2026, pull request 45. The listener task must keep subscription events away from it and decide on a delete in a federated calendar. |
| DONE | N7.2 | Decide per change whether the event is in the user's synced calendar. Why: scope is checked per user today, not per calendar. Files: `lib/Service/ChangeNotification/SyncScope.php`, `lib/Service/SyncUserService.php` for reading the setting. Unit tests included. | 0 | C9 | Dropped 23 November 2026, nothing merged: pull request 44 closed. The app does not filter by calendar; Artem confirmed that the sync side filters the rows on its own list. |
| DONE | N7.3 | Write the per-event row together with the per-calendar row, inside the same savepoint, with failures swallowed and logged as today. Why: a feed failure must never break the user's save. Files: `lib/Listener/DavChangeListener.php`, `lib/Service/ChangeNotification/ChangeLedgerService.php`. | 1 | N6.2, N7.1 | solo. Writes rows for every calendar of a sync user; no check for the synced calendar. Done 25 November 2026, pull request 48. Known limit: a delete in a federated calendar can give a trash event for a user calendar with the same id. |
| DONE | N7.4 | Extend the re-stamp after commit to per-event rows. Why: a row written before the commit can otherwise sit below a cursor a reader already passed. Files: `lib/Service/ChangeNotification/ChangeLedgerService.php`, `lib/Service/ChangeNotification/PostCommitQueue.php`. | 0.5 | N7.3 | solo. Decided 24 November 2026: a row that was passed is not changed; a second row with a new number is added. The counter must also look at the per-event table. Done 25 November 2026, pull request 49. The counter and the cursor limit read the two tables. When a read has passed both rows of a change, the second per-event row gets the higher number. |

## Phase 3: read path

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| DONE | N8.1 | Return per-event rows from `/notify/changes` in one sequence order with the per-calendar rows, under the existing fence and page limit, with one cursor. Files: `lib/Controller/ChangeFeedApiController.php`, the mapper from N6.2. Unit tests included. | 1 | N6.2, C8 | solo. Can be built against rows inserted by a test, in parallel with Phase 2. The cursor limit already reads the two tables (N7.4); do not add that again. Done 25 November 2026, pull request 50. |
| DONE | N8.2 | Answer a cursor that is older than the oldest kept row as contract C10 says. Needs a mark of the highest removed sequence that survives the cleanup. Files: `lib/Service/ChangeNotification/CursorService.php`, `lib/Controller/ChangeFeedApiController.php`. | 0 | N8.1, N9.1, C10 | Dropped 23 November 2026, nothing built: Q6 is settled with no signal for a too-old cursor. |

## Phase 4: housekeeping

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| DONE | N9.1 | Remove per-event rows older than the retention period in the maintenance job. Files: `lib/Cron/ChangeNotificationMaintenance.php`, `lib/Constants.php`. | 0.5 | N6.2, C10 | solo. The period is a fixed constant, not a setting (C10). Done 25 November 2026, pull request 51. |
| WIP Kleon | N9.2 | Remove a user's per-event rows when the user is deleted or leaves the active group. Why: the rows carry file names and would otherwise outlive the user until retention. Files: `lib/AppInfo/Application.php`, a listener in `lib/Listener/`. | 0.5 | N6.2 | solo. |
| TODO | N9.3 | Add the event UID to the per-event row as the optional field `i` (contract C8, Q3). Why: the sync team asked for it for callbacks on their side; agreed 26 November 2026. Covers: a new migration with a column that can be empty, the UID from each of the six event kinds, one read of the UID from the database for a restore, the write path, and the read endpoint, which leaves the field out when the row has no UID. Files: `lib/Migration/`, `lib/Db/EventChangeRow.php`, `lib/Db/EventChangeRowMapper.php`, `lib/ChangeNotification/EventChange.php`, `lib/Service/ChangeNotification/DavEventReferenceExtractor.php`, `lib/Service/ChangeNotification/ChangeLedgerService.php`, `lib/Controller/ChangeFeedApiController.php`. Unit tests included. | 1 | N8.1, C8 | solo. The last implementation task. A failed read of the UID must not stop the row or the user's save. Rows written before the migration have no UID. The field name `i` is not confirmed by Artem yet. Not verified: that the UID is in the event data of all five other kinds on Nextcloud 32 and 33. |

## Phase 5: tests, docs and gate

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| TODO | N10.1 | Integration test on the CI matrix: each row of the table in contract C9 produces exactly the row it names, on MariaDB and Postgres. Files: `tests/`, `.github/workflows/cn-integration.yml`. | 1 | N7.4, N8.1, N9.3 | safe. Includes the UID field. |
| TODO | N10.2 | Concurrency test: two writers and one reader, no row is skipped or seen out of order across the fence. | 1 | N7.4, N8.1 | safe. |
| TODO | N10.3 | Update the feed docs and the reference client for the new row. Files: `docs/change-notifications.md`, `docs/connector-change-feed-contract.md`, `docs/connector-reference/SendentChangeFeedClient.cs`. | 0.5 | C8, C9, C10, N9.3 | safe. Includes the UID field. |
| TODO | S1 | Sync service: confirm that no change is needed to process per-event rows, or fix the lookup for events created in Nextcloud. | 0 | C2, C8 | Other repo. Artem says the file name is enough; G1 confirms it with an event created in Nextcloud. |
| TODO | G1 | Gate on the dev instance. Pass: an event created, changed and deleted in the synced calendar in Nextcloud reaches the other side through the feed without waiting for the timer sync, and a change in a calendar the sync service does not sync produces a row and changes nothing on the other side. Once a user can have a second synced calendar: a change there lands in the right calendar on the other side. | 0.5 | N10.1, N9.1, N9.2, S1 | Owed by everyone. needs Q2. |

## Cross-cutting follow-ups

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| TODO | X1 | Run the event probe on Nextcloud 33. | 0.25 | | safe. 32 and 35 were run, 34 was read. |
| DONE | X2 | Check in the Calendar app: file name on a move between calendars, and what "this and future events" produces. | 0.25 | | safe. Feeds Q4. Done 18 November 2026: a move keeps the file name and UID. "This and all future" leaves the original with its name and UID and adds a second event with a new name and a new UID. Only a title change was tested. |
| DONE | X3 | Run a change made by a sharee in a shared calendar and check which principal and calendar URI the event carries. | 0.25 | | safe. Feeds contract C9 and N7.2. Done 18 November 2026: the feed row carries the owner's principal and the calendar's own URI for a create, change and delete by the sharee. |
| TODO | X4 | The trash scrub listener uses `personal` and ignores the synced-calendar setting (`lib/Listener/CalendarObjectTrashScrubListener.php:82`). Decide whether it follows the setting. | 0.25 | | Found on the way. Not part of the feed. |
| TODO | X5 | Optional. Keep one rule for the calendar that `getValidUsers()` reports, with its tests, and no scope check. Why: the rule is written out in more than one place today. Files: `lib/Service/SyncUserService.php`. | 0.25 | | safe. Not part of the feed. The code is on the closed branch `task/n7.2-synced-calendar-check`. |

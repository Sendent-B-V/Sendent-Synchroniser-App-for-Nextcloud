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
| TODO | C2 | Settle Q2: can the sync service process a row for an event created in Nextcloud. | 0 | | all. Needs a run against the sync service. |
| TODO | C3 | Settle Q3: UID in the row or not. | 0 | C1 | all. |
| TODO | C4 | Settle Q4: what is sent when an event moves out of the synced calendar. | 0 | C1 | all. |
| TODO | C5 | Settle Q5: restore sent as created or as its own kind. | 0 | C1 | all. |
| TODO | C6 | Settle Q6: retention period and the too-old cursor. | 0 | C1 | all. |
| TODO | C7 | Settle Q7: explicit row for a deleted calendar or not. | 0 | C1 | all. |
| TODO | C8 | Finish and agree contract C8, the row shape. | 0.5 | C3, C5 | all. |
| TODO | C9 | Finish and agree contract C9, which changes produce a row. | 0.25 | C4, C7 | all. Includes how the synced calendar is found for a user. |
| TODO | C10 | Write and agree contract C10, retention and the too-old cursor. | 0.25 | C6 | all. |

## Phase 1: storage

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| TODO | N6.1 | Migration for a separate append-only table of per-event rows, with the indexes the read and the cleanup need. Why: per-calendar rows are overwritten in place, per-event rows must not be. Files: new migration next to `lib/Migration/Version000004Date20260820.php`, `appinfo/info.xml`. | 0.5 | C8 | solo. needs Q3. Must run on MySQL, MariaDB and Postgres. |
| TODO | N6.2 | Entity and mapper for the new table: insert, read a page by sequence, highest sequence, delete older than a time, delete for one user. Files: new classes in `lib/Db/`, modelled on `DirtyCollection` and `DirtyCollectionMapper`. Unit tests included. | 0.5 | N6.1 | safe. |

## Phase 2: write path

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| TODO | N7.1 | Turn each of the six event-level changes into the file name and kind for a row, following contract C9. Covers the trash name, the silent purge, the restore event that lacks a UID, and the move. Why: the listener reads only the calendar today. Files: `lib/Service/ChangeNotification/DavEventReferenceExtractor.php`, `lib/ChangeNotification/`. Unit tests included. | 1 | C8, C9 | safe. needs Q3, Q4, Q5. |
| TODO | N7.2 | Decide per change whether the event is in the user's synced calendar. Why: scope is checked per user today, not per calendar. Files: `lib/Service/ChangeNotification/SyncScope.php`, `lib/Service/SyncUserService.php` for reading the setting. Unit tests included. | 0.5 | C9 | safe. How the setting resolves per user is not verified yet; see the range in PLAN.md. |
| TODO | N7.3 | Write the per-event row together with the per-calendar row, inside the same savepoint, with failures swallowed and logged as today. Why: a feed failure must never break the user's save. Files: `lib/Listener/DavChangeListener.php`, `lib/Service/ChangeNotification/ChangeLedgerService.php`. | 1 | N6.2, N7.1, N7.2 | solo. |
| TODO | N7.4 | Extend the re-stamp after commit to per-event rows. Why: a row written before the commit can otherwise sit below a cursor a reader already passed. Files: `lib/Service/ChangeNotification/ChangeLedgerService.php`, `lib/Service/ChangeNotification/PostCommitQueue.php`. | 0.5 | N7.3 | solo. |

## Phase 3: read path

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| TODO | N8.1 | Return per-event rows from `/notify/changes` in one sequence order with the per-calendar rows, under the existing fence and page limit, with one cursor. Files: `lib/Controller/ChangeFeedApiController.php`, the mapper from N6.2. Unit tests included. | 1 | N6.2, C8 | solo. Can be built against rows inserted by a test, in parallel with Phase 2. |
| TODO | N8.2 | Answer a cursor that is older than the oldest kept row as contract C10 says. Needs a mark of the highest removed sequence that survives the cleanup. Files: `lib/Service/ChangeNotification/CursorService.php`, `lib/Controller/ChangeFeedApiController.php`. | 0.5 | N8.1, N9.1, C10 | solo. needs Q6. |

## Phase 4: housekeeping

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| TODO | N9.1 | Remove per-event rows older than the retention period in the maintenance job. Files: `lib/Cron/ChangeNotificationMaintenance.php`, `lib/Service/ChangeNotification/ChangeNotificationConfig.php`. | 0.5 | N6.2, C10 | solo. needs Q6. |
| TODO | N9.2 | Remove a user's per-event rows when the user is deleted or leaves the active group. Why: the rows carry file names and would otherwise outlive the user until retention. Files: `lib/AppInfo/Application.php`, a listener in `lib/Listener/`. | 0.5 | N6.2 | solo. |

## Phase 5: tests, docs and gate

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| TODO | N10.1 | Integration test on the CI matrix: each row of the table in contract C9 produces exactly the row it names, on MariaDB and Postgres. Files: `tests/`, `.github/workflows/cn-integration.yml`. | 1 | N7.4, N8.1 | safe. |
| TODO | N10.2 | Concurrency test: two writers and one reader, no row is skipped or seen out of order across the fence. | 1 | N7.4, N8.1 | safe. |
| TODO | N10.3 | Update the feed docs and the reference client for the new row. Files: `docs/change-notifications.md`, `docs/connector-change-feed-contract.md`, `docs/connector-reference/SendentChangeFeedClient.cs`. | 0.5 | C8, C9, C10 | safe. |
| TODO | S1 | Sync service: confirm that no change is needed to process per-event rows, or fix the lookup for events created in Nextcloud. | 0 to 1 | C2, C8 | needs Q2. Other repo. |
| TODO | G1 | Gate on the dev instance. Pass: an event created, changed and deleted in the synced calendar in Nextcloud reaches the other side through the feed without waiting for the timer sync, and a change in another calendar produces no row. | 0.5 | N10.1, N9.1, N9.2, S1 | Owed by everyone. needs Q2. |

## Cross-cutting follow-ups

| Status | Id | Task | Est | After | Notes |
|---|---|---|---|---|---|
| TODO | X1 | Run the event probe on Nextcloud 33. | 0.25 | | safe. 32 and 35 were run, 34 was read. |
| TODO | X2 | Check in the Calendar app: file name on a move between calendars, and what "this and future events" produces. | 0.25 | | safe. Feeds Q4. |

# Per-event change feed rows: plan

16 November 2026, Kleon. Draft, updated 18 November 2026. Building per-event rows is decided. The remaining open questions are in CONTRACTS.md.

This file is a snapshot. Tracking happens in TODO.md.

## Summary

The change feed tells the sync service which calendar changed. The sync service wants to know which event changed and how. This plan adds one feed row per changed event. The existing rows stay, and the new rows go in their own table.

| Area | Days |
|---|---|
| Decisions and contracts | 1 |
| Build in the app, unit tests included | 6 |
| Integration and concurrency tests | 2 |
| Docs | 0.5 |
| Gate on dev | 0.5 |
| Total | 10 |

Realistic range: 8 to 12 days. What moves it:

- Concurrency tests. One day if the existing test setup carries over, two if it does not.
- The new number after commit for an append-only row. Today that step overwrites a per-calendar row. For a per-event row it has to change the number on the existing row. Half a day is planned; it is the least certain build task.
- The gate. If the sync service does not find an event created in Nextcloud after all, that is a second gate run and work in the other repo.

## Where we are today

- The feed exists on `feat/notification` only. It is not in main and not released.
- One row per changed calendar or address book, overwritten in place. The row holds no calendar data and no sync token, on purpose.
- The sync service drops the calendar rows, so calendar changes sync on the timer. Address-book rows are used as they are.

## Goal and scope

In scope: one row per changed event in any calendar of a sync user, with the event's file name and the change kind.

Out of scope: per-card rows for contacts, event content in the row, the event UID, sync tokens in the row, filtering by synced calendar in the app, a batch endpoint, a signal for a too-old cursor.

## Target design

The contracts in CONTRACTS.md hold the exact shapes. In short:

- A per-event row has the fields of a per-calendar row plus the event's file name and the change kind. The kind is created, updated or deleted. There is no UID and no event content.
- Rows are written for events in every calendar of a sync user. The list of synced calendars lives on the sync side, so the sync service filters on the calendar named in the row.
- A move to trash is sent as deleted, a restore as created, and a purge from trash sends nothing. A move between calendars is deleted in the calendar the event left and created in the one it entered.
- Per-calendar rows stay as they are. Per-event rows go in a separate append-only table.
- Each row has its own sequence number. One endpoint returns both kinds in one list ordered by number, with one cursor.
- The row is written in the user's transaction inside a savepoint. A failure is swallowed and logged and never breaks the save.
- Per-event rows are kept for 3 days (Q6). Per-calendar rows are never removed. A cursor older than the kept rows gets a normal response; the timer sync covers the rest.
- A user's per-event rows are removed when the user is deleted or leaves the active group.
- Events the sync service itself writes into a calendar produce rows too. The sync service drops its own writes on its side.
- Works on MySQL, MariaDB and Postgres. Minimum Nextcloud version is 32: the per-event listener uses the calendar object events in `OCP\Calendar\Events`, which exist from 32 (before 32 they lived in `OCA\DAV\Events` and that set was removed in 32). The app raised its own minimum from 28 to 32 to match on 7 October 2026, on the change-feed branch.

## Work breakdown

See TODO.md for the tasks. Areas:

- Storage: the table and its mapper. 1 day.
- Write path: from the calendar event to a row. 3 days.
- Read path: both kinds of row from one endpoint in one order. 1 day.
- Housekeeping: retention cleanup and user removal. 1 day.

## Testing scope

Unit tests with each task. One integration test on the CI matrix for every row of the table in contract C9. One concurrency test for the fence. One manual gate on dev together with the sync service, which includes an event created in Nextcloud.

## Dependencies and open questions

Settled: Q1 to Q7 and the contracts C8 to C10, the last of them on 23 November 2026.

## Risks

- The sync service is expected to find an event from its file name alone. That was confirmed by Artem and not yet seen end to end. The gate covers it.
- After an outage longer than 3 days, older changes reach the sync service through the timer sync and the feed does not show that. Accepted in Q6.
- Nextcloud 33 was not run. 32 and 35 were, and 34 was read.
- The table grows with every calendar change of every synced user, the sync service's own writes included, until the cleanup runs.

## Resources

To fill in: who works on it and for how many days a week.

## Roadmap

No dates yet. The order is fixed by what depends on what:

1. Decision PR merged. Nothing else merges before it.
2. Storage. Half a day, then the mapper.
3. Write path and read path, side by side. The read path can be built against rows a test inserts.
4. Housekeeping, any time after storage.
5. Integration and concurrency tests, and the docs.
6. Gate on dev with the sync service.

With one person the build and tests take about 9 working days after the decision PR. With two, the write path and the read path overlap and it is about 6.

## References

- `docs/change-notifications.md` and `docs/connector-change-feed-contract.md` on this branch.
- Sync service branch `feature/onprem-kerberos-nc-changefeed`.
- Nextcloud `apps/dav/lib/CalDAV/CalDavBackend.php`, stable34.

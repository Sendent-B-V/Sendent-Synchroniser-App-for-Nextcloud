# Per-event change feed rows: plan

16 November 2026, Kleon. Draft. Building per-event rows is decided. The remaining open questions are in CONTRACTS.md.

This file is a snapshot. Tracking happens in TODO.md.

## Summary

The change feed tells the sync service which calendar changed. The sync service wants to know which event changed and how. This plan adds one feed row per changed event, next to the existing rows and in its own table.

| Area | Days |
|---|---|
| Decisions and contracts | 1 |
| Build in the app, unit tests included | 6.5 |
| Integration and concurrency tests | 2 |
| Docs | 0.5 |
| Gate on dev | 0.5 |
| Total | 10.5 |

Realistic range: 8 to 13 days. What moves it:

- Q2. If the sync service cannot find an event created in Nextcloud, that is up to a day in the sync service and a second gate run.
- The synced calendar. How the setting resolves per user was not checked. Half a day if it is a plain lookup, more if it is not.
- Q5. With restore sent as "created" the write path is slightly smaller. The UID is out of the row (Q3), which already removed the extra read on restore.
- Concurrency tests. One day if the existing test setup carries over, two if it does not.

## Where we are today

- The feed exists on `feat/notification` only. It is not in main and not released.
- One row per changed calendar or address book, overwritten in place. The row holds no calendar data and no sync token, on purpose.
- The sync service drops the calendar rows, so calendar changes sync on the timer. Address-book rows are used as they are.

## Goal and scope

In scope: one row per changed event in the user's synced calendar, with the event's file name and the change kind.

Out of scope: per-card rows for contacts, event content in the row, sync tokens in the row, changes in calendars that are not synced, a batch endpoint.

## Target design

To fill in after the decision PR. Fixed points from the existing design:

- Per-calendar rows stay. Per-event rows go in a separate append-only table.
- One sequence, one cursor, one endpoint.
- The row is written in the user's transaction inside a savepoint. A failure is swallowed and logged and never breaks the save.
- Works on MySQL, MariaDB and Postgres. Minimum Nextcloud version is 32.

## Work breakdown

See TODO.md for the tasks. Areas:

- Storage: the table and its mapper.
- Write path: from the calendar event to a row, synced calendar only.
- Read path: both kinds of row from one endpoint in one order.
- Housekeeping: retention, cleanup, user removal.

## Testing scope

Unit tests with each task. One integration test on the CI matrix for every row of the table in contract C9. One concurrency test for the fence. One manual gate on dev together with the sync service.

## Dependencies and open questions

Q2 to Q7 in CONTRACTS.md. Q1 is settled. Q2 needs a run against the sync service.

## Risks

- A row that names an event the sync service cannot find. See Q2.
- A cursor older than the kept rows that reads as "nothing changed". See Q6.
- Nextcloud 33 was not run. 32 and 35 were, and 34 was read.
- The table grows with every calendar change of every synced user until the cleanup runs.

## Resources

To fill in.

## Roadmap

To fill in.

## References

- `docs/change-notifications.md` and `docs/connector-change-feed-contract.md` on this branch.
- Sync service branch `feature/onprem-kerberos-nc-changefeed`.
- Nextcloud `apps/dav/lib/CalDAV/CalDavBackend.php`, stable34.

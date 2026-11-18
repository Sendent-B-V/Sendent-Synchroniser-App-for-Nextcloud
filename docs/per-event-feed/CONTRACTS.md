# Per-event change feed rows: decisions and contracts

16 November 2026, Kleon. Draft for the decision PR.

A section in this file changes only by PR, with another developer as reviewer.

The PR that adds this file is the decision PR. Merging it means the open questions below are answered as written. Until then every answer to an open question is a proposal. A section head changes from "open" to "settled <date>" in that PR.

Evidence marked "run" was tested on Nextcloud 32.0.15 with Postgres and 35.0.2 with MariaDB on 16 November 2026. Evidence marked "read" comes from the stable34 source or the sync service branch `feature/onprem-kerberos-nc-changefeed`. Nextcloud 33 was neither run nor read.

## Q1: Do we build per-event rows at all? (settled 16 November 2026)

Yes. The app adds one feed row per changed event. The existing per-calendar and per-address-book rows stay.

The alternative was a token read by the sync service on the calendar when our per-calendar row arrives, with no change in the app. It was not chosen.

What the research found: all six event-level changes reach the app with the calendar and the event's file name (run).

Depends on this: every task below Phase 0.

## Q2: Can the sync service process a row for an event that was created in Nextcloud? (open)

A "created" or "updated" row gives the calendar and the event's file name. The sync service has to find that event from the file name alone.

What we know:

- The sync service turns the file name from the row into a calendar query on the property `X-SENDENTDAVRESOURCEID` (`Application/ChangeFeed/NextcloudChangeFeedWorker.cs:244-250`, `DavClient/Lookup/DavEventLookup.cs:43`, `Sendent.Dav.Client/Calls/BuildReportBody.cs:355-357`, read at commit 590e3133).
- The sync service writes that property, equal to the file name, on events it creates in Nextcloud (`Sendent.Dav.Client/Calls/Repository/WebDavCalendarRepository.cs:53-56`, read).
- That same query, run on Nextcloud 32 and 35 on 17 November 2026: it finds an event that has the property. It finds nothing for an event without it, which is every event created in Nextcloud or by another CalDAV client (run).
- A query on the UID finds both kinds of event (run). The sync service has such a lookup (`Sendent.Dav.Client/Calls/Gateways/CalDavCalendarQueryGateway.cs:109-114`, read), but the feed worker does not pass the UID from the row to it.
- Not run: the sync service itself. The query was built from its source and run inside Nextcloud.

Proposed answer: no, not as the sync service is today. A row for an event created in Nextcloud is fetched and comes back empty. Rows for events the sync service created work. The sync service needs a change: look the event up by the UID from the row, or fetch it by path.

Depends on this: S1, G1, Q3.

Depends on this: S1, G1.

## Q3: Does the row carry the event UID? (open)

The UID was asked for as "ideally". The sync service reads by file name.

What we know: five of the six events carry the UID (run). The restore event does not (run). To fill it we read one column of the event row in the same transaction. Q2 shows a lookup by UID finds events created in Nextcloud, where the lookup by file name does not.

Option A. Carry the UID. One extra field in the row and one extra read on restore.

Option B. Leave it out. The row adds only the file name and the kind.

Proposed answer: none yet. The plan assumes A until this is settled.

Depends on this: C8, N7.1.

## Q4: What do we send when an event moves out of the synced calendar? (open)

What we know: the move event carries the source calendar, the target calendar and the new file name (run). It does not carry the old file name. The Calendar app (6.6.5 on Nextcloud 35) keeps the file name and the UID on a move; it sends one WebDAV MOVE and only the calendar changes (run twice, 18 November 2026). Another CalDAV client can still rename the file in the same MOVE.

Option A. Send nothing. The timer sync removes the event on the other side.

Option B. Send "deleted" for the source calendar with the new file name. This is right for a move in the Calendar app. When a client renames the file during the move, the row names a file the synced calendar never held.

A move into the synced calendar is sent as "created" in both options.

Proposed answer: A.

Depends on this: C9, N7.1.

## Q5: Is a restore from trash sent as "created" or as its own kind? (open)

What we know: the sync service handles created and updated the same way (`Application.Abstractions/Orchestration/HookedCalendarChange.cs:6`, read). The restore event gives the original file name back (run).

Proposed answer: "created". No new kind.

Depends on this: C8, N7.1.

## Q6: How long are rows kept, and what does a reader with an older cursor get? (open)

Per-calendar rows are overwritten in place and never grow. Per-event rows are appended, so they need a retention period and a cleanup.

To decide:

- The retention period, in days.
- What a reader gets when its cursor is older than the oldest row we still hold. It must be able to tell "nothing happened" from "rows were removed", and fall back to a full sync in the second case.

Proposed answer: to be filled.

Depends on this: C10, N8.2, N9.1.

## Q7: Does deleting a calendar get an explicit row? (open)

What we know: moving a calendar to trash, restoring it and deleting it each fire one calendar-level event and no event-level ones (run). The per-calendar row stays in the feed.

Proposed answer: no extra row. The existing per-calendar row covers it.

Depends on this: nothing if the answer is no.

## C8: Row shape in /notify/changes (proposal, 16 November 2026)

Route: `GET /apps/sendentsynchroniser/api/1.0/notify/changes`, unchanged.

Per-calendar and per-address-book rows keep their shape: `p`, `t`, `u`, `c`, `q`.

A per-event row has the same five fields plus:

- `o`: the event's file name inside the calendar, for example `one.ics`. Not a full path.
- `k`: the change kind. One of `created`, `updated`, `deleted`.
- `uid`: the event UID. Only if Q3 is answered A.

Still to write down here before review:

- How a reader tells a per-event row from a per-calendar row.
- The order of rows in one response when both kinds are present, and how the cursor moves.
- Whether one change produces both a per-calendar row and a per-event row with separate sequence numbers.
- One example response as the shared test vector.

Decisions this section makes, for review:

- The field names `o`, `k` and `uid`.
- The three kinds, with no "moved" and no "restored".
- The row holds no event content, no etag and no sync token.

## C9: Which changes produce a per-event row (proposal, 16 November 2026)

Rows are written only for events in the user's synced calendar, and only for users the feed already covers.

| Change | Row |
|---|---|
| Event created | `created` |
| Event updated | `updated` |
| One occurrence of a series changed | `updated`, on the series file |
| Event moved to trash | `deleted`, with the original file name |
| Event restored from trash | see Q5 |
| Event purged from trash | none |
| Event moved into the synced calendar | `created` |
| Event moved out of the synced calendar | see Q4 |
| Calendar trashed, restored or deleted | none at event level, see Q7 |
| Subscription and federated calendars | none |
| Contacts | none at card level; address-book rows stay as they are |

What we know about the synced calendar (read on 3a339c2, 18 November 2026):

- It is a calendar URI. The app resolves it as the `calendar` column of the user's row in `sndntsync_users`, then the app setting `defaultCalendar`, then `personal` (`lib/Service/SyncUserService.php:260`, `:310-318`). This is the value the sync service is told.
- Nothing in the app writes the per-user column, so today it is one value for all users.
- The setting is free text and is not checked on save. The app does not check that the calendar exists when it reports the URI.
- The listener already has the changed calendar's URI and reads the user's row, so the check is a comparison of two URIs with no extra query.
- Not verified: that a change made by a sharee arrives with the owner's principal and the original URI.

Still to write down here before review: how the listener and the list of sync users are kept on the same rule, and what happens for a user whose setting names a calendar that does not exist.

Decisions this section makes, for review:

- Changes in other calendars of the same user produce no per-event row.
- A purge from trash is silent, because the trash step already sent `deleted`.
- A failure while writing a per-event row never breaks the user's save.

## C10: Retention and the too-old cursor (proposal, 16 November 2026)

Empty until Q6 is settled. This section then states the retention period, the configuration key, the exact response for a cursor that is too old, and what the reader is expected to do with it.

Decisions this section makes, for review: to be listed.

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

## Q2: Can the sync service process a row for an event that was created in Nextcloud? (settled 18 November 2026)

A "created" or "updated" row gives the calendar and the event's file name. The sync service has to find that event from the file name alone.

What we know:

- The sync service turns the file name from the row into a calendar query on the property `X-SENDENTDAVRESOURCEID` (`Application/ChangeFeed/NextcloudChangeFeedWorker.cs:244-250`, `DavClient/Lookup/DavEventLookup.cs:43`, `Sendent.Dav.Client/Calls/BuildReportBody.cs:355-357`, read at commit 590e3133).
- The sync service writes that property, equal to the file name, on events it creates in Nextcloud (`Sendent.Dav.Client/Calls/Repository/WebDavCalendarRepository.cs:53-56`, read).
- That same query, run on Nextcloud 32 and 35 on 17 November 2026: it finds an event that has the property. It finds nothing for an event without it, which is every event created in Nextcloud or by another CalDAV client (run).
- A query on the UID finds both kinds of event (run). The sync service has such a lookup (`Sendent.Dav.Client/Calls/Gateways/CalDavCalendarQueryGateway.cs:109-114`, read), but the feed worker does not pass the UID from the row to it.
- Not run: the sync service itself. The query was built from its source and run inside Nextcloud.

Answer: yes. Artem confirmed on 18 November 2026 that the file name is enough. The query result above was on commit 590e3133 and is kept here as the reason gate G1 includes an event created in Nextcloud.

Depends on this: S1, G1.

## Q3: Does the row carry the event UID? (settled 18 November 2026, changed 26 November 2026)

The UID was asked for as "ideally". The sync service reads by file name.

What we know: five of the six events carry the UID (run). The restore event does not (run). To fill it we read one column of the event row in the same transaction.

First answer, 18 November 2026: no. The row adds only the file name and the kind. Artem confirmed the file name is enough (Q2), and the restore event then needs no extra read.

Answer now: yes. On 24 November 2026 the sync team asked for the UID again, for callbacks on their side. Kleon and Artem agreed on 26 November 2026 to add it. The per-event tables and the read endpoint were already merged without it, so the UID is added by a separate task (N9.3) and is an optional field. Contract C8 has the field.

Not known to us: what the callbacks do with the UID. Not built and not run yet: the read of the UID for a restore.

Depends on this: C8, N7.1.

## Q4: What do we send when an event moves out of the synced calendar? (settled 19 November 2026)

What we know: the move event carries the source calendar, the target calendar and the new file name (run). It does not carry the old file name. The Calendar app (6.6.5 on Nextcloud 35) keeps the file name and the UID on a move; it sends one WebDAV MOVE and only the calendar changes (run twice, 18 November 2026). Another CalDAV client can still rename the file in the same MOVE.

Option A. Send nothing. The timer sync removes the event on the other side.

Option B. Send "deleted" for the source calendar with the new file name. This is right for a move in the Calendar app. When a client renames the file during the move, the row names a file the synced calendar never held.

A move into the synced calendar is sent as "created" in both options.

Answer: B. A move out of the synced calendar is sent as "deleted" for the synced calendar, with the file name the event has after the move. Artem asked for this on 19 November 2026. The Calendar app keeps the file name on a move, so the row names the event the sync service knows. A client that renames the file during the move produces a row for a name the synced calendar never held.

Changed 19 November 2026: the app does not know which calendars are synced (see C9), so it sends both sides of every move: `deleted` in the calendar the event left and `created` in the calendar it entered. The answer above still holds for a move out of a synced calendar.

Depends on this: C9, N7.1.

## Q5: Is a restore from trash sent as "created" or as its own kind? (settled 18 November 2026)

What we know: the sync service handles created and updated the same way (`Application.Abstractions/Orchestration/HookedCalendarChange.cs:6`, read). The restore event gives the original file name back (run).

Answer: "created". No new kind. The sync service sees the state of a restored event when it fetches it, so a separate kind would tell it nothing more.

Depends on this: C8, N7.1.

## Q6: How long are rows kept, and what does a reader with an older cursor get? (settled 23 November 2026)

Per-calendar rows are overwritten in place and never grow. Per-event rows are appended, so they need a retention period and a cleanup.

To decide:

- The retention period, in days.
- What a reader gets when its cursor is older than the oldest row we still hold. It must be able to tell "nothing happened" from "rows were removed", and fall back to a full sync in the second case.

What we know:

- A row is only useful until the timer sync has covered the same change. Retention has to exceed the longest gap the sync service should survive without a full resync: a restart is minutes, an overnight crash about 16 hours, a weekend about 3 days.
- The listener records a change whoever made it. It filters on the owner of the calendar only, not on who wrote (`lib/Listener/DavChangeListener.php:58-64`, `lib/Service/ChangeNotification/SyncScope.php:32-36`, read; a write by another user was recorded under the owner, run). So events the sync service itself writes into the synced calendar produce rows too.
- The sync service has a step that drops its own writes when they come back (`Application/Orchestration/SynchronizeEvents/Steps/FilterEventEchoStep.cs`, read at 590e3133). Not checked: that it also applies to rows from the feed.
- Table size is not the limit. At an assumed 1,000 users with 30 changes a day each, own writes included, 3 days is about 90,000 small rows.

Answer: 3 days, agreed by Maarten on 23 November 2026. The sync service can then be down for a whole weekend, from Friday evening to Monday morning, and still read every row when it comes back, without a full resync.

A reader with an older cursor gets no special response. It receives the per-event rows that are still kept, and nothing says that older ones were removed.

Why nothing is lost:

- Per-calendar rows are never removed. A reader with an older cursor still gets the row that says the calendar changed since its cursor.
- The timer sync runs whatever the feed says, and it reads the calendar by sync token. Every change the removed rows described is picked up there. Those changes arrive one timer interval later than they would have through the feed, which is how every calendar change arrives today.
- The only thing a signal could trigger is a sync of that calendar, and the timer sync does that anyway.

What this costs: the gap is not visible. After an outage longer than the retention period the response looks normal, and the feed does not show that older changes came in through the timer sync.

Options not chosen:

- An error status for a too-old cursor. It withholds the per-calendar rows, which are still valid, and a new reader starting at 0 would always hit it.
- A flag saying the range is incomplete. The reader asks from its cursor minus the overlap, so on an instance with no changes for longer than the retention period the flag would be raised on every request.
- A floor value in every response that the reader compares with its stored cursor. This is the shape to add if a visible signal is wanted later. It is an added field, so it can come later without breaking a reader.

Depends on this: C10, N8.2, N9.1.

## Q7: Does deleting a calendar get an explicit row? (settled 18 November 2026)

What we know: moving a calendar to trash, restoring it and deleting it each fire one calendar-level event and no event-level ones (run). The per-calendar row stays in the feed.

Answer: no extra row. The existing per-calendar row covers it.

Depends on this: nothing if the answer is no.

## C8: Row shape in /notify/changes (settled 23 November 2026)

Route: `GET /apps/sendentsynchroniser/api/1.0/notify/changes`, unchanged.

Per-calendar and per-address-book rows keep their shape: `p`, `t`, `u`, `c`, `q`.

A per-event row has the same five fields plus:

- `o`: the event's file name inside the calendar, for example `one.ics`. Not a full path.
- `k`: the change kind. One of `created`, `updated`, `deleted`.

A per-event row can also have (added 26 November 2026, see Q3):

- `i`: the UID of the event, as it is in the event's data. The field name is our proposal; Artem has not confirmed the name yet.

`i` is optional. A reader must accept a per-event row without it. The field is absent, not empty, in these cases:

- The row was written before the app version that adds the UID.
- The app could not read the UID. This can happen for a restore, where the UID is not in the Nextcloud event and is read from the database.

A row is never held back because the UID is missing. One event has one UID, also when it has changed occurrences, so one row has at most one `i`.

A row is a per-event row when it has both `o` and `k`. A row without them is a per-calendar or per-address-book row. There is no separate marker. The sync service already reads rows this way (`Application/ChangeFeed/NextcloudChangeFeedWorker.cs:234`, read).

Sequence numbers and order:

- One event change writes two rows, a per-event row and the per-calendar row. Each gets its own sequence number. No two rows share a number, as today (`lib/Service/ChangeNotification/ChangeLedgerService.php:54-58`, read).
- A response holds both kinds of row in one list, ordered by sequence number. The cursor moves as it does today.
- The per-event row usually has the lower number. The sync service must handle either row arriving first and must not treat one as proof that the other was already delivered.
- Why the order is not guaranteed: a row whose number a read has already passed gets a new number after commit. That can happen to the per-event row alone, which then sits above its per-calendar row.

To bring up with Artem: the sentence above on handling either row first.

Options not chosen:

- Both rows share one number. A page that ends between the two would move the cursor past the second row, so paging would need a rule for equal numbers, and the new number after commit would have to be given to both rows in two tables. Nothing asks for the two rows to be matched.
- A guaranteed order. The new number after commit would then always have to move both rows together.

Shared test vector. User alice saved a contact and then changed the event `one.ics` in her synced calendar `personal`. The sync service asks `GET /notify/changes?since=1000`:

```json
{
  "v": 1,
  "instance": "oc1a2b3c4d5e",
  "cursor": 1052,
  "refs": [
    { "p": "principals/users/alice", "t": "carddav", "u": "contacts", "c": false, "q": 1049 },
    { "p": "principals/users/alice", "t": "caldav", "u": "personal", "c": false, "q": 1051, "o": "one.ics", "k": "updated", "i": "5f2c9a1e-7b3d-4c60-9a41-2d8e6f0b1c77" },
    { "p": "principals/users/alice", "t": "caldav", "u": "personal", "c": false, "q": 1052 }
  ],
  "has_more": false
}
```

The second row is the per-event row. The third is the per-calendar row for the same change. `c` is always false on a per-event row. The order of keys inside a row carries no meaning. Until task N9.3 is merged, the app sends this row without `i`.

Decisions this section makes, for review:

- The field names `o` and `k`.
- The UID in the optional field `i`, absent when it is not known (changed 26 November 2026; first decision was no UID).
- A reader tells the two kinds of row apart by the presence of `o` and `k`.
- Each row has its own sequence number, and the order between a per-event row and its per-calendar row is not guaranteed.
- The three kinds, with no "moved" and no "restored".
- The row holds no event content, no etag and no sync token.

## C9: Which changes produce a per-event row (settled 23 November 2026)

Rows are written for events in every calendar of a user the feed already covers. The app does not filter by calendar. The sync service filters on the calendar named in the row (`u`).

| Change | Row |
|---|---|
| Event created | `created` |
| Event updated | `updated` |
| One occurrence of a series changed | `updated`, on the series file |
| Event moved to trash | `deleted`, with the original file name |
| Event restored from trash | `created`, with the original file name |
| Event purged from trash | none |
| Event moved between calendars | `deleted` in the calendar it left and `created` in the calendar it entered, both with the file name after the move |
| Calendar trashed, restored or deleted | none at event level, see Q7 |
| Subscription and federated calendars | none |
| Contacts | none at card level; address-book rows stay as they are |

Why the app does not filter by calendar (19 November 2026). The sync team is testing several synced calendars per user, and Artem said the list of a user's synced calendars lives on the sync side. The app can only filter on a list it holds, and it holds one value per user. So every calendar of a covered user gets per-event rows, and the sync service drops the rows for calendars it does not sync, as it drops per-calendar rows today. The field `u` is already in every row. With several synced calendars the sync service must read it to know which calendar an event belongs to; the file name alone is not enough.

Confirmed by Artem on 23 November 2026: the sync side filters the feed rows on its own list of synced calendars, and Nextcloud sends every row. Not run by us: on the branch read (590e3133) the worker does not look at `u`. Gate G1 checks it.

What we know about the calendar the app reports (read on 3a339c2, 18 November 2026):

- It is a calendar URI. The app resolves it as the `calendar` column of the user's row in `sndntsync_users`, then the app setting `defaultCalendar`, then `personal` (`lib/Service/SyncUserService.php:260`, `:310-318`). This is the value the sync service is told in the list of sync users.
- Nothing in the app writes the per-user column, so today it is one value for all users.
- The setting is free text and is not checked on save. The app does not check that the calendar exists when it reports the URI.
- A change made by a sharee arrives with the owner's principal and the calendar's own URI, not the sharee's `_shared_by_` view (run twice on Nextcloud 35 over CalDAV, 18 November 2026). Not run: the Calendar web UI as sharee, group shares, a sharee who is also a sync user.

Open: which calendar the list of sync users reports as `calendar` once a user has several synced calendars. It does not affect the feed.

Decisions this section makes, for review:

- Every calendar of a covered user gets per-event rows. The sync service filters on the calendar in the row.
- A move always produces two rows, one for each calendar.
- A purge from trash is silent, because the trash step already sent `deleted`.
- A failure while writing a per-event row never breaks the user's save.

## C10: Retention and the too-old cursor (settled 23 November 2026)

Follows Q6.

- Per-event rows are kept for 3 days and then removed by the maintenance job. Per-calendar and per-address-book rows are never removed.
- A request with a cursor older than the oldest kept per-event row is answered like any other request. The response has no extra field and no error.
- The reader needs no new behaviour. Its timer sync covers changes whose per-event rows were removed.

The setting: the retention period is a fixed value in `lib/Constants.php`, not a setting an admin can change. The feed already does this for its other fixed limits, the overlap and the page size (`lib/Constants.php:68-72`, read), and keeps app settings for the values an admin is meant to tune. The maintenance job runs on every cron run (`lib/Cron/ChangeNotificationMaintenance.php:30`, read), so rows are removed within minutes of passing 3 days.

Decisions this section makes, for review:

- 3 days of retention for per-event rows.
- No signal for a too-old cursor.
- The retention period is a fixed value, not an admin setting.

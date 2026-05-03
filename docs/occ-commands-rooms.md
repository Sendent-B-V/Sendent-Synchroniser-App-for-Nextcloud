# Sendent Sync — `occ` commands for rooms

Server-side reference for the `sendentsynchroniser:rooms:*` Symfony Console commands. All commands are run via Nextcloud's `occ` from the Nextcloud webroot:

```bash
sudo -u www-data php occ sendentsynchroniser:rooms:<command> [args] [options]
```

Use the user that owns the Nextcloud install (commonly `www-data`, `nginx`, or `apache`).

## License model

| Command | License required |
|---|---|
| `rooms:list`, `rooms:create`, `rooms:show`, `rooms:update`, `rooms:delete` | No |
| `rooms:groups:list`, `rooms:groups:create`, `rooms:groups:delete` | No |
| `rooms:permissions:list`, `rooms:permissions:grant`, `rooms:permissions:revoke` | No |
| **`rooms:bind`** | **Yes** — `rooms.sync` entitlement |
| `rooms:unbind`, `rooms:retry-sync` | No (cleanup must always work) |

**Non-cloud-bound rooms can be fully managed through occ** without a license, without the NC Exchange Connector running, and without any external service reachable.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Success |
| `1` | Generic failure (Symfony Console default for uncaught exceptions) |
| `2` | Validation error (bad id, missing required field, invalid email, etc.) |
| `3` | Not found (room id, group id, perm-id, or binding) |
| `4` | License required (`rooms:bind` only) |

## Quick reference

```text
sendentsynchroniser:rooms:list                  [--group=GID]
sendentsynchroniser:rooms:create                <id> <name> [room options]
sendentsynchroniser:rooms:show                  <id>
sendentsynchroniser:rooms:update                <id> [--field=value …]
sendentsynchroniser:rooms:delete                <id> [--force]

sendentsynchroniser:rooms:groups:list
sendentsynchroniser:rooms:groups:create         <id> <name> [--description=…]
sendentsynchroniser:rooms:groups:delete         <id> [--force]

sendentsynchroniser:rooms:permissions:list      <id> [--group]
sendentsynchroniser:rooms:permissions:grant     <id> <role> <user|group> <principal-id> [--on-group]
sendentsynchroniser:rooms:permissions:revoke    <perm-id>

sendentsynchroniser:rooms:bind                  <room-id> <kind> <external-id>          # license-gated
sendentsynchroniser:rooms:unbind                <room-id>
sendentsynchroniser:rooms:retry-sync            <room-id>
```

---

## Rooms

### `rooms:list`

List all rooms. The `binding` column shows `kind:state` (e.g. `exchange:completed`) when the room is bound, or `—` when unbound.

**Options**

| Option | Description |
|---|---|
| `--group=GID` | Filter to rooms in this group only |

**Example**

```bash
$ sudo -u www-data php occ sendentsynchroniser:rooms:list
+--------------+--------------+----------+-------+--------+----------------------+
| id           | name         | capacity | group | active | binding              |
+--------------+--------------+----------+-------+--------+----------------------+
| boardroom-a  | Boardroom A  | 12       | exec  | yes    | exchange:completed   |
| huddle-1     | Huddle 1     | 4        |       | yes    | —                    |
| phone-booth  | Phone Booth  | 1        |       | no     | —                    |
+--------------+--------------+----------+-------+--------+----------------------+
```

### `rooms:create`

Create a new room. Provisions the hidden NC user `_room_<id>` and a CalDAV calendar at `/remote.php/dav/calendars/_room_<id>/room/`. **No license required** — this works for free-tier rooms.

**Arguments**

| Argument | Required | Description |
|---|---|---|
| `id` | yes | Room id. Must be lowercase kebab-case, 2–64 chars (regex `^[a-z0-9][a-z0-9-]{0,62}[a-z0-9]$`) |
| `name` | yes | Display name |

**Options**

| Option | Description |
|---|---|
| `--email=<addr>` | Contact email (informational; not the same as a binding `externalId`) |
| `--capacity=<n>` | Integer, ≥ 0 |
| `--room-number=<s>` | E.g. `3.14` |
| `--floor=<s>` | E.g. `3` or `Ground` |
| `--address=<s>` | Free-form |
| `--room-type=<s>` | One of `meeting-room` (default), `board-room`, `phone-booth`, `office`, or any string |
| `--description=<s>` | |
| `--group=<gid>` | Room group id (must already exist) |
| `--facility=<f>` | Repeatable. E.g. `--facility=projector --facility=whiteboard` |

**Example**

```bash
sudo -u www-data php occ sendentsynchroniser:rooms:create boardroom-a "Boardroom A" \
    --capacity=12 --floor=3 --room-number=3.14 \
    --facility=projector --facility=whiteboard \
    --group=exec
```

### `rooms:show`

Print one room as JSON, with its binding (if any).

**Arguments**

| Argument | Required | Description |
|---|---|---|
| `id` | yes | Room id |

**Example**

```bash
$ sudo -u www-data php occ sendentsynchroniser:rooms:show boardroom-a
{
    "id": "boardroom-a",
    "name": "Boardroom A",
    "capacity": 12,
    "active": true,
    "backingPrincipalUri": "principals/users/_room_boardroom-a",
    "backingCalendarUri": "/remote.php/dav/calendars/_room_boardroom-a/room/",
    "createdAt": "2026-05-02T10:00:00+00:00",
    ...
}

Binding:
{
    "roomId": "boardroom-a",
    "kind": "exchange",
    "externalId": "boardroom-a@contoso.com",
    "linkVersion": 3,
    "state": "completed",
    "lastSyncedAt": "2026-05-02T10:15:00+00:00",
    "stats": {"eventsPushed": 5, "eventsPulled": 2}
}
```

### `rooms:update`

Patch one or more fields on an existing room. Only options actually passed are applied; omitted fields are left unchanged.

**Arguments**

| Argument | Required | Description |
|---|---|---|
| `id` | yes | Room id |

**Options** — same set as `rooms:create`, plus:

| Option | Description |
|---|---|
| `--active=true\|false` | Soft-disable / re-enable the room |
| `--group=<gid>` | Reassign group; pass empty string `--group=""` to clear |
| `--facility=<f>` | Repeatable. **Replaces the entire facilities array** when present. Omit to leave facilities untouched. |

**Example — bulk reassignment**

```bash
for id in $(occ sendentsynchroniser:rooms:list --group=exec | awk '/^| /{print $2}' | grep -v '^id$'); do
    occ sendentsynchroniser:rooms:update "$id" --group=executive-floor
done
```

### `rooms:delete`

Delete a room. Drops:
- The binding row (if any) — sync service sees it disappear on its next `GET /rooms` poll.
- Permission rows (per-room and group-permission rows where this room is the target).
- Facility rows.
- The CalDAV calendar.
- The hidden NC user `_room_<id>`.
- The room row itself.

**Arguments**

| Argument | Required | Description |
|---|---|---|
| `id` | yes | Room id |

**Options**

| Option | Description |
|---|---|
| `--force`, `-f` | Skip the confirmation prompt |

**Example**

```bash
sudo -u www-data php occ sendentsynchroniser:rooms:delete boardroom-a -f
```

---

## Room groups

### `rooms:groups:list`

```bash
$ sudo -u www-data php occ sendentsynchroniser:rooms:groups:list
+------+-------------------+--------------------+
| id   | name              | description        |
+------+-------------------+--------------------+
| exec | Executive Floor   | Top-floor rooms    |
| eng  | Engineering       |                    |
+------+-------------------+--------------------+
```

### `rooms:groups:create`

| Argument / option | Description |
|---|---|
| `id` (arg) | Group id. Same regex as room id. |
| `name` (arg) | Display name |
| `--description=<s>` | Optional |

### `rooms:groups:delete`

Rooms in the group are **unassigned** (their `groupId` is set null), not deleted. Group-level permission rows are dropped.

| Argument / option | Description |
|---|---|
| `id` (arg) | Group id |
| `--force`, `-f` | Skip prompt |

---

## Permissions

Permissions grant `viewer` / `booker` / `manager` roles to NC users or NC groups, scoped to a room or to a room-group. The `id` argument is the **target** id (room id by default, or group id with `--group` / `--on-group`).

### `rooms:permissions:list`

```bash
$ occ sendentsynchroniser:rooms:permissions:list boardroom-a
+---------+---------+---------------+-------------+
| perm-id | role    | principalType | principalId |
+---------+---------+---------------+-------------+
| 7       | booker  | user          | alice       |
| 8       | manager | user          | bob         |
| 9       | viewer  | group         | engineering |
+---------+---------+---------------+-------------+

# List permissions on a room-group instead:
$ occ sendentsynchroniser:rooms:permissions:list exec --group
```

### `rooms:permissions:grant`

```bash
# Grant on a room
occ sendentsynchroniser:rooms:permissions:grant boardroom-a booker user alice

# Grant on a room-group (note --on-group)
occ sendentsynchroniser:rooms:permissions:grant exec viewer group engineering --on-group
```

| Argument | Description |
|---|---|
| `id` | Room id (default) or group id with `--on-group` |
| `role` | `viewer` / `booker` / `manager` |
| `principal-type` | `user` / `group` |
| `principal-id` | NC uid or NC group gid |

### `rooms:permissions:revoke`

```bash
occ sendentsynchroniser:rooms:permissions:revoke 7
```

The `perm-id` is the numeric id from `permissions:list`.

---

## Bindings

A **binding** links a Sendent-Sync room to one external service. Today only `kind=exchange` is registered; the architecture is generic and future kinds (e.g. `google`) plug in via PHP without schema changes.

### `rooms:bind` (license-gated)

```bash
sudo -u www-data php occ sendentsynchroniser:rooms:bind boardroom-a exchange boardroom-a@contoso.com
```

| Argument | Description |
|---|---|
| `room-id` | Existing Sendent-Sync room |
| `kind` | Today: `exchange` |
| `external-id` | Kind-specific. For Exchange: room mailbox SMTP/UPN. |

**License:** requires `rooms.sync` entitlement on the active Sendent-Sync license. Without it, the command exits with code `4` and a `Room sync requires a Sendent Sync license.` message.

**Side effects on success:**
- `linkVersion` set to 1 (or incremented if the room already had a different binding).
- `state` set to `pending`.
- `initialSyncRequested` set to `true`.
- The NC Exchange Connector picks the change up on its next `GET /rooms` poll and starts the initial pull from Exchange.

### `rooms:unbind` (no license check)

```bash
sudo -u www-data php occ sendentsynchroniser:rooms:unbind boardroom-a
```

Drops the binding row. The room reverts to unbound semantics on the next iTIP/CalDAV write. CalDAV events that were mirrored from Exchange are **kept** (they become local-only history); the Connector will GC its per-room state on next poll.

### `rooms:retry-sync` (no license check)

```bash
sudo -u www-data php occ sendentsynchroniser:rooms:retry-sync boardroom-a
```

Bumps `linkVersion`, resets `state` to `pending`, sets `initialSyncRequested=true`. Use after an admin fixes a misconfigured mailbox in Exchange (the Connector reported `state: failed` previously) — this signals the Connector to redo the initial pull.

---

## Common workflows

### Bulk-create rooms from a CSV

```bash
#!/usr/bin/env bash
# rooms.csv: id,name,capacity,group
while IFS=, read -r id name capacity group; do
    [[ "$id" == "id" ]] && continue   # skip header
    sudo -u www-data php occ sendentsynchroniser:rooms:create \
        "$id" "$name" --capacity="$capacity" --group="$group"
done < rooms.csv
```

### Provision a new bound room end-to-end

```bash
sudo -u www-data php occ sendentsynchroniser:rooms:create boardroom-a "Boardroom A" --capacity=12
sudo -u www-data php occ sendentsynchroniser:rooms:bind boardroom-a exchange boardroom-a@contoso.com
sudo -u www-data php occ sendentsynchroniser:rooms:permissions:grant boardroom-a booker user alice
sudo -u www-data php occ sendentsynchroniser:rooms:permissions:grant boardroom-a manager user bob
```

### Audit and clean up

```bash
# Find rooms with failed sync state
sudo -u www-data php occ sendentsynchroniser:rooms:list \
    | awk '/exchange:failed/{print $2}'

# Retry sync on each
for id in $(occ sendentsynchroniser:rooms:list | awk '/exchange:failed/{print $2}'); do
    sudo -u www-data php occ sendentsynchroniser:rooms:retry-sync "$id"
done
```

### Decommission a room

```bash
# Soft-disable first (rooms remain in DB; bookings still in calendar)
sudo -u www-data php occ sendentsynchroniser:rooms:update boardroom-a --active=false

# When ready to remove permanently:
sudo -u www-data php occ sendentsynchroniser:rooms:unbind boardroom-a       # if bound
sudo -u www-data php occ sendentsynchroniser:rooms:delete boardroom-a -f
```

---

## Notes

- All commands are **idempotent on read** (`list`, `show`) and validate input before mutating.
- `update` and `delete` operate on a single room — there is no bulk variant. Compose with shell loops as shown in the workflow examples.
- Commands consume the same `RoomService`, `BindingService`, `PermissionService` as the REST API and the Vue admin UI. Any rule (validation, license gate, cascade behavior) that applies to one applies to all three surfaces.
- Output of `list` commands uses Symfony's `Table` helper. For machine-readable output, use the REST API instead — `GET /api/1.0/rooms` returns JSON.
- The CLI cannot manage **bookings** (CalDAV events on the room calendar). Use the REST API (`GET /rooms/{id}/bookings`, `DELETE /rooms/{id}/bookings/{uid}`) or the Vue UI for that.

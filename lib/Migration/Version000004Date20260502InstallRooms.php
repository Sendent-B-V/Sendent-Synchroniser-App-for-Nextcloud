<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000004Date20260502InstallRooms extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sndntsync_rooms')) {
			$t = $schema->createTable('sndntsync_rooms');
			$t->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('email', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('capacity', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('room_number', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('floor', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('address', Types::TEXT, ['notnull' => false]);
			$t->addColumn('room_type', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'meeting-room']);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('backing_principal_uri', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('backing_calendar_uri', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('group_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['group_id'], 'sndntsync_room_group_idx');
		}

		if (!$schema->hasTable('sndntsync_room_facilities')) {
			$t = $schema->createTable('sndntsync_room_facilities');
			$t->addColumn('id', Types::BIGINT, ['notnull' => true, 'autoincrement' => true]);
			$t->addColumn('room_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('facility', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['room_id', 'facility'], 'sndntsync_room_facility_uniq');
		}

		if (!$schema->hasTable('sndntsync_room_groups')) {
			$t = $schema->createTable('sndntsync_room_groups');
			$t->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('sndntsync_room_permissions')) {
			$t = $schema->createTable('sndntsync_room_permissions');
			$t->addColumn('id', Types::BIGINT, ['notnull' => true, 'autoincrement' => true]);
			$t->addColumn('room_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('group_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('principal_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('principal_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['room_id'], 'sndntsync_perm_room_idx');
			$t->addIndex(['group_id'], 'sndntsync_perm_group_idx');
		}

		if (!$schema->hasTable('sndntsync_room_bindings')) {
			$t = $schema->createTable('sndntsync_room_bindings');
			$t->addColumn('room_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('external_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('config', Types::TEXT, ['notnull' => true, 'default' => '{}']);
			$t->addColumn('link_version', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$t->addColumn('last_synced_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$t->addColumn('initial_sync_requested', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$t->addColumn('last_events_pushed', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_events_pulled', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['room_id']);
			$t->addIndex(['kind'], 'sndntsync_binding_kind_idx');
		}

		return $schema;
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The app's first schema. Created in one step, before the first `occ app:enable`:
 * executed migrations are frozen in oc_migrations by (app, version), so reshaping this
 * file later changes nothing — a further change needs a new file.
 *
 * Every column here has a matching entity property (see lib/Db/); a column without one
 * makes reads fail with 500 while writes keep working.
 */
class Version1000Date20261102120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('todofolder_workspaces')) {
			$table = $schema->createTable('todofolder_workspaces');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 128,
			]);
			// Path relative to the user's files root, e.g. "Tasks/客户A".
			$table->addColumn('folder_path', Types::STRING, [
				'notnull' => true,
				'length' => 4000,
			]);
			$table->addColumn('folder_fileid', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('deck_board_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('deck_stack_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('auto_deck', Types::BOOLEAN, [
				'notnull' => true,
				'default' => false,
			]);
			$table->addColumn('scan_enabled', Types::BOOLEAN, [
				'notnull' => true,
				'default' => true,
			]);
			$table->addColumn('scan_interval', Types::INTEGER, [
				'notnull' => true,
				'default' => 15,
			]);
			$table->addColumn('last_scan_at', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('ai_extra_prompt', Types::TEXT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'todofolder_ws_uid');
			$table->addUniqueIndex(['user_id', 'folder_path'], 'todofolder_ws_folder');
		}

		if (!$schema->hasTable('todofolder_workspace_shares')) {
			$table = $schema->createTable('todofolder_workspace_shares');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('workspace_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('group_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			// 1 = read, 2 = read-write
			$table->addColumn('permission', Types::INTEGER, [
				'notnull' => true,
				'default' => 1,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['workspace_id'], 'todofolder_share_ws');
			$table->addUniqueIndex(['workspace_id', 'group_id'], 'todofolder_share_uniq');
		}

		if (!$schema->hasTable('todofolder_tasks')) {
			$table = $schema->createTable('todofolder_tasks');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('workspace_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('folder_path', Types::STRING, [
				'notnull' => true,
				'length' => 4000,
			]);
			$table->addColumn('folder_fileid', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			// Folder name as it is on disk; the AI title lives in `title`.
			$table->addColumn('folder_name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => false,
				'default' => null,
				'length' => 255,
			]);
			$table->addColumn('summary', Types::TEXT, [
				'notnull' => false,
				'default' => null,
			]);
			// YYYY-MM-DD; a date the AI produced and we normalised.
			$table->addColumn('due_date', Types::STRING, [
				'notnull' => false,
				'default' => null,
				'length' => 32,
			]);
			// 0 = low, 1 = normal, 2 = high, 3 = urgent
			$table->addColumn('priority', Types::INTEGER, [
				'notnull' => true,
				'default' => 1,
			]);
			// pending | analyzing | ready | error | ignored
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'default' => 'pending',
				'length' => 16,
			]);
			$table->addColumn('content_hash', Types::STRING, [
				'notnull' => false,
				'default' => null,
				'length' => 64,
			]);
			$table->addColumn('ai_model', Types::STRING, [
				'notnull' => false,
				'default' => null,
				'length' => 128,
			]);
			$table->addColumn('error_message', Types::TEXT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('deck_card_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('analyzed_at', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['workspace_id'], 'todofolder_task_ws');
			$table->addIndex(['status'], 'todofolder_task_status');
			$table->addUniqueIndex(['workspace_id', 'folder_path'], 'todofolder_task_folder');
		}

		if (!$schema->hasTable('todofolder_task_tags')) {
			$table = $schema->createTable('todofolder_task_tags');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('task_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('tag_name', Types::STRING, [
				'notnull' => true,
				'length' => 128,
			]);
			// Id in oc_systemtag, when the tag was also created there.
			$table->addColumn('systemtag_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			// ai | user
			$table->addColumn('source', Types::STRING, [
				'notnull' => true,
				'default' => 'ai',
				'length' => 8,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['task_id'], 'todofolder_tag_task');
			$table->addUniqueIndex(['task_id', 'tag_name'], 'todofolder_tag_uniq');
		}

		return $schema;
	}
}

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
 * Deck columns become categories: a task keeps the column it currently sits in, the column the
 * model suggested while analysing, and the workspace remembers how classification is configured
 * (when to classify, where a finished card goes, which columns may be picked, what each column
 * means for the task state).
 */
class Version1300Date20261110130000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('todofolder_tasks') || !$schema->hasTable('todofolder_workspaces')) {
			return null;
		}

		$tasks = $schema->getTable('todofolder_tasks');
		if (!$tasks->hasColumn('deck_stack_id')) {
			$tasks->addColumn('deck_stack_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$tasks->addColumn('stack_source', Types::STRING, [
				'notnull' => false,
				'length' => 16,
				'default' => null,
			]);
			$tasks->addColumn('suggested_stack_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$tasks->addColumn('suggested_stack_name', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$tasks->addColumn('suggested_stack_reason', Types::STRING, [
				'notnull' => false,
				'length' => 255,
				'default' => null,
			]);
			$tasks->addColumn('started_at', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
		}

		$workspaces = $schema->getTable('todofolder_workspaces');
		if (!$workspaces->hasColumn('deck_classify')) {
			$workspaces->addColumn('deck_inbox_stack_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$workspaces->addColumn('deck_classify', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$workspaces->addColumn('deck_classify_timing', Types::STRING, [
				'notnull' => false,
				'length' => 16,
				'default' => 'on_done',
			]);
			$workspaces->addColumn('deck_done_target', Types::STRING, [
				'notnull' => false,
				'length' => 16,
				'default' => 'category',
			]);
			$workspaces->addColumn('deck_category_stacks', Types::TEXT, [
				'notnull' => false,
				'default' => null,
			]);
			$workspaces->addColumn('deck_state_map', Types::TEXT, [
				'notnull' => false,
				'default' => null,
			]);
			$workspaces->addColumn('deck_completion_stack_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$workspaces->addColumn('deck_reconcile_interval', Types::INTEGER, [
				'notnull' => false,
				'default' => 15,
			]);
		}

		return $schema;
	}
}

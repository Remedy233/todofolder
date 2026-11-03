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
 * Remembers which fields a human edited, so a later analysis does not overwrite them.
 *
 * The column holds a comma-separated list of column names ("title,due_date"); a text
 * column rather than a boolean per field keeps the schema stable as fields are added.
 */
class Version1200Date20261102230000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('todofolder_tasks')) {
			return null;
		}

		$table = $schema->getTable('todofolder_tasks');
		if ($table->hasColumn('locked_fields')) {
			return null;
		}

		$table->addColumn('locked_fields', Types::TEXT, [
			'notnull' => false,
			'default' => null,
		]);

		return $schema;
	}
}

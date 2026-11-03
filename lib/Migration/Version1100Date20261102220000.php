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
 * Adds the received date: the day the task folder first showed up. It defaults to the
 * day of the first scan, is editable, and travels to Deck as the card's start date.
 *
 * A new file rather than an edit: executed migrations are frozen in oc_migrations by
 * (app, version), so changing the first one would change nothing on an installed system.
 */
class Version1100Date20261102220000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('todofolder_tasks')) {
			return null;
		}

		$table = $schema->getTable('todofolder_tasks');
		if ($table->hasColumn('received_at')) {
			return null;
		}

		// Nullable on purpose: rows created before this migration have no received date,
		// and a NOT NULL column without a default cannot be added to a populated table.
		$table->addColumn('received_at', Types::STRING, [
			'notnull' => false,
			'default' => null,
			'length' => 32,
		]);

		return $schema;
	}
}

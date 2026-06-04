<?php

use App\modules\phpgwapi\services\Migration\Migration;

/**
 * Add indexes for the hottest queries identified from pg_stat_statements.
 *
 * Every targeted table previously had only its primary key indexed, forcing
 * sequential scans on foreign-key joins and filter columns. Each index below
 * maps to a specific slow query from the production statistics dump.
 */
return new class extends Migration
{
	public string $description = 'Add performance indexes for booking hot queries';

	public function up(): void
	{
		// bb_application: status + session lookup (largest total cost), case
		// officer joins/filters, and status+created dashboard queries.
		$this->addIndex('bb_application', 'idx_bb_application_session_status', ['session_id', 'status']);
		$this->addIndex('bb_application', 'idx_bb_application_case_officer_id', 'case_officer_id');
		$this->addIndex('bb_application', 'idx_bb_application_status_created', ['status', 'created']);

		// bb_allocation_resource: resource_id is the second column of the
		// (allocation_id, resource_id) PK, so "resource_id IN (...)" can't use it.
		$this->addIndex('bb_allocation_resource', 'idx_bb_allocation_resource_resource_id', 'resource_id');

		// bb_allocation: foreign-key joins and date-range/availability filters.
		$this->addIndex('bb_allocation', 'idx_bb_allocation_season_id', 'season_id');
		$this->addIndex('bb_allocation', 'idx_bb_allocation_organization_id', 'organization_id');
		$this->addIndex('bb_allocation', 'idx_bb_allocation_active_from_to', ['active', 'from_', 'to_']);
		$this->addIndex('bb_allocation', 'idx_bb_allocation_completed_to', ['completed', 'to_']);

		// bb_event: anti-join from bb_application (LEFT JOIN ... WHERE id IS NULL).
		$this->addIndex('bb_event', 'idx_bb_event_application_id', 'application_id');

		// bb_event_comment: per-event comment fetch ordered by time (high call count).
		$this->addIndex('bb_event_comment', 'idx_bb_event_comment_event_id_time', ['event_id', 'time']);
	}
};

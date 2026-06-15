<?php

use App\modules\phpgwapi\services\Migration\Migration;

/**
 * Converge index set with the updated production analysis (2026-06-11).
 *
 * - Drops the covering INCLUDE indexes — the partial (application_id, from_)
 *   indexes cover the same queries at a fraction of the size.
 * - Drops indexes superseded by renamed/extended replacements below.
 * - Adds new indexes for foreign-key joins and hot filter columns.
 *
 * All steps are idempotent, so this is safe on databases where the legacy
 * tables_update has already created the same indexes.
 */
return new class extends Migration
{
	public string $description = 'Update performance indexes: drop covering indexes, add FK/filter indexes';

	public function up(): void
	{
		// Covering indexes are out — superseded by the smaller partial indexes.
		$this->dropIndex('bb_booking', 'idx_booking_covering');
		$this->dropIndex('bb_allocation', 'idx_allocation_covering');
		$this->dropIndex('bb_event', 'idx_event_covering');

		// Superseded by renamed/extended replacements created below.
		$this->dropIndex('bb_application', 'idx_bb_application_case_officer_id');
		$this->dropIndex('bb_application', 'idx_bb_application_status_created');
		$this->dropIndex('bb_allocation_resource', 'idx_bb_allocation_resource_resource_id');

		// idx_bb_allocation_active_from_to gained a trailing id column. Same
		// name, new definition — rebuild only if the existing index lacks id.
		$this->db->query(
			"SELECT indexdef FROM pg_indexes "
			. "WHERE tablename = 'bb_allocation' AND indexname = 'idx_bb_allocation_active_from_to'",
			__LINE__,
			__FILE__
		);
		if ($this->db->next_record() && strpos($this->db->Record['indexdef'], ', id)') === false) {
			$this->dropIndex('bb_allocation', 'idx_bb_allocation_active_from_to');
		}

		// Renamed/extended replacements for the indexes dropped above.
		$this->addIndex('bb_application', 'idx_bb_application_case_officer', 'case_officer_id');
		$this->addIndex('bb_application', 'idx_bb_application_status_id_desc', ['status', 'id DESC']);
		$this->addIndex('bb_allocation_resource', 'idx_ar_resource_allocation', ['resource_id', 'allocation_id']);
		$this->addIndex('bb_allocation', 'idx_bb_allocation_active_from_to', ['active', 'from_', 'to_', 'id']);

		// Full application_id index on bb_event — unlike the partial index it
		// also serves scans that don't match the IS NOT NULL predicate.
		$this->addIndex('bb_event', 'idx_bb_event_application_id_only', 'application_id');

		// Foreign-key join and hot filter columns.
		$this->addIndex('bb_application_resource', 'idx_ar_application_id', 'application_id');
		$this->addIndex('bb_building_resource', 'idx_br_resource_id', 'resource_id');
		$this->addIndex('bb_permission', 'idx_permission_object_type_id_subject', ['object_type', 'object_id', 'subject_id']);
		$this->addIndex('bb_application_date', 'idx_ad_app_from_to', ['application_id', 'from_', 'to_']);
		$this->addIndex('bb_season', 'idx_bb_season_active_status', ['active', 'status', 'id']);
	}
};

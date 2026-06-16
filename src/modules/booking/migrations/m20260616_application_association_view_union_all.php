<?php

use App\modules\phpgwapi\services\Migration\Migration;

/**
 * Recreate bb_application_association with UNION ALL instead of UNION.
 *
 * The branches select from disjoint tables (booking/allocation/event) with a
 * distinct `type` literal each, and `id` is unique within every branch, so no
 * duplicate rows can ever exist across the union. UNION therefore only adds a
 * needless dedup (sort/hash) over the full result set. UNION ALL skips it and
 * is strictly faster — matching the hand-tuned view already in production.
 */
return new class extends Migration
{
	public string $description = 'Recreate bb_application_association view using UNION ALL';

	public function up(): void
	{
		if ($this->tableExists('bb_booking') && $this->tableExists('bb_allocation') && $this->tableExists('bb_event')) {
			$this->sql("DROP VIEW IF EXISTS bb_application_association");
			$this->sql(
				"CREATE OR REPLACE VIEW bb_application_association AS "
				. "SELECT 'booking' AS type, application_id, id, from_, to_, cost, active FROM bb_booking WHERE application_id IS NOT NULL "
				. "UNION ALL "
				. "SELECT 'allocation' AS type, application_id, id, from_, to_, cost, active FROM bb_allocation WHERE application_id IS NOT NULL "
				. "UNION ALL "
				. "SELECT 'event' AS type, application_id, id, from_, to_, cost, active FROM bb_event WHERE application_id IS NOT NULL"
			);
		}
	}
};

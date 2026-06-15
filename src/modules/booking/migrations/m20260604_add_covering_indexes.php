<?php

use App\modules\phpgwapi\services\Migration\Migration;

/**
 * Covering indexes on (application_id, from_) INCLUDE (id, to_, cost, active)
 * for the reservation tables, enabling index-only scans for queries that
 * filter/join by application_id and read those columns without a heap fetch.
 */
return new class extends Migration
{
	public string $description = 'Add covering indexes on reservation tables';

	public function up(): void
	{
		$include = ['id', 'to_', 'cost', 'active'];

		$this->addIndex('bb_booking', 'idx_booking_covering', ['application_id', 'from_'], false, null, $include);
		$this->addIndex('bb_allocation', 'idx_allocation_covering', ['application_id', 'from_'], false, null, $include);
		$this->addIndex('bb_event', 'idx_event_covering', ['application_id', 'from_'], false, null, $include);
	}
};

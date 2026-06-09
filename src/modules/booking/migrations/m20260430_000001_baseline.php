<?php

use App\modules\phpgwapi\services\Migration\Migration;

/**
 * Baseline marker for the booking module.
 *
 * The actual schema is built up by migrations 000002-000230 which
 * replay the full upgrade history from v0.1 onwards. This baseline
 * exists only as a tracking anchor for the migration system.
 */
return new class extends Migration
{
	public string $description = 'Booking migration baseline marker';

	public function up(): void
	{
		// No-op. Schema is built by subsequent migrations.
	}
};

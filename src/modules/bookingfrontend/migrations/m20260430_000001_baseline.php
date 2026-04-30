<?php

use App\modules\phpgwapi\services\Migration\Migration;

/**
 * Baseline migration for the bookingfrontend module.
 *
 * Bookingfrontend has no database tables of its own — this migration
 * exists to establish the migration tracking baseline.
 */
return new class extends Migration
{
	public string $description = 'Establish bookingfrontend migration baseline';

	public array $depends = [
		'booking' => 'm20260430_000001_baseline.php',
	];

	public function up(): void
	{
		// Bookingfrontend has no tables. This migration marks the baseline.
	}
};

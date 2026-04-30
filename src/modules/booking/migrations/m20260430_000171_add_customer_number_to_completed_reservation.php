<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add customer_number to bb_completed_reservation and populate from organization';

	public function up(): void
	{
		$this->ensureColumn('bb_completed_reservation', 'customer_number', [
			'type' => 'text', 'nullable' => true,
		]);

		// Populate customer_number from organization if not already set
		if ($this->tableExists('bb_organization'))
		{
			$this->sql(
				"UPDATE bb_completed_reservation SET customer_number = bb_organization.customer_number"
				. " FROM bb_organization"
				. " WHERE bb_completed_reservation.organization_id = bb_organization.id"
				. " AND bb_completed_reservation.customer_number IS NULL"
			);
		}

		// Async task registration is legacy setup and not migrated here
	}
};

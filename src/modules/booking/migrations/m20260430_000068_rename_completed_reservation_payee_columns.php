<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Rename payee columns to customer columns on bb_completed_reservation';

	public function up(): void
	{
		$this->renameColumn('bb_completed_reservation', 'payee_type', 'customer_type');
		$this->renameColumn('bb_completed_reservation', 'payee_organization_number', 'customer_organization_number');
		$this->renameColumn('bb_completed_reservation', 'payee_ssn', 'customer_ssn');
	}
};

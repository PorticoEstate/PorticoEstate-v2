<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add customer_number and customer identifier columns to organization, application, and event';

	public function up(): void
	{
		// bb_organization
		$this->ensureColumn('bb_organization', 'customer_number', ['type' => 'text', 'nullable' => true]);
		$this->ensureColumn('bb_organization', 'customer_identifier_type', ['type' => 'varchar', 'precision' => '255', 'nullable' => true]);
		$this->ensureColumn('bb_organization', 'customer_organization_number', ['type' => 'varchar', 'precision' => '9', 'nullable' => true]);
		$this->ensureColumn('bb_organization', 'customer_ssn', ['type' => 'varchar', 'precision' => '12', 'nullable' => true]);

		// bb_application
		$this->ensureColumn('bb_application', 'customer_identifier_type', ['type' => 'varchar', 'precision' => '255', 'nullable' => true]);
		$this->ensureColumn('bb_application', 'customer_organization_number', ['type' => 'varchar', 'precision' => '9', 'nullable' => true]);
		$this->ensureColumn('bb_application', 'customer_ssn', ['type' => 'varchar', 'precision' => '12', 'nullable' => true]);

		// bb_event
		$this->ensureColumn('bb_event', 'customer_identifier_type', ['type' => 'varchar', 'precision' => '255', 'nullable' => true]);
		$this->ensureColumn('bb_event', 'customer_organization_number', ['type' => 'varchar', 'precision' => '9', 'nullable' => true]);
		$this->ensureColumn('bb_event', 'customer_ssn', ['type' => 'varchar', 'precision' => '12', 'nullable' => true]);
	}
};

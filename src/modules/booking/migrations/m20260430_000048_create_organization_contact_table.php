<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop admin columns from bb_organization and create bb_organization_contact table';

	public function up(): void
	{
		$this->dropColumn('bb_organization', 'admin_primary');
		$this->dropColumn('bb_organization', 'admin_secondary');

		$this->createTable('bb_organization_contact', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false, 'default' => ''],
				'ssn' => ['type' => 'varchar', 'precision' => '12', 'nullable' => false, 'default' => ''],
				'phone' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false, 'default' => ''],
				'email' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false, 'default' => ''],
				'organization_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_organization' => ['organization_id' => 'id'],
			],
			'ix' => ['ssn'],
			'uc' => [],
		]);
	}
};

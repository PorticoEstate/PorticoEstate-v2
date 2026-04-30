<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop contact columns from bb_group and create bb_group_contact table';

	public function up(): void
	{
		$this->dropColumn('bb_group', 'contact_primary');
		$this->dropColumn('bb_group', 'contact_secondary');

		$this->createTable('bb_group_contact', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false, 'default' => ''],
				'phone' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false, 'default' => ''],
				'email' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false, 'default' => ''],
				'group_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_group' => ['group_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};

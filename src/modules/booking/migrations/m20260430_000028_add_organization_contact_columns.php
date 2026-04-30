<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add phone, email, and description columns to bb_organization';

	public function up(): void
	{
		$this->ensureColumn('bb_organization', 'phone', [
			'type' => 'varchar',
			'precision' => '250',
			'nullable' => false,
			'default' => '',
		]);
		$this->ensureColumn('bb_organization', 'email', [
			'type' => 'varchar',
			'precision' => '250',
			'nullable' => false,
			'default' => '',
		]);
		$this->ensureColumn('bb_organization', 'description', [
			'type' => 'varchar',
			'precision' => '1000',
			'nullable' => false,
			'default' => '',
		]);
	}
};

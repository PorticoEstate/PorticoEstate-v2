<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop address/phone/email from bb_resource and add description column';

	public function up(): void
	{
		$this->dropColumn('bb_resource', 'address');
		$this->dropColumn('bb_resource', 'phone');
		$this->dropColumn('bb_resource', 'email');
		$this->ensureColumn('bb_resource', 'description', [
			'type' => 'varchar',
			'precision' => '1000',
			'nullable' => false,
			'default' => '',
		]);
	}
};

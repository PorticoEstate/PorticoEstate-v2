<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add parent_id, capacity, e_lock to bb_rescategory and capacity to bb_resource';

	public function up(): void
	{
		$this->ensureColumn('bb_rescategory', 'parent_id', [
			'type' => 'int', 'precision' => 4, 'nullable' => true,
		]);
		$this->ensureColumn('bb_rescategory', 'capacity', [
			'type' => 'int', 'precision' => 2, 'nullable' => true,
		]);
		$this->ensureColumn('bb_rescategory', 'e_lock', [
			'type' => 'int', 'precision' => 2, 'nullable' => true,
		]);
		$this->ensureColumn('bb_resource', 'capacity', [
			'type' => 'int', 'precision' => 4, 'nullable' => true,
		]);
	}
};

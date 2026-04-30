<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add agreement_requirements column to bb_application';

	public function up(): void
	{
		$this->ensureColumn('bb_application', 'agreement_requirements', [
			'type' => 'text', 'nullable' => true,
		]);
	}
};

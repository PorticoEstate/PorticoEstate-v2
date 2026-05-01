<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add owner_id, modified_on, modified_by with FK constraints to bb_resource_activity_entityform';

	public function up(): void
	{
		$this->ensureColumn('bb_resource_activity_entityform', 'owner_id', [
			'type' => 'int',
			'precision' => 4,
			'nullable' => false,
		]);

		$this->ensureColumn('bb_resource_activity_entityform', 'modified_on', [
			'type' => 'timestamp',
			'nullable' => false,
			'default' => 'current_timestamp',
		]);

		$this->ensureColumn('bb_resource_activity_entityform', 'modified_by', [
			'type' => 'int',
			'precision' => 4,
			'nullable' => false,
		]);

		if (!$this->constraintExists('bb_resource_activity_entityform', 'fk_entityform_owner')) {
			$this->sql(
				"ALTER TABLE bb_resource_activity_entityform "
				. "ADD CONSTRAINT fk_entityform_owner FOREIGN KEY (owner_id) REFERENCES phpgw_accounts(account_id)"
			);
		}

		if (!$this->constraintExists('bb_resource_activity_entityform', 'fk_entityform_modified_by')) {
			$this->sql(
				"ALTER TABLE bb_resource_activity_entityform "
				. "ADD CONSTRAINT fk_entityform_modified_by FOREIGN KEY (modified_by) REFERENCES phpgw_accounts(account_id)"
			);
		}
	}
};

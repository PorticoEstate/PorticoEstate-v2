<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Alter object_number and responsible_code column sizes in bb_account_code_set';

	public function up(): void
	{
		if ($this->columnExists('bb_account_code_set', 'object_number'))
		{
			$currentType = $this->getColumnType('bb_account_code_set', 'object_number');
			if ($currentType !== null)
			{
				$this->sql("ALTER TABLE bb_account_code_set ALTER COLUMN object_number TYPE varchar(8)");
				if (!$this->isNullable('bb_account_code_set', 'object_number'))
				{
					$this->sql("ALTER TABLE bb_account_code_set ALTER COLUMN object_number DROP NOT NULL");
				}
			}
		}

		if ($this->columnExists('bb_account_code_set', 'responsible_code'))
		{
			$currentType = $this->getColumnType('bb_account_code_set', 'responsible_code');
			if ($currentType !== null)
			{
				$this->sql("ALTER TABLE bb_account_code_set ALTER COLUMN responsible_code TYPE varchar(6)");
				if (!$this->isNullable('bb_account_code_set', 'responsible_code'))
				{
					$this->sql("ALTER TABLE bb_account_code_set ALTER COLUMN responsible_code DROP NOT NULL");
				}
			}
		}
	}
};

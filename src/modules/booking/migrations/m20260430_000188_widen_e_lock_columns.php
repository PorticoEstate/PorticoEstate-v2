<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Delete null e_lock_name rows and widen e_lock_resource_id and e_lock_name to varchar(200), set not null';

    public function up(): void
    {
        $this->sql("DELETE FROM bb_resource_e_lock WHERE e_lock_name IS NULL");

        if ($this->columnExists('bb_resource_e_lock', 'e_lock_resource_id')) {
            $this->sql("ALTER TABLE bb_resource_e_lock ALTER COLUMN e_lock_resource_id TYPE varchar(200)");
            if ($this->isNullable('bb_resource_e_lock', 'e_lock_resource_id')) {
                $this->sql("ALTER TABLE bb_resource_e_lock ALTER COLUMN e_lock_resource_id SET NOT NULL");
            }
        }

        if ($this->columnExists('bb_resource_e_lock', 'e_lock_name')) {
            $this->sql("ALTER TABLE bb_resource_e_lock ALTER COLUMN e_lock_name TYPE varchar(200)");
            if ($this->isNullable('bb_resource_e_lock', 'e_lock_name')) {
                $this->sql("ALTER TABLE bb_resource_e_lock ALTER COLUMN e_lock_name SET NOT NULL");
            }
        }
    }
};

<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Update bb_application building_name from bb_building via resource links';

    public function up(): void
    {
        // Only update rows that still have the placeholder value (idempotent)
        if ($this->tableExists('bb_application') && $this->columnExists('bb_application', 'building_name')
            && $this->tableExists('bb_building') && $this->tableExists('bb_application_resource') && $this->tableExists('bb_resource')
            && $this->columnExists('bb_resource', 'building_id')) {
            $this->sql("UPDATE bb_application SET building_name = b2.name FROM bb_building b2 WHERE EXISTS (SELECT 1 FROM bb_building b, bb_application a, bb_application_resource ar, bb_resource r WHERE a.id = ar.application_id AND ar.resource_id = r.id AND r.building_id = b.id AND b2.id = b.id AND bb_application.id = a.id) AND bb_application.building_name = 'changeme'");
        }
    }
};

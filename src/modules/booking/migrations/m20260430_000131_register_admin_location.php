<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Register .admin location for booking module';

    public function up(): void
    {
        $location_obj = new \App\modules\phpgwapi\security\Locations();
        try {
            $location_obj->add('.admin', 'Admin section', 'booking');
        } catch (\Exception $e) {
            // Location may already exist
        }
    }
};

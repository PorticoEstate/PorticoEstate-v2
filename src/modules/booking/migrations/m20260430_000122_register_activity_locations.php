<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Register application and resource system locations per top-level activity';

    public function up(): void
    {
        // Fetch top-level activities (no parent)
        $db = \App\Database\Db::getInstance();
        $db->query("SELECT id, name FROM bb_activity WHERE parent_id IS NULL OR parent_id = 0 ORDER BY id");

        $activities = [];
        while ($db->next_record()) {
            $activities[] = [
                'id' => $db->f('id'),
                'name' => $db->f('name'),
            ];
        }

        $location_obj = new \App\modules\phpgwapi\security\Locations();

        foreach ($activities as $activity) {
            $appLocation = ".application.{$activity['id']}";
            $resLocation = ".resource.{$activity['id']}";

            try {
                $location_obj->add($appLocation, $activity['name'], 'booking', false, null, false, true);
            } catch (\Exception $e) {
                // Location may already exist
            }

            try {
                $location_obj->add($resLocation, $activity['name'], 'booking', false, null, false, true);
            } catch (\Exception $e) {
                // Location may already exist
            }
        }
    }
};

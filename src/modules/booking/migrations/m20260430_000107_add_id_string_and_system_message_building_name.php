<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add id_string columns to event, allocation, application and building_name to system_message';

    public function up(): void
    {
        $this->ensureColumn('bb_event', 'id_string', [
            'type' => 'varchar',
            'precision' => 20,
            'nullable' => false,
            'default' => '0',
        ]);
        if ($this->columnExists('bb_event', 'id_string')) {
            $this->sql("UPDATE bb_event SET id_string = cast(id AS varchar) WHERE id_string = '0'");
        }

        $this->ensureColumn('bb_allocation', 'id_string', [
            'type' => 'varchar',
            'precision' => 20,
            'nullable' => false,
            'default' => '0',
        ]);
        if ($this->columnExists('bb_allocation', 'id_string')) {
            $this->sql("UPDATE bb_allocation SET id_string = cast(id AS varchar) WHERE id_string = '0'");
        }

        $this->ensureColumn('bb_application', 'id_string', [
            'type' => 'varchar',
            'precision' => 20,
            'nullable' => false,
            'default' => '0',
        ]);
        if ($this->columnExists('bb_application', 'id_string')) {
            $this->sql("UPDATE bb_application SET id_string = cast(id AS varchar) WHERE id_string = '0'");
        }

        $this->ensureColumn('bb_system_message', 'building_name', [
            'type' => 'varchar',
            'precision' => 50,
            'nullable' => false,
            'default' => 'changeme',
        ]);
        if ($this->columnExists('bb_system_message', 'building_name')) {
            $this->sql("UPDATE bb_system_message SET building_name = b2.name FROM bb_building b2 WHERE EXISTS (SELECT 1 FROM bb_building b, bb_system_message a WHERE a.building_id = b.id AND b2.id = b.id AND bb_system_message.id = a.id) AND bb_system_message.building_name = 'changeme'");
        }
    }
};

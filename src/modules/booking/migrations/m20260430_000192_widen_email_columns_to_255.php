<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Widen email columns to varchar(255) across multiple booking tables';

    public function up(): void
    {
        $columns = [
            ['bb_building', 'email'],
            ['bb_building', 'tilsyn_email'],
            ['bb_building', 'tilsyn_email2'],
            ['bb_contact_person', 'email'],
            ['bb_organization', 'email'],
            ['bb_user', 'email'],
            ['bb_delegate', 'email'],
            ['bb_organization_contact', 'email'],
            ['bb_group_contact', 'email'],
            ['bb_event', 'contact_email'],
            ['bb_system_message', 'email'],
            ['bb_participant', 'email'],
        ];

        foreach ($columns as [$table, $column]) {
            if ($this->columnExists($table, $column)) {
                $this->sql("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE varchar(255)");
            }
        }
    }
};

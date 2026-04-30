<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Alter description column to text type on bb_application and bb_event';

    public function up(): void
    {
        if ($this->columnExists('bb_application', 'description') && $this->getColumnType('bb_application', 'description') !== 'text') {
            $this->sql("ALTER TABLE bb_application ALTER COLUMN description TYPE text");
        }

        if ($this->columnExists('bb_event', 'description') && $this->getColumnType('bb_event', 'description') !== 'text') {
            $this->sql("ALTER TABLE bb_event ALTER COLUMN description TYPE text");
        }
    }
};

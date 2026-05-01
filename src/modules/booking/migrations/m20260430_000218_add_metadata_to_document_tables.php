<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add metadata jsonb column to document tables for focal points and future metadata';

    public function up(): void
    {
        $tables = [
            'bb_document_building',
            'bb_document_resource',
            'bb_document_application',
            'bb_document_organization',
        ];

        foreach ($tables as $table) {
            $this->ensureColumn($table, 'metadata', [
                'type' => 'jsonb',
                'nullable' => true,
            ]);
        }
    }
};

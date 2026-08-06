<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add deactivate_in_frontend to bb_hospitality_article (hide catering article from the frontend/public, mirroring bb_article_mapping.deactivate_in_frontend)';

    public function up(): void
    {
        $this->ensureColumn('bb_hospitality_article', 'deactivate_in_frontend', [
            'type' => 'int',
            'precision' => 2,
            'nullable' => true,
        ]);
    }
};

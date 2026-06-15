<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Rename percent to percent_ in bb_article_price_reduction to avoid reserved word conflict';

    public function up(): void
    {
        $this->renameColumn('bb_article_price_reduction', 'percent', 'percent_');
    }
};

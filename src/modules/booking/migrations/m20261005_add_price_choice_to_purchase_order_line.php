<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add article_price_id and price_label to bb_purchase_order_line (ref #711). A resource can have several active prices, and the citizen chooses which one applies when booking. article_price_id records the bb_article_price row the line was priced from; price_label snapshots what the citizen chose (the remark, or the amount when the remark is empty) and is set only when there was a choice. Both are NULL on every pre-existing row, meaning no choice was recorded. No foreign key on article_price_id: the admin deletes superseded prices outright, and a key would either block that or rewrite order lines, so the label is the durable record.';

    public function up(): void
    {
        $this->assertTableExists('bb_purchase_order_line');

        $this->ensureColumn('bb_purchase_order_line', 'article_price_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_purchase_order_line', 'price_label', [
            'type' => 'varchar',
            'precision' => 100,
            'nullable' => true,
        ]);
    }
};

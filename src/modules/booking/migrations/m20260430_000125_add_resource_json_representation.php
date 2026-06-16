<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add json_representation column to bb_resource and create jsonb operators';

    public function up(): void
    {
        $this->ensureColumn('bb_resource', 'json_representation', [
            'type' => 'jsonb',
            'nullable' => true,
        ]);

        // Create custom jsonb operators if they don't already exist
        $db = \App\Database\Db::getInstance();

        try {
            $db->query("SELECT 1 FROM pg_operator WHERE oprname = '~@' AND oprleft = 'jsonb'::regtype LIMIT 1");
            if (!$db->next_record()) {
                $this->sql("CREATE OPERATOR ~@ (LEFTARG = jsonb, RIGHTARG = text, PROCEDURE = jsonb_exists)");
            }
        } catch (\Exception $e) {
            // Operator may already exist
        }

        try {
            $db->query("SELECT 1 FROM pg_operator WHERE oprname = '~@|' AND oprleft = 'jsonb'::regtype LIMIT 1");
            if (!$db->next_record()) {
                $this->sql("CREATE OPERATOR ~@| (LEFTARG = jsonb, RIGHTARG = text[], PROCEDURE = jsonb_exists_any)");
            }
        } catch (\Exception $e) {
            // Operator may already exist
        }

        try {
            $db->query("SELECT 1 FROM pg_operator WHERE oprname = '~@&' AND oprleft = 'jsonb'::regtype LIMIT 1");
            if (!$db->next_record()) {
                $this->sql("CREATE OPERATOR ~@& (LEFTARG = jsonb, RIGHTARG = text[], PROCEDURE = jsonb_exists_all)");
            }
        } catch (\Exception $e) {
            // Operator may already exist
        }
    }
};

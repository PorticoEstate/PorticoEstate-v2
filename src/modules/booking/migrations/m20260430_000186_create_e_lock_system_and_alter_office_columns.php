<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_e_lock_system table with seed data and alter office/office_user date columns to nullable';

    public function up(): void
    {
        $this->createTable('bb_e_lock_system', [
            'fd' => [
                'id' => ['type' => 'auto', 'nullable' => false],
                'name' => ['type' => 'varchar', 'precision' => 200, 'nullable' => false],
                'instruction' => ['type' => 'text', 'nullable' => true],
                'sms_alert' => ['type' => 'int', 'precision' => 2, 'nullable' => true],
                'user_id' => ['type' => 'int', 'precision' => 8, 'nullable' => true],
                'entry_date' => ['type' => 'int', 'precision' => 8, 'nullable' => true],
                'modified_date' => ['type' => 'int', 'precision' => 8, 'nullable' => true],
            ],
            'pk' => ['id'],
            'fk' => [],
            'ix' => [],
            'uc' => [],
        ]);

        $saltoText = '<p>For å få tilgang til adgangskontrollsystemet SALTO - må den ansvarlige for bookingen laste ned en app til telefonen på forhånd.</p>'
            . '<p>Nøkkel vil bli pushet ut til appen ca 10 minutt før avtaletidspunktet.</p>'
            . '<p>Android:</p>'
            . '<a href="https://play.google.com/store/apps/details?id=com.saltosystems.justin&hl=no&gl=US" target="_blank" rel="noreferrer noopener">https://play.google.com/store/apps/details?id=com.saltosystems.justin&hl=no&gl=US</a>'
            . '<p>Apple:</p>'
            . '<a href="https://apps.apple.com/no/app/justin-mobile/id960998088" target="_blank" rel="noreferrer noopener">https://apps.apple.com/no/app/justin-mobile/id960998088</a>';

        $this->sql("INSERT INTO bb_e_lock_system (id, name, sms_alert) SELECT 1, 'STANLEY', 1 WHERE NOT EXISTS (SELECT 1 FROM bb_e_lock_system WHERE id = 1)");
        $this->sql("INSERT INTO bb_e_lock_system (id, name, sms_alert) SELECT 2, 'ARX', 1 WHERE NOT EXISTS (SELECT 1 FROM bb_e_lock_system WHERE id = 2)");
        $this->sql("INSERT INTO bb_e_lock_system (id, name, instruction) SELECT 3, 'SALTO', " . $this->quote($saltoText) . " WHERE NOT EXISTS (SELECT 1 FROM bb_e_lock_system WHERE id = 3)");

        // Alter office columns to nullable
        if ($this->columnExists('bb_office', 'entry_date') && !$this->isNullable('bb_office', 'entry_date')) {
            $this->sql("ALTER TABLE bb_office ALTER COLUMN entry_date DROP NOT NULL");
        }
        if ($this->columnExists('bb_office', 'modified_date') && !$this->isNullable('bb_office', 'modified_date')) {
            $this->sql("ALTER TABLE bb_office ALTER COLUMN modified_date DROP NOT NULL");
        }

        // Alter office_user columns to nullable
        if ($this->columnExists('bb_office_user', 'entry_date') && !$this->isNullable('bb_office_user', 'entry_date')) {
            $this->sql("ALTER TABLE bb_office_user ALTER COLUMN entry_date DROP NOT NULL");
        }
        if ($this->columnExists('bb_office_user', 'modified_date') && !$this->isNullable('bb_office_user', 'modified_date')) {
            $this->sql("ALTER TABLE bb_office_user ALTER COLUMN modified_date DROP NOT NULL");
        }
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
};

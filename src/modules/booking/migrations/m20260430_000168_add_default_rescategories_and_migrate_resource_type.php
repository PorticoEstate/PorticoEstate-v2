<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Insert default Lokale/Utstyr rescategories, link to activities, and migrate resource type column';

	public function up(): void
	{
		// Only run data migration if the type column still exists (not yet migrated)
		if ($this->columnExists('bb_resource', 'type'))
		{
			// Insert default categories if they don't exist by name
			$this->sql(
				"INSERT INTO bb_rescategory (name, capacity, e_lock, active)"
				. " SELECT 'Lokale', 1, 1, 1"
				. " WHERE NOT EXISTS (SELECT 1 FROM bb_rescategory WHERE name = 'Lokale')"
			);
			$this->sql(
				"INSERT INTO bb_rescategory (name, capacity, e_lock, active)"
				. " SELECT 'Utstyr', NULL, NULL, 1"
				. " WHERE NOT EXISTS (SELECT 1 FROM bb_rescategory WHERE name = 'Utstyr')"
			);

			// Fix sequence
			$this->sql("SELECT setval('seq_bb_rescategory', COALESCE((SELECT MAX(id)+1 FROM bb_rescategory), 1), false)");

			// Link new categories to top-level activities
			$this->sql(
				"INSERT INTO bb_rescategory_activity (rescategory_id, activity_id)"
				. " SELECT rc.id, a.id FROM bb_rescategory rc"
				. " CROSS JOIN bb_activity a"
				. " WHERE rc.name = 'Lokale' AND (a.parent_id IS NULL OR a.parent_id = 0)"
				. " AND NOT EXISTS ("
				. "   SELECT 1 FROM bb_rescategory_activity ra WHERE ra.rescategory_id = rc.id AND ra.activity_id = a.id"
				. " )"
			);
			$this->sql(
				"INSERT INTO bb_rescategory_activity (rescategory_id, activity_id)"
				. " SELECT rc.id, a.id FROM bb_rescategory rc"
				. " CROSS JOIN bb_activity a"
				. " WHERE rc.name = 'Utstyr' AND (a.parent_id IS NULL OR a.parent_id = 0)"
				. " AND NOT EXISTS ("
				. "   SELECT 1 FROM bb_rescategory_activity ra WHERE ra.rescategory_id = rc.id AND ra.activity_id = a.id"
				. " )"
			);

			// Migrate type column to rescategory_id
			$this->sql(
				"UPDATE bb_resource SET rescategory_id = (SELECT id FROM bb_rescategory WHERE name = 'Lokale' LIMIT 1)"
				. " WHERE type = 'Location' AND rescategory_id IS NULL"
			);
			$this->sql(
				"UPDATE bb_resource SET rescategory_id = (SELECT id FROM bb_rescategory WHERE name = 'Utstyr' LIMIT 1)"
				. " WHERE type = 'Equipment' AND rescategory_id IS NULL"
			);

			$this->dropColumn('bb_resource', 'type');
		}
	}
};

<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Enforce non-overlapping weekly template allocations per resource';

	public function up(): void
	{
		$this->assertTableExists('bb_wtemplate_alloc');
		$this->assertTableExists('bb_wtemplate_alloc_resource');

		$this->sql('CREATE EXTENSION IF NOT EXISTS btree_gist');

		$this->sql('ALTER TABLE bb_wtemplate_alloc_resource'
			. ' ADD COLUMN IF NOT EXISTS season_id integer,'
			. ' ADD COLUMN IF NOT EXISTS wday integer,'
			. ' ADD COLUMN IF NOT EXISTS from_ time without time zone,'
			. ' ADD COLUMN IF NOT EXISTS to_ time without time zone');

		$this->sql('UPDATE bb_wtemplate_alloc_resource AS resource'
			. ' SET season_id = alloc.season_id, wday = alloc.wday,'
			. ' from_ = alloc.from_, to_ = alloc.to_'
			. ' FROM bb_wtemplate_alloc AS alloc'
			. ' WHERE resource.allocation_id = alloc.id'
			. ' AND (resource.season_id, resource.wday, resource.from_, resource.to_)'
			. ' IS DISTINCT FROM (alloc.season_id, alloc.wday, alloc.from_, alloc.to_)');

		$this->sql('ALTER TABLE bb_wtemplate_alloc_resource'
			. ' ALTER COLUMN season_id SET NOT NULL,'
			. ' ALTER COLUMN wday SET NOT NULL,'
			. ' ALTER COLUMN from_ SET NOT NULL,'
			. ' ALTER COLUMN to_ SET NOT NULL');

		if (!$this->constraintExists('bb_wtemplate_alloc', 'bb_wtemplate_alloc_valid_time_check'))
		{
			$this->sql('ALTER TABLE bb_wtemplate_alloc ADD CONSTRAINT bb_wtemplate_alloc_valid_time_check'
				. ' CHECK (from_ < to_)');
		}

		if (!$this->constraintExists('bb_wtemplate_alloc', 'bb_wtemplate_alloc_id_time_key'))
		{
			$this->sql('ALTER TABLE bb_wtemplate_alloc ADD CONSTRAINT bb_wtemplate_alloc_id_time_key'
				. ' UNIQUE (id, season_id, wday, from_, to_)');
		}

		if (!$this->constraintExists('bb_wtemplate_alloc_resource', 'bb_wtemplate_alloc_resource_time_fkey'))
		{
			$this->sql('ALTER TABLE bb_wtemplate_alloc_resource ADD CONSTRAINT bb_wtemplate_alloc_resource_time_fkey'
				. ' FOREIGN KEY (allocation_id, season_id, wday, from_, to_)'
				. ' REFERENCES bb_wtemplate_alloc (id, season_id, wday, from_, to_) ON UPDATE CASCADE');
		}

		// Existing callers insert only allocation_id and resource_id.
		$this->sql(<<<'SQL'
CREATE OR REPLACE FUNCTION bb_wtemplate_alloc_resource_set_time() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
	SELECT season_id, wday, from_, to_
	INTO NEW.season_id, NEW.wday, NEW.from_, NEW.to_
	FROM bb_wtemplate_alloc
	WHERE id = NEW.allocation_id
	FOR KEY SHARE;

	IF NOT FOUND THEN
		RAISE EXCEPTION 'Weekly template allocation % does not exist', NEW.allocation_id
			USING ERRCODE = 'foreign_key_violation';
	END IF;
	RETURN NEW;
END;
$$
SQL);
		$this->sql('DROP TRIGGER IF EXISTS bb_wtemplate_alloc_resource_set_time ON bb_wtemplate_alloc_resource');
		$this->sql('CREATE TRIGGER bb_wtemplate_alloc_resource_set_time'
			. ' BEFORE INSERT OR UPDATE OF allocation_id ON bb_wtemplate_alloc_resource'
			. ' FOR EACH ROW EXECUTE FUNCTION bb_wtemplate_alloc_resource_set_time()');

		// information_schema.table_constraints does not expose exclusion constraints.
		$this->db->query("SELECT COUNT(*) AS cnt FROM pg_constraint"
			. " WHERE conrelid = 'public.bb_wtemplate_alloc_resource'::regclass"
			. " AND conname = 'bb_wtemplate_alloc_resource_no_overlap'", __LINE__, __FILE__);
		$this->db->next_record();
		if ((int) $this->db->Record['cnt'] === 0)
		{
			// The date is arbitrary: tsrange needs timestamps, but only the times matter.
			// [) includes the start and excludes the end, allowing adjacent allocations.
			$this->sql(<<<'SQL'
ALTER TABLE bb_wtemplate_alloc_resource
	ADD CONSTRAINT bb_wtemplate_alloc_resource_no_overlap
	EXCLUDE USING gist (
		season_id WITH =,
		wday WITH =,
		resource_id WITH =,
		tsrange(DATE '2000-01-01' + from_, DATE '2000-01-01' + to_, '[)') WITH &&
	)
SQL);
		}

		if ($this->constraintExists('bb_wtemplate_alloc', 'bb_wtemplate_alloc_season_id_wday_from__key'))
		{
			$this->sql('ALTER TABLE bb_wtemplate_alloc'
				. ' DROP CONSTRAINT bb_wtemplate_alloc_season_id_wday_from__key');
		}
	}
};

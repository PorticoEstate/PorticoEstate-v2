<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = "Repair bb_application.from_ across every existing row, using the combined minimum: the earliest of the application's OWN dates and the dates of its ACTIVE children (c.parent_id = a.id AND c.id <> a.id AND c.active = 1). This SUPERSEDES the formula in m20260430_000214_add_from_to_application.php, which populated from_ with MIN of the application's own dates only and carried an 'AND from_ IS NULL' guard. Two consequences of that earlier migration are what this one exists to undo: the own-min formula stores a value LATER than the true earliest whenever a child holds an earlier date, and the IS NULL guard means such a row can never be corrected by re-running it — a wrong value is not a missing one. This migration therefore has NO IS NULL guard: it rewrites any row whose stored value differs from the combined minimum, as well as any row where it is missing. It only ever writes a value computed from a real bb_application_date row, and never nulls an existing value, so an application that carries a from_ but has no date rows is left exactly as it is.";

	public function up(): void
	{
		$this->assertTableExists('bb_application');
		$this->assertTableExists('bb_application_date');
		$this->assertColumnExists('bb_application', 'from_');

		// One statement for every shape. It reproduces booking.soapplication::update_from_field()
		// exactly: a row with no children falls back to its own minimum because the child
		// aggregate is NULL, and PostgreSQL's LEAST ignores NULL arguments. Both aggregates
		// NULL means the application has no dates at all, and the IS NOT NULL filter below
		// leaves that row untouched rather than erasing whatever it carries.
		//
		// The WHERE is also what makes this migration idempotent: on a second run no row
		// differs from the computed value any more, so it matches nothing and writes nothing.
		$this->sql(trim("
			UPDATE bb_application a
			SET from_ = s.spec_from
			FROM (
				SELECT p.id, LEAST(own.min_from, kid.min_from) AS spec_from
				FROM bb_application p
				LEFT JOIN (
					SELECT application_id, MIN(from_) AS min_from
					FROM bb_application_date
					GROUP BY application_id
				) own ON own.application_id = p.id
				LEFT JOIN (
					SELECT c.parent_id AS id, MIN(cd.from_) AS min_from
					FROM bb_application c
					JOIN bb_application_date cd ON cd.application_id = c.id
					WHERE c.parent_id IS NOT NULL
					AND c.parent_id <> c.id
					AND c.active = 1
					GROUP BY c.parent_id
				) kid ON kid.id = p.id
			) s
			WHERE a.id = s.id
			AND s.spec_from IS NOT NULL
			AND (a.from_ IS NULL OR a.from_ <> s.spec_from)
		"));
	}
};

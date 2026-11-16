<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Store the hospitality timestamps as naive UTC on every install (ref #1423, ref #1425). bb_hospitality.created/modified, bb_hospitality_order.created/modified and bb_hospitality_order_changelog.changed_at were filled by the database from DEFAULT now(), i.e. in the time zone of the database SESSION: UTC on a laptop and Europe/Oslo on test. The order model and the changelog read them as UTC, so on an Oslo session the admin pages showed every order and case entry one hour late in winter and two in summer. The rows are converted ONCE from the zone of this session to UTC (a no-op on a UTC session), and the five DEFAULTs become (now() AT TIME ZONE \'UTC\'), so a writer that forgets still stores UTC. The code writes modified with DEFAULT instead of NOW() for the same reason: the column default is the one place that decides, so the order in which the code and this migration are deployed does not matter for new-code rows. The whole of up() runs in the runner\'s one transaction with the three tables locked, and the marker is the state of the defaults itself (all five legacy: convert; all five UTC: nothing to do; anything else: abort), so a second run cannot shift the rows again. It ABORTS, changing nothing, when the session zone did not come from the server (libpq PGTZ/PGOPTIONS or a SET in this session) because the app\'s own sessions would not share it; the failure is written to the error log as well as thrown. Known loss: the naive hour 02:00-03:00 on the night the clocks go back exists twice, and AT TIME ZONE reads it as the second (winter-time) pass, so a row written in the first pass comes out one hour late; its Oslo wall clock still reads 02:xx. It trusts the zone of the session that runs it: a database restored from another install and migrated on a UTC session is not converted. `php migrate.php seed` marks this applied without running it, and an install that never runs it keeps session-naive writes. Deploy the new code first and run this after it: a row the new code wrote before the migration is still in the session zone and is converted with the rest, and a row written after it is UTC. Run it first and the only wrong rows are the ones old code writes with an explicit NOW() (modified, on an update) until that code is replaced.';

	/** What pg_get_expr prints for the default this migration installs, spelling and all. */
	private const UTC_DEFAULT = "(now() AT TIME ZONE 'UTC'::text)";

	/** What pg_get_expr prints for the defaults the tables were created with. */
	private const LEGACY_DEFAULTS = ['now()', 'CURRENT_TIMESTAMP'];

	/** pg_settings.source values that are a property of the server, shared by every session including php-fpm's. */
	private const SERVER_SIDE_SOURCES = ['configuration file', 'database', 'user', 'database user', 'default'];

	/** Session zones that already are UTC; the rows need no rewrite. */
	private const UTC_ZONES = ['UTC', 'Etc/UTC'];

	/** Table => the columns the database fills from its default. serving_time_iso is written as explicit UTC and is not here. */
	private const COLUMNS = [
		'bb_hospitality' => ['created', 'modified'],
		'bb_hospitality_order' => ['created', 'modified'],
		'bb_hospitality_order_changelog' => ['changed_at'],
	];

	public function up(): void
	{
		// By regclass, not information_schema: that follows search_path, which is what the app's own
		// queries do, and it cannot be answered by a table of the same name in another schema.
		foreach (array_keys(self::COLUMNS) as $table) {
			$this->db->query("SELECT to_regclass('{$table}') IS NOT NULL AS present", __LINE__, __FILE__, false, true);
			$this->db->next_record();
			if (!$this->db->Record['present']) {
				$this->fail("table {$table} does not exist");
			}
		}

		// ACCESS EXCLUSIVE before anything is read: a lock taken later and raised to the mode ALTER
		// needs is a lock upgrade, which can deadlock against a writer that arrives in between.
		$this->sql('LOCK TABLE ' . implode(', ', array_keys(self::COLUMNS)) . ' IN ACCESS EXCLUSIVE MODE');

		if ($this->defaultsState() === 'utc') {
			error_log('[hospitality UTC migration] all five defaults are already UTC: nothing to do');
			return;
		}

		$this->db->query(
			"SELECT current_setting('TimeZone') AS zone, (SELECT source FROM pg_settings WHERE name = 'TimeZone') AS source",
			__LINE__,
			__FILE__,
			false,
			true
		);
		$this->db->next_record();
		$zone = (string) $this->db->Record['zone'];
		$source = (string) $this->db->Record['source'];
		error_log("[hospitality UTC migration] session TimeZone={$zone}, source={$source}");

		if (!in_array($source, self::SERVER_SIDE_SOURCES, true)) {
			$this->fail("the session TimeZone '{$zone}' comes from '{$source}', not from the server: the app's own sessions would not share it, so the rows would be converted by the wrong offset. Run the migration from a shell without PGTZ/PGOPTIONS, or check the role/database/server setting");
		}

		if (!in_array($zone, self::UTC_ZONES, true)) {
			foreach (self::COLUMNS as $table => $columns) {
				$set = [];
				foreach ($columns as $column) {
					$set[] = "{$column} = ({$column} AT TIME ZONE current_setting('TimeZone')) AT TIME ZONE 'UTC'";
				}
				$this->sql("UPDATE {$table} SET " . implode(', ', $set));
			}
		}

		foreach (self::COLUMNS as $table => $columns) {
			$alter = [];
			foreach ($columns as $column) {
				$alter[] = "ALTER COLUMN {$column} SET DEFAULT (now() AT TIME ZONE 'UTC')";
			}
			$this->sql("ALTER TABLE {$table} " . implode(', ', $alter));
		}

		if ($this->defaultsState() !== 'utc') {
			$this->fail('the defaults are not UTC after the change');
		}
		error_log('[hospitality UTC migration] done: rows ' . (in_array($zone, self::UTC_ZONES, true) ? 'left as they were (UTC session)' : "converted from {$zone} to UTC") . ', defaults now UTC');
	}

	/**
	 * 'legacy' when all five defaults are the ones the tables were created with, 'utc' when all five
	 * are the default this migration installs. Anything else is a half-done or hand-edited state
	 * that must not be guessed at: converting it would shift rows twice, skipping it would leave
	 * them in the session zone.
	 */
	private function defaultsState(): string
	{
		$exprs = [];
		foreach (self::COLUMNS as $table => $columns) {
			foreach ($columns as $column) {
				$this->db->query(
					"SELECT pg_get_expr(d.adbin, d.adrelid) AS expr FROM pg_attrdef d "
					. "JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum "
					. "WHERE d.adrelid = '{$table}'::regclass AND a.attname = '{$column}'",
					__LINE__,
					__FILE__,
					false,
					true
				);
				$this->db->next_record();
				$exprs["{$table}.{$column}"] = $this->db->Record['expr'] ?? null;
			}
		}

		$utc = array_filter($exprs, static fn($e) => $e === self::UTC_DEFAULT);
		if (count($utc) === count($exprs)) {
			return 'utc';
		}
		$legacy = array_filter($exprs, static fn($e) => in_array($e, self::LEGACY_DEFAULTS, true));
		if (count($legacy) === count($exprs)) {
			return 'legacy';
		}

		$seen = [];
		foreach ($exprs as $name => $expr) {
			$seen[] = "{$name} = " . ($expr ?? '(none)');
		}
		$this->fail('the five defaults are neither all the original ones nor all UTC, so nothing is changed: ' . implode('; ', $seen));
	}

	/** Log it where a deploy that only looks at the exit status can still find it, then stop. */
	private function fail(string $message): never
	{
		error_log('[hospitality UTC migration] ABORTED: ' . $message);
		throw new \RuntimeException($message);
	}
};

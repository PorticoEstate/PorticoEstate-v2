<?php

namespace App\modules\phpgwapi\services\Migration;

use App\Database\Db;
use App\modules\phpgwapi\services\SchemaProc\SchemaProc;

/**
 * Base class for database migrations.
 *
 * All migration helper methods are idempotent - they check DB state
 * before making changes. This means migrations can safely be replayed
 * on databases that already have the changes applied.
 */
abstract class Migration
{
	protected Db $db;
	protected SchemaProc $schemaProc;

	public string $description = '';

	/**
	 * Migration-level dependencies.
	 * Format: ['module' => 'migration_filename', ...]
	 * Example: ['booking' => 'm20260430_000001_baseline.php']
	 *
	 * The migration runner will verify these are applied before running this migration.
	 */
	public array $depends = [];

	public function setDependencies(Db $db, SchemaProc $schemaProc): void
	{
		$this->db = $db;
		$this->schemaProc = $schemaProc;
	}

	/**
	 * Run the migration.
	 */
	abstract public function up(): void;

	// ------------------------------------------------------------------
	// DB state inspection helpers
	// ------------------------------------------------------------------

	protected function tableExists(string $table): bool
	{
		$this->db->query(
			"SELECT COUNT(*) AS cnt FROM information_schema.tables "
			. "WHERE table_schema = 'public' AND table_name = '$table'",
			__LINE__,
			__FILE__,
			false,
			true // fetch_single mode for reliable single-row fetch
		);
		$this->db->next_record();
		return (int) ($this->db->Record['cnt'] ?? 0) > 0;
	}

	protected function columnExists(string $table, string $column): bool
	{
		$this->db->query(
			"SELECT COUNT(*) AS cnt FROM information_schema.columns "
			. "WHERE table_schema = 'public' AND table_name = '$table' AND column_name = '$column'",
			__LINE__,
			__FILE__
		);
		$this->db->next_record();
		return (int) $this->db->Record['cnt'] > 0;
	}

	protected function indexExists(string $table, string $index): bool
	{
		$this->db->query(
			"SELECT COUNT(*) AS cnt FROM pg_indexes "
			. "WHERE tablename = '$table' AND indexname = '$index'",
			__LINE__,
			__FILE__
		);
		$this->db->next_record();
		return (int) $this->db->Record['cnt'] > 0;
	}

	protected function constraintExists(string $table, string $constraint): bool
	{
		$this->db->query(
			"SELECT COUNT(*) AS cnt FROM information_schema.table_constraints "
			. "WHERE table_schema = 'public' AND table_name = '$table' AND constraint_name = '$constraint'",
			__LINE__,
			__FILE__
		);
		$this->db->next_record();
		return (int) $this->db->Record['cnt'] > 0;
	}

	protected function viewExists(string $view): bool
	{
		$this->db->query(
			"SELECT COUNT(*) AS cnt FROM information_schema.views "
			. "WHERE table_schema = 'public' AND table_name = '$view'",
			__LINE__,
			__FILE__
		);
		$this->db->next_record();
		return (int) $this->db->Record['cnt'] > 0;
	}

	/**
	 * Check if a column is nullable.
	 */
	protected function isNullable(string $table, string $column): bool
	{
		$this->db->query(
			"SELECT is_nullable FROM information_schema.columns "
			. "WHERE table_schema = 'public' AND table_name = '$table' AND column_name = '$column'",
			__LINE__,
			__FILE__
		);
		$this->db->next_record();
		return $this->db->Record['is_nullable'] === 'YES';
	}

	/**
	 * Get the data type of a column (e.g., 'integer', 'character varying', 'jsonb').
	 */
	protected function getColumnType(string $table, string $column): ?string
	{
		$this->db->query(
			"SELECT data_type FROM information_schema.columns "
			. "WHERE table_schema = 'public' AND table_name = '$table' AND column_name = '$column'",
			__LINE__,
			__FILE__
		);
		if ($this->db->next_record()) {
			return $this->db->Record['data_type'];
		}
		return null;
	}

	/**
	 * Get the column default value as PostgreSQL reports it.
	 * Returns null if no default is set.
	 */
	protected function getColumnDefault(string $table, string $column): ?string
	{
		$this->db->query(
			"SELECT column_default FROM information_schema.columns "
			. "WHERE table_schema = 'public' AND table_name = '$table' AND column_name = '$column'",
			__LINE__,
			__FILE__
		);
		if ($this->db->next_record()) {
			return $this->db->Record['column_default'];
		}
		return null;
	}

	// ------------------------------------------------------------------
	// Idempotent DDL helpers
	// ------------------------------------------------------------------

	/**
	 * Create a table if it does not exist.
	 * Uses the same definition format as tables_current.inc.php.
	 */
	protected function createTable(string $name, array $definition): void
	{
		if (!$this->tableExists($name)) {
			// Drop orphaned sequence if it exists (from a rolled-back prior attempt)
			$this->db->query(
				"DROP SEQUENCE IF EXISTS seq_{$name}",
				__LINE__,
				__FILE__
			);
			$result = $this->schemaProc->CreateTable($name, $definition);
			// Verify the table was actually created
			if (!$this->tableExists($name)) {
				throw new \RuntimeException("createTable({$name}) failed — table does not exist after creation");
			}
		}
	}

	/**
	 * Add a column if it does not exist.
	 *
	 * For NOT NULL columns with a default, the column is added as nullable first,
	 * existing rows are filled with the default, then NOT NULL is applied.
	 * This avoids PostgreSQL errors on tables that already have data.
	 */
	protected function addColumn(string $table, string $column, array $columnDef): void
	{
		if ($this->columnExists($table, $column)) {
			return;
		}

		$wantNotNull = isset($columnDef['nullable']) && ($columnDef['nullable'] === false || $columnDef['nullable'] === 'False');
		$hasDefault = isset($columnDef['default']);

		if ($wantNotNull && $hasDefault) {
			// Add as nullable first to avoid NOT NULL violation on existing rows
			$nullableDef = $columnDef;
			$nullableDef['nullable'] = true;
			$this->schemaProc->AddColumn($table, $column, $nullableDef);

			// Fill existing rows with the default value
			$default = $columnDef['default'];
			if (is_string($default) && $default !== 'current_timestamp') {
				$default = "'" . str_replace("'", "''", $default) . "'";
			}
			$this->sql("UPDATE {$table} SET {$column} = {$default} WHERE {$column} IS NULL");

			// Now set NOT NULL
			$this->sql("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");
		} else {
			$this->schemaProc->AddColumn($table, $column, $columnDef);
		}
	}

	/**
	 * Drop a column if it exists.
	 */
	protected function dropColumn(string $table, string $column): void
	{
		if ($this->columnExists($table, $column)) {
			$this->schemaProc->DropColumn($table, [], $column);
		}
	}

	/**
	 * Drop a table if it exists.
	 */
	protected function dropTable(string $table): void
	{
		if ($this->tableExists($table)) {
			$this->schemaProc->DropTable($table);
		}
	}

	/**
	 * Rename a table if the old name exists and the new name does not.
	 */
	protected function renameTable(string $oldName, string $newName): void
	{
		if ($this->tableExists($oldName) && !$this->tableExists($newName)) {
			$this->schemaProc->RenameTable($oldName, $newName);
		}
	}

	/**
	 * Rename a column if the old column exists and the new one does not.
	 */
	protected function renameColumn(string $table, string $oldName, string $newName): void
	{
		if ($this->columnExists($table, $oldName) && !$this->columnExists($table, $newName)) {
			$this->schemaProc->RenameColumn($table, $oldName, $newName);
		}
	}

	/**
	 * Run raw SQL. No idempotency check — caller is responsible.
	 */
	protected function sql(string $query): void
	{
		$this->db->query($query, __LINE__, __FILE__);
	}

	// ------------------------------------------------------------------
	// Assertions — fail the migration if preconditions aren't met
	// ------------------------------------------------------------------

	/**
	 * Assert a table exists. Throws if it doesn't.
	 */
	protected function assertTableExists(string $table): void
	{
		if (!$this->tableExists($table)) {
			throw new \RuntimeException("Migration precondition failed: table '{$table}' does not exist.");
		}
	}

	/**
	 * Assert a column exists on a table. Throws if it doesn't.
	 */
	protected function assertColumnExists(string $table, string $column): void
	{
		if (!$this->columnExists($table, $column)) {
			throw new \RuntimeException("Migration precondition failed: column '{$table}.{$column}' does not exist.");
		}
	}

	/**
	 * Assert a table is empty. Use before destructive schema changes.
	 * Throws if the table has data.
	 */
	protected function assertTableEmpty(string $table): void
	{
		$this->db->query("SELECT EXISTS(SELECT 1 FROM {$table}) AS has_rows", __LINE__, __FILE__);
		$this->db->next_record();
		if ($this->db->Record['has_rows'] === true || $this->db->Record['has_rows'] === 't') {
			throw new \RuntimeException("Migration precondition failed: table '{$table}' is not empty.");
		}
	}

	/**
	 * Assert a column has no NULL values. Use before adding NOT NULL constraint.
	 */
	protected function assertNoNulls(string $table, string $column): void
	{
		$this->db->query(
			"SELECT EXISTS(SELECT 1 FROM {$table} WHERE {$column} IS NULL) AS has_nulls",
			__LINE__, __FILE__
		);
		$this->db->next_record();
		if ($this->db->Record['has_nulls'] === true || $this->db->Record['has_nulls'] === 't') {
			throw new \RuntimeException(
				"Migration precondition failed: '{$table}.{$column}' contains NULL values. "
				. "Handle data migration before adding NOT NULL constraint."
			);
		}
	}

	/**
	 * Assert a column has no values that would violate a type conversion.
	 * Runs the CAST and checks for failures.
	 */
	protected function assertCastable(string $table, string $column, string $targetType): void
	{
		try {
			$this->db->query(
				"SELECT 1 FROM {$table} WHERE {$column} IS NOT NULL AND {$column}::text != '' LIMIT 1",
				__LINE__, __FILE__
			);
			// Try the actual cast on a sample
			$this->db->query(
				"DO $$ BEGIN PERFORM {$column}::{$targetType} FROM {$table} WHERE {$column} IS NOT NULL LIMIT 100; END $$;",
				__LINE__, __FILE__
			);
		} catch (\Exception $e) {
			throw new \RuntimeException(
				"Migration precondition failed: '{$table}.{$column}' cannot be cast to {$targetType}. "
				. "Handle data conversion explicitly. Error: " . $e->getMessage()
			);
		}
	}

	/**
	 * Get the row count of a table.
	 */
	protected function rowCount(string $table): int
	{
		$this->db->query("SELECT COUNT(*) AS cnt FROM {$table}", __LINE__, __FILE__);
		$this->db->next_record();
		return (int) $this->db->Record['cnt'];
	}

	/**
	 * Assert a condition with a custom message. Generic escape hatch.
	 */
	protected function assert(bool $condition, string $message): void
	{
		if (!$condition) {
			throw new \RuntimeException("Migration assertion failed: {$message}");
		}
	}

	// ------------------------------------------------------------------
	// Column verification
	// ------------------------------------------------------------------

	/**
	 * Map SchemaProc type definitions to PostgreSQL information_schema data_type values.
	 */
	private function mapTypeToPg(string $type, int $precision = 0): string
	{
		return match ($type) {
			'auto' => 'integer',
			'int' => match (true) {
				$precision <= 2 => 'smallint',
				$precision <= 4 => 'integer',
				default => 'bigint',
			},
			'varchar' => 'character varying',
			'char' => 'character',
			'text' => 'text',
			'blob' => 'text',
			'bool' => 'boolean',
			'decimal' => 'numeric',
			'float', 'double' => 'double precision',
			'datetime', 'timestamp' => 'timestamp without time zone',
			'timestamptz' => 'timestamp with time zone',
			'date' => 'date',
			'time' => 'time without time zone',
			'jsonb' => 'jsonb',
			'json' => 'json',
			default => $type,
		};
	}

	/**
	 * Compare an expected default value against PostgreSQL's column_default string.
	 * PostgreSQL stores defaults with type casts (e.g. '0'::integer, 'hours'::character varying, now()).
	 */
	private function defaultsMatch(?string $pgDefault, $expected): bool
	{
		// Both null/unset
		if ($pgDefault === null && $expected === null) {
			return true;
		}
		if ($pgDefault === null || $expected === null) {
			return false;
		}

		$expected = (string) $expected;

		// Strip type casts: '0'::integer -> 0, 'hours'::character varying -> hours
		$normalized = $pgDefault;
		if (preg_match("/^'(.*)'::[\w\s]+$/", $normalized, $m)) {
			$normalized = $m[1];
		}
		// Unquoted numeric casts: 0::integer -> 0
		if (preg_match("/^(-?[\d.]+)::[\w\s]+$/", $normalized, $m)) {
			$normalized = $m[1];
		}

		// Direct match after normalization
		if ($normalized === $expected) {
			return true;
		}

		// Numeric comparison: '0' == 0, '0.0' == 0
		if (is_numeric($normalized) && is_numeric($expected)) {
			return (float) $normalized === (float) $expected;
		}

		// Timestamp defaults: now(), CURRENT_TIMESTAMP, current_timestamp are equivalent
		$tsAliases = ['now()', 'current_timestamp', 'current_timestamp()'];
		if (in_array(strtolower($normalized), $tsAliases) && in_array(strtolower($expected), $tsAliases)) {
			return true;
		}

		// Boolean: 'false'::boolean -> false, true::boolean -> true
		$boolMap = ['true' => true, 'false' => false, '1' => true, '0' => false];
		if (isset($boolMap[strtolower($normalized)]) && isset($boolMap[strtolower($expected)])) {
			return $boolMap[strtolower($normalized)] === $boolMap[strtolower($expected)];
		}

		return false;
	}

	/**
	 * Verify that an existing column matches the expected definition and fix drift.
	 *
	 * - Default mismatch: auto-fixed (non-destructive).
	 * - Nullable mismatch: auto-fixed if safe (dropping NOT NULL is always safe;
	 *   adding NOT NULL is safe only if no NULLs exist — otherwise throws).
	 * - Type mismatch: always throws. Type changes can lose data and must be
	 *   handled with explicit SQL in the migration.
	 *
	 * @throws \RuntimeException if the mismatch requires manual data migration.
	 */
	protected function verifyColumn(string $table, string $column, array $expectedDef): bool
	{
		if (!$this->columnExists($table, $column)) {
			return false;
		}

		// Check type — cannot auto-fix, data loss risk
		if (isset($expectedDef['type'])) {
			$expectedPgType = $this->mapTypeToPg(
				$expectedDef['type'],
				(int) ($expectedDef['precision'] ?? 0)
			);
			$actualType = $this->getColumnType($table, $column);

			if ($actualType !== null && $actualType !== $expectedPgType) {
				throw new \RuntimeException(
					"Migration error: {$table}.{$column} type mismatch — "
					. "expected '{$expectedPgType}', got '{$actualType}'. "
					. "Rewrite this migration to handle the type conversion explicitly."
				);
			}
		}

		// Fix nullable
		if (isset($expectedDef['nullable'])) {
			$actualNullable = $this->isNullable($table, $column);
			$expectedNullable = (bool) $expectedDef['nullable'];

			if ($actualNullable !== $expectedNullable) {
				if ($expectedNullable) {
					// Dropping NOT NULL is always safe
					$this->sql("ALTER TABLE {$table} ALTER COLUMN {$column} DROP NOT NULL");
				} else {
					// Adding NOT NULL — fail if there are NULLs, developer must handle data
					$this->db->query(
						"SELECT EXISTS(SELECT 1 FROM {$table} WHERE {$column} IS NULL) AS has_nulls",
						__LINE__, __FILE__
					);
					$this->db->next_record();
					if ($this->db->Record['has_nulls'] === true || $this->db->Record['has_nulls'] === 't') {
						throw new \RuntimeException(
							"Migration error: {$table}.{$column} has NULL values but migration expects NOT NULL. "
							. "Rewrite this migration to handle the data migration before setting NOT NULL."
						);
					}
					$this->sql("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");
				}
			}
		}

		// Fix default
		if (array_key_exists('default', $expectedDef)) {
			$actualDefault = $this->getColumnDefault($table, $column);
			$expectedDefault = $expectedDef['default'];

			if (!$this->defaultsMatch($actualDefault, $expectedDefault)) {
				if ($expectedDefault === null) {
					$this->sql("ALTER TABLE {$table} ALTER COLUMN {$column} DROP DEFAULT");
				} else {
					$this->sql("ALTER TABLE {$table} ALTER COLUMN {$column} SET DEFAULT " . $this->db->quote($expectedDefault));
				}
			}
		}

		return true;
	}

	/**
	 * Add a column if it does not exist, or verify it matches if it does.
	 */
	protected function ensureColumn(string $table, string $column, array $columnDef): void
	{
		if (!$this->columnExists($table, $column)) {
			$this->addColumn($table, $column, $columnDef);
		} else {
			$this->verifyColumn($table, $column, $columnDef);
		}
	}
}

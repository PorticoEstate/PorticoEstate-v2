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
			__FILE__
		);
		$this->db->next_record();
		return (int) $this->db->Record['cnt'] > 0;
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
			$this->schemaProc->CreateTable($name, $definition);
		}
	}

	/**
	 * Add a column if it does not exist.
	 */
	protected function addColumn(string $table, string $column, array $columnDef): void
	{
		if (!$this->columnExists($table, $column)) {
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
			'timestamp' => 'timestamp without time zone',
			'timestamptz' => 'timestamp with time zone',
			'date' => 'date',
			'time' => 'time without time zone',
			'jsonb' => 'jsonb',
			'json' => 'json',
			default => $type,
		};
	}

	/**
	 * Verify that an existing column matches the expected definition.
	 * Logs a warning but does not throw — the column exists, it's just potentially misconfigured.
	 *
	 * Checks: data type and nullable.
	 */
	protected function verifyColumn(string $table, string $column, array $expectedDef): bool
	{
		if (!$this->columnExists($table, $column)) {
			return false;
		}

		$issues = [];

		// Check type
		if (isset($expectedDef['type'])) {
			$expectedPgType = $this->mapTypeToPg(
				$expectedDef['type'],
				(int) ($expectedDef['precision'] ?? 0)
			);
			$actualType = $this->getColumnType($table, $column);

			if ($actualType !== null && $actualType !== $expectedPgType) {
				$issues[] = "type mismatch: expected '{$expectedPgType}', got '{$actualType}'";
			}
		}

		// Check nullable
		if (isset($expectedDef['nullable'])) {
			$actualNullable = $this->isNullable($table, $column);
			$expectedNullable = (bool) $expectedDef['nullable'];

			if ($actualNullable !== $expectedNullable) {
				$expected = $expectedNullable ? 'nullable' : 'not null';
				$actual = $actualNullable ? 'nullable' : 'not null';
				$issues[] = "nullable mismatch: expected '{$expected}', got '{$actual}'";
			}
		}

		if (!empty($issues)) {
			$msg = "Column {$table}.{$column} verification: " . implode('; ', $issues);
			trigger_error($msg, E_USER_WARNING);
			return false;
		}

		return true;
	}

	/**
	 * Add a column if it does not exist, or verify it matches if it does.
	 */
	protected function ensureColumn(string $table, string $column, array $columnDef): void
	{
		if (!$this->columnExists($table, $column)) {
			$this->schemaProc->AddColumn($table, $column, $columnDef);
		} else {
			$this->verifyColumn($table, $column, $columnDef);
		}
	}
}

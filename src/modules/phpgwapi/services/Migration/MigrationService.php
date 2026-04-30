<?php

namespace App\modules\phpgwapi\services\Migration;

use App\Database\Db;
use App\modules\phpgwapi\services\SchemaProc\SchemaProc;

/**
 * Discovers, tracks, and executes migrations for modules.
 *
 * Works alongside the legacy tables_current / tables_update system.
 * Modules that have a migrations/ directory will have their migrations
 * run after the legacy install/upgrade process completes.
 *
 * Every migration is idempotent — it checks DB state before making changes,
 * so it is safe to run on databases that already have the changes applied.
 */
class MigrationService
{
	private Db $db;
	private SchemaProc $schemaProc;

	public function __construct()
	{
		$this->db = Db::getInstance();
		$config = $this->db->get_config();
		$dbType = $config['db_type'] ?? 'postgres';
		$this->schemaProc = new SchemaProc($dbType);
		$this->schemaProc->m_odb = $this->db;
		$this->schemaProc->m_bDeltaOnly = false;
	}

	// ------------------------------------------------------------------
	// Migrations table management
	// ------------------------------------------------------------------

	public function hasMigrationsTable(): bool
	{
		$this->db->query(
			"SELECT COUNT(*) AS cnt FROM information_schema.tables "
			. "WHERE table_schema = 'public' AND table_name = 'phpgw_migrations'",
			__LINE__,
			__FILE__
		);
		$this->db->next_record();
		return (int) $this->db->Record['cnt'] > 0;
	}

	public function ensureMigrationsTable(): void
	{
		if ($this->hasMigrationsTable()) {
			return;
		}

		$this->db->query(
			"CREATE TABLE phpgw_migrations ("
			. " id SERIAL PRIMARY KEY,"
			. " module VARCHAR(50) NOT NULL,"
			. " migration VARCHAR(255) NOT NULL,"
			. " batch INT NOT NULL DEFAULT 1,"
			. " executed_at TIMESTAMP NOT NULL DEFAULT NOW(),"
			. " UNIQUE(module, migration)"
			. ")",
			__LINE__,
			__FILE__
		);
	}

	// ------------------------------------------------------------------
	// Discovery
	// ------------------------------------------------------------------

	/**
	 * Check if a module uses the new migration system.
	 */
	public function moduleHasMigrations(string $module): bool
	{
		$dir = SRC_ROOT_PATH . "/modules/{$module}/migrations/";
		return is_dir($dir) && count($this->getMigrationFiles($module)) > 0;
	}

	/**
	 * Get all migration files for a module, sorted by filename (chronological).
	 *
	 * @return string[] Absolute file paths sorted alphabetically.
	 */
	public function getMigrationFiles(string $module): array
	{
		$dir = SRC_ROOT_PATH . "/modules/{$module}/migrations/";
		if (!is_dir($dir)) {
			return [];
		}

		$files = glob($dir . 'm*.php');
		if (!$files) {
			return [];
		}

		sort($files);
		return $files;
	}

	// ------------------------------------------------------------------
	// Tracking
	// ------------------------------------------------------------------

	/**
	 * Get names of migrations that have already been applied.
	 *
	 * @return string[] Migration filenames (without path).
	 */
	public function getAppliedMigrations(string $module): array
	{
		if (!$this->hasMigrationsTable()) {
			return [];
		}

		$applied = [];
		$this->db->query(
			"SELECT migration FROM phpgw_migrations WHERE module = '$module' ORDER BY id",
			__LINE__,
			__FILE__
		);
		while ($this->db->next_record()) {
			$applied[] = $this->db->Record['migration'];
		}
		return $applied;
	}

	/**
	 * Get migrations that have not yet been applied.
	 *
	 * @return array{file: string, name: string}[]
	 */
	public function getPendingMigrations(string $module): array
	{
		$allFiles = $this->getMigrationFiles($module);
		$applied = $this->getAppliedMigrations($module);

		$pending = [];
		foreach ($allFiles as $file) {
			$name = basename($file);
			if (!in_array($name, $applied, true)) {
				$pending[] = ['file' => $file, 'name' => $name];
			}
		}
		return $pending;
	}

	/**
	 * Get the next batch number for a module.
	 */
	private function getNextBatch(string $module): int
	{
		$this->db->query(
			"SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM phpgw_migrations WHERE module = '$module'",
			__LINE__,
			__FILE__
		);
		$this->db->next_record();
		return (int) $this->db->Record['next_batch'];
	}

	/**
	 * Record that a migration has been applied.
	 */
	private function recordMigration(string $module, string $migrationName, int $batch): void
	{
		$this->db->query(
			"INSERT INTO phpgw_migrations (module, migration, batch) "
			. "VALUES ('$module', '$migrationName', $batch)",
			__LINE__,
			__FILE__
		);
	}

	/**
	 * Verify that all migration-level dependencies are satisfied.
	 *
	 * @param array $depends ['module' => 'migration_filename', ...]
	 * @throws \RuntimeException if a dependency is not met
	 */
	private function checkMigrationDependencies(array $depends, string $forModule, string $forMigration): void
	{
		$applied = [];

		foreach ($depends as $depModule => $depMigration) {
			if (!isset($applied[$depModule])) {
				$applied[$depModule] = $this->getAppliedMigrations($depModule);
			}

			if (!in_array($depMigration, $applied[$depModule], true)) {
				throw new \RuntimeException(
					"Migration {$forModule}/{$forMigration} depends on {$depModule}/{$depMigration} which has not been applied"
				);
			}
		}
	}

	// ------------------------------------------------------------------
	// Execution
	// ------------------------------------------------------------------

	/**
	 * Run all pending migrations for a module.
	 *
	 * @return array{name: string, status: string, error?: string}[] Results per migration.
	 */
	public function runPending(string $module, bool $debug = false): array
	{
		$this->ensureMigrationsTable();
		$pending = $this->getPendingMigrations($module);

		if (empty($pending)) {
			if ($debug) {
				echo "<br>MigrationService: No pending migrations for {$module}\n";
			}
			return [];
		}

		$batch = $this->getNextBatch($module);
		$results = [];

		foreach ($pending as $entry) {
			$file = $entry['file'];
			$name = $entry['name'];

			if ($debug) {
				echo "<br>MigrationService: Running {$name} for {$module}\n";
			}

			try {
				/** @var Migration $migration */
				$migration = require $file;
				$migration->setDependencies($this->db, $this->schemaProc);

				// Check migration-level dependencies
				if (!empty($migration->depends)) {
					$this->checkMigrationDependencies($migration->depends, $module, $name);
				}

				$this->db->transaction_begin();
				$migration->up();

				$this->recordMigration($module, $name, $batch);
				$this->db->transaction_commit();

				$results[] = ['name' => $name, 'status' => 'applied'];

				if ($debug) {
					$desc = $migration->description ?: $name;
					echo "<br>MigrationService: Applied {$desc}\n";
				}
			} catch (\Exception $e) {
				$this->db->transaction_abort();
				$results[] = [
					'name' => $name,
					'status' => 'failed',
					'error' => $e->getMessage(),
				];

				if ($debug) {
					echo "<br>MigrationService: FAILED {$name}: " . $e->getMessage() . "\n";
				}

				// Stop on first failure
				break;
			}
		}

		return $results;
	}

	/**
	 * Run pending migrations for all modules that use the migration system.
	 *
	 * @return array<string, array> Results keyed by module name.
	 */
	public function runAll(bool $debug = false): array
	{
		$results = [];
		$modulesDir = SRC_ROOT_PATH . '/modules/';

		foreach (scandir($modulesDir) as $module) {
			if ($module === '.' || $module === '..') {
				continue;
			}
			if ($this->moduleHasMigrations($module)) {
				$moduleResults = $this->runPending($module, $debug);
				if (!empty($moduleResults)) {
					$results[$module] = $moduleResults;
				}
			}
		}

		return $results;
	}

	/**
	 * Get a status overview for one or all modules.
	 *
	 * @return array<string, array{total: int, applied: int, pending: int, migrations: array}>
	 */
	public function getStatus(?string $module = null): array
	{
		$modules = [];
		if ($module) {
			$modules[] = $module;
		} else {
			$modulesDir = SRC_ROOT_PATH . '/modules/';
			foreach (scandir($modulesDir) as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}
				if ($this->moduleHasMigrations($entry)) {
					$modules[] = $entry;
				}
			}
		}

		$status = [];
		foreach ($modules as $mod) {
			$allFiles = $this->getMigrationFiles($mod);
			$applied = $this->getAppliedMigrations($mod);
			$pending = $this->getPendingMigrations($mod);

			$migrations = [];
			foreach ($allFiles as $file) {
				$name = basename($file);
				$migrationObj = require $file;
				$migrations[] = [
					'name' => $name,
					'description' => $migrationObj->description ?? '',
					'applied' => in_array($name, $applied, true),
				];
			}

			$status[$mod] = [
				'total' => count($allFiles),
				'applied' => count($applied),
				'pending' => count($pending),
				'migrations' => $migrations,
			];
		}

		return $status;
	}

	/**
	 * Find the migration filename that corresponds to a legacy version string.
	 *
	 * Looks for a legacy_version_map.php file in the module's migrations directory.
	 * Returns the migration filename that, once applied, brings the DB to that version.
	 *
	 * @return string|null Migration filename, or null if no mapping found.
	 */
	public function findMigrationForLegacyVersion(string $module, string $legacyVersion): ?string
	{
		$mapFile = SRC_ROOT_PATH . "/modules/{$module}/migrations/legacy_version_map.php";
		if (!file_exists($mapFile)) {
			return null;
		}

		$map = require $mapFile;
		return $map[$legacyVersion] ?? null;
	}

	/**
	 * Get the "current version" for a migration-based module as applied count.
	 */
	public function getCurrentVersion(string $module): string
	{
		$applied = count($this->getAppliedMigrations($module));
		return (string) $applied;
	}

	/**
	 * Get the "target version" for a migration-based module as total migration count.
	 */
	public function getTargetVersion(string $module): string
	{
		$total = count($this->getMigrationFiles($module));
		return (string) $total;
	}

	/**
	 * Mark migrations up to (and including) a specific migration as applied, without running them.
	 * Migrations after the cutoff are left pending so they will be executed with idempotent checks.
	 *
	 * @return int Number of migrations seeded.
	 */
	public function seedUpTo(string $module, string $cutoffMigration, bool $debug = false): int
	{
		$this->ensureMigrationsTable();
		$allFiles = $this->getMigrationFiles($module);
		$applied = $this->getAppliedMigrations($module);
		$batch = $this->getNextBatch($module);
		$count = 0;

		foreach ($allFiles as $file) {
			$name = basename($file);

			if (in_array($name, $applied, true)) {
				continue;
			}

			$this->recordMigration($module, $name, $batch);
			$count++;

			if ($debug) {
				echo "MigrationService: Seeded {$module}/{$name}\n";
			}

			if ($name === $cutoffMigration) {
				break;
			}
		}

		return $count;
	}

	/**
	 * Mark all migrations for a module as already applied without running them.
	 *
	 * Use this for existing databases that are already up-to-date via the
	 * legacy tables_update system. Seeds the tracking table so migrations
	 * won't be replayed.
	 *
	 * @return int Number of migrations marked as applied.
	 */
	public function seedModule(string $module, bool $debug = false): int
	{
		$this->ensureMigrationsTable();
		$pending = $this->getPendingMigrations($module);

		if (empty($pending)) {
			if ($debug) {
				echo "MigrationService: {$module} — nothing to seed, all migrations already tracked.\n";
			}
			return 0;
		}

		$batch = $this->getNextBatch($module);
		$count = 0;

		foreach ($pending as $entry) {
			$this->recordMigration($module, $entry['name'], $batch);
			$count++;
			if ($debug) {
				echo "MigrationService: Seeded {$module}/{$entry['name']}\n";
			}
		}

		return $count;
	}
}

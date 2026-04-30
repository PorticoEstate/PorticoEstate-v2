<?php

/**
 * CLI tool for managing database migrations.
 *
 * Usage:
 *   php migrate.php status [--module=booking]
 *   php migrate.php run [--module=booking] [--debug]
 *   php migrate.php seed [--module=booking]   — mark all as applied (for existing DBs)
 */

if (php_sapi_name() !== 'cli') {
	die("This script must be run from the command line.\n");
}

if (!defined('SRC_ROOT_PATH')) {
	define('SRC_ROOT_PATH', __DIR__ . '/src');
}

require __DIR__ . '/vendor/autoload.php';

// Bootstrap minimal application context
require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';

use App\modules\phpgwapi\services\Migration\MigrationService;

// Parse arguments
$command = $argv[1] ?? 'status';
$options = [];
foreach (array_slice($argv, 2) as $arg) {
	if (str_starts_with($arg, '--')) {
		$parts = explode('=', substr($arg, 2), 2);
		$options[$parts[0]] = $parts[1] ?? true;
	}
}

$module = $options['module'] ?? null;
$debug = isset($options['debug']);

$service = new MigrationService();

switch ($command) {
	case 'status':
		showStatus($service, $module);
		break;

	case 'run':
		runMigrations($service, $module, $debug);
		break;

	case 'seed':
		seedMigrations($service, $module, $debug);
		break;

	default:
		echo "Unknown command: {$command}\n";
		echo "Usage:\n";
		echo "  php migrate.php status [--module=name]\n";
		echo "  php migrate.php run [--module=name] [--debug]\n";
		echo "  php migrate.php seed [--module=name]  — mark all as applied without running\n";
		exit(1);
}

function showStatus(MigrationService $service, ?string $module): void
{
	$status = $service->getStatus($module);

	if (empty($status)) {
		echo "No modules with migrations found.\n";
		return;
	}

	foreach ($status as $mod => $info) {
		echo "\n{$mod}:\n";
		echo str_repeat('-', 60) . "\n";

		if ($info['total'] === 0) {
			echo "  No migrations defined.\n";
			continue;
		}

		foreach ($info['migrations'] as $migration) {
			$marker = $migration['applied'] ? '[x]' : '[ ]';
			$desc = $migration['description'] ? " — {$migration['description']}" : '';
			echo "  {$marker} {$migration['name']}{$desc}\n";
		}

		echo "\n  Total: {$info['total']}  Applied: {$info['applied']}  Pending: {$info['pending']}\n";
	}
	echo "\n";
}

function runMigrations(MigrationService $service, ?string $module, bool $debug): void
{
	if ($module) {
		if (!$service->moduleHasMigrations($module)) {
			echo "Module '{$module}' has no migrations directory.\n";
			exit(1);
		}

		$pending = $service->getPendingMigrations($module);
		if (empty($pending)) {
			echo "{$module}: No pending migrations.\n";
			return;
		}

		echo "{$module}: Running " . count($pending) . " migration(s)...\n";
		$results = $service->runPending($module, $debug);
		printResults($module, $results);
	} else {
		$results = $service->runAll($debug);

		if (empty($results)) {
			echo "No pending migrations for any module.\n";
			return;
		}

		foreach ($results as $mod => $modResults) {
			printResults($mod, $modResults);
		}
	}
}

function seedMigrations(MigrationService $service, ?string $module, bool $debug): void
{
	if ($module) {
		if (!$service->moduleHasMigrations($module)) {
			echo "Module '{$module}' has no migrations directory.\n";
			exit(1);
		}
		$count = $service->seedModule($module, $debug);
		echo "{$module}: Marked {$count} migration(s) as applied.\n";
	} else {
		$modulesDir = SRC_ROOT_PATH . '/modules/';
		$total = 0;
		foreach (scandir($modulesDir) as $mod) {
			if ($mod === '.' || $mod === '..') continue;
			if ($service->moduleHasMigrations($mod)) {
				$count = $service->seedModule($mod, $debug);
				if ($count > 0) {
					echo "{$mod}: Marked {$count} migration(s) as applied.\n";
				}
				$total += $count;
			}
		}
		if ($total === 0) {
			echo "All migrations already tracked.\n";
		}
	}
}

function printResults(string $module, array $results): void
{
	foreach ($results as $result) {
		$status = $result['status'] === 'applied' ? 'OK' : 'FAIL';
		echo "  [{$status}] {$module}: {$result['name']}";
		if (isset($result['error'])) {
			echo " — {$result['error']}";
		}
		echo "\n";
	}
}

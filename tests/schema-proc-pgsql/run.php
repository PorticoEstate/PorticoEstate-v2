<?php

require_once __DIR__ . '/../../src/modules/phpgwapi/services/SchemaProc/SchemaProcArray.php';
require_once __DIR__ . '/../../src/modules/phpgwapi/services/SchemaProc/SchemaProcPgsql.php';
require_once __DIR__ . '/../../src/modules/phpgwapi/services/SchemaProc/PostgresViewDependencies.php';
require_once __DIR__ . '/../../src/modules/phpgwapi/services/SchemaProc/SchemaProc.php';

use App\modules\phpgwapi\services\SchemaProc\SchemaProc;

$dsn = getenv('SCHEMA_PROC_TEST_DSN');
if (!$dsn)
{
	throw new RuntimeException('Set SCHEMA_PROC_TEST_DSN to run PostgreSQL schema tests.');
}
$db = new PDO($dsn, getenv('POSTGRES_USER'), getenv('POSTGRES_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->beginTransaction();
try
{
	$schema = 'schema_proc_test_' . bin2hex(random_bytes(6));
	$db->exec("CREATE SCHEMA {$schema}; SET LOCAL search_path TO {$schema}");
	$db->exec("CREATE TABLE source (name varchar(10), other text);
		INSERT INTO source VALUES ('abc', 'other');
		CREATE VIEW first_view AS SELECT name FROM source;
		ALTER VIEW first_view RENAME COLUMN name TO renamed;
		COMMENT ON VIEW first_view IS 'view comment';
		COMMENT ON COLUMN first_view.renamed IS 'column comment';
		CREATE VIEW second_view AS SELECT renamed FROM first_view;
		CREATE MATERIALIZED VIEW materialized AS SELECT renamed FROM second_view;
		CREATE MATERIALIZED VIEW unpopulated AS SELECT renamed FROM first_view WITH NO DATA;
		CREATE VIEW unrelated AS SELECT other FROM source;
		UPDATE source SET name = 'def'");

	$proc = new SchemaProc('postgres');
	$proc->m_odb = $db;
	$definition = ['type' => 'varchar', 'precision' => 20, 'nullable' => true];
	$unrelatedOid = $db->query("SELECT 'unrelated'::regclass::oid")->fetchColumn();
	if (!$proc->AlterColumn('source', 'name', $definition, true, true))
	{
		throw new RuntimeException('Column alteration failed.');
	}
	$check = function ($sql, $expected) use ($db)
	{
		$actual = $db->query($sql)->fetchColumn();
		if ($actual !== $expected)
		{
			throw new RuntimeException("Unexpected result for {$sql}: " . var_export($actual, true));
		}
	};
	$check("SELECT renamed FROM materialized", 'def');
	$check("SELECT relispopulated FROM pg_class WHERE oid = 'unpopulated'::regclass", false);
	$check("SELECT obj_description('first_view'::regclass, 'pg_class')", 'view comment');
	$check("SELECT col_description('first_view'::regclass, 1)", 'column comment');
	$check("SELECT character_maximum_length FROM information_schema.columns WHERE table_schema = '{$schema}' AND table_name = 'source' AND column_name = 'name'", 20);
	$check("SELECT other FROM unrelated", 'other');
	$check("SELECT 'unrelated'::regclass::oid", $unrelatedOid);

	$db->exec('CREATE INDEX ON materialized (renamed)');
	try
	{
		$proc->AlterColumn('source', 'name', ['type' => 'varchar', 'precision' => 30, 'nullable' => true], true, true);
		throw new RuntimeException('Expected an indexed materialized view to abort the alteration.');
	}
	catch (RuntimeException $e)
	{
		if (!str_contains($e->getMessage(), 'unsupported metadata'))
		{
			throw $e;
		}
	}
	$check("SELECT renamed FROM materialized", 'def');
	$check("SELECT count(*) FROM pg_indexes WHERE schemaname = '{$schema}' AND tablename = 'materialized'", 1);
	$db->exec("DROP INDEX {$schema}.materialized_renamed_idx");

	$db->exec("GRANT SELECT ON first_view TO PUBLIC");
	try
	{
		$proc->AlterColumn('source', 'name', ['type' => 'varchar', 'precision' => 30, 'nullable' => true], true, true);
		throw new RuntimeException('Expected unsupported privileges to abort the alteration.');
	}
	catch (RuntimeException $e)
	{
		if (!str_contains($e->getMessage(), 'unsupported metadata'))
		{
			throw $e;
		}
	}
	$check("SELECT renamed FROM materialized", 'def');
	$check("SELECT has_table_privilege('public', 'first_view', 'SELECT')", true);
	$check("SELECT character_maximum_length FROM information_schema.columns WHERE table_schema = '{$schema}' AND table_name = 'source' AND column_name = 'name'", 20);

	$db->exec('REVOKE ALL ON first_view FROM PUBLIC');
	// Reset the explicit ACL to its original NULL state with a fresh view.
	$db->exec('DROP MATERIALIZED VIEW materialized; DROP MATERIALIZED VIEW unpopulated;
		DROP VIEW second_view; DROP VIEW first_view;
		CREATE VIEW first_view AS SELECT name FROM source');
	try
	{
		$proc->AlterColumn('source', 'name', ['type' => 'int', 'precision' => 4, 'nullable' => true], true, true);
		throw new RuntimeException('Expected the invalid type conversion to fail.');
	}
	catch (PDOException $e)
	{
		$check("SELECT name FROM first_view", 'def');
	}
}
finally
{
	$db->rollBack();
}

$db->exec('CREATE TEMP TABLE no_views (name varchar(10))');
$proc = new SchemaProc('postgres');
$proc->m_odb = $db;
if (!$proc->AlterColumn('no_views', 'name', ['type' => 'varchar', 'precision' => 20, 'nullable' => true], true, true)
	|| $db->inTransaction())
{
	throw new RuntimeException('An alteration without dependent views did not commit.');
}
echo "PostgreSQL schema view tests passed.\n";

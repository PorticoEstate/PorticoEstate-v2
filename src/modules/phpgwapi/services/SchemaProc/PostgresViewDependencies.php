<?php

namespace App\modules\phpgwapi\services\SchemaProc;

use RuntimeException;
use Throwable;

/**
 * Rebuilds dependent views, rejecting metadata that cannot be preserved safely.
 * Populated materialized views are refreshed; unpopulated ones remain unpopulated.
 */
class PostgresViewDependencies
{
	public function alterColumn($db, string $table, string $column, callable $alter): bool
	{
		$nested = $db->inTransaction();
		$savepoint = 'schema_views_' . bin2hex(random_bytes(8));
		if ($nested)
		{
			$this->exec($db, "SAVEPOINT {$savepoint}");
		}
		elseif (!$db->beginTransaction())
		{
			throw new RuntimeException('Could not begin the view rebuild transaction');
		}

		try
		{
			$target = $this->fetchAll($db, <<<'SQL'
SELECT format('%I.%I', n.nspname, c.relname) AS name, a.attnum
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = :column AND NOT a.attisdropped
WHERE c.oid = to_regclass(:table)
SQL, ['table' => $table, 'column' => $column]);
			if (count($target) !== 1)
			{
				throw new RuntimeException("Cannot find column {$table}.{$column}");
			}

			$this->exec($db, 'LOCK TABLE ' . $target[0]['name'] . ' IN ACCESS EXCLUSIVE MODE');
			$views = $this->fetchAll($db, <<<'SQL'
WITH RECURSIVE dependencies AS (
	SELECT v.oid, 1 AS depth, ARRAY[v.oid] AS path
	FROM pg_depend d
	JOIN pg_rewrite r ON r.oid = d.objid AND d.classid = 'pg_rewrite'::regclass
	JOIN pg_class v ON v.oid = r.ev_class AND v.relkind IN ('v', 'm')
	WHERE d.refclassid = 'pg_class'::regclass
		AND d.refobjid = to_regclass(:table)
		AND d.refobjsubid IN (0, CAST(:attnum AS integer))
		AND r.rulename = '_RETURN'
	UNION ALL
	SELECT v.oid, dependencies.depth + 1, dependencies.path || v.oid
	FROM dependencies
	JOIN pg_depend d ON d.refclassid = 'pg_class'::regclass AND d.refobjid = dependencies.oid
	JOIN pg_rewrite r ON r.oid = d.objid AND d.classid = 'pg_rewrite'::regclass
	JOIN pg_class v ON v.oid = r.ev_class AND v.relkind IN ('v', 'm')
	WHERE r.rulename = '_RETURN' AND NOT v.oid = ANY(dependencies.path)
), ordered AS (
	SELECT oid, max(depth) AS depth FROM dependencies GROUP BY oid
)
SELECT c.oid, c.relkind, c.relispopulated,
	format('%I.%I', n.nspname, c.relname) AS name,
	quote_ident(pg_get_userbyid(c.relowner)) AS owner,
	(SELECT quote_ident(am.amname) FROM pg_am am WHERE am.oid = c.relam) AS access_method,
	pg_get_viewdef(c.oid, false) AS definition,
	(SELECT string_agg(quote_ident(a.attname), ', ' ORDER BY a.attnum)
		FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped) AS columns,
	CASE WHEN c.relacl IS NOT NULL OR c.reloptions IS NOT NULL
		OR c.reltablespace <> 0 OR c.relpersistence <> 'p'
		OR EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid
			AND (a.attacl IS NOT NULL OR a.attoptions IS NOT NULL OR a.atthasdef OR a.attstattarget >= 0
				OR COALESCE(to_jsonb(a)->>'attcompression', '') <> ''
				OR a.attstorage <> (SELECT t.typstorage FROM pg_type t WHERE t.oid = a.atttypid)))
		OR EXISTS (SELECT 1 FROM pg_index i WHERE i.indrelid = c.oid)
		OR EXISTS (SELECT 1 FROM pg_class toast WHERE toast.oid = c.reltoastrelid AND toast.reloptions IS NOT NULL)
		OR EXISTS (SELECT 1 FROM pg_trigger t WHERE t.tgrelid = c.oid)
		OR EXISTS (SELECT 1 FROM pg_rewrite r WHERE r.ev_class = c.oid AND r.rulename <> '_RETURN')
		OR EXISTS (SELECT 1 FROM pg_seclabel s WHERE s.classoid = 'pg_class'::regclass AND s.objoid = c.oid)
		OR EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass
			AND d.objid = c.oid AND d.deptype = 'e')
		OR EXISTS (SELECT 1 FROM pg_depend d WHERE d.refclassid = 'pg_class'::regclass
			AND d.refobjid = c.oid AND d.deptype IN ('a', 'i')
			AND NOT (d.classid = 'pg_rewrite'::regclass AND EXISTS (
				SELECT 1 FROM pg_rewrite r WHERE r.oid = d.objid AND r.rulename = '_RETURN'))
			AND NOT (d.classid = 'pg_type'::regclass AND d.objid = c.reltype)
			AND NOT (d.classid = 'pg_class'::regclass AND d.objid = c.reltoastrelid))
		OR EXISTS (SELECT 1 FROM pg_type t WHERE t.oid IN (c.reltype,
				(SELECT typ.typarray FROM pg_type typ WHERE typ.oid = c.reltype))
			AND (t.typacl IS NOT NULL
				OR EXISTS (SELECT 1 FROM pg_description d WHERE d.classoid = 'pg_type'::regclass AND d.objoid = t.oid)
				OR EXISTS (SELECT 1 FROM pg_seclabel s WHERE s.classoid = 'pg_type'::regclass AND s.objoid = t.oid)))
		THEN 1 ELSE 0 END AS unsupported
FROM ordered
JOIN pg_class c ON c.oid = ordered.oid
JOIN pg_namespace n ON n.oid = c.relnamespace
ORDER BY ordered.depth DESC, c.oid
SQL, ['table' => $table, 'attnum' => $target[0]['attnum']]);

			foreach ($views as &$view)
			{
				if ((int)$view['unsupported'])
				{
					throw new RuntimeException("Cannot safely rebuild view {$view['name']}: unsupported metadata (such as privileges, options, indexes, triggers or rules)");
				}
				$view['comments'] = $this->fetchAll($db, <<<'SQL'
SELECT CASE WHEN d.objsubid = 0
	THEN format('COMMENT ON %s %s IS %L', CASE WHEN c.relkind = 'm' THEN 'MATERIALIZED VIEW' ELSE 'VIEW' END,
		format('%I.%I', n.nspname, c.relname), d.description)
	ELSE format('COMMENT ON COLUMN %I.%I.%I IS %L', n.nspname, c.relname, a.attname, d.description)
	END AS statement
FROM pg_description d
JOIN pg_class c ON c.oid = d.objoid
JOIN pg_namespace n ON n.oid = c.relnamespace
LEFT JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = d.objsubid
WHERE d.classoid = 'pg_class'::regclass AND d.objoid = :oid
SQL, ['oid' => $view['oid']]);
			}
			unset($view);

			foreach ($views as $view)
			{
				$kind = $view['relkind'] === 'm' ? 'MATERIALIZED VIEW' : 'VIEW';
				$this->exec($db, "DROP {$kind} {$view['name']}");
			}
			if (!$alter())
			{
				throw new RuntimeException("Could not alter column {$table}.{$column}");
			}

			foreach (array_reverse($views) as $view)
			{
				$kind = $view['relkind'] === 'm' ? 'MATERIALIZED VIEW' : 'VIEW';
				$columns = $view['columns'] === null ? '' : " ({$view['columns']})";
				$accessMethod = $kind === 'MATERIALIZED VIEW' ? " USING {$view['access_method']}" : '';
				$sql = "CREATE {$kind} {$view['name']}{$columns}{$accessMethod} AS {$view['definition']}";
				$sql = rtrim($sql, " \t\n\r;") . ($kind === 'MATERIALIZED VIEW' ? ' WITH NO DATA' : '');
				$this->exec($db, $sql);
				foreach ($view['comments'] as $comment)
				{
					$this->exec($db, $comment['statement']);
				}
				$this->exec($db, "ALTER {$kind} {$view['name']} OWNER TO {$view['owner']}");
				if ($kind === 'MATERIALIZED VIEW' && in_array($view['relispopulated'], [true, 1, '1', 't'], true))
				{
					$this->exec($db, "REFRESH MATERIALIZED VIEW {$view['name']}");
				}

				$acl = $this->fetchAll($db, <<<'SQL'
SELECT c.relacl IS NULL AND NOT EXISTS (
	SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attacl IS NOT NULL
) AS unchanged FROM pg_class c WHERE c.oid = to_regclass(:name)
SQL, ['name' => $view['name']]);
				if (empty($acl[0]['unchanged']))
				{
					throw new RuntimeException("Cannot safely rebuild view {$view['name']}: default privileges changed");
				}
			}

			if ($nested)
			{
				$this->exec($db, "RELEASE SAVEPOINT {$savepoint}");
			}
			elseif (!$db->commit())
			{
				throw new RuntimeException('Could not commit the view rebuild transaction');
			}
			return true;
		}
		catch (Throwable $e)
		{
			if ($nested)
			{
				$this->exec($db, "ROLLBACK TO SAVEPOINT {$savepoint}");
				$this->exec($db, "RELEASE SAVEPOINT {$savepoint}");
			}
			elseif ($db->inTransaction())
			{
				$db->rollBack();
			}
			throw $e;
		}
	}

	private function exec($db, string $sql): void
	{
		if ($db->exec($sql) === false)
		{
			throw new RuntimeException("PostgreSQL statement failed: {$sql}");
		}
	}

	private function fetchAll($db, string $sql, array $parameters): array
	{
		$statement = $db->prepare($sql);
		if (!$statement || !$statement->execute($parameters))
		{
			throw new RuntimeException('PostgreSQL view dependency query failed');
		}
		return $statement->fetchAll(\PDO::FETCH_ASSOC);
	}
}

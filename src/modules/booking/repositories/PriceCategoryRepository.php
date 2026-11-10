<?php

namespace App\modules\booking\repositories;

use App\Database\Db;
use PDO;
use PDOException;

/**
 * The global list of price categories (bb_price_category, ref #1477) that a
 * resource's price rows link to with bb_article_price.category_id.
 */
class PriceCategoryRepository
{
	/** Longest name: bb_price_category.name and bb_purchase_order_line.price_label are varchar(100) */
	public const NAME_MAX_LENGTH = 100;

	/**
	 * Whitespace a name may not start or end with, the same set as the
	 * bb_price_category_name_trimmed_check constraint
	 */
	private const EDGE_SPACE = '/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u';

	/** A name that is only an amount, as bb_price_category_name_not_amount_check refuses */
	private const AMOUNT_ONLY = '/^[0-9]+([.,][0-9]+)?$/';

	private static ?bool $installed = null;

	private Db $db;

	public function __construct()
	{
		$this->db = Db::getInstance();
	}

	/**
	 * Whether m20261103_create_bb_price_category has run. Until it has, the
	 * code that reads or writes categories keeps to the columns that exist,
	 * so deploying the code before the migration does not break pricing.
	 * Asked once per request. The migration is one transaction, so the table
	 * and both columns are there together or not at all.
	 *
	 * Transitional: once every environment has run the migration, the check
	 * and the branches on it can go.
	 */
	public static function isInstalled(): bool
	{
		if (self::$installed === null)
		{
			$stmt = Db::getInstance()->prepare(
				"SELECT to_regclass('public.bb_price_category') IS NOT NULL
					AND (SELECT COUNT(*) FROM information_schema.columns
						WHERE table_schema = 'public' AND column_name = 'category_id'
						AND table_name IN ('bb_article_price', 'bb_purchase_order_line')) = 2 AS installed"
			);
			$stmt->execute();
			$value = $stmt->fetchColumn();
			self::$installed = $value === true || $value === 't' || $value === 1 || $value === '1';
		}

		return self::$installed;
	}

	/**
	 * A name as it is stored: without surrounding whitespace. Null when it
	 * cannot be one (empty, too long, or only an amount).
	 */
	public static function normalizeName($name): ?string
	{
		if (!is_string($name) || !mb_check_encoding($name, 'UTF-8'))
		{
			return null;
		}

		$name = (string)preg_replace(self::EDGE_SPACE, '', $name);

		if ($name === '' || mb_strlen($name, 'UTF-8') > self::NAME_MAX_LENGTH || preg_match(self::AMOUNT_ONLY, $name))
		{
			return null;
		}

		return $name;
	}

	/**
	 * The categories offered for a price row: the active ones, plus any of
	 * $keepIds (the categories rows already have) so that a row's own
	 * category does not drop out of its list.
	 *
	 * @param int[] $keepIds
	 * @return array Rows of id, name, active, sort
	 */
	public function listForSelect(array $keepIds = []): array
	{
		$keepIds = array_values(array_unique(array_map('intval', $keepIds)));
		$params = [];
		$keep = '';
		if ($keepIds)
		{
			$keep = ' OR id IN (' . implode(',', array_fill(0, count($keepIds), '?')) . ')';
			$params = $keepIds;
		}

		$stmt = $this->db->prepare(
			"SELECT id, name, active, sort FROM bb_price_category
			WHERE active = 1{$keep}
			ORDER BY sort, lower(name), id"
		);
		$stmt->execute($params);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function exists(int $id): bool
	{
		$stmt = $this->db->prepare("SELECT 1 FROM bb_price_category WHERE id = :id");
		$stmt->execute([':id' => $id]);

		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Add a category. The name must already be normalized.
	 *
	 * @return array{created: bool, category: array|null} created false with the
	 *         category already named so, ignoring case; category null when the
	 *         database refused the name
	 */
	public function create(string $name): array
	{
		// Uniqueness is the database's: insert and read a violation, so two
		// admins adding the same name at once cannot both succeed
		$stmt = $this->db->prepare(
			"INSERT INTO bb_price_category (name, active, sort) VALUES (:name, 1, 0)
			RETURNING id, name, active, sort"
		);

		try
		{
			$ok = $stmt->execute([':name' => $name]);
			$state = $ok ? null : (string)($stmt->errorInfo()[0] ?? '');
		}
		catch (PDOException $e)
		{
			$state = (string)$e->getCode();
		}

		if ($state === null)
		{
			return ['created' => true, 'category' => $stmt->fetch(PDO::FETCH_ASSOC)];
		}

		if ($state === '23505')
		{
			return ['created' => false, 'category' => $this->findByName($name)];
		}

		if ($state === '23514')
		{
			return ['created' => false, 'category' => null];
		}

		throw new \RuntimeException("Could not add price category (SQLSTATE {$state})");
	}

	private function findByName(string $name): ?array
	{
		$stmt = $this->db->prepare(
			"SELECT id, name, active, sort FROM bb_price_category WHERE lower(name) = lower(:name)"
		);
		$stmt->execute([':name' => $name]);

		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}
}

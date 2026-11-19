<?php

namespace App\modules\property\helpers;

use App\Database\Db;
use DomainException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class BoeiImportService
{
	private object $db;

	public function __construct(?object $db = null)
	{
		$this->db = $db ?? Db::getInstance();
	}

	public function refreshCandidates(): void
	{
		$this->transaction(function ()
		{
			$this->execute('LOCK TABLE boei_objekt, boei_leieobjekt IN SHARE MODE');
			$duplicates = $this->rows('SELECT objekt_id, leie_id FROM boei_leieobjekt'
				. ' GROUP BY objekt_id, leie_id HAVING COUNT(*) > 1');
			if ($duplicates)
			{
				throw new DomainException('Duplicate BOEI Objekt_ID/Leie_ID: '
					. implode(', ', array_map(fn($r) => $r['objekt_id'] . '/' . $r['leie_id'], $duplicates)));
			}
			$this->execute("INSERT INTO boei_objekt_candidate (objekt_id, status)
				SELECT o.objekt_id, CASE WHEN l.loc1 IS NULL THEN 'pending' ELSE 'existing' END
				FROM boei_objekt o LEFT JOIN fm_location1 l ON l.loc1 = o.objekt_id
				ON CONFLICT (objekt_id) DO NOTHING");
			$this->execute("INSERT INTO boei_leieobjekt_candidate (objekt_id, leie_id, street_id, street_number)
				SELECT b.objekt_id, b.leie_id, b.gateadresse_id, b.gatenr FROM boei_leieobjekt b
				WHERE NOT EXISTS (SELECT 1 FROM fm_location4 l
					WHERE l.loc1 = b.objekt_id AND l.loc4 = b.leie_id)
				ON CONFLICT (objekt_id, leie_id) DO NOTHING");
		});
	}

	public function listObjects(string $loc1 = ''): array
	{
		return $this->rows("SELECT c.*, o.navn,
			COALESCE(u.total_units, 0) AS total_units, COALESCE(u.pending_units, 0) AS pending_units,
			COALESCE(u.approved_units, 0) AS approved_units, COALESCE(u.imported_units, 0) AS imported_units,
			CASE WHEN o.objekt_id IS NULL THEN 0 ELSE 1 END AS source_present
			FROM boei_objekt_candidate c LEFT JOIN boei_objekt o ON o.objekt_id = c.objekt_id
			LEFT JOIN (SELECT objekt_id, COUNT(*) AS total_units,
				COUNT(*) FILTER (WHERE status = 'pending') AS pending_units,
				COUNT(*) FILTER (WHERE status = 'approved') AS approved_units,
				COUNT(*) FILTER (WHERE status = 'imported') AS imported_units
				FROM boei_leieobjekt_candidate GROUP BY objekt_id) u ON u.objekt_id = c.objekt_id
			WHERE (:filter = '' OR c.objekt_id = :loc1)
			AND (c.status != 'existing' OR EXISTS (SELECT 1 FROM boei_leieobjekt_candidate u
				WHERE u.objekt_id = c.objekt_id AND u.status != 'imported'))
			ORDER BY c.objekt_id", ['filter' => $loc1, 'loc1' => $loc1]);
	}

	public function listUnits(string $loc1): array
	{
		return $this->rows("SELECT c.*, s.descr AS street_name, source_street.descr AS source_street_name,
			b.gatenr AS source_address, b.gateadresse_id AS source_street_id,
			CASE WHEN b.objekt_id IS NULL THEN 0 ELSE 1 END AS source_present
			FROM boei_leieobjekt_candidate c
			LEFT JOIN boei_leieobjekt b ON b.objekt_id = c.objekt_id AND b.leie_id = c.leie_id
			LEFT JOIN fm_streetaddress s ON s.id = c.street_id
			LEFT JOIN fm_streetaddress source_street ON source_street.id = b.gateadresse_id
			WHERE c.objekt_id = :loc1 ORDER BY c.leie_id", ['loc1' => $loc1]);
	}

	public function saveObject(string $loc1, array $values, bool $approve, int $account): void
	{
		$this->transaction(function () use ($loc1, $values, $approve, $account)
		{
			$candidate = $this->one('SELECT * FROM boei_objekt_candidate WHERE objekt_id = ? FOR UPDATE', [$loc1]);
			$this->assertEditable($candidate);
			$gnr = $this->positiveNumber($values['gnr'] ?? '', 'Gnr', $approve);
			$bnr = $this->positiveNumber($values['bnr'] ?? '', 'Bnr', $approve);
			$this->execute(
				'UPDATE boei_objekt_candidate SET gnr = ?, bnr = ?, status = ?,'
					. ' approved_by = ?, approved_at = ? WHERE objekt_id = ?',
				[$gnr, $bnr, $approve ? 'approved' : 'pending', $approve ? $account : null, $approve ? time() : null, $loc1]
			);
		});
	}

	public function saveUnit(string $loc1, string $loc4, array $values, bool $approve, int $account, bool $propagate = false): void
	{
		$this->transaction(function () use ($loc1, $loc4, $values, $approve, $account, $propagate)
		{
			$this->one('SELECT objekt_id FROM boei_objekt_candidate WHERE objekt_id = ? FOR UPDATE', [$loc1]);
			$candidate = $this->one('SELECT * FROM boei_leieobjekt_candidate'
				. ' WHERE objekt_id = ? AND leie_id = ? FOR UPDATE', [$loc1, $loc4]);
			$this->assertEditable($candidate);
			$data = $this->validateUnit(array_replace($values, ['street_id' => $candidate['street_id']]), $approve);
			if ($propagate && ($data['street_id'] === null || $data['street_number'] === ''))
			{
				throw new DomainException('A street ID and house number are required to propagate candidate values.');
			}
			$this->storeUnit($loc1, $loc4, $data, $approve, $account);
			if (!$propagate)
			{
				return;
			}
			$targets = $this->rows(
				"SELECT * FROM boei_leieobjekt_candidate
				WHERE objekt_id = ? AND leie_id > ? AND street_id = ?
				AND status IN ('pending', 'approved') ORDER BY leie_id FOR UPDATE",
				[$loc1, $loc4, $data['street_id']]
			);
			foreach ($targets as $target)
			{
				if (self::normalizeStreetNumber($target['street_number'] ?? '') !== $data['street_number'])
				{
					continue;
				}
				$changed = false;
				foreach (['bygningsnr', 'gate_kode', 'adf'] as $field)
				{
					if (trim((string)$target[$field]) === '' && $data[$field] !== null && $data[$field] !== '')
					{
						$target[$field] = $data[$field];
						$changed = true;
					}
				}
				if ($changed)
				{
					$complete = $target['bygningsnr'] !== null && $target['gate_kode'] !== null
						&& trim((string)$target['adf']) !== '' && $data['street_number'] !== '';
					$approveTarget = $approve && $complete;
					$targetData = $this->validateUnit($target, $approveTarget);
					$this->storeUnit($loc1, $target['leie_id'], $targetData, $approveTarget, $account);
				}
			}
		});
	}

	private function storeUnit(string $loc1, string $loc4, array $data, bool $approve, int $account): void
	{
		$this->execute(
			'UPDATE boei_leieobjekt_candidate SET bygningsnr = ?, gate_kode = ?,'
				. ' street_number = ?, adf = ?, status = ?, approved_by = ?, approved_at = ?'
				. ' WHERE objekt_id = ? AND leie_id = ?',
			[
				$data['bygningsnr'],
				$data['gate_kode'],
				$data['street_number'],
				$data['adf'],
				$approve ? 'approved' : 'pending',
				$approve ? $account : null,
				$approve ? time() : null,
				$loc1,
				$loc4
			]
		);
	}

	public function importApproved(string $loc1): int
	{
		return $this->transaction(function () use ($loc1)
		{
			// Serialize allocation within an Objekt, including imports from cron and the admin UI.
			$object = $this->one('SELECT * FROM boei_objekt_candidate WHERE objekt_id = ? FOR UPDATE', [$loc1]);
			if (!$object || !in_array($object['status'], ['approved', 'existing', 'imported'], true))
			{
				throw new DomainException('The Objekt must be approved before import.');
			}
			$this->execute('LOCK TABLE boei_objekt, boei_leieobjekt IN SHARE MODE');
			$units = $this->rows("SELECT * FROM boei_leieobjekt_candidate
				WHERE objekt_id = ? AND status = 'approved' ORDER BY leie_id FOR UPDATE", [$loc1]);
			if (!$units)
			{
				return 0;
			}
			if (!$this->one('SELECT objekt_id FROM boei_objekt WHERE objekt_id = ?', [$loc1]))
			{
				throw new DomainException('Objekt is no longer present in the BOEI source.');
			}
			$this->createObject($loc1, $object);
			$count = 0;
			foreach ($units as $unit)
			{
				$data = $this->validateUnit($unit, true);
				$sources = $this->rows('SELECT b.*, t.navn AS tv_signal FROM boei_leieobjekt b
					LEFT JOIN boei_leieobjekt_tv_signal t ON t.id = b.tv_signal_id
					WHERE b.objekt_id = ? AND b.leie_id = ?', [$loc1, $unit['leie_id']]);
				if (count($sources) !== 1)
				{
					throw new DomainException('Leieobjekt must occur exactly once in the current BOEI source.');
				}
				$source = $sources[0];
				if ($this->one('SELECT loc4 FROM fm_location4 WHERE loc1 = ? AND loc4 = ?', [$loc1, $unit['leie_id']]))
				{
					throw new DomainException('Leieobjekt already exists in Portico; no existing location was changed.');
				}
				$loc2 = $this->findBuilding($loc1, $data['bygningsnr']);
				$loc3 = $this->findEntrance($loc1, $loc2, $data);
				$code = "{$loc1}-{$loc2}-{$loc3}-{$unit['leie_id']}";
				$this->insert('fm_location4', [
					'location_code' => $code,
					'loc1' => $loc1,
					'loc2' => $loc2,
					'loc3' => $loc3,
					'loc4' => $unit['leie_id'],
					'bygningsnr' => $data['bygningsnr'],
					'gate_kode' => $data['gate_kode'],
					'adf' => $data['adf'],
					'street_id' => $data['street_id'],
					'street_number' => $data['street_number'],
					'category' => $source['formaal_id'],
					'etasje' => $source['etasje'],
					'loc4_name' => $source['etasje'],
					'antallrom' => $source['antallrom'],
					'boareal' => $source['boareal'],
					'livslopsstd' => $source['livslopsstd'],
					'heis' => $source['heis'],
					'driftsstatus_id' => $source['driftsstatus_id'],
					'tenant_id' => $source['leietaker_id'],
					'beregnet_boa' => $source['beregnet_boa'],
					'flyttenr' => $source['flyttenr'],
					'tv_signal' => $source['tv_signal'],
					'user_id' => $unit['approved_by'],
					'entry_date' => time(),
				]);
				$this->registerLocation(4, $code, $loc1, $source['etasje']);
				$this->updateNames($loc1, $loc2, $loc3);
				$this->execute("UPDATE boei_leieobjekt_candidate SET status = 'imported', imported_at = ?, location_code = ?
					WHERE objekt_id = ? AND leie_id = ?", [time(), $code, $loc1, $unit['leie_id']]);
				$count++;
			}
			return $count;
		});
	}

	public function importAllApproved(): int
	{
		$objects = $this->rows("SELECT DISTINCT o.objekt_id FROM boei_objekt_candidate o
			JOIN boei_leieobjekt_candidate u ON u.objekt_id = o.objekt_id
			WHERE o.status IN ('approved', 'existing', 'imported') AND u.status = 'approved' ORDER BY o.objekt_id");
		$count = 0;
		foreach ($objects as $object)
		{
			$count += $this->importApproved($object['objekt_id']);
		}
		return $count;
	}

	private function createObject(string $loc1, array $candidate): void
	{
		if ($this->one('SELECT loc1 FROM fm_location1 WHERE loc1 = ? FOR UPDATE', [$loc1]))
		{
			return;
		}
		$this->positiveNumber($candidate['gnr'], 'Gnr', true);
		$this->positiveNumber($candidate['bnr'], 'Bnr', true);
		$source = $this->one('SELECT o.*, e.eiertype_id FROM boei_objekt o
			JOIN boei_eier e ON e.eier_id = o.eier_id WHERE o.objekt_id = ?', [$loc1]);
		if (!$source)
		{
			throw new DomainException('Objekt or owner is missing from the BOEI source.');
		}
		$ownerType = (int)$source['eiertype_id'] ?: 5;
		$service = (int)$source['tjenestested'];
		$mva = ($service === 26555 || ($ownerType !== 5 && $service === 26550)) ? 0 : 75;
		$this->insert('fm_location1', [
			'location_code' => $loc1,
			'loc1' => $loc1,
			'loc1_name' => $source['navn'],
			'part_of_town_id' => $source['bydel_id'],
			'owner_id' => $source['eier_id'],
			'kostra_id' => $service,
			'zip_code' => $source['postnr_id'],
			'category' => 1,
			'mva' => $mva,
			'entry_date' => time(),
			'user_id' => $candidate['approved_by'],
		]);
		$this->registerLocation(1, $loc1, $loc1, $source['navn']);
		$this->execute("UPDATE boei_objekt_candidate SET status = 'imported', imported_at = ? WHERE objekt_id = ?", [time(), $loc1]);
	}

	private function findBuilding(string $loc1, int $building): string
	{
		$matches = $this->rows('SELECT DISTINCT loc2 FROM fm_location4 WHERE loc1 = ? AND bygningsnr = ?', [$loc1, $building]);
		if (count($matches) > 1)
		{
			throw new DomainException('The building number belongs to multiple existing buildings. Correct the existing hierarchy first.');
		}
		if ($matches)
		{
			$loc2 = $matches[0]['loc2'];
			$other = $this->one('SELECT loc4 FROM fm_location4 WHERE loc1 = ? AND loc2 = ?
				AND (bygningsnr IS NULL OR bygningsnr != ?) LIMIT 1', [$loc1, $loc2, $building]);
			if ($other)
			{
				throw new DomainException('The existing building contains missing or conflicting building numbers.');
			}
			return $loc2;
		}
		$loc2 = $this->nextNumber($this->rows('SELECT loc2 AS number FROM fm_location2 WHERE loc1 = ?', [$loc1]));
		$this->insert('fm_location2', [
			'location_code' => "{$loc1}-{$loc2}",
			'loc1' => $loc1,
			'loc2' => $loc2,
			'category' => 98,
		]);
		return $loc2;
	}

	private function findEntrance(string $loc1, string $loc2, array $data): string
	{
		$rows = $this->rows('SELECT loc3, street_id, street_number FROM fm_location4 WHERE loc1 = ? AND loc2 = ?', [$loc1, $loc2]);
		$matches = [];
		foreach ($rows as $row)
		{
			if (
				(int)$row['street_id'] === $data['street_id']
				&& self::normalizeStreetNumber((string)$row['street_number']) === $data['street_number']
			)
			{
				$matches[$row['loc3']] = true;
			}
		}
		if (count($matches) > 1)
		{
			throw new DomainException('The address belongs to multiple existing entrances.');
		}
		if ($matches)
		{
			$loc3 = (string)array_key_first($matches);
			foreach ($rows as $row)
			{
				if ($row['loc3'] === $loc3 && ((int)$row['street_id'] !== $data['street_id']
					|| self::normalizeStreetNumber((string)$row['street_number']) !== $data['street_number']))
				{
					throw new DomainException('The existing entrance contains conflicting addresses.');
				}
			}
			return $loc3;
		}
		$loc3 = $this->nextNumber($this->rows('SELECT loc3 AS number FROM fm_location3 WHERE loc1 = ? AND loc2 = ?', [$loc1, $loc2]));
		$this->insert('fm_location3', [
			'location_code' => "{$loc1}-{$loc2}-{$loc3}",
			'loc1' => $loc1,
			'loc2' => $loc2,
			'loc3' => $loc3,
			'category' => 98,
		]);
		return $loc3;
	}

	private function nextNumber(array $rows): string
	{
		$used = array_map(fn($r) => (int)$r['number'], $rows);
		for ($number = 1; $number <= 99; $number++)
		{
			if (!in_array($number, $used, true))
			{
				return str_pad((string)$number, 2, '0', STR_PAD_LEFT);
			}
		}
		throw new DomainException('No free two-digit hierarchy number remains (01-99).');
	}

	private function updateNames(string $loc1, string $loc2, string $loc3): void
	{
		$addresses = [];
		$entranceName = null;
		foreach (
			$this->rows('SELECT l.loc3, l.street_number, s.descr FROM fm_location4 l
			JOIN fm_streetaddress s ON s.id = l.street_id WHERE l.loc1 = ? AND l.loc2 = ?
			ORDER BY s.descr, l.street_number', [$loc1, $loc2]) as $row
		)
		{
			$number = self::normalizeStreetNumber($row['street_number']);
			$addresses[$row['descr']][$number] = $number;
			if ($row['loc3'] === $loc3)
			{
				$entranceName = trim($row['descr'] . ' ' . $number);
			}
		}
		if ($entranceName === null)
		{
			throw new RuntimeException('Cannot generate an entrance name without a registered street.');
		}
		$buildingName = LocationHierarchyAnalyzer::generateLoc2Name(array_map('array_values', $addresses));
		$this->execute('UPDATE fm_location2 SET loc2_name = ? WHERE loc1 = ? AND loc2 = ?', [$buildingName, $loc1, $loc2]);
		$this->execute('UPDATE fm_location3 SET loc3_name = ? WHERE loc1 = ? AND loc2 = ? AND loc3 = ?', [$entranceName, $loc1, $loc2, $loc3]);
		$this->registerLocation(2, "{$loc1}-{$loc2}", $loc1, $buildingName);
		$this->registerLocation(3, "{$loc1}-{$loc2}-{$loc3}", $loc1, $entranceName);
	}

	private function registerLocation(int $level, string $code, string $loc1, ?string $name): void
	{
		$this->execute('INSERT INTO fm_locations (level, location_code, loc1, name) VALUES (?, ?, ?, ?)
			ON CONFLICT (location_code) DO UPDATE SET name = EXCLUDED.name', [$level, $code, $loc1, $name]);
	}

	private function validateUnit(array $values, bool $required): array
	{
		$building = $this->positiveNumber($values['bygningsnr'] ?? '', 'Bygningsnummer', $required);
		$gate = $this->positiveNumber($values['gate_kode'] ?? '', 'Gatekode', $required);
		$number = self::normalizeStreetNumber(trim((string)($values['street_number'] ?? '')));
		$adf = trim((string)($values['adf'] ?? ''));
		if (($required || $number !== '') && (!preg_match('/^[1-9][0-9]*[A-Z]?$/D', $number) || strlen($number) > 10))
		{
			throw new DomainException('Enter a house number with an optional letter, for example 15A.');
		}
		if (($required && $adf === '') || mb_strlen($adf) > 20)
		{
			throw new DomainException('ADF must contain 1-20 characters before approval.');
		}
		$streetId = $this->positiveNumber($values['street_id'] ?? '', 'Street ID', $required);
		if ($required && !$this->one('SELECT id FROM fm_streetaddress WHERE id = ?', [$streetId]))
		{
			throw new DomainException('Street ID from BOEI must exist in the street register.');
		}
		return ['bygningsnr' => $building, 'gate_kode' => $gate, 'street_id' => $streetId, 'street_number' => $number, 'adf' => $adf];
	}

	public static function normalizeStreetNumber(string $number): string
	{
		return strtoupper(preg_replace('/\s+/', '', $number));
	}

	private function positiveNumber($value, string $label, bool $required): ?int
	{
		if ($value === null || $value === '')
		{
			if (!$required)
			{
				return null;
			}
			throw new DomainException($label . ' is required before approval.');
		}
		$value = trim((string)$value);
		if (!ctype_digit($value) || (int)$value < 1 || (float)$value > 2147483647)
		{
			throw new DomainException($label . ' must be a positive integer (maximum 2147483647).');
		}
		return (int)$value;
	}

	private function assertEditable(?array $candidate): void
	{
		if (!$candidate || !in_array($candidate['status'], ['pending', 'approved'], true))
		{
			throw new DomainException('Only pending or approved candidates can be edited.');
		}
	}

	private function insert(string $table, array $values): void
	{
		$this->execute('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($values)) . ') VALUES ('
			. implode(', ', array_fill(0, count($values), '?')) . ')', array_values($values));
	}

	private function execute(string $sql, array $params = []): PDOStatement
	{
		$statement = $this->db->prepare($sql);
		if (!$statement || !$statement->execute($params))
		{
			throw new RuntimeException('BOEI import database operation failed.');
		}
		return $statement;
	}

	private function rows(string $sql, array $params = []): array
	{
		return $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
	}

	private function one(string $sql, array $params = []): ?array
	{
		$row = $this->execute($sql, $params)->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	private function transaction(callable $operation)
	{
		$this->db->beginTransaction();
		try
		{
			$result = $operation();
			$this->db->commit();
			return $result;
		}
		catch (Throwable $e)
		{
			$this->db->rollBack();
			throw $e;
		}
	}
}

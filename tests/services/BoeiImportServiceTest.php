<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database\Db;
use App\modules\phpgwapi\services\SchemaProc\SchemaProc;
use App\modules\property\helpers\BoeiImportService;
use PHPUnit\Framework\TestCase;

final class BoeiImportServiceTest extends TestCase
{
	private PDO $db;
	private BoeiImportService $service;

	protected function setUp(): void
	{
		$domain = getenv('BOEI_TEST_DOMAIN');
		if (!$domain)
		{
			$this->markTestSkipped('Set BOEI_TEST_DOMAIN to run PostgreSQL tests in connection-local temporary tables.');
		}
		$settings = require __DIR__ . '/../../config/header.inc.php';
		$config = $settings['phpgw_domain'][$domain];
		$this->db = new PDO(Db::CreateDsn($config), $config['db_user'], $config['db_pass'], [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_EMULATE_PREPARES => false,
		]);
		// No unqualified query can reach a persistent application table.
		$this->db->exec('SET search_path TO pg_temp');
		foreach (['boei_objekt', 'boei_leieobjekt', 'boei_eier', 'boei_leieobjekt_tv_signal', 'boei_reskontro',
			'fm_streetaddress', 'fm_location1', 'fm_location2', 'fm_location3', 'fm_location4'] as $table)
		{
			$this->db->exec("CREATE TEMP TABLE {$table} (LIKE public.{$table} INCLUDING CONSTRAINTS)");
		}
		$this->db->exec('CREATE TEMP TABLE fm_locations (
			id SERIAL PRIMARY KEY, level INTEGER NOT NULL, location_code VARCHAR(50) UNIQUE NOT NULL, loc1 VARCHAR(6), name TEXT)');
		require __DIR__ . '/../../src/modules/property/setup/tables_current.inc.php';
		$schema = new SchemaProc('postgres');
		ob_start();
		$schema->GenerateScripts(array_intersect_key($phpgw_baseline, array_flip([
			'boei_objekt_candidate', 'boei_leieobjekt_candidate',
		])), true);
		$sql = strip_tags(ob_get_clean());
		$this->db->exec(str_replace('CREATE TABLE ', 'CREATE TEMP TABLE ', $sql));
		$this->insert('boei_objekt', ['objekt_id' => '5000', 'navn' => 'Test property', 'eier_id' => 1,
			'bydel_id' => 1, 'tjenestested' => 26550, 'postnr_id' => '5000']);
		$this->insert('boei_eier', ['eier_id' => 1, 'eiertype_id' => 5]);
		$this->insert('boei_leieobjekt_tv_signal', ['id' => 1, 'navn' => 'Cable']);
		$this->insert('fm_streetaddress', ['id' => 11, 'nasjonalid' => 1001, 'descr' => "King's road"]);
		$this->service = new BoeiImportService($this->db);
	}

	protected function tearDown(): void
	{
		unset($this->service, $this->db);
	}

	public function testApprovedCandidatesCreateAndReuseFullHierarchyWithoutBoeiBuildingIds(): void
	{
		foreach (['001', '002', '003', '004'] as $id)
		{
			$this->sourceUnit($id);
		}
		$this->service->refreshCandidates();
		$this->service->saveObject('5000', ['gnr' => 10, 'bnr' => 20], true, 7);
		foreach (['001' => '10 a', '002' => '10A', '003' => '10B', '004' => '20'] as $id => $address)
		{
			$this->service->saveUnit('5000', $id, $this->values($id === '004' ? 456 : 123, $address), true, 7);
		}
		$this->service->refreshCandidates();
		$this->assertSame(4, $this->service->importApproved('5000'));
		$locations = $this->db->query('SELECT loc2, loc3, loc4, street_number, bygningsnr, gate_kode, adf
			FROM fm_location4 ORDER BY loc4')->fetchAll(PDO::FETCH_ASSOC);
		$this->assertSame(['01', '01', '01', '02'], array_column($locations, 'loc2'));
		$this->assertSame(['01', '01', '02', '01'], array_column($locations, 'loc3'));
		$this->assertSame(['001', '002', '003', '004'], array_column($locations, 'loc4'));
		$this->assertSame(['10A', '10A', '10B', '20'], array_column($locations, 'street_number'));
		$this->assertSame([123, 123, 123, 456], array_column($locations, 'bygningsnr'));
		$this->assertSame([1001, 1001, 1001, 1001], array_column($locations, 'gate_kode'));
		$this->assertSame(['ADF-1', 'ADF-1', 'ADF-1', 'ADF-1'], array_column($locations, 'adf'));
		$this->assertSame("King's road 10A, 10B", $this->scalar("SELECT loc2_name FROM fm_location2 WHERE loc2 = '01'"));
		$this->assertSame("King's road 10B", $this->scalar("SELECT loc3_name FROM fm_location3 WHERE loc2 = '01' AND loc3 = '02'"));
		$this->assertSame(10, (int)$this->scalar('SELECT COUNT(*) FROM fm_locations'));
		$this->assertSame('imported', $this->scalar('SELECT status FROM boei_objekt_candidate'));
		$this->service->refreshCandidates();
		$this->assertSame(0, $this->service->importAllApproved());
		$this->assertCount(4, $this->service->listUnits('5000'));
	}

	public function testNewCandidatesCopySourceAddressWithoutOverwritingManualCorrections(): void
	{
		$this->sourceUnit('001');
		$this->db->exec('UPDATE boei_leieobjekt SET gateadresse_id = 999');
		$this->insert('fm_streetaddress', ['id' => 999, 'nasjonalid' => 1999, 'descr' => 'Source road']);
		$this->service->refreshCandidates();
		$unit = $this->service->listUnits('5000')[0];
		$this->assertSame(999, (int)$unit['street_id']);
		$this->assertSame('999Z', $unit['street_number']);
		$this->assertSame('Source road', $unit['source_street_name']);
		$this->assertSame('pending', $unit['status']);

		$this->service->saveUnit('5000', '001', $this->values(), false, 7);
		$this->insert('fm_streetaddress', ['id' => 888, 'nasjonalid' => 1888, 'descr' => 'Updated source road']);
		$this->db->exec("UPDATE boei_leieobjekt SET gateadresse_id = 888, gatenr = '88B'");
		$this->service->refreshCandidates();
		$unit = $this->service->listUnits('5000')[0];
		$this->assertSame(999, (int)$unit['street_id']);
		$this->assertSame('10A', $unit['street_number']);
		$this->assertSame('Source road', $unit['street_name']);
		$this->assertSame(888, (int)$unit['source_street_id']);
		$this->assertSame('88B', $unit['source_address']);
		$this->assertSame('Updated source road', $unit['source_street_name']);
	}

	public function testDraftsSurviveRefreshAndCannotBeImportedUntilBothLevelsAreApproved(): void
	{
		$this->sourceUnit('001');
		$this->service->refreshCandidates();
		$this->service->saveUnit('5000', '001', ['adf' => 'unfinished'], false, 7);
		$this->service->refreshCandidates();
		$this->assertSame('unfinished', $this->service->listUnits('5000')[0]['adf']);
		$this->assertSame(0, $this->service->importAllApproved());
		try
		{
			$this->service->saveUnit('5000', '001', $this->values(), true, 7);
			$this->service->importApproved('5000');
			$this->fail('An unapproved Objekt must block import.');
		}
		catch (DomainException $e)
		{
			$this->assertStringContainsString('Objekt', $e->getMessage());
		}
		$this->assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM fm_location4'));
		$this->service->saveObject('5000', ['gnr' => 1, 'bnr' => 2], true, 7);
		$this->assertSame(1, $this->service->importApproved('5000'));
	}

	public function testPropagationOnlyFillsLaterMatchingUnimportedCandidatesAndApprovesUpdatedUnits(): void
	{
		foreach (['001', '002', '003', '004', '005', '006', '007', '008'] as $id)
		{
			$this->sourceUnit($id);
		}
		$this->db->exec("UPDATE boei_leieobjekt SET gatenr = '10 a'");
		$this->db->exec("UPDATE boei_leieobjekt SET gatenr = '20' WHERE leie_id = '005'");
		$this->db->exec("UPDATE boei_leieobjekt SET gateadresse_id = 12 WHERE leie_id = '006'");
		$this->service->refreshCandidates();
		$this->service->saveUnit('5000', '004', ['bygningsnr' => 456, 'street_number' => '10A'], false, 7);
		$this->service->saveUnit('5000', '008', $this->values(789), false, 7);
		$this->db->exec("UPDATE boei_leieobjekt_candidate SET status = 'imported' WHERE leie_id = '007'");
		$this->insert('boei_objekt_candidate', ['objekt_id' => '6000', 'status' => 'existing']);
		$this->insert('boei_leieobjekt_candidate', ['objekt_id' => '6000', 'leie_id' => '009',
			'street_id' => 11, 'street_number' => '10A']);

		$this->service->saveUnit('5000', '002', $this->values(), true, 7, true);
		$units = array_column($this->service->listUnits('5000'), null, 'leie_id');
		foreach (['003' => 123, '004' => 456] as $id => $building)
		{
			$this->assertSame($building, (int)$units[$id]['bygningsnr']);
			$this->assertSame(1001, (int)$units[$id]['gate_kode']);
			$this->assertSame('ADF-1', $units[$id]['adf']);
			$this->assertSame('approved', $units[$id]['status']);
			$this->assertSame(7, (int)$units[$id]['approved_by']);
			$this->assertNotNull($units[$id]['approved_at']);
		}
		foreach (['001', '005', '006', '007'] as $id)
		{
			$this->assertNull($units[$id]['bygningsnr']);
		}
		$this->assertSame('imported', $units['007']['status']);
		$this->assertSame(789, (int)$units['008']['bygningsnr']);
		$this->assertSame('pending', $units['008']['status']);
		$this->assertNull($this->service->listUnits('6000')[0]['bygningsnr']);
	}

	public function testPropagationIsOptionalAndDraftPropagationDoesNotApproveTargets(): void
	{
		$this->sourceUnit('001');
		$this->sourceUnit('002');
		$this->service->refreshCandidates();
		$values = ['bygningsnr' => 123, 'street_number' => '999Z'];
		try
		{
			$this->service->saveUnit('5000', '001', ['bygningsnr' => 123], false, 7, true);
			$this->fail('An incomplete address must not propagate values.');
		}
		catch (DomainException $e)
		{
			$this->assertStringContainsString('house number', $e->getMessage());
			$this->assertNull($this->service->listUnits('5000')[0]['bygningsnr']);
		}
		$this->service->saveUnit('5000', '001', $values, false, 7);
		$this->assertNull($this->service->listUnits('5000')[1]['bygningsnr']);
		$this->service->saveUnit('5000', '001', $values, false, 7, true);
		$unit = $this->service->listUnits('5000')[1];
		$this->assertSame(123, (int)$unit['bygningsnr']);
		$this->assertNull($unit['gate_kode']);
		$this->assertSame('', $unit['adf']);
		$this->assertSame('pending', $unit['status']);
		$this->assertNull($unit['approved_by']);
		$this->assertNull($unit['approved_at']);
	}

	public function testMissingRequiredFieldsCannotBeApproved(): void
	{
		$this->sourceUnit('001');
		$this->service->refreshCandidates();
		foreach ([
			array_replace($this->values(), ['bygningsnr' => '']),
			array_replace($this->values(), ['gate_kode' => '']),
			array_replace($this->values(), ['adf' => '']),
			array_replace($this->values(), ['street_number' => '']),
		] as $values)
		{
			try
			{
				$this->service->saveUnit('5000', '001', $values, true, 7);
				$this->fail('Incomplete candidate was approved.');
			}
			catch (DomainException $e)
			{
				$this->assertSame('pending', $this->service->listUnits('5000')[0]['status']);
			}
		}
	}

	public function testPublicStreetCodeDoesNotResolveOrReplaceInternalStreetId(): void
	{
		$this->sourceUnit('001');
		$this->service->refreshCandidates();
		$this->service->saveObject('5000', ['gnr' => 1, 'bnr' => 2], true, 7);
		$values = array_replace($this->values(), ['gate_kode' => 99999, 'street_id' => 999]);
		$this->service->saveUnit('5000', '001', $values, true, 7);
		$this->assertSame(11, (int)$this->service->listUnits('5000')[0]['street_id']);
		$this->assertSame(1, $this->service->importApproved('5000'));
		$this->assertSame(11, (int)$this->scalar('SELECT street_id FROM fm_location4'));
		$this->assertSame(99999, (int)$this->scalar('SELECT gate_kode FROM fm_location4'));
	}

	public function testUnknownInternalStreetBlocksApprovalAndImport(): void
	{
		$this->sourceUnit('001');
		$this->service->refreshCandidates();
		$this->service->saveObject('5000', ['gnr' => 1, 'bnr' => 2], true, 7);
		$this->db->exec('DELETE FROM fm_streetaddress WHERE id = 11');
		try
		{
			$this->service->saveUnit('5000', '001', $this->values(), true, 7);
			$this->fail('An unknown internal street must block approval.');
		}
		catch (DomainException $e)
		{
			$this->assertStringContainsString('Street ID', $e->getMessage());
			$this->assertSame('pending', $this->service->listUnits('5000')[0]['status']);
		}
		$this->insert('fm_streetaddress', ['id' => 11, 'nasjonalid' => 1001, 'descr' => "King's road"]);
		$this->service->saveUnit('5000', '001', $this->values(), true, 7);
		$this->db->exec('DELETE FROM fm_streetaddress WHERE id = 11');
		try
		{
			$this->service->importApproved('5000');
			$this->fail('An unknown internal street must block import.');
		}
		catch (DomainException $e)
		{
			$this->assertStringContainsString('Street ID', $e->getMessage());
			$this->assertSame('approved', $this->service->listUnits('5000')[0]['status']);
			$this->assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM fm_location4'));
		}
	}

	public function testFailureRollsBackWholeObjectAndLeavesApprovalsRetryable(): void
	{
		$this->sourceUnit('001');
		$this->sourceUnit('002');
		$this->service->refreshCandidates();
		$this->service->saveObject('5000', ['gnr' => 1, 'bnr' => 2], true, 7);
		foreach (['001', '002'] as $id)
		{
			$this->service->saveUnit('5000', $id, $this->values(), true, 7);
		}
		$this->db->exec("DELETE FROM boei_leieobjekt WHERE leie_id = '002'");
		try
		{
			$this->service->importApproved('5000');
			$this->fail('A missing source row must abort the import.');
		}
		catch (DomainException $e)
		{
			$this->assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM fm_location1'));
			$this->assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM fm_locations'));
			$this->assertSame(['approved', 'approved'], array_column($this->service->listUnits('5000'), 'status'));
		}
	}

	public function testExistingPropertyAndBuildingAreReusedWithoutReorganizingUnits(): void
	{
		$this->insert('fm_location1', ['loc1' => '5000', 'location_code' => '5000', 'loc1_name' => 'Existing']);
		$this->insert('fm_location2', ['loc1' => '5000', 'loc2' => '08', 'location_code' => '5000-08']);
		$this->insert('fm_location3', ['loc1' => '5000', 'loc2' => '08', 'loc3' => '04', 'location_code' => '5000-08-04']);
		$this->insert('fm_location4', ['loc1' => '5000', 'loc2' => '08', 'loc3' => '04', 'loc4' => '099',
			'location_code' => '5000-08-04-099', 'bygningsnr' => 123, 'street_id' => 11, 'street_number' => '10 A']);
		$this->sourceUnit('001');
		$this->service->refreshCandidates();
		$this->service->saveUnit('5000', '001', $this->values(), true, 7);
		$this->assertSame(1, $this->service->importApproved('5000'));
		$this->assertSame('5000-08-04-001', $this->scalar("SELECT location_code FROM fm_location4 WHERE loc4 = '001'"));
		$this->assertSame('5000-08-04-099', $this->scalar("SELECT location_code FROM fm_location4 WHERE loc4 = '099'"));
		$this->assertSame('Existing', $this->scalar('SELECT loc1_name FROM fm_location1'));
	}

	public function testDuplicateBoeiIdentityIsRejectedRatherThanUsingBuildingAndSection(): void
	{
		$this->sourceUnit('001');
		$this->sourceUnit('001', '98');
		$this->expectException(DomainException::class);
		$this->service->refreshCandidates();
	}

	public function testSameHouseNumberInDifferentStreetsCreatesSeparateEntrances(): void
	{
		$this->sourceUnit('001');
		$this->sourceUnit('002');
		$this->insert('fm_streetaddress', ['id' => 12, 'nasjonalid' => 1002, 'descr' => 'Second road']);
		$this->db->exec("UPDATE boei_leieobjekt SET gateadresse_id = 12 WHERE leie_id = '002'");
		$this->service->refreshCandidates();
		$this->service->saveObject('5000', ['gnr' => 1, 'bnr' => 2], true, 7);
		$this->service->saveUnit('5000', '001', $this->values(), true, 7);
		$this->service->saveUnit('5000', '002', $this->values(), true, 7);
		$this->assertSame(2, $this->service->importApproved('5000'));
		$this->assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM fm_location2'));
		$this->assertSame(2, (int)$this->scalar('SELECT COUNT(*) FROM fm_location3'));
	}

	public function testExhaustedBuildingNumbersAbortRatherThanCreatingAnInvalidCode(): void
	{
		$this->insert('fm_location1', ['loc1' => '5000', 'location_code' => '5000']);
		for ($n = 1; $n <= 99; $n++)
		{
			$loc2 = sprintf('%02d', $n);
			$this->insert('fm_location2', ['loc1' => '5000', 'loc2' => $loc2, 'location_code' => "5000-{$loc2}"]);
		}
		$this->sourceUnit('001');
		$this->service->refreshCandidates();
		$this->service->saveUnit('5000', '001', $this->values(), true, 7);
		try
		{
			$this->service->importApproved('5000');
			$this->fail('The two-digit range must not overflow.');
		}
		catch (DomainException $e)
		{
			$this->assertStringContainsString('01-99', $e->getMessage());
			$this->assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM fm_location4'));
			$this->assertSame('approved', $this->service->listUnits('5000')[0]['status']);
		}
	}

	public function testSynchronizationProtectsApprovedAddressAndNamesButKeepsOperationalUpdates(): void
	{
		$this->sourceUnit('001');
		$this->service->refreshCandidates();
		$this->service->saveObject('5000', ['gnr' => 1, 'bnr' => 2], true, 7);
		$this->service->saveUnit('5000', '001', $this->values(), true, 7);
		$this->service->importApproved('5000');
		$this->db->exec('UPDATE boei_leieobjekt SET boareal = 75, leietaker_id = 8');
		$this->insert('boei_reskontro', ['objekt_id' => '5000', 'leie_id' => '001',
			'flyttenr' => 1, 'leietaker_id' => 8, 'innflyttetdato' => '2026-01-01']);
		if (!function_exists('include_class'))
		{
			function include_class($module, $class, $path = 'inc/')
			{
				return require_once __DIR__ . "/../../src/modules/{$module}/{$path}class.{$class}.inc.php";
			}
		}
		require_once __DIR__ . '/../../src/modules/property/inc/cron/default/synkroniser_med_boei.php';
		$cron = new class($this->db) extends synkroniser_med_boei {
			public function __construct(PDO $db)
			{
				$this->db = new BoeiCronTestDatabase($db);
				$this->db2 = new BoeiCronTestDatabase($db);
			}
			public function cron_log($receipt = '')
			{
			}
		};
		$cron->oppdater_leieobjekt();
		$cron->oppdater_navn();
		$cron->oppdater_boa_bygg();
		$cron->oppdater_boa_del();
		$this->assertSame('10A', $this->scalar('SELECT street_number FROM fm_location4'));
		$this->assertSame(11, (int)$this->scalar('SELECT street_id FROM fm_location4'));
		$this->assertSame(75, (int)$this->scalar('SELECT boareal FROM fm_location4'));
		$this->assertSame(8, (int)$this->scalar('SELECT tenant_id FROM fm_location4'));
		$this->assertSame("King's road 10A", $this->scalar('SELECT loc2_name FROM fm_location2'));
		$this->assertSame("King's road 10A", $this->scalar('SELECT loc3_name FROM fm_location3'));
		$this->assertSame(75, (int)$this->scalar('SELECT sum_boa FROM fm_location2'));
		$this->assertSame(75, (int)$this->scalar('SELECT sum_boa FROM fm_location3'));
	}

	private function values(int $building = 123, string $address = '10A'): array
	{
		return ['bygningsnr' => $building, 'gate_kode' => 1001, 'street_number' => $address, 'adf' => 'ADF-1'];
	}

	private function sourceUnit(string $id, string $building = '99'): void
	{
		$this->insert('boei_leieobjekt', ['objekt_id' => '5000', 'leie_id' => $id,
			'bygg_id' => $building, 'seksjons_id' => '99', 'formaal_id' => 1, 'etasje' => 'H01',
			'gateadresse_id' => 11, 'gatenr' => '999Z', 'tv_signal_id' => 1, 'boareal' => 50,
			'leietaker_id' => 7, 'flyttenr' => 1, 'driftsstatus_id' => 1, 'antallrom' => 2, 'heis' => 0, 'livslopsstd' => 0]);
	}

	private function insert(string $table, array $values): void
	{
		$statement = $this->db->prepare("INSERT INTO {$table} (" . implode(',', array_keys($values))
			. ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
		$statement->execute(array_values($values));
	}

	private function scalar(string $sql)
	{
		return $this->db->query($sql)->fetchColumn();
	}
}

final class BoeiCronTestDatabase
{
	private array $rows = [];
	private array $current = [];
	private int $affected = 0;

	public function __construct(private PDO $db)
	{
	}

	public function query($sql, $line = '', $file = ''): void
	{
		$statement = $this->db->query($sql);
		$this->rows = $statement->fetchAll(PDO::FETCH_ASSOC);
		$this->affected = $statement->rowCount();
	}

	public function next_record(): bool
	{
		$row = array_shift($this->rows);
		$this->current = $row ?? [];
		return $row !== null;
	}

	public function f($name)
	{
		return $this->current[$name] ?? null;
	}

	public function transaction_begin(): void
	{
		$this->db->beginTransaction();
	}

	public function transaction_commit(): void
	{
		$this->db->commit();
	}

	public function affected_rows(): int
	{
		return $this->affected;
	}
}

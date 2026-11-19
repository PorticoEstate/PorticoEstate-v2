<?php

/**
 * phpGroupWare - property: Location Hierarchy Analysis UI
 *
 * @author Sigurd Nes
 * @copyright Copyright (C) 2025 Free Software Foundation, Inc.
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package property
 * @subpackage admin
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\property\helpers\LocationHierarchyAnalyzer;
use App\modules\property\helpers\LocationHierarchyDocumentAnalyzer;
use App\modules\property\helpers\BoeiImportService;
use App\modules\phpgwapi\security\Acl;

phpgw::import_class('phpgwapi.uicommon_jquery');
phpgw::import_class('property.bolocation');

class property_uianalyze_location extends phpgwapi_uicommon_jquery
{
	private $bo;
	private $account;
	private $analyzer;

	var $public_functions = array(
		'index' => true,
		'documents' => true,
		'boei' => true,
	);

	public function __construct()
	{
		parent::__construct();

		$this->bo = new property_bolocation();
		$this->account = $this->userSettings['account_id'];

		// Check access permissions
		if (!$this->isAdmin())
		{
			phpgw::no_access();
		}

		// Load analyzer
		$this->analyzer = new LocationHierarchyAnalyzer();
	}

	public function query()
	{
	}

	public function boei()
	{
		Settings::getInstance()->update('flags', [
			'app_header' => lang('BOEI import candidates'),
			'menu_selection' => 'admin::property::location::boei_import',
			'xslt_app' => true,
		]);
		phpgwapi_xslttemplates::getInstance()->add_file(['boei_import']);
		$_SESSION['boei_import_token'] ??= bin2hex(random_bytes(32));
		$loc1 = Sanitizer::get_var('loc1', 'string', 'REQUEST', '');
		$data = ['loc1' => $loc1, 'token' => $_SESSION['boei_import_token'], 'objects' => [], 'units' => []];
		$service = new BoeiImportService();
		$failed = false;
		try
		{
			if ($_SERVER['REQUEST_METHOD'] === 'POST')
			{
				$token = $_POST['token'] ?? null;
				if (!is_string($token) || !hash_equals($_SESSION['boei_import_token'], $token))
				{
					throw new DomainException(lang('Invalid form token. Reload the page.'));
				}
				$action = Sanitizer::get_var('action', 'string', 'POST');
				switch ($action)
				{
					case 'refresh':
						$service->refreshCandidates();
						$data['message'] = lang('Candidates refreshed. Manual values and approvals were preserved.');
						break;
					case 'save_object':
					case 'approve_object':
						$service->saveObject($loc1, $_POST, $action === 'approve_object', (int)$this->account);
						$data['message'] = lang('Candidate saved');
						break;
					case 'save_unit':
					case 'approve_unit':
						$service->saveUnit($loc1, Sanitizer::get_var('leie_id', 'string', 'POST'), $_POST,
							$action === 'approve_unit', (int)$this->account);
						$data['message'] = lang('Candidate saved');
						break;
					case 'import':
						if (($_POST['confirm_import'] ?? '') !== '1')
						{
							throw new DomainException(lang('Confirm import of approved candidates.'));
						}
						$data['message'] = lang('Imported rental units: %1', $service->importApproved($loc1));
						break;
					default:
						throw new DomainException(lang('Invalid action'));
				}
			}
		}
		catch (DomainException $e)
		{
			$failed = true;
			$data['error'] = $e->getMessage();
		}
		catch (Throwable $e)
		{
			$failed = true;
			error_log('BOEI candidate import: ' . $e->getMessage());
			$data['error'] = lang('BOEI import failed. Check the property database upgrade and server log.');
		}
		try
		{
			$data['objects'] = $service->listObjects($loc1);
			$data['units'] = $loc1 !== '' ? $service->listUnits($loc1) : [];
			foreach ($data['objects'] as &$object)
			{
				$object['status_label'] = lang($object['status']);
				$object['url'] = phpgw::link('/index.php', [
					'menuaction' => 'property.uianalyze_location.boei', 'loc1' => $object['objekt_id'],
				]);
			}
			unset($object);
			foreach ($data['units'] as &$unit)
			{
				$unit['status_label'] = lang($unit['status']);
			}
			unset($unit);
		}
		catch (Throwable $e)
		{
			error_log('BOEI candidate listing: ' . $e->getMessage());
			$data['error'] = lang('BOEI import failed. Check the property database upgrade and server log.');
		}
		if ($failed && isset($_POST['action']) && is_string($_POST['action']))
		{
			foreach ($data['objects'] as &$object)
			{
				if (in_array($_POST['action'], ['save_object', 'approve_object'], true))
				{
					foreach (['gnr', 'bnr'] as $field)
					{
						if (isset($_POST[$field]) && is_string($_POST[$field]))
						{
							$object[$field] = $_POST[$field];
						}
					}
				}
			}
			unset($object);
			foreach ($data['units'] as &$unit)
			{
				if ($unit['leie_id'] === ($_POST['leie_id'] ?? null))
				{
					foreach (['bygningsnr', 'gate_kode', 'street_number', 'adf'] as $field)
					{
						if (isset($_POST[$field]) && is_string($_POST[$field]))
						{
							$unit[$field] = $_POST[$field];
						}
					}
				}
			}
			unset($unit);
		}
		$data['action_url'] = phpgw::link('/index.php', ['menuaction' => 'property.uianalyze_location.boei', 'loc1' => $loc1]);
		foreach ([
			'title' => 'BOEI import candidates', 'refresh' => 'Refresh candidates', 'filter' => 'Filter',
			'object' => 'Object', 'unit' => 'Rental unit', 'status' => 'Status', 'save' => 'Save draft',
			'approve' => 'Save and approve', 'import' => 'Import approved candidates', 'building' => 'Building number',
			'street' => 'Street code', 'number' => 'House number and letter', 'confirm' => 'Confirm import of approved candidates.',
			'empty' => 'No candidates. Refresh candidates after BOEI synchronization.',
			'source_missing' => 'Missing from current BOEI source', 'open' => 'Open', 'source' => 'BOEI address (reference only)',
			'instructions' => 'Keep BOEI Objekt_ID and Leie_ID. Enter building number, national street code, house number and ADF manually. Save drafts or approve completed candidates.',
		] as $key => $label)
		{
			$data['labels'][$key] = lang($label);
		}
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', ['boei' => $data]);
	}

	public function index()
	{
		if (!$this->isAdmin())
		{
			phpgw::no_access();
		}

		Settings::getInstance()->update('flags', [
			'app_header' => lang('Location Hierarchy Analysis'),
			'menu_selection' => 'admin::property::location::analyze_location',
			'xslt_app' => true,
		]);
		phpgwapi_xslttemplates::getInstance()->add_file(array('analyze_location'));

		$data = array();
		$selected_loc1 = isset($_REQUEST['loc1']) ? trim($_REQUEST['loc1']) : '';
		$data['selected_loc1'] = $selected_loc1;

		if (isset($_POST['run_analysis']) && $_POST['run_analysis'] == 'yes')
		{
			// Create a fresh analyzer instance for each analysis
			$this->analyzer = new LocationHierarchyAnalyzer();

			// If a specific loc1 is selected, analyze just that one
			// Otherwise analyze all loc1 values separately and combine results
			if ($selected_loc1)
			{
				$analysis_results = $this->analyzer->analyze($selected_loc1);
			}
			else
			{
				$analysis_results = $this->analyzer->analyzeAllLoc1Separately();
			}

			$data['statistics'] = $analysis_results['statistics'];
			$data['issues'] = $analysis_results['issues'];
			$data['suggestions'] = $analysis_results['suggestions'];
			$data['sql_statements'] = $analysis_results['sql_statements'];
			$data['analysis_ran'] = true;

			// Pass the automatically determined fixed location codes to the template
			$data['fixed_location_codes'] = $analysis_results['fixed_location_codes'] ?? [];
		}
		else if (isset($_POST['execute_sql']) && $_POST['execute_sql'] == 'yes' && !empty($_POST['sql_types']))
		{
			// Create a fresh analyzer instance for each SQL execution too
			$this->analyzer = new LocationHierarchyAnalyzer();

			// Run analysis first to get the SQL statements
			if ($selected_loc1)
			{
				$analysis_results = $this->analyzer->analyze($selected_loc1);
			}
			else
			{
				$analysis_results = $this->analyzer->analyzeAllLoc1Separately();
			}

			$data['statistics'] = $analysis_results['statistics'];
			$data['issues'] = $analysis_results['issues'];
			$data['suggestions'] = $analysis_results['suggestions'];
			$data['sql_statements'] = $analysis_results['sql_statements'];
			$data['analysis_ran'] = true;

			// Execute selected SQL statements
			$data['sql_execution_results'] = $this->executeSqlStatements($selected_loc1, $_POST['sql_types'], $analysis_results['sql_statements']);
		}
		else
		{
			$data['analysis_ran'] = false;
		}


		//if sql is executed, we need to re-run the analysis to get the updated statistics
		if (isset($data['sql_execution_results']) && $data['sql_execution_results'])
		{
			if ($selected_loc1)
			{
				$analysis_results = $this->analyzer->analyze($selected_loc1);
			}
			else
			{
				$analysis_results = $this->analyzer->analyzeAllLoc1Separately();
			}

			$data['statistics'] = $analysis_results['statistics'];
			$data['issues'] = $analysis_results['issues'];
			$data['suggestions'] = $analysis_results['suggestions'];
			$data['sql_statements'] = $analysis_results['sql_statements'];
			$data['analysis_ran'] = true;
		}

		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('analyze' => $data));
	}

	public function documents()
	{
		if (!$this->isAdmin())
		{
			phpgw::no_access();
		}

		Settings::getInstance()->update('flags', [
			'app_header' => lang('Location Document Move Analysis'),
			'menu_selection' => 'admin::property::location::analyze_location_documents',
			'xslt_app' => true,
		]);
		phpgwapi_xslttemplates::getInstance()->add_file(array('analyze_location_documents'));

		$analyzer = new LocationHierarchyDocumentAnalyzer();
		$data = [];
		$data['analysis_ran'] = false;
		
		$data['vfs_repair_analysis'] = [];
		$data['vfs_repair_execution_results'] = [];

		if (isset($_POST['start_analysis']) && $_POST['start_analysis'] == 'yes')
		{
			$data['analysis_ran'] = true;
			$data['candidates'] = $analyzer->analyzeCandidates();
		}
		else if (isset($_POST['save_files_to_move']) && $_POST['save_files_to_move'] == 'yes')
		{
			$selectedMappings = isset($_POST['mapping_keys']) && is_array($_POST['mapping_keys'])
				? $_POST['mapping_keys']
				: [];

			$analyzer->updateFilesToMoveSelection($selectedMappings);
			$data['selection_saved'] = true;
			$data['selection_count'] = count($selectedMappings);
			$data['analysis_ran'] = true;
		}

		if (isset($_POST['execute_move']) && $_POST['execute_move'] == 'yes')
		{
			if (!empty($_POST['confirm_execute']))
			{
				$data['execution_results'] = $analyzer->executeMovesForSelectedMappings();
			}
			else
			{
				$data['execution_warning'] = 'Please confirm execute before running the move operation.';
			}
			$data['candidates'] = [];
			$data['analysis_ran'] = true;
		}

		if (isset($_POST['analyze_vfs_repair']) && $_POST['analyze_vfs_repair'] == 'yes')
		{
			$data['vfs_repair_analysis'] = $analyzer->analyzeMissingVfsDirectories();
			$data['analysis_ran'] = true;
		}

		if (isset($_POST['execute_vfs_repair']) && $_POST['execute_vfs_repair'] == 'yes')
		{
			if (!empty($_POST['confirm_vfs_repair']))
			{
				try
				{
					$data['vfs_repair_execution_results'] = $analyzer->repairMissingVfsDirectories();
					$data['vfs_repair_analysis'] = $analyzer->analyzeMissingVfsDirectories();
				}
				catch (\Throwable $e)
				{
					$data['execution_warning'] = 'VFS directory repair failed: ' . $e->getMessage();
				}
			}
			else
			{
				$data['execution_warning'] = 'Please confirm execute before running the VFS directory repair.';
			}
			$data['analysis_ran'] = true;
		}


		$summary = [
			'total_candidates' => 0,
			'selected_for_move' => 0,
			'completed_moves' => 0,
		];

		if(!$data['analysis_ran'])
		{
			$data['previously_selected'] = $analyzer->getPreviouslySelectionMappings();
		}

		foreach (($data['previously_selected'] ?? []) as $candidate)
		{
			$summary['total_candidates']++;
			$summary['selected_for_move']++;
			if (!empty($candidate['files_moved']))
			{
				$summary['completed_moves']++;
			}
		}

		$data['summary'] = $summary;

		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('documents' => $data));
	}

	/**
	 * Execute selected SQL statements
	 * 
	 * @param string $loc1 The loc1 value
	 * @param array $sqlTypes Array of SQL types to execute
	 * @param array $sqlStatements All SQL statements from analysis
	 * @return array Results of SQL execution
	 */
	private function executeSqlStatements($loc1, $sqlTypes, $sqlStatements)
	{
		return $this->analyzer->executeSqlStatements($loc1, $sqlTypes, $sqlStatements);
	}

	/**
	 * Check if the current user is an admin
	 */
	private function isAdmin()
	{
		return $this->acl->check('admin', Acl::ADD, 'property') ||
			$this->acl->check('admin', Acl::EDIT, 'property');
	}
}

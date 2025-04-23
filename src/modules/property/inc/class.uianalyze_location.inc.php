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
	);

	public function __construct()
	{
		parent::__construct();

		self::set_active_menu('admin::property::location::analyze_location');

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

	public function index()
	{
		if (!$this->isAdmin())
		{
			phpgw::no_access();
		}

		Settings::getInstance()->update('flags', [
			'app_header' => lang('Location Hierarchy Analysis'),
			'menu_selection' => 'property::admin::analyze_location',
			'xslt_app' => true,
		]);
		phpgwapi_xslttemplates::getInstance()->add_file(array('analyze_location'));

		$data = array();
		$selected_loc1 = isset($_REQUEST['loc1']) ? $_REQUEST['loc1'] : '';
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
		$analyzer = new LocationHierarchyDocumentAnalyzer();
		$analyzer->analyze();
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

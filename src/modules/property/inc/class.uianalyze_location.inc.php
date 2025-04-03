<?php

/**
 * phpGroupWare - property: Location Hierarchy Analysis UI
 *
 * @author Sigurd Nes
 * @copyright Copyright (C) 2025 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package property
 * @subpackage admin
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\property\helpers\LocationHierarchyAnalyzer;
use App\modules\phpgwapi\security\Acl;

phpgw::import_class('phpgwapi.uicommon_jquery');
phpgw::import_class('property.bolocation');

class property_uianalyze_location extends phpgwapi_uicommon_jquery
{
	private $bo;
	private $account;
	private $analyzer;

	var $public_functions = array(
		'index'	 => true,
	);


	public function __construct()
	{
		parent::__construct();

		$this->bo = new property_bolocation();
		$this->account	 = $this->userSettings['account_id'];

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
		// Get selected loc1 if any
		$selected_loc1 = isset($_REQUEST['loc1']) ? $_REQUEST['loc1'] : '';
		$data['selected_loc1'] = $selected_loc1;

		if (isset($_POST['run_analysis']) && $_POST['run_analysis'] == 'yes')
		{
			// Use analyzeAndPreview instead of analyzeAll to get structured results
			$analysis_results = $this->analyzer->analyzeAndPreview($selected_loc1 ? $selected_loc1 : null);

			// Format statistics
			$statistics = "";
			$statistics .= "STATISTICS:\n";
			$statistics .= "-----------\n";
			$statistics .= "Properties (Level 1): {$analysis_results['statistics']['level1_count']}\n";
			$statistics .= "Buildings (Level 2): {$analysis_results['statistics']['level2_count']}\n";
			$statistics .= "Entrances (Level 3): {$analysis_results['statistics']['level3_count']}\n";
			$statistics .= "Apartments (Level 4): {$analysis_results['statistics']['level4_count']}\n";
			$statistics .= "Unique Building Numbers: {$analysis_results['statistics']['unique_buildings']}\n";
			$statistics .= "Unique Street Addresses: {$analysis_results['statistics']['unique_addresses']}\n";
			$statistics .= "Required Buildings: {$analysis_results['statistics']['required_buildings']}\n";
			$statistics .= "Required Entrances: {$analysis_results['statistics']['required_entrances']}\n\n";
			
			// Format issues summary
			$issuesByType = array_count_values(array_column($analysis_results['issues'], 'type'));
			$issues_summary = "ISSUES FOUND:\n";
			$issues_summary .= "-------------\n";
			foreach ($issuesByType as $type => $count) {
				$issues_summary .= ucfirst(str_replace('_', ' ', $type)) . ": $count\n";
			}
			
			// Format issue details (limited to 10 examples per type)
			$issues_details = "\nISSUE DETAILS:\n";
			$issues_details .= "-------------\n";
			$grouped_issues = [];
			foreach ($analysis_results['issues'] as $issue) {
				$grouped_issues[$issue['type']][] = $issue;
			}
			
			foreach ($grouped_issues as $type => $issues) {
				$issues_details .= "\n" . ucfirst(str_replace('_', ' ', $type)) . " details:\n";
				
				$count = 0;
				foreach ($issues as $issue) {
					$count++;
					if ($count <= 10) {
						$issues_details .= $this->analyzer->formatIssueDetail($issue) . "\n";
					}
				}
				
				if ($count > 10) {
					$issues_details .= "... and " . ($count - 10) . " more issues of this type.\n";
				}
			}
			
			// Format suggestions
			$suggestions = "\nSUGGESTED FIXES:\n";
			$suggestions .= "---------------\n";
			foreach ($analysis_results['suggestions'] as $suggestion) {
				$suggestions .= $suggestion . "\n";
			}
			
			$formatted_results = $statistics . $issues_summary . $issues_details . $suggestions;
			
			$data['analysis_results'] = nl2br(htmlspecialchars($formatted_results));
			$data['analysis_ran'] = true;
		}
		else
		{
			$data['analysis_ran'] = false;
		}
	
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('analyze' => $data));
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

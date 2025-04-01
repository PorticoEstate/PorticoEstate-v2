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

		if (isset($_POST['run_analysis']) && $_POST['run_analysis'] == 'yes')
		{
			// Run analysis and capture output
			ob_start();
			$this->analyzer->analyzeAll();
			$analysis_results = ob_get_clean();

			$data['analysis_results'] = nl2br(htmlspecialchars($analysis_results));
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

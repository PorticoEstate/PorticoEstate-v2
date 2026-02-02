<?php

use App\modules\phpgwapi\services\Settings;

phpgw::import_class('booking.uicommon');

class booking_uiaccount_code_dimension extends booking_uicommon
{

	public $public_functions = array(
		'index' => true,
		'query' => true,
	);

	public function __construct()
	{
		parent::__construct();
		self::set_active_menu('booking::settings::account_code_dimensions');
		Settings::getInstance()->update('flags', ['app_header' => lang('booking') . "::" . lang('Account Code Dimension')]);
	}

	public function index()
	{
		$config = CreateObject('phpgwapi.config', 'booking');
		$config->read();

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			foreach ($_POST as $dim => $value)
			{
				if($dim == 'invoice_ssh_private_key' && $value == '*** PRIVATE KEY SET ***')				
				{
					continue;
				}
			
				if (strlen(trim($value)) > 0)
				{
					$config->value($dim, trim($value));
				}
				else
				{
					unset($config->config_data[$dim]);
				}
			}

			$config->config_data['differentiate_org_payer'] = Sanitizer::get_var('differentiate_org_payer', 'int', 'POST');

			$config->save_repository();
		}

		if(!empty($config->config_data['invoice_ssh_private_key']))
		{
			$config->config_data['invoice_ssh_private_key'] = '*** PRIVATE KEY SET ***';
		}

		$tabs = array();
		$tabs['generic'] = array('label' => lang('Account Code Dimension'), 'link' => '#account_code');
		$active_tab = 'generic';

		$data = array();
		$data['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);

		self::render_template_xsl('account_code_dimension', array(
			'config_data' => $config->config_data,
			'data' => $data
		));
	}

	public function query()
	{
	}
}

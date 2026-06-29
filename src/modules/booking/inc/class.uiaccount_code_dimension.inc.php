<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;

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
		$is_test_sftp = false;

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$is_test_sftp = (bool) Sanitizer::get_var('test_sftp_connection', 'bool', 'POST');

			if ($is_test_sftp)
			{
				$effective_config = $config->config_data;

				foreach ($_POST as $dim => $value)
				{
					if (!is_string($value))
					{
						continue;
					}

					$value = trim($value);
					if ($dim == 'invoice_ssh_private_key' && $value == '*** PRIVATE KEY SET ***')
					{
						continue;
					}
					if ($dim == 'sftp_key_passphrase' && $value == '*** PASSPHRASE SET ***')
					{
						continue;
					}

					if (strlen($value) > 0)
					{
						$effective_config[$dim] = $value;
					}
				}

				try
				{
					phpgw::import_class('booking.export_agresso');
					$export_agresso = new export_agresso();
					$test_result = $export_agresso->test_sftp_connection($effective_config);

					if (!empty($test_result['success']))
					{
						Cache::message_set($test_result['message'], 'message');
					}
					else
					{
						Cache::message_set($test_result['message'], 'error');
					}
				}
				catch (Exception $e)
				{
					Cache::message_set(lang('SFTP connection test failed: %1', $e->getMessage()), 'error');
				}

				$config->config_data = $effective_config;
			}
			else
			{
				foreach ($_POST as $dim => $value)
				{
					if($dim == 'invoice_ssh_private_key' && $value == '*** PRIVATE KEY SET ***')				
					{
						continue;
					}
					if($dim == 'sftp_key_passphrase' && $value == '*** PASSPHRASE SET ***')
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
		}

		if(!$is_test_sftp && !empty($config->config_data['invoice_ssh_private_key']))
		{
			$config->config_data['invoice_ssh_private_key'] = '*** PRIVATE KEY SET ***';
		}
		if(!$is_test_sftp && !empty($config->config_data['sftp_key_passphrase']))
		{
			$config->config_data['sftp_key_passphrase'] = '*** PASSPHRASE SET ***';
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

<?php
	/**
	 * phpGroupWare - property: a Facilities Management System.
	 *
	 * @author Sigurd Nes <sigurdne@online.no>
	 * @copyright Copyright (C) 2003,2004,2005,2006,2007 Free Software Foundation, Inc. http://www.fsf.org/
	 * This file is part of phpGroupWare.
	 *
	 * phpGroupWare is free software; you can redistribute it and/or modify
	 * it under the terms of the GNU General Public License as published by
	 * the Free Software Foundation; either version 2 of the License, or
	 * (at your option) any later version.
	 *
	 * phpGroupWare is distributed in the hope that it will be useful,
	 * but WITHOUT ANY WARRANTY; without even the implied warranty of
	 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	 * GNU General Public License for more details.
	 *
	 * You should have received a copy of the GNU General Public License
	 * along with phpGroupWare; if not, write to the Free Software
	 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
	 *
	 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
	 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
	 * @package property
	 * @subpackage admin
	 * @version $Id$
	 */

	use App\modules\phpgwapi\services\Cache;
	use App\modules\phpgwapi\services\Settings;
	use App\modules\phpgwapi\controllers\Locations;

	/**
	 * Description
	 * @package property
	 */
	class property_boXport
	{

		var $public_functions = array
			(
			'import'		 => true,
			'export'		 => true,
			'export_cron'	 => true
		);
		var $start;
		var $query;
		var $sort;
		var $order;
		var $filter;
		var $cat_id, $config,$debug,$userSettings,$flags;
		var $use_session		 = false;

		function __construct( $session = false )
		{
			$this->userSettings = Settings::getInstance()->get('user');
			$this->flags = Settings::getInstance()->get('flags');

			$this->flags['currentapp'] = 'property';
			Settings::getInstance()->set('flags', $this->flags);

			$locations_obj = new Locations();

			$this->config = CreateObject('admin.soconfig', $locations_obj->get_id('property', '.invoice'));

			if ($session)
			{
				$this->read_sessiondata();
				$this->use_session = true;
			}

			$start	 = Sanitizer::get_var('start', 'int', 'REQUEST', 0);
			$query	 = Sanitizer::get_var('query');
			$sort	 = Sanitizer::get_var('sort');
			$order	 = Sanitizer::get_var('order');
			$filter	 = Sanitizer::get_var('filter', 'int');
			$cat_id	 = Sanitizer::get_var('cat_id', 'int');

			if ($start || $start == 0)
			{
				$this->start = $start;
			}
			if ($query)
			{
				$this->query = $query;
			}
			if ($sort)
			{
				$this->sort = $sort;
			}
			if ($order)
			{
				$this->order = $order;
			}
			if ($filter)
			{
				$this->filter = $filter;
			}
			$this->cat_id = $cat_id;
		}

		function save_sessiondata()
		{

			if ($this->use_session)
			{
				$data = array(
					'start'	 => $this->start,
					'query'	 => $this->query,
					'sort'	 => $this->sort,
					'order'	 => $this->order,
					'filter' => $this->filter,
					'cat_id' => $this->cat_id
				);
				if ($this->debug)
				{
					echo '<br>Save:';
					_debug_array($data);
				}
				Cache::session_set('export', 'session_data', $data);
			}
		}

		function read_sessiondata()
		{
			$data = Cache::session_get('export', 'session_data');

			$this->start	 = $data['start'];
			$this->query	 = $data['query'];
			$this->sort		 = $data['sort'];
			$this->order	 = $data['order'];
			$this->filter	 = $data['filter'];
			$this->cat_id	 = $data['cat_id'];
		}

		function select_import_conv( $selected = '' )
		{
			$dir_handle	 = opendir(PHPGW_SERVER_ROOT . "/property/inc/import/{$this->userSettings['domain']}");
			$i			 = 0;
			$myfilearray = array();
			while ($file		 = readdir($dir_handle))
			{
				if ((substr($file, 0, 1) != '.') && is_file(PHPGW_SERVER_ROOT . "/property/inc/import/{$this->userSettings['domain']}/{$file}"))
				{
					$myfilearray[$i] = $file;
					$i++;
				}
			}
			closedir($dir_handle);
			sort($myfilearray);

			for ($i = 0; $i < count($myfilearray); $i++)
			{
				$fname		 = preg_replace('/_/', ' ', $myfilearray[$i]);
				$sel_file	 = '';
				if ($myfilearray[$i] == $selected)
				{
					$sel_file = 'selected';
				}

				$conv_list[] = array
					(
					'id'		 => $myfilearray[$i],
					'name'		 => $fname,
					'selected'	 => $sel_file
				);
			}

			for ($i = 0; $i < count($conv_list); $i++)
			{
				if ($conv_list[$i]['selected'] != 'selected')
				{
					unset($conv_list[$i]['selected']);
				}
			}

			return $conv_list;
		}

		function select_export_conv( $selected = '' )
		{
			$dir_handle	 = @opendir(PHPGW_SERVER_ROOT . "/property/inc/export/{$this->userSettings['domain']}");
			$i			 = 0;
			$myfilearray = array();
			while ($file		 = readdir($dir_handle))
			{
				if ((substr($file, 0, 1) != '.') && is_file(PHPGW_SERVER_ROOT . "/property/inc/export/{$this->userSettings['domain']}/{$file}"))
				{
					$myfilearray[$i] = $file;
					$i++;
				}
			}
			closedir($dir_handle);
			sort($myfilearray);

			for ($i = 0; $i < count($myfilearray); $i++)
			{
				$fname		 = preg_replace('/_/', ' ', $myfilearray[$i]);
				$sel_file	 = '';
				if ($myfilearray[$i] == $selected)
				{
					$sel_file = 'selected';
				}

				$conv_list[] = array
					(
					'id'		 => $myfilearray[$i],
					'name'		 => $fname,
					'selected'	 => $sel_file
				);
			}

			for ($i = 0; $i < count($conv_list); $i++)
			{
				if ($conv_list[$i]['selected'] != 'selected')
				{
					unset($conv_list[$i]['selected']);
				}
			}

			return $conv_list;
		}

		function select_rollback_file( $selected = '' )
		{
			$rollback_list = array();

			$file_catalog = $this->config->config_data['export']['path'];
			
			$dir_handle	 = opendir($file_catalog);			

			if(!$dir_handle)
			{
				return array();
			}
			$i			 = 0;
			$myfilearray = array();
			while ($file = readdir($dir_handle))
			{
				if ((substr($file, 0, 1) != '.') && is_file("{$file_catalog}/{$file}"))
				{
					$myfilearray[$i] = $file;
					$i++;
				}
			}
			closedir($dir_handle);
			sort($myfilearray);

			for ($i = 0; $i < count($myfilearray); $i++)
			{
				$fname		 = preg_replace('/_/', ' ', $myfilearray[$i]);
				$sel_file	 = '';
				if ($myfilearray[$i] == $selected)
				{
					$sel_file = 'selected';
				}

				$rollback_list[] = array
					(
					'id'		 => $myfilearray[$i],
					'name'		 => $fname,
					'selected'	 => $sel_file
				);
			}

			for ($i = 0; $i < count($rollback_list); $i++)
			{
				if ($rollback_list[$i]['selected'] != 'selected')
				{
					unset($rollback_list[$i]['selected']);
				}
			}

			return $rollback_list;
		}

		function import( $invoice_common, $download )
		{
			include (PHPGW_SERVER_ROOT . "/property/inc/import/{$this->userSettings['domain']}/{$invoice_common['conv_type']}");
			$invoice = new import_conv;

			$buffer = $invoice->import($invoice_common, $download);
			if ($download)
			{
				$header	 = $invoice->header;
				$import	 = $invoice->import;
				$buffer	 = array(
					'table'	 => $buffer,
					'header' => $header,
					'import' => $import
				);
			}
			return $buffer;
		}

		function export( $data )
		{
			$conv_type			 = $data['conv_type'];
			$download			 = $data['download'];
			$pre_transfer		 = $data['pre_transfer'];
			$force_period_year	 = $data['force_period_year'];

			$file_name = PHPGW_SERVER_ROOT . "/property/inc/export/{$this->userSettings['domain']}/{$conv_type}";

			if(is_file("{$file_name}.php"))
			{
				require_once "{$file_name}.php";
			}
			else
			{
				require_once $file_name;
			}

			$invoice = new export_conv;

			$buffer = $invoice->overfor($download, $pre_transfer, $force_period_year);

			return $buffer;
		}

		function rollback( $conv_type, $role_back_date, $rollback_file, $rollback_voucher, $voucher_id_intern )
		{
			$file_name = PHPGW_SERVER_ROOT . "/property/inc/export/{$this->userSettings['domain']}/{$conv_type}";
			if(is_file("{$file_name}.php"))
			{
				require_once "{$file_name}.php";
			}
			else
			{
				require_once $file_name;
			}
			$invoice = new export_conv;
			$buffer	 = $invoice->RullTilbake($role_back_date, $rollback_file, $rollback_voucher, $voucher_id_intern);
			return $buffer;
		}

		function export_cron( $data = array() )
		{
			if (!$data)
			{
				$data	 = unserialize(urldecode(Sanitizer::get_var('data')));
				$data	 = Sanitizer::clean_value($data);
			}

			if (!isset($data['enabled']) || (isset($data['enabled']) && $data['enabled'] === 1))
			{
				$receipt = $this->export($data);
				if (!empty($receipt))
				{
					_debug_array($receipt);
				}
			}
		}
	}	
<?php

use App\modules\phpgwapi\services\Settings;
use App\Database\Db;


phpgw::import_class('booking.uicommon');

	phpgw::import_class('booking.uidocument_building');
	phpgw::import_class('booking.uipermission_building');

//	phpgw::import_class('phpgwapi.uicommon_jquery');

	class booking_uicompleted_reservation_export_file extends booking_uicommon
	{

		public $public_functions = array
			(
			'index' => true,
			'query' => true,
			'show' => true,
			'add' => true,
			'download' => true,
			'log' => true,
			'upload' => true,
		);
		protected
			$module = 'booking';

		var $export_agresso, $display_name,$db;
		public function __construct()
		{
			parent::__construct();
			$this->bo = CreateObject('booking.bocompleted_reservation_export_file');
			$this->export_agresso = CreateObject('booking.export_agresso');
			self::set_active_menu('booking::invoice_center::generated_files');
			$this->url_prefix = 'booking.uicompleted_reservation_export_file';
			$this->display_name = lang('Generated Files');
			Settings::getInstance()->update('flags', ['app_header' => lang('booking') . "::{$this->display_name}"]); 
		}

		public function link_to( $action, $params = array() )
		{
			return $this->link($this->link_to_params($action, $params));
		}

		public function redirect_to( $action, $params = array() )
		{
			return self::redirect($this->link_to_params($action, $params));
		}

		public function link_to_params( $action, $params = array() )
		{
			if (isset($params['ui']))
			{
				$ui = $params['ui'];
				unset($params['ui']);
			}
			else
			{
				$ui = 'completed_reservation_export_file';
			}

			$action = sprintf($this->module . '.ui%s.%s', $ui, $action);
			return array_merge(array('menuaction' => $action), $params);
		}

		public function add_default_display_data( &$export_file )
		{
			$export_file['created_on'] = pretty_timestamp($export_file['created_on']);
			$export_file['index_link'] = $this->link_to('index');
			$export_file['download_link'] = $this->link_to('download', array('id' => $export_file['id']));
			$export_file['log_link'] = $this->link_to('log', array('id' => $export_file['id']));
		}

		public function index()
		{
			if (Sanitizer::get_var('phpgw_return_as') == 'json')
			{
				return $this->query();
			}

			phpgwapi_jquery::load_widget('numberformat');

			$data = array(
				'datatable_name' => $this->display_name,
				'datatable' => array(
					'source' => $this->link_to('index', array('phpgw_return_as' => 'json')),
					'sorted_by' => array('key' => 0, 'dir' => 'desc'),
					'field' => array(
						array(
							'key' => 'id',
							'label' => lang('ID'),
							'formatter' => 'JqueryPortico.formatLink'
						),
						array(
							'key' => 'type',
							'label' => lang('Type'),
						),
						array(
							'key' => 'total_items',
							'label' => lang('Total Items'),
						),
						array(
							'key' => 'total_cost',
							'label' => lang('Total Cost'),
							'formatter' => 'JqueryPortico.FormatterAmount2'
							),
						array(
							'key' => 'created_on',
							'label' => lang('Created'),
						),
						array(
							'key' => 'created_by_name',
							'label' => lang('Created by'),
						),
						array(
							'key' => 'download',
							'label' => lang('Actions'),
							'formatter' => 'JqueryPortico.formatLinkGeneric',
							'sortable' => false,
						),
						array(
							'key' => 'upload',
							'label' => lang('Export'),
							'formatter' => 'JqueryPortico.formatLinkGeneric',
							'sortable' => false,
						),
						array(
							'key' => 'log',
							'label' => lang('Logfile'),
							'formatter' => 'JqueryPortico.formatLinkGeneric',
							'sortable' => false,
						),
						array(
							'key' => 'link',
							'hidden' => true
						),
					)
				)
			);

			self::render_template_xsl('datatable2', $data);
		}

		public function query()
		{
			$this->db = Db::getInstance();
			$config = CreateObject('phpgwapi.config', 'booking');
			$config->read();
#            if ($config->config_data['output_files'] == 'single')
			$export_files = $this->bo->read();
			array_walk($export_files["results"], array($this, "_add_links"), $this->module . ".uicompleted_reservation_export_file.show");
			$found_not_exported = false;
			$export_files["results"] = array_reverse($export_files["results"]);
			foreach ($export_files["results"] as &$export_file)
			{
				$export_file['created_on'] = pretty_timestamp(substr($export_file['created_on'], 0, 19));
				$export_file['type'] = lang($export_file['type']);

				$export_file['download'] = array(
					'label' => lang('Download'),
					'href' => $this->link_to('download', array('id' => $export_file['id']))
				);
				if ($export_file['total_items'] > 0 and ! empty($export_file['log_filename'])) //and $export_file['id'] > $config->config_data['invoice_last_id'] )
				{
					$export_file['log'] = array(
						'label' => lang('log'),
						'href' => $this->link_to('log', array('id' => $export_file['id']))
					);
				}
				else
				{
					$export_file['log'] = array(
						'label' => ' ',
						'href' => '#'
					);
				}
				if (!$found_not_exported && $export_file['total_items'] > 0 and $export_file['id'] > $config->config_data['invoice_last_id'])
				{
					$found_not_exported = true;
					$export_file['upload'] = array(
						'label' => lang('Upload'),
						'href' => $this->link_to('upload', array('id' => $export_file['id']))
					);
				}
				else
				{
					$export_file['upload'] = array(
						'label' => ' ',
						'href' => '#'
					);
				}
				$sql = "SELECT account_lastname, account_firstname FROM phpgw_accounts WHERE account_lid = '" . $export_file['created_by_name'] . "'";
				$this->db->query($sql);
				while ($record = array_shift($this->db->resultSet))
				{
					$export_file['created_by_name'] = $record['account_firstname'] . " " . $record['account_lastname'];
				}
			}
			$export_files["results"] = array_reverse($export_files["results"]);

			$results = $this->jquery_results($export_files);
			return $results;
		}

		public function show()
		{
			$id = Sanitizer::get_var('id', 'int');
			if (!$id)
			{
				phpgw::no_access('booking', lang('missing id'));
			}
			$export_file = $this->bo->read_single($id);
			if(!$export_file)
			{
				phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
			}
			$export_file['type'] = lang($export_file['type']);
			$this->add_default_display_data($export_file);
			$tabs = array();
			$tabs['generic'] = array('label' => lang('Export File'), 'link' => '#export_file');
			$active_tab = 'generic';
			$export_file['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
			self::render_template_xsl('completed_reservation_export_file', array('export_file' => $export_file));
		}

		public function download()
		{
			$export_file = $this->bo->read_single(Sanitizer::get_var('id', 'int'));

			if (!is_array($export_file))
			{
				$this->redirect_to('index');
			}

			$file = $this->bo->get_file($export_file);

			$this->send_file($file->get_system_identifier(), array('filename' => $file->get_identifier()));
		}

		public function log()
		{
			$export_file = $this->bo->read_single(Sanitizer::get_var('id', 'int'));

			if (!is_array($export_file))
			{
				$this->redirect_to('index');
			}

			$file = $this->bo->get_logfile($export_file);

			$this->send_file($file->get_system_identifier(), array('filename' => $file->get_identifier()));
		}

		public function upload()
		{
			$id = Sanitizer::get_var('id', 'int');
			$export_file = $this->bo->read_single(Sanitizer::get_var('id', 'int'));

			if (!is_array($export_file))
			{
				$this->redirect_to('index');
			}

			$file = $this->bo->get_file($export_file);

			$path_parts = pathinfo($export_file['filename']);
			$extension = $path_parts['extension'];

			$file_type_arr = explode('_', $export_file['filename']);

			$type = $file_type_arr[1];

			$config_data = CreateObject('phpgwapi.config', 'booking')->read();

			switch ($config_data["{$type}_format"])
			{
				case 'FACTUM':
					$file_name_part = 'FACTUM_';
					break;
				case 'VISMA':
					$file_name_part = 'VISMA_';
					break;
				case 'AGRESSO':
					$file_name_part = 'AktivbyLG04_';
					break;
				case 'CSV':
					$file_name_part = 'CSV_';
					break;
				case 'KOMMFAKT':
					$file_name_part = 'KOMMFAKT_';
					break;
				default:
					$file_name_part = 'AktivbyLG04_';
					break;
			}

			$file_name_part .= "{$type}_";
			$file_name_part .= substr($file_type_arr[2], 0, -4);

			$content = file_get_contents($file->get_system_identifier(), false);
			$this->export_agresso->do_your_magic($content, $id, $file_name_part, $extension);
			$this->redirect_to('index');
		}
	}
<?php

/**
 * Frontend : a simplified tool for end users.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2010 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package Frontend
 * @version $Id$
 */
/*
	  This program is free software: you can redistribute it and/or modify
	  it under the terms of the GNU General Public License as published by
	  the Free Software Foundation, either version 2 of the License, or
	  (at your option) any later version.

	  This program is distributed in the hope that it will be useful,
	  but WITHOUT ANY WARRANTY; without even the implied warranty of
	  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	  GNU General Public License for more details.

	  You should have received a copy of the GNU General Public License
	  along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

use App\modules\phpgwapi\services\Cache;

phpgw::import_class('frontend.uicommon');

/**
 * Drawings
 *
 * @package Frontend
 */
class frontend_uidrawings extends frontend_uicommon
{

	public $public_functions = array(
		'index' => true,
		'query' => true
	);

	var $location_code, $start, $query, $sort, $order, $filter;
	public function __construct()
	{
		parent::__construct();
		Cache::session_set('frontend', 'tab', $this->locations->get_id('frontend', '.drawings'));
		$this->location_code = $this->header_state['selected_location'];
	}

	public function index()
	{
		$config = CreateObject('phpgwapi.config', 'frontend');
		$config->read();
		$doc_types = isset($config->config_data['document_frontend_cat']) && $config->config_data['document_frontend_cat'] ? $config->config_data['document_frontend_cat'] : array();

		$allrows = true;
		$sodocument = CreateObject('property.sodocument');

		$document_list = array();
		$total_records = 0;
		if ($this->location_code)
		{
			foreach ($doc_types as $doc_type)
			{
				if ($doc_type)
				{
					$document_list = array_merge($document_list, $sodocument->read_at_location(array(
						'start' => $this->start,
						'query' => $this->query,
						'sort' => $this->sort,
						'order' => $this->order,
						'filter' => $this->filter,
						'location_code' => $this->location_code,
						'doc_type' => $doc_type,
						'allrows' => $allrows
					)));
				}

				$total_records = $total_records + $sodocument->total_records;
			}
		}

		$valid_types = isset($config->config_data['document_valid_types']) && $config->config_data['document_valid_types'] ? str_replace(',', '|', $config->config_data['document_valid_types']) : '';

		$content = array();
		if ($valid_types)
		{
			foreach ($document_list as $entry)
			{
				if (!preg_match("/({$valid_types})$/i", $entry['document_name']))
				{
					continue;
				}

				$content[] = array(
					'document_name' => $entry['document_name'],
					'document_id' => $entry['document_id'],
					'link' => phpgw::link('/index.php', array(
						'menuaction' => 'property.uidocument.view_file',
						'id' => $entry['document_id']
					)),
					'title' => $entry['title'],
					'doc_type' => $entry['doc_type'],
					'document_date' => $this->phpgwapi_common->show_date($entry['document_date'], $this->userSettings['preferences']['common']['dateformat']),
				);
			}
		}

		$msglog = Cache::session_get('frontend', 'msgbox');
		Cache::session_clear('frontend', 'msgbox');

		$datatable_def[] = array(
			'container' => 'datatable-container_0',
			'requestUrl' => "''",
			'ColumnDefs' => array(
				array(
					'key' => 'document_name',
					'label' => lang('filename'),
					'sortable' => true,
					'formatter' => 'JqueryPortico.formatLink'
				),
				array(
					'key' => 'document_id',
					'label' => lang('filename'),
					'sortable' => false,
					'hidden' => true
				),
				array('key' => 'title', 'label' => lang('name'), 'sortable' => true),
				array('key' => 'doc_type', 'label' => 'Type', 'sortable' => true),
				array('key' => 'document_date', 'label' => lang('date'), 'sortable' => true)
			),
			'data' => json_encode($content)
		);


		$data = array(
			'header' => $this->header_state,
			'section' => array(
				'datatable_def' => $datatable_def,
				'tabs' => $this->tabs,
				'tabs_content' => $this->tabs_content,
				'tab_selected' => $this->tab_selected,
				'msgbox_data' => $this->phpgwapi_common->msgbox($this->phpgwapi_common->msgbox_data($msglog))
			)
		);

		self::render_template_xsl(array('drawings', 'datatable_inline', 'frontend'), $data);
	}

	public function query()
	{
	}
}

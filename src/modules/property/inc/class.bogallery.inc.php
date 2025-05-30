<?php
	/**
	 * phpGroupWare - property: a Facilities Management System.
	 *
	 * @author Sigurd Nes <sigurdne@online.no>
	 * @copyright Copyright (C) 2003,2004,2005,2006,2007,2008,2009 Free Software Foundation, Inc. http://www.fsf.org/
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
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\security\Acl;


	 /*
	 * Import the datetime class for date processing
	 */
	phpgw::import_class('phpgwapi.datetime');

	/**
	 * Description
	 * @package property
	 */
	class property_bogallery
	{

		var $start;
		var $query;
		var $filter;
		var $sort;
		var $order;
		var $cat_id;
		var $location_info = array();
		var$so,$mime_magic, $interlink, $use_session, $location_id, $user_id,$allrows,
		$start_date,$end_date,$total_records,$mime_type,$flags,$userSettings,$phpgwapi_common,$serverSettings;

		function __construct( $session = false )
		{
		$this->flags = Settings::getInstance()->get('flags');
		$this->userSettings = Settings::getInstance()->get('user');
		$this->phpgwapi_common = new \phpgwapi_common();
		$this->serverSettings = Settings::getInstance()->get('server');

		$this->so			 = CreateObject('property.sogallery');
			$this->mime_magic	 = createObject('phpgwapi.mime_magic');
			$this->interlink	 = CreateObject('property.interlink');

			if ($session)
			{
				$this->read_sessiondata();
				$this->use_session = true;
			}

			$start		 = Sanitizer::get_var('start', 'int', 'REQUEST', 0);
			$query		 = Sanitizer::get_var('query');
			$sort		 = Sanitizer::get_var('sort');
			$order		 = Sanitizer::get_var('order');
			$filter		 = Sanitizer::get_var('filter', 'int');
			$cat_id		 = Sanitizer::get_var('cat_id', 'string');
			$location_id = Sanitizer::get_var('location_id', 'int');
			$allrows	 = Sanitizer::get_var('allrows', 'bool');
			$type		 = Sanitizer::get_var('type');
			$type_id	 = Sanitizer::get_var('type_id', 'int');
			$user_id	 = Sanitizer::get_var('user_id', 'int');
			$mime_type	 = Sanitizer::get_var('mime_type');
			$start_date	 = Sanitizer::get_var('start_date', 'string');
			$end_date	 = Sanitizer::get_var('end_date', 'string');


			$this->start		 = $start ? $start : 0;
			$this->query		 = isset($_REQUEST['query']) ? $query : $this->query;
			$this->sort			 = isset($_REQUEST['sort']) ? $sort : $this->sort;
			$this->order		 = isset($_REQUEST['order']) ? $order : $this->order;
			$this->filter		 = isset($_REQUEST['filter']) ? $filter : $this->filter;
			$this->cat_id		 = isset($_REQUEST['cat_id']) ? $cat_id : $this->cat_id;
			$this->location_id	 = isset($_REQUEST['location_id']) ? $location_id : $this->location_id;
			$this->user_id		 = isset($_REQUEST['user_id']) ? $user_id : $this->user_id;
			$this->allrows		 = isset($allrows) ? $allrows : false;
			$this->mime_type	 = isset($_REQUEST['mime_type']) ? $mime_type : $this->mime_type;

			$this->start_date	 = isset($_REQUEST['start_date']) ? urldecode($start_date) : $this->start_date;
			$this->end_date		 = isset($_REQUEST['end_date']) ? urldecode($end_date) : $this->end_date;
		}

		public function save_sessiondata( $data )
		{
			if ($this->use_session)
			{
				Cache::session_set('gallery', 'session_data', $data);

			}
		}

		function read_sessiondata()
		{
			$data = Cache::session_get('gallery', 'session_data');

			$this->start	 = $data['start'];
			$this->query	 = $data['query'];
			$this->filter	 = $data['filter'];
			$this->sort		 = $data['sort'];
			$this->order	 = $data['order'];
			$this->cat_id	 = $data['cat_id'];
			$this->allrows	 = $data['allrows'];
			$this->mime_type = $data['mime_type'];
			$this->user_id	 = $data['user_id'];
		}

		public function read( $data = array() )
		{
			$values = $this->so->read($data);

			$img_types			 = array
				(
				'image/jpeg',
				'image/png',
				'image/gif'
			);
			static $locations	 = array();
			static $urls		 = array();
			$dateformat			 = $this->userSettings['preferences']['common']['dateformat'];
			$i					 = 0;
			foreach ($values as &$entry)
			{
				if (!$entry['mime_type'])
				{
					$entry['mime_type'] = $this->mime_magic->filename2mime($entry['name']);
				}

				$entry['img_id'] = '';
				if (in_array($entry['mime_type'], $img_types))
				{
					$entry['img_id']		 = $entry['id'];
					$entry['file_name']		 = $entry['name'];
					$entry['img_url']		 = phpgw::link('/index.php', array(
						'menuaction' => 'property.uigallery.view_file',
						'img_id'	 => $entry['img_id'],
						'file'		 => "{$entry['directory']}/{$entry['file_name']}"
						)
					);
					$entry['thumbnail_flag'] = 'thumb=1';
				}

				$entry['date'] = $this->phpgwapi_common->show_date(strtotime($entry['created']), $dateformat);

				$directory = explode('/', $entry['directory']);

				$location = $this->get_location($directory);

				$entry['location']			 = $location['location'];
				$entry['location_item_id']	 = $location['location_item_id'];
				$entry['url']				 = $this->interlink->get_relation_link($location, $entry['location_item_id']);

				$entry['location_name']	 = $this->interlink->get_location_name($entry['location']);
				$entry['document_url']	 = phpgw::link('/index.php', array
					(
					'menuaction' => 'property.uigallery.view_file',
					'file_id'	 => $entry['id']
				));

				if($entry['createdby_id'])
				{
					$accounts_obj = new Accounts();
					$entry['user']			 = $accounts_obj->get($entry['createdby_id'])->__toString();
				}
			}
			//_debug_array($values);
			$this->total_records = $this->so->total_records;

			return $values;
		}

		public function get_location( $directory = array() )
		{
			$values = array();
			$values['appname'] = $directory[1];
			switch ($directory[2])
			{
				case 'agreement':
					$values['location']			 = '.agreement';
					$values['location_item_id']	 = $directory[3];
					break;
				case 'document':
					$values['location']			 = '.document';
					$values['location_item_id']	 = $directory[4];
					break;
				case 'fmticket':
					$values['location']			 = '.ticket';
					$values['location_item_id']	 = $directory[3];
					break;
				case 'request':
					$values['location']			 = '.project.request';
					$values['location_item_id']	 = $directory[4];
					break;
				case 'service_agreement':
					$values['location']			 = '.s_agreement';
					$values['location_item_id']	 = $directory[3];
					break;
				case 'workorder':
					$values['location']			 = '.project.workorder';
					$values['location_item_id']	 = $directory[3];
					break;
				case 'project':
					$values['location']			 = '.project';
					$values['location_item_id']	 = $directory[3];
					break;
				default:
					$values['location']			 = '.' . str_replace('_', '.', $directory[2]);
					$values['location_item_id']	 = $directory[4];
			}
			return $values;
		}

		public function get_filetypes()
		{
			$values = $this->so->get_filetypes();

			$map = array_flip($this->mime_magic->mime_extension_map);

			$filetypes = array();
			foreach ($values as $mime_type)
			{
				if ($mime_type)
				{
					$filetypes[] = array
						(
						'id'	 => $mime_type,
						'name'	 => $map[$mime_type]
					);
				}
			}
			return $filetypes;
		}

		public function get_gallery_location()
		{
			$values = $this->so->get_gallery_location();

			$_locations	 = array();
			$locations	 = array();
			foreach ($values as $entry)
			{
				$directory = explode('/', $entry);

				if (!empty($directory[2]) && !isset($directory[3]))
				{
					$location_info = $this->get_location($directory);
					$acl =Acl::getInstance();
					if ($acl->check($location_info['location'], ACL_READ, 'property'))
					{
						$locations[] = array
							(
							'id'	 => $entry,
							'name'	 => $this->interlink->get_location_name($location_info['location'])
						);
					}
				}
			}

			return $locations;
		}
	}
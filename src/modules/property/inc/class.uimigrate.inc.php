<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2008 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package property
 * @subpackage admin
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

/**
 * Description
 * @package property
 */

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;

phpgw::import_class('phpgwapi.uicommon_jquery');

class property_uimigrate extends phpgwapi_uicommon_jquery
{

	/**
	 * @var ??? $start ???
	 */
	private $start = 0;

	/**
	 * @var ??? $sort ???
	 */
	private $sort;

	/**
	 * @var ??? $order ???
	 */
	private $order;

	/**
	 * @var object $nextmatches paging handler
	 */
	private $nextmatches;

	/**
	 * @var object $bo business logic
	 */
	private $bo;

	/**
	 * @var object $acl reference to global access control list manager
	 */

	/**
	 * @var string $acl_location the access control location
	 */
	private $acl_location;

	/**
	 * @var bool $acl_read does the current user have read access to the current location
	 */
	private $acl_read;

	/**
	 * @var bool $acl_add does the current user have add access to the current location
	 */
	private $acl_add;

	/**
	 * @var bool $acl_edit does the current user have edit access to the current location
	 */
	private $acl_edit;

	/**
	 * @var bool $allrows display all rows of result set?
	 */
	private $allrows;

	/**
	 * @var array $public_functions publicly available methods of the class
	 */

	var $nextmatchs, $acl_delete, $acl_manage;

	public $public_functions = array(
		'index'		 => true,
		'no_access'	 => true
	);

	public function __construct()
	{
		parent::__construct();
		$this->flags['xslt_app']			 = true;
		$this->flags['menu_selection']	 = 'admin::property::migrate_db';
		Settings::getInstance()->update('flags', ['xslt_app' => true, 'menu_selection' => 'admin::property::migrate_db']);

		$this->bo											 = CreateObject('property.bomigrate', true);
		$this->nextmatchs									 = CreateObject('phpgwapi.nextmatchs');
		$this->acl_location									 = $this->bo->get_acl_location();
		$this->acl_read										 = $this->acl->check($this->acl_location, ACL_READ, 'property');
		$this->acl_add										 = $this->acl->check($this->acl_location, ACL_ADD, 'property');
		$this->acl_edit										 = $this->acl->check($this->acl_location, ACL_EDIT, 'property');
		$this->acl_delete									 = $this->acl->check($this->acl_location, ACL_DELETE, 'property');
		$this->acl_manage									 = $this->acl->check($this->acl_location, 16, 'property');
	}

	function query()
	{
	}

	private function save_sessiondata()
	{
		$data = array(
			'start'	 => $this->start,
			//		'query'		=> $this->query,
			'sort'	 => $this->sort,
			'order'	 => $this->order,
		);
		$this->bo->save_sessiondata($data);
	}

	public function index()
	{
		if (!$this->acl_read)
		{
			phpgw::no_access();
		}
		phpgwapi_xslttemplates::getInstance()->add_file(array('migrate', 'nextmatchs'));

		$values = Sanitizer::get_var('values', 'string', 'POST');

		$_alert_msg = 'This one will really fuck up your database if you accidentally choose wrong';

		if ($values)
		{
			/**
			 * FIXME
			 * This one will really fuck up your database if you accidentally choose wrong
			 */
			if (!$this->acl_manage || $this->userSettings['lid'] !== 'hc483')
			{
				phpgw::no_access('admin', $_alert_msg);
			}
			$this->bo->migrate($values);
		}
		else
		{
			Cache::message_set($_alert_msg, 'error');
		}

		$domain_info = $this->bo->read();

		$lang_select_migrate_text	 = '';
		$text_select				 = '';

		$content = array();
		foreach ($domain_info as $domain => $entry)
		{
			if ($this->acl_edit)
			{
				$lang_select_migrate_text = lang('select domain to migrate to') . ': ' . $domain;
			}

			$content[] = array(
				'domain'					 => $domain,
				'db_host'					 => $entry['db_host'],
				'db_port'					 => $entry['db_port'],
				'db_name'					 => $entry['db_name'],
				'db_type'					 => $entry['db_type'],
				'lang_select_migrate_text'	 => $lang_select_migrate_text,
			);
		}

		$table_header[] = array(
			'sort_domain'	 => $this->nextmatchs->show_sort_order(array(
				'sort'	 => $this->sort,
				'var'	 => 'domain',
				'order'	 => $this->order,
				'extra'	 => array(
					'menuaction' => 'property.uimigrate.index',
					'allrows'	 => $this->allrows
				)
			)),
			'lang_domain'	 => lang('domain'),
			'lang_db_host'	 => lang('db_host'),
			'lang_db_port'	 => lang('db_port'),
			'lang_db_name'	 => lang('db_name'),
			'lang_db_type'	 => lang('db_type'),
			'lang_select'	 => (isset($this->acl_edit) ? lang('select') : ''),
		);

		if (!$this->allrows)
		{
			$record_limit = $this->userSettings['preferences']['common']['maxmatchs'];
		}
		else
		{
			$record_limit = $this->bo->total_records;
		}

		$link_data = array(
			'menuaction' => 'property.uimigrate.index',
			'sort'		 => $this->sort,
			'order'		 => $this->order
		);

		$table_migrate[] = array(
			'lang_migrate'				 => lang('migrate'),
			'lang_migrate_statustext'	 => lang('perform selected migrations'),
		);

		$msgbox_data = (isset($receipt) ? $this->phpgwapi_common->msgbox_data($receipt) : '');

		$data = array(
			'msgbox_data'					 => $this->phpgwapi_common->msgbox($msgbox_data),
			'allow_allrows'					 => true,
			'allrows'						 => $this->allrows,
			'start_record'					 => $this->start,
			'record_limit'					 => $record_limit,
			'num_records'					 => ($domain_info ? count($domain_info) : 0),
			'all_records'					 => ($domain_info ? count($domain_info) : 0),
			'link_url'						 => phpgw::link('/index.php', $link_data),
			'img_path'						 => $this->phpgwapi_common->get_image_path('phpgwapi', 'default'),
			'lang_searchfield_statustext'	 => lang('Enter the search string. To show all entries, empty this field and press the SUBMIT button again'),
			'lang_searchbutton_statustext'	 => lang('Submit the search string'),
			//				'query'									=> $this->query,
			'lang_search'					 => lang('search'),
			'table_header'					 => $table_header,
			'table_migrate'					 => $table_migrate,
			'migrate_action'				 => phpgw::link('/index.php', array('menuaction' => 'property.uimigrate.index')),
			'values'						 => (isset($content) ? $content : '')
		);

		$function_msg = lang('list available domains');

		$this->flags['app_header'] = lang('migrate') . ":: {$function_msg}";
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);

		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('list' => $data));
		$this->save_sessiondata();
	}

	public function no_access()
	{
		phpgwapi_xslttemplates::getInstance()->add_file(array('no_access'));

		$receipt['error'][] = array('msg' => lang('NO ACCESS'));

		$msgbox_data = $this->phpgwapi_common->msgbox_data($receipt);

		$data = array(
			'msgbox_data' => $this->phpgwapi_common->msgbox($msgbox_data)
		);

		$function_msg = lang('No access');

		$this->flags['app_header'] = lang('migrate') . ":: {$function_msg}";
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('no_access' => $data));
	}
}

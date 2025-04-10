<?php

/**
 * phpGroupWare - CATCH: An application for importing data from handhelds into property.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2009 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package catch
 * @subpackage catch
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

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;


/**
 * Description
 * @package demo
 */

class catch_uicatch
{
	/**
	 * @var ??? $grants ???
	 */
	private $grants;

	/**
	 * @var ??? $start ???
	 */
	private $start;

	/**
	 * @var ??? $query ???
	 */
	private $query;

	/**
	 * @var ??? $sort ???
	 */
	private $sort;

	/**
	 * @var ??? $order ???
	 */
	private $order;

	/**
	 * @var object $cats categories object
	 */
	private $cats;

	/**
	 * @var object $nextmatches paging handler
	 */
	private $nextmatches;

	/**
	 * @var int $account reference to the current user id
	 */
	private $account;

	/**
	 * @var object $bo business logic
	 */
	private $bo;

	/**
	 * @var object $acl reference to global access control list manager
	 */
	private $acl;

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
	private $acl_delete;

	/**
	 * @var bool $allrows display all rows of result set?
	 */
	private $allrows;

	/**
	 * @var int $cat_id the currently selected category
	 */
	private $cat_id;

	/**
	 * @var bool $filter the current filter
	 */
	private $filter;

	var $serverSettings;
	/**
	 * @var array $public_functions publicly available methods of the class
	 */
	public $public_functions = array(
		'index' 	=> true,
	);

	public function __construct()
	{
		$this->serverSettings = Settings::getInstance()->get('server');
		$userSettings = Settings::getInstance()->get('user');
		$this->cats				= CreateObject('phpgwapi.categories');
		$this->nextmatches		= CreateObject('phpgwapi.nextmatchs');
		$this->account			= $userSettings['account_id'];
		$this->acl 				= Acl::getInstance();
		$this->acl_read 		= $this->acl->check($this->acl_location, ACL_READ, 'demo');
		$this->acl_add 			= $this->acl->check($this->acl_location, ACL_ADD, 'demo');
		$this->acl_edit 		= $this->acl->check($this->acl_location, ACL_EDIT, 'demo');
		$this->acl_delete 		= $this->acl->check($this->acl_location, ACL_DELETE, 'demo');

		$this->start			= $this->bo->start;
		$this->query			= $this->bo->query;
		$this->sort				= $this->bo->sort;
		$this->order			= $this->bo->order;
		$this->allrows			= $this->bo->allrows;
		$this->cat_id			= $this->bo->cat_id;
		$this->filter			= $this->bo->filter;
		Settings::getInstance()->update('flags', ['xslt_app' => true, 'menu_selection' => 'catch']);
	}

	private function save_sessiondata()
	{
		$data = array(
			'start'		=> $this->start,
			'query'		=> $this->query,
			'sort'		=> $this->sort,
			'order'		=> $this->order,
		);
		$this->bo->save_sessiondata($data);
	}

	public function index()
	{
		$output	= self::get_output();

		Settings::getInstance()->update('flags', ['xslt_app' => false,// not really
		 'menu_selection' => 'catch::' . $output]);


		if (!$this->acl_read)
		{
			//			$this->no_access();
			//			return;
		}
		$phpgwapi_common = new \phpgwapi_common();
		$phpgwapi_common->phpgw_header(true);
		echo '<b>Catch and release...Placeholder for links to for various reports</b>';
	}

	/**
	 * Get the output format
	 *
	 * @return string the output format - html, wml etc
	 */
	private static function get_output()
	{
		$output = Sanitizer::get_var('output', 'string', 'REQUEST', 'html');
		phpgwapi_xslttemplates::getInstance()->set_output($output);
		return $output;
	}
}
